<?php

declare(strict_types=1);

/**
 * Field roles are how anything knows which answer is the child's name.
 *
 * The alternative was guessing from key names, which fails silently and badly:
 * rename a field and a printed roster shows the wrong child against the wrong
 * allergy. Roles are explicit, so the failure mode is an empty column instead.
 */
final class FieldRolesTest extends CJW_TestCase
{
    /**
     * @param array $fields Fields for a single step.
     */
    private function config(array $fields): array
    {
        return [
            'steps' => [
                'stap' => [
                    'key' => 'stap',
                    'label' => 'Stap',
                    'type' => 'default',
                    'fields' => $fields,
                    'condition' => [],
                ],
            ],
            'rules' => [],
        ];
    }

    public function testFindsTheValueForARole(): void
    {
        $config = $this->config([
            [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
            [ 'key' => 'kamp', 'label' => 'Kamp', 'type' => 'radio', 'role' => CJW_Wizard_Field_Roles::CAMP_TYPE ],
        ]);

        $data = [ 'naam' => 'Anna de Vries', 'kamp' => 'week' ];

        $this->assertSame('Anna de Vries', CJW_Wizard_Field_Roles::valueFor($config, $data, CJW_Wizard_Field_Roles::CHILD_NAME));
        $this->assertSame('week', CJW_Wizard_Field_Roles::valueFor($config, $data, CJW_Wizard_Field_Roles::CAMP_TYPE));
    }

    /**
     * A form that marks nothing must keep working. It simply cannot fill the
     * columns that depend on knowing.
     */
    public function testAFormThatMarksNothingYieldsNothingRatherThanGuessing(): void
    {
        $config = $this->config([
            [ 'key' => 'naam', 'label' => 'Naam van het kind', 'type' => 'text' ],
        ]);

        $this->assertSame(
            '',
            CJW_Wizard_Field_Roles::valueFor($config, [ 'naam' => 'Anna' ], CJW_Wizard_Field_Roles::CHILD_NAME),
            'A label that happens to read like a name is not a role.'
        );
    }

    public function testRenamingAFieldDoesNotBreakTheRole(): void
    {
        $config = $this->config([
            [ 'key' => 'veld_7', 'label' => 'Voornaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
        ]);

        $this->assertSame(
            'Anna',
            CJW_Wizard_Field_Roles::valueFor($config, [ 'veld_7' => 'Anna' ], CJW_Wizard_Field_Roles::CHILD_NAME),
            'The role travels with the field, whatever it is called.'
        );
    }

    public function testTheFirstFieldCarryingARoleWins(): void
    {
        $config = [
            'steps' => [
                'een' => [
                    'key' => 'een', 'label' => 'Een', 'type' => 'default', 'condition' => [],
                    'fields' => [ [ 'key' => 'kind', 'label' => 'Kind', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ] ],
                ],
                'twee' => [
                    'key' => 'twee', 'label' => 'Twee', 'type' => 'default', 'condition' => [],
                    'fields' => [ [ 'key' => 'tweede_kind', 'label' => 'Tweede kind', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ] ],
                ],
            ],
            'rules' => [],
        ];

        $this->assertSame('kind', CJW_Wizard_Field_Roles::pathFor($config, CJW_Wizard_Field_Roles::CHILD_NAME));
    }

    public function testFindsARoleNestedUnderARadioOption(): void
    {
        $config = $this->config([
            [
                'key' => 'soort',
                'label' => 'Soort',
                'type' => 'radio',
                'options' => [
                    [
                        'label' => 'Week',
                        'value' => 'week',
                        'fields' => [
                            [ 'key' => 'week_kamp', 'label' => 'Welke week', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CAMP_TYPE ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            'Week 2',
            CJW_Wizard_Field_Roles::valueFor($config, [ 'soort' => 'week', 'week_kamp' => 'Week 2' ], CJW_Wizard_Field_Roles::CAMP_TYPE)
        );
    }

    public function testFindsARoleInsideARepeaterRow(): void
    {
        $config = $this->config([
            [
                'key' => 'kinderen',
                'label' => 'Kinderen',
                'type' => 'repeater',
                'fields' => [
                    [ 'key' => 'kind_naam', 'label' => 'Naam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                ],
            ],
        ]);

        $this->assertSame(
            'Anna',
            CJW_Wizard_Field_Roles::valueFor($config, [ 'kinderen' => [ [ 'kind_naam' => 'Anna' ] ] ], CJW_Wizard_Field_Roles::CHILD_NAME)
        );
    }

    public function testAnUnknownRoleIsNotAccepted(): void
    {
        $this->assertFalse(CJW_Wizard_Field_Roles::isValid('lievelingskleur'));
        $this->assertFalse(CJW_Wizard_Field_Roles::isValid(''));
        $this->assertFalse(CJW_Wizard_Field_Roles::isValid(42));
        $this->assertTrue(CJW_Wizard_Field_Roles::isValid(CJW_Wizard_Field_Roles::CHILD_NAME));
    }

    /**
     * A hand-edited config must not be able to introduce a role the code will
     * later look for and never find.
     */
    public function testTheNormalizerDropsAnUnknownRole(): void
    {
        $normalized = CJW_Wizard_Config_Normalizer::normalize([
            'steps' => [
                [
                    'key' => 'stap',
                    'fields' => [
                        [ 'key' => 'a', 'label' => 'A', 'type' => 'text', 'role' => 'verzonnen' ],
                        [ 'key' => 'b', 'label' => 'B', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                    ],
                ],
            ],
        ]);

        $fields = $normalized['steps']['stap']['fields'];

        $this->assertSame('', $fields[0]['role'], 'An invented role must be dropped, not stored.');
        $this->assertSame(CJW_Wizard_Field_Roles::CHILD_NAME, $fields[1]['role']);
    }

    public function testTheNormalizerGivesEveryFieldARoleKey(): void
    {
        $normalized = CJW_Wizard_Config_Normalizer::normalize([
            'steps' => [ [ 'key' => 'stap', 'fields' => [ [ 'key' => 'a', 'label' => 'A', 'type' => 'text' ] ] ] ],
        ]);

        $this->assertArrayHasKey(
            'role',
            $normalized['steps']['stap']['fields'][0],
            'Callers should not have to guard against the key being absent.'
        );
    }

    /**
     * The case that made path matching necessary: a registration form asks
     * `voornaam` once per child inside a repeater and once again for the parent.
     * Matching on the bare key put whichever came first in the child column.
     */
    public function testTheParentsNameIsNotMistakenForTheChilds(): void
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
                                [ 'key' => 'achternaam', 'label' => 'Achternaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_LAST_NAME ],
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

        $data = [
            'kinderen' => [ [ 'voornaam' => 'Anna', 'achternaam' => 'de Vries' ] ],
            'voornaam' => 'Pieter',
            'achternaam' => 'Jansen',
        ];

        $this->assertSame('kinderen.*.voornaam', CJW_Wizard_Field_Roles::pathFor($config, CJW_Wizard_Field_Roles::CHILD_NAME));
        $this->assertSame([ 'Anna de Vries' ], CJW_Wizard_Field_Roles::childNames($config, $data));
    }

    public function testAFullNameIsComposedFromItsParts(): void
    {
        $config = $this->config([
            [ 'key' => 'v', 'label' => 'Voornaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
            [ 'key' => 't', 'label' => 'Tussenvoegsel', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_INFIX ],
            [ 'key' => 'a', 'label' => 'Achternaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_LAST_NAME ],
        ]);

        $this->assertSame(
            [ 'Anna van der Berg' ],
            CJW_Wizard_Field_Roles::childNames($config, [ 'v' => 'Anna', 't' => 'van der', 'a' => 'Berg' ])
        );
    }

    public function testAnEmptyInfixDoesNotLeaveADoubleSpace(): void
    {
        $config = $this->config([
            [ 'key' => 'v', 'label' => 'Voornaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
            [ 'key' => 't', 'label' => 'Tussenvoegsel', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_INFIX ],
            [ 'key' => 'a', 'label' => 'Achternaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_LAST_NAME ],
        ]);

        $this->assertSame(
            [ 'Anna Boersma' ],
            CJW_Wizard_Field_Roles::childNames($config, [ 'v' => 'Anna', 't' => '', 'a' => 'Boersma' ])
        );
    }

    public function testEveryChildInARepeaterGetsAName(): void
    {
        $config = $this->config([
            [
                'key' => 'kinderen', 'label' => 'Kinderen', 'type' => 'repeater',
                'fields' => [
                    [ 'key' => 'v', 'label' => 'Voornaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_NAME ],
                    [ 'key' => 'a', 'label' => 'Achternaam', 'type' => 'text', 'role' => CJW_Wizard_Field_Roles::CHILD_LAST_NAME ],
                ],
            ],
        ]);

        $names = CJW_Wizard_Field_Roles::childNames($config, [
            'kinderen' => [
                [ 'v' => 'Anna', 'a' => 'Boersma' ],
                [ 'v' => 'Milan', 'a' => 'Boersma' ],
            ],
        ]);

        $this->assertSame([ 'Anna Boersma', 'Milan Boersma' ], $names, 'A family registering two children must show both.');
    }

    public function testEachChildKeepsTheirOwnCamp(): void
    {
        $config = $this->config([
            [
                'key' => 'kinderen', 'label' => 'Kinderen', 'type' => 'repeater',
                'fields' => [
                    [ 'key' => 'kamp', 'label' => 'Kamp', 'type' => 'radio', 'role' => CJW_Wizard_Field_Roles::CAMP_TYPE ],
                ],
            ],
        ]);

        $this->assertSame(
            [ 'kinderen.0' => 'Weekend', 'kinderen.1' => 'Week' ],
            CJW_Wizard_Field_Roles::valuesFor($config, [ 'kinderen' => [ [ 'kamp' => 'Weekend' ], [ 'kamp' => 'Week' ] ] ], CJW_Wizard_Field_Roles::CAMP_TYPE)
        );
    }

    public function testNamesAndBirthDatesAreMarkedAsPersonal(): void
    {
        $this->assertContains(CJW_Wizard_Field_Roles::CHILD_NAME, CJW_Wizard_Field_Roles::PERSONAL);
        $this->assertContains(CJW_Wizard_Field_Roles::BIRTH_DATE, CJW_Wizard_Field_Roles::PERSONAL);
        $this->assertNotContains(
            CJW_Wizard_Field_Roles::CAMP_TYPE,
            CJW_Wizard_Field_Roles::PERSONAL,
            'Camp type identifies nobody, and keeping it is what makes the yearly counts survive anonymisation.'
        );
    }
}
