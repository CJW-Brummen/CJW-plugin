<?php

declare(strict_types=1);

/**
 * Rendering, not sending. What reaches an inbox is a question about WordPress
 * and PHPMailer and belongs in the integration suite; what the message says is
 * decided here.
 */
final class MailerTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options']);

        parent::tearDown();
    }

    private function birthday(string $subject, string $body): array
    {
        CJW_Mail_Templates::save([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => $subject, 'body' => $body ],
        ]);

        return CJW_Mailer::render(CJW_Mail_Templates::BIRTHDAY, [
            '{child_name}' => 'Anna',
            '{child_age}' => '9',
        ]);
    }

    public function testTokensAreFilledIn(): void
    {
        $mail = $this->birthday('Hoera {child_name}', '<p>{child_name} wordt {child_age}.</p>');

        $this->assertSame('Hoera Anna', $mail['subject']);
        $this->assertStringContainsString('Anna wordt 9.', $mail['html']);
    }

    /**
     * The allow-list is the mechanism that keeps the organiser's notification
     * free of children's names, so a token off the list must vanish rather than
     * survive as literal text a later change might start resolving.
     */
    public function testATokenTheTemplateMayNotUseIsRemoved(): void
    {
        $mail = $this->birthday('Hoi', '<p>Hoi {amount_due} {onzin}.</p>');

        $this->assertStringNotContainsString('{amount_due}', $mail['html']);
        $this->assertStringNotContainsString('{onzin}', $mail['html']);
    }

    public function testAValueCannotSmuggleMarkupIntoTheMail(): void
    {
        CJW_Mail_Templates::save([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => 'Hoi', 'body' => '<p>{child_name}</p>' ],
        ]);

        $mail = CJW_Mailer::render(CJW_Mail_Templates::BIRTHDAY, [
            '{child_name}' => '<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $mail['html']);
    }

    public function testTheSubjectNeverCarriesMarkup(): void
    {
        $mail = $this->birthday('<b>Hoera</b> {child_name}', '<p>Hoi.</p>');

        $this->assertSame('Hoera Anna', $mail['subject']);
    }

    public function testAnUnknownTemplateRendersNothing(): void
    {
        $mail = CJW_Mailer::render('geen_bestaand_sjabloon');

        $this->assertSame('', $mail['subject']);
        $this->assertSame('', $mail['html']);
    }

    public function testTheMailIsWrappedInAWholeDocument(): void
    {
        $mail = $this->birthday('Hoi', '<p>Hoi.</p>');

        $this->assertStringStartsWith('<!DOCTYPE html>', $mail['html']);
        $this->assertStringContainsString('<body', $mail['html']);
        $this->assertStringContainsString('</html>', $mail['html']);
    }

    /**
     * Mail clients drop a stylesheet, so everything has to be inline. A design
     * relying on a class attribute arrives unstyled.
     */
    public function testTheStylingIsInlineRatherThanInAStylesheet(): void
    {
        $mail = $this->birthday('Hoi', '<p>Hoi.</p>');

        $this->assertStringNotContainsString('<style', $mail['html']);
        $this->assertStringNotContainsString('<link', $mail['html']);
        $this->assertStringContainsString('style="', $mail['html']);
    }

    /**
     * Images are blocked by default for a first-time sender, so a mail that
     * needs one arrives broken.
     */
    public function testNoImagesAreLoadedFromTheServer(): void
    {
        $mail = $this->birthday('Hoi', '<p>Hoi.</p>');

        $this->assertStringNotContainsString('<img', $mail['html']);
    }

    public function testTheCampColoursAreUsed(): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, [
            'theme_primary_color' => '#123456',
        ]);

        $mail = $this->birthday('Hoi', '<p>Hoi.</p>');

        $this->assertStringContainsString('#123456', $mail['html']);
    }

    public function testAnUnusableColourFallsBackRatherThanBreakingTheMail(): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, [
            'theme_primary_color' => 'niet-een-kleur',
        ]);

        $mail = $this->birthday('Hoi', '<p>Hoi.</p>');

        $this->assertStringContainsString('background:#', $mail['html']);
        $this->assertStringNotContainsString('niet-een-kleur', $mail['html']);
    }

    public function testThereIsAlwaysAPlainTextTwin(): void
    {
        $mail = $this->birthday('Hoi', '<p>Eerste regel.</p><p>Tweede regel.</p>');

        $this->assertStringNotContainsString('<p>', $mail['text']);
        $this->assertStringContainsString('Eerste regel.', $mail['text']);
        $this->assertStringContainsString('Tweede regel.', $mail['text']);
    }

    /**
     * "Klik hier" with no address is the one thing a text alternative must not
     * do.
     */
    public function testLinksSurviveIntoThePlainTextVersion(): void
    {
        $mail = $this->birthday('Hoi', '<p><a href="https://example.test/kamp">Meer weten</a></p>');

        $this->assertStringContainsString('Meer weten', $mail['text']);
        $this->assertStringContainsString('https://example.test/kamp', $mail['text']);
    }

    public function testTheTextVersionDoesNotRunEverythingTogether(): void
    {
        $mail = $this->birthday('Hoi', '<p>Een.</p><p>Twee.</p>');

        $this->assertStringNotContainsString('Een.Twee.', $mail['text']);
    }
}
