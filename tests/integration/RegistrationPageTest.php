<?php

declare(strict_types=1);

/**
 * Where the "Schrijf je in" buttons point.
 *
 * They used to default to `#inschrijven`, an anchor no template carries, so
 * the primary call to action scrolled nowhere -- and activation wrote that
 * default into every existing site's settings, where a default-only fix could
 * not reach it. One canonical answer now: the address the organiser typed, or
 * the published page that actually carries the shortcode.
 *
 * Integration rather than shim: finding the page is a WP_Query, the cache is a
 * transient, and the flushing rides on WordPress's own post hooks.
 */
final class RegistrationPageTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        CJW_Registration_Page::flush();
        $this->settings([]);
    }

    public function tear_down(): void
    {
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);
        CJW_Summer_Camp_Service::flush();
        CJW_Registration_Page::flush();

        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function settings(array $overrides): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, array_merge(
            CJW_Summer_Camp_Service::defaults(),
            [ 'registration_active' => true, 'registration_deadline' => '' ],
            $overrides
        ));
        CJW_Summer_Camp_Service::flush();
    }

    private function formPage(string $status = 'publish', string $content = '[cjw_wizard id="12"]'): int
    {
        return self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => $status,
            'post_title' => 'Inschrijven',
            'post_content' => $content,
        ]);
    }

    public function testThePublishedPageCarryingTheShortcodeIsFound(): void
    {
        $page = $this->formPage();

        $this->assertSame($page, CJW_Registration_Page::id());
        $this->assertSame(get_permalink($page), cjw_summer_camp()->getRegistrationPageUrl());
    }

    public function testADraftDoesNotCount(): void
    {
        $this->formPage('draft');

        $this->assertSame(0, CJW_Registration_Page::id());
        $this->assertSame('', cjw_summer_camp()->getRegistrationPageUrl());
    }

    /**
     * A page that merely talks about the shortcode is not the form.
     */
    public function testMentioningTheShortcodeInProseIsNotTheSameAsCarryingIt(): void
    {
        $this->formPage('publish', '<p>Plak de shortcode cjw_wizard op een pagina.</p>');

        $this->assertSame(0, CJW_Registration_Page::id());
    }

    public function testTheAddressTheOrganiserTypedWinsOverTheFoundPage(): void
    {
        $this->formPage();
        $this->settings([ 'late_registration_link' => 'https://example.test/schrijf-je-in/' ]);

        $this->assertSame('https://example.test/schrijf-je-in/', cjw_summer_camp()->getRegistrationPageUrl());
    }

    public function testTrashingThePageIsNoticedAtOnce(): void
    {
        $page = $this->formPage();

        $this->assertSame($page, CJW_Registration_Page::id(), 'primes the cache');

        wp_trash_post($page);

        $this->assertSame(0, CJW_Registration_Page::id());
        $this->assertSame('', cjw_summer_camp()->getRegistrationPageUrl());
    }

    public function testTheFoundPageIsCachedUntilAPageChanges(): void
    {
        $page = $this->formPage();

        CJW_Registration_Page::id();

        $this->assertSame([ 'id' => $page ], get_transient(CJW_Registration_Page::TRANSIENT));

        self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Iets anders' ]);

        $this->assertFalse(get_transient(CJW_Registration_Page::TRANSIENT), 'any page save drops the cache');
    }

    public function testTheHeroButtonFallsBackToTheFormPage(): void
    {
        $page = $this->formPage();

        $this->assertSame(get_permalink($page), cjw_summer_camp()->getPrimaryHeroCta()['url']);
    }

    /**
     * Activation wrote `#inschrijven` into the settings of every site that had
     * the plugin. Stored, it means "never set".
     */
    public function testTheAnchorActivationUsedToWriteIsTreatedAsUnset(): void
    {
        $page = $this->formPage();
        $this->settings([ 'hero_primary_cta_url' => '#inschrijven' ]);

        $this->assertSame(get_permalink($page), cjw_summer_camp()->getPrimaryHeroCta()['url']);
    }

    public function testAnAddressTheOrganiserChoseForTheHeroButtonStillWins(): void
    {
        $this->formPage();
        $this->settings([ 'hero_primary_cta_url' => 'https://example.test/ergens-anders/' ]);

        $this->assertSame('https://example.test/ergens-anders/', cjw_summer_camp()->getPrimaryHeroCta()['url']);
    }

    public function testTheLateRegistrationLinkIsBuiltOnTheSamePage(): void
    {
        $page = $this->formPage();
        $this->settings([ 'late_registration_token' => 'abcdef1234567890' ]);

        $link = cjw_summer_camp()->getLateRegistrationLink();

        $this->assertStringStartsWith(get_permalink($page), $link);
        $this->assertStringContainsString(CJW_Summer_Camp_Service::getLateRegistrationQueryArg() . '=abcdef1234567890', $link);
    }

    public function testWithoutAPageTheButtonHasNowhereToGo(): void
    {
        $this->assertSame('', cjw_summer_camp()->getPrimaryHeroCta()['url']);
    }

    public function testSiteHealthIsCriticalWhenRegistrationIsOpenAndThereIsNoPage(): void
    {
        $result = (new CJW_Summer_Camp_Site_Health())->testRegistrationPage();

        $this->assertSame('critical', $result['status']);
    }

    public function testSiteHealthOnlyRecommendsWhileRegistrationIsClosed(): void
    {
        $this->settings([ 'registration_active' => false ]);

        $result = (new CJW_Summer_Camp_Site_Health())->testRegistrationPage();

        $this->assertSame('recommended', $result['status']);
    }

    public function testSiteHealthIsGoodOnceThePageExists(): void
    {
        $page = $this->formPage();

        $result = (new CJW_Summer_Camp_Site_Health())->testRegistrationPage();

        $this->assertSame('good', $result['status']);
        $this->assertStringContainsString(get_permalink($page), $result['description']);
    }
}
