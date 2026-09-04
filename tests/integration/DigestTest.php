<?php

declare(strict_types=1);

/**
 * The weekly mail that carries the dashboard widget to people who never open
 * the dashboard.
 *
 * Against a real WordPress because almost everything here is WordPress: who
 * holds a capability, what the Site Health checks answer, whether a cron event
 * is scheduled, and whether wp_mail() got as far as handing the message off.
 */
final class DigestTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(CJW_Summer_Camp_Digest::OPTION_KEY);
        CJW_Summer_Camp_Digest::unschedule();
        reset_phpmailer_instance();

        CJW_Roles::install();
        $this->actAsAdministrator();
    }

    public function tear_down(): void
    {
        CJW_Summer_Camp_Digest::unschedule();
        reset_phpmailer_instance();

        parent::tear_down();
    }

    /**
     * A camp with nothing wrong with it, so the tests decide what is reported.
     *
     * Every camp Site Health check has to pass here, including the two that are
     * not settings: a photo on the wall, and evidence that mail works.
     *
     * @param array<string, mixed> $overrides
     */
    private function healthyCamp(array $overrides = []): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize(array_merge([
            'theme_year' => '2027',
            'start_date' => '10-07-2027',
            'end_date' => '17-07-2027',
            'registration_deadline' => '01-06-2027',
            'contact_email' => 'info@example.test',
            // A healthy site has somewhere for the signup buttons to go.
            'late_registration_link' => 'https://example.test/inschrijven/',
            'polaroid_1_image_id' => self::factory()->attachment->create_object([
                'file' => 'kampvuur.jpg',
                'post_mime_type' => 'image/jpeg',
            ]),
        ], $overrides)));

        update_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST, [
            'time' => time(),
            'to' => 'info@example.test',
            'ok' => true,
            'error' => '',
            'by' => 'Marieke',
        ]);

        // Stands in for an SMTP plugin. WP_UnitTestCase restores the hook
        // registry between tests, so this does not leak into the next one.
        add_action('phpmailer_init', '__return_false');
    }

    public function testItIsOffUntilSomebodySwitchesItOn(): void
    {
        $this->assertFalse(CJW_Summer_Camp_Digest::isEnabled());
        $this->assertFalse(wp_next_scheduled(CJW_Summer_Camp_Digest::HOOK));
    }

    public function testSwitchingItOnSchedulesIt(): void
    {
        update_option(CJW_Summer_Camp_Digest::OPTION_KEY, [ 'enabled' => true ]);
        CJW_Summer_Camp_Digest::schedule();

        $this->assertIsInt(wp_next_scheduled(CJW_Summer_Camp_Digest::HOOK));
    }

    public function testSwitchingItOffUnschedulesIt(): void
    {
        CJW_Summer_Camp_Digest::schedule();
        CJW_Summer_Camp_Digest::unschedule();

        $this->assertFalse(wp_next_scheduled(CJW_Summer_Camp_Digest::HOOK));
    }

    public function testNothingIsSentWhileItIsSwitchedOff(): void
    {
        $this->assertFalse(new CJW_Summer_Camp_Digest()->maybeSend());
    }

    /**
     * A weekly mail that reads "alles in orde" for eight months is a weekly
     * mail nobody opens in September.
     */
    public function testAQuietWeekProducesNoMail(): void
    {
        $this->healthyCamp();
        update_option(CJW_Summer_Camp_Digest::OPTION_KEY, [ 'enabled' => true ]);

        // Nothing failing, no deadline in sight, no registrations.
        $this->assertSame([], CJW_Summer_Camp_Digest::sections());
        $this->assertFalse(new CJW_Summer_Camp_Digest()->maybeSend());
    }

    public function testAMissingSettingIsReported(): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize([]));

        $titles = array_column(CJW_Summer_Camp_Digest::sections(), 'title');

        $this->assertContains('Dit heeft aandacht nodig', $titles);
    }

    /**
     * The checks are the Site Health ones, not a second copy of them: a check
     * added there has to appear here without anyone remembering to add it.
     */
    public function testTheReportedProblemsAreTheSiteHealthOnes(): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize([]));

        $sections = CJW_Summer_Camp_Digest::sections();
        $attention = [];

        foreach ($sections as $section) {
            if ($section['title'] === 'Dit heeft aandacht nodig') {
                $attention = $section['lines'];
            }
        }

        $this->assertContains(new CJW_Summer_Camp_Site_Health()->testCampDates()['label'], $attention);
    }

    public function testANearDeadlineIsReported(): void
    {
        $deadline = new DateTimeImmutable('now', wp_timezone())->modify('+10 days');

        $this->healthyCamp([
            'start_date' => $deadline->modify('+30 days')->format('d-m-Y'),
            'end_date' => $deadline->modify('+37 days')->format('d-m-Y'),
            'registration_deadline' => $deadline->format('d-m-Y'),
            'registration_active' => true,
        ]);

        $lines = implode(' ', array_merge(...array_column(CJW_Summer_Camp_Digest::sections(), 'lines')));

        $this->assertStringContainsString('10 dagen', $lines);
        $this->assertStringContainsString('staan open', $lines);
    }

    public function testADistantDeadlineIsNotWorthAMail(): void
    {
        $deadline = new DateTimeImmutable('now', wp_timezone())->modify('+200 days');

        $this->healthyCamp([
            'start_date' => $deadline->modify('+30 days')->format('d-m-Y'),
            'end_date' => $deadline->modify('+37 days')->format('d-m-Y'),
            'registration_deadline' => $deadline->format('d-m-Y'),
        ]);

        $this->assertSame([], CJW_Summer_Camp_Digest::sections());
    }

    public function testItGoesToEveryoneWhoRunsTheCamp(): void
    {
        $manager = self::factory()->user->create([
            'role' => CJW_Roles::ROLE,
            'user_email' => 'kampbeheerder@example.test',
        ]);
        self::factory()->user->create([
            'role' => 'subscriber',
            'user_email' => 'abonnee@example.test',
        ]);

        $recipients = CJW_Summer_Camp_Digest::recipients();

        $this->assertContains('kampbeheerder@example.test', $recipients);
        $this->assertNotContains('abonnee@example.test', $recipients);
        $this->assertNotEmpty($manager);
    }

    /**
     * Volunteers' addresses are not the site's to introduce to each other, and
     * a To line full of them is how that happens by accident.
     */
    public function testEachRecipientGetsTheirOwnMessage(): void
    {
        self::factory()->user->create([
            'role' => CJW_Roles::ROLE,
            'user_email' => 'een@example.test',
        ]);
        self::factory()->user->create([
            'role' => CJW_Roles::ROLE,
            'user_email' => 'twee@example.test',
        ]);

        $sent = new CJW_Summer_Camp_Digest()->send([
            [ 'title' => 'Test', 'lines' => [ 'Een regel.' ] ],
        ]);

        $this->assertGreaterThanOrEqual(2, $sent);

        $mail = tests_retrieve_phpmailer_instance()->get_sent();

        $this->assertCount(1, $mail->to);
    }

    public function testTheMailCarriesNoPersonalDataFromRegistrations(): void
    {
        $body = (string) new ReflectionMethod(CJW_Summer_Camp_Digest::class, 'body')
            ->invoke(null, [
                [ 'title' => 'Nieuwe inschrijvingen', 'lines' => [ 'Er zijn deze week 3 inschrijvingen binnengekomen.' ] ],
            ]);

        // A mailbox is forwarded, backed up and read on trains. The details
        // stay in the one place retention and an erasure request can reach.
        $this->assertStringContainsString('3 inschrijvingen', $body);
        $this->assertStringContainsString('admin.php?page=cjw-summer-camp', $body);
        $this->assertStringContainsString('uitzetten', $body);
    }
}
