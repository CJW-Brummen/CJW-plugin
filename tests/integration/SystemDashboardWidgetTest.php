<?php

declare(strict_types=1);

/**
 * The administrator's dashboard widget.
 *
 * A second widget beside the kampbeheerder's "Zomerkamp" one, on purpose: this
 * is about whether the machine is running, and mixed into the other it would
 * show a volunteer PHP versions they have no use for while burying the dates
 * and prices they came for.
 */
final class SystemDashboardWidgetTest extends CJW_IntegrationTestCase
{
    private const string WIDGET_ID = 'cjw_system_dashboard_widget';

    public function set_up(): void
    {
        parent::set_up();

        require_once ABSPATH . 'wp-admin/includes/dashboard.php';

        CJW_Roles::install();
        $GLOBALS['wp_meta_boxes'] = [];

        // wp_add_dashboard_widget() files the box under the current screen, and
        // without one it quietly registers nothing at all.
        set_current_screen('dashboard');

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::defaults());
        CJW_Summer_Camp_Service::flush();
        CJW_Status_Endpoint::flush();
        delete_option(CJW_Fatal_Error_Watch::OPTION_KEY);
        delete_option(CJW_Alerts::OPTION_KEY);
    }

    public function tear_down(): void
    {
        $GLOBALS['wp_meta_boxes'] = [];
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);
        delete_option(CJW_Fatal_Error_Watch::OPTION_KEY);
        delete_option(CJW_Alerts::OPTION_KEY);
        CJW_Summer_Camp_Service::flush();
        CJW_Status_Endpoint::flush();

        parent::tear_down();
    }

    private function registeredWidgets(): array
    {
        new CJW_System_Dashboard_Widget()->register();

        return array_keys($GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'] ?? []);
    }

    private function render(): string
    {
        ob_start();

        try {
            new CJW_System_Dashboard_Widget()->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    public function testAnAdministratorGetsIt(): void
    {
        $this->actAsAdministrator();

        $this->assertContains(self::WIDGET_ID, $this->registeredWidgets());
    }

    /**
     * A kampbeheerder has their own widget. This one is not for them.
     */
    public function testAKampbeheerderDoesNotGetIt(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => CJW_Roles::ROLE ]));

        $this->assertNotContains(self::WIDGET_ID, $this->registeredWidgets());
    }

    public function testASubscriberDoesNotGetIt(): void
    {
        $this->actAsSubscriber();

        $this->assertNotContains(self::WIDGET_ID, $this->registeredWidgets());
    }

    /**
     * It sits beside the camp widget rather than replacing it.
     */
    public function testTheCampWidgetIsStillThereToo(): void
    {
        $this->actAsAdministrator();

        new CJW_Summer_Camp_Dashboard_Widget()->register();
        new CJW_System_Dashboard_Widget()->register();

        $widgets = array_keys($GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'] ?? []);

        $this->assertContains('cjw_summer_camp_dashboard_widget', $widgets);
        $this->assertContains(self::WIDGET_ID, $widgets);
    }

    public function testAHealthySiteSaysSoAndListsNoProblems(): void
    {
        $this->actAsAdministrator();
        update_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST, [
            'time' => time(),
            'to' => 'beheer@example.test',
            'ok' => true,
            'error' => '',
            'by' => 'Marieke',
        ]);

        $html = $this->render();

        $this->assertStringContainsString('In orde', $html);
        $this->assertStringNotContainsString('<li', $html);

        delete_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST);
    }

    public function testACrashIsShownAsAFaultWorthWakingSomebodyFor(): void
    {
        $this->actAsAdministrator();
        CJW_Fatal_Error_Watch::report('Uncaught Error: kaput', ABSPATH . 'wp-content/plugins/cjw/boom.php', 12);

        $html = $this->render();

        $this->assertStringContainsString('Storing', $html);
        $this->assertStringContainsString('De website is vastgelopen.', $html);
        $this->assertStringContainsString('wp-content/plugins/cjw/boom.php', $html);
    }

    public function testSomethingSmallerReadsAsAWarningRatherThanAnOutage(): void
    {
        $this->actAsAdministrator();

        $html = $this->render();

        // Mail has never been tested on this fixture, which is exactly the
        // kind of thing that should not read as an outage.
        $this->assertStringContainsString('Let op', $html);
        $this->assertStringNotContainsString('Storing', $html);
    }

    public function testItCountsThisCampYearsRegistrations(): void
    {
        $this->actAsAdministrator();

        $wizard = self::factory()->post->create([ 'post_type' => CJW_Wizard_Post_Type::SLUG ]);
        $repository = new CJW_Wizard_Submission_Repository();
        $config = [
            'steps' => [
                [
                    'title' => 'Kind',
                    'fields' => [
                        [ 'key' => 'naam', 'type' => 'text', 'label' => 'Naam', 'role' => 'child_name' ],
                        [ 'key' => 'fotos', 'type' => 'radio', 'label' => 'Foto’s', 'role' => 'photo_consent' ],
                    ],
                ],
            ],
        ];

        $repository->save($wizard, $config, [ 'naam' => 'Fleur', 'fotos' => 'nee' ]);
        $repository->save($wizard, $config, [ 'naam' => 'Sem', 'fotos' => 'ja' ]);

        wp_cache_flush();
        $html = $this->render();

        $this->assertStringContainsString('2 inschrijvingen voor ' . CJW_Wizard_Submission_Repository::currentCampYear(), $html);
        $this->assertStringContainsString('2 in de afgelopen 7 dagen', $html);
        $this->assertStringContainsString('1 kind zonder fototoestemming', $html);
    }

    /**
     * The endpoint only reports a job once it is late. Here is the same
     * information before that point, which is where somebody notices that WP
     * Cron stopped firing at all.
     */
    public function testItShowsWhenEachScheduledJobLastRan(): void
    {
        $this->actAsAdministrator();
        CJW_Cron_Heartbeat::record(CJW_Wizard_Submission_Retention::HOOK);

        $html = $this->render();

        $this->assertStringContainsString('Geplande taken', $html);
        $this->assertStringContainsString('Bewaartermijn opschonen', $html);
        $this->assertStringContainsString('geleden', $html);
    }

    public function testItSaysWhichInstallationThisIsAndWhatItRuns(): void
    {
        $this->actAsAdministrator();

        $html = $this->render();

        $this->assertStringContainsString('Omgeving', $html);
        $this->assertStringContainsString('CJW ' . CJW_VERSION, $html);
        $this->assertStringContainsString('PHP ' . PHP_VERSION, $html);
        $this->assertStringContainsString('Live website', $html);
    }

    public function testACopyOfTheSiteIsMarkedAsOne(): void
    {
        $this->actAsAdministrator();
        update_option(CJW_Mail_Safe_Mode::OPTION_PRODUCTION_HOST, 'www.cjw-brummen.nl');

        $html = $this->render();

        $this->assertStringContainsString('Kopie', $html);
        $this->assertStringNotContainsString('Live website', $html);

        delete_option(CJW_Mail_Safe_Mode::OPTION_PRODUCTION_HOST);
    }

    public function testItLinksOnwardsRatherThanRepeatingEverything(): void
    {
        $this->actAsAdministrator();

        $html = $this->render();

        $this->assertStringContainsString('tab=beheer', $html);
        $this->assertStringContainsString('site-health.php', $html);
    }
}
