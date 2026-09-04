<?php

declare(strict_types=1);

final class RendererTest extends CJW_TestCase
{
    public function testRendersPreviousButtonOnVisibleStepAfterFirst(): void
    {
        $renderer = new CJW_Wizard_Renderer(
            [
                'steps' => [
                    'participant' => [
                        'key' => 'participant',
                        'label' => 'Deelnemer',
                        'type' => 'default',
                        'fields' => [],
                    ],
                    'summary' => [
                        'key' => 'summary',
                        'label' => 'Samenvatting',
                        'type' => 'summary',
                    ],
                ],
                'settings' => [
                    ...CJW_Wizard_Defaults::settings(),
                    'show_previous_button' => true,
                    'previous_label' => 'Terug',
                ],
            ]
        );

        ob_start();
        $renderer->render(
            [
                'step' => 1,
                'data' => [],
            ],
            [],
            'abc123'
        );
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('value="previous"', $output);
        $this->assertStringContainsString('Terug', $output);
        $this->assertStringContainsString('Samenvatting', $output);
    }

    /**
     * A form with steps, rendered past the last of them. This is the only
     * state that has ever earned the success screen.
     */
    public function testRendersCompletionStateWhenNoStepsRemain(): void
    {
        $renderer = new CJW_Wizard_Renderer(
            [
                'steps' => [
                    'participant' => [
                        'key' => 'participant',
                        'label' => 'Deelnemer',
                        'type' => 'default',
                        'fields' => [],
                    ],
                ],
                'settings' => [
                    ...CJW_Wizard_Defaults::settings(),
                    'success_message' => 'Alles gelukt.',
                ],
            ]
        );

        ob_start();
        $renderer->render(
            [
                'step' => 1,
                'data' => [],
            ],
            [],
            'done-token'
        );
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Gelukt!', $output);
        $this->assertStringContainsString('Alles gelukt.', $output);
        $this->assertStringContainsString('reset_wizard=1', $output);
    }

    /**
     * @param array<string, mixed> $completion
     */
    private function completed(array $completion): string
    {
        $renderer = new CJW_Wizard_Renderer(
            [
                'steps' => [
                    'participant' => [
                        'key' => 'participant',
                        'label' => 'Deelnemer',
                        'type' => 'default',
                        'fields' => [],
                    ],
                ],
                'settings' => CJW_Wizard_Defaults::settings(),
            ]
        );

        ob_start();
        $renderer->render(
            [
                'step' => 1,
                'data' => [],
                'completion' => $completion,
            ],
            [],
            'done-token'
        );

        return (string) ob_get_clean();
    }

    /**
     * A tick is not proof. A number a parent can quote on the phone is.
     */
    public function testTheSuccessScreenCarriesTheRegistrationNumber(): void
    {
        $output = $this->completed([ 'submission' => 4210, 'email' => '', 'mail' => '' ]);

        $this->assertStringContainsString('4210', $output);
        $this->assertStringContainsString('inschrijfnummer', $output);
    }

    public function testTheSuccessScreenNamesTheAddressTheConfirmationWentTo(): void
    {
        $output = $this->completed([
            'submission' => 7,
            'email' => 'ouder@example.test',
            'mail' => CJW_Wizard_Submission_Confirmation::SENT,
        ]);

        $this->assertStringContainsString('ouder@example.test', $output);
    }

    /**
     * The registration is stored either way; only the mail failed. Saying so
     * here is the difference between a parent waiting for an inbox that stays
     * empty and a parent who knows where they stand.
     */
    public function testAFailedConfirmationIsAdmittedOnTheSuccessScreen(): void
    {
        $output = $this->completed([
            'submission' => 7,
            'email' => 'ouder@example.test',
            'mail' => CJW_Wizard_Submission_Confirmation::FAILED,
        ]);

        $this->assertStringContainsString('Gelukt!', $output);
        $this->assertStringContainsString('lukte niet', $output);
        $this->assertStringNotContainsString('We hebben een bevestiging gestuurd', $output);
    }

    /**
     * A form that promises no confirmation should not mention one.
     */
    public function testNothingIsSaidAboutMailWhenTheFormSendsNone(): void
    {
        $output = $this->completed([ 'submission' => 7, 'email' => '', 'mail' => '' ]);

        $this->assertStringNotContainsString('bevestiging', $output);
        $this->assertStringNotContainsString('lukte niet', $output);
    }

    /**
     * The defect this suite used to assert: an empty step list took the same
     * branch as a finished registration, so a mistyped shortcode id told a
     * parent "Gelukt!" while nothing had been saved and nobody mailed.
     */
    public function testEmptyStepListIsNotReportedAsASuccessfulRegistration(): void
    {
        $renderer = new CJW_Wizard_Renderer(
            [
                'steps' => [],
                'settings' => [
                    ...CJW_Wizard_Defaults::settings(),
                    'success_message' => 'Alles gelukt.',
                ],
            ]
        );

        ob_start();
        $renderer->render([ 'step' => 0, 'data' => [] ], [], 'no-form-token');
        $output = (string) ob_get_clean();

        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringNotContainsString('Alles gelukt.', $output);
        $this->assertStringContainsString('staat op dit moment niet klaar', $output);
    }

    /**
     * The reason is for the person who can act on it, and for nobody else: a
     * parent has no use for "this form has no steps" and every reason to be
     * unsettled by it.
     */
    public function testTheReasonIsShownOnlyToSomebodyWhoCanEditTheForm(): void
    {
        $config = [
            'steps' => [],
            'settings' => CJW_Wizard_Defaults::settings(),
        ];

        ob_start();
        new CJW_Wizard_Renderer($config, false)->render([ 'step' => 0, 'data' => [] ], [], 'tok');
        $visitor = (string) ob_get_clean();

        ob_start();
        new CJW_Wizard_Renderer($config, true)->render([ 'step' => 0, 'data' => [] ], [], 'tok');
        $editor = (string) ob_get_clean();

        $this->assertStringNotContainsString('formulierbouwer', $visitor);
        $this->assertStringContainsString('formulierbouwer', $editor);
    }

    /**
     * The second route to a false success: a first step carrying a condition
     * that is false on an empty state. The form is not finished, it simply
     * starts later.
     */
    public function testConditionalFirstStepRendersTheNextVisibleStepNotSuccess(): void
    {
        $renderer = new CJW_Wizard_Renderer(
            [
                'steps' => [
                    'brothers' => [
                        'key' => 'brothers',
                        'label' => 'Broers en zussen',
                        'type' => 'default',
                        'fields' => [],
                        'condition' => [
                            'field' => 'has_siblings',
                            'operator' => 'equals',
                            'value' => 'ja',
                        ],
                    ],
                    'participant' => [
                        'key' => 'participant',
                        'label' => 'Deelnemer',
                        'type' => 'default',
                        'fields' => [],
                    ],
                ],
                'settings' => CJW_Wizard_Defaults::settings(),
            ]
        );

        ob_start();
        $renderer->render([ 'step' => 0, 'data' => [] ], [], 'tok');
        $output = (string) ob_get_clean();

        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringContainsString('Deelnemer', $output);
        $this->assertStringContainsString('cjw-wizard-form', $output);
    }

    /**
     * Every step conditioned away leaves nothing to fill in, which is a broken
     * form rather than a completed one.
     */
    public function testFormWithEveryStepHiddenIsUnavailableRatherThanComplete(): void
    {
        $renderer = new CJW_Wizard_Renderer(
            [
                'steps' => [
                    'participant' => [
                        'key' => 'participant',
                        'label' => 'Deelnemer',
                        'type' => 'default',
                        'fields' => [],
                        'condition' => [
                            'field' => 'never_set',
                            'operator' => 'equals',
                            'value' => 'ja',
                        ],
                    ],
                ],
                'settings' => CJW_Wizard_Defaults::settings(),
            ]
        );

        ob_start();
        $renderer->render([ 'step' => 0, 'data' => [] ], [], 'tok');
        $output = (string) ob_get_clean();

        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringContainsString('staat op dit moment niet klaar', $output);
    }

    public function testRendersConditionAttributesForConditionalField(): void
    {
        $renderer = new CJW_Wizard_Renderer(
            [
                'steps' => [
                    'participant' => [
                        'key' => 'participant',
                        'label' => 'Deelnemer',
                        'type' => 'default',
                        'fields' => [
                            [
                                'key' => 'pickup_location',
                                'label' => 'Opstapplaats',
                                'type' => 'text',
                                'condition' => [
                                    'field' => 'needs_transport',
                                    'operator' => 'equals',
                                    'value' => '1',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        ob_start();
        $renderer->render(
            [
                'step' => 0,
                'data' => [],
            ],
            [],
            'cond-token'
        );
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('data-condition-field="needs_transport"', $output);
        $this->assertStringContainsString('data-condition-operator="equals"', $output);
        $this->assertStringContainsString('data-condition-value="1"', $output);
    }

    public function testRendersRadioOptionSubfieldsIndentedUnderOption(): void
    {
        $renderer = new CJW_Wizard_Renderer(
            [
                'steps' => [
                    'transport' => [
                        'key' => 'transport',
                        'label' => 'Vervoer',
                        'type' => 'default',
                        'fields' => [
                            [
                                'key' => 'drive',
                                'label' => 'Kun je rijden?',
                                'type' => 'radio',
                                'required' => true,
                                'options' => [
                                    [
                                        'label' => 'Ja, ik kan rijden en ben beschikbaar op:',
                                        'value' => 'Ja',
                                        'default' => true,
                                        'fields' => [
                                            [
                                                'key' => 'drive_days',
                                                'label' => 'Beschikbaar op',
                                                'type' => 'checkbox_group',
                                                'required' => true,
                                                'options' => [
                                                    [
                                                        'label' => 'Zaterdag 18 juli (heen)',
                                                        'value' => 'saturday_to',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'label' => 'Nee, ik ben niet beschikbaar',
                                        'value' => 'Nee',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        ob_start();
        $renderer->render(
            [
                'step' => 0,
                'data' => [],
            ],
            [],
            'transport-token'
        );
        $output = (string) ob_get_clean();

        // WordPress's own format: a leading space and single quotes. This used
        // to assert checked="checked", which was the test shim's invention.
        $this->assertStringContainsString('value="Ja"  checked=\'checked\'', $output);
        $this->assertStringContainsString('class="cjw-radio-option-children indented-checkboxes"', $output);
        $this->assertStringContainsString('data-condition-field="drive"', $output);
        $this->assertStringContainsString('name="cjw_field_drive_days[]"', $output);
        $this->assertStringContainsString('Zaterdag 18 juli (heen)', $output);
    }
}
