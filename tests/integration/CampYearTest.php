<?php

declare(strict_types=1);

/**
 * Starting a new camp year, with both modules present.
 *
 * The interesting part is the seam: the summer-camp module clears its settings
 * and announces the year, and the form builder scrubs last season's
 * registrations without either module referencing the other. Only a real
 * WordPress dispatches that.
 */
final class CampYearTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(CJW_Summer_Camp_Settings_History::OPTION_KEY);
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);

        $this->actAsAdministrator();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function given(array $settings): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize($settings));
    }

    /**
     * A registration filed under a camp year, holding the details that matter.
     */
    private function registration(string $year, string $child = 'Anna de Vries'): int
    {
        $id = self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::CONFIRMED,
            'post_title' => 'Inschrijving',
        ]);

        update_post_meta($id, CJW_Wizard_Submission_Repository::META_CAMP_YEAR, $year);
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_CHILD_NAME, $child);
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_EMAIL, 'ouder@example.test');
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_PAYLOAD, wp_json_encode([ 'naam' => $child ]));
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_CAMP_TYPE, 'week');

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $stored = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    public function testLastSeasonsRegistrationsLoseTheirPersonalData(): void
    {
        $this->given([ 'theme_year' => '2026' ]);
        $last_year = $this->registration('2026');

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $repository = new CJW_Wizard_Submission_Repository();

        $this->assertTrue($repository->is_anonymised($last_year));
        $this->assertSame('', $repository->get_child_name($last_year));
        $this->assertSame('', $repository->get_email($last_year));
        $this->assertSame([], $repository->get_payload($last_year));
    }

    /**
     * The count of who came is not personal data, and it is the one thing a
     * camp genuinely wants years later.
     */
    public function testTheCampItselfIsStillCountable(): void
    {
        $this->given([ 'theme_year' => '2026' ]);
        $last_year = $this->registration('2026');

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $repository = new CJW_Wizard_Submission_Repository();

        $this->assertSame('week', $repository->get_camp_type($last_year));
        $this->assertSame('2026', $repository->get_camp_year($last_year));
    }

    public function testAnEarlyRegistrationForTheNewYearIsLeftAlone(): void
    {
        $this->given([ 'theme_year' => '2026' ]);
        $next_year = $this->registration('2027', 'Sem Jansen');

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $repository = new CJW_Wizard_Submission_Repository();

        $this->assertFalse($repository->is_anonymised($next_year));
        $this->assertSame('Sem Jansen', $repository->get_child_name($next_year));
    }

    public function testTheChildsNameIsGoneFromTheTitleAndTheSlug(): void
    {
        $this->given([ 'theme_year' => '2026' ]);

        $id = $this->registration('2026');
        wp_update_post([ 'ID' => $id, 'post_name' => 'anna-de-vries' ]);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $post = get_post($id);

        $this->assertStringNotContainsString('Anna', (string) $post->post_title);
        $this->assertStringNotContainsString('anna', (string) $post->post_name);
    }

    public function testTheDatesAreClearedAndTheRestIsNot(): void
    {
        $this->given([
            'theme_year' => '2026',
            'theme_name' => 'Piraten',
            'start_date' => '11-07-2026',
            'end_date' => '18-07-2026',
            'registration_deadline' => '01-06-2026',
            'hero_image_id' => 42,
            'price_week' => 99,
        ]);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $settings = $this->settings();

        $this->assertSame('', $settings['start_date']);
        $this->assertSame('', $settings['end_date']);
        $this->assertSame('', $settings['registration_deadline']);
        $this->assertSame('2027', $settings['theme_year']);
        $this->assertSame('Piraten', $settings['theme_name']);
        $this->assertSame(42, $settings['hero_image_id']);
        $this->assertSame(99, $settings['price_week']);
    }

    public function testTheOldSettingsAreArchivedUnderTheYearTheyBelongedTo(): void
    {
        $this->given([ 'theme_year' => '2026', 'theme_name' => 'Piraten' ]);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $snapshots = CJW_Summer_Camp_Settings_History::all();

        $this->assertSame('Archief van kampjaar 2026', $snapshots[0]['reason']);
        $this->assertSame('2026', $snapshots[0]['settings']['theme_year']);
        $this->assertSame('Piraten', $snapshots[0]['settings']['theme_name']);
    }

    public function testTheArchiveIsTheOnlySnapshotTheRolloverLeaves(): void
    {
        $this->given([ 'theme_year' => '2026' ]);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $this->assertCount(1, CJW_Summer_Camp_Settings_History::all());
    }

    public function testRegistrationDoesNotStayOpenWithoutADeadline(): void
    {
        $this->given([
            'theme_year' => '2026',
            'registration_active' => true,
            'registration_deadline' => '01-06-2026',
        ]);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $this->assertFalse(new CJW_Summer_Camp_Service()->isRegistrationOpen());
    }

    /**
     * The button has to say what it will do before it is pressed, and the count
     * of affected registrations is knowledge only the other module has.
     */
    public function testTheButtonWarnsAboutTheRegistrationsItWillScrub(): void
    {
        $this->given([ 'theme_year' => '2026' ]);
        $this->registration('2026');
        $this->registration('2026', 'Sem Jansen');

        $effects = implode(' ', CJW_Summer_Camp_Year::effects());

        $this->assertStringContainsString('2 inschrijvingen', $effects);
        $this->assertStringContainsString('geanonimiseerd', $effects);
    }

    public function testTheWarningIsAbsentWhenThereIsNothingToScrub(): void
    {
        $this->given([ 'theme_year' => '2026' ]);

        $this->assertStringNotContainsString('geanonimiseerd', implode(' ', CJW_Summer_Camp_Year::effects()));
    }
}
