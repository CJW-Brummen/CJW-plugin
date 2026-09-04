<?php

declare(strict_types=1);

/**
 * One click that creates what a fresh site is missing, and only that.
 *
 * Everything here is WordPress: pages, options, a nav menu and its location.
 */
final class FirstRunTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        $this->actAsAdministrator();
        register_nav_menu('menu-1', 'Hoofdmenu');

        // A blank site: no front page, no privacy page, no camp settings, no forms.
        update_option('show_on_front', 'posts');
        delete_option('page_on_front');
        delete_option('wp_page_for_privacy_policy');
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);
        delete_option(CJW_Summer_Camp_First_Run::OPTION_DISMISSED);
        CJW_Summer_Camp_Service::flush();
        CJW_Registration_Page::flush();
        remove_theme_mod('nav_menu_locations');
    }

    public function tear_down(): void
    {
        remove_theme_mod('nav_menu_locations');
        unregister_nav_menu('menu-1');
        CJW_Summer_Camp_Service::flush();
        CJW_Registration_Page::flush();

        parent::tear_down();
    }

    public function testABlankSiteIsMissingEverything(): void
    {
        $this->assertSame(
            [ 'form', 'registration_page', 'front_page', 'card_pages', 'privacy_page', 'menu' ],
            CJW_Summer_Camp_First_Run::missing()
        );
        $this->assertSame('recommended', (new CJW_Summer_Camp_Site_Health())->testSetup()['status']);
    }

    public function testOneRunCreatesEverythingAndWiresItUp(): void
    {
        $created = (new CJW_Summer_Camp_First_Run())->run();

        $this->assertSame([ 'form', 'registration_page', 'card_pages', 'front_page', 'privacy_page', 'menu' ], $created);
        $this->assertTrue(CJW_Summer_Camp_First_Run::isComplete());
        $this->assertSame('good', (new CJW_Summer_Camp_Site_Health())->testSetup()['status']);

        // The front page: a published page with the six blocks, set as such.
        $front = (int) get_option('page_on_front');
        $this->assertSame('page', get_option('show_on_front'));
        $this->assertSame('publish', get_post_status($front));
        foreach ([ 'hero', 'intro', 'jaarthema', 'cards', 'photos', 'sponsors' ] as $block) {
            $this->assertStringContainsString("<!-- wp:cjw/{$block} /-->", (string) get_post($front)->post_content);
        }

        // The registration page embeds the form that was just created, and the
        // signup buttons find it.
        $forms = get_posts([ 'post_type' => CJW_Wizard_Post_Type::SLUG, 'post_status' => 'any', 'numberposts' => -1 ]);
        $this->assertCount(1, $forms);
        $this->assertSame('draft', $forms[0]->post_status, 'a form nobody has built yet is not open for business');
        $page = get_post(CJW_Registration_Page::id());
        $this->assertInstanceOf(WP_Post::class, $page);
        $this->assertStringContainsString('[cjw_wizard id="' . $forms[0]->ID . '"]', $page->post_content);
        $this->assertSame(get_permalink($page), cjw_summer_camp()->getRegistrationPageUrl());

        // The card pages exist and the settings point at them.
        $camp = cjw_summer_camp();
        $this->assertSame('publish', get_post_status($camp->getCardZomerkampPageId()));
        $this->assertSame('zomerkamp', get_post($camp->getCardZomerkampPageId())->post_name);
        $this->assertSame('publish', get_post_status($camp->getCardInfoPageId()));
        $this->assertSame('praktische-info', get_post($camp->getCardInfoPageId())->post_name);

        // The privacy page is a draft: somebody has to read it before it goes live.
        $privacy = (int) get_option('wp_page_for_privacy_policy');
        $this->assertSame('draft', get_post_status($privacy));

        // The menu is assigned and links to the real pages.
        $this->assertTrue(has_nav_menu('menu-1'));
        $locations = get_nav_menu_locations();
        $items = wp_get_nav_menu_items((int) $locations['menu-1']);
        $titles = array_map(static fn(WP_Post $item): string => $item->title, $items);
        $this->assertSame([ 'Home', 'Zomerkamp', 'Praktische info', 'Inschrijven', 'Sponsoren', 'Contact' ], $titles);
        $this->assertSame((string) $camp->getCardZomerkampPageId(), (string) $items[1]->object_id);
    }

    public function testRunningItAgainCreatesNothing(): void
    {
        $runner = new CJW_Summer_Camp_First_Run();
        $runner->run();

        $pages = count(get_posts([ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1 ]));

        $this->assertSame([], $runner->run());
        $this->assertCount($pages, get_posts([ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1 ]));
    }

    /**
     * The one rule: nothing that exists is touched.
     */
    public function testAnExistingFrontPageAndFormAreLeftAlone(): void
    {
        $front = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Onze eigen homepage' ]);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $front);
        $form = self::factory()->post->create([ 'post_type' => CJW_Wizard_Post_Type::SLUG, 'post_status' => 'publish', 'post_title' => 'Echt formulier' ]);

        $created = (new CJW_Summer_Camp_First_Run())->run();

        $this->assertNotContains('front_page', $created);
        $this->assertNotContains('form', $created);
        $this->assertSame($front, (int) get_option('page_on_front'));
        $this->assertSame('Onze eigen homepage', get_post($front)->post_title);

        $page = get_post(CJW_Registration_Page::id());
        $this->assertStringContainsString('[cjw_wizard id="' . $form . '"]', $page->post_content, 'the published form is used, not a new draft');
    }

    public function testOnlySomebodyWhoMayPublishPagesCanRunIt(): void
    {
        $this->assertTrue(CJW_Summer_Camp_First_Run::mayRun());

        $this->actAsSubscriber();

        $this->assertFalse(CJW_Summer_Camp_First_Run::mayRun());
    }

    public function testTheNoticeNamesWhatIsMissingAndCanBeDismissed(): void
    {
        ob_start();
        (new CJW_Summer_Camp_First_Run())->renderNotice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('nog niet helemaal ingericht', $html);
        $this->assertStringContainsString('Homepage', $html);
        $this->assertStringContainsString('Privacyverklaring', $html);

        update_option(CJW_Summer_Camp_First_Run::OPTION_DISMISSED, '1');

        ob_start();
        (new CJW_Summer_Camp_First_Run())->renderNotice();

        $this->assertSame('', (string) ob_get_clean());
    }
}
