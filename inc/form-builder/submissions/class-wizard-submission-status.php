<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where a registration is in the process: new, confirmed, paid, cancelled.
 *
 * Registered as real post statuses rather than a meta field, so WordPress
 * renders the "Alle | Nieuw | Bevestigd | Betaald | Geannuleerd" filter links
 * above the list with live counts, and organisers work the screen they already
 * know instead of a bespoke one.
 */
final class CJW_Wizard_Submission_Status
{
    public const string NEW = 'cjw_new';
    public const string CONFIRMED = 'cjw_confirmed';
    public const string PAID = 'cjw_paid';
    public const string CANCELLED = 'cjw_cancelled';

    /**
     * The status a submission arrives in.
     */
    public const string DEFAULT = self::NEW;

    public function init(): void
    {
        add_action('init', [ $this, 'register' ]);
        add_action('add_meta_boxes', [ $this, 'register_box' ], 10, 2);
        add_action('save_post_' . CJW_Wizard_Submission_Repository::POST_TYPE, [ $this, 'save' ], 10, 2);
    }

    /**
     * Every status a submission can hold.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::NEW => __('Nieuw', 'cjw'),
            self::CONFIRMED => __('Bevestigd', 'cjw'),
            self::PAID => __('Betaald', 'cjw'),
            self::CANCELLED => __('Geannuleerd', 'cjw'),
        ];
    }

    /**
     * The statuses the "Alle" tab should show.
     *
     * Everything except cancelled, which gets its own tab -- the same treatment
     * WordPress gives the trash.
     *
     * @return array<int, string>
     */
    public static function inAllList(): array
    {
        return array_values(array_filter(
            array_keys(self::all()),
            static fn(string $status): bool => $status !== self::CANCELLED
        ));
    }

    /**
     * Statuses that count towards a camp being full.
     *
     * Cancelled does not, and neither does new: a registration nobody has
     * looked at yet is not a place taken.
     *
     * @return array<int, string>
     */
    public static function counted(): array
    {
        return [ self::CONFIRMED, self::PAID ];
    }

    public static function isKnown(string $status): bool
    {
        return array_key_exists($status, self::all());
    }

    /**
     * The label for a status, falling back to the raw value.
     */
    public static function label(string $status): string
    {
        return self::all()[ $status ] ?? $status;
    }

    /**
     * The admin colour for a status.
     *
     * Taken from the WordPress admin palette so the screen does not look like a
     * different product: blue for something needing attention, amber for in
     * progress, green for settled, grey for inactive.
     */
    public static function colour(string $status): string
    {
        return match ($status) {
            self::NEW => '#2271b1',
            self::CONFIRMED => '#dba617',
            self::PAID => '#00a32a',
            self::CANCELLED => '#8c8f94',
            default => '#8c8f94',
        };
    }

    /**
     * A status as a coloured pill, ready to echo.
     *
     * Returns escaped markup: a dot carries the state at a glance and the label
     * carries it for anyone who cannot rely on colour alone.
     */
    public static function badge(string $status): string
    {
        $colour = self::colour($status);

        return sprintf(
            '<span style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">'
            . '<span aria-hidden="true" style="width:9px;height:9px;border-radius:50%%;background:%1$s;flex:0 0 9px;"></span>'
            . '<span>%2$s</span></span>',
            esc_attr($colour),
            esc_html(self::label($status))
        );
    }

    /**
     * The count format WordPress shows beside each filter link.
     *
     * Spelled out per status because _n_noop() is read by the translation
     * scanner, which needs literal strings -- a concatenated label would be
     * invisible to it and never translated.
     *
     * @param string $status Status key.
     *
     * @return array<int|string, mixed>
     */
    private static function labelCount(string $status): array
    {
        return match ($status) {
            /* translators: %s: number of submissions with this status. */
            self::NEW => _n_noop('Nieuw <span class="count">(%s)</span>', 'Nieuw <span class="count">(%s)</span>', 'cjw'),
            /* translators: %s: number of submissions with this status. */
            self::CONFIRMED => _n_noop('Bevestigd <span class="count">(%s)</span>', 'Bevestigd <span class="count">(%s)</span>', 'cjw'),
            /* translators: %s: number of submissions with this status. */
            self::PAID => _n_noop('Betaald <span class="count">(%s)</span>', 'Betaald <span class="count">(%s)</span>', 'cjw'),
            /* translators: %s: number of submissions with this status. */
            default => _n_noop('Geannuleerd <span class="count">(%s)</span>', 'Geannuleerd <span class="count">(%s)</span>', 'cjw'),
        };
    }

    public function register(): void
    {
        foreach (self::all() as $status => $label) {
            register_post_status($status, [
                'label' => $label,
                'public' => false,
                'internal' => false,
                'exclude_from_search' => true,
                'show_in_admin_all_list' => $status !== self::CANCELLED,
                'show_in_admin_status_list' => true,
                'label_count' => self::labelCount($status),
            ]);
        }
    }

    /**
     * Puts the status where "Publiceren" used to be.
     *
     * A registration is not a post being written towards publication. The
     * Publish box offered a vocabulary that means nothing here -- concept,
     * revisies, zichtbaarheid, direct publiceren -- and buried the one control
     * that does mean something at the bottom of it. It is dropped, and its
     * genuinely useful parts (when it arrived, save, delete) move into a box
     * about status.
     *
     * @param string       $post_type Post type being edited.
     * @param WP_Post|null $post      The row, when WordPress passes one.
     */
    public function register_box(string $post_type = '', ?WP_Post $post = null): void
    {
        unset($post);

        if ($post_type !== CJW_Wizard_Submission_Repository::POST_TYPE) {
            return;
        }

        remove_meta_box('submitdiv', CJW_Wizard_Submission_Repository::POST_TYPE, 'side');

        add_meta_box(
            'cjw_wizard_entry_status',
            __('Status', 'cjw'),
            [ $this, 'render_box' ],
            CJW_Wizard_Submission_Repository::POST_TYPE,
            'side',
            'high'
        );
    }

    /**
     * The status box: when it arrived, what state it is in, save, delete.
     *
     * @param WP_Post $post Post being edited.
     */
    public function render_box(WP_Post $post): void
    {
        $current = self::isKnown($post->post_status) ? $post->post_status : self::DEFAULT;
        $timestamp = get_post_timestamp($post);
        $deleteLink = get_delete_post_link((int) $post->ID);
        $confirmation = CJW_Wizard_Submission_Confirmation::summary((int) $post->ID);
        $confirmationFailed = CJW_Wizard_Submission_Confirmation::failed((int) $post->ID);

        ?>
		<div class="submitbox" id="submitpost">
			<div id="minor-publishing">
				<?php if ($timestamp !== false) : ?>
					<div class="misc-pub-section">
						<?php esc_html_e('Ontvangen', 'cjw'); ?>:
						<strong><?php echo esc_html(wp_date(__('j F Y \o\m H:i', 'cjw'), $timestamp)); ?></strong>
					</div>
				<?php endif; ?>

				<?php if ($confirmation !== '') : ?>
					<div class="misc-pub-section">
						<?php esc_html_e('Bevestiging aan ouder', 'cjw'); ?>:
						<strong<?php echo $confirmationFailed ? ' style="color:#b32d2e;"' : ''; ?>>
							<?php
                            // Escaped by summary(); echoing it raw keeps the
                            // translated date from being escaped twice.
                            echo $confirmation; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</strong>
					</div>
				<?php endif; ?>

				<div class="misc-pub-section cjw-submission-status">
					<?php wp_nonce_field('cjw_submission_status', 'cjw_submission_status_nonce'); ?>
					<?php
                    // Handed straight back to WordPress. The status itself is
                    // changed below, after core has saved, so this only keeps
                    // core from deciding the row's status for itself.
                    ?>
					<input type="hidden" name="post_status" value="<?php echo esc_attr($post->post_status); ?>">

					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e('Status inschrijving', 'cjw'); ?></legend>
						<?php foreach (array_keys(self::all()) as $status) : ?>
							<label style="display:flex;align-items:center;gap:8px;margin:0 0 8px;">
								<input
									type="radio"
									name="cjw_submission_status"
									value="<?php echo esc_attr($status); ?>"
									<?php checked($current, $status); ?>
								>
								<?php
                                // Pre-escaped by the badge helper, which
                                // carries the label visibly -- so no
                                // screen-reader copy beside it, which would
                                // announce every option twice.
                                echo self::badge($status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</div>
			</div>

			<div id="major-publishing-actions">
				<?php if ($deleteLink !== null) : ?>
					<div id="delete-action">
						<a class="submitdelete deletion" href="<?php echo esc_url($deleteLink); ?>">
							<?php esc_html_e('Naar prullenbak', 'cjw'); ?>
						</a>
					</div>
				<?php endif; ?>

				<div id="publishing-action">
					<input
						type="submit"
						name="save"
						id="publish"
						class="button button-primary button-large"
						value="<?php esc_attr_e('Opslaan', 'cjw'); ?>"
					>
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<?php
    }

    /**
     * Stores the chosen status.
     *
     * @param int     $post_id Submission post id.
     * @param WP_Post $post    Submission.
     */
    public function save(int $post_id, WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST['cjw_submission_status_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['cjw_submission_status_nonce']))
            : '';

        if ($nonce === '' || ! wp_verify_nonce($nonce, 'cjw_submission_status')) {
            return;
        }

        if (! current_user_can(CJW_Roles::CAPABILITY)) {
            return;
        }

        $requested = isset($_POST['cjw_submission_status'])
            ? sanitize_key(wp_unslash((string) $_POST['cjw_submission_status']))
            : '';

        // A trashed submission keeps its status: an organiser deleted it, and a
        // status change is not an undelete.
        if (! self::isKnown($requested) || $requested === $post->post_status || $post->post_status === 'trash') {
            return;
        }

        // A payment recorded in the same save has already decided this, and
        // updating the post fired save_post again to get us here. Re-applying
        // the radio button now would undo the status the money just earned.
        if (class_exists('CJW_Wizard_Payments') && CJW_Wizard_Payments::statusWasApplied($post_id)) {
            return;
        }

        remove_action('save_post_' . CJW_Wizard_Submission_Repository::POST_TYPE, [ $this, 'save' ], 10);

        wp_update_post([
            'ID' => $post_id,
            'post_status' => $requested,
        ]);

        add_action('save_post_' . CJW_Wizard_Submission_Repository::POST_TYPE, [ $this, 'save' ], 10, 2);
    }
}
