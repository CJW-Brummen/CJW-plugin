<?php

declare(strict_types=1);

/**
 * WordPress answers an AVG access or erasure request by e-mail address, but a
 * submission payload is keyed by whatever the form happens to ask. Finding the
 * right address is what makes both requests answerable at all -- picking the
 * wrong one would hand a stranger someone else's data.
 */
final class SubmissionEmailTest extends CJW_TestCase
{
    /**
     * @param array $fields Field configuration for one step.
     */
    private function config(array $fields): array
    {
        return [
            'steps' => [
                'contact' => [
                    'key' => 'contact',
                    'label' => 'Contact',
                    'type' => 'default',
                    'fields' => $fields,
                    'condition' => [],
                ],
            ],
            'rules' => [],
        ];
    }

    public function testFindsTheFieldTheFormDeclaresAsEmail(): void
    {
        $config = $this->config([
            [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text' ],
            [ 'key' => 'ouder_email', 'label' => 'E-mail ouder', 'type' => 'email' ],
        ]);

        $this->assertSame(
            'ouder@example.test',
            CJW_Wizard_Submission_Repository::findEmail($config, [
                'naam' => 'Anna',
                'ouder_email' => 'ouder@example.test',
            ])
        );
    }

    public function testTheFirstDeclaredEmailFieldWinsOverALaterOne(): void
    {
        $config = $this->config([
            [ 'key' => 'ouder_email', 'label' => 'E-mail ouder', 'type' => 'email' ],
            [ 'key' => 'noodcontact_email', 'label' => 'E-mail noodcontact', 'type' => 'email' ],
        ]);

        $this->assertSame(
            'ouder@example.test',
            CJW_Wizard_Submission_Repository::findEmail($config, [
                'noodcontact_email' => 'oma@example.test',
                'ouder_email' => 'ouder@example.test',
            ]),
            'The registering parent is asked first, so their address is the one to match on.'
        );
    }

    public function testFindsAnEmailFieldNestedUnderARadioOption(): void
    {
        $config = $this->config([
            [
                'key' => 'contactwijze',
                'label' => 'Contact via',
                'type' => 'radio',
                'options' => [
                    [
                        'label' => 'E-mail',
                        'value' => 'mail',
                        'fields' => [
                            [ 'key' => 'mailadres', 'label' => 'E-mailadres', 'type' => 'email' ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            'ouder@example.test',
            CJW_Wizard_Submission_Repository::findEmail($config, [
                'contactwijze' => 'mail',
                'mailadres' => 'ouder@example.test',
            ])
        );
    }

    public function testFallsBackToAnyAddressWhenNoFieldIsTypedAsEmail(): void
    {
        $config = $this->config([
            [ 'key' => 'contact', 'label' => 'Contact', 'type' => 'text' ],
        ]);

        $this->assertSame(
            'ouder@example.test',
            CJW_Wizard_Submission_Repository::findEmail($config, [ 'contact' => 'ouder@example.test' ]),
            'Forms built before the email field type existed must still be reachable.'
        );
    }

    public function testFindsAnAddressInsideARepeaterRow(): void
    {
        $config = $this->config([
            [
                'key' => 'kinderen',
                'label' => 'Kinderen',
                'type' => 'repeater',
                'fields' => [
                    [ 'key' => 'contact_email', 'label' => 'E-mail', 'type' => 'email' ],
                ],
            ],
        ]);

        $this->assertSame(
            'ouder@example.test',
            CJW_Wizard_Submission_Repository::findEmail($config, [
                'kinderen' => [ [ 'contact_email' => 'ouder@example.test' ] ],
            ])
        );
    }

    public function testReturnsNothingWhenThereIsNoAddressAtAll(): void
    {
        $config = $this->config([
            [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text' ],
        ]);

        $this->assertSame('', CJW_Wizard_Submission_Repository::findEmail($config, [ 'naam' => 'Anna' ]));
    }

    public function testAValueThatIsNotAnAddressIsNeverReturned(): void
    {
        $config = $this->config([
            [ 'key' => 'ouder_email', 'label' => 'E-mail ouder', 'type' => 'email' ],
        ]);

        $this->assertSame(
            '',
            CJW_Wizard_Submission_Repository::findEmail($config, [ 'ouder_email' => 'niet ingevuld' ])
        );
    }
}
