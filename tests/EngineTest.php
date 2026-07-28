<?php

declare(strict_types=1);

final class EngineTest extends CJW_TestCase
{
    private array $config;

    public function setUp(): void
    {
        $this->config = [
            'steps' => [
                'participant' => [
                    'key' => 'participant',
                    'label' => 'Deelnemer',
                    'fields' => [
                        [
                            'key' => 'needs_transport',
                            'label' => 'Vervoer nodig',
                            'type' => 'checkbox',
                            'required' => false,
                        ],
                    ],
                ],
                'transport' => [
                    'key' => 'transport',
                    'label' => 'Transport',
                    'condition' => [
                        'field' => 'needs_transport',
                        'operator' => 'equals',
                        'value' => '1',
                    ],
                    'fields' => [
                        [
                            'key' => 'pickup_location',
                            'label' => 'Opstapplaats',
                            'type' => 'text',
                            'required' => true,
                        ],
                    ],
                ],
                'summary' => [
                    'key' => 'summary',
                    'label' => 'Samenvatting',
                    'type' => 'summary',
                ],
            ],
        ];
    }

    public function testSkipsHiddenConditionalStepWhenMovingForward(): void
    {
        $engine = new CJW_Wizard_Engine($this->config);

        $result = $engine->next(
            [
                'step' => 0,
                'data' => [],
            ],
            [ 'cjw_wizard_action' => 'next' ]
        );

        $this->assertSame(2, $result['state']['step']);
        $this->assertSame([], $result['errors']);
    }

    public function testNavigatesToConditionalStepWhenConditionMatches(): void
    {
        $engine = new CJW_Wizard_Engine($this->config);

        $result = $engine->next(
            [
                'step' => 0,
                'data' => [],
            ],
            [
                'cjw_wizard_action' => 'next',
                'cjw_field_needs_transport' => '1',
            ]
        );

        $this->assertSame(1, $result['state']['step']);
        $this->assertSame('1', $result['state']['data']['needs_transport']);
    }

    public function testPreviousSkipsBackOverHiddenConditionalStep(): void
    {
        $engine = new CJW_Wizard_Engine($this->config);

        $result = $engine->next(
            [
                'step' => 2,
                'data' => [ 'needs_transport' => '' ],
            ],
            [ 'cjw_wizard_action' => 'previous' ]
        );

        $this->assertSame(0, $result['state']['step']);
    }

    public function testClearsUncheckedCheckboxesAndInactiveRadioOptionSubfields(): void
    {
        $engine = new CJW_Wizard_Engine(
            [
                'steps' => [
                    'transport' => [
                        'key' => 'transport',
                        'label' => 'Transport',
                        'fields' => [
                            [
                                'key' => 'drive',
                                'label' => 'Kun je rijden?',
                                'type' => 'radio',
                                'required' => true,
                                'options' => [
                                    [
                                        'label' => 'Ja',
                                        'value' => 'Ja',
                                        'fields' => [
                                            [
                                                'key' => 'drive_days',
                                                'label' => 'Beschikbaar op',
                                                'type' => 'checkbox_group',
                                                'required' => false,
                                                'options' => [
                                                    [
                                                        'label' => 'Zaterdag heen',
                                                        'value' => 'saturday_to',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'label' => 'Nee',
                                        'value' => 'Nee',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'summary' => [
                        'key' => 'summary',
                        'label' => 'Samenvatting',
                        'type' => 'summary',
                    ],
                ],
            ]
        );

        $result = $engine->next(
            [
                'step' => 0,
                'data' => [
                    'drive' => 'Ja',
                    'drive_days' => [ 'saturday_to' ],
                ],
            ],
            [
                'cjw_wizard_action' => 'next',
                'cjw_field_drive' => 'Nee',
            ]
        );

        $this->assertSame('Nee', $result['state']['data']['drive']);
        $this->assertSame([], $result['state']['data']['drive_days']);
    }
}
