<?php

declare(strict_types=1);

/**
 * The birthday mail, end to end.
 *
 * The two settings behind this sat on the Inhoud tab for a long time labelled
 * "nog niet actief". What matters most here is not that it sends, but that it
 * stops: on a family whose registration has been anonymised, on a second run
 * the same day, and when nobody switched it on.
 */
final class BirthdayMailTest extends CJW_IntegrationTestCase
{
    private CJW_Wizard_Submission_Repository $repository;

    public function set_up(): void
    {
        parent::set_up();

        reset_phpmailer_instance();

        $this->repository = new CJW_Wizard_Submission_Repository();

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize([
            'theme_year' => wp_date('Y'),
            'start_date' => wp_date('d-m-Y'),
            'end_date' => wp_date('d-m-Y'),
            'contact_email' => 'info@example.test',
            'birthday_mail_enabled' => true,
        ]));
    }

    public function tear_down(): void
    {
        reset_phpmailer_instance();

        parent::tear_down();
    }

    /**
     * A registration for one child, with a birth date this test controls.
     */
    private function registration(string $birthDate, string $name = 'Anna'): int
    {
        $config = [
            'settings' => [],
            'steps' => [
                'stap' => [
                    'key' => 'stap',
                    'label' => 'Stap',
                    'type' => 'default',
                    'condition' => [],
                    'fields' => [
                        [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                        [ 'key' => 'geboren', 'label' => 'Geboortedatum', 'type' => 'date', 'role' => CJW_Wizard_Field_Roles::BIRTH_DATE ],
                    ],
                ],
            ],
            'rules' => [],
        ];

        $id = self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::CONFIRMED,
        ]);

        update_post_meta($id, CJW_Wizard_Submission_Repository::META_CAMP_YEAR, wp_date('Y'));
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_EMAIL, 'ouder@example.test');
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_SNAPSHOT, wp_json_encode($config));
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_PAYLOAD, wp_json_encode([
            'naam' => $name,
            'geboren' => $birthDate,
        ]));

        return $id;
    }

    /**
     * A birth date whose day and month are today's.
     */
    private function bornToday(int $yearsAgo = 9): string
    {
        return new DateTimeImmutable('now', wp_timezone())
            ->modify('-' . $yearsAgo . ' years')
            ->format('d-m-Y');
    }

    private function mailer(): CJW_Wizard_Birthday_Mailer
    {
        return new CJW_Wizard_Birthday_Mailer($this->repository);
    }

    public function testAChildWithABirthdayTodayIsGreeted(): void
    {
        $this->registration($this->bornToday());

        $this->assertSame(1, $this->mailer()->send_todays_greetings());

        $mail = tests_retrieve_phpmailer_instance()->get_sent();

        $this->assertSame('ouder@example.test', $mail->to[0][0]);
        $this->assertStringContainsString('Anna', (string) $mail->subject);
    }

    /**
     * The form collects a parent's address, and that is the only address there
     * is. This is not a mail to a child.
     */
    public function testItGoesToTheAddressThatRegistered(): void
    {
        $this->registration($this->bornToday());
        $this->mailer()->send_todays_greetings();

        $this->assertSame('ouder@example.test', tests_retrieve_phpmailer_instance()->get_sent()->to[0][0]);
    }

    public function testTheAgeIsTheOneTheChildTurnsToday(): void
    {
        $this->registration($this->bornToday(9));
        $this->mailer()->send_todays_greetings();

        $this->assertStringContainsString('9', (string) tests_retrieve_phpmailer_instance()->get_sent()->body);
    }

    public function testNobodyIsGreetedOnAnyOtherDay(): void
    {
        $this->registration(new DateTimeImmutable('now', wp_timezone())->modify('-9 years +3 days')->format('d-m-Y'));

        $this->assertSame(0, $this->mailer()->send_todays_greetings());
    }

    public function testNothingIsSentWhileTheSettingIsOff(): void
    {
        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY);
        $settings['birthday_mail_enabled'] = false;
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, $settings);

        $this->registration($this->bornToday());

        $this->assertSame(0, $this->mailer()->send_todays_greetings());
    }

    /**
     * Cron can fire more than once a day, and a duplicate birthday mail is a
     * small embarrassment that arrives at scale.
     */
    public function testASecondRunTheSameDaySendsNothing(): void
    {
        $this->registration($this->bornToday());

        $this->assertSame(1, $this->mailer()->send_todays_greetings());
        $this->assertSame(0, $this->mailer()->send_todays_greetings());
    }

    /**
     * The whole privacy position: no data is kept for this, and it stops the
     * moment retention takes the data away.
     */
    public function testAnAnonymisedRegistrationIsNeverGreeted(): void
    {
        $id = $this->registration($this->bornToday());
        $this->repository->anonymise($id);

        $this->assertSame(0, $this->mailer()->send_todays_greetings());
    }

    public function testLastYearsFamiliesAreNotGreeted(): void
    {
        $id = $this->registration($this->bornToday());
        update_post_meta($id, CJW_Wizard_Submission_Repository::META_CAMP_YEAR, (string) ((int) wp_date('Y') - 1));

        $this->assertSame(0, $this->mailer()->send_todays_greetings());
    }

    public function testTwoChildrenSharingABirthdayBothGetOne(): void
    {
        $this->registration($this->bornToday(9), 'Anna');
        $this->registration($this->bornToday(11), 'Sem');

        $this->assertSame(2, $this->mailer()->send_todays_greetings());
    }

    public function testTheMailIsScheduledSoItRunsWithoutAnyone(): void
    {
        CJW_Wizard_Birthday_Mailer::unschedule();
        CJW_Wizard_Birthday_Mailer::schedule();

        $this->assertIsInt(wp_next_scheduled(CJW_Wizard_Birthday_Mailer::HOOK));

        CJW_Wizard_Birthday_Mailer::unschedule();
        $this->assertFalse(wp_next_scheduled(CJW_Wizard_Birthday_Mailer::HOOK));
    }
}
