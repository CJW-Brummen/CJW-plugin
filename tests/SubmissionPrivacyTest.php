<?php

declare(strict_types=1);

/**
 * An erasure request that reports success while leaving personal data on disk
 * is worse than one that fails loudly: nobody goes looking again.
 *
 * WordPress drives an eraser as a page-incrementing AJAX loop -- page 1, then 2,
 * then 3, until the callback says done -- and re-runs the query on each page.
 * The eraser deletes the very meta key it finds rows by, so the set shrinks
 * under it. Offsetting into that set silently skipped a page's worth of rows
 * every time and then declared the request complete.
 */
final class SubmissionPrivacyTest extends CJW_TestCase
{
    private const string EMAIL = 'ouder@example.test';

    /**
     * Runs the eraser the way WordPress does, and returns how many passes it took.
     *
     * @param CJW_Wizard_Submission_Privacy $privacy Eraser under test.
     */
    private function eraseToCompletion(CJW_Wizard_Submission_Privacy $privacy): array
    {
        $page = 1;
        $removed = 0;

        do {
            $result = $privacy->erase(self::EMAIL, $page);
            $removed += $result['items_removed'];
            $page++;

            $this->assertLessThan(50, $page, 'The eraser must terminate, not spin the AJAX loop forever.');
        } while (! $result['done']);

        return [
            'removed' => $removed,
            'passes' => $page - 1,
            'last' => $result,
        ];
    }

    /**
     * @param int $count Submissions to seed.
     */
    private function withSubmissions(int $count): array
    {
        $repository = new FakeSubmissionRepository();
        $repository->seed($count, self::EMAIL);

        return [ $repository, new CJW_Wizard_Submission_Privacy($repository) ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function submissionCountProvider(): array
    {
        return [
            'none' => [ 0 ],
            'one' => [ 1 ],
            'one short of a page' => [ 24 ],
            'exactly one page' => [ 25 ],
            'one over a page' => [ 26 ],
            'exactly two pages' => [ 50 ],
            'many pages' => [ 60 ],
            'a lot' => [ 137 ],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('submissionCountProvider')]
    public function testEveryMatchingSubmissionIsErasedWhateverTheCount(int $count): void
    {
        [ $repository, $privacy ] = $this->withSubmissions($count);

        $result = $this->eraseToCompletion($privacy);

        $this->assertSame(
            0,
            $repository->remaining(),
            "With {$count} submissions, {$repository->remaining()} still held personal data after the request completed."
        );
        $this->assertSame($count, $result['removed']);
    }

    /**
     * The exact-multiple case is the one that looked healthiest and was worst:
     * page 2 of 50 rows sliced offset 25 of a 25-item array, returned nothing,
     * and reported done with half the data intact.
     */
    public function testTheExactMultipleCaseDoesNotStopHalfway(): void
    {
        [ $repository, $privacy ] = $this->withSubmissions(50);

        $first = $privacy->erase(self::EMAIL, 1);

        $this->assertSame(25, $first['items_removed']);
        $this->assertFalse($first['done'], 'Half the rows are still there; the request is not complete.');

        $second = $privacy->erase(self::EMAIL, 2);

        $this->assertSame(25, $second['items_removed'], 'The second pass must find the remaining rows, not an empty slice.');
        $this->assertTrue($second['done']);
        $this->assertSame(0, $repository->remaining());
    }

    public function testNothingToEraseIsImmediatelyDone(): void
    {
        [ , $privacy ] = $this->withSubmissions(0);

        $result = $privacy->erase(self::EMAIL, 1);

        $this->assertSame(0, $result['items_removed']);
        $this->assertTrue($result['done']);
        $this->assertFalse($result['items_retained']);
    }

    /**
     * A row that cannot be anonymised keeps matching the query. Without a
     * termination guard the loop would ask for page after page forever.
     */
    public function testARowThatCannotBeErasedTerminatesTheLoopAndIsReported(): void
    {
        $repository = new FakeSubmissionRepository();
        $repository->seed(3, self::EMAIL);
        $repository->makeStubborn(0);
        $repository->makeStubborn(1);
        $repository->makeStubborn(2);

        $privacy = new CJW_Wizard_Submission_Privacy($repository);
        $result = $privacy->erase(self::EMAIL, 1);

        $this->assertTrue($result['done'], 'A pass that removes nothing must end the request rather than loop.');
        $this->assertTrue($result['items_retained'], 'The administrator must be told data was retained, not that all is well.');
    }

    public function testRetainedIsFalseWhenEverythingWasActuallyRemoved(): void
    {
        [ , $privacy ] = $this->withSubmissions(5);

        $result = $privacy->erase(self::EMAIL, 1);

        $this->assertSame(5, $result['items_removed']);
        $this->assertFalse($result['items_retained']);
    }

    /**
     * The exporter only reads, so its set is stable and real paging is correct.
     */
    public function testExportPagesThroughWithoutSkippingOrRepeating(): void
    {
        [ , $privacy ] = $this->withSubmissions(60);

        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $result = $privacy->export(self::EMAIL, $page);

            foreach ($result['data'] as $item) {
                $seen[] = $item['item_id'];
            }
        }

        $this->assertCount(60, $seen);
        $this->assertCount(60, array_unique($seen), 'No submission may be exported twice.');
    }

    public function testExportReportsDoneOnlyOnTheLastPage(): void
    {
        [ , $privacy ] = $this->withSubmissions(60);

        $this->assertFalse($privacy->export(self::EMAIL, 1)['done']);
        $this->assertFalse($privacy->export(self::EMAIL, 2)['done']);
        $this->assertTrue($privacy->export(self::EMAIL, 3)['done']);
    }
}
