<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gives the settings screen the undo that every page on the site already has.
 *
 * All camp settings live in one option row. An option has no revisions: someone
 * clears the hero text or pastes over the prices, saves, and the previous values
 * are simply gone. That is a strange gap on a site where every ordinary page
 * keeps its full history, and the screen without history is the one that
 * controls the homepage.
 *
 * Snapshots are taken on save rather than on a schedule, so what is kept is
 * exactly the set of states somebody deliberately produced.
 */
final class CJW_Summer_Camp_Settings_History
{
    /**
     * Where the snapshots live.
     */
    public const string OPTION_KEY = 'cjw_summer_camp_settings_history';

    /**
     * The admin-post action behind the restore button.
     */
    public const string ACTION_RESTORE = 'cjw_restore_settings';

    /**
     * The admin-post action behind the export button.
     */
    public const string ACTION_EXPORT = 'cjw_export_settings';

    /**
     * The admin-post action behind the import form.
     */
    public const string ACTION_IMPORT = 'cjw_import_settings';

    /**
     * How many snapshots to keep.
     *
     * Ten covers the realistic accident -- noticing a week later that the price
     * is wrong -- without turning one option row into an unbounded archive of
     * every save anyone ever made.
     */
    public const int KEEP = 10;

    /**
     * A caller-supplied name for the snapshot the next save will produce.
     *
     * Set by whoever is about to write the option, so the history says
     * "Archief van kampjaar 2026" rather than "Instellingen opgeslagen". It has
     * to work this way round: the snapshot is taken inside update_option, so a
     * caller wanting to name it cannot also take it without producing two.
     *
     * @var string
     */
    private static string $next_reason = '';

    /**
     * Names the snapshot the next settings write will produce.
     *
     * @param string $reason Short description, in Dutch.
     *
     * @return void
     */
    public static function nameNextSnapshot(string $reason): void
    {
        self::$next_reason = $reason;
    }

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        // Priority 1: before anything else can filter the value being stored,
        // so the snapshot is of what was actually saved last time, not of a
        // half-updated row.
        add_filter('pre_update_option_' . CJW_Summer_Camp_Service::OPTION_KEY, [$instance, 'snapshotBeforeUpdate'], 1, 2);

        add_action('admin_post_' . self::ACTION_RESTORE, [$instance, 'handleRestore']);
        add_action('admin_post_' . self::ACTION_EXPORT, [$instance, 'handleExport']);
        add_action('admin_post_' . self::ACTION_IMPORT, [$instance, 'handleImport']);
    }

    /**
     * Snapshots the outgoing value whenever the settings are written.
     *
     * Hooked on `pre_update_option_*` rather than on the settings form, because
     * that catches every writer: the settings screen, the new-camp-year action,
     * WP-CLI, and anything a future feature adds. A history with holes in it is
     * worse than no history, because it invites trust it cannot honour.
     *
     * @param mixed $value     The value about to be stored.
     * @param mixed $old_value The value being replaced.
     *
     * @return mixed The value, unchanged.
     */
    public function snapshotBeforeUpdate(mixed $value, mixed $old_value): mixed
    {
        if (!is_array($old_value) || $old_value === []) {
            return $value;
        }

        // WordPress does not call this filter when nothing changed, but a
        // caller can. Storing an identical snapshot would push a genuinely
        // different state off the end of the list.
        if ($value === $old_value) {
            return $value;
        }

        $reason = self::$next_reason !== '' ? self::$next_reason : $this->describeCurrentWriter();
        self::$next_reason = '';

        $this->store($old_value, $reason);

        return $value;
    }

    /**
     * Adds a snapshot to the top of the list and drops the oldest.
     *
     * @param array<string, mixed> $settings Settings to keep.
     * @param string               $reason   Short description of what caused it.
     *
     * @return void
     */
    public function store(array $settings, string $reason = ''): void
    {
        $user = wp_get_current_user();

        $snapshots = self::all();

        array_unshift($snapshots, [
            'time' => time(),
            'by' => $user instanceof WP_User && $user->ID > 0 ? $user->display_name : __('Systeem', 'cjw'),
            'reason' => $reason,
            'settings' => $settings,
        ]);

        // autoload false: this can hold ten copies of every camp setting, and
        // nothing on the front end ever reads it.
        update_option(self::OPTION_KEY, array_slice($snapshots, 0, self::KEEP), false);
    }

    /**
     * Every stored snapshot, newest first.
     *
     * @return array<int, array{time: int, by: string, reason: string, settings: array<string, mixed>}>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            return [];
        }

        $snapshots = [];

        foreach ($stored as $snapshot) {
            if (!is_array($snapshot) || !isset($snapshot['settings']) || !is_array($snapshot['settings'])) {
                continue;
            }

            $snapshots[] = [
                'time' => (int) ($snapshot['time'] ?? 0),
                'by' => (string) ($snapshot['by'] ?? ''),
                'reason' => (string) ($snapshot['reason'] ?? ''),
                'settings' => $snapshot['settings'],
            ];
        }

        return $snapshots;
    }

    /**
     * Restores one snapshot.
     *
     * @return void
     */
    public function handleRestore(): void
    {
        $this->assertPermitted();
        check_admin_referer(self::ACTION_RESTORE);

        $index = isset($_POST['snapshot']) ? absint(wp_unslash($_POST['snapshot'])) : -1;
        $snapshots = self::all();

        if (!array_key_exists($index, $snapshots)) {
            $this->returnToBeheer('restore_failed');
        }

        // Not a special path: this goes through update_option like any other
        // save, so the state being replaced is itself snapshotted first. An
        // undo you cannot undo is a second way to lose the same work.
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, $snapshots[$index]['settings']);

        $this->returnToBeheer('restored');
    }

    /**
     * Sends the current settings as a JSON download.
     *
     * @return void
     */
    public function handleExport(): void
    {
        $this->assertPermitted();
        check_admin_referer(self::ACTION_EXPORT);

        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);

        $payload = [
            'plugin' => 'cjw',
            'type' => 'summer-camp-settings',
            'version' => defined('CJW_VERSION') ? CJW_VERSION : '',
            'exported' => gmdate('c'),
            'site' => home_url('/'),
            'settings' => is_array($settings) ? $settings : [],
        ];

        $filename = sprintf('zomerkamp-instellingen-%s.json', wp_date('Y-m-d'));

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        exit;
    }

    /**
     * Reads an uploaded JSON file back into the settings.
     *
     * @return void
     */
    public function handleImport(): void
    {
        $this->assertPermitted();
        check_admin_referer(self::ACTION_IMPORT);

        $settings = $this->readUploadedSettings();

        if ($settings === null) {
            $this->returnToBeheer('import_failed');
        }

        $current = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);
        $current = is_array($current) ? $current : [];

        // Merged over the current values rather than replacing them wholesale:
        // an export from an older version is missing whatever has been added
        // since, and a straight replace would silently blank those fields.
        //
        // The merge happens before sanitising, not after. sanitize() returns
        // every known key, filling absent ones with defaults -- so sanitising
        // first and merging second would hand array_merge a complete array of
        // mostly-empty values and overwrite everything the file did not carry.
        $merged = array_merge($current, $settings);

        // Run through the same sanitiser the settings form uses, so an imported
        // file cannot put anything in the option that typing could not.
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, CJW_Summer_Camp_Service::sanitize($merged));

        $this->returnToBeheer('imported');
    }

    /**
     * Validates and decodes the uploaded file.
     *
     * Returns the values as they were written, unsanitised: the caller merges
     * them over the current settings first, and sanitises the result.
     *
     * @return array<string, mixed>|null The settings it carried, or null when
     *                                   the upload is not one of ours.
     */
    private function readUploadedSettings(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handleImport() calls check_admin_referer() before reaching here.
        if (!isset($_FILES['cjw_settings_file']) || !is_array($_FILES['cjw_settings_file'])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce checked in handleImport(); the only value read from this array is a temp path, which is PHP's own and is validated by is_uploaded_file() below.
        $upload = $_FILES['cjw_settings_file'];

        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $path = (string) ($upload['tmp_name'] ?? '');

        if ($path === '' || !is_uploaded_file($path)) {
            return null;
        }

        // A settings export is a few kilobytes. Anything larger is not one, and
        // reading it into memory to find that out is the wrong order.
        if ((int) ($upload['size'] ?? 0) > MB_IN_BYTES) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local upload temp file, not a URL; wp_remote_get() cannot read it.
        $raw = file_get_contents($path);

        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (
            !is_array($decoded)
            || ($decoded['type'] ?? '') !== 'summer-camp-settings'
            || !isset($decoded['settings'])
            || !is_array($decoded['settings'])
        ) {
            return null;
        }

        return $decoded['settings'];
    }

    /**
     * How many settings a snapshot would change if it were restored.
     *
     * The list is otherwise a wall of identical timestamps, and the only
     * question anyone actually has in front of it is "how far back do I have to
     * go".
     *
     * @param array<string, mixed> $settings Snapshot settings.
     *
     * @return int
     */
    public static function differenceCount(array $settings): int
    {
        $current = get_option(CJW_Summer_Camp_Service::OPTION_KEY, []);
        $current = is_array($current) ? $current : [];

        $keys = array_unique(array_merge(array_keys($current), array_keys($settings)));
        $changed = 0;

        foreach ($keys as $key) {
            if (($current[$key] ?? null) !== ($settings[$key] ?? null)) {
                ++$changed;
            }
        }

        return $changed;
    }

    /**
     * Renders the history and file panels on the Beheer tab.
     *
     * @return void
     */
    public static function renderPanel(): void
    {
        $snapshots = self::all();

        ?>
        <div class="cjw-tool-panel">
            <h2><?php esc_html_e('Eerdere versies van de instellingen', 'cjw'); ?></h2>

            <p class="description">
                <?php
                echo esc_html(sprintf(
                    /* translators: %d: number of snapshots kept. */
                    __('Elke keer dat de instellingen worden opgeslagen, bewaart de website de vorige versie. De laatste %d versies staan hieronder. Terugzetten kan altijd ongedaan gemaakt worden: ook dat wordt bewaard.', 'cjw'),
                    self::KEEP
                ));
                ?>
            </p>

            <?php if ($snapshots === []) : ?>
                <p><em><?php esc_html_e('Er zijn nog geen eerdere versies. Die verschijnen zodra de instellingen voor het eerst opnieuw worden opgeslagen.', 'cjw'); ?></em></p>
            <?php else : ?>
                <table class="widefat striped cjw-tool-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Opgeslagen op', 'cjw'); ?></th>
                            <th scope="col"><?php esc_html_e('Door', 'cjw'); ?></th>
                            <th scope="col"><?php esc_html_e('Aanleiding', 'cjw'); ?></th>
                            <th scope="col"><?php esc_html_e('Verschil met nu', 'cjw'); ?></th>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actie', 'cjw'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snapshots as $index => $snapshot) : ?>
                            <?php $differences = self::differenceCount($snapshot['settings']); ?>
                            <tr>
                                <td><?php echo esc_html(wp_date(__('j F Y \o\m H:i', 'cjw'), $snapshot['time'])); ?></td>
                                <td><?php echo esc_html($snapshot['by'] !== '' ? $snapshot['by'] : '—'); ?></td>
                                <td><?php echo esc_html($snapshot['reason'] !== '' ? $snapshot['reason'] : '—'); ?></td>
                                <td>
                                    <?php if ($differences === 0) : ?>
                                        <?php esc_html_e('gelijk aan nu', 'cjw'); ?>
                                    <?php else : ?>
                                        <?php
                                        echo esc_html(sprintf(
                                            /* translators: %d: number of settings that differ. */
                                            _n('%d instelling anders', '%d instellingen anders', $differences, 'cjw'),
                                            $differences
                                        ));
                                        ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field(self::ACTION_RESTORE); ?>
                                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESTORE); ?>" />
                                        <input type="hidden" name="snapshot" value="<?php echo esc_attr((string) $index); ?>" />
                                        <button
                                            type="submit"
                                            class="button"
                                            <?php echo $differences === 0 ? 'disabled' : ''; ?>
                                            onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Weet je zeker dat je deze versie wilt terugzetten? De huidige instellingen worden bewaard, dus je kunt dit ongedaan maken.', 'cjw'))); ?>);"
                                        >
                                            <?php esc_html_e('Zet terug', 'cjw'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="cjw-tool-panel">
            <h2><?php esc_html_e('Instellingen als bestand', 'cjw'); ?></h2>

            <p class="description">
                <?php esc_html_e('Bewaar een kopie van alle instellingen buiten de website, of zet een eerder bewaarde kopie terug. Handig voor een verhuizing naar een andere server, of om een werkende configuratie veilig te stellen voordat je gaat experimenteren.', 'cjw'); ?>
            </p>

            <div class="cjw-tool-form">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::ACTION_EXPORT); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_EXPORT); ?>" />
                    <?php submit_button(__('Download de instellingen', 'cjw'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <div class="cjw-tool-form">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field(self::ACTION_IMPORT); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_IMPORT); ?>" />
                    <label for="cjw-settings-file"><?php esc_html_e('Zet een bestand terug', 'cjw'); ?></label>
                    <input type="file" id="cjw-settings-file" name="cjw_settings_file" accept="application/json,.json" required />
                    <?php submit_button(__('Lees het bestand in', 'cjw'), 'secondary', 'submit', false); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Names what caused a snapshot, for the list.
     *
     * @return string
     */
    private function describeCurrentWriter(): string
    {
        // WP-CLI defines this as true and never as anything else, so its mere
        // presence is the check.
        if (defined('WP_CLI')) {
            return __('WP-CLI', 'cjw');
        }

        return is_admin() ? __('Instellingen opgeslagen', 'cjw') : __('Automatisch', 'cjw');
    }

    /**
     * Stops anyone without the camp capability.
     *
     * @return void
     */
    private function assertPermitted(): void
    {
        if (!CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Je hebt geen toestemming om deze actie uit te voeren.', 'cjw'));
        }
    }

    /**
     * Returns to the Beheer tab carrying a result flag.
     *
     * @param string $result Result key the panel renders a notice for.
     *
     * @return never
     */
    private function returnToBeheer(string $result): void
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
