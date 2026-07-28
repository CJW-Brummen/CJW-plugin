<?php

declare(strict_types=1);

final class SubmissionRepositoryTest extends CJW_TestCase
{
    public function testFlattenDataUsesDotNotationForNestedArrays(): void
    {
        $repository = new CJW_Wizard_Submission_Repository();

        $flat = $repository->flatten_data(
            [
                'participant' => [
                    'name' => 'Sam',
                ],
                'children' => [
                    [ 'name' => 'Anna' ],
                    [ 'name' => 'Milan' ],
                ],
            ]
        );

        $this->assertSame(
            [
                'participant.name' => 'Sam',
                'children.0.name' => 'Anna',
                'children.1.name' => 'Milan',
            ],
            $flat
        );
    }
}
