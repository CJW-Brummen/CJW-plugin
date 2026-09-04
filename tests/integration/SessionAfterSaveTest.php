<?php

declare(strict_types=1);

/**
 * What the wizard session still holds once a registration has been stored.
 *
 * Until the last step is submitted, the session transient is the only place a
 * half-filled registration exists, and it has to carry every answer so far.
 * The moment the submission is saved that stops being true: the answers live
 * on the submission, under the retention sweep and the privacy eraser. The
 * transient answers to neither, yet it kept a full copy -- a child's name,
 * birth date and allergies -- for the six hours the session lives on so that
 * the success screen survives a reload. That screen needs the registration
 * number and the mail result, and nothing else.
 *
 * Only a real WordPress can settle this: the session is a transient, the
 * nonce is WordPress's, and the whole path runs through do_shortcode().
 */
final class SessionAfterSaveTest extends CJW_IntegrationTestCase
{
    private const string TOKEN = 'sessionaftersavetest';

    private const string PARENT_EMAIL = 'karin.jansen@example.test';

    public function tear_down(): void
    {
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
        delete_transient('cjw_wizard_' . self::TOKEN);

        parent::tear_down();
    }

    /**
     * A published one-step form, so a single POST is a completed registration.
     */
    private function publishedForm(): int
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
                        [ 'key' => 'geboortedatum', 'label' => 'Geboortedatum', 'type' => 'date', 'role' => 'birth_date' ],
                        [ 'key' => 'allergie', 'label' => 'Allergieën', 'type' => 'textarea' ],
                        [ 'key' => 'email', 'label' => 'E-mailadres', 'type' => 'email' ],
                    ],
                ],
            ],
        ]));

        return $id;
    }

    /**
     * Submits the last step the way a browser would: the hidden fields the
     * renderer writes, a render stamp old enough to have been read by a person,
     * the answers under the prefix the engine strips, and the privacy tick.
     */
    private function submitLastStep(int $formId): string
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
            'cjw_field_geboortedatum' => '2016-04-11',
            'cjw_field_allergie' => 'Pinda-allergie, EpiPen in de tas',
            'cjw_field_email' => self::PARENT_EMAIL,
            // The privacy tick, which a browser posts along with the rest and
            // without which the last step is refused. See PrivacyConsentTest.
            CJW_Wizard_Privacy_Consent::FIELD => '1',
        ];

        return (string) do_shortcode('[cjw_wizard id="' . $formId . '"]');
    }

    /**
     * @return array<string, mixed>
     */
    private function storedSession(): array
    {
        wp_cache_flush();

        $state = get_transient('cjw_wizard_' . self::TOKEN);

        $this->assertIsArray($state, 'the session should still exist so the success screen survives a reload');

        return $state;
    }

    public function testTheAnswersLeaveTheSessionOnceTheyAreStored(): void
    {
        $formId = $this->publishedForm();

        $output = $this->submitLastStep($formId);
        $state = $this->storedSession();

        $this->assertStringContainsString('Gelukt!', $output);
        $this->assertSame([], $state['data'], 'nothing a parent typed should stay behind in the session');

        // Belt and braces: not under 'data', and not under any other key either.
        $serialized = (string) wp_json_encode($state, JSON_UNESCAPED_UNICODE);

        foreach ([ 'Fleur', '2016-04-11', 'Pinda', 'EpiPen' ] as $personal) {
            $this->assertStringNotContainsString($personal, $serialized, $personal);
        }
    }

    public function testTheSuccessScreenKeepsWhatItNeeds(): void
    {
        $formId = $this->publishedForm();

        $output = $this->submitLastStep($formId);
        $state = $this->storedSession();

        $submissionId = (int) ($state['completion']['submission'] ?? 0);

        $this->assertGreaterThan(0, $submissionId, 'the registration number must survive for the success screen');
        $this->assertStringContainsString((string) $submissionId, $output);

        // The address is the one thing kept: the screen tells the parent where
        // the confirmation went.
        $this->assertSame(self::PARENT_EMAIL, $state['completion']['email'] ?? null);
    }

    public function testTheAnswersAreOnTheSubmissionNotInTheSession(): void
    {
        $formId = $this->publishedForm();

        $this->submitLastStep($formId);
        $state = $this->storedSession();

        wp_cache_flush();

        $payload = (new CJW_Wizard_Submission_Repository())->get_payload((int) $state['completion']['submission']);

        $this->assertSame('Fleur', $payload['voornaam']);
        $this->assertSame('Pinda-allergie, EpiPen in de tas', $payload['allergie']);
    }

    /**
     * The success screen has no form, so a POST after it is a browser
     * re-sending the last step. It must not bring the answers back into the
     * session, must not lose the number, and must not register the child twice.
     */
    public function testARepostAfterTheSuccessScreenChangesNothing(): void
    {
        $formId = $this->publishedForm();

        $this->submitLastStep($formId);
        $first = $this->storedSession();
        $submissionId = (int) $first['completion']['submission'];
        $type = (string) get_post_type($submissionId);

        $output = $this->submitLastStep($formId);
        $second = $this->storedSession();

        $this->assertStringContainsString('Gelukt!', $output);
        $this->assertStringContainsString((string) $submissionId, $output);
        $this->assertSame([], $second['data'], 're-sending the last step must not refill the session');
        $this->assertSame($first['completion'], $second['completion']);

        // Counted across every status: a registration starts in the plugin's
        // own "Nieuw" status, which 'any' does not cover.
        wp_cache_flush();
        $stored = array_sum(array_map('intval', (array) wp_count_posts($type)));

        $this->assertSame(1, $stored, 'a re-post must not create a second registration');
    }
}
