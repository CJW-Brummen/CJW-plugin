<?php

declare(strict_types=1);

/**
 * The webhook, which is the one endpoint on this site that anybody on the
 * internet may call and that moves money in the books.
 *
 * Mollie signs nothing. It posts an id and nothing else, precisely so that the
 * request carries no claim worth forging -- and that only holds if this end
 * refuses to believe anything it was not told by Mollie over an authenticated
 * connection. Every test here is a way somebody might try to get a
 * registration marked paid without paying.
 */
final class IdealWebhookTest extends CJW_IntegrationTestCase
{
    private const string KEY = 'test_abcdefghij1234567890ABCDE';

    private CJW_Wizard_Submission_Repository $repository;

    private int $wizardId = 0;

    private int $submissionId = 0;

    /**
     * What the faked Mollie answers with, per payment id.
     *
     * @var array<string, array{code: int, body: array<string, mixed>}|WP_Error>
     */
    private array $answers = [];

    /**
     * The requests that were made, so the credential can be checked.
     *
     * @var array<int, array{url: string, args: array<string, mixed>}>
     */
    private array $requests = [];

    public function set_up(): void
    {
        parent::set_up();

        CJW_Roles::install();
        $this->repository = new CJW_Wizard_Submission_Repository();

        // https, or the plugin refuses to take payments at all.
        add_filter('home_url', [ $this, 'httpsHome' ], 10, 1);
        CJW_Mollie::storeKey(self::KEY);

        $this->wizardId = self::factory()->post->create([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => 'publish',
        ]);

        $this->submissionId = $this->repository->save($this->wizardId, $this->config(), [
            'kinderen' => [ [ 'voornaam' => 'Fleur', 'kamp' => 'Weekkamp (€ 120)' ] ],
            'email' => 'ouder@example.test',
        ]);

        add_filter('pre_http_request', [ $this, 'fakeMollie' ], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [ $this, 'fakeMollie' ], 10);
        remove_filter('home_url', [ $this, 'httpsHome' ], 10);
        delete_option(CJW_Mollie::OPTION_KEY);
        delete_option(CJW_Mail_Safe_Mode::OPTION_PRODUCTION_HOST);

        parent::tear_down();
    }

    /**
     * @param string $url The address WordPress built.
     */
    public function httpsHome(string $url): string
    {
        return str_replace('http://', 'https://', $url);
    }

    /**
     * Stands in for Mollie.
     *
     * @param mixed  $pre  Whatever another filter decided.
     * @param array  $args Request arguments.
     * @param string $url  Request URL.
     * @param array<string, mixed> $args
     *
     * @return mixed
     */
    public function fakeMollie(mixed $pre, array $args, string $url): mixed
    {
        if (! str_starts_with($url, 'https://api.mollie.com/')) {
            return $pre;
        }

        $this->requests[] = [
            'url' => $url,
            'args' => $args,
        ];

        foreach ($this->answers as $id => $answer) {
            if (str_ends_with($url, '/payments/' . $id)) {
                if ($answer instanceof WP_Error) {
                    return $answer;
                }

                return [
                    'headers' => [],
                    'response' => [
                        'code' => $answer['code'],
                        'message' => '',
                    ],
                    'body' => (string) wp_json_encode($answer['body']),
                    'cookies' => [],
                    'filename' => null,
                ];
            }
        }

        return [
            'headers' => [],
            'response' => [
                'code' => 404,
                'message' => 'Not Found',
            ],
            'body' => (string) wp_json_encode([ 'detail' => 'No payment exists with token' ]),
            'cookies' => [],
            'filename' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'settings' => [
                'require_ideal_payment' => true,
                'send_parent_confirmation' => true,
            ],
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
                        [ 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'role' => 'parent_email' ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A payment as Mollie would describe it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payment(array $overrides = []): array
    {
        return array_replace_recursive([
            'resource' => 'payment',
            'id' => 'tr_aaaaaaaaaaaaaaaaaaaaaa',
            'mode' => 'test',
            'status' => 'paid',
            'paidAt' => '2027-05-01T10:00:00+00:00',
            'amount' => [
                'currency' => 'EUR',
                'value' => '120.00',
            ],
            'metadata' => [ 'submission' => $this->submissionId ],
        ], $overrides);
    }

    /**
     * Registers what Mollie will answer, and records the attempt the way
     * start() would have.
     *
     * @param array<string, mixed> $payment
     */
    private function given(array $payment, int $code = 200, bool $known = true, string $mode = 'test'): string
    {
        $id = (string) $payment['id'];

        $this->answers[ $id ] = [
            'code' => $code,
            'body' => $payment,
        ];

        if ($known) {
            $this->registerAttempt($id, $mode);
        }

        return $id;
    }

    /**
     * Records a payment this site started, the way rememberAttempt() does.
     *
     * @param string $id   Mollie payment id.
     * @param string $mode The mode it was created in.
     */
    private function registerAttempt(string $id, string $mode = 'test'): void
    {
        $attempts = get_post_meta($this->submissionId, CJW_Wizard_Ideal::META_ATTEMPTS, true);
        $attempts = is_array($attempts) ? $attempts : [];
        $attempts[ $id ] = [
            'cents' => 12000,
            'mode' => $mode,
            'at' => time(),
            'status' => '',
            'url' => 'https://www.mollie.com/checkout/' . $id,
        ];
        update_post_meta($this->submissionId, CJW_Wizard_Ideal::META_ATTEMPTS, $attempts);

        // The queryable row rememberAttempt() writes beside the array: it is
        // what lets the webhook trace an id to a registration without asking
        // Mollie first.
        add_post_meta($this->submissionId, CJW_Wizard_Ideal::META_PAYMENT, $id);
    }

    public function testAPaidPaymentIsRecordedOnceAndSettlesTheRegistration(): void
    {
        $id = $this->given($this->payment());

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertTrue($result['handled']);
        $this->assertFalse($result['retry']);
        $this->assertSame(12000, CJW_Wizard_Payments::paid($this->submissionId));
        $this->assertSame($id, CJW_Wizard_Payments::payments($this->submissionId)[0]['ref']);
        $this->assertSame(CJW_Wizard_Payments::METHOD_IDEAL, CJW_Wizard_Payments::payments($this->submissionId)[0]['method']);
        $this->assertSame('2027-05-01', CJW_Wizard_Payments::payments($this->submissionId)[0]['date']);

        wp_cache_flush();
        $this->assertSame(CJW_Wizard_Submission_Status::PAID, get_post_status($this->submissionId));
    }

    /**
     * Mollie retries for a day when anything goes wrong at this end, so the
     * same payment arrives more than once as a matter of course.
     */
    public function testTheSameWebhookTwiceRecordsOnePayment(): void
    {
        $id = $this->given($this->payment());

        CJW_Wizard_Ideal::process($id);
        $second = CJW_Wizard_Ideal::process($id);

        $this->assertFalse($second['handled'], 'nothing new to do');
        $this->assertCount(1, CJW_Wizard_Payments::payments($this->submissionId));
        $this->assertSame(12000, CJW_Wizard_Payments::paid($this->submissionId));
    }

    /**
     * The attack the design exists to stop: posting an id that was never ours.
     */
    public function testAPaymentThisSiteNeverStartedIsIgnored(): void
    {
        $id = $this->given($this->payment([ 'id' => 'tr_bbbbbbbbbbbbbbbbbbbbbb' ]), 200, false);

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertFalse($result['handled']);
        $this->assertSame('foreign', $result['reason']);
        $this->assertSame(0, CJW_Wizard_Payments::paid($this->submissionId));
        $this->assertSame([], $this->requests, 'and no API call is spent on a stranger\'s id');
    }

    public function testAPaymentPointingAtSomebodyElsesRegistrationIsIgnored(): void
    {
        $other = $this->repository->save($this->wizardId, $this->config(), []);
        $id = $this->given($this->payment([ 'metadata' => [ 'submission' => $other ] ]));

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertFalse($result['handled']);
        $this->assertSame(0, CJW_Wizard_Payments::paid($other));
        $this->assertSame(0, CJW_Wizard_Payments::paid($this->submissionId));
    }

    public function testAPaymentWithNoMetadataIsIgnored(): void
    {
        $id = $this->given($this->payment([ 'metadata' => null ]));

        $this->assertFalse(CJW_Wizard_Ideal::process($id)['handled']);
        $this->assertSame(0, CJW_Wizard_Payments::paid($this->submissionId));
    }

    /**
     * The reason the mode is taken from the attempt rather than from the
     * switch: somebody rehearsing next year's form must not silently drop a
     * real payment that was still on its way.
     */
    public function testALivePaymentStillLandsAfterTheSiteIsSwitchedToTest(): void
    {
        CJW_Mollie::storeKey('live_zyxwvutsrq0987654321ZYXWV');
        CJW_Mollie::setMode(CJW_Mollie::MODE_LIVE);

        $id = $this->given($this->payment([ 'mode' => 'live' ]), 200, true, 'live');

        // The rehearsal begins while the payment is in flight.
        CJW_Mollie::setMode(CJW_Mollie::MODE_TEST);
        $this->assertSame(CJW_Mollie::MODE_TEST, CJW_Mollie::mode());

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertTrue($result['handled'], 'the money still arrives');
        $this->assertSame(12000, CJW_Wizard_Payments::paid($this->submissionId));
        $this->assertSame(
            'Bearer live_zyxwvutsrq0987654321ZYXWV',
            $this->requests[0]['args']['headers']['Authorization'],
            'and it was verified with the key that created it'
        );
    }

    /**
     * A test payment must never be written into live books, nor the reverse.
     */
    public function testAPaymentFromTheOtherModeIsIgnored(): void
    {
        $id = $this->given($this->payment([ 'mode' => 'live' ]));

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertSame('wrong-mode', $result['reason']);
        $this->assertSame(0, CJW_Wizard_Payments::paid($this->submissionId));
    }

    public function testAPaymentInAnotherCurrencyIsIgnored(): void
    {
        $id = $this->given($this->payment([ 'amount' => [ 'currency' => 'USD', 'value' => '120.00' ] ]));

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertSame('wrong-currency', $result['reason']);
        $this->assertSame(0, CJW_Wizard_Payments::paid($this->submissionId));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unpaidStatuses(): array
    {
        return [
            'still open' => [ 'open' ],
            'cancelled at the bank' => [ 'canceled' ],
            'expired' => [ 'expired' ],
            'failed' => [ 'failed' ],
            'pending' => [ 'pending' ],
        ];
    }

    /**
     * @param string $status What Mollie says the payment is doing.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('unpaidStatuses')]
    public function testNothingIsRecordedUntilMollieSaysPaid(string $status): void
    {
        $id = $this->given($this->payment([ 'status' => $status ]));

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertFalse($result['handled']);
        $this->assertSame($status, $result['reason']);
        $this->assertSame(0, CJW_Wizard_Payments::paid($this->submissionId));
        $this->assertSame($status, CJW_Wizard_Ideal::attempts($this->submissionId)[ $id ]['status']);
    }

    /**
     * The body is not evidence. A forged "paid" with a real id still has to
     * survive being asked about, and it does not.
     */
    public function testTheWebhookBodyIsNotBelieved(): void
    {
        $id = $this->given($this->payment([ 'status' => 'open' ]));

        $request = new WP_REST_Request('POST', '/' . CJW_Status_Endpoint::REST_NAMESPACE . CJW_Wizard_Ideal::REST_ROUTE);
        $request->set_param('id', $id);
        // As if the caller had posted a status of their own.
        $request->set_param('status', 'paid');
        $request->set_param('amount', '120.00');

        $response = CJW_Wizard_Ideal::handleWebhook($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, CJW_Wizard_Payments::paid($this->submissionId));
    }

    public function testAMalformedIdNeverReachesAUrl(): void
    {
        $result = CJW_Wizard_Ideal::process('tr_../../etc/passwd');

        $this->assertSame('malformed', $result['reason']);
        $this->assertFalse($result['retry']);
        $this->assertSame([], $this->requests, 'nothing was asked of Mollie');
    }

    /**
     * A payment we started that Mollie no longer has -- deleted, or invisible
     * to this key -- is not worth 26 hours of retries.
     */
    public function testAnIdMollieDoesNotKnowIsNotRetried(): void
    {
        $id = 'tr_cccccccccccccccccccccc';
        $this->registerAttempt($id);

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertFalse($result['retry']);
        $this->assertSame('unknown', $result['reason']);
    }

    /**
     * A failure at this end is exactly what Mollie's retries are for.
     */
    public function testMollieBeingUnreachableAsksForARetry(): void
    {
        $id = 'tr_dddddddddddddddddddddd';
        $this->registerAttempt($id);
        $this->answers[ $id ] = new WP_Error('http_request_failed', 'cURL error 28: timed out');

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertTrue($result['retry']);
        $this->assertSame('unreachable', $result['reason']);
    }

    public function testTheWebhookAnswers503WhenItWantsARetry(): void
    {
        $id = 'tr_eeeeeeeeeeeeeeeeeeeeee';
        $this->registerAttempt($id);
        $this->answers[ $id ] = new WP_Error('http_request_failed', 'timed out');

        $request = new WP_REST_Request('POST', '/x');
        $request->set_param('id', $id);

        $this->assertSame(503, CJW_Wizard_Ideal::handleWebhook($request)->get_status());
    }

    /**
     * The key for that mode was removed while a payment was in flight. Not
     * something to drop: Mollie retries for a day, which is time enough for
     * somebody to put it back.
     */
    public function testAPaymentWhoseKeyIsGoneAsksForARetry(): void
    {
        $id = $this->given($this->payment([ 'mode' => 'live' ]), 200, true, 'live');

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertTrue($result['retry']);
        $this->assertSame('no-key', $result['reason']);
        $this->assertSame([], $this->requests, 'and nothing is asked with the wrong credential');
    }

    /**
     * A copy of the site must not write the real books, even if Mollie somehow
     * reaches it.
     */
    public function testACopyOfTheSiteProcessesNothing(): void
    {
        $id = $this->given($this->payment());
        update_option(CJW_Mail_Safe_Mode::OPTION_PRODUCTION_HOST, 'www.cjw-brummen.nl');

        $result = CJW_Wizard_Ideal::process($id);

        $this->assertSame('copy', $result['reason']);
        $this->assertSame(0, CJW_Wizard_Payments::paid($this->submissionId));
        $this->assertSame([], $this->requests, 'and it does not even ask');
    }

    /**
     * The books follow the bank: somebody paying an older link after a
     * correction pays what that link said, and that is what is recorded.
     */
    public function testTheAmountRecordedIsTheAmountMolliePaid(): void
    {
        $id = $this->given($this->payment([ 'amount' => [ 'currency' => 'EUR', 'value' => '75.50' ] ]));

        CJW_Wizard_Ideal::process($id);

        $this->assertSame(7550, CJW_Wizard_Payments::paid($this->submissionId));
        $this->assertSame(4450, CJW_Wizard_Payments::outstanding($this->submissionId));
    }

    public function testTheKeyTravelsAsABearerTokenAndNotInTheUrl(): void
    {
        $id = $this->given($this->payment());

        CJW_Wizard_Ideal::process($id);

        $this->assertNotSame([], $this->requests);
        $this->assertSame('Bearer ' . self::KEY, $this->requests[0]['args']['headers']['Authorization']);
        $this->assertStringNotContainsString(self::KEY, $this->requests[0]['url']);
    }

    /**
     * The confirmation is held back until the payment resolves, and this is
     * the moment it resolves.
     */
    public function testThePaymentReleasesTheConfirmationMail(): void
    {
        $id = $this->given($this->payment());
        reset_phpmailer_instance();

        CJW_Wizard_Ideal::process($id);

        $this->assertSame(
            CJW_Wizard_Submission_Confirmation::SENT,
            CJW_Wizard_Submission_Confirmation::result($this->submissionId)
        );

        $sent = tests_retrieve_phpmailer_instance()->get_sent();

        $this->assertSame('ouder@example.test', $sent->to[0][0]);
        $this->assertStringNotContainsString(
            CJW_Wizard_Ideal::token($this->submissionId),
            $sent->body,
            'and it no longer carries a pay link, because there is nothing left to pay'
        );
    }
}
