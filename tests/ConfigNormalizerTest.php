<?php

declare(strict_types=1);

final class ConfigNormalizerTest extends CJW_TestCase
{
    public function testNormalizesStepListIntoKeyedRuntimeMap(): void
    {
        $normalized = CJW_Wizard_Config_Normalizer::normalize(
            [
                'steps' => [
                    [
                        'key' => 'participant',
                        'label' => 'Deelnemer',
                        'fields' => [
                            [
                                'key' => 'email',
                                'label' => 'E-mail',
                                'type' => 'email',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertArrayHasKey('participant', $normalized['steps']);
        $this->assertSame('participant', $normalized['steps']['participant']['key']);
        $this->assertSame('email', $normalized['steps']['participant']['fields'][0]['key']);
    }

    public function testReturnsEmptyStructureForInvalidConfig(): void
    {
        $this->assertSame(
            [
                'steps' => [],
                'rules' => [],
            ],
            CJW_Wizard_Config_Normalizer::normalize('not-json')
        );
    }

    public function testNormalizesRepeaterAndConditionStructure(): void
    {
        $normalized = CJW_Wizard_Config_Normalizer::normalize(
            [
                'steps' => [
                    'children' => [
                        'label' => 'Kinderen',
                        'fields' => [
                            [
                                'key' => 'kids',
                                'type' => 'repeater',
                                'max_entries' => '3',
                                'condition' => [
                                    'field' => 'needs_children',
                                    'operator' => 'equals',
                                    'value' => '1',
                                ],
                                'fields' => [
                                    'Naam kind',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $field = $normalized['steps']['children']['fields'][0];

        $this->assertSame(3, $field['max_entries']);
        $this->assertSame('needs_children', $field['condition']['field']);
        $this->assertSame('text', $field['fields'][0]['type']);
    }

    public function testNormalizesRadioOptionSubfields(): void
    {
        $normalized = CJW_Wizard_Config_Normalizer::normalize(
            [
                'steps' => [
                    [
                        'key' => 'transport',
                        'fields' => [
                            [
                                'key' => 'drive',
                                'label' => 'Kun je rijden?',
                                'type' => 'radio',
                                'options' => [
                                    [
                                        'label' => 'Ja, ik kan rijden',
                                        'value' => 'Ja',
                                        'default' => true,
                                        'fields' => [
                                            [
                                                'key' => 'drive_saturday_to',
                                                'label' => 'Zaterdag heen',
                                                'type' => 'checkbox',
                                            ],
                                        ],
                                    ],
                                    'Nee',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $options = $normalized['steps']['transport']['fields'][0]['options'];

        $this->assertSame('Ja, ik kan rijden', $options[0]['label']);
        $this->assertSame('Ja', $options[0]['value']);
        $this->assertTrue($options[0]['default']);
        $this->assertSame('drive_saturday_to', $options[0]['fields'][0]['key']);
        $this->assertSame('Nee', $options[1]);
    }

    public function testNormalizesCheckboxGroupOptions(): void
    {
        $normalized = CJW_Wizard_Config_Normalizer::normalize(
            [
                'steps' => [
                    [
                        'key' => 'transport',
                        'fields' => [
                            [
                                'key' => 'drive_days',
                                'label' => 'Beschikbaar op',
                                'type' => 'checkbox_group',
                                'required' => true,
                                'options' => [
                                    [
                                        'label' => 'Zaterdag heen',
                                        'value' => 'saturday_to',
                                    ],
                                    'Maandag terug',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $field = $normalized['steps']['transport']['fields'][0];

        $this->assertSame('checkbox_group', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertSame('Zaterdag heen', $field['options'][0]['label']);
        $this->assertSame('saturday_to', $field['options'][0]['value']);
        $this->assertSame('Maandag terug', $field['options'][1]);
    }
}
