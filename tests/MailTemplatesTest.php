<?php

declare(strict_types=1);

final class MailTemplatesTest extends CJW_TestCase
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

    public function testEveryTemplateShipsWithCopy(): void
    {
        foreach (CJW_Mail_Templates::all() as $key => $definition) {
            $this->assertNotSame('', trim(CJW_Mail_Templates::subject($key)), $key . ' needs a subject');
            $this->assertNotSame('', trim(CJW_Mail_Templates::body($key)), $key . ' needs a body');
            $this->assertNotSame('', $definition['label'], $key . ' needs a name a volunteer recognises');
            $this->assertNotSame('', $definition['description'], $key . ' needs to say when it goes out');
        }
    }

    public function testWrittenCopyReplacesTheDefault(): void
    {
        CJW_Mail_Templates::save([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => 'Gefeliciteerd!', 'body' => '<p>Hoera.</p>' ],
        ]);

        $this->assertSame('Gefeliciteerd!', CJW_Mail_Templates::subject(CJW_Mail_Templates::BIRTHDAY));
        $this->assertSame('<p>Hoera.</p>', CJW_Mail_Templates::body(CJW_Mail_Templates::BIRTHDAY));
        $this->assertFalse(CJW_Mail_Templates::isDefault(CJW_Mail_Templates::BIRTHDAY));
    }

    /**
     * Clearing a field is how somebody asks for the default back. Sending an
     * empty mail instead would be a strange reading of an empty box.
     */
    public function testClearingAFieldRestoresTheDefault(): void
    {
        $default = CJW_Mail_Templates::subject(CJW_Mail_Templates::BIRTHDAY);

        CJW_Mail_Templates::save([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => '   ', 'body' => '' ],
        ]);

        $this->assertSame($default, CJW_Mail_Templates::subject(CJW_Mail_Templates::BIRTHDAY));
        $this->assertTrue(CJW_Mail_Templates::isDefault(CJW_Mail_Templates::BIRTHDAY));
    }

    public function testUnknownTemplatesAreDiscarded(): void
    {
        $clean = CJW_Mail_Templates::sanitize([
            'iets_anders' => [ 'subject' => 'Hoi', 'body' => 'Hoi' ],
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => 'Hoi', 'body' => 'Hoi' ],
        ]);

        $this->assertArrayNotHasKey('iets_anders', $clean);
        $this->assertArrayHasKey(CJW_Mail_Templates::BIRTHDAY, $clean);
    }

    public function testASubjectCannotCarryMarkup(): void
    {
        $clean = CJW_Mail_Templates::sanitize([
            CJW_Mail_Templates::BIRTHDAY => [ 'subject' => 'Hoera <script>alert(1)</script>', 'body' => '' ],
        ]);

        $this->assertStringNotContainsString('<script', $clean[ CJW_Mail_Templates::BIRTHDAY ]['subject']);
    }

    public function testEveryTemplateOffersTheSharedCampTokens(): void
    {
        foreach (array_keys(CJW_Mail_Templates::all()) as $key) {
            $tokens = CJW_Mail_Templates::tokensFor($key);

            $this->assertArrayHasKey('{camp_date_range}', $tokens, $key);
            $this->assertArrayHasKey('{camp_contact_email}', $tokens, $key);
        }
    }

    public function testEveryTokenIsExplainedInDutch(): void
    {
        foreach (array_keys(CJW_Mail_Templates::all()) as $key) {
            foreach (CJW_Mail_Templates::tokensFor($key) as $token => $what) {
                $this->assertMatchesRegularExpression('/^\{[a-z0-9_]+\}$/', $token);
                $this->assertNotSame('', trim($what), $token . ' has no explanation');
            }
        }
    }

    /**
     * A default that used a token the template is not given would be blanked by
     * the renderer, so the shipped copy would arrive with a hole in it.
     */
    public function testShippedCopyOnlyUsesTokensItsOwnTemplateAllows(): void
    {
        foreach (array_keys(CJW_Mail_Templates::all()) as $key) {
            $allowed = array_keys(CJW_Mail_Templates::tokensFor($key));
            $copy = CJW_Mail_Templates::subject($key) . ' ' . CJW_Mail_Templates::body($key);

            preg_match_all('/\{[a-z0-9_]+\}/i', $copy, $found);

            foreach (array_unique($found[0]) as $token) {
                $this->assertContains($token, $allowed, $token . ' is used by ' . $key . ' but not offered to it');
            }
        }
    }

    /**
     * The rule the organiser's notification exists to keep.
     */
    public function testTheNotificationIsOfferedNothingPersonal(): void
    {
        $tokens = array_keys(CJW_Mail_Templates::tokensFor(CJW_Mail_Templates::NOTIFICATION));

        foreach ($tokens as $token) {
            $this->assertStringNotContainsString('child', $token);
            $this->assertStringNotContainsString('amount', $token);
        }
    }
}
