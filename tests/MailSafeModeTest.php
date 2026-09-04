<?php

declare(strict_types=1);

/**
 * A copy of the site must not mail real parents.
 *
 * The decision -- is this a copy? -- and the rewrite of an outgoing mail are
 * both plain functions of options and arguments, so the shim can exercise every
 * branch. Whether WordPress actually routes the result through wp_mail() is
 * the integration suite's question.
 */
final class MailSafeModeTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [ 'admin_email' => 'beheer@example.test' ];
        $GLOBALS['cjw_test_environment_type'] = 'production';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options'], $GLOBALS['cjw_test_environment_type']);

        parent::tearDown();
    }

    public function testAProductionSiteWithNothingConfirmedSendsNormally(): void
    {
        $this->assertFalse(CJW_Mail_Safe_Mode::isActive());
        $this->assertSame('', CJW_Mail_Safe_Mode::reason());
    }

    public function testAnEnvironmentThatIsNotProductionIsACopy(): void
    {
        $GLOBALS['cjw_test_environment_type'] = 'staging';

        $this->assertTrue(CJW_Mail_Safe_Mode::isActive());
        $this->assertStringContainsString('staging', CJW_Mail_Safe_Mode::reason());
    }

    /**
     * The signal that catches a restored backup: the database says the live
     * site is one host, and this request is being served from another.
     */
    public function testADifferentHostThanTheConfirmedOneIsACopy(): void
    {
        $GLOBALS['cjw_test_options'][ CJW_Mail_Safe_Mode::OPTION_PRODUCTION_HOST ] = 'www.cjw-brummen.nl';

        $this->assertNotSame('www.cjw-brummen.nl', CJW_Mail_Safe_Mode::currentHost(), 'the shim serves from another host');
        $this->assertTrue(CJW_Mail_Safe_Mode::isActive());
        $this->assertStringContainsString('www.cjw-brummen.nl', CJW_Mail_Safe_Mode::reason());
    }

    public function testTheConfirmedHostItselfIsNotACopy(): void
    {
        CJW_Mail_Safe_Mode::confirmCurrentHost();

        $this->assertTrue(CJW_Mail_Safe_Mode::isConfirmed());
        $this->assertFalse(CJW_Mail_Safe_Mode::isActive());
    }

    public function testMailIsLeftAloneWhileSafeModeIsOff(): void
    {
        $args = [ 'to' => 'ouder@example.test', 'subject' => 'Hoera', 'message' => 'Body', 'headers' => [], 'attachments' => [] ];

        $this->assertSame($args, CJW_Mail_Safe_Mode::divert($args));
    }

    public function testAnOutgoingMailIsRedirectedToTheAdministrator(): void
    {
        $GLOBALS['cjw_test_environment_type'] = 'staging';

        $diverted = CJW_Mail_Safe_Mode::divert([
            'to' => [ 'ouder@example.test', 'oma@example.test' ],
            'subject' => 'Hiep hiep hoera, Fleur!',
            'message' => '<p>Gefeliciteerd</p>',
            'headers' => [ 'Cc: tante@example.test', 'Reply-To: info@example.test', 'Bcc: opa@example.test' ],
            'attachments' => [],
        ]);

        $this->assertSame('beheer@example.test', $diverted['to']);
        $this->assertSame('[TEST] Hiep hiep hoera, Fleur!', $diverted['subject']);
        $this->assertStringStartsWith('[Mail-veiligmodus]', $diverted['message']);
        $this->assertStringContainsString('ouder@example.test, oma@example.test', $diverted['message'], 'the intended recipients are named at the top');
        $this->assertStringContainsString('<p>Gefeliciteerd</p>', $diverted['message']);
        $this->assertSame([ 'Reply-To: info@example.test' ], $diverted['headers'], 'copies would reach real people past the redirect');
    }

    public function testCopyHeadersGivenAsOneStringAreStrippedToo(): void
    {
        $GLOBALS['cjw_test_environment_type'] = 'staging';

        $diverted = CJW_Mail_Safe_Mode::divert([
            'to' => 'ouder@example.test',
            'subject' => 'X',
            'message' => 'Y',
            'headers' => "From: a@example.test\r\nBCC: opa@example.test\r\nCc: tante@example.test",
        ]);

        $this->assertSame('From: a@example.test', $diverted['headers']);
    }

    public function testTheSubjectIsNotPrefixedTwice(): void
    {
        $GLOBALS['cjw_test_environment_type'] = 'staging';

        $diverted = CJW_Mail_Safe_Mode::divert([ 'to' => 'a@example.test', 'subject' => '[TEST] Al gemarkeerd', 'message' => '' ]);

        $this->assertSame('[TEST] Al gemarkeerd', $diverted['subject']);
    }

    /**
     * No administrator address means nowhere safe to send to. An empty
     * recipient makes wp_mail() fail, which is silence -- the right outcome.
     */
    public function testWithoutAValidAdministratorAddressNothingGoesAnywhere(): void
    {
        $GLOBALS['cjw_test_environment_type'] = 'staging';
        $GLOBALS['cjw_test_options']['admin_email'] = 'kapot';

        $diverted = CJW_Mail_Safe_Mode::divert([ 'to' => 'ouder@example.test', 'subject' => 'X', 'message' => 'Y' ]);

        $this->assertSame('', $diverted['to']);
    }

    public function testRedirectedMailIsCountedAndRemembered(): void
    {
        $GLOBALS['cjw_test_environment_type'] = 'staging';

        CJW_Mail_Safe_Mode::divert([ 'to' => 'een@example.test', 'subject' => 'Eerste', 'message' => '' ]);
        CJW_Mail_Safe_Mode::divert([ 'to' => 'twee@example.test', 'subject' => 'Tweede', 'message' => '' ]);

        $diverted = CJW_Mail_Safe_Mode::diverted();

        $this->assertSame(2, $diverted['count']);
        $this->assertSame('Tweede', $diverted['last'][0]['subject'], 'newest first');
        $this->assertSame('een@example.test', $diverted['last'][1]['to']);
    }
}
