<?php

declare(strict_types=1);

/**
 * The engine used to keep every POST key prefixed `cjw_field_` regardless of
 * whether the form asked for it, merge it into session state, and persist it to
 * post meta. The step's own configuration is the only list of keys that should
 * ever survive.
 */
final class EngineInputBoundsTest extends CJW_TestCase
{
    /**
     * @param array $fields Field configuration for the single step.
     */
    private function engine(array $fields): CJW_Wizard_Engine
    {
        return new CJW_Wizard_Engine([
            'steps' => [
                'details' => [
                    'key' => 'details',
                    'label' => 'Gegevens',
                    'type' => 'default',
                    'fields' => $fields,
                    'condition' => [],
                ],
            ],
            'rules' => [],
        ]);
    }

    /**
     * @param array $fields Field configuration.
     * @param array $post   Raw request payload.
     */
    private function submit(array $fields, array $post): array
    {
        $result = $this->engine($fields)->next([ 'step' => 0, 'data' => [] ], $post);

        return $result['state']['data'];
    }

    public function testKeysTheFormNeverAskedForAreDropped(): void
    {
        $data = $this->submit(
            [ [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'required' => false, 'condition' => [] ] ],
            [
                'cjw_field_naam' => 'Anna',
                'cjw_field_stowaway' => 'x',
                'cjw_field_another' => str_repeat('y', 100),
            ]
        );

        $this->assertSame([ 'naam' => 'Anna' ], $data);
    }

    public function testAValueIsCappedAtTheFieldsOwnConfiguredLength(): void
    {
        $data = $this->submit(
            [ [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'required' => false, 'max_length' => 10, 'condition' => [] ] ],
            [ 'cjw_field_naam' => str_repeat('a', 500) ]
        );

        $this->assertSame(10, strlen($data['naam']));
    }

    public function testAValueWithNoConfiguredLengthFallsBackToTheBlanketCeiling(): void
    {
        $data = $this->submit(
            [ [ 'key' => 'notitie', 'label' => 'Notitie', 'type' => 'textarea', 'required' => false, 'condition' => [] ] ],
            [ 'cjw_field_notitie' => str_repeat('a', 50000) ]
        );

        $this->assertSame(1000, strlen($data['notitie']));
    }

    public function testAnOrdinaryAnswerIsNotTruncated(): void
    {
        $note = 'Anna is allergisch voor noten en heeft een EpiPen bij zich.';

        $data = $this->submit(
            [ [ 'key' => 'notitie', 'label' => 'Notitie', 'type' => 'textarea', 'required' => false, 'condition' => [] ] ],
            [ 'cjw_field_notitie' => $note ]
        );

        $this->assertSame($note, $data['notitie']);
    }

    public function testFieldsHangingOffARadioOptionAreStillAllowed(): void
    {
        $data = $this->submit(
            [
                [
                    'key' => 'vervoer',
                    'label' => 'Vervoer',
                    'type' => 'radio',
                    'required' => false,
                    'condition' => [],
                    'options' => [
                        [
                            'label' => 'Zelf',
                            'value' => 'zelf',
                            'fields' => [
                                [ 'key' => 'kenteken', 'label' => 'Kenteken', 'type' => 'text', 'required' => false, 'condition' => [] ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'cjw_field_vervoer' => 'zelf',
                'cjw_field_kenteken' => 'AB-12-CD',
                'cjw_field_bogus' => 'nope',
            ]
        );

        $this->assertSame('zelf', $data['vervoer']);
        $this->assertSame('AB-12-CD', $data['kenteken']);
        $this->assertArrayNotHasKey('bogus', $data);
    }

    public function testRepeaterRowsAreCappedAndTheirValuesBounded(): void
    {
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = [ 'naam' => str_repeat('z', 3000), 'smokkelwaar' => 'x' ];
        }

        $data = $this->submit(
            [
                [
                    'key' => 'kinderen',
                    'label' => 'Kinderen',
                    'type' => 'repeater',
                    'required' => false,
                    'condition' => [],
                    'max_entries' => 4,
                    'fields' => [
                        [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'required' => false, 'condition' => [] ],
                    ],
                ],
            ],
            [ 'cjw_field_kinderen' => $rows ]
        );

        $this->assertCount(4, $data['kinderen'], 'Rows beyond max_entries must be discarded.');
        $this->assertSame([ 'naam' ], array_keys($data['kinderen'][0]), 'Unknown sub-keys must be dropped too.');
        $this->assertSame(1000, strlen($data['kinderen'][0]['naam']));
    }

    /**
     * A checkbox group's value is a flat list of chosen options, not repeater
     * rows. It used to be sent through the repeater path, so a group offering
     * more than 50 options silently dropped the tail of a parent's answers.
     */
    public function testACheckboxGroupKeepsEveryChoiceTheFormOffered(): void
    {
        $options = array_map(static fn(int $i): array => [ 'label' => "Optie {$i}", 'value' => "o{$i}" ], range(1, 80));

        $data = $this->submit(
            [
                [
                    'key' => 'opties',
                    'label' => 'Opties',
                    'type' => 'checkbox_group',
                    'required' => false,
                    'condition' => [],
                    'options' => $options,
                ],
            ],
            [ 'cjw_field_opties' => array_map(static fn(int $i): string => "o{$i}", range(1, 80)) ]
        );

        $this->assertCount(80, $data['opties'], 'All 80 chosen options must survive, not 50.');
    }

    public function testACheckboxGroupCannotReturnMoreChoicesThanItOffered(): void
    {
        $data = $this->submit(
            [
                [
                    'key' => 'opties',
                    'label' => 'Opties',
                    'type' => 'checkbox_group',
                    'required' => false,
                    'condition' => [],
                    'options' => [ [ 'label' => 'Ja', 'value' => 'ja' ], [ 'label' => 'Nee', 'value' => 'nee' ] ],
                ],
            ],
            [ 'cjw_field_opties' => array_fill(0, 500, 'ja') ]
        );

        $this->assertCount(2, $data['opties'], 'A two-option group cannot legitimately return 500 answers.');
    }

    public function testCheckboxGroupValuesAreLengthBoundedLikeAnyOtherAnswer(): void
    {
        $data = $this->submit(
            [
                [
                    'key' => 'opties',
                    'label' => 'Opties',
                    'type' => 'checkbox_group',
                    'required' => false,
                    'condition' => [],
                    'options' => [ [ 'label' => 'Ja', 'value' => 'ja' ] ],
                ],
            ],
            [ 'cjw_field_opties' => [ str_repeat('x', 50000) ] ]
        );

        $this->assertSame(1000, strlen($data['opties'][0]), 'An unbounded checkbox value is a hole in the size cap.');
    }

    public function testRepeaterWithoutMaxEntriesFallsBackToTheBlanketRowCeiling(): void
    {
        $rows = array_fill(0, 500, [ 'naam' => 'Anna' ]);

        $data = $this->submit(
            [
                [
                    'key' => 'kinderen',
                    'label' => 'Kinderen',
                    'type' => 'repeater',
                    'required' => false,
                    'condition' => [],
                    'fields' => [
                        [ 'key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'required' => false, 'condition' => [] ],
                    ],
                ],
            ],
            [ 'cjw_field_kinderen' => $rows ]
        );

        $this->assertCount(50, $data['kinderen']);
    }
}
