<?php

declare(strict_types=1);

/**
 * Moving copy out of the two homes that are being closed.
 *
 * The live site happens to have nothing written in either, so this is the only
 * place the migration is ever exercised. A migration nobody has run is the same
 * kind of promise as a backup nobody has restored.
 */
final class MailMigrationTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(CJW_Mail_Migration::OPTION_DONE);
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);
    }

    private function formWithCopy(string $subject, string $body): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);

        update_post_meta($id, '_cjw_wizard_settings', [
            'send_parent_confirmation' => true,
            'parent_confirmation_subject' => $subject,
            'parent_confirmation_body' => $body,
        ]);

        return $id;
    }

    public function testAFormsConfirmationCopyIsKept(): void
    {
        $this->formWithCopy('Tot ziens op kamp!', "Hoi,\n\nWe zien je graag in juli.");

        $this->assertSame(1, CJW_Mail_Migration::run());
        $this->assertSame('Tot ziens op kamp!', CJW_Mail_Templates::subject(CJW_Mail_Templates::CONFIRMATION));
        $this->assertStringContainsString('We zien je graag in juli.', CJW_Mail_Templates::body(CJW_Mail_Templates::CONFIRMATION));
    }

    /**
     * The old field was a plain textarea, so its line breaks are the only
     * structure it has. Carried across as-is they would collapse into one
     * run-on paragraph in an HTML mail.
     */
    public function testLineBreaksBecomeParagraphs(): void
    {
        $this->formWithCopy('Hoi', "Eerste alinea.\n\nTweede alinea.");

        CJW_Mail_Migration::run();

        $body = CJW_Mail_Templates::body(CJW_Mail_Templates::CONFIRMATION);

        $this->assertStringContainsString('<p>Eerste alinea.</p>', $body);
        $this->assertStringContainsString('<p>Tweede alinea.</p>', $body);
    }

    public function testTheBirthdayTextIsKept(): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, [
            'birthday_mail_text' => '<p>Hoera, gefeliciteerd!</p>',
        ]);

        $this->assertSame(1, CJW_Mail_Migration::run());
        $this->assertStringContainsString('Hoera, gefeliciteerd!', CJW_Mail_Templates::body(CJW_Mail_Templates::BIRTHDAY));
    }

    public function testAFormThatWroteNothingIsNotMigrated(): void
    {
        $this->formWithCopy('', '');

        $this->assertSame(0, CJW_Mail_Migration::run());
        $this->assertTrue(CJW_Mail_Templates::isDefault(CJW_Mail_Templates::CONFIRMATION));
    }

    /**
     * The way these usually go wrong: a migration runs a second time and
     * replaces newer text with the older copy it was moving.
     */
    public function testItNeverOverwritesCopyWrittenSince(): void
    {
        $this->formWithCopy('Oude tekst', 'Oude tekst.');

        CJW_Mail_Templates::save([
            CJW_Mail_Templates::CONFIRMATION => [ 'subject' => 'Nieuwe tekst', 'body' => '<p>Nieuw.</p>' ],
        ]);
        delete_option(CJW_Mail_Migration::OPTION_DONE);

        CJW_Mail_Migration::run();

        $this->assertSame('Nieuwe tekst', CJW_Mail_Templates::subject(CJW_Mail_Templates::CONFIRMATION));
    }

    public function testItOnlyRunsOnce(): void
    {
        $this->formWithCopy('Tot ziens op kamp!', 'Hoi.');

        $this->assertSame(1, CJW_Mail_Migration::run());
        $this->assertSame(0, CJW_Mail_Migration::run());
    }

    /**
     * Activation is where it runs, and activation happens more than once.
     */
    public function testActivatingTwiceDoesNotDisturbIt(): void
    {
        $this->formWithCopy('Tot ziens op kamp!', 'Hoi.');

        CJW_Activator::activate();
        CJW_Mail_Templates::save([
            CJW_Mail_Templates::CONFIRMATION => [ 'subject' => 'Handmatig aangepast', 'body' => '<p>Nieuw.</p>' ],
        ]);
        CJW_Activator::activate();

        $this->assertSame('Handmatig aangepast', CJW_Mail_Templates::subject(CJW_Mail_Templates::CONFIRMATION));
    }
}
