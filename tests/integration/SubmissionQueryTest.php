<?php

declare(strict_types=1);

/**
 * Which registrations a query actually reaches.
 *
 * `post_status => 'any'` sounds like "everything" and is not: WP_Query expands
 * it to every status that is not exclude_from_search, and register_post_status()
 * defaults that flag to the status's `internal` flag -- which trash sets. So
 * `any` silently omits the trash, and an organiser deleting a registration in
 * wp-admin would move a child's name and medical notes somewhere the retention
 * sweep and an erasure request could both never reach.
 *
 * That expansion happens inside WP_Query. A stub cannot reproduce it.
 */
final class SubmissionQueryTest extends CJW_IntegrationTestCase
{
    private function makeRegistration(string $status): int
    {
        return self::factory()->post->create([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => $status,
        ]);
    }

    public function testAnyFindsNoRegistrationAtAll(): void
    {
        $live = $this->makeRegistration(CJW_Wizard_Submission_Status::NEW);
        $trashed = $this->makeRegistration(CJW_Wizard_Submission_Status::NEW);
        wp_trash_post($trashed);

        $found = array_map('intval', get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]));

        // Worse than "any omits the trash", which is how this was understood
        // when ALL_STATUSES was written: every camp status sets
        // exclude_from_search, so `any` omits all of them and matches nothing.
        // Any query that reaches for it silently operates on an empty set.
        $this->assertNotContains($live, $found);
        $this->assertNotContains($trashed, $found);
    }

    public function testTheStatusesSpelledOutFindEveryRegistration(): void
    {
        $live = $this->makeRegistration(CJW_Wizard_Submission_Status::NEW);
        $cancelled = $this->makeRegistration(CJW_Wizard_Submission_Status::CANCELLED);
        $trashed = $this->makeRegistration(CJW_Wizard_Submission_Status::PAID);
        wp_trash_post($trashed);

        // What the repository does instead, and why: an erasure request and the
        // retention sweep both have to reach a registration wherever it sits.
        $found = array_map('intval', get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => (new ReflectionClass(CJW_Wizard_Submission_Repository::class))
                ->getConstant('ALL_STATUSES'),
            'numberposts' => -1,
            'fields' => 'ids',
        ]));

        $this->assertContains($live, $found);
        $this->assertContains($cancelled, $found);
        $this->assertContains($trashed, $found);
    }

    public function testTheRetentionSweepReachesATrashedRegistration(): void
    {
        $trashed = $this->makeRegistration(CJW_Wizard_Submission_Status::NEW);
        wp_trash_post($trashed);

        $expired = (new CJW_Wizard_Submission_Repository())
            ->get_expired(new DateTimeImmutable('+1 year'));

        $this->assertContains($trashed, array_map(static fn($p): int => (int) $p->ID, $expired));
    }

    public function testTheSweepIgnoresRegistrationsItHasAlreadyScrubbed(): void
    {
        $repository = new CJW_Wizard_Submission_Repository();
        $done = $this->makeRegistration(CJW_Wizard_Submission_Status::NEW);
        $repository->anonymise($done);

        $expired = $repository->get_expired(new DateTimeImmutable('+1 year'));

        $this->assertNotContains($done, array_map(static fn($p): int => (int) $p->ID, $expired));
    }

    public function testTheAlleTabShowsEverythingExceptCancelled(): void
    {
        $ids = [];

        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            $ids[ $status ] = $this->makeRegistration($status);
        }

        $found = array_map('intval', get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::inAllList(),
            'numberposts' => -1,
            'fields' => 'ids',
        ]));

        $this->assertContains($ids[ CJW_Wizard_Submission_Status::NEW ], $found);
        $this->assertContains($ids[ CJW_Wizard_Submission_Status::CONFIRMED ], $found);
        $this->assertContains($ids[ CJW_Wizard_Submission_Status::PAID ], $found);
        $this->assertNotContains($ids[ CJW_Wizard_Submission_Status::CANCELLED ], $found);
    }

    public function testTheStatusesAreAdminOnlyAndNotPublic(): void
    {
        // The reason the "Alle" tab came up empty: WP_Query falls back to
        // statuses registered `public => true` when asked for none, and none
        // of ours are.
        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            $object = get_post_status_object($status);

            $this->assertFalse($object->public, $status);
            $this->assertTrue($object->exclude_from_search, $status);
            $this->assertTrue($object->show_in_admin_status_list, $status);
        }

        $this->assertFalse(get_post_status_object(CJW_Wizard_Submission_Status::CANCELLED)->show_in_admin_all_list);
    }
}
