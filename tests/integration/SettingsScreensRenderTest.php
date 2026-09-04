<?php

declare(strict_types=1);

/**
 * That the settings screens still render at all.
 *
 * Every panel on the Beheer tab and every field on the other tabs is code that
 * runs only when somebody opens that screen. A fatal there is invisible to
 * every other test in this suite and perfectly visible to the one volunteer
 * who opens the page, so each tab is rendered here once.
 */
final class SettingsScreensRenderTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        CJW_Roles::install();
        $this->actAsAdministrator();

        // The sections and fields this screen renders, registered directly
        // rather than by firing admin_init: that hook carries the whole site's
        // admin bootstrap, and half of it complains at being run outside
        // wp-admin.
        new CJW_Summer_Camp_Settings_Page()->register();
    }

    /**
     * @param string $tab Tab to open.
     */
    private function renderTab(string $tab): string
    {
        $_GET['page'] = 'cjw-summer-camp';
        $_GET['tab'] = $tab;

        ob_start();

        try {
            new CJW_Summer_Camp_Settings_Page()->render();
        } finally {
            $html = (string) ob_get_clean();
            unset($_GET['page'], $_GET['tab']);
        }

        return $html;
    }

    public function testEveryTabRenders(): void
    {
        foreach ([ 'general', 'theme', 'pricing', 'content', 'website', 'beheer' ] as $tab) {
            $this->assertStringContainsString('Zomerkamp Instellingen', $this->renderTab($tab), $tab);
        }
    }

    public function testTheContentTabOffersThePrivacyTextAndItsTokens(): void
    {
        $html = $this->renderTab('content');

        $this->assertStringContainsString('Privacytekst op het formulier', $html);
        $this->assertStringContainsString('{bewaartermijn}', $html);
        $this->assertStringContainsString('{contact_email}', $html);
    }

    public function testTheBeheerTabCarriesEveryPanel(): void
    {
        $html = $this->renderTab('beheer');

        foreach ([
            'Website inrichten',
            'Statuspagina voor bewaking',
            'E-mail',
            'Mail-veiligmodus',
            'Weekbericht',
            'Nieuw kampjaar',
        ] as $panel) {
            $this->assertStringContainsString($panel, $html, $panel);
        }
    }

    public function testTheStatusPanelShowsTheCrashAndTheWayToClearIt(): void
    {
        CJW_Fatal_Error_Watch::report('Uncaught Error: kaput', ABSPATH . 'wp-content/plugins/cjw/boom.php', 12);

        $html = $this->renderTab('beheer');

        $this->assertStringContainsString('Uncaught Error: kaput', $html);
        $this->assertStringContainsString(CJW_Fatal_Error_Watch::ACTION_CLEAR, $html);

        CJW_Fatal_Error_Watch::clear();

        $this->assertStringNotContainsString('Uncaught Error: kaput', $this->renderTab('beheer'));
    }
}
