<?php

declare(strict_types=1);

/**
 * Safe mode as WordPress actually applies it: through the wp_mail filter, to a
 * real PHPMailer, on a site whose live host is recorded in the database.
 */
final class MailSafeModeHostTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(CJW_Mail_Safe_Mode::OPTION_PRODUCTION_HOST);
        delete_option(CJW_Mail_Safe_Mode::OPTION_DIVERTED);
        update_option('admin_email', 'beheer@example.org');

        // After the option change: WordPress mails the old administrator about
        // it, and the mock returns the first mail sent, not the last.
        reset_phpmailer_instance();
    }

    public function tear_down(): void
    {
        reset_phpmailer_instance();
        delete_option(CJW_Mail_Safe_Mode::OPTION_PRODUCTION_HOST);
        delete_option(CJW_Mail_Safe_Mode::OPTION_DIVERTED);

        parent::tear_down();
    }

    private function requireProductionEnvironment(): void
    {
        if (CJW_Mail_Safe_Mode::environment() !== 'production') {
            $this->markTestSkipped('This test suite runs with WP_ENVIRONMENT_TYPE set; the environment branch is covered by the fast suite.');
        }
    }

    public function testARestoredCopyMailsTheAdministratorInsteadOfTheParent(): void
    {
        update_option(CJW_Mail_Safe_Mode::OPTION_PRODUCTION_HOST, 'www.cjw-brummen.nl');

        $this->assertTrue(CJW_Mail_Safe_Mode::isActive());

        wp_mail('ouder@example.test', 'Bevestiging van je inschrijving', 'Beste ouder');

        $mailer = tests_retrieve_phpmailer_instance();
        $sent = $mailer->get_sent();

        $this->assertSame('beheer@example.org', $sent->to[0][0]);
        $this->assertSame('[TEST] Bevestiging van je inschrijving', $sent->subject);
        $this->assertStringContainsString('ouder@example.test', $sent->body);
        $this->assertStringContainsString('Beste ouder', $sent->body);
        $this->assertSame(1, CJW_Mail_Safe_Mode::diverted()['count']);
    }

    public function testTheLiveSiteItselfMailsTheParent(): void
    {
        $this->requireProductionEnvironment();

        CJW_Mail_Safe_Mode::confirmCurrentHost();

        $this->assertFalse(CJW_Mail_Safe_Mode::isActive());

        wp_mail('ouder@example.test', 'Bevestiging', 'Beste ouder');

        $sent = tests_retrieve_phpmailer_instance()->get_sent();

        $this->assertSame('ouder@example.test', $sent->to[0][0]);
        $this->assertSame('Bevestiging', $sent->subject);
    }

    public function testSiteHealthNagsUntilTheLiveHostIsConfirmed(): void
    {
        $this->requireProductionEnvironment();

        $health = new CJW_Summer_Camp_Site_Health();

        $this->assertSame('recommended', $health->testMailSafeMode()['status']);

        CJW_Mail_Safe_Mode::confirmCurrentHost();

        $this->assertSame('good', $health->testMailSafeMode()['status']);
    }

    public function testForgettingTheHostTurnsTheCheckBackOn(): void
    {
        $this->requireProductionEnvironment();

        CJW_Mail_Safe_Mode::confirmCurrentHost();
        CJW_Mail_Safe_Mode::forget();

        $this->assertFalse(CJW_Mail_Safe_Mode::isConfirmed());
        $this->assertFalse(CJW_Mail_Safe_Mode::isActive());
    }
}
