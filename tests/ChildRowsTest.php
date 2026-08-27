<?php

declare(strict_types=1);

/**
 * A family registering three children is one submission. The submissions screen
 * answers "who filled this in"; the Kinderen screen answers "who is coming",
 * and that needs the repeater pulled apart into one record per child.
 */
final class ChildRowsTest extends CJW_TestCase
{
    /**
     * A registration form: children in a repeater, parent details beside them.
     */
    private function config(): array
    {
        return [
            'steps' => [
                'kind' => [
                    'key' => 'kind', 'label' => 'Kind', 'type' => 'default', 'condition' => [],
                    'fields' => [
                        [
                            'key' => 'kinderen', 'label' => 'Kinderen', 'type' => 'repeater',
                            'fields' => [
                                [ 'key' => 'voornaam', 'label' => 'Voornaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                                [ 'key' => 'tussenvoegsel', 'label' => 'Tussenvoegsel', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_INFIX ],
                                [ 'key' => 'achternaam', 'label' => 'Achternaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_LAST_NAME ],
                                [ 'key' => 'geboortedatum', 'label' => 'Geboortedatum', 'type' => 'date', 'role' => CJW_Wizard_Field_Roles::BIRTH_DATE ],
                                [ 'key' => 'soort_kamp', 'label' => 'Kamp', 'type' => 'radio', 'role' => CJW_Wizard_Field_Roles::CAMP_TYPE ],
                            ],
                        ],
                    ],
                ],
                'ouder' => [
                    'key' => 'ouder', 'label' => 'Ouder', 'type' => 'default', 'condition' => [],
                    'fields' => [
                        [ 'key' => 'voornaam', 'label' => 'Voornaam ouder', 'type' => 'text' ],
                        [ 'key' => 'achternaam', 'label' => 'Achternaam ouder', 'type' => 'text' ],
                    ],
                ],
            ],
            'rules' => [],
        ];
    }

    public function testEachChildBecomesItsOwnRecord(): void
    {
        $rows = CJW_Wizard_Field_Roles::childRows($this->config(), [
            'kinderen' => [
                [ 'voornaam' => 'Imre', 'tussenvoegsel' => 'van', 'achternaam' => 'Boersma', 'geboortedatum' => '2014-08-06', 'soort_kamp' => 'Weekendkamp' ],
                [ 'voornaam' => 'Anna', 'tussenvoegsel' => '', 'achternaam' => 'Boersma', 'geboortedatum' => '2016-03-14', 'soort_kamp' => 'Weekkamp' ],
            ],
            'voornaam' => 'Pieter',
            'achternaam' => 'Jansen',
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame('Imre van Boersma', $rows[0]['name']);
        $this->assertSame('Anna Boersma', $rows[1]['name'], 'An empty infix must not leave a double space.');
        $this->assertSame('Weekendkamp', $rows[0]['camp']);
        $this->assertSame('Weekkamp', $rows[1]['camp'], 'Each child keeps their own camp.');
    }

    public function testTheParentIsNeverListedAsAChild(): void
    {
        $rows = CJW_Wizard_Field_Roles::childRows($this->config(), [
            'kinderen' => [ [ 'voornaam' => 'Anna', 'achternaam' => 'Boersma' ] ],
            'voornaam' => 'Pieter',
            'achternaam' => 'Jansen',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Anna Boersma', $rows[0]['name']);
    }

    /**
     * The wizard keeps a slot for every repeater row the parent opened, and
     * people open one more than they need.
     */
    public function testAnEmptyRepeaterSlotIsNotAChild(): void
    {
        $rows = CJW_Wizard_Field_Roles::childRows($this->config(), [
            'kinderen' => [
                [ 'voornaam' => 'Anna', 'achternaam' => 'Boersma', 'soort_kamp' => 'Weekkamp' ],
                [ 'voornaam' => '', 'tussenvoegsel' => '', 'achternaam' => '', 'geboortedatum' => '', 'soort_kamp' => '' ],
            ],
        ]);

        $this->assertCount(1, $rows, 'A blank slot must not become a child with no name.');
    }

    public function testAFormWithoutARepeaterStillYieldsOneChild(): void
    {
        $config = [
            'steps' => [
                'kind' => [
                    'key' => 'kind', 'label' => 'Kind', 'type' => 'default', 'condition' => [],
                    'fields' => [
                        [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                        [ 'key' => 'kamp', 'label' => 'Kamp', 'type' => 'radio', 'role' => CJW_Wizard_Field_Roles::CAMP_TYPE ],
                    ],
                ],
            ],
            'rules' => [],
        ];

        $rows = CJW_Wizard_Field_Roles::childRows($config, [ 'naam' => 'Anna', 'kamp' => 'Weekkamp' ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Anna', $rows[0]['name']);
    }

    public function testAFormMarkingNoRolesYieldsNoChildren(): void
    {
        $config = [
            'steps' => [ 'a' => [ 'key' => 'a', 'label' => 'A', 'type' => 'default', 'condition' => [], 'fields' => [
                [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text' ],
            ] ] ],
            'rules' => [],
        ];

        $this->assertSame([], CJW_Wizard_Field_Roles::childRows($config, [ 'naam' => 'Anna' ]));
    }

    /**
     * Photo consent is asked once for the whole family, outside the children
     * repeater. Its empty row prefix used to be treated as another child, so
     * every registration grew a nameless extra row -- visible as a phantom
     * entry in the summary and a bogus "camp without a price" warning.
     */
    public function testAFormLevelRoleDoesNotInventAnExtraChild(): void
    {
        $config = [
            'steps' => [
                'kind' => [
                    'key' => 'kind', 'label' => 'Kind', 'type' => 'default', 'condition' => [],
                    'fields' => [
                        [
                            'key' => 'kinderen', 'label' => 'Kinderen', 'type' => 'repeater',
                            'fields' => [
                                [ 'key' => 'voornaam', 'label' => 'Voornaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                                [ 'key' => 'kamp', 'label' => 'Kamp', 'type' => 'radio', 'role' => CJW_Wizard_Field_Roles::CAMP_TYPE ],
                            ],
                        ],
                    ],
                ],
                'beeld' => [
                    'key' => 'beeld', 'label' => 'Beeldmateriaal', 'type' => 'default', 'condition' => [],
                    'fields' => [
                        [ 'key' => 'toestemming', 'label' => 'Toestemming', 'type' => 'checkbox', 'role' => CJW_Wizard_Field_Roles::PHOTO_CONSENT ],
                    ],
                ],
            ],
            'rules' => [],
        ];

        $rows = CJW_Wizard_Field_Roles::childRows($config, [
            'kinderen' => [ [ 'voornaam' => 'Anna', 'kamp' => 'Weekkamp' ] ],
            'toestemming' => '1',
        ]);

        $this->assertCount(1, $rows, 'Consent given once for the family is not a second child.');
        $this->assertSame('Anna', $rows[0]['name']);
    }

    /**
     * The mirror case: when the form asks about one child directly, the
     * form-level prefix IS the child, and consent belongs to them.
     */
    public function testAFormWithoutARepeaterKeepsItsSingleChild(): void
    {
        $config = [
            'steps' => [ 'a' => [ 'key' => 'a', 'label' => 'A', 'type' => 'default', 'condition' => [], 'fields' => [
                [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                [ 'key' => 'toestemming', 'label' => 'Toestemming', 'type' => 'checkbox', 'role' => CJW_Wizard_Field_Roles::PHOTO_CONSENT ],
            ] ] ],
            'rules' => [],
        ];

        $rows = CJW_Wizard_Field_Roles::childRows($config, [ 'naam' => 'Anna', 'toestemming' => '1' ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Anna', $rows[0]['name']);
        $this->assertSame('1', $rows[0]['photo_consent']);
    }

    public function testAStatusHasAColourAndAReadableLabel(): void
    {
        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            $badge = CJW_Wizard_Submission_Status::badge($status);

            $this->assertStringContainsString(CJW_Wizard_Submission_Status::colour($status), $badge);
            $this->assertStringContainsString(
                CJW_Wizard_Submission_Status::label($status),
                $badge,
                'Colour alone is not an indicator; the label has to be there too.'
            );
            $this->assertStringContainsString('aria-hidden="true"', $badge, 'The dot is decorative and must not be read out.');
        }
    }

    public function testAnUnknownStatusStillGetsAUsableBadge(): void
    {
        $badge = CJW_Wizard_Submission_Status::badge('publish');

        $this->assertStringContainsString('publish', $badge);
        $this->assertStringContainsString('#8c8f94', $badge, 'An unknown status falls back to the neutral grey.');
    }
}
