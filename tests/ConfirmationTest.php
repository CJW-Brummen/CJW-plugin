<?php

declare(strict_types=1);

/**
 * The confirmation to the parent is the one mail that legitimately carries
 * someone's own registration to their own address — the opposite case to the
 * organiser's notification, which carries nothing.
 *
 * It ships switched off, and that is the behaviour most worth pinning: mail
 * deliverability is not solved yet, and a confirmation that silently fails to
 * arrive is worse than none, because it replaces "I heard nothing, I'll check"
 * with "it said it would confirm, so it worked".
 */
final class ConfirmationTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_mail'] = [];
        $GLOBALS['cjw_test_post_meta'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['cjw_test_mail'] = [];
        $GLOBALS['cjw_test_post_meta'] = [];

        parent::tearDown();
    }

    /**
     * @param array $settings Form settings to apply over the defaults.
     */
    private function config(array $settings = []): array
    {
        return [
            'settings' => array_merge(CJW_Wizard_Defaults::settings(), $settings),
            'steps' => [
                'stap' => [
                    'key' => 'stap',
                    'label' => 'Stap',
                    'type' => 'default',
                    'condition' => [],
                    'fields' => [
                        [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                        [ 'key' => 'mail', 'label' => 'E-mail ouder', 'type' => 'email' ],
                    ],
                ],
            ],
            'rules' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $email = 'ouder@example.test'): array
    {
        return [ 'naam' => 'Anna de Vries', 'mail' => $email ];
    }

    public function testNothingIsSentUnlessTheFormAsksForIt(): void
    {
        $result = (new CJW_Wizard_Submission_Confirmation())->maybe_send(1, 2, $this->config(), $this->payload());

        $this->assertSame('', $result, 'Confirmations must be off until deliberately switched on.');
        $this->assertSame([], $GLOBALS['cjw_test_mail']);
    }

    public function testTheDefaultShippedSettingIsOff(): void
    {
        $this->assertFalse(
            CJW_Wizard_Defaults::settings()['send_parent_confirmation'],
            'A new form must not start mailing parents before anyone has checked that mail leaves the server.'
        );
    }

    public function testItSendsToTheAddressTheParentGave(): void
    {
        $result = (new CJW_Wizard_Submission_Confirmation())->maybe_send(
            1,
            2,
            $this->config([ 'send_parent_confirmation' => true ]),
            $this->payload()
        );

        $this->assertSame(CJW_Wizard_Submission_Confirmation::SENT, $result);
        $this->assertCount(1, $GLOBALS['cjw_test_mail']);
        $this->assertSame('ouder@example.test', $GLOBALS['cjw_test_mail'][0]['to']);
    }

    /**
     * A form that promises a confirmation but never asks for an address is a
     * misconfiguration, and it should be visible rather than silent.
     */
    public function testAMissingAddressIsRecordedRatherThanIgnored(): void
    {
        $result = (new CJW_Wizard_Submission_Confirmation())->maybe_send(
            7,
            2,
            $this->config([ 'send_parent_confirmation' => true ]),
            [ 'naam' => 'Anna de Vries' ]
        );

        $this->assertSame(CJW_Wizard_Submission_Confirmation::NO_ADDRESS, $result);
        $this->assertSame([], $GLOBALS['cjw_test_mail']);
        $this->assertSame(
            CJW_Wizard_Submission_Confirmation::NO_ADDRESS,
            $GLOBALS['cjw_test_post_meta'][7][ CJW_Wizard_Submission_Confirmation::META_RESULT ] ?? null,
            'The outcome must be on the submission so an organiser can see it.'
        );
    }

    public function testAnUnusableAddressIsNotMailed(): void
    {
        $result = (new CJW_Wizard_Submission_Confirmation())->maybe_send(
            1,
            2,
            $this->config([ 'send_parent_confirmation' => true ]),
            $this->payload('niet ingevuld')
        );

        $this->assertSame(CJW_Wizard_Submission_Confirmation::NO_ADDRESS, $result);
        $this->assertSame([], $GLOBALS['cjw_test_mail']);
    }

    public function testTheOutcomeIsRecordedOnASuccessfulSend(): void
    {
        (new CJW_Wizard_Submission_Confirmation())->maybe_send(
            9,
            2,
            $this->config([ 'send_parent_confirmation' => true ]),
            $this->payload()
        );

        $this->assertSame(
            CJW_Wizard_Submission_Confirmation::SENT,
            $GLOBALS['cjw_test_post_meta'][9][ CJW_Wizard_Submission_Confirmation::META_RESULT ] ?? null
        );
    }

    public function testTheCampsOwnCopyIsUsedWhenSet(): void
    {
        (new CJW_Wizard_Submission_Confirmation())->maybe_send(
            1,
            2,
            $this->config([
                'send_parent_confirmation' => true,
                'parent_confirmation_subject' => 'Tot ziens op kamp!',
                'parent_confirmation_body' => 'We zien Anna graag in juli.',
            ]),
            $this->payload()
        );

        $this->assertSame('Tot ziens op kamp!', $GLOBALS['cjw_test_mail'][0]['subject']);
        $this->assertStringContainsString('We zien Anna graag in juli.', $GLOBALS['cjw_test_mail'][0]['body']);
    }

    public function testThereIsAlwaysASubjectAndABodyEvenWithNoCopyWritten(): void
    {
        (new CJW_Wizard_Submission_Confirmation())->maybe_send(
            1,
            2,
            $this->config([ 'send_parent_confirmation' => true ]),
            $this->payload()
        );

        $this->assertNotSame('', trim($GLOBALS['cjw_test_mail'][0]['subject']));
        $this->assertNotSame('', trim($GLOBALS['cjw_test_mail'][0]['body']));
    }

    /**
     * The mail carries the camp's own copy, not the parent's answers read back.
     * Quoting a payload indiscriminately is how medical notes end up in a mail
     * thread that retention and erasure can never reach.
     */
    public function testAnswersAreNotQuotedBackIntoTheMail(): void
    {
        (new CJW_Wizard_Submission_Confirmation())->maybe_send(
            1,
            2,
            $this->config([ 'send_parent_confirmation' => true ]),
            [ 'naam' => 'Anna de Vries', 'mail' => 'ouder@example.test', 'allergie' => 'Allergisch voor noten' ]
        );

        $this->assertStringNotContainsString('Allergisch voor noten', $GLOBALS['cjw_test_mail'][0]['body']);
    }
}
