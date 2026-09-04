<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The screen where payments are recorded.
 *
 * A box on the registration itself, because that is where somebody is standing
 * when a transfer turns up in the bank account and they go looking for who it
 * was from. And a line above the list, because "how much is still outstanding"
 * is a question about the whole camp year, not about one family, and answering
 * it by opening two hundred registrations is how it stops being answered.
 */
final class CJW_Wizard_Payments_Admin
{
    private const string NONCE = 'cjw_wizard_payments';

    /**
     * Registrations whose box has already been applied this request.
     *
     * Settling a balance changes the status, and changing the status fires
     * save_post again. Without this, the payment that caused it would be
     * recorded a second time.
     *
     * @var array<int, bool>
     */
    private static array $applied = [];

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('add_meta_boxes', [ $this, 'register_box' ], 10, 2);

        // After the status box has had its say at 10: an organiser who picks a
        // status by hand should not be overruled by arithmetic, so the ledger
        // only moves the status when the ledger itself changed.
        add_action('save_post_' . CJW_Wizard_Submission_Repository::POST_TYPE, [ $this, 'save' ], 20, 2);
        add_action('manage_posts_extra_tablenav', [ $this, 'render_totals' ]);
    }

    /**
     * Adds the box to the registration screen.
     *
     * @param string       $post_type Post type being edited.
     * @param WP_Post|null $post      The row, when WordPress passes one.
     *
     * @return void
     */
    public function register_box(string $post_type = '', ?WP_Post $post = null): void
    {
        unset($post);

        if ($post_type !== CJW_Wizard_Submission_Repository::POST_TYPE) {
            return;
        }

        add_meta_box(
            'cjw_wizard_payments',
            __('Betalingen', 'cjw'),
            [ $this, 'render_box' ],
            CJW_Wizard_Submission_Repository::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * The box: what is owed, what came in, and what is still open.
     *
     * @param WP_Post $post Registration being edited.
     *
     * @return void
     */
    public function render_box(WP_Post $post): void
    {
        $id = (int) $post->ID;
        $amount = CJW_Wizard_Payments::amount($id);
        $paid = CJW_Wizard_Payments::paid($id);
        $outstanding = $amount['due'] - $paid;
        $rows = CJW_Wizard_Payments::payments($id);
        $settled = CJW_Wizard_Payments::isSettled($id);

        wp_nonce_field(self::NONCE, self::NONCE . '_nonce');

        ?>
        <table class="widefat" style="border:0;margin:0 0 10px;">
            <tbody>
                <tr>
                    <th scope="row" style="padding-left:0;"><?php esc_html_e('Te betalen', 'cjw'); ?></th>
                    <td style="text-align:right;padding-right:0;">
                        <?php echo esc_html(CJW_Wizard_Payments::formatAmount($amount['due'])); ?>
                        <?php if ($amount['override'] !== null && $amount['override'] !== $amount['snapshot']) : ?>
                            <br><span style="color:#646970;">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %s: the amount the form worked out. */
                                    __('formulier: %s', 'cjw'),
                                    CJW_Wizard_Payments::formatAmount($amount['snapshot'])
                                ));
                                ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row" style="padding-left:0;"><?php esc_html_e('Ontvangen', 'cjw'); ?></th>
                    <td style="text-align:right;padding-right:0;"><?php echo esc_html(CJW_Wizard_Payments::formatAmount($paid)); ?></td>
                </tr>
                <tr>
                    <th scope="row" style="padding-left:0;"><?php esc_html_e('Openstaand', 'cjw'); ?></th>
                    <td style="text-align:right;padding-right:0;">
                        <strong style="color:<?php echo esc_attr($settled ? '#007017' : '#b32d2e'); ?>;">
                            <?php echo esc_html(CJW_Wizard_Payments::formatAmount($outstanding)); ?>
                        </strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php if ($rows !== []) : ?>
            <ul style="margin:0 0 10px;">
                <?php foreach ($rows as $row) : ?>
                    <li style="display:flex;align-items:baseline;gap:6px;margin:0 0 4px;">
                        <label style="display:flex;align-items:baseline;gap:6px;">
                            <input type="checkbox" name="cjw_payment_delete[]" value="<?php echo esc_attr($row['id']); ?>">
                            <span>
                                <strong><?php echo esc_html(CJW_Wizard_Payments::formatAmount($row['cents'])); ?></strong>
                                <?php echo esc_html(CJW_Wizard_Payments::methods()[ $row['method'] ] ?? $row['method']); ?>
                                ·
                                <?php echo esc_html($this->readableDate($row['date'])); ?>
                                <?php if ($row['note'] !== '') : ?>
                                    <br><span style="color:#646970;"><?php echo esc_html($row['note']); ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="description" style="margin:0 0 10px;">
                <?php esc_html_e('Vink aan en sla op om een betaling te verwijderen.', 'cjw'); ?>
            </p>
        <?php endif; ?>

        <p style="margin:0 0 6px;"><strong><?php esc_html_e('Betaling toevoegen', 'cjw'); ?></strong></p>

        <p style="margin:0 0 6px;">
            <label for="cjw_payment_amount" class="screen-reader-text"><?php esc_html_e('Bedrag', 'cjw'); ?></label>
            <input
                type="text"
                id="cjw_payment_amount"
                name="cjw_payment_amount"
                value=""
                class="widefat"
                inputmode="decimal"
                autocomplete="off"
                placeholder="<?php esc_attr_e('Bedrag, bijvoorbeeld 72,50', 'cjw'); ?>"
            >
        </p>

        <p style="margin:0 0 6px;display:flex;gap:6px;">
            <label for="cjw_payment_date" class="screen-reader-text"><?php esc_html_e('Datum', 'cjw'); ?></label>
            <input type="date" id="cjw_payment_date" name="cjw_payment_date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>">

            <label for="cjw_payment_method" class="screen-reader-text"><?php esc_html_e('Manier', 'cjw'); ?></label>
            <select id="cjw_payment_method" name="cjw_payment_method">
                <?php foreach (CJW_Wizard_Payments::methods() as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <p style="margin:0 0 10px;">
            <label for="cjw_payment_note" class="screen-reader-text"><?php esc_html_e('Notitie', 'cjw'); ?></label>
            <input
                type="text"
                id="cjw_payment_note"
                name="cjw_payment_note"
                value=""
                class="widefat"
                autocomplete="off"
                placeholder="<?php esc_attr_e('Notitie, bijvoorbeeld “aan de poort”', 'cjw'); ?>"
            >
        </p>

        <p style="margin:0;">
            <label for="cjw_payment_due"><?php esc_html_e('Te betalen aanpassen', 'cjw'); ?></label>
            <input
                type="text"
                id="cjw_payment_due"
                name="cjw_payment_due"
                value="<?php echo esc_attr($amount['override'] === null ? '' : number_format_i18n($amount['override'] / 100, 2)); ?>"
                class="widefat"
                inputmode="decimal"
                autocomplete="off"
                placeholder="<?php echo esc_attr(number_format_i18n($amount['snapshot'] / 100, 2)); ?>"
            >
            <span class="description">
                <?php esc_html_e('Alleen invullen als er een ander bedrag geldt dan het formulier uitrekende, bijvoorbeeld bij het minimabeleid. Leeg laten om het bedrag van het formulier te gebruiken.', 'cjw'); ?>
            </span>
        </p>
        <?php
    }

    /**
     * Records what was typed into the box.
     *
     * @param int     $post_id Registration post id.
     * @param WP_Post $post    The registration.
     *
     * @return void
     */
    public function save(int $post_id, WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST[ self::NONCE . '_nonce' ])
            ? sanitize_text_field(wp_unslash((string) $_POST[ self::NONCE . '_nonce' ]))
            : '';

        if ($nonce === '' || ! wp_verify_nonce($nonce, self::NONCE)) {
            return;
        }

        if (! current_user_can(CJW_Roles::CAPABILITY)) {
            return;
        }

        if (isset(self::$applied[ $post_id ])) {
            return;
        }

        self::$applied[ $post_id ] = true;

        $changed = false;

        foreach ($this->deletions() as $paymentId) {
            $changed = CJW_Wizard_Payments::remove($post_id, $paymentId) || $changed;
        }

        $changed = $this->addSubmittedPayment($post_id) || $changed;
        $changed = $this->applySubmittedDue($post_id) || $changed;

        if (! $changed) {
            return;
        }

        // Only when the ledger moved. A status somebody chose by hand is their
        // decision; arithmetic overruling it on every save would make the radio
        // buttons above look broken.
        $status = CJW_Wizard_Payments::statusFor($post_id, (string) $post->post_status);

        if ($status === $post->post_status) {
            return;
        }

        remove_action('save_post_' . CJW_Wizard_Submission_Repository::POST_TYPE, [ $this, 'save' ], 20);

        wp_update_post([
            'ID' => $post_id,
            'post_status' => $status,
        ]);

        add_action('save_post_' . CJW_Wizard_Submission_Repository::POST_TYPE, [ $this, 'save' ], 20, 2);
    }

    /**
     * The summary above the list: one camp year's books.
     *
     * @param string $which Which end of the table WordPress is rendering.
     *
     * @return void
     */
    public function render_totals(string $which): void
    {
        if ($which !== 'top' || ! $this->onSubmissionList()) {
            return;
        }

        $year = CJW_Wizard_Submission_Repository::currentCampYear();
        $totals = CJW_Wizard_Payments::totalsForYear($year);

        if ($totals['registrations'] < 1) {
            return;
        }

        ?>
        <div class="cjw-payment-totals" style="clear:both;padding:8px 0;color:#3c434a;">
            <?php
            echo esc_html(sprintf(
                /* translators: 1: camp year, 2: amount owed, 3: amount received. */
                __('Kampjaar %1$s: %2$s te betalen, %3$s ontvangen', 'cjw'),
                $year,
                CJW_Wizard_Payments::formatAmount($totals['due']),
                CJW_Wizard_Payments::formatAmount($totals['paid'])
            ));
            ?>
            <?php if ($totals['outstanding'] > 0) : ?>
                — <strong style="color:#b32d2e;">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: outstanding amount, 2: number of registrations. */
                        _n(
                            '%1$s openstaand bij %2$d inschrijving',
                            '%1$s openstaand bij %2$d inschrijvingen',
                            $totals['unpaid'],
                            'cjw'
                        ),
                        CJW_Wizard_Payments::formatAmount($totals['outstanding']),
                        $totals['unpaid']
                    ));
                    ?>
                </strong>
            <?php else : ?>
                — <strong style="color:#007017;"><?php esc_html_e('alles voldaan', 'cjw'); ?></strong>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The payment cell for the list column.
     *
     * @param int $submissionId Registration post id.
     *
     * @return string Escaped markup.
     */
    public static function cell(int $submissionId): string
    {
        $amount = CJW_Wizard_Payments::amount($submissionId);

        if ($amount['due'] < 1) {
            return '<span style="color:#646970;">—</span>';
        }

        $outstanding = $amount['due'] - CJW_Wizard_Payments::paid($submissionId);

        if ($outstanding <= 0) {
            return sprintf(
                '<span style="color:#007017;white-space:nowrap;">%s</span>',
                esc_html(sprintf(
                    /* translators: %s: amount received. */
                    __('voldaan · %s', 'cjw'),
                    CJW_Wizard_Payments::formatAmount($amount['due'])
                ))
            );
        }

        return sprintf(
            '<span style="white-space:nowrap;">%1$s</span><br><span style="color:#b32d2e;white-space:nowrap;">%2$s</span>',
            esc_html(sprintf(
                /* translators: 1: amount received, 2: amount owed. */
                __('%1$s van %2$s', 'cjw'),
                CJW_Wizard_Payments::formatAmount(CJW_Wizard_Payments::paid($submissionId)),
                CJW_Wizard_Payments::formatAmount($amount['due'])
            )),
            esc_html(sprintf(
                /* translators: %s: outstanding amount. */
                __('%s open', 'cjw'),
                CJW_Wizard_Payments::formatAmount($outstanding)
            ))
        );
    }

    /**
     * The payment ids ticked for removal.
     *
     * @return array<int, string>
     */
    private function deletions(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save(), the only caller; every value is sanitised below.
        $raw = isset($_POST['cjw_payment_delete']) ? wp_unslash($_POST['cjw_payment_delete']) : [];

        return array_values(array_filter(array_map(
            static fn($value): string => sanitize_text_field((string) $value),
            is_array($raw) ? $raw : []
        )));
    }

    /**
     * Records the payment typed into the box, if one was.
     *
     * @param int $post_id Registration post id.
     *
     * @return bool Whether a payment was added.
     */
    private function addSubmittedPayment(int $post_id): bool
    {
        $raw = $this->posted('cjw_payment_amount');

        if (trim($raw) === '') {
            return false;
        }

        $cents = CJW_Wizard_Payments::parseAmount($raw);

        if ($cents === null) {
            return false;
        }

        $method = $this->posted('cjw_payment_method');

        return CJW_Wizard_Payments::add(
            $post_id,
            $cents,
            $method !== '' ? sanitize_key($method) : CJW_Wizard_Payments::METHOD_TRANSFER,
            $this->posted('cjw_payment_date'),
            $this->posted('cjw_payment_note')
        );
    }

    /**
     * Applies a corrected amount owed, or clears the correction.
     *
     * @param int $post_id Registration post id.
     *
     * @return bool Whether the amount owed changed.
     */
    private function applySubmittedDue(int $post_id): bool
    {
        if (! $this->wasPosted('cjw_payment_due')) {
            return false;
        }

        $raw = $this->posted('cjw_payment_due');
        $before = CJW_Wizard_Payments::amount($post_id);

        if (trim($raw) === '') {
            if ($before['override'] === null) {
                return false;
            }

            CJW_Wizard_Payments::setDue($post_id, null);

            return true;
        }

        $cents = CJW_Wizard_Payments::parseAmount($raw);

        if ($cents === null || $cents === $before['override']) {
            return false;
        }

        CJW_Wizard_Payments::setDue($post_id, $cents);

        return true;
    }

    /**
     * One field of the posted box, sanitised.
     *
     * Gathered here rather than read in three places, so the note that the
     * nonce was checked in save() sits next to the one access that needs it.
     *
     * @param string $key Field name.
     *
     * @return string
     */
    private function posted(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save(), the only caller.
        if (! isset($_POST[ $key ]) || ! is_scalar($_POST[ $key ])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
        return sanitize_text_field(wp_unslash((string) $_POST[ $key ]));
    }

    /**
     * Whether a field was in the request at all.
     *
     * An empty correction field means "use the form's figure", which is a
     * different instruction from not having the box on screen.
     *
     * @param string $key Field name.
     *
     * @return bool
     */
    private function wasPosted(string $key): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save(), the only caller.
        return isset($_POST[ $key ]);
    }

    /**
     * Whether the screen being rendered is the registrations list.
     *
     * @return bool
     */
    private function onSubmissionList(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen instanceof WP_Screen
            && $screen->id === 'edit-' . CJW_Wizard_Submission_Repository::POST_TYPE;
    }

    /**
     * A stored date as a person reads it.
     *
     * @param string $date Y-m-d.
     *
     * @return string
     */
    private function readableDate(string $date): string
    {
        $time = strtotime($date);

        return $time === false ? $date : wp_date((string) get_option('date_format'), $time);
    }
}
