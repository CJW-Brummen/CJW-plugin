<?php

declare(strict_types=1);

/**
 * Whether the site can send mail, and whether anyone finds out when it cannot.
 *
 * Every assertion here needs a real wp_mail(): the failure path exists only
 * because WordPress reports the reason on a hook rather than in a return value,
 * and a stand-in that returns true would make the whole feature untestable by
 * agreeing with it.
 */
final class MailTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST);
        reset_phpmailer_instance();

        $this->actAsAdministrator();
    }

    public function tear_down(): void
    {
        reset_phpmailer_instance();

        parent::tear_down();
    }

    public function testASuccessfulTestIsRecorded(): void
    {
        $sent = new CJW_Summer_Camp_Mail()->send('ouder@example.test');

        $last = CJW_Summer_Camp_Mail::lastTest();

        $this->assertTrue($sent);
        $this->assertNotNull($last);
        $this->assertTrue($last['ok']);
        $this->assertSame('ouder@example.test', $last['to']);
        $this->assertSame('', $last['error']);
        $this->assertGreaterThan(0, $last['time']);
    }

    public function testTheTestMailSaysWhatArrivingProves(): void
    {
        new CJW_Summer_Camp_Mail()->send('ouder@example.test');

        $body = (string) tests_retrieve_phpmailer_instance()->get_sent()->body;

        // Landing in the spam folder is a failure that looks like a success,
        // and it is the failure this site will actually have.
        $this->assertStringContainsString('ongewenste mail', $body);
    }

    /**
     * wp_mail() answers only true or false. The reason arrives on wp_mail_failed
     * and nowhere else, and it is the whole difference between a message an
     * organiser can act on and "het is mislukt".
     */
    public function testAFailureIsRecordedWithItsReason(): void
    {
        $sent = new CJW_Summer_Camp_Mail()->send('geen-adres');

        $last = CJW_Summer_Camp_Mail::lastTest();

        $this->assertFalse($sent);
        $this->assertNotNull($last);
        $this->assertFalse($last['ok']);
        $this->assertNotSame('', $last['error']);
    }

    public function testNothingIsRecordedBeforeTheFirstTest(): void
    {
        $this->assertNull(CJW_Summer_Camp_Mail::lastTest());
    }

    public function testAFreshSuccessIsNotStale(): void
    {
        new CJW_Summer_Camp_Mail()->send('ouder@example.test');

        $this->assertFalse(CJW_Summer_Camp_Mail::lastTestIsStale());
    }

    public function testASuccessFromLastSeasonIsStale(): void
    {
        update_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST, [
            'time' => time() - (200 * DAY_IN_SECONDS),
            'to' => 'ouder@example.test',
            'ok' => true,
            'error' => '',
            'by' => 'Marieke',
        ]);

        $this->assertTrue(CJW_Summer_Camp_Mail::lastTestIsStale());
    }

    public function testAFailureIsNeverCalledStale(): void
    {
        update_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST, [
            'time' => time() - (200 * DAY_IN_SECONDS),
            'to' => 'ouder@example.test',
            'ok' => false,
            'error' => 'SMTP connect() failed',
            'by' => 'Marieke',
        ]);

        // Stale describes evidence that has expired. A failure has not expired;
        // it is still the last thing known, and it still needs fixing.
        $this->assertFalse(CJW_Summer_Camp_Mail::lastTestIsStale());
    }

    public function testAPluginTakingOverDeliveryIsDetected(): void
    {
        $this->assertFalse(CJW_Summer_Camp_Mail::transportIsConfigured());

        add_action('phpmailer_init', '__return_false');

        $this->assertTrue(CJW_Summer_Camp_Mail::transportIsConfigured());

        remove_action('phpmailer_init', '__return_false');
    }

    public function testAnApiBasedSenderIsDetectedToo(): void
    {
        add_filter('pre_wp_mail', '__return_false');

        $this->assertTrue(CJW_Summer_Camp_Mail::transportIsConfigured());

        remove_filter('pre_wp_mail', '__return_false');
    }

    public function testSiteHealthAsksForATestWhenNoneWasEverSent(): void
    {
        $result = new CJW_Summer_Camp_Site_Health()->testMail();

        $this->assertSame('recommended', $result['status']);
        $this->assertStringContainsString('nog nooit', $result['label']);
    }

    public function testSiteHealthCallsAFailedTestCritical(): void
    {
        new CJW_Summer_Camp_Mail()->send('geen-adres');

        $result = new CJW_Summer_Camp_Site_Health()->testMail();

        $this->assertSame('critical', $result['status']);
    }

    /**
     * A successful test through the bare webserver is not the same as solved:
     * this is exactly the configuration Gmail and Outlook filter, and the site
     * has no way to notice that they did.
     */
    public function testSiteHealthStillWarnsWhenNoPluginHandlesDelivery(): void
    {
        new CJW_Summer_Camp_Mail()->send('ouder@example.test');

        $result = new CJW_Summer_Camp_Site_Health()->testMail();

        $this->assertSame('recommended', $result['status']);
    }

    public function testSiteHealthIsHappyWithARecentTestAndAMailPlugin(): void
    {
        new CJW_Summer_Camp_Mail()->send('ouder@example.test');

        add_action('phpmailer_init', '__return_false');
        $result = new CJW_Summer_Camp_Site_Health()->testMail();
        remove_action('phpmailer_init', '__return_false');

        $this->assertSame('good', $result['status']);
    }

    public function testTheTestDefaultsToWhoeverIsLoggedIn(): void
    {
        $user = get_userdata(get_current_user_id());

        $this->assertSame($user->user_email, CJW_Summer_Camp_Mail::suggestedRecipient());
    }
}
