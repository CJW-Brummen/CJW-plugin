<?php

declare(strict_types=1);

/**
 * The status route as WordPress serves it.
 *
 * A monitor outside the site is the only thing that can notice a site which
 * has stopped running code, and it reaches this route with no cookies and no
 * session. What it gets back, and how little of it without the token, is what
 * matters here.
 */
final class StatusEndpointRouteTest extends CJW_IntegrationTestCase
{
    private const string TOKEN = 'statustoken1234567890';

    public function set_up(): void
    {
        parent::set_up();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);

        update_option(CJW_Summer_Camp_Service::OPTION_KEY, array_merge(
            CJW_Summer_Camp_Service::defaults(),
            [ 'status_token' => self::TOKEN ]
        ));
        CJW_Summer_Camp_Service::flush();
        delete_option(CJW_Fatal_Error_Watch::OPTION_KEY);
        delete_option(CJW_Alerts::OPTION_KEY);

        // A site with nothing wrong with it, so each test decides what is.
        // Registration is closed by default, which is a state with nothing to
        // check; mail has to have been proved to work at some point.
        update_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST, [
            'time' => time(),
            'to' => 'beheer@example.test',
            'ok' => true,
            'error' => '',
            'by' => 'Marieke',
        ]);

        CJW_Status_Endpoint::flush();
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);
        delete_option(CJW_Fatal_Error_Watch::OPTION_KEY);
        delete_option(CJW_Alerts::OPTION_KEY);
        delete_option(CJW_Summer_Camp_Mail::OPTION_LAST_TEST);
        CJW_Summer_Camp_Service::flush();
        CJW_Status_Endpoint::flush();

        parent::tear_down();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function get(string $token = ''): array
    {
        $request = new WP_REST_Request('GET', '/' . CJW_Status_Endpoint::REST_NAMESPACE . CJW_Status_Endpoint::ROUTE);

        if ($token !== '') {
            $request->set_param(CJW_Status_Endpoint::TOKEN_ARG, $token);
        }

        // The route caches for half a minute; a test changing something and
        // asking again wants the new answer, not the one from a line earlier.
        CJW_Status_Endpoint::flush();

        $response = rest_get_server()->dispatch($request);

        return [
            'status' => $response->get_status(),
            'body' => (array) $response->get_data(),
        ];
    }

    public function testTheRouteIsRegisteredAndNeedsNoLogin(): void
    {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/' . CJW_Status_Endpoint::REST_NAMESPACE . CJW_Status_Endpoint::ROUTE, $routes);

        $answer = $this->get();

        $this->assertSame(200, $answer['status']);
        $this->assertSame('ok', $answer['body']['status']);
    }

    public function testAnAnonymousCallerIsToldNothingBeyondUpOrNot(): void
    {
        CJW_Fatal_Error_Watch::report('Uncaught Error: kaput', ABSPATH . 'wp-content/plugins/cjw/boom.php', 12);

        $answer = $this->get();

        $this->assertSame(200, $answer['status'], 'reachability is what an uptime monitor measures');
        $this->assertSame('critical', $answer['body']['status']);
        $this->assertSame([ 'status', 'time' ], array_keys($answer['body']));
        $this->assertStringNotContainsString('kaput', (string) wp_json_encode($answer['body']));
        $this->assertStringNotContainsString('boom.php', (string) wp_json_encode($answer['body']));
    }

    public function testTheTokenUnlocksWhatIsActuallyWrong(): void
    {
        CJW_Fatal_Error_Watch::report('Uncaught Error: kaput', ABSPATH . 'wp-content/plugins/cjw/boom.php', 12);

        $answer = $this->get(self::TOKEN);

        $this->assertSame('critical', $answer['body']['status']);
        $this->assertSame('Uncaught Error: kaput', $answer['body']['checks']['fatal']['message']);
        $this->assertSame('wp-content/plugins/cjw/boom.php', $answer['body']['checks']['fatal']['file']);
        $this->assertArrayHasKey('site', $answer['body']);
        $this->assertSame(CJW_VERSION, $answer['body']['version']);
    }

    /**
     * A near miss must look exactly like no attempt at all.
     */
    public function testAWrongTokenIsAnsweredLikeNoToken(): void
    {
        $answer = $this->get('statustoken1234567891');

        $this->assertSame(200, $answer['status']);
        $this->assertSame([ 'status', 'time' ], array_keys($answer['body']));
    }

    /**
     * For monitors that can only look at the status code.
     */
    public function testStrictModeAnswers503WhenSomethingIsCritical(): void
    {
        $request = new WP_REST_Request('GET', '/' . CJW_Status_Endpoint::REST_NAMESPACE . CJW_Status_Endpoint::ROUTE);
        $request->set_param(CJW_Status_Endpoint::STRICT_ARG, '1');

        CJW_Status_Endpoint::flush();

        $this->assertSame(200, rest_get_server()->dispatch($request)->get_status(), 'a healthy site is still a 200');

        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 1);
        CJW_Status_Endpoint::flush();

        $this->assertSame(503, rest_get_server()->dispatch($request)->get_status());
    }

    /**
     * A warning is not worth waking anybody: strict mode stays at 200 for it,
     * and the body still says so for whoever is reading words.
     */
    public function testStrictModeStaysAt200ForAWarning(): void
    {
        CJW_Alerts::raise('iets', 'Iets kleins', '<p>x</p>');

        $request = new WP_REST_Request('GET', '/' . CJW_Status_Endpoint::REST_NAMESPACE . CJW_Status_Endpoint::ROUTE);
        $request->set_param(CJW_Status_Endpoint::STRICT_ARG, '1');

        CJW_Status_Endpoint::flush();
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('warning', $response->get_data()['status']);
    }

    public function testTheAnswerIsCachedBetweenPolls(): void
    {
        $this->assertSame('ok', $this->get()['body']['status']);

        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 1);

        // Not flushed on purpose: this is what a second poll within the window
        // gets, and it is why the route costs almost nothing to hammer.
        $request = new WP_REST_Request('GET', '/' . CJW_Status_Endpoint::REST_NAMESPACE . CJW_Status_Endpoint::ROUTE);

        $this->assertSame('ok', rest_get_server()->dispatch($request)->get_data()['status']);
    }

    public function testTheAddressOnTheBeheerTabCarriesTheToken(): void
    {
        $url = CJW_Status_Endpoint::url(true);

        // Without pretty permalinks the route travels as a query argument, and
        // add_query_arg() encodes its slashes, so the parts are what to check.
        $this->assertStringContainsString('status', $url);
        $this->assertStringContainsString(CJW_Status_Endpoint::TOKEN_ARG . '=' . self::TOKEN, $url);
        $this->assertStringNotContainsString(self::TOKEN, CJW_Status_Endpoint::url());

    }

    public function testClearingTheCrashMakesTheSiteReportOkAgain(): void
    {
        CJW_Fatal_Error_Watch::report('Boom', ABSPATH . 'a.php', 1);

        $this->assertSame('critical', $this->get()['body']['status']);

        CJW_Fatal_Error_Watch::clear();

        $this->assertSame('ok', $this->get()['body']['status']);
    }

    /**
     * Activation generates the token, because the detail is useless without one
     * and nobody types a token by hand.
     */
    public function testASavedSettingsFormKeepsTheToken(): void
    {
        $saved = CJW_Summer_Camp_Service::sanitize(array_merge(
            (array) get_option(CJW_Summer_Camp_Service::OPTION_KEY),
            [ 'status_token' => self::TOKEN ]
        ));

        $this->assertSame(self::TOKEN, $saved['status_token']);
    }

    public function testAnEmptyTokenIsReplacedRatherThanLeftBlank(): void
    {
        $saved = CJW_Summer_Camp_Service::sanitize([ 'status_token' => '' ]);

        $this->assertNotSame('', $saved['status_token']);
    }
}
