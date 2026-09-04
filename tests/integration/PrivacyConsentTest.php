<?php

declare(strict_types=1);

/**
 * The privacy text on the form, and the tick that has to go with it.
 *
 * The form asks for a child's full name, date of birth and medication notes.
 * It said nothing about who receives that or how long it is kept, while the
 * site has known the retention answer all along -- a cron enforces it nightly.
 *
 * Integration rather than shim: the refusal runs through do_shortcode() and a
 * real nonce, the record is post meta, and the stripping of unsafe markup is
 * wp_kses_post()'s own behaviour rather than this plugin's.
 */
final class PrivacyConsentTest extends CJW_IntegrationTestCase
{
    private const string TOKEN = 'privacyconsenttest';

    public function set_up(): void
    {
        parent::set_up();

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, array_merge(
            CJW_Summer_Camp_Service::defaults(),
            [
                'end_date' => '18-07-2027',
                'retention_months' => 12,
                'contact_email' => 'kamp@example.test',
            ]
        ));
        CJW_Summer_Camp_Service::flush();
    }

    public function tear_down(): void
    {
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
        delete_transient('cjw_wizard_' . self::TOKEN);
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);
        CJW_Summer_Camp_Service::flush();

        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $settings Per-form settings to store.
     */
    private function publishedForm(array $settings = []): int
    {
        $id = self::factory()->post->create([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_title' => 'Inschrijfformulier',
            'post_status' => 'publish',
        ]);

        update_post_meta($id, '_cjw_wizard_config', (string) wp_json_encode([
            'steps' => [
                [
                    'key' => 'kind',
                    'label' => 'Kind',
                    'type' => 'default',
                    'fields' => [
                        [ 'key' => 'voornaam', 'label' => 'Voornaam', 'type' => 'text', 'role' => 'child_name' ],
                        [ 'key' => 'email', 'label' => 'E-mailadres', 'type' => 'email', 'role' => 'parent_email' ],
                    ],
                ],
            ],
        ]));

        if ($settings !== []) {
            update_post_meta($id, CJW_Wizard_Settings::META_KEY, $settings);
        }

        return $id;
    }

    /**
     * @param bool $tick Whether the parent ticked the box.
     */
    private function submit(int $formId, bool $tick): string
    {
        $issued = (string) (time() - 30);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'cjw_wizard_token' => self::TOKEN,
            'cjw_wizard_nonce' => wp_create_nonce('cjw_wizard_submit_' . self::TOKEN),
            'cjw_wizard_action' => 'next',
            CJW_Wizard_Guard::HONEYPOT_FIELD => '',
            CJW_Wizard_Guard::TIMESTAMP_FIELD => $issued . '.' . hash_hmac('sha256', $issued, wp_salt('nonce')),
            'cjw_field_voornaam' => 'Fleur',
            'cjw_field_email' => 'karin@example.test',
        ];

        if ($tick) {
            $_POST[ CJW_Wizard_Privacy_Consent::FIELD ] = '1';
        }

        return (string) do_shortcode('[cjw_wizard id="' . $formId . '"]');
    }

    private function registrations(): array
    {
        return get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::inAllList(),
            'numberposts' => -1,
        ]);
    }

    public function testTheTextAndTheTickAreOnTheLastStep(): void
    {
        $output = (string) do_shortcode('[cjw_wizard id="' . $this->publishedForm() . '"]');

        $this->assertStringContainsString('cjw-wizard-privacy', $output);
        $this->assertStringContainsString('name="' . CJW_Wizard_Privacy_Consent::FIELD . '"', $output);
        $this->assertStringContainsString('12 maanden na afloop van het kamp', $output);
        $this->assertStringContainsString('kamp@example.test', $output);
        $this->assertStringContainsString('2028', $output, 'the date the data is erased');
    }

    public function testWithoutTheTickNothingIsStoredAndTheParentIsTold(): void
    {
        $output = $this->submit($this->publishedForm(), false);

        $this->assertSame([], $this->registrations());
        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringContainsString('pas versturen als je akkoord gaat', $output);
        $this->assertStringContainsString('cjw-wizard-privacy--invalid', $output);
    }

    /**
     * Refusing must not throw away what the parent already typed.
     */
    public function testTheAnswersSurviveTheRefusal(): void
    {
        $output = $this->submit($this->publishedForm(), false);

        $this->assertStringContainsString('value="Fleur"', $output);
        $this->assertStringContainsString('value="karin@example.test"', $output);
    }

    public function testWithTheTickTheRegistrationIsStoredAndTheConsentRecorded(): void
    {
        $output = $this->submit($this->publishedForm(), true);

        $this->assertStringContainsString('Gelukt!', $output);

        $registrations = $this->registrations();
        $this->assertCount(1, $registrations);

        $consent = CJW_Wizard_Privacy_Consent::recorded((int) $registrations[0]->ID);

        $this->assertIsArray($consent);
        $this->assertGreaterThan(0, $consent['at']);
        $this->assertSame(CJW_Privacy_Notice::consentLabel(), $consent['label']);

        // The wording as it was on screen, so a later rewrite cannot change
        // what this parent is recorded as having read.
        $this->assertStringContainsString('12 maanden na afloop van het kamp', $consent['text']);
        $this->assertStringNotContainsString('<', $consent['text']);
    }

    /**
     * Once the name and the address are gone the record identifies nobody, and
     * it is the only remaining evidence that the registration was taken
     * properly.
     */
    public function testTheRecordSurvivesAnonymisation(): void
    {
        $this->submit($this->publishedForm(), true);

        $id = (int) $this->registrations()[0]->ID;
        $repository = new CJW_Wizard_Submission_Repository();

        $repository->anonymise($id);
        wp_cache_flush();

        $this->assertTrue($repository->is_anonymised($id));
        $this->assertIsArray(CJW_Wizard_Privacy_Consent::recorded($id));
    }

    public function testAFormWithTheTickSwitchedOffSubmitsWithoutIt(): void
    {
        $form = $this->publishedForm([ 'require_privacy_consent' => false ]);

        $output = $this->submit($form, false);

        $this->assertStringContainsString('Gelukt!', $output);
        $this->assertCount(1, $this->registrations());
        $this->assertNull(CJW_Wizard_Privacy_Consent::recorded((int) $this->registrations()[0]->ID));
    }

    public function testAFormWithTheTickSwitchedOffShowsNoText(): void
    {
        $output = (string) do_shortcode('[cjw_wizard id="' . $this->publishedForm([ 'require_privacy_consent' => false ]) . '"]');

        $this->assertStringNotContainsString('cjw-wizard-privacy', $output);
    }

    /**
     * The organisers write this text themselves, so it goes through the same
     * filter as any other editor content.
     */
    public function testUnsafeMarkupInTheOrganisersOwnTextIsStripped(): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, array_merge(
            (array) get_option(CJW_Summer_Camp_Service::OPTION_KEY),
            [ CJW_Privacy_Notice::SETTING => '<p>Onze tekst</p><script>alert(1)</script>' ]
        ));
        CJW_Summer_Camp_Service::flush();

        $text = CJW_Privacy_Notice::text();

        $this->assertStringContainsString('Onze tekst', $text);
        $this->assertStringNotContainsString('<script', $text);
    }

    public function testTheLinkToThePrivacyPageIsAddedWhenThereIsOne(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Privacyverklaring' ]);
        update_option('wp_page_for_privacy_policy', $page);

        $this->assertStringContainsString(get_privacy_policy_url(), CJW_Privacy_Notice::text());
        $this->assertStringContainsString('Lees de volledige privacyverklaring', CJW_Privacy_Notice::text());

        delete_option('wp_page_for_privacy_policy');
    }

    public function testWithoutAPrivacyPageNoBrokenLinkIsShown(): void
    {
        delete_option('wp_page_for_privacy_policy');

        $this->assertStringNotContainsString('Lees de volledige privacyverklaring', CJW_Privacy_Notice::text());
    }
}
