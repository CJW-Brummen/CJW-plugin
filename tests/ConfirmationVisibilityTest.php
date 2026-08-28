<?php

declare(strict_types=1);

/**
 * The confirmation result was being recorded and never shown. A parent who says
 * "ik heb niks gekregen" is answerable only if an organiser can see what
 * happened, so these cover the reading side rather than the sending side.
 */
final class ConfirmationVisibilityTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_post_meta'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_post_meta']);

        parent::tearDown();
    }

    public function testAFormThatDoesNotConfirmSaysNothingAtAll(): void
    {
        $this->assertSame('', CJW_Wizard_Submission_Confirmation::summary(226));
        $this->assertFalse(CJW_Wizard_Submission_Confirmation::failed(226));
    }

    public function testASentConfirmationCarriesTheMomentItWentOut(): void
    {
        update_post_meta(226, CJW_Wizard_Submission_Confirmation::META_RESULT, CJW_Wizard_Submission_Confirmation::SENT);
        update_post_meta(226, CJW_Wizard_Submission_Confirmation::META_RESULT_TIME, mktime(14, 3, 0, 7, 14, 2026));

        $summary = CJW_Wizard_Submission_Confirmation::summary(226);

        $this->assertStringContainsString('Verstuurd', $summary);
        $this->assertStringContainsString('14:03', $summary);
        $this->assertStringContainsString('14 July 2026', $summary);
    }

    /**
     * Rows written before the timestamp existed still have to render. The mail
     * went out in the same request that created the submission, so its arrival
     * time is the honest answer rather than an invented one.
     */
    public function testAConfirmationWithoutATimeFallsBackToTheArrivalTime(): void
    {
        update_post_meta(226, CJW_Wizard_Submission_Confirmation::META_RESULT, CJW_Wizard_Submission_Confirmation::SENT);

        $this->assertStringContainsString('Verstuurd op', CJW_Wizard_Submission_Confirmation::summary(226));
    }

    public function testAFailureSaysSoAndIsFlaggable(): void
    {
        update_post_meta(226, CJW_Wizard_Submission_Confirmation::META_RESULT, CJW_Wizard_Submission_Confirmation::FAILED);

        $this->assertSame('Versturen mislukt', CJW_Wizard_Submission_Confirmation::summary(226));
        $this->assertTrue(CJW_Wizard_Submission_Confirmation::failed(226));
    }

    public function testAMissingAddressIsReportedAsAMisconfiguration(): void
    {
        update_post_meta(226, CJW_Wizard_Submission_Confirmation::META_RESULT, CJW_Wizard_Submission_Confirmation::NO_ADDRESS);

        $summary = CJW_Wizard_Submission_Confirmation::summary(226);

        $this->assertStringContainsString('Niet verstuurd', $summary);
        $this->assertStringContainsString('e-mailadres', $summary);

        // Not a failure: the form never asked for an address, so nobody was
        // promised anything. Flagging it in the list would be noise on every row.
        $this->assertFalse(CJW_Wizard_Submission_Confirmation::failed(226));
    }

    public function testAnUnknownStoredValueRendersNothingRatherThanItself(): void
    {
        update_post_meta(226, CJW_Wizard_Submission_Confirmation::META_RESULT, 'iets anders');

        $this->assertSame('', CJW_Wizard_Submission_Confirmation::summary(226));
    }
}
