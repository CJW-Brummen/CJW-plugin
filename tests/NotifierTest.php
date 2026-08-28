<?php

declare(strict_types=1);

/**
 * The organiser's notification used to list every answered field as plaintext:
 * names, birth dates, addresses and any medical or allergy note, to whatever
 * inbox was configured, over whatever unauthenticated mail the host uses.
 *
 * That copies children's data out of the one place it is governed and into
 * mailboxes, phone notifications, forwarded threads and mail backups — none of
 * which the retention sweep or an erasure request can reach.
 *
 * The rule used to be kept by the notifier simply not reading the payload. Now
 * that the copy is editable, that is no longer enough: somebody can type
 * anything into the Mails screen. So the rule moved into the renderer, which
 * only substitutes the tokens a template was given, and these tests assert the
 * guarantee rather than the mechanism — they render the real template with
 * personal values offered, and check none of them come out.
 */
final class NotifierTest extends CJW_TestCase
{
    /**
     * The notification as it would be sent, with personal tokens offered.
     */
    private function body(string $value = ''): string
    {
        $rendered = CJW_Mailer::render(CJW_Mail_Templates::NOTIFICATION, [
            '{form_title}' => 'Inschrijfformulier',
            '{registration_number}' => '226',
            '{submissions_url}' => 'https://example.test/wp-admin/edit.php?post_type='
                . CJW_Wizard_Submission_Repository::POST_TYPE,
            // Offered deliberately. The notification's token list does not
            // include these, so the renderer must drop them.
            '{child_names}' => $value,
            '{child_name}' => $value,
            '{amount_due}' => $value,
        ]);

        return $rendered['html'] . ' ' . $rendered['text'] . ' ' . $rendered['subject'];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function personalDataProvider(): array
    {
        return [
            'child name' => [ 'Anna de Vries' ],
            'parent email' => [ 'ouder@example.test' ],
            'address' => [ 'Dorpsstraat 12' ],
            'birth date' => [ '2015-04-09' ],
            'medical note' => [ 'Allergisch voor noten' ],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('personalDataProvider')]
    public function testNoSubmittedValueEverReachesTheNotification(string $value): void
    {
        $this->assertStringNotContainsString(
            $value,
            $this->body($value),
            'Personal data must stay in the database, not travel by mail.'
        );
    }

    /**
     * The tokens are not merely unused — they are not on offer. A template that
     * listed them would be one edit away from sending them.
     */
    public function testTheNotificationIsNotEvenOfferedPersonalTokens(): void
    {
        $tokens = array_keys(CJW_Mail_Templates::tokensFor(CJW_Mail_Templates::NOTIFICATION));

        foreach ([ '{child_names}', '{child_name}', '{child_age}', '{amount_due}' ] as $forbidden) {
            $this->assertNotContains($forbidden, $tokens, $forbidden . ' must not be available here.');
        }
    }

    /**
     * A token the template may not use has to disappear rather than travel as
     * literal text: left in place, a later change to the allow-list would
     * quietly start resolving it.
     */
    public function testAnUnavailableTokenIsRemovedRatherThanPrinted(): void
    {
        $this->assertStringNotContainsString('{child_names}', $this->body('Anna de Vries'));
    }

    public function testTheNotificationStillSaysWhatHappenedAndWhereToLook(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('inschrijving', strtolower($body), 'The organiser must know what arrived.');
        $this->assertStringContainsString('edit.php?post_type=', $body, 'And where to read it.');
        $this->assertStringContainsString(
            CJW_Wizard_Submission_Repository::POST_TYPE,
            $body,
            'The link must point at the post type that actually registers.'
        );
    }
}
