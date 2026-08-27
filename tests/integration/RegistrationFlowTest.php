<?php

declare(strict_types=1);

/**
 * A parent registering a child, from the last step to a stored registration.
 *
 * Everything the other integration tests check in isolation, wired together
 * and run once: the form saves, the payload survives, the roles pick the child
 * and the camp out of it, the status starts at Nieuw, the confirmation goes to
 * the parent, and the notification to the organiser carries none of it.
 */
final class RegistrationFlowTest extends CJW_IntegrationTestCase
{
    private const string PARENT_EMAIL = 'karin.jansen@example.test';

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'settings' => [
                'send_parent_confirmation' => true,
                'parent_confirmation_subject' => 'Bevestiging inschrijving CJW',
                'parent_confirmation_body' => 'Bedankt voor je inschrijving.',
                'notification_email' => 'kamp@example.test',
                'send_admin_notification' => true,
            ],
            'steps' => [
                [
                    'key' => 'kind',
                    'title' => 'Kind',
                    'fields' => [
                        [ 'key' => 'voornaam', 'type' => 'text', 'label' => 'Voornaam', 'role' => 'child_name', 'required' => true ],
                        [ 'key' => 'achternaam', 'type' => 'text', 'label' => 'Achternaam', 'role' => 'child_last_name', 'required' => true ],
                        [ 'key' => 'geboortedatum', 'type' => 'date', 'label' => 'Geboortedatum', 'role' => 'birth_date' ],
                        [ 'key' => 'kamp', 'type' => 'select', 'label' => 'Kamp', 'role' => 'camp_type', 'options' => [
                            [ 'value' => 'Weekkamp (10 t/m 17 jaar) - €101', 'label' => 'Weekkamp (10 t/m 17 jaar) - €101' ],
                        ] ],
                        [ 'key' => 'allergie', 'type' => 'textarea', 'label' => 'Allergieën of medicijnen' ],
                    ],
                ],
                [
                    'key' => 'ouder',
                    'title' => 'Ouder',
                    'fields' => [
                        [ 'key' => 'email', 'type' => 'email', 'label' => 'E-mailadres', 'required' => true ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'voornaam' => 'Fleur',
            'achternaam' => 'Jansen',
            'geboortedatum' => '2016-04-11',
            'kamp' => 'Weekkamp (10 t/m 17 jaar) - €101',
            'allergie' => 'Pinda-allergie, EpiPen in de tas',
            'email' => self::PARENT_EMAIL,
        ];
    }

    private function register(): int
    {
        $submissions = new CJW_Wizard_Submissions();
        $wizardId = self::factory()->post->create([
            'post_type' => 'cjw_wizard',
            'post_title' => 'Inschrijfformulier zomerkamp',
        ]);

        $id = $submissions->save_submission($wizardId, $this->config(), $this->payload());
        $submissions->maybe_notify_admin($wizardId, $this->config(), $this->payload());
        $submissions->maybe_confirm_to_parent($id, $wizardId, $this->config(), $this->payload());

        return $id;
    }

    public function testARegistrationArrivesReadyToWorkWith(): void
    {
        $id = $this->register();

        wp_cache_flush();

        $repository = new CJW_Wizard_Submission_Repository();

        $this->assertSame(CJW_Wizard_Submission_Status::NEW, get_post_status($id));
        $this->assertSame('Fleur Jansen', $repository->get_child_name($id));
        $this->assertSame('Weekkamp (10 t/m 17 jaar) - €101', $repository->get_camp_type($id));
        $this->assertSame(self::PARENT_EMAIL, $repository->get_email($id));
        $this->assertSame('Pinda-allergie, EpiPen in de tas', $repository->get_payload($id)['allergie']);
    }

    public function testTheChildAppearsOnThePerChildScreen(): void
    {
        $id = $this->register();

        $snapshot = get_post_meta($id, CJW_Wizard_Submission_Repository::META_SNAPSHOT, true);
        $rows = CJW_Wizard_Field_Roles::childRows(
            json_decode((string) $snapshot, true),
            (new CJW_Wizard_Submission_Repository())->get_payload($id)
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Fleur Jansen', $rows[0]['name']);
        $this->assertSame('2016-04-11', $rows[0]['birth_date']);
    }

    public function testTheParentIsToldTheRegistrationArrived(): void
    {
        $sent = [];
        add_filter('wp_mail', static function (array $args) use (&$sent): array {
            $sent[] = $args;

            return $args;
        });

        $this->register();

        $toParent = array_values(array_filter($sent, static fn(array $m): bool => in_array(self::PARENT_EMAIL, (array) $m['to'], true)));

        $this->assertCount(1, $toParent);
        $this->assertSame('Bevestiging inschrijving CJW', $toParent[0]['subject']);
        $this->assertStringContainsString('Bedankt voor je inschrijving.', $toParent[0]['message']);
    }

    public function testTheOrganisersNotificationCarriesNoneOfTheChildsData(): void
    {
        $sent = [];
        add_filter('wp_mail', static function (array $args) use (&$sent): array {
            $sent[] = $args;

            return $args;
        });

        $this->register();

        $toOrganiser = array_values(array_filter($sent, static fn(array $m): bool => in_array('kamp@example.test', (array) $m['to'], true)));

        $this->assertCount(1, $toOrganiser);

        // Phase 3: the organiser's mail says a registration arrived and where
        // to read it. It does not put a child's allergies in an inbox.
        foreach ([ 'Fleur', 'Jansen', 'Pinda', 'EpiPen', '2016-04-11', self::PARENT_EMAIL ] as $personal) {
            $this->assertStringNotContainsString($personal, $toOrganiser[0]['message'], $personal);
            $this->assertStringNotContainsString($personal, $toOrganiser[0]['subject'], $personal);
        }
    }

    public function testTheRegistrationCanBeExportedAndErasedByEmailAddress(): void
    {
        $id = $this->register();

        $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
        $found = false;

        foreach ($exporters as $exporter) {
            $result = call_user_func($exporter['callback'], self::PARENT_EMAIL, 1);

            foreach ($result['data'] ?? [] as $group) {
                if (($group['item_id'] ?? '') === 'cjw-wizard-submission-' . $id) {
                    $found = true;
                }
            }
        }

        $this->assertTrue($found, 'the registration should be findable by the address the parent gave');

        $erasers = apply_filters('wp_privacy_personal_data_erasers', []);

        foreach ($erasers as $eraser) {
            call_user_func($eraser['callback'], self::PARENT_EMAIL, 1);
        }

        wp_cache_flush();

        $this->assertSame([], (new CJW_Wizard_Submission_Repository())->get_payload($id));
    }
}
