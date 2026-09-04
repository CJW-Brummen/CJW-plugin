<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../../../../');
}

if (! function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}

if (! function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = ''): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (! function_exists('is_email')) {
    function is_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (! function_exists('get_post_meta')) {
    /*
     * Reads back whatever update_post_meta() stored, so a test can set up a row
     * and then assert what a screen makes of it. Unset keys answer the way
     * WordPress does -- '' for a single read, [] otherwise -- rather than
     * always [], which turned a `(string) get_post_meta(..., true)` into the
     * literal "Array".
     */
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        if (array_key_exists($key, $GLOBALS['cjw_test_post_meta'][ $post_id ] ?? [])) {
            $stored = $GLOBALS['cjw_test_post_meta'][ $post_id ][ $key ];

            return $single ? $stored : [ $stored ];
        }

        // add_post_meta() writes rows, not a value: a submission holds one
        // camp row per child, and a single read has to answer with the first.
        $rows = $GLOBALS['cjw_test_post_meta_rows'][ $post_id ][ $key ] ?? null;

        if ($rows !== null) {
            return $single ? ($rows[0] ?? '') : $rows;
        }

        return $single ? '' : [];
    }
}

if (! function_exists('add_post_meta')) {
    function add_post_meta(int $post_id, string $key, mixed $value): bool
    {
        $GLOBALS['cjw_test_post_meta_rows'][ $post_id ][ $key ][] = $value;

        return true;
    }
}

if (! function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $key): bool
    {
        unset(
            $GLOBALS['cjw_test_post_meta'][ $post_id ][ $key ],
            $GLOBALS['cjw_test_post_meta_rows'][ $post_id ][ $key ]
        );

        return true;
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('wp_insert_post')) {
    /*
     * Stores the row and hands back an id, which is the whole point here: the
     * title is built from the id, so it cannot exist before the insert does.
     */
    function wp_insert_post(array $postarr, bool $wp_error = false): int|WP_Error
    {
        unset($wp_error);

        $id = 1 + (int) array_key_last($GLOBALS['cjw_test_posts'] ?? [ 200 => [] ]);

        $GLOBALS['cjw_test_posts'][ $id ] = $postarr + [ 'ID' => $id, 'post_name' => '' ];

        return $id;
    }
}

if (! function_exists('wp_update_post')) {
    function wp_update_post(array $postarr): int
    {
        $id = (int) ($postarr['ID'] ?? 0);

        $GLOBALS['cjw_test_posts'][ $id ] = $postarr + ($GLOBALS['cjw_test_posts'][ $id ] ?? []);

        return $id;
    }
}

if (! function_exists('wp_parse_args')) {
    function wp_parse_args($args, array $defaults = []): array
    {
        if (is_array($args)) {
            return array_merge($defaults, $args);
        }

        return $defaults;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test bootstrap shim for WordPress sanitizer.
        return trim(strip_tags($value));
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test bootstrap shim for WordPress sanitizer.
        return trim(strip_tags($value));
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test bootstrap shim mirrors the WordPress translation helper; both arguments are forwarded parameters by design.
        return esc_html(__($text, $domain));
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = ''): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test shim; both arguments are forwarded parameters.
        return esc_attr(__($text, $domain));
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (! function_exists('esc_textarea')) {
    function esc_textarea(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $text): string
    {
        return $text;
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test bootstrap shim for WordPress helper.
        return trim(strip_tags($text));
    }
}

if (! function_exists('wp_date')) {
    /*
     * The timestamp is optional in WordPress, and code that leaves it out
     * means "now" -- a fixed instant here, so a test never straddles midnight.
     */
    function wp_date(string $format, ?int $timestamp = null): string
    {
        return gmdate($format, $timestamp ?? ($GLOBALS['cjw_test_now'] ?? 1756166400));
    }
}

if (! function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (! function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, ',', '.');
    }
}

if (! function_exists('checked')) {
    /*
     * WordPress ECHOES when $display is true and returns either way. The shim
     * used to only return, so `<?php checked( $a, $b ); ?>` -- the form the
     * whole of wp-admin is written in -- silently rendered nothing here while
     * working perfectly in the browser.
     */
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Matches WordPress, which compares as strings.
        $result = (string) $checked === (string) $current ? " checked='checked'" : '';

        if ($display) {
            echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed attribute.
        }

        return $result;
    }
}

if (! function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? " selected='selected'" : '';

        if ($display) {
            echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed attribute.
        }

        return $result;
    }
}

if (! function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $class): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $class) ?? '';
    }
}

if (! function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action, string $name): void
    {
        printf(
            '<input type="hidden" name="%s" value="nonce-for-%s">',
            esc_attr($name),
            esc_attr($action)
        );
    }
}

if (! function_exists('add_query_arg')) {
    function add_query_arg(array|string $key = [], string $value = '', string $url = 'http://example.test/current'): string
    {
        $base = $url;
        $params = [];

        if (is_string($key) && $key !== '') {
            $params[ $key ] = $value;
        } elseif (is_array($key)) {
            $params = $key;
        }

        if ($params === []) {
            return $base;
        }

        return $base . '?' . http_build_query($params);
    }
}

if (! function_exists('remove_query_arg')) {
    function remove_query_arg(array|string $key, string $url = 'http://example.test/current'): string
    {
        $parts = explode('?', $url, 2);

        if (count($parts) !== 2) {
            return $url;
        }

        parse_str($parts[1], $params);

        foreach ((array) $key as $name) {
            unset($params[ $name ]);
        }

        return $params === [] ? $parts[0] : $parts[0] . '?' . http_build_query($params);
    }
}

if (! function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action, string $name = '_wpnonce'): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . rawurlencode($name) . '=' . rawurlencode('nonce-for-' . $action);
    }
}

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! defined('MB_IN_BYTES')) {
    define('MB_IN_BYTES', 1048576);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}

if (! function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'cjw-test-salt-' . $scheme;
    }
}

/*
 * In-memory transients. The guard's rate limiting is a counter with a reset
 * time, so the suite needs storage that actually round-trips -- returning false
 * from a stub would make every limit look untouched and the tests meaningless.
 */
if (! function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return $GLOBALS['cjw_test_transients'][ $key ] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['cjw_test_transients'][ $key ] = $value;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['cjw_test_transients'][ $key ]);

        return true;
    }
}

if (! function_exists('has_shortcode')) {
    function has_shortcode(string $content, string $tag): bool
    {
        return str_contains($content, '[' . $tag);
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink(int $post_id): string|false
    {
        return 'https://example.test/?page_id=' . $post_id;
    }
}

if (! function_exists('get_posts')) {
    /*
     * Records the arguments so a test can assert which post statuses the
     * repository actually looks in -- the trash omission was invisible until
     * something checked the query itself.
     */
    function get_posts(array $args = []): array
    {
        $GLOBALS['cjw_test_get_posts_args'][] = $args;

        return $GLOBALS['cjw_test_get_posts_return'] ?? [];
    }
}

if (! function_exists('wp_mail')) {
    /*
     * Records instead of sending, so a test can assert who was written to and
     * — just as important — what was NOT put in the body.
     */
    function wp_mail(string $to, string $subject, string $message, array|string $headers = []): bool
    {
        $GLOBALS['cjw_test_mail'][] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $message,
            'headers' => $headers,
        ];

        return true;
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $key, mixed $value): bool
    {
        $GLOBALS['cjw_test_post_meta'][ $post_id ][ $key ] = $value;

        return true;
    }
}

if (! function_exists('get_post_time')) {
    function get_post_time(string $format = 'U', bool $gmt = false, mixed $post = null): string
    {
        return gmdate($format, 1756166400);
    }
}

if (! function_exists('get_the_title')) {
    function get_the_title(int $post = 0): string
    {
        return 'Inschrijfformulier';
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'http://example.test/wp-admin/' . $path;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['cjw_test_options'][ $option ] ?? $default;
    }
}

if (! function_exists('update_option')) {
    /*
     * The third argument is WordPress's own: callers that store something the
     * front end never reads pass autoload false. A two-argument shim would make
     * those callers fatal here while working perfectly in production.
     */
    function update_option(string $option, mixed $value, ?bool $autoload = null): bool
    {
        unset($autoload);

        $GLOBALS['cjw_test_options'][ $option ] = $value;

        return true;
    }
}

if (! class_exists('WP_User')) {
    /*
     * Only the two properties this plugin reads off a user.
     */
    class WP_User
    {
        public int $ID = 0;
        public string $display_name = '';
        public string $user_email = '';

        public function __construct(int $id = 0, string $display_name = '', string $user_email = '')
        {
            $this->ID = $id;
            $this->display_name = $display_name;
            $this->user_email = $user_email;
        }
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user(): WP_User
    {
        return $GLOBALS['cjw_test_current_user'] ?? new WP_User();
    }
}

if (! function_exists('do_action')) {
    /*
     * Records rather than dispatches. Whether a listener actually runs is a
     * question about WordPress, and it is answered in the integration suite
     * against a real one; what a unit test can honestly check is that the
     * announcement was made, with the right arguments.
     */
    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['cjw_test_actions'][] = [
            'hook' => $hook,
            'args' => $args,
        ];
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (! function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Amsterdam');
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (! function_exists('is_ssl')) {
    function is_ssl(): bool
    {
        return false;
    }
}

if (! function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}

require_once __DIR__ . '/../inc/shared/class-camp-type.php';
require_once __DIR__ . '/../inc/shared/localization.php';
require_once __DIR__ . '/../inc/form-builder/domain/class-wizard-field-roles.php';
require_once __DIR__ . '/../inc/form-builder/domain/class-wizard-defaults.php';
require_once __DIR__ . '/../inc/form-builder/domain/class-wizard-config-normalizer.php';
require_once __DIR__ . '/../inc/form-builder/admin/class-wizard-settings.php';
require_once __DIR__ . '/../inc/form-builder/integrations/class-summer-camp-context.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-conditions.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-validator.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-engine.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-renderer.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-status.php';
require_once __DIR__ . '/../inc/form-builder/submissions/interface-wizard-submission-store.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-repository.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-exporter.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-unavailable.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-guard.php';
require_once __DIR__ . '/../inc/shared/class-cjw-roles.php';
require_once __DIR__ . '/../inc/shared/class-summer-camp-service.php';
require_once __DIR__ . '/../inc/shared/class-cjw-registration-page.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-session.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-privacy.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-retention.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-notifier.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-confirmation.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-detail.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-admin.php';
if (! function_exists('cjw_summer_camp')) {
    /*
     * Deliberately NOT the singleton the plugin ships: a test needs the settings
     * it just wrote to take effect, which a cached instance would hide.
     *
     * A test may install its own stand-in in $GLOBALS['cjw_test_summer_camp'];
     * otherwise this reads whatever settings the test put in
     * $GLOBALS['cjw_test_options'].
     */
    function cjw_summer_camp(): object
    {
        return $GLOBALS['cjw_test_summer_camp'] ?? new CJW_Summer_Camp_Service();
    }
}

if (! function_exists('get_users')) {
    /**
     * The ids the test decided exist. Empty by default, so a test that does
     * not care about recipients sends to nobody rather than to a fixture.
     *
     * @return array<int, int>
     */
    function get_users(array $args = []): array
    {
        return $GLOBALS['cjw_test_users'] ?? [];
    }
}

if (! function_exists('get_userdata')) {
    function get_userdata(int $user_id): mixed
    {
        return $GLOBALS['cjw_test_userdata'][ $user_id ] ?? false;
    }
}

if (! function_exists('get_the_date')) {
    function get_the_date(string $format = '', mixed $post = null): string
    {
        return wp_date($format !== '' ? $format : 'j F Y');
    }
}

if (! function_exists('get_the_time')) {
    function get_the_time(string $format = '', mixed $post = null): string
    {
        return wp_date($format !== '' ? $format : 'H:i');
    }
}

if (! function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = ''): void
    {
        echo esc_html($text);
    }
}

if (! function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = ''): void
    {
        echo esc_attr($text);
    }
}

if (! function_exists('get_post_timestamp')) {
    function get_post_timestamp(mixed $post = null, string $field = 'date'): int
    {
        return 1756166400;
    }
}

if (! function_exists('get_delete_post_link')) {
    function get_delete_post_link(int $post_id = 0): ?string
    {
        return 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=trash';
    }
}

if (! function_exists('remove_meta_box')) {
    function remove_meta_box(string $id, mixed $screen = null, string $context = 'advanced'): void
    {
        unset($screen, $context);

        $GLOBALS['cjw_test_meta_boxes_removed'][] = $id;
    }
}

if (! function_exists('add_meta_box')) {
    function add_meta_box(string $id, string $title, mixed $callback = null, mixed $screen = null, string $context = 'advanced'): void
    {
        unset($callback, $screen, $context);

        $GLOBALS['cjw_test_meta_boxes'][ $id ] = $title;
    }
}

if (! class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '') {}
    }
}

if (! class_exists('WP_Post')) {
    /*
     * Just enough of a post for the list-table code: an id and a type.
     */
    final class WP_Post
    {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_title = '';
        public string $post_status = 'publish';

        public function __construct(int $id = 0, string $type = 'post')
        {
            $this->ID = $id;
            $this->post_type = $type;
        }
    }
}

if (! function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $post_id = 0): ?string
    {
        return $GLOBALS['cjw_test_no_edit_link'] ?? false
            ? null
            : 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
    }
}

if (! function_exists('wp_schedule_single_event')) {
    /*
     * Records rather than schedules. Whether WP-Cron then runs it is a question
     * about WordPress; what a unit test can check is that the work left over
     * from a batch was handed on rather than dropped.
     */
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        $GLOBALS['cjw_test_scheduled'][] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }
}

if (! function_exists('sanitize_hex_color')) {
    function sanitize_hex_color(string $color): ?string
    {
        return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color) === 1 ? $color : null;
    }
}

if (! function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        return match ($show) {
            'name' => 'CJW Brummen',
            'description' => 'Zomerkamp',
            default => '',
        };
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter(string $hook, callable $callback, int $priority = 10): bool
    {
        return true;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

if (! function_exists('remove_action')) {
    function remove_action(string $hook, callable $callback, int $priority = 10): bool
    {
        return true;
    }
}

if (! function_exists('wpautop')) {
    function wpautop(string $text): string
    {
        $parts = preg_split('/\n\s*\n/', trim($text)) ?: [];

        return implode('', array_map(static fn(string $p): string => '<p>' . trim($p) . '</p>', $parts));
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return $GLOBALS['cjw_test_is_admin'] ?? false;
    }
}

if (! function_exists('get_post')) {
    function get_post(mixed $post = null): ?WP_Post
    {
        return is_int($post) ? new WP_Post($post) : null;
    }
}

require_once __DIR__ . '/../inc/shared/class-cjw-mail-templates.php';
require_once __DIR__ . '/../inc/shared/class-cjw-mail-tokens.php';
require_once __DIR__ . '/../inc/shared/class-cjw-mailer.php';
require_once __DIR__ . '/../inc/shared/class-cjw-alerts.php';
require_once __DIR__ . '/../inc/shared/class-cjw-cron-heartbeat.php';
require_once __DIR__ . '/../inc/form-builder/domain/class-wizard-amount.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-birthday-mailer.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-year-rollover.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-digest.php';
require_once __DIR__ . '/../inc/summer-camp/admin/class-cjw-summer-camp-mail.php';
require_once __DIR__ . '/../inc/summer-camp/admin/class-cjw-summer-camp-settings-history.php';
require_once __DIR__ . '/../inc/summer-camp/admin/class-cjw-summer-camp-year.php';
require_once __DIR__ . '/../inc/summer-camp/class-cjw-summer-camp-rental-post-type.php';

if (! function_exists('remove_accents')) {
    /**
     * Enough of WordPress's transliteration for a duplicate check: the Dutch
     * names this site sees are accented, not transliterated from another script.
     */
    function remove_accents(string $text): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return is_string($converted) ? $converted : $text;
    }
}

require_once __DIR__ . '/support/TestCase.php';
require_once __DIR__ . '/support/FakeSubmissionRepository.php';
