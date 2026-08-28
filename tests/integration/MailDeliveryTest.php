<?php

declare(strict_types=1);

/**
 * How a themed mail actually leaves WordPress.
 *
 * wp_mail() sends plain text unless told otherwise, and has no parameter at all
 * for a plain-text alternative — that only exists on PHPMailer. Both facts are
 * invisible to a stub, and getting either wrong produces mail that looks fine
 * in the code and arrives as raw HTML markup in somebody's inbox.
 */
final class MailDeliveryTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        reset_phpmailer_instance();

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize([
            'theme_name' => 'Piraten',
            'theme_year' => '2027',
            'contact_email' => 'info@example.test',
        ]));
    }

    public function tear_down(): void
    {
        reset_phpmailer_instance();

        parent::tear_down();
    }

    private function send(): object
    {
        CJW_Mailer::send(CJW_Mail_Templates::BIRTHDAY, 'ouder@example.test', [
            '{child_name}' => 'Anna',
            '{child_age}' => '9',
        ]);

        return tests_retrieve_phpmailer_instance()->get_sent();
    }

    /**
     * Not text/html but multipart/alternative: because a plain-text twin is set,
     * PHPMailer builds a message carrying both parts and lets the client pick.
     * That is the outcome worth pinning — a bare text/html message is the one
     * that arrives as nothing in a client that refuses HTML.
     */
    public function testItGoesOutAsHtmlWithATextPartBesideIt(): void
    {
        $mail = $this->send();

        $this->assertStringContainsString('multipart/alternative', (string) $mail->header);
        $this->assertStringContainsString('<!DOCTYPE html>', (string) $mail->body);
        $this->assertStringContainsString('text/html', (string) $mail->body);
        $this->assertStringContainsString('text/plain', (string) $mail->body);
    }

    /**
     * A message with no text alternative reads worse to a spam filter, and to a
     * client that refuses HTML it reads as nothing at all.
     */
    public function testItCarriesAPlainTextAlternative(): void
    {
        $this->send();

        $alt = (string) tests_retrieve_phpmailer_instance()->AltBody;

        $this->assertNotSame('', $alt);
        $this->assertStringContainsString('Anna', $alt);
        $this->assertStringNotContainsString('<p>', $alt);
    }

    /**
     * The content-type filter is added for our own send and taken off again.
     * Left registered, it would quietly turn every other plugin's plain-text
     * mail on this site into HTML.
     */
    public function testTheHtmlContentTypeDoesNotLeakIntoOtherMail(): void
    {
        $this->send();

        $this->assertFalse(has_filter('wp_mail_content_type'));

        wp_mail('iemand@example.test', 'Gewoon bericht', 'Platte tekst.');

        $this->assertStringNotContainsString(
            'text/html',
            (string) tests_retrieve_phpmailer_instance()->get_sent()->header
        );
    }

    public function testTheCampNameIsInTheHeaderBand(): void
    {
        $this->assertStringContainsString('Piraten 2027', (string) $this->send()->body);
    }

    public function testNothingIsSentToAnAddressThatIsNotOne(): void
    {
        $this->assertFalse(CJW_Mailer::send(CJW_Mail_Templates::BIRTHDAY, 'geen-adres', []));
    }
}
