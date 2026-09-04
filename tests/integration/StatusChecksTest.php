<?php

declare(strict_types=1);

/**
 * What the camp adds to the status endpoint.
 *
 * Both checks ask the same thing from a parent's side of the screen: can
 * somebody register right now, and will they hear anything back? Neither can
 * be answered without WordPress -- one counts published forms, the other reads
 * the record of the last test mail.
 */
final class StatusChecksTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        CJW_Status_Endpoint::flush();
        CJW_Registration_Page::flush();
        $this->settings([]);
        delete_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST);
    }

    public function tear_down(): void
    {
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);
        delete_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST);
        CJW_Summer_Camp_Service::flush();
        CJW_Registration_Page::flush();
        CJW_Status_Endpoint::flush();

        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function settings(array $overrides): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, array_merge(
            CJW_Summer_Camp_Service::defaults(),
            $overrides
        ));
        CJW_Summer_Camp_Service::flush();
    }

    private function openRegistration(): void
    {
        $this->settings([
            'registration_active' => true,
            'registration_deadline' => '',
        ]);
    }

    private function publishForm(): int
    {
        return self::factory()->post->create([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => 'publish',
            'post_title' => 'Inschrijfformulier',
        ]);
    }

    private function publishFormPage(): int
    {
        $id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Inschrijven',
            'post_content' => '[cjw_wizard id="1"]',
        ]);

        CJW_Registration_Page::flush();

        return $id;
    }

    public function testAClosedRegistrationHasNothingToBeWrongAbout(): void
    {
        $check = CJW_Summer_Camp_Status_Checks::registrationCheck();

        $this->assertSame('ok', $check['level']);
        $this->assertFalse($check['open']);
    }

    /**
     * The failure nobody notices: the site looks finished, the deadline
     * passes, and the registrations never arrived.
     */
    public function testAnOpenRegistrationWithNowhereToRegisterIsCritical(): void
    {
        $this->openRegistration();

        $check = CJW_Summer_Camp_Status_Checks::registrationCheck();

        $this->assertSame('critical', $check['level']);
        $this->assertFalse($check['page']);
        $this->assertFalse($check['form']);
        $this->assertStringContainsString('niemand kan zich inschrijven', $check['says']);
    }

    public function testAnOpenRegistrationWithoutAPublishedFormIsCritical(): void
    {
        $this->openRegistration();
        $this->publishFormPage();

        $check = CJW_Summer_Camp_Status_Checks::registrationCheck();

        $this->assertSame('critical', $check['level']);
        $this->assertTrue($check['page']);
        $this->assertFalse($check['form']);
        $this->assertStringContainsString('geen gepubliceerd formulier', $check['says']);
    }

    public function testAnOpenRegistrationThatWorksIsFine(): void
    {
        $this->openRegistration();
        $this->publishFormPage();
        $this->publishForm();

        $check = CJW_Summer_Camp_Status_Checks::registrationCheck();

        $this->assertSame('ok', $check['level']);
        $this->assertSame('', $check['says']);
    }

    /**
     * A draft form is somebody's work in progress, not something a parent can
     * fill in.
     */
    public function testADraftFormDoesNotCountAsSomewhereToRegister(): void
    {
        $this->openRegistration();
        $this->publishFormPage();
        self::factory()->post->create([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => 'draft',
        ]);

        $this->assertSame('critical', CJW_Summer_Camp_Status_Checks::registrationCheck()['level']);
    }

    public function testMailThatWasNeverTestedIsAWarning(): void
    {
        $check = CJW_Summer_Camp_Status_Checks::mailCheck();

        $this->assertSame('warning', $check['level']);
        $this->assertFalse($check['tested']);
        $this->assertStringContainsString('nog nooit getest', $check['says']);
    }

    public function testAFailedTestMailIsAWarning(): void
    {
        update_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST, [
            'time' => time(),
            'to' => 'beheer@example.test',
            'ok' => false,
            'error' => 'SMTP connect() failed',
            'by' => 'Marieke',
        ]);

        $check = CJW_Summer_Camp_Status_Checks::mailCheck();

        $this->assertSame('warning', $check['level']);
        $this->assertStringContainsString('niet aangekomen', $check['says']);
    }

    public function testATestFromLongAgoProvesLittleAndSaysSo(): void
    {
        update_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST, [
            'time' => time() - (400 * DAY_IN_SECONDS),
            'to' => 'beheer@example.test',
            'ok' => true,
            'error' => '',
            'by' => 'Marieke',
        ]);

        $check = CJW_Summer_Camp_Status_Checks::mailCheck();

        $this->assertSame('warning', $check['level']);
        $this->assertTrue($check['stale']);
    }

    public function testARecentSuccessfulTestIsFine(): void
    {
        update_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST, [
            'time' => time(),
            'to' => 'beheer@example.test',
            'ok' => true,
            'error' => '',
            'by' => 'Marieke',
        ]);

        $this->assertSame('ok', CJW_Summer_Camp_Status_Checks::mailCheck()['level']);
    }

    /**
     * The endpoint must not have to know what a camp is: these arrive on the
     * filter, and turning the filter off leaves the plumbing checks intact.
     */
    public function testTheChecksReachTheEndpointThroughTheFilter(): void
    {
        $checks = CJW_Status_Endpoint::checks(true);

        $this->assertArrayHasKey('registration', $checks);
        $this->assertArrayHasKey('mail', $checks);
        $this->assertArrayHasKey('cron', $checks, 'the endpoint keeps its own');

        remove_filter(CJW_Status_Endpoint::FILTER_CHECKS, [CJW_Summer_Camp_Status_Checks::class, 'add']);

        $without = CJW_Status_Endpoint::checks(true);

        $this->assertArrayNotHasKey('registration', $without);
        $this->assertArrayHasKey('cron', $without);

        add_filter(CJW_Status_Endpoint::FILTER_CHECKS, [CJW_Summer_Camp_Status_Checks::class, 'add']);
    }

    public function testAnOpenRegistrationThatNobodyCanUseMakesTheWholeSiteCritical(): void
    {
        $this->openRegistration();

        $this->assertSame('critical', CJW_Status_Endpoint::payload(false, true)['status']);
        $this->assertNotSame([], CJW_Status_Endpoint::problems());
    }
}
