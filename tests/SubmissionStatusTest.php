<?php

declare(strict_types=1);

/**
 * A registration's status is what turns a list of arrivals into a workable
 * process, and it is what a payment webhook will later write into. Both make it
 * worth pinning what the values are and which of them mean "a place is taken".
 */
final class SubmissionStatusTest extends CJW_TestCase
{
    public function testASubmissionArrivesAsNew(): void
    {
        $this->assertSame(
            CJW_Wizard_Submission_Status::NEW,
            CJW_Wizard_Submission_Status::DEFAULT,
            'A registration nobody has looked at yet is new, not confirmed.'
        );
    }

    public function testEveryStatusIsNamespaced(): void
    {
        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            $this->assertStringStartsWith(
                'cjw_',
                $status,
                'An unprefixed status could collide with another plugin registering the same name.'
            );
        }
    }

    public function testTheFourStatusesAreTheOnesTheProcessNeeds(): void
    {
        $this->assertSame(
            [ 'cjw_new', 'cjw_confirmed', 'cjw_paid', 'cjw_cancelled' ],
            array_keys(CJW_Wizard_Submission_Status::all())
        );
    }

    public function testOnlyConfirmedAndPaidTakeUpAPlace(): void
    {
        $counted = CJW_Wizard_Submission_Status::counted();

        $this->assertContains(CJW_Wizard_Submission_Status::CONFIRMED, $counted);
        $this->assertContains(CJW_Wizard_Submission_Status::PAID, $counted);
        $this->assertNotContains(
            CJW_Wizard_Submission_Status::CANCELLED,
            $counted,
            'A cancelled registration must free its place, or the camp fills up with people who are not coming.'
        );
        $this->assertNotContains(
            CJW_Wizard_Submission_Status::NEW,
            $counted,
            'An unreviewed registration is not yet a place taken.'
        );
    }

    public function testAnUnknownStatusIsRejected(): void
    {
        $this->assertFalse(CJW_Wizard_Submission_Status::isKnown('publish'));
        $this->assertFalse(CJW_Wizard_Submission_Status::isKnown('trash'));
        $this->assertFalse(CJW_Wizard_Submission_Status::isKnown(''));
        $this->assertTrue(CJW_Wizard_Submission_Status::isKnown(CJW_Wizard_Submission_Status::PAID));
    }

    public function testLabelsAreHumanReadableAndFallBackSafely(): void
    {
        $this->assertSame('Betaald', CJW_Wizard_Submission_Status::label(CJW_Wizard_Submission_Status::PAID));
        $this->assertSame('iets_anders', CJW_Wizard_Submission_Status::label('iets_anders'));
    }

    /**
     * Phase 3's queries name every status explicitly so nothing is invisible to
     * the retention sweep or an erasure request. Adding a status without adding
     * it there would recreate exactly the trash bug.
     */
    public function testEveryStatusIsVisibleToTheRetentionAndErasureQueries(): void
    {
        $GLOBALS['cjw_test_get_posts_args'] = [];
        (new CJW_Wizard_Submission_Repository())->get_expired(new DateTimeImmutable('2020-01-01'), 10);

        $queried = (array) ($GLOBALS['cjw_test_get_posts_args'][0]['post_status'] ?? []);

        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            $this->assertContains(
                $status,
                $queried,
                "Status '{$status}' is not covered, so those submissions would never expire."
            );
        }

        $GLOBALS['cjw_test_get_posts_args'] = [];
    }

    public function testThePublishBoxIsReplacedByAStatusBox(): void
    {
        $GLOBALS['cjw_test_meta_boxes'] = [];
        $GLOBALS['cjw_test_meta_boxes_removed'] = [];

        (new CJW_Wizard_Submission_Status())->register_box(CJW_Wizard_Submission_Repository::POST_TYPE, null);

        // "Publiceren" speaks about drafts, revisions and visibility -- none of
        // which a registration has.
        $this->assertContains('submitdiv', $GLOBALS['cjw_test_meta_boxes_removed']);
        $this->assertSame('Status', $GLOBALS['cjw_test_meta_boxes']['cjw_wizard_entry_status']);
    }

    public function testEveryOtherPostTypeKeepsItsPublishBox(): void
    {
        $GLOBALS['cjw_test_meta_boxes'] = [];
        $GLOBALS['cjw_test_meta_boxes_removed'] = [];

        (new CJW_Wizard_Submission_Status())->register_box('page', null);

        $this->assertSame([], $GLOBALS['cjw_test_meta_boxes_removed']);
        $this->assertSame([], $GLOBALS['cjw_test_meta_boxes']);
    }

    public function testTheStatusBoxCarriesTheOnlyWayLeftToSave(): void
    {
        // Dropping the publish box drops the Update button with it, so the
        // replacement has to bring one -- otherwise the screen reads back a
        // registration and can never record a decision about it.
        $html = $this->renderBox(CJW_Wizard_Submission_Status::NEW);

        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('name="save"', $html);
    }

    public function testTheStatusBoxOffersEveryStatusAndPreselectsTheCurrentOne(): void
    {
        $html = $this->renderBox(CJW_Wizard_Submission_Status::PAID);

        $this->assertSame(4, substr_count($html, 'type="radio"'));

        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            $this->assertStringContainsString('value="' . $status . '"', $html);
        }

        $this->assertMatchesRegularExpression(
            '/value="' . CJW_Wizard_Submission_Status::PAID . '"\s+checked/',
            $html
        );
    }

    public function testTheStatusBoxIsNonceProtected(): void
    {
        $this->assertStringContainsString('cjw_submission_status_nonce', $this->renderBox(CJW_Wizard_Submission_Status::NEW));
    }

    public function testTheStatusBoxHandsWordPressBackTheStatusItAlreadyHas(): void
    {
        // Core saves before this class does. Without a post_status of its own
        // it would pick one, and the registration would land somewhere nobody
        // asked for.
        $this->assertStringContainsString(
            '<input type="hidden" name="post_status" value="' . CJW_Wizard_Submission_Status::CONFIRMED . '">',
            $this->renderBox(CJW_Wizard_Submission_Status::CONFIRMED)
        );
    }

    public function testAnUnknownStatusFallsBackToNew(): void
    {
        $html = $this->renderBox('draft');

        $this->assertMatchesRegularExpression(
            '/value="' . CJW_Wizard_Submission_Status::NEW . '"\s+checked/',
            $html
        );
    }

    private function renderBox(string $status): string
    {
        $post = new WP_Post(226, CJW_Wizard_Submission_Repository::POST_TYPE);
        $post->post_status = $status;

        ob_start();
        (new CJW_Wizard_Submission_Status())->render_box($post);

        return (string) ob_get_clean();
    }
}
