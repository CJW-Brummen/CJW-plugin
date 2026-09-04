<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Submission_Repository implements CJW_Wizard_Submission_Store
{
    /**
     * The submissions post type.
     *
     * WordPress caps post type names at 20 characters. The original name,
     * `cjw_wizard_submission`, was 21, so register_post_type() always returned
     * post_type_length_invalid and the submissions screen never existed --
     * submissions were saved to wp_posts and were simply unreachable in
     * wp-admin. Any name set here must stay within the limit;
     * PostTypeNameTest asserts it.
     */
    public const string POST_TYPE = 'cjw_wizard_entry';

    public const string META_WIZARD_ID = '_cjw_wizard_id';
    public const string META_PAYLOAD = '_cjw_wizard_payload';
    public const string META_SNAPSHOT = '_cjw_wizard_snapshot';

    /**
     * The registering parent's e-mail address, lifted out of the payload.
     *
     * WordPress's privacy tools answer an access or deletion request by e-mail
     * address, but a payload is keyed by whatever the form happens to ask, so
     * there is nothing to look the person up by. Recording the address once at
     * submission time turns both requests into an exact meta query instead of a
     * scan through every stored payload.
     */
    public const string META_EMAIL = '_cjw_wizard_email';

    /**
     * Camp year, kept so anonymised rows can still be counted.
     */
    public const string META_CAMP_YEAR = '_cjw_wizard_camp_year';

    /**
     * Date the row was stripped of personal data, if it has been.
     */
    public const string META_ANONYMISED = '_cjw_wizard_anonymised';

    /**
     * The child's name, lifted out of the payload via its field role.
     *
     * Personal data: removed by anonymise() along with everything else.
     */
    public const string META_CHILD_NAME = '_cjw_wizard_child_name';

    /**
     * Which camp the registration is for. Not personal, so it survives
     * anonymisation and keeps the yearly counts meaningful.
     */
    public const string META_CAMP_TYPE = '_cjw_wizard_camp_type';

    /**
     * Internal notes an organiser adds. Never shown to the parent.
     */
    public const string META_NOTES = '_cjw_wizard_notes';

    /**
     * Photo consent, one row per child, as 'ja' or 'nee'.
     *
     * The answer has always been in the payload, which is a JSON blob: readable
     * on one registration at a time and queryable never. "Which children may
     * not be photographed" is a question somebody asks with a camera in their
     * hand, on a field, before a photo goes on the site -- so it is indexed,
     * the way the camp type is.
     *
     * One row per child rather than one joined value, because siblings differ
     * and a filter has to match a registration where any one of them said no.
     */
    public const string META_PHOTO_CONSENT = '_cjw_wizard_photo_consent';

    /**
     * A child's identity for duplicate detection: one row per child.
     *
     * A hash of the normalised name and date of birth, not the pair itself.
     * Equality is the only thing duplicate detection needs, and a hash answers
     * that without adding a second readable copy of a child's name and birthday
     * to the database. It is cleared on anonymisation all the same: it is
     * derived from personal data and it links two rows together.
     */
    public const string META_CHILD_KEY = '_cjw_wizard_child_key';

    /**
     * The earlier registration this one appears to repeat.
     *
     * Set at submission time and shown in the admin list. Nothing is refused
     * and the parent is told nothing: two children can share a name, a parent
     * can be correcting a mistake, and a form that argues with somebody halfway
     * through is worse than a list a kampbeheerder can glance at.
     */
    public const string META_DUPLICATE_OF = '_cjw_wizard_duplicate_of';

    /**
     * Every status a submission we still hold data for can be in.
     *
     * Spelled out rather than using `'any'`, which sounds like "everything" and
     * is not: WP_Query expands `any` to every status that is not
     * `exclude_from_search`, and register_post_status() defaults that flag to
     * the status's `internal` flag -- which trash sets. So `'any'` silently
     * omits the trash, and an organiser deleting a registration in wp-admin
     * would move a child's name, address and medical notes somewhere the
     * retention sweep and an erasure request could both never reach.
     *
     * @var array<int, string>
     */
    private const array ALL_STATUSES = [
        CJW_Wizard_Submission_Status::NEW,
        CJW_Wizard_Submission_Status::CONFIRMED,
        CJW_Wizard_Submission_Status::PAID,
        CJW_Wizard_Submission_Status::CANCELLED,
        'publish',
        'draft',
        'pending',
        'private',
        'future',
        'trash',
    ];

    /**
     * Stores a completed registration and everything needed to read it back.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    public function save(int $wizard_id, array $config, array $data): int
    {
        $childNames = CJW_Wizard_Field_Roles::childNames($config, $data);

        $submission_id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => CJW_Wizard_Submission_Status::DEFAULT,
            'post_title' => __('Inschrijving', 'cjw'),
        ], true);

        if (is_wp_error($submission_id)) {
            return 0;
        }

        $this->name_after_itself((int) $submission_id);

        update_post_meta($submission_id, self::META_WIZARD_ID, $wizard_id);
        update_post_meta($submission_id, self::META_PAYLOAD, wp_json_encode($data, JSON_UNESCAPED_UNICODE));
        update_post_meta($submission_id, self::META_SNAPSHOT, wp_json_encode($config, JSON_UNESCAPED_UNICODE));
        update_post_meta($submission_id, self::META_CAMP_YEAR, $this->campYear());

        $email = self::findEmail($config, $data);

        if ($email !== '') {
            update_post_meta($submission_id, self::META_EMAIL, $email);
        }

        if ($childNames !== []) {
            update_post_meta($submission_id, self::META_CHILD_NAME, implode(', ', $childNames));
        }

        // One row per camp, not one joined string: a submission can hold two
        // children going to different camps, and filtering by camp has to match
        // either of them.
        foreach (array_unique(CJW_Wizard_Field_Roles::valuesFor($config, $data, CJW_Wizard_Field_Roles::CAMP_TYPE)) as $campType) {
            add_post_meta($submission_id, self::META_CAMP_TYPE, $campType);
        }

        $this->indexChildren((int) $submission_id, CJW_Wizard_Field_Roles::childRows($config, $data));

        return (int) $submission_id;
    }

    /**
     * How many children this camp year answered each way about photos.
     *
     * Counted over the consent rows rather than over registrations: a family of
     * three where one child said no is one registration and three children, and
     * the number that matters is the children.
     *
     * @return array{ja: int, nee: int}
     */
    public static function consentCounts(): array
    {
        $counts = [
            'ja' => 0,
            'nee' => 0,
        ];

        foreach (get_posts(self::thisCampYearQuery()) as $submission_id) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reading every row of one key, which is the point.
            $answers = get_post_meta((int) $submission_id, self::META_PHOTO_CONSENT, false);

            foreach (is_array($answers) ? $answers : [] as $answer) {
                $answer = (string) $answer;

                if (isset($counts[ $answer ])) {
                    ++$counts[ $answer ];
                }
            }
        }

        return $counts;
    }

    /**
     * The names of this camp year's children whose parents said no to photos.
     *
     * For whoever is choosing photos for the website: the counts say how many
     * children must not appear, this says which. Anonymised registrations have
     * no name left to give and are skipped, so after the retention sweep the
     * count can exceed this list.
     *
     * @return array<int, string>
     */
    public function childrenWithoutConsent(): array
    {
        $names = [];

        foreach (get_posts(self::thisCampYearQuery()) as $submission_id) {
            $id = (int) $submission_id;

            if ($this->is_anonymised($id)) {
                continue;
            }

            $snapshot = get_post_meta($id, self::META_SNAPSHOT, true);
            $config = is_string($snapshot) ? json_decode($snapshot, true) : null;

            if (! is_array($config)) {
                continue;
            }

            foreach (CJW_Wizard_Field_Roles::childRows($config, $this->get_payload($id)) as $child) {
                if ($child['name'] !== '' && self::consentValue($child['photo_consent']) === 'nee') {
                    $names[] = $child['name'];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every registration of the current camp year, as ids.
     *
     * @return array<string, mixed> get_posts() arguments.
     */
    private static function thisCampYearQuery(): array
    {
        return [
            'post_type' => self::POST_TYPE,
            // Not 'any'. See ALL_STATUSES: WP_Query expands it to every status
            // that is not exclude_from_search, which silently drops the custom
            // ones this post type actually uses -- so this counted nothing at
            // all. Trash is left out on purpose here: a deleted registration is
            // not a child anybody is photographing.
            'post_status' => array_keys(CJW_Wizard_Submission_Status::all()),
            // A camp year is a few hundred registrations; this is a ceiling
            // rather than a pagination scheme.
            // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- See above: bounded by the size of a camp year.
            'numberposts' => 500,
            'fields' => 'ids',
            'no_found_rows' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One camp year's registrations; the alternative is counting nothing.
            'meta_query' => [
                [
                    'key' => self::META_CAMP_YEAR,
                    'value' => self::currentCampYear(),
                ],
            ],
        ];
    }

    /**
     * Writes the per-child rows that make a registration answerable.
     *
     * @param int $submission_id Registration just stored.
     * @param array<int, array{name: string, birth_date: string, camp: string, photo_consent: string}> $rows Children on it.
     * @return void
     */
    private function indexChildren(int $submission_id, array $rows): void
    {
        $keys = [];

        foreach ($rows as $row) {
            add_post_meta($submission_id, self::META_PHOTO_CONSENT, self::consentValue($row['photo_consent']));

            $key = self::childKey($row['name'], $row['birth_date']);

            if ($key !== '' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
                add_post_meta($submission_id, self::META_CHILD_KEY, $key);
            }
        }

        $earlier = $this->earlierRegistrationFor($submission_id, $keys);

        if ($earlier > 0) {
            update_post_meta($submission_id, self::META_DUPLICATE_OF, $earlier);
        }
    }

    /**
     * Photo consent as one of two words.
     *
     * A checkbox arrives as '1', 'on', 'ja' or missing entirely depending on how
     * the field was built, and an unchecked box sends nothing at all. Anything
     * that is not recognisably a yes is a no: consent has to be given, and a
     * value nobody understands is not consent.
     *
     * @param mixed $raw Whatever the form submitted.
     * @return string
     */
    public static function consentValue($raw): string
    {
        if (is_array($raw)) {
            $raw = reset($raw);
        }

        $value = strtolower(trim((string) $raw));

        return in_array($value, [ '1', 'on', 'true', 'ja', 'yes', 'akkoord' ], true) ? 'ja' : 'nee';
    }

    /**
     * A child's identity, as a hash of name and date of birth.
     *
     * Normalised first, because "Sanne  de Vries" and "sanne de vries" are the
     * same child and a duplicate that only matches on an exact string is a
     * duplicate check that finds nothing. Answers an empty string when either
     * half is missing -- a name on its own is not enough to call two children
     * the same person.
     *
     * @param string $name Child's full name as entered.
     * @param string $birth_date Date of birth as entered.
     * @return string
     */
    public static function childKey(string $name, string $birth_date): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', remove_accents($name)) ?? '');
        $date = self::normaliseDate($birth_date);

        if ($name === '' || $date === '') {
            return '';
        }

        return sha1(strtolower($name) . '|' . $date);
    }

    /**
     * A date of birth as Y-m-d, whatever shape it arrived in.
     *
     * @param string $value Date as entered.
     * @return string Empty when it cannot be read as a date.
     */
    private static function normaliseDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        foreach ([ 'Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d' ] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return '';
    }

    /**
     * The oldest registration in this camp year holding any of these children.
     *
     * Scoped to the camp year on purpose: the same child registering again next
     * summer is not a duplicate, it is next summer. Anonymised rows are skipped
     * because their keys are gone.
     *
     * @param int $submission_id The registration being stored.
     * @param array<int, string> $keys Child keys on it.
     * @return int Zero when there is no earlier match.
     */
    private function earlierRegistrationFor(int $submission_id, array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $query = [
            'post_type' => self::POST_TYPE,
            // Not 'any' -- see consentCounts(). Trash is excluded deliberately:
            // if somebody already deleted the earlier registration, this one is
            // the only one there is and calling it a duplicate is wrong.
            'post_status' => array_keys(CJW_Wizard_Submission_Status::all()),
            'numberposts' => 2,
            'orderby' => 'ID',
            'order' => 'ASC',
            'exclude' => [ $submission_id ],
            'fields' => 'ids',
            'no_found_rows' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs once per submission, over one camp year.
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => self::META_CAMP_YEAR,
                    'value' => $this->campYear(),
                ],
                [
                    'key' => self::META_CHILD_KEY,
                    'value' => $keys,
                    'compare' => 'IN',
                ],
            ],
        ];

        $matches = get_posts($query);

        return $matches === [] ? 0 : (int) $matches[0];
    }

    /**
     * Titles a submission after its own id.
     *
     * The children's names used to be the title, which read well but put
     * personal data into the post title and the slug derived from it -- so
     * every erasure had to rewrite both, and the name lived on in any URL that
     * had been copied out. The number identifies the registration just as well
     * for the one job a title has here: being quoted in a mail or over the
     * phone. Who is actually coming is answered by the Naam column and the
     * detail page, both of which read the payload.
     *
     * The id only exists after the insert, hence the second write.
     *
     * @param int $submission_id Submission post id.
     */
    private function name_after_itself(int $submission_id): void
    {
        wp_update_post([
            'ID' => $submission_id,
            'post_title' => sprintf(
                /* translators: %d: submission number. */
                __('Inschrijving #%d', 'cjw'),
                $submission_id
            ),
            'post_name' => 'inschrijving-' . $submission_id,
        ]);
    }

    /**
     * Finds the registering parent's e-mail address in a submission.
     *
     * The field the builder marks as "E-mailadres van de ouder" is the answer
     * when the form has one: it is the only reading that does not depend on
     * field order. Without the role, prefers a field the form declares as
     * `email`, in the order the form asks them, so a parent's address wins over
     * a second contact's. Falls back to the first value that looks like an
     * address at all, for forms built before the field type existed or where
     * the builder used a plain text field.
     *
     * @param array $config Form configuration.
     * @param array $data   Submitted payload.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    public static function findEmail(array $config, array $data): string
    {
        $declared = CJW_Wizard_Field_Roles::valueFor($config, $data, CJW_Wizard_Field_Roles::PARENT_EMAIL);

        if (is_email($declared)) {
            return sanitize_email($declared);
        }

        $flat = (new self())->flatten_data($data);

        foreach (self::emailFieldKeys($config['steps'] ?? []) as $key) {
            foreach ($flat as $column => $value) {
                if (($column === $key || str_ends_with($column, ".{$key}")) && is_email($value)) {
                    return sanitize_email($value);
                }
            }
        }

        foreach ($flat as $value) {
            if (is_email($value)) {
                return sanitize_email($value);
            }
        }

        return '';
    }

    /**
     * Keys of every field the form declares as an e-mail field, in form order.
     *
     * @param mixed $steps Configured steps.
     *
     * @return array<int, string>
     */
    private static function emailFieldKeys(mixed $steps): array
    {
        $keys = [];

        if (! is_array($steps)) {
            return $keys;
        }

        foreach ($steps as $step) {
            $keys = [ ...$keys, ...self::emailKeysInFields(is_array($step['fields'] ?? null) ? $step['fields'] : []) ];
        }

        return $keys;
    }

    /**
     * Collects e-mail field keys from one level of fields and everything nested
     * under it.
     *
     * @param array $fields Field configuration.
     * @param array<int, mixed> $fields
     *
     * @return array<int, string>
     */
    private static function emailKeysInFields(array $fields): array
    {
        $keys = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (($field['type'] ?? '') === 'email' && ($field['key'] ?? '') !== '') {
                $keys[] = (string) $field['key'];
            }

            if (is_array($field['fields'] ?? null)) {
                $keys = [ ...$keys, ...self::emailKeysInFields($field['fields']) ];
            }

            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                if (is_array($option) && is_array($option['fields'] ?? null)) {
                    $keys = [ ...$keys, ...self::emailKeysInFields($option['fields']) ];
                }
            }
        }

        return $keys;
    }

    /**
     * The camp year to file this submission under.
     */
    private function campYear(): string
    {
        return self::currentCampYear();
    }

    /**
     * The answers a parent gave, as they were submitted.
     *
     * @return array<string, mixed>
     */
    public function get_payload(int $submission_id): array
    {
        $payload = get_post_meta($submission_id, self::META_PAYLOAD, true);

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * The form configuration as it was when this registration was made.
     *
     * A form edited afterwards must not change what an old submission means, so
     * every screen and every mail reads the snapshot rather than the live form.
     *
     * @return array<string, mixed>
     */
    public function get_config(int $submission_id): array
    {
        $snapshot = get_post_meta($submission_id, self::META_SNAPSHOT, true);
        $config = is_string($snapshot) ? json_decode($snapshot, true) : null;

        return is_array($config) ? $config : [];
    }

    /**
     * Registrations filed under one camp year that still hold personal data.
     *
     * Exactly that year, unlike get_for_camp_year_upto(): the birthday mail is
     * about the children coming to this camp, not everyone who ever came.
     *
     * @return WP_Post[]
     */
    public function get_for_camp_year(string $year, int $limit = 500): array
    {
        if (!preg_match('/^\d{4}$/', $year)) {
            return [];
        }

        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::ALL_STATUSES,
            'numberposts' => $limit,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs once a day on cron, over one camp year.
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => self::META_CAMP_YEAR,
                    'value' => $year,
                ],
                [
                    'key' => self::META_ANONYMISED,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);
    }

    /**
     * The camp year now running, for callers that file or read by it.
     */
    public static function currentCampYear(): string
    {
        if (function_exists('cjw_summer_camp')) {
            $start = cjw_summer_camp()->getStartDate();

            if ($start instanceof DateTimeImmutable) {
                return $start->format('Y');
            }
        }

        return wp_date('Y');
    }

    public function get_wizard_id(int $submission_id): int
    {
        return (int) get_post_meta($submission_id, self::META_WIZARD_ID, true);
    }

    /**
     * Every registration made through one form, newest first.
     *
     * @return array<int, WP_Post>
     */
    public function get_by_wizard(int $wizard_id): array
    {
        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::ALL_STATUSES,
            'numberposts' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Submissions are stored as a custom post type and scoped by the owning form id.
            'meta_key' => self::META_WIZARD_ID,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- This admin/export query intentionally filters submissions for one form.
            'meta_value' => (string) $wizard_id,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Every submission belonging to one e-mail address.
     *
     * @param string $email Address to match.
     *
     * @return array<int, WP_Post>
     */
    public function get_by_email(string $email): array
    {
        $email = sanitize_email($email);

        if (! is_email($email)) {
            return [];
        }

        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::ALL_STATUSES,
            'numberposts' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An access or erasure request is by definition a lookup by address; the meta exists for exactly this.
            'meta_key' => self::META_EMAIL,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
            'meta_value' => $email,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Strips a submission of everything that identifies anybody, keeping the row.
     *
     * The camp legitimately wants to know it ran for 74 children in 2026 long
     * after it has any business holding their names. Deleting the row would take
     * that history with it, so the payload, the config snapshot and the address
     * go, and the date, the form it belonged to and the camp year stay.
     *
     * Idempotent: anonymising an already-anonymised row changes nothing.
     *
     * @param int $submission_id Submission post id.
     *
     * @return bool Whether anything was removed.
     */
    public function anonymise(int $submission_id): bool
    {
        if ($this->is_anonymised($submission_id)) {
            return false;
        }

        delete_post_meta($submission_id, self::META_PAYLOAD);
        delete_post_meta($submission_id, self::META_SNAPSHOT);
        delete_post_meta($submission_id, self::META_EMAIL);
        delete_post_meta($submission_id, self::META_CHILD_NAME);
        delete_post_meta($submission_id, self::META_NOTES);
        // Derived from a name and a birthday, and it links two registrations to
        // each other. It goes when they do.
        delete_post_meta($submission_id, self::META_CHILD_KEY);
        delete_post_meta($submission_id, self::META_DUPLICATE_OF);
        // META_CAMP_TYPE and META_PHOTO_CONSENT deliberately survive: neither
        // identifies anybody, and they are what keeps "74 registrations for the
        // 2026 week camp" and "12 of them said no to photos" answerable after
        // the personal data is gone.

        update_post_meta($submission_id, self::META_ANONYMISED, wp_date('Y-m-d'));

        // post_status is passed back unchanged: wp_update_post() would otherwise
        // restore a trashed row to draft, undoing an organiser's deletion as a
        // side effect of scrubbing it.
        // The slug is rewritten too. Rows saved before titles became numbers
        // still carry a slug derived from a child's name, and replacing only
        // the title would leave that name behind in post_name.
        wp_update_post([
            'ID' => $submission_id,
            'post_status' => get_post_status($submission_id),
            'post_name' => 'cjw-entry-' . $submission_id,
            'post_title' => sprintf(
                /* translators: %s: camp year the anonymised submission belonged to. */
                __('Gewiste inschrijving (%s)', 'cjw'),
                $this->get_camp_year($submission_id)
            ),
        ]);

        return true;
    }

    /**
     * Whether a submission has already had its personal data removed.
     */
    public function is_anonymised(int $submission_id): bool
    {
        return (string) get_post_meta($submission_id, self::META_ANONYMISED, true) !== '';
    }

    /**
     * The camp year a submission belongs to.
     */
    public function get_camp_year(int $submission_id): string
    {
        return (string) get_post_meta($submission_id, self::META_CAMP_YEAR, true);
    }

    /**
     * The child's name, if the form marked a field with that role.
     */
    public function get_child_name(int $submission_id): string
    {
        return (string) get_post_meta($submission_id, self::META_CHILD_NAME, true);
    }

    /**
     * Which camp the registration is for, if the form marked a field.
     */
    public function get_camp_type(int $submission_id): string
    {
        // Deliberately not $single: a submission can hold two children going to
        // different camps, so every row matters.
        $types = get_post_meta($submission_id, self::META_CAMP_TYPE, false);

        return is_array($types) ? implode(', ', array_map('strval', $types)) : '';
    }

    /**
     * Internal notes on a submission.
     */
    public function get_notes(int $submission_id): string
    {
        return (string) get_post_meta($submission_id, self::META_NOTES, true);
    }

    /**
     * The distinct camp types that have actually been registered for.
     *
     * Read from the submissions rather than from a fixed list, because what a
     * form calls its camps is the form's business -- one might offer "week" and
     * "weekend", another "Kamp 1" and "Kamp 2".
     *
     * @return array<int, string>
     */
    public function known_camp_types(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinct values of one meta key; no core API returns that. Cached in the transient below.
        $cached = get_transient('cjw_wizard_camp_types');

        if (is_array($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
        $types = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value ASC",
                self::META_CAMP_TYPE
            )
        );

        $types = array_map('strval', is_array($types) ? $types : []);

        set_transient('cjw_wizard_camp_types', $types, HOUR_IN_SECONDS);

        return $types;
    }

    /**
     * The stored e-mail address for a submission, if it still has one.
     */
    public function get_email(int $submission_id): string
    {
        return (string) get_post_meta($submission_id, self::META_EMAIL, true);
    }

    /**
     * Submissions still holding personal data that were taken before a cut-off.
     *
     * @param DateTimeImmutable $before Anything older than this is expired.
     * @param int               $limit  Maximum rows to return.
     *
     * @return array<int, WP_Post>
     */
    public function get_expired(DateTimeImmutable $before, int $limit = 100): array
    {
        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::ALL_STATUSES,
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'ASC',
            'date_query' => [
                [
                    'before' => $before->format('Y-m-d H:i:s'),
                    'inclusive' => true,
                ],
            ],
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The sweep must find rows that have never been anonymised, and absence of a meta key is the only way to express that. It runs once a day on cron, batched.
            'meta_query' => [
                [
                    'key' => self::META_ANONYMISED,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);
    }

    /**
     * Registrations belonging to a camp year or any year before it, that still
     * hold personal data.
     *
     * Used when a new camp year is started: last year's registrations have done
     * their job by then, and keeping children's details on the chance that
     * somebody might want them is exactly what the retention rules exist to
     * stop. Retention still runs on its own schedule; this is the same rule
     * applied at the moment an organiser says the season is over.
     *
     * @param string $year  Camp year, four digits.
     * @param int    $limit Rows to return.
     *
     * @return WP_Post[]
     */
    public function get_for_camp_year_upto(string $year, int $limit = 100): array
    {
        if (!preg_match('/^\d{4}$/', $year)) {
            return [];
        }

        return get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::ALL_STATUSES,
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs when an organiser starts a new camp year, batched, at most a few times a year.
            'meta_query' => $this->campYearUptoQuery($year),
        ]);
    }

    /**
     * This camp year's registrations, counted per camp.
     *
     * Cancelled rows are left out, unlike count_since(): a running total is
     * read as "how many are coming", and a withdrawn registration is not.
     *
     * The counts do not necessarily add up to the total, and that is not a bug:
     * one registration can cover two children going to different camps, so it
     * is counted under both. The total counts registrations; the breakdown
     * counts places.
     *
     * @param string $year Camp year, four digits.
     *
     * @return array<string, int> Camp type to count, plus a 'total' key.
     */
    public function count_by_camp_type(string $year): array
    {
        if (!preg_match('/^\d{4}$/', $year)) {
            return [];
        }

        $total = $this->countMatching([
            [
                'key' => self::META_CAMP_YEAR,
                'value' => $year,
            ],
        ]);

        if ($total < 1) {
            return [];
        }

        $counts = [];

        foreach ($this->known_camp_types() as $camp) {
            $count = $this->countMatching([
                [
                    'key' => self::META_CAMP_YEAR,
                    'value' => $year,
                ],
                [
                    'key' => self::META_CAMP_TYPE,
                    'value' => $camp,
                ],
            ]);

            if ($count > 0) {
                $counts[$camp] = $count;
            }
        }

        $counts['total'] = $total;

        return $counts;
    }

    /**
     * Counts live registrations matching a meta query.
     *
     * Cancelled is excluded here rather than by the callers, because every
     * question this answers is a question about who is coming.
     *
     * @param array<int, array<string, mixed>> $meta_query
     */
    private function countMatching(array $meta_query): int
    {
        $statuses = array_values(array_filter(
            self::ALL_STATUSES,
            static fn(string $status): bool => $status !== CJW_Wizard_Submission_Status::CANCELLED
        ));

        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => $statuses,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs once a week on cron, over one camp year.
            'meta_query' => $meta_query,
        ]);

        return (int) $query->found_posts;
    }

    /**
     * How many registrations arrived since a moment.
     *
     * Counts every status including cancelled: the weekly report is about what
     * came in, and a registration withdrawn on Thursday still happened.
     */
    public function count_since(DateTimeImmutable $since): int
    {
        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => self::ALL_STATUSES,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
            'date_query' => [
                [
                    'after' => $since->format('Y-m-d H:i:s'),
                    'inclusive' => true,
                ],
            ],
        ]);

        return (int) $query->found_posts;
    }

    /**
     * How many registrations get_for_camp_year_upto() would eventually reach.
     *
     * Separate from the fetch because the number is wanted before anything is
     * changed: a button that says "247 inschrijvingen worden geanonimiseerd" is
     * one somebody can decide about, and "weet je het zeker?" is not.
     */
    public function count_for_camp_year_upto(string $year): int
    {
        if (!preg_match('/^\d{4}$/', $year)) {
            return 0;
        }

        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => self::ALL_STATUSES,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only count, see above.
            'meta_query' => $this->campYearUptoQuery($year),
        ]);

        return (int) $query->found_posts;
    }

    /**
     * The meta conditions shared by the two calls above.
     *
     * NUMERIC rather than string comparison: as text, '2026' sorts after
     * '10000' and before '999', which is only ever wrong.
     *
     * @return array<int|string, mixed>
     */
    private function campYearUptoQuery(string $year): array
    {
        return [
            'relation' => 'AND',
            [
                'key' => self::META_CAMP_YEAR,
                'value' => $year,
                'compare' => '<=',
                'type' => 'NUMERIC',
            ],
            [
                'key' => self::META_ANONYMISED,
                'compare' => 'NOT EXISTS',
            ],
        ];
    }

    /**
     * Flattens a nested payload to dot-notation keys, for the CSV export.
     *
     * @param array<string, mixed> $data
     */
    public function flatten_data(array $data, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($data as $key => $value) {
            $column = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flattened += $this->flatten_data($value, $column);
                continue;
            }

            $flattened[ $column ] = (string) $value;
        }

        return $flattened;
    }
}
