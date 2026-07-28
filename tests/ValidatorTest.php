<?php

declare(strict_types=1);

final class ValidatorTest extends CJW_TestCase
{
    private CJW_Wizard_Validator $validator;

    public function setUp(): void
    {
        $config = [
            'steps' => [
                'contact' => [
                    'fields' => [
                        [
                            'key' => 'email',
                            'label' => 'E-mail',
                            'type' => 'email',
                            'required' => true,
                        ],
                        [
                            'key' => 'camp_days',
                            'label' => 'Dagen',
                            'type' => 'textarea',
                            'required' => false,
                            'min_length' => 5,
                        ],
                        [
                            'key' => 'start_date',
                            'label' => 'Start',
                            'type' => 'date',
                            'required' => false,
                        ],
                        [
                            'key' => 'end_date',
                            'label' => 'Einde',
                            'type' => 'date',
                            'required' => false,
                            'date_after_field' => 'start_date',
                        ],
                        [
                            'key' => 'children',
                            'label' => 'Kinderen',
                            'type' => 'repeater',
                            'required' => true,
                            'max_entries' => 2,
                            'fields' => [
                                [
                                    'key' => 'name',
                                    'label' => 'Naam',
                                    'type' => 'text',
                                    'required' => true,
                                ],
                                [
                                    'key' => 'note',
                                    'label' => 'Notitie',
                                    'type' => 'text',
                                    'required' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->validator = new CJW_Wizard_Validator($config);
    }

    public function testRejectsInvalidEmail(): void
    {
        $errors = $this->validator->validateStep('contact', [ 'email' => 'not-an-email' ], [ 'email' => 'not-an-email' ]);

        $this->assertArrayHasKey('email', $errors);
    }

    public function testRejectsDateBeforeLinkedStartDate(): void
    {
        $submitted = [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-09',
            'children' => [
                [ 'name' => 'Anna' ],
            ],
        ];

        $errors = $this->validator->validateStep('contact', $submitted, $submitted);

        $this->assertArrayHasKey('end_date', $errors);
    }

    public function testRejectsTooManyRepeaterRows(): void
    {
        $submitted = [
            'email' => 'test@example.com',
            'children' => [
                [ 'name' => 'Anna' ],
                [ 'name' => 'Milan' ],
                [ 'name' => 'Saar' ],
            ],
        ];

        $errors = $this->validator->validateStep('contact', $submitted, $submitted);

        $this->assertArrayHasKey('children', $errors);
    }

    public function testValidatesRepeaterSubfieldRequirements(): void
    {
        $submitted = [
            'email' => 'test@example.com',
            'children' => [
                [
                    'name' => '',
                    'note' => 'has content',
                ],
            ],
        ];

        $errors = $this->validator->validateStep('contact', $submitted, $submitted);

        $this->assertArrayHasKey('children.0.name', $errors);
    }

    public function testValidatesRadioOptionSubfieldsOnlyWhenSelected(): void
    {
        $validator = new CJW_Wizard_Validator(
            [
                'steps' => [
                    'transport' => [
                        'fields' => [
                            [
                                'key' => 'drive',
                                'label' => 'Kun je rijden?',
                                'type' => 'radio',
                                'required' => true,
                                'options' => [
                                    [
                                        'label' => 'Ja, ik kan rijden',
                                        'value' => 'Ja',
                                        'fields' => [
                                            [
                                                'key' => 'drive_saturday_to',
                                                'label' => 'Zaterdag heen',
                                                'type' => 'checkbox',
                                                'required' => true,
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
                ],
            ]
        );

        $errors = $validator->validateStep('transport', [ 'drive' => 'Ja' ], [ 'drive' => 'Ja' ]);
        $this->assertArrayHasKey('drive_saturday_to', $errors);

        $errors = $validator->validateStep('transport', [ 'drive' => 'Nee' ], [ 'drive' => 'Nee' ]);
        $this->assertArrayNotHasKey('drive_saturday_to', $errors);
    }

    public function testCanRequireAtLeastOneCheckboxInCheckboxGroup(): void
    {
        $validator = new CJW_Wizard_Validator(
            [
                'steps' => [
                    'transport' => [
                        'fields' => [
                            [
                                'key' => 'drive',
                                'label' => 'Kun je rijden?',
                                'type' => 'radio',
                                'required' => true,
                                'options' => [
                                    [
                                        'label' => 'Ja, ik kan rijden',
                                        'value' => 'Ja',
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
                                                    [
                                                        'label' => 'Maandag terug',
                                                        'value' => 'monday_back',
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
                ],
            ]
        );

        $errors = $validator->validateStep(
            'transport',
            [
                'drive' => 'Ja',
                'drive_days' => [],
            ],
            [ 'drive' => 'Ja' ]
        );
        $this->assertArrayHasKey('drive_days', $errors);

        $errors = $validator->validateStep(
            'transport',
            [
                'drive' => 'Ja',
                'drive_days' => [ 'saturday_to' ],
            ],
            [
                'drive' => 'Ja',
                'drive_days' => [ 'saturday_to' ],
            ]
        );
        $this->assertArrayNotHasKey('drive_days', $errors);

        $errors = $validator->validateStep('transport', [ 'drive' => 'Nee' ], [ 'drive' => 'Nee' ]);
        $this->assertArrayNotHasKey('drive_days', $errors);
    }
}
