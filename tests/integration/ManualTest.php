<?php

declare(strict_types=1);

/**
 * The Dutch manual.
 *
 * Mostly prose, so what is worth testing is not the words but the promises the
 * page makes: that it is reachable by the people it is for and nobody else,
 * that its links point at screens that exist, and that the details it reads
 * from the site are read rather than written down.
 */
final class ManualTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        CJW_Roles::install();
        $GLOBALS['submenu'] = [];
        $GLOBALS['menu'] = [];
    }

    /**
     * The rendered page, as a string.
     */
    private function page(): string
    {
        ob_start();

        try {
            new CJW_Summer_Camp_Manual()->render();
        } finally {
            // render() calls wp_die() for anyone without the capability, which
            // throws past ob_get_clean() and leaves the buffer open for every
            // later test in the process.
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    public function testItSitsUnderTheCampMenu(): void
    {
        $this->actAsAdministrator();

        do_action('admin_menu');

        $slugs = array_column($GLOBALS['submenu']['cjw-summer-camp'] ?? [], 2);

        $this->assertContains(CJW_Summer_Camp_Manual::PAGE_SLUG, $slugs);
    }

    public function testAKampbeheerderCanReadIt(): void
    {
        $id = self::factory()->user->create([ 'role' => CJW_Roles::ROLE ]);
        wp_set_current_user($id);

        $this->assertStringContainsString('Handleiding zomerkampwebsite', $this->page());
    }

    public function testEveryChapterIsOnThePage(): void
    {
        $this->actAsAdministrator();
        $page = $this->page();

        foreach ([
            'Het kampjaar in stappen',
            'Wat staat waar',
            'Inschrijvingen bekijken',
            'Als er iets misgaat',
            'Eenmalig instellen',
            'Updates',
        ] as $chapter) {
            $this->assertStringContainsString($chapter, $page);
        }
    }

    public function testEveryChapterInTheContentsHasSomethingToLinkTo(): void
    {
        $this->actAsAdministrator();
        $page = $this->page();

        preg_match_all('/href="#(cjw-[a-z]+)"/', $page, $links);

        $this->assertNotEmpty($links[1]);

        foreach (array_unique($links[1]) as $anchor) {
            $this->assertStringContainsString('id="' . $anchor . '"', $page);
        }
    }

    /**
     * The three things a volunteer cannot do from this screen, and which are
     * therefore the three most likely never to be done.
     */
    public function testTheRunbookCoversMailBackupsAndUptime(): void
    {
        $this->actAsAdministrator();
        $page = $this->page();

        $this->assertStringContainsString('SPF', $page);
        $this->assertStringContainsString('DKIM', $page);
        $this->assertStringContainsString('DMARC', $page);
        $this->assertStringContainsString('terug op een testomgeving', $page);
        $this->assertStringContainsString('uptime', $page);
    }

    public function testTheUpdatePolicyNamesAMonth(): void
    {
        $this->actAsAdministrator();
        $page = $this->page();

        // "Do majors deliberately" is a preference. "In september of oktober"
        // is a rule somebody can be held to.
        $this->assertStringContainsString('september of oktober', $page);
    }

    public function testItAsksForAContactWhenNobodyIsNamed(): void
    {
        $this->actAsAdministrator();
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize([]));

        $this->assertStringContainsString('Hier staat nog niemand', $this->page());
    }

    public function testItNamesTheContactOnceThereIsOne(): void
    {
        $this->actAsAdministrator();
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize([
            'support_contact' => 'Imre Boersma, 06 12345678, imre@example.test',
        ]));

        $page = $this->page();

        $this->assertStringContainsString('06 12345678', $page);
        $this->assertStringNotContainsString('Hier staat nog niemand', $page);
    }

    /**
     * Read from the site rather than written into the prose, so it stays right
     * when the page moves.
     */
    public function testTheUptimeCheckPointsAtTheRegistrationPage(): void
    {
        $this->actAsAdministrator();

        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Inschrijven',
            'post_content' => '[cjw_wizard id="24"]',
        ]);

        $this->assertStringContainsString(get_permalink($page_id), $this->page());
    }

    public function testSomebodyWithoutTheCapabilityIsRefused(): void
    {
        $this->actAsSubscriber();

        $this->expectException(WPDieException::class);

        $this->page();
    }
}
