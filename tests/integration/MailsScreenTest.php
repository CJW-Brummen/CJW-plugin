<?php

declare(strict_types=1);

/**
 * The Mails screen, and the two things about it that are not copy: that it is
 * reachable by the people it is for, and that saving actually goes through the
 * same path as every other settings change — so the history covers it.
 */
final class MailsScreenTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        CJW_Roles::install();
        delete_option(CJW_Summer_Camp_Settings_History::OPTION_KEY);
        $GLOBALS['submenu'] = [];
        $GLOBALS['menu'] = [];
    }

    public function testItSitsUnderTheCampMenu(): void
    {
        $this->actAsAdministrator();

        do_action('admin_menu');

        $slugs = array_column($GLOBALS['submenu']['cjw-summer-camp'] ?? [], 2);

        $this->assertContains(CJW_Summer_Camp_Mails_Page::PAGE_SLUG, $slugs);
    }

    public function testAKampbeheerderCanOpenIt(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => CJW_Roles::ROLE ]));

        ob_start();

        try {
            new CJW_Summer_Camp_Mails_Page()->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertStringContainsString('Mails', $html);

        foreach (CJW_Mail_Templates::all() as $definition) {
            $this->assertStringContainsString($definition['label'], $html);
        }
    }

    public function testSomebodyWithoutTheCapabilityIsRefused(): void
    {
        $this->actAsSubscriber();

        $this->expectException(WPDieException::class);

        ob_start();

        try {
            new CJW_Summer_Camp_Mails_Page()->render();
        } finally {
            ob_get_clean();
        }
    }

    /**
     * The templates live inside the camp settings option precisely so that the
     * settings history covers mail copy without knowing anything about mail.
     * Copy somebody spent an afternoon on should be recoverable by the same
     * undo as everything else on that screen.
     */
    public function testEditingCopyIsCoveredByTheSettingsHistory(): void
    {
        $this->actAsAdministrator();

        CJW_Mail_Templates::save([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => 'Eerste versie', 'body' => '<p>Een.</p>' ],
        ]);
        CJW_Mail_Templates::save([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => 'Tweede versie', 'body' => '<p>Twee.</p>' ],
        ]);

        $snapshots = CJW_Summer_Camp_Settings_History::all();

        $this->assertNotEmpty($snapshots);
        $this->assertSame(
            'Eerste versie',
            $snapshots[0]['settings'][ CJW_Mail_Templates::SETTINGS_KEY ][ CJW_Mail_Templates::BIRTHDAY ]['subject']
        );
    }

    /**
     * The same reasoning applied to the file export: a working configuration
     * kept off the server should include what the mails say.
     */
    public function testCopyTravelsWithTheSettingsExport(): void
    {
        CJW_Mail_Templates::save([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => 'Gefeliciteerd', 'body' => '<p>Hoera.</p>' ],
        ]);

        $exported = CJW_Summer_Camp_Service::sanitize(get_option(CJW_Summer_Camp_Service::OPTION_KEY));

        $this->assertSame(
            'Gefeliciteerd',
            $exported[ CJW_Mail_Templates::SETTINGS_KEY ][ CJW_Mail_Templates::BIRTHDAY ]['subject']
        );
    }

    /**
     * Saving other settings must not wipe the mail copy, which is what happens
     * when a key is not carried through the sanitiser.
     */
    public function testSavingOtherSettingsLeavesTheCopyAlone(): void
    {
        CJW_Mail_Templates::save([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => 'Gefeliciteerd', 'body' => '<p>Hoera.</p>' ],
        ]);

        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY);
        $settings['camp_location'] = 'Stokkum';
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize($settings));

        $this->assertSame('Gefeliciteerd', CJW_Mail_Templates::subject(CJW_Mail_Templates::BIRTHDAY));
    }
}
