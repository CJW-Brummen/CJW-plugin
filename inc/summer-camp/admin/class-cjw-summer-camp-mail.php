<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes mail delivery something anyone can check, and something that fails
 * loudly instead of silently.
 *
 * The plugin does not send mail itself: transport is left to a dedicated SMTP
 * plugin, because storing a mail password in the options table and re-writing
 * PHPMailer configuration is a worse trade than one well-maintained dependency.
 * What lives here is the half that is genuinely ours -- proving that whatever
 * transport is in place actually works, and saying so where somebody will look.
 *
 * That distinction matters more since the parent confirmation shipped. A
 * registration that silently fails to confirm does not read as a broken mail
 * server; it reads to a parent as a failed registration, and they register
 * again or telephone.
 */
final class CJW_Summer_Camp_Mail
{
    /**
     * The admin-post action behind the test button.
     */
    public const string ACTION = 'cjw_send_test_mail';

    /**
     * Outcome of the last test send.
     *
     * @var string
     */
    public const string OPTION_LAST_TEST = 'cjw_summer_camp_last_test_mail';

    /**
     * How long a successful test stays evidence.
     *
     * Six months, which is a little under the gap between two camp seasons: a
     * test that passed before last summer says nothing about a host that has
     * since changed its mail policy, and the year's registrations are exactly
     * what would discover that.
     */
    private const int STALE_AFTER_DAYS = 180;

    /**
     * Registers the admin hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [new self(), 'handleTestMail']);
    }

    /**
     * Handles the "Stuur een testmail" submission and returns to the settings
     * screen.
     *
     * @return void
     */
    public function handleTestMail(): void
    {
        if (!CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Je hebt geen toestemming om een testmail te versturen.', 'cjw'));
        }

        check_admin_referer(self::ACTION);

        $requested = isset($_POST['cjw_test_mail_to'])
            ? sanitize_email(wp_unslash((string) $_POST['cjw_test_mail_to']))
            : '';

        $to = is_email($requested) ? $requested : $this->defaultRecipient();

        if (!is_email($to)) {
            $this->record('', false, __('Er is geen geldig e-mailadres opgegeven.', 'cjw'));
        } else {
            $this->send($to);
        }

        wp_safe_redirect(add_query_arg(
            [
                'page' => 'cjw-summer-camp',
                'tab' => 'beheer',
                'cjw_mail_test' => '1',
            ],
            admin_url('admin.php')
        ));

        exit;
    }

    /**
     * Sends the test message and records what happened.
     *
     * @param string $to Recipient address.
     *
     * @return bool Whether the mail was handed off without error.
     */
    public function send(string $to): bool
    {
        $error = '';

        // wp_mail() answers only true or false. The reason -- authentication
        // refused, connection timed out, relay denied -- arrives on this hook
        // and nowhere else, and it is the entire difference between a message
        // somebody can act on and "het is mislukt".
        $capture = static function (WP_Error $wp_error) use (&$error): void {
            $error = (string) $wp_error->get_error_message();
        };

        add_action('wp_mail_failed', $capture);

        $sent = wp_mail(
            $to,
            __('Testmail van de zomerkampwebsite', 'cjw'),
            $this->body()
        );

        remove_action('wp_mail_failed', $capture);

        if (!$sent && $error === '') {
            $error = __('De mailserver gaf geen reden op.', 'cjw');
        }

        $this->record($to, $sent, $error);

        return $sent;
    }

    /**
     * The message body.
     *
     * Says what arriving proves and what it does not, because "de mail kwam
     * aan" is regularly taken to mean deliverability is solved, when all it
     * shows is that this one message reached this one inbox.
     *
     * @return string
     */
    private function body(): string
    {
        $lines = [
            __('Dit is een testmail van de zomerkampwebsite.', 'cjw'),
            '',
            __('Als je deze mail leest, kan de website mail versturen naar dit adres.', 'cjw'),
            __('Controleer ook of de mail niet in de map ongewenste mail (spam) is beland: dat telt als een probleem, ook al is de mail aangekomen.', 'cjw'),
            '',
            sprintf(
                /* translators: %s: site home URL. */
                __('Verstuurd vanaf: %s', 'cjw'),
                home_url('/')
            ),
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * Stores the outcome of a test send.
     *
     * @param string $to    Recipient address.
     * @param bool   $ok    Whether the send succeeded.
     * @param string $error Failure reason, when there is one.
     *
     * @return void
     */
    private function record(string $to, bool $ok, string $error = ''): void
    {
        $user = wp_get_current_user();

        update_option(self::OPTION_LAST_TEST, [
            'time' => time(),
            'to' => $to,
            'ok' => $ok,
            'error' => $error,
            'by' => $user instanceof WP_User ? $user->display_name : '',
        ], false);
    }

    /**
     * The recorded outcome of the last test send.
     *
     * @return array{time: int, to: string, ok: bool, error: string, by: string}|null
     */
    public static function lastTest(): ?array
    {
        $stored = get_option(self::OPTION_LAST_TEST);

        if (!is_array($stored) || !isset($stored['time'])) {
            return null;
        }

        return [
            'time' => (int) ($stored['time'] ?? 0),
            'to' => (string) ($stored['to'] ?? ''),
            'ok' => (bool) ($stored['ok'] ?? false),
            'error' => (string) ($stored['error'] ?? ''),
            'by' => (string) ($stored['by'] ?? ''),
        ];
    }

    /**
     * Whether the last successful test is old enough to have stopped meaning
     * anything.
     *
     * @return bool
     */
    public static function lastTestIsStale(): bool
    {
        $last = self::lastTest();

        if ($last === null || !$last['ok']) {
            return false;
        }

        return (time() - $last['time']) > (self::STALE_AFTER_DAYS * DAY_IN_SECONDS);
    }

    /**
     * Whether anything has taken over mail delivery from the bare PHP default.
     *
     * A heuristic, and named as one. An SMTP plugin configures PHPMailer on
     * `phpmailer_init`; an API-based sender (Postmark, Mailgun, Brevo) replaces
     * wp_mail() wholesale on `pre_wp_mail`. Neither hook is used by core, so a
     * callback on either means somebody has made a decision about how mail
     * leaves. It cannot tell a working configuration from a broken one -- that
     * is what the test send is for.
     *
     * @return bool
     */
    public static function transportIsConfigured(): bool
    {
        return has_action('phpmailer_init') !== false || has_filter('pre_wp_mail') !== false;
    }

    /**
     * Renders the mail panel on the Beheer tab.
     *
     * @return void
     */
    public static function renderPanel(): void
    {
        $last = self::lastTest();
        $configured = self::transportIsConfigured();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a flag set by our own redirect; nothing is changed here.
        $just_tested = isset($_GET['cjw_mail_test']);

        ?>
        <div class="cjw-tool-panel">
            <h2><?php esc_html_e('E-mail', 'cjw'); ?></h2>

            <p class="description">
                <?php esc_html_e('De website stuurt twee soorten mail: een bericht aan de organisatie bij een nieuwe inschrijving, en een bevestiging aan de ouder. Controleer hier of dat werkt.', 'cjw'); ?>
            </p>

            <?php if ($just_tested && $last !== null) : ?>
                <div class="notice notice-<?php echo $last['ok'] ? 'success' : 'error'; ?> inline">
                    <?php if ($last['ok']) : ?>
                        <p>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: e-mail address. */
                                __('De testmail is verstuurd naar %s. Controleer of hij aankomt, en of hij niet in de map ongewenste mail belandt.', 'cjw'),
                                $last['to']
                            ));
                            ?>
                        </p>
                    <?php else : ?>
                        <p>
                            <strong><?php esc_html_e('De testmail is niet verstuurd.', 'cjw'); ?></strong>
                            <?php if ($last['error'] !== '') : ?>
                                <br><?php echo esc_html($last['error']); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <table class="widefat striped cjw-tool-table">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Verzendmethode', 'cjw'); ?></th>
                        <td>
                            <?php if ($configured) : ?>
                                <?php esc_html_e('Er is een mailplugin actief die het versturen overneemt.', 'cjw'); ?>
                            <?php else : ?>
                                <strong><?php esc_html_e('Geen mailplugin actief.', 'cjw'); ?></strong>
                                <?php esc_html_e('De website verstuurt mail rechtstreeks via de webserver. Grote mailproviders zoals Gmail en Outlook weigeren die mail vaak of zetten hem in de map ongewenste mail.', 'cjw'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Laatste test', 'cjw'); ?></th>
                        <td>
                            <?php if ($last === null) : ?>
                                <?php esc_html_e('Er is nog nooit een testmail verstuurd.', 'cjw'); ?>
                            <?php else : ?>
                                <?php
                                echo esc_html(sprintf(
                                    $last['ok']
                                        /* translators: 1: date and time, 2: e-mail address. */
                                        ? __('Gelukt op %1$s, naar %2$s.', 'cjw')
                                        /* translators: 1: date and time, 2: e-mail address. */
                                        : __('Mislukt op %1$s, naar %2$s.', 'cjw'),
                                    wp_date(__('j F Y \o\m H:i', 'cjw'), $last['time']),
                                    $last['to'] !== '' ? $last['to'] : __('geen adres', 'cjw')
                                ));
                                ?>
                                <?php if (!$last['ok'] && $last['error'] !== '') : ?>
                                    <br><span style="color:#b32d2e;"><?php echo esc_html($last['error']); ?></span>
                                <?php endif; ?>
                                <?php if (self::lastTestIsStale()) : ?>
                                    <br><em><?php esc_html_e('Dat is inmiddels lang geleden. Test opnieuw voordat de inschrijvingen opengaan.', 'cjw'); ?></em>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                <?php wp_nonce_field(self::ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />

                <label for="cjw-test-mail-to"><?php esc_html_e('Stuur een testmail naar', 'cjw'); ?></label>
                <input
                    type="email"
                    id="cjw-test-mail-to"
                    name="cjw_test_mail_to"
                    class="regular-text"
                    value="<?php echo esc_attr(self::suggestedRecipient()); ?>"
                />
                <?php submit_button(__('Stuur een testmail', 'cjw'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * The address the test defaults to: whoever pressed the button.
     *
     * @return string
     */
    private function defaultRecipient(): string
    {
        $user = wp_get_current_user();
        $email = $user instanceof WP_User ? (string) $user->user_email : '';

        return is_email($email) ? $email : (string) get_option('admin_email');
    }

    /**
     * The address to prefill the field with.
     *
     * @return string
     */
    public static function suggestedRecipient(): string
    {
        return new self()->defaultRecipient();
    }
}
