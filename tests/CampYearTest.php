<?php

declare(strict_types=1);

final class CampYearTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
        $GLOBALS['cjw_test_actions'] = [];
        $GLOBALS['cjw_test_current_user'] = new WP_User(3, 'Marieke');
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['cjw_test_options'],
            $GLOBALS['cjw_test_actions'],
            $GLOBALS['cjw_test_current_user']
        );

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function given(array $settings): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, $settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $stored = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    public function testTheYearComesFromTheThemeYear(): void
    {
        $this->given(['theme_year' => '2026', 'start_date' => '11-07-2030']);

        $this->assertSame('2026', CJW_Summer_Camp_Year::currentYear());
        $this->assertSame('2027', CJW_Summer_Camp_Year::nextYear());
    }

    public function testTheStartDateAnswersWhenNoThemeYearIsSet(): void
    {
        $this->given(['theme_year' => '', 'start_date' => '11-07-2030']);

        $this->assertSame('2030', CJW_Summer_Camp_Year::currentYear());
    }

    public function testStartingAYearClearsEveryDate(): void
    {
        $this->given([
            'theme_year' => '2026',
            'start_date' => '11-07-2026',
            'end_date' => '18-07-2026',
            'registration_deadline' => '01-06-2026',
            'early_bird_start' => '01-01-2026',
            'early_bird_end' => '01-03-2026',
            'drive_dates' => [['date' => '11-07-2026', 'label' => 'Heenreis']],
        ]);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $settings = $this->settings();

        $this->assertSame('', $settings['start_date']);
        $this->assertSame('', $settings['end_date']);
        $this->assertSame('', $settings['registration_deadline']);
        $this->assertSame('', $settings['early_bird_start']);
        $this->assertSame('', $settings['early_bird_end']);
        $this->assertSame([], $settings['drive_dates']);
    }

    public function testStartingAYearKeepsThePicturesAndTheCopy(): void
    {
        $this->given([
            'theme_year' => '2026',
            'theme_name' => 'Piraten',
            'hero_title' => 'Zomerkamp 2026',
            'hero_image_id' => 42,
            'polaroid_1_image_id' => 7,
            'polaroid_1_caption' => 'Kampvuur',
            'price_week' => 99,
            'contact_email' => 'info@example.test',
            'footer_about' => 'Al veertig jaar kamp.',
        ]);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $settings = $this->settings();

        $this->assertSame('Piraten', $settings['theme_name']);
        $this->assertSame('Zomerkamp 2026', $settings['hero_title']);
        $this->assertSame(42, $settings['hero_image_id']);
        $this->assertSame(7, $settings['polaroid_1_image_id']);
        $this->assertSame('Kampvuur', $settings['polaroid_1_caption']);
        $this->assertSame(99, $settings['price_week']);
        $this->assertSame('info@example.test', $settings['contact_email']);
        $this->assertSame('Al veertig jaar kamp.', $settings['footer_about']);
    }

    public function testStartingAYearRollsTheThemeYear(): void
    {
        $this->given(['theme_year' => '2026']);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $this->assertSame('2027', $this->settings()['theme_year']);
    }

    /**
     * Clearing the deadline is what makes this mandatory rather than a
     * preference: isRegistrationOpen() reads an empty deadline as "no deadline",
     * so leaving registration active would throw the form open indefinitely for
     * a camp that has no date yet.
     */
    public function testStartingAYearClosesRegistration(): void
    {
        $this->given([
            'theme_year' => '2026',
            'registration_active' => true,
            'registration_deadline' => '01-06-2026',
        ]);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $this->assertFalse($this->settings()['registration_active']);
        $this->assertFalse(cjw_summer_camp()->isRegistrationOpen());
    }

    /**
     * Only the half start() controls on its own: that the snapshot the save is
     * about to take gets named after the year being closed. Whether saving the
     * option takes a snapshot at all is a question about WordPress filters, and
     * it is answered in the integration suite against a real one -- the shim
     * here does not dispatch them, and a test that pretended otherwise would
     * pass on a codebase where this was broken.
     */
    public function testStartingAYearNamesTheArchiveAfterTheOutgoingYear(): void
    {
        $this->given(['theme_year' => '2026', 'theme_name' => 'Piraten']);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        new CJW_Summer_Camp_Settings_History()->snapshotBeforeUpdate(
            ['theme_name' => 'Ruimtevaart'],
            ['theme_name' => 'Piraten']
        );

        $this->assertSame(
            'Archief van kampjaar 2026',
            CJW_Summer_Camp_Settings_History::all()[0]['reason']
        );
    }

    public function testStartingAYearAnnouncesItself(): void
    {
        $this->given(['theme_year' => '2026']);

        new CJW_Summer_Camp_Year()->start('2026', '2027');

        $announced = array_values(array_filter(
            $GLOBALS['cjw_test_actions'],
            static fn(array $call): bool => $call['hook'] === CJW_Summer_Camp_Year::HOOK_STARTED
        ));

        $this->assertCount(1, $announced);
        $this->assertSame(['2026', '2027'], $announced[0]['args']);
    }

    public function testEveryDateFieldIsOneTheSettingsActuallyHave(): void
    {
        $known = array_keys(CJW_Summer_Camp_Service::sanitize([]));

        foreach (CJW_Summer_Camp_Year::DATE_FIELDS as $field) {
            $this->assertContains($field, $known, $field . ' is not a real setting');
        }
    }

    public function testTheEffectsListSaysWhatIsClearedAndWhatIsKept(): void
    {
        $this->given(['theme_year' => '2026']);

        $effects = implode(' ', CJW_Summer_Camp_Year::effects());

        $this->assertStringContainsString('2026', $effects);
        $this->assertStringContainsString('2027', $effects);
        $this->assertStringContainsString('kampdata', $effects);
        $this->assertStringContainsString('blijven staan', $effects);
    }
}
