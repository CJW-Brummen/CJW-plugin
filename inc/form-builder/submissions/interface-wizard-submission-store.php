<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The slice of submission storage the privacy tools need.
 *
 * Narrow on purpose. An access or erasure request only has to find a person's
 * submissions, read one, and scrub one -- and depending on that instead of on
 * the whole repository lets the pagination be tested without a database.
 *
 * That matters here more than it usually would: the eraser's contract with
 * WordPress is a page-incrementing loop over a result set the eraser itself
 * shrinks, and that interaction is arithmetic, not storage. It is exactly the
 * kind of bug a live-only test finds late and an in-memory one catches on the
 * first run.
 */
interface CJW_Wizard_Submission_Store
{
    /**
     * Every submission belonging to one e-mail address, newest first.
     *
     * Must not return rows that have already been anonymised: they no longer
     * hold the address, and the eraser relies on them dropping out.
     *
     * @param string $email Address to match.
     *
     * @return array<int, object>
     */
    public function get_by_email(string $email): array;

    /**
     * Strips a submission of everything identifying, keeping the row.
     *
     * @param int $submission_id Submission post id.
     *
     * @return bool Whether anything was removed.
     */
    public function anonymise(int $submission_id): bool;

    /**
     * The submitted answers for one submission.
     *
     * @param int $submission_id Submission post id.
     *
     * @return array<string, mixed>
     */
    public function get_payload(int $submission_id): array;

    /**
     * Submissions taken before a cut-off that still hold personal data.
     *
     * Must skip rows already anonymised, or every sweep would rewrite the whole
     * archive instead of only what has just expired.
     *
     * @param DateTimeImmutable $before Anything older than this has expired.
     * @param int               $limit  Maximum rows to return.
     *
     * @return array<int, object>
     */
    public function get_expired(DateTimeImmutable $before, int $limit = 100): array;

    /**
     * Flattens nested answers into dot-notation columns.
     *
     * @param array  $data   Nested answers.
     * @param string $prefix Column prefix while recursing.
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public function flatten_data(array $data, string $prefix = ''): array;
}
