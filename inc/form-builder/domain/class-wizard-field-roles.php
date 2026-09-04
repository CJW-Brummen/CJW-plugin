<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What a field *means*, as opposed to what type of input it is.
 *
 * The builder lets someone name their fields anything, so a payload is a bag of
 * arbitrary keys. That is fine for storing and showing a submission back, and
 * useless the moment anything needs to know which answer is the child's name --
 * a list column, a printable roster, duplicate detection, a capacity count.
 *
 * Guessing from key names was the alternative and it fails silently: rename a
 * field and the roster prints the wrong child. Marking the field once, in the
 * builder, is explicit and survives renaming.
 *
 * Roles are matched by PATH, not by key. A registration form typically asks the
 * same question twice -- `voornaam` for each child inside a repeater, and
 * `voornaam` again for the parent -- so a bare key is ambiguous and would put
 * the parent's name in the child column depending only on field order.
 *
 * A role is optional. A form that marks nothing still works exactly as before;
 * it simply cannot fill the columns that depend on knowing.
 */
final class CJW_Wizard_Field_Roles
{
    /**
     * The child's first name.
     */
    public const string CHILD_NAME = 'child_name';

    /**
     * The Dutch naming infix -- "de", "van der". Optional.
     */
    public const string CHILD_INFIX = 'child_infix';

    /**
     * The child's family name.
     */
    public const string CHILD_LAST_NAME = 'child_last_name';

    /**
     * Used for the roster and for spotting the same child registered twice.
     */
    public const string BIRTH_DATE = 'birth_date';

    /**
     * Which camp — week or weekend. Drives capacity and pricing.
     */
    public const string CAMP_TYPE = 'camp_type';

    /**
     * Whether the child may appear in photos.
     */
    public const string PHOTO_CONSENT = 'photo_consent';

    /**
     * The registering parent's e-mail address.
     *
     * The only role about the parent rather than the child, and the one most
     * things stand on: the confirmation goes there, and WordPress answers an
     * AVG access or erasure request by this address. Without it the plugin
     * falls back to the first value in the payload that looks like an address,
     * which on a form with an emergency contact is a coin toss.
     */
    public const string PARENT_EMAIL = 'parent_email';

    /**
     * Roles whose value identifies a person and must not outlive retention.
     *
     * @var array<int, string>
     */
    public const array PERSONAL = [
        self::CHILD_NAME,
        self::CHILD_INFIX,
        self::CHILD_LAST_NAME,
        self::BIRTH_DATE,
        self::PARENT_EMAIL,
    ];

    /**
     * The parts of a name, in the order Dutch writes them.
     *
     * @var array<int, string>
     */
    private const array NAME_PARTS = [ self::CHILD_NAME, self::CHILD_INFIX, self::CHILD_LAST_NAME ];

    /**
     * Every role, with the label the builder shows.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::CHILD_NAME => __('Voornaam van het kind', 'cjw'),
            self::CHILD_INFIX => __('Tussenvoegsel van het kind', 'cjw'),
            self::CHILD_LAST_NAME => __('Achternaam van het kind', 'cjw'),
            self::BIRTH_DATE => __('Geboortedatum', 'cjw'),
            self::CAMP_TYPE => __('Type kamp', 'cjw'),
            self::PHOTO_CONSENT => __('Toestemming foto’s', 'cjw'),
            self::PARENT_EMAIL => __('E-mailadres van de ouder', 'cjw'),
        ];
    }

    /**
     * Whether a value is a role this version understands.
     *
     * @param mixed $role Candidate role.
     */
    public static function isValid(mixed $role): bool
    {
        return is_string($role) && array_key_exists($role, self::all());
    }

    /**
     * The dotted path of the field carrying a role, or an empty string.
     *
     * A field inside a repeater gets a `*` where the row index goes, e.g.
     * `kinderen.*.voornaam`.
     *
     * @param array  $config Form configuration.
     * @param string $role   Role to look for.
     * @param array<string, mixed> $config
     */
    public static function pathFor(array $config, string $role): string
    {
        $paths = self::pathsFor($config, $role);

        return $paths === [] ? '' : $paths[0];
    }

    /**
     * Every field carrying a role, in the order the form asks them.
     *
     * More than one is legitimate and common: marking `voornaam`,
     * `tussenvoegsel` and `achternaam` all as "voornaam van het kind" is what
     * someone naturally does, and it should compose into a full name rather
     * than silently keep only the first.
     *
     * @param array  $config Form configuration.
     * @param string $role   Role to look for.
     * @param array<string, mixed> $config
     *
     * @return array<int, string>
     */
    public static function pathsFor(array $config, string $role): array
    {
        return self::pathsByRole($config)[ $role ] ?? [];
    }

    /**
     * Every submitted value for a role, one per repeater row.
     *
     * A form asking for several children returns several names, in row order.
     *
     * @param array  $config Form configuration.
     * @param array  $data   Submitted payload.
     * @param string $role   Role to look for.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     *
     * @return array<string, string> Row prefix => value.
     */
    public static function valuesFor(array $config, array $data, string $role): array
    {
        $path = self::pathFor($config, $role);

        if ($path === '') {
            return [];
        }

        $found = [];

        foreach (self::flatten($data) as $column => $value) {
            $prefix = self::matchPath($path, $column);

            if ($prefix === null) {
                continue;
            }

            $value = trim($value);

            if ($value !== '') {
                $found[ $prefix ] = $value;
            }
        }

        return $found;
    }

    /**
     * The first submitted value for a role, or an empty string.
     *
     * @param array  $config Form configuration.
     * @param array  $data   Submitted payload.
     * @param string $role   Role to look for.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    public static function valueFor(array $config, array $data, string $role): string
    {
        $values = self::valuesFor($config, $data, $role);

        return $values === [] ? '' : (string) reset($values);
    }

    /**
     * The full name of every child in a submission, in the order asked.
     *
     * Composed from whichever name parts the form marks, so a form with only a
     * first name still yields a usable name and one with all three yields
     * "Anna van der Berg".
     *
     * @param array $config Form configuration.
     * @param array $data   Submitted payload.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     *
     * @return array<int, string>
     */
    public static function childNames(array $config, array $data): array
    {
        $names = [];

        foreach (self::gather($data, self::namePaths($config)) as $parts) {
            $name = trim(implode(' ', $parts));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * One record per child in a submission.
     *
     * A registration form asks for children in a repeater, so a single
     * submission routinely holds two or three of them, each with their own camp
     * and birth date. Anything that lists children -- a roster, a capacity
     * count, the admin screen -- needs them separated, not joined.
     *
     * @param array $config Form configuration.
     * @param array $data   Submitted payload.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     *
     * @return array<int, array{name: string, birth_date: string, camp: string, photo_consent: string}>
     */
    public static function childRows(array $config, array $data): array
    {
        $names = self::gather($data, self::namePaths($config));

        $other = [];

        foreach ([ self::BIRTH_DATE, self::CAMP_TYPE, self::PHOTO_CONSENT ] as $role) {
            $other[ $role ] = self::valuesFor($config, $data, $role);
        }

        $prefixes = array_keys($names);

        // Only roles living in the same repeater as the children can add a row.
        // A form-level role -- photo consent asked once for the whole family --
        // has an empty prefix, and letting that through invented a nameless
        // extra child on every registration.
        $scope = self::childScope($config);

        foreach ($other as $values) {
            foreach (array_keys($values) as $prefix) {
                if (! str_starts_with($prefix, $scope)) {
                    continue;
                }

                if ($scope === '' && $prefix !== '') {
                    continue;
                }

                if (! in_array($prefix, $prefixes, true)) {
                    $prefixes[] = $prefix;
                }
            }
        }

        $rows = [];

        foreach ($prefixes as $prefix) {
            $row = [
                'name' => trim(implode(' ', $names[ $prefix ] ?? [])),
                'birth_date' => $other[ self::BIRTH_DATE ][ $prefix ] ?? '',
                'camp' => $other[ self::CAMP_TYPE ][ $prefix ] ?? '',
                'photo_consent' => $other[ self::PHOTO_CONSENT ][ $prefix ] ?? '',
            ];

            // A row with nothing in it is a repeater slot the parent left blank.
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The repeater prefix the children live under, or '' when the form asks
     * about one child directly.
     *
     * Taken from whichever child-identifying role the form actually marks, so a
     * form marking only a camp type still scopes correctly.
     *
     * @param array $config Form configuration.
     * @param array<string, mixed> $config
     */
    private static function childScope(array $config): string
    {
        foreach ([ ...self::NAME_PARTS, self::BIRTH_DATE, self::CAMP_TYPE ] as $role) {
            foreach (self::pathsFor($config, $role) as $path) {
                if (str_contains($path, '.*.')) {
                    return explode('.*.', $path)[0] . '.';
                }
            }
        }

        return '';
    }

    /**
     * Role => every dotted path carrying it, in form order.
     *
     * @param array $config Form configuration.
     * @param array<string, mixed> $config
     *
     * @return array<string, array<int, string>>
     */
    public static function pathsByRole(array $config): array
    {
        $found = [];

        foreach (is_array($config['steps'] ?? null) ? $config['steps'] : [] as $step) {
            if (! is_array($step)) {
                continue;
            }

            foreach (self::collect(is_array($step['fields'] ?? null) ? $step['fields'] : [], '') as $role => $paths) {
                $found[ $role ] = [ ...($found[ $role ] ?? []), ...$paths ];
            }
        }

        return $found;
    }

    /**
     * The paths that make up a child's name, in the order they are written.
     *
     * First names, then any infix, then family names -- and within each, the
     * order the form asks them.
     *
     * @param array $config Form configuration.
     * @param array<string, mixed> $config
     *
     * @return array<int, string>
     */
    private static function namePaths(array $config): array
    {
        $paths = [];

        foreach (self::NAME_PARTS as $role) {
            $paths = [ ...$paths, ...self::pathsFor($config, $role) ];
        }

        return $paths;
    }

    /**
     * Values for a set of paths, grouped by the row they belong to.
     *
     * @param array               $data  Submitted payload.
     * @param array<int, string>  $paths Paths to gather.
     * @param array<string, mixed> $data
     *
     * @return array<string, array<int, string>> Row prefix => values in path order.
     */
    private static function gather(array $data, array $paths): array
    {
        $flat = self::flatten($data);
        $rows = [];

        foreach ($paths as $path) {
            foreach ($flat as $column => $value) {
                $prefix = self::matchPath($path, $column);

                if ($prefix === null) {
                    continue;
                }

                $value = trim($value);

                if ($value !== '') {
                    $rows[ $prefix ][] = $value;
                }
            }
        }

        return $rows;
    }

    /**
     * Walks one level of fields and everything nested under it.
     *
     * @param array  $fields Field configuration.
     * @param string $prefix Dotted path of the level being walked.
     * @param array<int, mixed> $fields
     *
     * @return array<string, array<int, string>>
     */
    private static function collect(array $fields, string $prefix): array
    {
        $found = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            $role = $field['role'] ?? '';
            $path = $key === '' ? $prefix : $prefix . $key;

            if (self::isValid($role) && $key !== '') {
                $found[ (string) $role ][] = $path;
            }

            if (is_array($field['fields'] ?? null)) {
                // A repeater's answers live under a row index, so the path gets
                // a wildcard where that number will be.
                $childPrefix = ($field['type'] ?? '') === 'repeater' ? $path . '.*.' : $prefix;

                foreach (self::collect($field['fields'], $childPrefix) as $nestedRole => $nestedPaths) {
                    $found[ $nestedRole ] = [ ...($found[ $nestedRole ] ?? []), ...$nestedPaths ];
                }
            }

            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                if (is_array($option) && is_array($option['fields'] ?? null)) {
                    foreach (self::collect($option['fields'], $prefix) as $optionRole => $optionPaths) {
                        $found[ $optionRole ] = [ ...($found[ $optionRole ] ?? []), ...$optionPaths ];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Matches a concrete payload column against a role path.
     *
     * @param string $path   Role path, possibly containing `*`.
     * @param string $column Concrete dotted column from the payload.
     *
     * @return string|null The row prefix when it matches, null when it does not.
     */
    private static function matchPath(string $path, string $column): ?string
    {
        if (! str_contains($path, '*')) {
            return $path === $column ? '' : null;
        }

        [ $before, $after ] = explode('*', $path, 2);

        if (! str_starts_with($column, $before) || ! str_ends_with($column, $after)) {
            return null;
        }

        $index = substr($column, strlen($before), strlen($column) - strlen($before) - strlen($after));

        // Exactly one path segment: the row index, and nothing deeper.
        return $index !== '' && ! str_contains($index, '.') ? $before . $index : null;
    }

    /**
     * Flattens a payload into dot-notation columns.
     *
     * @param array  $data   Nested answers.
     * @param string $prefix Column prefix while recursing.
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $column = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += self::flatten($value, $column);
                continue;
            }

            if (is_scalar($value)) {
                $flat[ $column ] = (string) $value;
            }
        }

        return $flat;
    }
}
