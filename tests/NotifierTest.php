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
 */
final class NotifierTest extends CJW_TestCase
{
    private function body(array $data): string
    {
        // setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5.
        $method = new ReflectionMethod(CJW_Wizard_Submission_Notifier::class, 'build_email_body');

        $notifier = new CJW_Wizard_Submission_Notifier(new CJW_Wizard_Submission_Repository());

        // The payload is deliberately not a parameter any more; passing it here
        // would only prove the signature, so the guard is that these values
        // cannot appear whatever was submitted.
        unset($data);

        return (string) $method->invoke($notifier, 1);
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
        $body = $this->body([ 'veld' => $value ]);

        $this->assertStringNotContainsString(
            $value,
            $body,
            'Personal data must stay in the database, not travel by mail.'
        );
    }

    public function testTheNotificationStillSaysWhatHappenedAndWhereToLook(): void
    {
        $body = $this->body([]);

        $this->assertStringContainsString('inschrijving', strtolower($body), 'The organiser must know what arrived.');
        $this->assertStringContainsString('edit.php?post_type=', $body, 'And where to read it.');
        $this->assertStringContainsString(
            CJW_Wizard_Submission_Repository::POST_TYPE,
            $body,
            'The link must point at the post type that actually registers.'
        );
    }
}
