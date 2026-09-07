<?php

declare(strict_types=1);

/**
 * Registering on a form that ends in a payment.
 *
 * The order is the point: the registration is stored, and only then is the
 * parent sent to iDEAL. A bank app that crashes, a phone that dies, a tab
 * closed on the payment screen -- none of it may cost the camp a registration,
 * and no money is ever asked for details nobody wrote down.
 */
final class IdealFlowTest extends CJW_IntegrationTestCase
{
    private const string KEY = 'test_abcdefghij1234567890ABCDE';

    private const string TOKEN = 'idealflowtesttoken';

    private const string CHECKOUT = 'https://www.mollie.com/checkout/tr_flow0000000000000000';

    private int $wizardId = 0;

    private int $pageId = 0;

    public function set_up(): void
    {
        parent::set_up();

        CJW_Roles::install();
        add_filter('home_url', [ $this, 'httpsHome' ], 10, 1);
        add_filter('pre_http_request', [ $this, 'fakeMollie' ], 10, 3);
        CJW_Mollie::storeKey(self::KEY);

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, array_merge(
            CJW_Summer_Camp_Service::defaults(),
            [ 'end_date' => '18-07-2027', 'retention_months' => 12 ]
        ));
        CJW_Summer_Camp_Service::flush();

        $this->pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Inschrijven',
            'post_content' => '[cjw_wizard]',
        ]);
        CJW_Registration_Page::flush();
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [ $this, 'fakeMollie' ], 10);
        remove_filter('home_url', [ $this, 'httpsHome' ], 10);
        $_POST = [];
        $_GET = [];
        unset($_SERVER['REQUEST_METHOD']);
        delete_transient('cjw_wizard_' . self::TOKEN);
        delete_option(CJW_Mollie::OPTION_KEY);
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);
        CJW_Summer_Camp_Service::flush();
        CJW_Registration_Page::flush();

        parent::tear_down();
    }

    /**
     * @param string $url Address WordPress built.
     */
    public function httpsHome(string $url): string
    {
        return str_replace('http://', 'https://', $url);
    }

    /**
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

        return [
            'headers' => [],
            'response' => [
                'code' => 201,
                'message' => '',
            ],
            'body' => (string) wp_json_encode([
                'id' => 'tr_flow0000000000000000',
                'mode' => 'test',
                'status' => 'open',
                'amount' => [ 'currency' => 'EUR', 'value' => '120.00' ],
                '_links' => [ 'checkout' => [ 'href' => self::CHECKOUT ] ],
            ]),
            'cookies' => [],
            'filename' => null,
        ];
    }

    /**
     * @param bool $requirePayment Whether the form ends in iDEAL.
     */
    private function publishedForm(bool $requirePayment): int
    {
        $id = self::factory()->post->create([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => 'publish',
            'post_title' => 'Inschrijfformulier',
        ]);

        // JSON_UNESCAPED_UNICODE on purpose: update_post_meta() unslashes what
        // it is given, which turns a \u20ac escape into a literal "u20ac" and
        // leaves the stored option value not matching the posted answer.
        update_post_meta($id, '_cjw_wizard_config', (string) wp_json_encode([
            'steps' => [
                [
                    'key' => 'kind',
                    'label' => 'Kind',
                    'type' => 'default',
                    'fields' => [
                        [ 'key' => 'voornaam', 'label' => 'Voornaam', 'type' => 'text', 'role' => 'child_name' ],
                        [
                            'key' => 'kamp',
                            'label' => 'Kamp',
                            'type' => 'radio',
                            'role' => 'camp_type',
                            'options' => [
                                [ 'label' => 'Weekkamp (€ 120)', 'value' => 'Weekkamp (€ 120)' ],
                            ],
                        ],
                        [ 'key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'role' => 'parent_email' ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        update_post_meta($id, CJW_Wizard_Settings::META_KEY, [
            'require_ideal_payment' => $requirePayment,
            'require_privacy_consent' => false,
            'send_parent_confirmation' => true,
        ]);

        $this->wizardId = $id;

        return $id;
    }

    private function submit(int $formId): string
    {
        $issued = (string) (time() - 30);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'cjw_wizard_token' => self::TOKEN,
            'cjw_wizard_nonce' => wp_create_nonce('cjw_wizard_submit_' . self::TOKEN),
            'cjw_wizard_action' => 'next',
            CJW_Wizard_Guard::HONEYPOT_FIELD => '',
            CJW_Wizard_Guard::TIMESTAMP_FIELD => $issued . '.' . hash_hmac('sha256', $issued, wp_salt('nonce')),
            'cjw_field_voornaam' => 'Fleur',
            'cjw_field_kamp' => 'Weekkamp (€ 120)',
            'cjw_field_email' => 'ouder@example.test',
        ];

        return (string) do_shortcode('[cjw_wizard id="' . $formId . '"]');
    }

    private function lastSubmission(): int
    {
        $found = get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::inAllList(),
            'numberposts' => 1,
        ]);

        return $found === [] ? 0 : (int) $found[0]->ID;
    }

    public function testTheRegistrationIsStoredBeforeAnybodyIsAskedToPay(): void
    {
        $output = $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        $this->assertGreaterThan(0, $id, 'stored first, always');
        $this->assertSame('Fleur', $this->payload($id)['voornaam']);
        $this->assertSame(12000, CJW_Wizard_Payments::amount($id)['due']);
        $this->assertStringContainsString('Nog één stap: betalen', $output);
        $this->assertStringNotContainsString('Gelukt!', $output);
    }

    public function testTheSuccessScreenCarriesThePayLink(): void
    {
        $output = $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        $this->assertStringContainsString(CJW_Wizard_Ideal::ARG_PAY . '=' . CJW_Wizard_Ideal::token($id), $output);
        $this->assertStringContainsString('Betaal', $output);
        $this->assertStringNotContainsString('Begin Opnieuw', $output, 'not while a payment is waiting');
    }

    /**
     * Nothing useful to confirm yet: the parent is either about to pay or
     * about to abandon it, and both deserve a different mail.
     */
    public function testTheConfirmationIsHeldBackWithASafetyNet(): void
    {
        reset_phpmailer_instance();

        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        $this->assertSame('', CJW_Wizard_Submission_Confirmation::result($id), 'not sent yet');
        $this->assertNotFalse(
            wp_next_scheduled(CJW_Wizard_Ideal::HOOK_CONFIRMATION, [ $id ]),
            'but somebody who never pays still hears from us'
        );
    }

    /**
     * The safety net firing: they abandoned it, so the mail carries the link.
     */
    public function testTheDeferredConfirmationCarriesThePayLink(): void
    {
        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        reset_phpmailer_instance();
        CJW_Wizard_Ideal::sendDeferredConfirmation($id);

        $sent = tests_retrieve_phpmailer_instance()->get_sent();

        $this->assertSame(CJW_Wizard_Submission_Confirmation::SENT, CJW_Wizard_Submission_Confirmation::result($id));
        $this->assertStringContainsString(CJW_Wizard_Ideal::token($id), $sent->body);
        $this->assertStringContainsString('Betaal', $sent->body);
    }

    /**
     * And once it is paid, that button is gone -- not hidden, absent.
     */
    public function testTheMailCarriesNoPayLinkOnceThereIsNothingToPay(): void
    {
        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        CJW_Wizard_Payments::add($id, 12000, CJW_Wizard_Payments::METHOD_IDEAL, '', '', 'tr_paidbyhand00000000');

        $this->assertSame('', CJW_Wizard_Ideal::mailButton($id));

        reset_phpmailer_instance();
        CJW_Wizard_Ideal::sendDeferredConfirmation($id);

        $this->assertStringNotContainsString(
            CJW_Wizard_Ideal::token($id),
            tests_retrieve_phpmailer_instance()->get_sent()->body
        );
    }

    public function testAFormWithoutPaymentBehavesExactlyAsBefore(): void
    {
        reset_phpmailer_instance();

        $output = $this->submit($this->publishedForm(false));
        $id = $this->lastSubmission();

        $this->assertStringContainsString('Gelukt!', $output);
        $this->assertSame(CJW_Wizard_Submission_Confirmation::SENT, CJW_Wizard_Submission_Confirmation::result($id));
        $this->assertFalse(wp_next_scheduled(CJW_Wizard_Ideal::HOOK_CONFIRMATION, [ $id ]));
    }

    public function testStartingAPaymentAsksMollieForExactlyWhatIsOutstanding(): void
    {
        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        CJW_Wizard_Payments::add($id, 2000, CJW_Wizard_Payments::METHOD_CASH);

        $started = CJW_Wizard_Ideal::start($id);

        $this->assertTrue($started['ok']);
        $this->assertSame(self::CHECKOUT, $started['url']);

        $attempts = CJW_Wizard_Ideal::attempts($id);

        $this->assertSame(10000, reset($attempts)['cents'], 'the €20 already in hand is not asked for again');
    }

    /**
     * A parent who clicks twice should land on the payment they already have.
     */
    public function testAPaymentJustStartedIsOfferedAgainRatherThanDuplicated(): void
    {
        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        CJW_Wizard_Ideal::start($id);
        CJW_Wizard_Ideal::start($id);

        $this->assertCount(1, CJW_Wizard_Ideal::attempts($id));
    }

    public function testNothingIsStartedOnceTheBalanceIsSettled(): void
    {
        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        CJW_Wizard_Payments::add($id, 12000, CJW_Wizard_Payments::METHOD_TRANSFER);

        $started = CJW_Wizard_Ideal::start($id);

        $this->assertFalse($started['ok']);
        $this->assertStringContainsString('niets meer open', (string) $started['error']);
    }

    public function testACancelledRegistrationCannotBePaid(): void
    {
        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        wp_update_post([ 'ID' => $id, 'post_status' => CJW_Wizard_Submission_Status::CANCELLED ]);

        $this->assertFalse(CJW_Wizard_Ideal::start($id)['ok']);
    }

    /**
     * The parent comes back from the bank, possibly on another device, with
     * nothing but the token in the address.
     */
    public function testComingBackFromIdealShowsTheirOwnConfirmation(): void
    {
        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();
        $token = CJW_Wizard_Ideal::token($id);

        CJW_Wizard_Payments::add($id, 12000, CJW_Wizard_Payments::METHOD_IDEAL, '', '', 'tr_returned000000000');

        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
        $_GET = [ CJW_Wizard_Ideal::ARG_RETURN => $token ];

        $output = (string) do_shortcode('[cjw_wizard id="' . $this->wizardId . '"]');

        $this->assertStringContainsString('Gelukt!', $output);
        $this->assertStringContainsString((string) $id, $output);
        $this->assertStringNotContainsString('Betaal', $output, 'there is nothing left to pay');
    }

    public function testComingBackWithoutHavingPaidStillAsksForThePayment(): void
    {
        $this->submit($this->publishedForm(true));
        $id = $this->lastSubmission();

        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
        $_GET = [ CJW_Wizard_Ideal::ARG_RETURN => CJW_Wizard_Ideal::token($id) ];

        $output = (string) do_shortcode('[cjw_wizard id="' . $this->wizardId . '"]');

        $this->assertStringContainsString('Nog één stap: betalen', $output);
    }

    public function testAMadeUpTokenShowsNobodyAnything(): void
    {
        $this->publishedForm(true);
        $_GET = [ CJW_Wizard_Ideal::ARG_RETURN => str_repeat('a', 32) ];

        $output = (string) do_shortcode('[cjw_wizard id="' . $this->wizardId . '"]');

        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringNotContainsString('Fleur', $output);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $submissionId): array
    {
        return new CJW_Wizard_Submission_Repository()->get_payload($submissionId);
    }
}
