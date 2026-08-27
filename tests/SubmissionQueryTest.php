<?php

declare(strict_types=1);

/**
 * Guards the post statuses the repository looks in.
 *
 * `'post_status' => 'any'` reads like "everything" and is not: WP_Query expands
 * it to every status that is not `exclude_from_search`, and
 * register_post_status() defaults that flag to the status's `internal` flag --
 * which trash sets. So `'any'` quietly omits the trash.
 *
 * That mattered twice over. An organiser deleting a registration in wp-admin
 * moves it to the trash, where it keeps the child's name, address and any
 * medical note. Under `'any'` the retention sweep would never expire it and an
 * erasure request would never find it: deleting a registration was the one
 * action that made its data permanent.
 */
final class SubmissionQueryTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_get_posts_args'] = [];
        $GLOBALS['cjw_test_get_posts_return'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['cjw_test_get_posts_args'] = [];
        $GLOBALS['cjw_test_get_posts_return'] = [];

        parent::tearDown();
    }

    private function lastQuery(): array
    {
        $calls = $GLOBALS['cjw_test_get_posts_args'] ?? [];

        $this->assertNotSame([], $calls, 'Expected the repository to run a query.');

        return (array) end($calls);
    }

    /**
     * @param array $query Captured get_posts() arguments.
     */
    private function assertLooksEverywhere(array $query, string $method): void
    {
        $this->assertArrayHasKey('post_status', $query);

        $status = $query['post_status'];

        $this->assertIsArray(
            $status,
            "{$method}() must name its statuses explicitly; 'any' silently excludes the trash."
        );
        $this->assertContains(
            'trash',
            $status,
            "{$method}() must look in the trash, or deleting a registration would make its personal data permanent."
        );

        foreach ([ 'publish', 'draft', 'pending', 'private' ] as $expected) {
            $this->assertContains($expected, $status, "{$method}() must also cover the '{$expected}' status.");
        }
    }

    public function testLookupByEmailIncludesTrashedSubmissions(): void
    {
        (new CJW_Wizard_Submission_Repository())->get_by_email('ouder@example.test');

        $this->assertLooksEverywhere($this->lastQuery(), 'get_by_email');
    }

    public function testTheRetentionSweepIncludesTrashedSubmissions(): void
    {
        (new CJW_Wizard_Submission_Repository())->get_expired(new DateTimeImmutable('2020-01-01'), 100);

        $this->assertLooksEverywhere($this->lastQuery(), 'get_expired');
    }

    public function testLookupByEmailRefusesAnAddressThatIsNotOne(): void
    {
        $repository = new CJW_Wizard_Submission_Repository();

        $this->assertSame([], $repository->get_by_email('niet-een-adres'));
        $this->assertSame([], $repository->get_by_email(''));
        $this->assertSame(
            [],
            $GLOBALS['cjw_test_get_posts_args'],
            'A malformed address must not reach the database at all.'
        );
    }

    public function testTheRetentionSweepOnlyLooksForRowsThatStillHoldData(): void
    {
        (new CJW_Wizard_Submission_Repository())->get_expired(new DateTimeImmutable('2020-01-01'), 100);

        $query = $this->lastQuery();

        $this->assertArrayHasKey('meta_query', $query);
        $this->assertSame(
            CJW_Wizard_Submission_Repository::META_ANONYMISED,
            $query['meta_query'][0]['key'] ?? null
        );
        $this->assertSame(
            'NOT EXISTS',
            $query['meta_query'][0]['compare'] ?? null,
            'Already-anonymised rows must be skipped, or every sweep rewrites the whole archive.'
        );
    }

    public function testTheRetentionSweepIsBatched(): void
    {
        (new CJW_Wizard_Submission_Repository())->get_expired(new DateTimeImmutable('2020-01-01'), 25);

        $this->assertSame(
            25,
            $this->lastQuery()['numberposts'] ?? null,
            'An unbounded sweep would try to rewrite years of history in one cron request.'
        );
    }
}
