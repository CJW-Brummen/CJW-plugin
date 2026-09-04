<?php

declare(strict_types=1);

/**
 * The ledger as it behaves on a real registration.
 *
 * The amount owed used to be recomputed from the payload every time it was
 * shown, and the retention sweep erases that payload -- so a camp year's
 * figures turned into zero once its registrations were anonymised. That is a
 * WordPress question: it needs a stored registration, the sweep's own
 * anonymise(), and the meta that survives it.
 */
final class PaymentsLedgerTest extends CJW_IntegrationTestCase
{
    private CJW_Wizard_Submission_Repository $repository;

    private int $wizardId = 0;

    public function set_up(): void
    {
        parent::set_up();

        CJW_Roles::install();
        $this->actAsAdministrator();

        $this->repository = new CJW_Wizard_Submission_Repository();
        $this->wizardId = self::factory()->post->create([ 'post_type' => CJW_Wizard_Post_Type::SLUG ]);
    }

    public function tear_down(): void
    {
        $_POST = [];

        parent::tear_down();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'steps' => [
                [
                    'title' => 'Kinderen',
                    'fields' => [
                        [
                            'key' => 'kinderen',
                            'type' => 'repeater',
                            'label' => 'Kinderen',
                            'fields' => [
                                [ 'key' => 'voornaam', 'type' => 'text', 'label' => 'Voornaam', 'role' => 'child_name' ],
                                [ 'key' => 'kamp', 'type' => 'radio', 'label' => 'Kamp', 'role' => 'camp_type' ],
                            ],
                        ],
                        [ 'key' => 'donatie', 'type' => 'text', 'label' => 'Donatie' ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, string>> $children
     * @param string                            $donation
     */
    private function register(array $children, string $donation = ''): int
    {
        return $this->repository->save($this->wizardId, $this->config(), [
            'kinderen' => $children,
            'donatie' => $donation,
        ]);
    }

    /**
     * Posts the payments box the way the edit screen would.
     *
     * @param int                  $submissionId Registration post id.
     * @param array<string, mixed> $fields       Box fields.
     */
    private function submitBox(int $submissionId, array $fields): void
    {
        $_POST = array_merge([
            'cjw_wizard_payments_nonce' => wp_create_nonce('cjw_wizard_payments'),
        ], $fields);

        $post = get_post($submissionId);

        new CJW_Wizard_Payments_Admin()->save($submissionId, $post);
    }

    public function testTheAmountIsFrozenWhenTheRegistrationIsTaken(): void
    {
        $id = $this->register([
            [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ],
            [ 'voornaam' => 'Sem', 'kamp' => 'Weekkamp (€ 120)' ],
        ], '15');

        $amount = CJW_Wizard_Payments::amount($id);

        $this->assertSame(24000, $amount['camp']);
        $this->assertSame(1500, $amount['donation']);
        $this->assertSame(25500, $amount['due']);
    }

    /**
     * The bug this card exists for: the books used to rewrite themselves a
     * year after the fact.
     */
    public function testTheAmountSurvivesTheRetentionSweep(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        CJW_Wizard_Payments::add($id, 12000, CJW_Wizard_Payments::METHOD_TRANSFER, '2027-05-01', 'van oma');

        $this->repository->anonymise($id);
        wp_cache_flush();

        $this->assertTrue($this->repository->is_anonymised($id));
        $this->assertSame([], $this->repository->get_payload($id), 'the answers are gone');
        $this->assertSame(12000, CJW_Wizard_Payments::amount($id)['due'], 'the amount owed is not');
        $this->assertSame(12000, CJW_Wizard_Payments::paid($id));
        $this->assertSame('', CJW_Wizard_Payments::payments($id)[0]['note'], 'but what somebody typed is');
    }

    public function testRecordingAPaymentThroughTheBoxSettlesTheRegistration(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        wp_update_post([ 'ID' => $id, 'post_status' => CJW_Wizard_Submission_Status::CONFIRMED ]);

        $this->submitBox($id, [
            'cjw_payment_amount' => '120,00',
            'cjw_payment_method' => CJW_Wizard_Payments::METHOD_TRANSFER,
            'cjw_payment_date' => '2027-05-01',
            'cjw_payment_note' => 'overboeking mei',
        ]);

        wp_cache_flush();

        $this->assertSame(12000, CJW_Wizard_Payments::paid($id));
        $this->assertSame('overboeking mei', CJW_Wizard_Payments::payments($id)[0]['note']);
        $this->assertSame(CJW_Wizard_Submission_Status::PAID, get_post_status($id), 'the status follows the balance');
    }

    public function testAPartPaymentLeavesItOpen(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        wp_update_post([ 'ID' => $id, 'post_status' => CJW_Wizard_Submission_Status::CONFIRMED ]);

        $this->submitBox($id, [
            'cjw_payment_amount' => '60',
            'cjw_payment_method' => CJW_Wizard_Payments::METHOD_TRANSFER,
        ]);

        wp_cache_flush();

        $this->assertSame(6000, CJW_Wizard_Payments::outstanding($id));
        $this->assertSame(CJW_Wizard_Submission_Status::CONFIRMED, get_post_status($id));
    }

    public function testTakingAPaymentBackOutTakesTheStatusWithIt(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        CJW_Wizard_Payments::add($id, 12000, CJW_Wizard_Payments::METHOD_TRANSFER);
        wp_update_post([ 'ID' => $id, 'post_status' => CJW_Wizard_Submission_Status::PAID ]);

        $rows = CJW_Wizard_Payments::payments($id);
        $this->submitBox($id, [ 'cjw_payment_delete' => [ $rows[0]['id'] ] ]);

        wp_cache_flush();

        $this->assertSame(0, CJW_Wizard_Payments::paid($id));
        $this->assertSame(CJW_Wizard_Submission_Status::CONFIRMED, get_post_status($id));
    }

    /**
     * The radio buttons above the box are somebody's decision. Arithmetic
     * overruling them on every save would make them look broken.
     */
    public function testAStatusChosenByHandIsNotOverruled(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        wp_update_post([ 'ID' => $id, 'post_status' => CJW_Wizard_Submission_Status::PAID ]);

        // A save with nothing typed into the box: no payment, no correction.
        $this->submitBox($id, [ 'cjw_payment_amount' => '' ]);

        wp_cache_flush();

        $this->assertSame(CJW_Wizard_Submission_Status::PAID, get_post_status($id));
    }

    public function testACorrectedAmountIsRecordedAndSettlesAtTheNewFigure(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        wp_update_post([ 'ID' => $id, 'post_status' => CJW_Wizard_Submission_Status::CONFIRMED ]);

        $this->submitBox($id, [
            'cjw_payment_due' => '50',
            'cjw_payment_amount' => '50',
            'cjw_payment_method' => CJW_Wizard_Payments::METHOD_CASH,
        ]);

        wp_cache_flush();

        $amount = CJW_Wizard_Payments::amount($id);

        $this->assertSame(5000, $amount['due']);
        $this->assertSame(12000, $amount['snapshot']);
        $this->assertSame(CJW_Wizard_Submission_Status::PAID, get_post_status($id));
    }

    public function testSomebodyWithoutTheCampCapabilityRecordsNothing(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);

        $this->actAsSubscriber();

        $this->submitBox($id, [
            'cjw_payment_amount' => '120',
            'cjw_payment_method' => CJW_Wizard_Payments::METHOD_CASH,
        ]);

        $this->assertSame(0, CJW_Wizard_Payments::paid($id));
    }

    public function testAPostWithoutTheNonceRecordsNothing(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);

        $_POST = [
            'cjw_payment_amount' => '120',
            'cjw_payment_method' => CJW_Wizard_Payments::METHOD_CASH,
        ];

        new CJW_Wizard_Payments_Admin()->save($id, get_post($id));

        $this->assertSame(0, CJW_Wizard_Payments::paid($id));
    }

    public function testTheYearAddsUpAcrossRegistrations(): void
    {
        $first = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        $second = $this->register([ [ 'voornaam' => 'Sem', 'kamp' => 'Weekkamp (€ 120)' ] ], '10');

        CJW_Wizard_Payments::add($first, 12000, CJW_Wizard_Payments::METHOD_TRANSFER);
        CJW_Wizard_Payments::add($second, 5000, CJW_Wizard_Payments::METHOD_TRANSFER);

        wp_cache_flush();
        $totals = CJW_Wizard_Payments::totalsForYear(CJW_Wizard_Submission_Repository::currentCampYear());

        $this->assertSame(2, $totals['registrations']);
        $this->assertSame(25000, $totals['due']);
        $this->assertSame(17000, $totals['paid']);
        $this->assertSame(8000, $totals['outstanding']);
        $this->assertSame(1, $totals['unpaid']);
    }

    /**
     * The whole reason for freezing the figure: last year's books still add up
     * after the sweep has been through them.
     */
    public function testTheYearStillAddsUpOnceEverythingIsAnonymised(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        CJW_Wizard_Payments::add($id, 12000, CJW_Wizard_Payments::METHOD_TRANSFER);

        $this->repository->anonymise($id);
        wp_cache_flush();

        $totals = CJW_Wizard_Payments::totalsForYear(CJW_Wizard_Submission_Repository::currentCampYear());

        $this->assertSame(12000, $totals['due']);
        $this->assertSame(12000, $totals['paid']);
    }

    public function testACancelledRegistrationIsNotMoneyAnybodyIsWaitingFor(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        wp_update_post([ 'ID' => $id, 'post_status' => CJW_Wizard_Submission_Status::CANCELLED ]);
        wp_cache_flush();

        $this->assertSame(0, CJW_Wizard_Payments::totalsForYear(CJW_Wizard_Submission_Repository::currentCampYear())['due']);
    }

    public function testTheListColumnSaysWhatIsOpen(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);

        $this->assertStringContainsString(CJW_Wizard_Payments::formatAmount(12000), CJW_Wizard_Payments_Admin::cell($id));
        $this->assertStringContainsString('open', CJW_Wizard_Payments_Admin::cell($id));

        CJW_Wizard_Payments::add($id, 12000, CJW_Wizard_Payments::METHOD_TRANSFER);

        $this->assertStringContainsString('voldaan', CJW_Wizard_Payments_Admin::cell($id));
    }

    public function testAFormWithNoPricesShowsNoBalance(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp' ] ]);

        $this->assertStringContainsString('—', CJW_Wizard_Payments_Admin::cell($id));
    }

    /**
     * The regression guard for the screen: the figure has to come from the
     * frozen amount, which is in cents, rather than from a fresh sum over the
     * payload. The two are told apart by the cents the old path never printed.
     */
    public function testTheDetailScreenReadsTheFrozenAmount(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        CJW_Wizard_Payments::add($id, 5000, CJW_Wizard_Payments::METHOD_TRANSFER);

        ob_start();

        try {
            new CJW_Wizard_Submission_Detail($this->repository)->render($id);
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertStringContainsString(CJW_Wizard_Payments::formatAmount(12000), $html);
        $this->assertStringContainsString(CJW_Wizard_Payments::formatAmount(7000), $html, 'and what is still open');
    }

    /**
     * An anonymised registration says one thing and nothing else, which is
     * deliberate -- but its money is still in the year's totals, tested above.
     */
    public function testTheDetailScreenStaysSilentAboutAnAnonymisedRegistration(): void
    {
        $id = $this->register([ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ]);
        $this->repository->anonymise($id);
        wp_cache_flush();

        ob_start();

        try {
            new CJW_Wizard_Submission_Detail($this->repository)->render($id);
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertStringContainsString('bewaartermijn', $html);
        $this->assertStringNotContainsString('Fleur', $html);
    }
}
