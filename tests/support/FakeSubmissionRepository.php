<?php

declare(strict_types=1);

/**
 * An in-memory stand-in for the submission repository.
 *
 * The eraser's pagination bug was arithmetic, not database behaviour: it
 * offsets into a result set that its own writes shrink. That reproduces exactly
 * here, without needing a WordPress install, as long as the fake keeps the one
 * property that caused it -- anonymising a row removes it from the set
 * get_by_email() returns.
 */
final class FakeSubmissionRepository implements CJW_Wizard_Submission_Store
{
    /**
     * id => ['email' => string, 'anonymised' => bool]
     *
     * @var array<int, array{email: string, anonymised: bool}>
     */
    private array $rows = [];

    /**
     * Rows that refuse to anonymise, to simulate a write that cannot complete.
     *
     * @var array<int, int>
     */
    private array $stubborn = [];

    /**
     * @param int    $count How many submissions to seed.
     * @param string $email Address they all belong to.
     */
    public function seed(int $count, string $email): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->rows[] = [
                'email' => $email,
                'anonymised' => false,
            ];
        }
    }

    /**
     * Marks a row as one that anonymise() will fail on.
     */
    public function makeStubborn(int $index): void
    {
        $this->stubborn[] = $index;
    }

    /**
     * @return array<int, object>
     */
    public function get_by_email(string $email): array
    {
        $matches = [];

        foreach ($this->rows as $id => $row) {
            if ($row['email'] === $email && ! $row['anonymised']) {
                $matches[] = (object) [ 'ID' => $id ];
            }
        }

        return $matches;
    }

    public function anonymise(int $submission_id): bool
    {
        if (! isset($this->rows[ $submission_id ]) || $this->rows[ $submission_id ]['anonymised']) {
            return false;
        }

        if (in_array($submission_id, $this->stubborn, true)) {
            return false;
        }

        $this->rows[ $submission_id ]['anonymised'] = true;

        return true;
    }

    /**
     * How many seeded rows still hold personal data.
     */
    public function remaining(): int
    {
        return count(array_filter($this->rows, static fn(array $row): bool => ! $row['anonymised']));
    }

    public function get_expired(DateTimeImmutable $before, int $limit = 100): array
    {
        $expired = [];

        foreach ($this->rows as $id => $row) {
            if (! $row['anonymised']) {
                $expired[] = (object) [ 'ID' => $id ];
            }
        }

        return array_slice($expired, 0, $limit);
    }

    public function get_payload(int $submission_id): array
    {
        return [ 'naam' => 'Anna', 'mail' => 'ouder@example.test' ];
    }

    /**
     * Flattening is pure, so the real implementation is reused rather than
     * reimplemented — a second copy would be free to drift from the first.
     *
     * @param array  $data   Nested answers.
     * @param string $prefix Column prefix while recursing.
     *
     * @return array<string, string>
     */
    public function flatten_data(array $data, string $prefix = ''): array
    {
        return (new CJW_Wizard_Submission_Repository())->flatten_data($data, $prefix);
    }
}
