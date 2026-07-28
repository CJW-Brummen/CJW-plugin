<?php

declare(strict_types=1);

final class ConditionsTest extends CJW_TestCase
{
    public function testVisibleWithoutCondition(): void
    {
        $this->assertTrue(CJW_Wizard_Conditions::isVisible([ 'label' => 'Naam' ], []));
    }

    public function testMatchesDotNotationInRepeaterData(): void
    {
        $data = [
            'kids' => [
                [ 'name' => 'Anna' ],
            ],
        ];

        $this->assertSame('Anna', CJW_Wizard_Conditions::getValueByPath($data, 'kids.0.name'));
    }

    public function testContainsOperatorMatchesNormalizedValues(): void
    {
        $condition = [
            'field' => 'group',
            'operator' => 'contains',
            'value' => 'a',
        ];

        $this->assertTrue(CJW_Wizard_Conditions::matches($condition, [ 'group' => ' Groep A ' ]));
    }

    public function testEmptyOperatorDetectsNestedEmptyArray(): void
    {
        $condition = [
            'field' => 'kids',
            'operator' => 'empty',
        ];

        $this->assertTrue(CJW_Wizard_Conditions::matches($condition, [ 'kids' => [ [ 'name' => '' ], [ 'name' => '' ] ] ]));
    }
}
