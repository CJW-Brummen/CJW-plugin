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

    public function testRendersCompletionStateWhenNoStepsRemain(): void
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
        $renderer->render(
            [
                'step' => 0,
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
