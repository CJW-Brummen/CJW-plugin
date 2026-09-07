<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What a family owes, and what has actually come in.
 *
 * "Betaald" used to be a single status: one radio button, on or off. A family
 * paying half now and half at the drive, a grandparent transferring for one
 * child, a note of ten euros in cash at the gate -- none of it could be
 * recorded, so it lived in somebody's head or in a spreadsheet nobody else
 * could see.
 *
 * The amount owed was worse than unrecorded: it was recomputed from the
 * payload every time it was shown. The retention sweep erases that payload,
 * so a camp year's figures quietly turned into zero the moment its
 * registrations were anonymised -- the books rewriting themselves a year
 * later. The amount is now frozen at submission time, in cents, and survives
 * anonymisation because a number of euros identifies nobody.
 *
 * Cents throughout. The camp's own prices are whole euros, but a payment is
 * whatever arrived in the bank account, and a ledger that cannot hold 72,50 is
 * a ledger somebody keeps twice.
 */
final class CJW_Wizard_Payments
{
    /**
     * What the registration came to, frozen when it was taken.
     *
     * Survives anonymisation: it is money, not a person.
     */
    public const string META_AMOUNT = '_cjw_wizard_amount';

    /**
     * The payments received against it, one row each.
     */
    public const string META_PAYMENTS = '_cjw_wizard_payments';

    public const string METHOD_TRANSFER = 'overboeking';

    public const string METHOD_CASH = 'contant';

    public const string METHOD_IDEAL = 'ideal';

    public const string METHOD_OTHER = 'anders';

    /**
     * How a payment can arrive, in the words an organiser would use.
     *
     * @return array<string, string>
     */
    public static function methods(): array
    {
        return [
            self::METHOD_TRANSFER => __('Overboeking', 'cjw'),
            self::METHOD_CASH => __('Contant', 'cjw'),
            self::METHOD_IDEAL => __('iDEAL', 'cjw'),
            self::METHOD_OTHER => __('Anders', 'cjw'),
        ];
    }

    /**
     * Records what this registration comes to, at the moment it is taken.
     *
     * @param int   $submissionId The registration just stored.
     * @param array $children     Child records.
     * @param array $payload      Submitted answers.
     * @param array<int, array<string, string>> $children
     * @param array<string, mixed>              $payload
     *
     * @return void
     */
    public static function freeze(int $submissionId, array $children, array $payload): void
    {
        if ($submissionId < 1) {
            return;
        }

        $amount = CJW_Wizard_Amount::of($children, $payload);

        update_post_meta($submissionId, self::META_AMOUNT, [
            'camp' => (int) $amount['camp'] * 100,
            'donation' => (int) $amount['donation'] * 100,
            'total' => (int) $amount['total'] * 100,
            'unpriced' => (int) $amount['unpriced'],
        ]);
    }

    /**
     * What this registration comes to, in cents.
     *
     * `due` is what the organisers are actually asking for: the frozen total,
     * unless somebody has corrected it -- a reduced rate under the
     * minimabeleid, a sibling discount, a cancelled child. `snapshot` is what
     * the form worked out at the time, kept beside it so a correction is
     * visible as a correction rather than replacing history.
     *
     * @param int $submissionId Registration post id.
     *
     * @return array{camp: int, donation: int, snapshot: int, due: int, override: ?int, unpriced: int}
     */
    public static function amount(int $submissionId): array
    {
        $stored = get_post_meta($submissionId, self::META_AMOUNT, true);
        $stored = is_array($stored) ? $stored : [];

        $snapshot = (int) ($stored['total'] ?? 0);
        $override = isset($stored['override']) ? (int) $stored['override'] : null;

        return [
            'camp' => (int) ($stored['camp'] ?? 0),
            'donation' => (int) ($stored['donation'] ?? 0),
            'snapshot' => $snapshot,
            'due' => $override ?? $snapshot,
            'override' => $override,
            'unpriced' => (int) ($stored['unpriced'] ?? 0),
        ];
    }

    /**
     * Corrects what is being asked for, or drops the correction.
     *
     * @param int      $submissionId Registration post id.
     * @param int|null $cents        The corrected amount, or null to use the snapshot again.
     *
     * @return void
     */
    public static function setDue(int $submissionId, ?int $cents): void
    {
        $stored = get_post_meta($submissionId, self::META_AMOUNT, true);
        $stored = is_array($stored) ? $stored : [];

        if ($cents === null) {
            unset($stored['override']);
        } else {
            $stored['override'] = max(0, $cents);
        }

        update_post_meta($submissionId, self::META_AMOUNT, $stored);
    }

    /**
     * Every payment received, oldest first.
     *
     * @param int $submissionId Registration post id.
     *
     * @return array<int, array{id: string, date: string, cents: int, method: string, note: string, ref: string, by: int, at: int}>
     */
    public static function payments(int $submissionId): array
    {
        $stored = get_post_meta($submissionId, self::META_PAYMENTS, true);
        $rows = [];

        foreach (is_array($stored) ? $stored : [] as $row) {
            if (! is_array($row) || ! isset($row['cents'])) {
                continue;
            }

            $rows[] = [
                'id' => (string) ($row['id'] ?? ''),
                'date' => (string) ($row['date'] ?? ''),
                'cents' => (int) $row['cents'],
                'method' => array_key_exists((string) ($row['method'] ?? ''), self::methods())
                    ? (string) $row['method']
                    : self::METHOD_OTHER,
                'note' => (string) ($row['note'] ?? ''),
                'ref' => (string) ($row['ref'] ?? ''),
                'by' => (int) ($row['by'] ?? 0),
                'at' => (int) ($row['at'] ?? 0),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => [ $a['date'], $a['at'] ] <=> [ $b['date'], $b['at'] ]);

        return $rows;
    }

    /**
     * Records one payment.
     *
     * A negative amount is allowed and means a refund: money that went back,
     * which is a thing that happens and which a ledger that only adds up
     * cannot express. Zero is refused -- it records nothing and would sit in
     * the list forever.
     *
     * @param int    $submissionId Registration post id.
     * @param int    $cents        Amount received.
     * @param string $method       One of the METHOD_ constants.
     * @param string $date         Date received, as Y-m-d.
     * @param string $note         Free text, e.g. "aan de poort".
     * @param string $reference    The provider's own identifier, when a
     *                             provider was involved. Survives
     *                             anonymisation: it is how a line in the bank
     *                             statement is matched to a row here.
     *
     * @return bool Whether anything was recorded.
     */
    public static function add(
        int $submissionId,
        int $cents,
        string $method,
        string $date = '',
        string $note = '',
        string $reference = ''
    ): bool {
        if ($submissionId < 1 || $cents === 0) {
            return false;
        }

        $stored = get_post_meta($submissionId, self::META_PAYMENTS, true);
        $rows = is_array($stored) ? $stored : [];

        $rows[] = [
            'id' => self::newId(),
            'date' => self::normaliseDate($date),
            'cents' => $cents,
            'method' => array_key_exists($method, self::methods()) ? $method : self::METHOD_OTHER,
            'note' => $note,
            'ref' => $reference,
            'by' => get_current_user_id(),
            'at' => time(),
        ];

        update_post_meta($submissionId, self::META_PAYMENTS, $rows);

        return true;
    }

    /**
     * Removes one payment, for the one that was typed in twice.
     *
     * @param int    $submissionId Registration post id.
     * @param string $paymentId    Row id.
     *
     * @return bool Whether a row was removed.
     */
    public static function remove(int $submissionId, string $paymentId): bool
    {
        $stored = get_post_meta($submissionId, self::META_PAYMENTS, true);
        $rows = is_array($stored) ? $stored : [];

        $kept = array_values(array_filter(
            $rows,
            static fn($row): bool => ! is_array($row) || (string) ($row['id'] ?? '') !== $paymentId
        ));

        if (count($kept) === count($rows)) {
            return false;
        }

        update_post_meta($submissionId, self::META_PAYMENTS, $kept);

        return true;
    }

    /**
     * Whether a payment carrying this reference is already recorded.
     *
     * The webhook that reports a payment can arrive more than once -- Mollie
     * retries for a day when anything goes wrong at this end -- and a ledger
     * that adds the same money twice is worse than one that misses it.
     *
     * @param int    $submissionId Registration post id.
     * @param string $reference    Provider identifier.
     *
     * @return bool
     */
    public static function hasReference(int $submissionId, string $reference): bool
    {
        if ($reference === '') {
            return false;
        }

        foreach (self::payments($submissionId) as $row) {
            if ($row['ref'] === $reference) {
                return true;
            }
        }

        return false;
    }

    /**
     * What has come in, in cents.
     *
     * @param int $submissionId Registration post id.
     *
     * @return int
     */
    public static function paid(int $submissionId): int
    {
        $total = 0;

        foreach (self::payments($submissionId) as $row) {
            $total += $row['cents'];
        }

        return $total;
    }

    /**
     * What is still owed, in cents. Negative means overpaid.
     *
     * @param int $submissionId Registration post id.
     *
     * @return int
     */
    public static function outstanding(int $submissionId): int
    {
        return self::amount($submissionId)['due'] - self::paid($submissionId);
    }

    /**
     * Whether this registration is square.
     *
     * A registration with nothing to pay is not settled -- there was never
     * anything to settle -- which keeps a form with no prices in it from
     * marking every arrival as paid.
     *
     * @param int $submissionId Registration post id.
     *
     * @return bool
     */
    public static function isSettled(int $submissionId): bool
    {
        return self::amount($submissionId)['due'] > 0 && self::outstanding($submissionId) <= 0;
    }

    /**
     * The status the ledger implies, given the one the row has now.
     *
     * Only ever moves between "betaald" and "bevestigd", and only when there
     * is an amount to go on. A cancelled registration is left alone: somebody
     * decided that, and a payment arriving afterwards is a refund to arrange,
     * not an undo.
     *
     * @param int    $submissionId Registration post id.
     * @param string $current      Status the row holds now.
     *
     * @return string The status it should hold.
     */
    public static function statusFor(int $submissionId, string $current): string
    {
        if ($current === CJW_Wizard_Submission_Status::CANCELLED || $current === 'trash') {
            return $current;
        }

        if (self::amount($submissionId)['due'] < 1) {
            return $current;
        }

        if (self::isSettled($submissionId)) {
            return CJW_Wizard_Submission_Status::PAID;
        }

        return $current === CJW_Wizard_Submission_Status::PAID
            ? CJW_Wizard_Submission_Status::CONFIRMED
            : $current;
    }

    /**
     * Registrations whose status the ledger has decided this request.
     *
     * @var array<int, bool>
     */
    private static array $statusApplied = [];

    /**
     * Whether the ledger has already set this registration's status now.
     *
     * The status box reads this. Updating a post from inside save_post fires
     * save_post again, and on that second pass the box would re-apply the
     * radio button that was submitted -- putting "Bevestigd" back over the
     * "Betaald" the payment just earned.
     *
     * @param int $submissionId Registration post id.
     *
     * @return bool
     */
    public static function statusWasApplied(int $submissionId): bool
    {
        return isset(self::$statusApplied[ $submissionId ]);
    }

    /**
     * Moves a registration to the status its balance implies.
     *
     * The one place the transition happens, because there is more than one way
     * for money to arrive -- a webhook from Mollie, a transfer typed in by
     * hand, a refund -- and each of them forgetting to move the status is how
     * a paid family ends up being chased.
     *
     * Deliberately not called from add() itself. Recording a payment happens
     * during save_post on the registration screen, and updating the post from
     * inside that fires save_post again: the status box would then re-apply
     * whatever radio button was submitted and undo this. So the callers that
     * are not in the middle of a save call it, and the one that is does it
     * once at the end of its own handler.
     *
     * @param int $submissionId Registration post id.
     *
     * @return bool Whether the status changed.
     */
    public static function applyStatus(int $submissionId): bool
    {
        $post = get_post($submissionId);

        if (! $post instanceof WP_Post || $post->post_type !== CJW_Wizard_Submission_Repository::POST_TYPE) {
            return false;
        }

        $status = self::statusFor($submissionId, (string) $post->post_status);

        if ($status === $post->post_status) {
            return false;
        }

        // Set before the update, so the save_post this triggers already sees it.
        self::$statusApplied[ $submissionId ] = true;

        wp_update_post([
            'ID' => $submissionId,
            'post_status' => $status,
        ]);

        return true;
    }

    /**
     * Drops what an organiser typed, and keeps the money.
     *
     * Called when a registration is anonymised. The amounts, dates and methods
     * are the camp's own books and identify nobody; a note is free text
     * somebody wrote, and "contant van de buurvrouw" is exactly the kind of
     * sentence the retention promise covers.
     *
     * @param int $submissionId Registration post id.
     *
     * @return void
     */
    public static function anonymise(int $submissionId): void
    {
        $rows = self::payments($submissionId);

        if ($rows === []) {
            return;
        }

        update_post_meta($submissionId, self::META_PAYMENTS, array_map(
            static function (array $row): array {
                // The reference stays: it identifies a transaction, not a
                // person, and it is what reconciles this row against a bank
                // statement long after the names have gone.
                $row['note'] = '';
                $row['by'] = 0;

                return $row;
            },
            $rows
        ));
    }

    /**
     * Reads an amount the way somebody types it.
     *
     * "72,50", "72.50", "€ 72,50", "1.234,50" and "72" all mean what they look
     * like. Returns null when there is no number in there at all, so a
     * mistyped field is refused rather than silently recorded as nothing.
     *
     * @param string $raw What was typed.
     *
     * @return int|null Cents.
     */
    public static function parseAmount(string $raw): ?int
    {
        $clean = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';

        if ($clean === '' || preg_match('/[0-9]/', $clean) !== 1) {
            return null;
        }

        $negative = str_starts_with(trim($clean), '-');
        $clean = str_replace('-', '', $clean);

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');
        $separator = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

        if ($separator < 0) {
            $cents = ((int) $clean) * 100;

            return $negative ? -$cents : $cents;
        }

        $decimals = substr($clean, $separator + 1);

        // Two digits after the last separator is a decimal fraction; anything
        // else is a thousands separator -- "1.234" is one thousand, not 1,23.
        if (strlen($decimals) !== 2) {
            $cents = ((int) preg_replace('/[,.]/', '', $clean)) * 100;

            return $negative ? -$cents : $cents;
        }

        $whole = (int) preg_replace('/[,.]/', '', substr($clean, 0, $separator));
        $cents = $whole * 100 + (int) $decimals;

        return $negative ? -$cents : $cents;
    }

    /**
     * An amount as a person reads it.
     *
     * @param int $cents Amount.
     *
     * @return string
     */
    public static function formatAmount(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';

        return $sign . '€ ' . number_format_i18n(abs($cents) / 100, 2);
    }

    /**
     * The books for one camp year.
     *
     * Reads the frozen amounts rather than the payloads, which is the whole
     * point: the figures still add up after the sweep has been through.
     *
     * @param string $year Camp year, four digits.
     *
     * @return array{registrations: int, due: int, paid: int, outstanding: int, unpaid: int}
     */
    public static function totalsForYear(string $year): array
    {
        $totals = [
            'registrations' => 0,
            'due' => 0,
            'paid' => 0,
            'outstanding' => 0,
            'unpaid' => 0,
        ];

        if (preg_match('/^\d{4}$/', $year) !== 1) {
            return $totals;
        }

        $submissions = get_posts([
            'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
            // Cancelled registrations are not money anybody is waiting for.
            'post_status' => CJW_Wizard_Submission_Status::inAllList(),
            // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- Bounded by the size of a camp year; the same ceiling consentCounts() uses.
            'numberposts' => 500,
            'fields' => 'ids',
            'no_found_rows' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One camp year's registrations, on an admin screen.
            'meta_query' => [
                [
                    'key' => CJW_Wizard_Submission_Repository::META_CAMP_YEAR,
                    'value' => $year,
                ],
            ],
        ]);

        foreach ($submissions as $submissionId) {
            $id = (int) $submissionId;
            $due = self::amount($id)['due'];

            if ($due < 1) {
                continue;
            }

            $paid = self::paid($id);

            ++$totals['registrations'];
            $totals['due'] += $due;
            $totals['paid'] += $paid;

            if ($due - $paid > 0) {
                ++$totals['unpaid'];
            }
        }

        $totals['outstanding'] = $totals['due'] - $totals['paid'];

        return $totals;
    }

    /**
     * A date that is a date, defaulting to today.
     *
     * @param string $date Whatever was submitted.
     *
     * @return string Y-m-d.
     */
    private static function normaliseDate(string $date): string
    {
        $date = trim($date);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return wp_date('Y-m-d') ?: gmdate('Y-m-d');
    }

    /**
     * An id for one row, so it can be pointed at later.
     *
     * @return string
     */
    private static function newId(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (Exception) {
            return (string) time();
        }
    }
}
