<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps a copy of the site from mailing real parents.
 *
 * The manual tells volunteers to rehearse on a restored copy before touching
 * the live site. The moment that copy exists it holds real registrations --
 * real parents, real children's birthdays -- and nothing in the plugin knew it
 * was a copy. The birthday job would have run on schedule and greeted a child
 * from a staging address; a rehearsed confirmation would have reached a parent
 * who registered nowhere.
 *
 * A copy is recognised two ways. WordPress's own environment type, when the
 * host sets it to anything but production. And the site's host name: once
 * somebody confirms on the live site "this is the live website", any other
 * host running this database is a copy by definition. The second signal is
 * the one that catches a restored backup, because a backup carries the
 * production database's settings along with it -- including this class's own
 * option, which is exactly what makes the comparison work.
 *
 * In safe mode every mail the site sends, this plugin's or WordPress's own, is
 * redirected to the site administrator with the intended recipient written at
 * the top. Nothing is silently dropped: the person rehearsing sees precisely
 * what would have gone out, and to whom.
 */
final class CJW_Mail_Safe_Mode
{
    /**
     * The host name confirmed as the live website.
     */
    public const string OPTION_PRODUCTION_HOST = 'cjw_production_host';

    /**
     * How many mails were redirected, and the last few.
     */
    public const string OPTION_DIVERTED = 'cjw_mail_safe_mode_diverted';

    public const string ACTION_CONFIRM = 'cjw_confirm_production_host';

    public const string ACTION_FORGET = 'cjw_forget_production_host';

    private const string SUBJECT_PREFIX = '[TEST] ';

    /**
     * How many redirected mails to remember for the panel.
     */
    private const int REMEMBER = 8;

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        // Late, so that whatever another plugin does to the arguments has
        // already happened; the redirect must be the last word.
        add_filter('wp_mail', [ self::class, 'divert' ], 1000);

        if (is_admin()) {
            add_action('admin_notices', [ self::class, 'renderNotice' ]);
            add_action('admin_post_' . self::ACTION_CONFIRM, [ self::class, 'handleConfirm' ]);
            add_action('admin_post_' . self::ACTION_FORGET, [ self::class, 'handleForget' ]);
        }
    }

    /**
     * Whether this site is a copy and mail must not leave it.
     *
     * @return bool
     */
    public static function isActive(): bool
    {
        return self::reason() !== '';
    }

    /**
     * Why safe mode is on, for a human, or an empty string when it is off.
     *
     * @return string
     */
    public static function reason(): string
    {
        $environment = self::environment();

        if ($environment !== 'production') {
            return sprintf(
                /* translators: %s: WordPress environment type, e.g. "staging". */
                __('WordPress meldt dat dit een %s-omgeving is.', 'cjw'),
                $environment
            );
        }

        $production = self::productionHost();
        $current = self::currentHost();

        if ($production !== '' && $current !== '' && $production !== $current) {
            return sprintf(
                /* translators: 1: this site's host name, 2: the confirmed live host name. */
                __('Deze website draait op %1$s, terwijl %2$s is bevestigd als de live website.', 'cjw'),
                $current,
                $production
            );
        }

        return '';
    }

    /**
     * WordPress's own environment type.
     *
     * @return string
     */
    public static function environment(): string
    {
        return function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'production';
    }

    /**
     * The host name this site is being served from, lower-cased.
     *
     * @return string
     */
    public static function currentHost(): string
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * The host name somebody confirmed as the live website, or ''.
     *
     * @return string
     */
    public static function productionHost(): string
    {
        return strtolower(sanitize_text_field((string) get_option(self::OPTION_PRODUCTION_HOST, '')));
    }

    /**
     * Whether the live host has been confirmed at all.
     *
     * Until it has, only the environment type protects a copy, and that is
     * only as good as the host's configuration. Site Health nags about this.
     *
     * @return bool
     */
    public static function isConfirmed(): bool
    {
        return self::productionHost() !== '';
    }

    /**
     * Records the current host as the live website.
     *
     * @return void
     */
    public static function confirmCurrentHost(): void
    {
        update_option(self::OPTION_PRODUCTION_HOST, self::currentHost(), false);
    }

    /**
     * Forgets the confirmed host.
     *
     * @return void
     */
    public static function forget(): void
    {
        delete_option(self::OPTION_PRODUCTION_HOST);
    }

    /**
     * Where redirected mail goes.
     *
     * The site administrator: a volunteer, never a parent, and the one address
     * that exists on every WordPress site.
     *
     * @return string
     */
    public static function safeAddress(): string
    {
        $address = sanitize_email((string) get_option('admin_email', ''));

        return is_email($address) ? $address : '';
    }

    /**
     * The wp_mail filter: rewrites every outgoing mail while safe mode is on.
     *
     * @param array<string, mixed> $args The wp_mail() arguments.
     *
     * @return array<string, mixed>
     */
    public static function divert(array $args): array
    {
        if (! self::isActive()) {
            return $args;
        }

        $intended = self::recipients($args['to'] ?? '');
        $subject = (string) ($args['subject'] ?? '');

        $note = sprintf(
            /* translators: 1: intended recipients, 2: this site's host name. */
            __('[Mail-veiligmodus] Deze mail is niet verstuurd naar %1$s. Hij komt van de kopie op %2$s.', 'cjw'),
            $intended !== '' ? $intended : __('(geen ontvanger)', 'cjw'),
            self::currentHost()
        );

        // With no valid administrator address there is nowhere safe to send
        // it; an empty recipient makes wp_mail() fail, which is the right
        // outcome -- silence, not a parent's inbox.
        $args['to'] = self::safeAddress();
        $args['subject'] = str_starts_with($subject, self::SUBJECT_PREFIX) ? $subject : self::SUBJECT_PREFIX . $subject;
        $args['message'] = $note . "\n\n" . (string) ($args['message'] ?? '');
        $args['headers'] = self::withoutCopies($args['headers'] ?? []);

        self::record($intended, $subject);

        return $args;
    }

    /**
     * What has been redirected so far.
     *
     * @return array{count: int, last: array<int, array{to: string, subject: string, time: int}>}
     */
    public static function diverted(): array
    {
        $stored = get_option(self::OPTION_DIVERTED, []);

        return [
            'count' => is_array($stored) ? (int) ($stored['count'] ?? 0) : 0,
            'last' => is_array($stored) && is_array($stored['last'] ?? null) ? array_values($stored['last']) : [],
        ];
    }

    /**
     * Handles the "this is the live website" button.
     *
     * @return void
     */
    public static function handleConfirm(): void
    {
        self::authorise(self::ACTION_CONFIRM);
        self::confirmCurrentHost();
        self::returnToBeheer('host_confirmed');
    }

    /**
     * Handles the "forget" button.
     *
     * @return void
     */
    public static function handleForget(): void
    {
        self::authorise(self::ACTION_FORGET);
        self::forget();
        self::returnToBeheer('host_forgotten');
    }

    /**
     * The banner on every admin screen of a copy.
     *
     * Not dismissible on purpose: it is the one thing that tells somebody
     * rehearsing that they are rehearsing.
     *
     * @return void
     */
    public static function renderNotice(): void
    {
        if (! self::isActive() || ! (CJW_Roles::currentUserCan() || current_user_can('manage_options'))) {
            return;
        }

        $safe = self::safeAddress();

        ?>
        <div class="notice notice-warning cjw-safe-mode-notice">
            <p>
                <strong><?php esc_html_e('Dit is een kopie van de website: mail-veiligmodus staat aan.', 'cjw'); ?></strong>
                <?php echo esc_html(self::reason()); ?>
                <?php if ($safe !== '') : ?>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: e-mail address. */
                        __('Elke mail die de website zou versturen gaat in plaats daarvan naar %s, met [TEST] in het onderwerp. Ouders ontvangen niets.', 'cjw'),
                        $safe
                    ));
                    ?>
                <?php else : ?>
                    <?php esc_html_e('Er is geen geldig beheerdersadres, dus er wordt helemaal geen mail verstuurd.', 'cjw'); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * The panel on the Beheer tab.
     *
     * @return void
     */
    public static function renderPanel(): void
    {
        $active = self::isActive();
        $production = self::productionHost();
        $current = self::currentHost();
        $diverted = self::diverted();

        ?>
        <div class="cjw-tool-panel<?php echo $active ? ' cjw-tool-panel--warn' : ''; ?>">
            <h2><?php esc_html_e('Mail-veiligmodus', 'cjw'); ?></h2>

            <p class="description">
                <?php esc_html_e('Een teruggezette kopie van de website bevat echte inschrijvingen. Zodra de live website hier is bevestigd, herkent elke kopie zichzelf aan zijn andere webadres en stuurt hij geen mail meer naar ouders: alles gaat dan naar de beheerder, met [TEST] in het onderwerp.', 'cjw'); ?>
            </p>

            <table class="widefat striped cjw-tool-table">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'cjw'); ?></th>
                        <td>
                            <?php if ($active) : ?>
                                <strong><?php esc_html_e('Aan: dit is een kopie.', 'cjw'); ?></strong>
                                <?php echo esc_html(self::reason()); ?>
                            <?php else : ?>
                                <?php esc_html_e('Uit: mail wordt gewoon verstuurd.', 'cjw'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Deze website', 'cjw'); ?></th>
                        <td><code><?php echo esc_html($current); ?></code>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: WordPress environment type. */
                                __('(omgeving volgens WordPress: %s)', 'cjw'),
                                self::environment()
                            ));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Live website', 'cjw'); ?></th>
                        <td>
                            <?php if ($production !== '') : ?>
                                <code><?php echo esc_html($production); ?></code>
                            <?php else : ?>
                                <strong><?php esc_html_e('Nog niet bevestigd.', 'cjw'); ?></strong>
                                <?php esc_html_e('Tot die tijd wordt een kopie alleen herkend als de hostingpartij de omgeving als test heeft gemarkeerd.', 'cjw'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Tegengehouden mail', 'cjw'); ?></th>
                        <td>
                            <?php if ($diverted['count'] === 0) : ?>
                                <?php esc_html_e('Nog geen.', 'cjw'); ?>
                            <?php else : ?>
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %d: number of redirected mails. */
                                    _n('%d mail is doorgestuurd naar de beheerder.', '%d mails zijn doorgestuurd naar de beheerder.', $diverted['count'], 'cjw'),
                                    $diverted['count']
                                ));
                                ?>
                                <ul>
                                    <?php foreach ($diverted['last'] as $entry) : ?>
                                        <li>
                                            <?php
                                            echo esc_html(sprintf(
                                                /* translators: 1: date and time, 2: subject, 3: intended recipient. */
                                                __('%1$s: “%2$s” voor %3$s', 'cjw'),
                                                wp_date(__('j F Y \o\m H:i', 'cjw'), (int) $entry['time']),
                                                (string) $entry['subject'],
                                                (string) $entry['to']
                                            ));
                                            ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if ($production === '' || $production === $current) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                    <?php wp_nonce_field(self::ACTION_CONFIRM); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CONFIRM); ?>" />
                    <?php
                    submit_button(
                        $production === ''
                            ? sprintf(
                                /* translators: %s: this site's host name. */
                                __('Dit is de live website: %s', 'cjw'),
                                $current
                            )
                            : __('Bevestig opnieuw', 'cjw'),
                        $production === '' ? 'primary' : 'secondary',
                        'submit',
                        false
                    );
                    ?>
                </form>
            <?php endif; ?>

            <?php if ($production !== '') : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                    <?php wp_nonce_field(self::ACTION_FORGET); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_FORGET); ?>" />
                    <?php submit_button(__('Vergeet de live website', 'cjw'), 'secondary', 'submit', false); ?>
                    <p class="description"><?php esc_html_e('Alleen nodig als de website naar een ander webadres verhuist. Bevestig het nieuwe adres daarna meteen op de nieuwe live website.', 'cjw'); ?></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The intended recipients as one readable string.
     *
     * @param mixed $to Whatever wp_mail() was given.
     *
     * @return string
     */
    private static function recipients(mixed $to): string
    {
        $list = is_array($to) ? $to : explode(',', (string) $to);
        $list = array_values(array_filter(array_map(static fn($v): string => trim((string) $v), $list)));

        return implode(', ', $list);
    }

    /**
     * Headers without the Cc and Bcc lines, which would otherwise reach real
     * people past the redirect.
     *
     * @param mixed $headers String or array of header lines.
     *
     * @return array<int, string>|string Same shape as given.
     */
    private static function withoutCopies(mixed $headers): array|string
    {
        $isString = is_string($headers);
        $lines = $isString ? preg_split('/\r\n|\r|\n/', $headers) : (array) $headers;
        $lines = is_array($lines) ? $lines : [];

        $kept = array_values(array_filter($lines, static function (mixed $line): bool {
            return is_string($line) && preg_match('/^\s*b?cc\s*:/i', $line) !== 1;
        }));

        return $isString ? implode("\r\n", $kept) : $kept;
    }

    /**
     * Remembers a redirected mail.
     *
     * @param string $to      Intended recipients.
     * @param string $subject Original subject.
     *
     * @return void
     */
    private static function record(string $to, string $subject): void
    {
        $diverted = self::diverted();

        array_unshift($diverted['last'], [
            'to' => $to,
            'subject' => $subject,
            'time' => time(),
        ]);

        update_option(self::OPTION_DIVERTED, [
            'count' => $diverted['count'] + 1,
            'last' => array_slice($diverted['last'], 0, self::REMEMBER),
        ], false);
    }

    /**
     * Stops anyone without the camp capability, and checks the nonce.
     *
     * @param string $action The admin-post action being handled.
     *
     * @return void
     */
    private static function authorise(string $action): void
    {
        if (! CJW_Roles::currentUserCan() && ! current_user_can('manage_options')) {
            wp_die(esc_html__('Je hebt geen toestemming hiervoor.', 'cjw'));
        }

        check_admin_referer($action);
    }

    /**
     * Returns to the Beheer tab carrying a result flag.
     *
     * @param string $result Result flag the Beheer tab renders a notice for.
     *
     * @return never
     */
    private static function returnToBeheer(string $result): void
    {
        wp_safe_redirect(add_query_arg(
            [
                'page' => 'cjw-summer-camp',
                'tab' => 'beheer',
                'cjw_result' => $result,
            ],
            admin_url('admin.php')
        ));

        exit;
    }
}
