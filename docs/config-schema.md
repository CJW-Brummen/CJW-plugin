# CJW Form Builder Config Schema

This document is the canonical reference for the wizard configuration shape used by the form builder module (`inc/form-builder/`).

It describes:

- the JSON shape saved by the builder in `_cjw_wizard_config`
- the separate form-level settings saved in `_cjw_wizard_settings`
- the normalized runtime shape returned by `CJW_Wizard_Config::get()`
- the shared code defaults in `inc/domain/class-wizard-defaults.php`
- the normalization rules in `inc/domain/class-wizard-config-normalizer.php`

## Two related data sources

The plugin stores wizard data in two places:

1. `_cjw_wizard_config`
  Contains the builder JSON: steps, fields, validation options, repeater structure, and conditional display rules.

2. `_cjw_wizard_settings`
   Contains form-wide settings such as button labels, success message, admin notification, and CSV export.

At runtime, `CJW_Wizard_Config::get()` combines both into a single array:

```php
[
    'steps' => [...],
    'rules' => [],
    'settings' => [...],
]
```

Default setting values are defined in code in:

- `CJW_Wizard_Defaults::settings()`

That method is the single source of truth for schema-level default settings.

Normalization of saved config into runtime shape is handled by:

- `CJW_Wizard_Config_Normalizer::normalize()`

## Important normalization rule

The builder saves `steps` as a sequential array:

```json
{
  "steps": [
    { "key": "participant", "label": "Deelnemer" }
  ]
}
```

At runtime, `CJW_Wizard_Config::get()` converts that into an associative array keyed by step key:

```php
[
    'steps' => [
        'participant' => [
            'key' => 'participant',
            'label' => 'Deelnemer',
        ],
    ],
]
```

Code that reads runtime config should therefore assume:

- `steps` is a keyed map
- each step still contains its own `key`

## Top-level runtime shape

```php
[
    'steps' => [
        'step_key' => step-array,
    ],
    'rules' => [],
    'settings' => settings-array,
]
```

### Top-level keys

`steps`
- Type: `array<string, array>`
- Required: yes
- Meaning: all configured steps keyed by step key

`rules`
- Type: `array`
- Required: no in practice
- Meaning: reserved/legacy top-level rule storage
- Current status: not actively used by builder/runtime logic

`settings`
- Type: `array`
- Required: yes at runtime
- Meaning: merged form-wide settings from `_cjw_wizard_settings`

## Step schema

Each step is an array with this shape:

```php
[
    'key' => 'participant',
    'label' => 'Deelnemer',
    'type' => 'default',
    'fields' => [
        field-array,
    ],
    'condition' => condition-array,
]
```

### Step properties

`key`
- Type: `string`
- Required: yes
- Meaning: unique identifier for the step
- Notes: used for normalization and internal step lookup

`label`
- Type: `string`
- Required: effectively yes
- Meaning: title shown to the user

`type`
- Type: `string`
- Allowed values:
  - `default`
  - `summary`
- Default: `default`
- Meaning:
  - `default` renders its fields normally
  - `summary` renders the summary view and uses the complete button label

`fields`
- Type: `array<int, array>`
- Required: for `default` steps
- Meaning: list of configured fields/components in that step

`condition`
- Type: `array`
- Required: no
- Meaning: optional conditional visibility rule for the entire step

## Field schema

Each field/component inside a step uses this base shape:

```php
[
    'key' => 'email',
    'label' => 'E-mail',
    'type' => 'email',
    'required' => true,
    'condition' => [],
]
```

### Base field properties

`key`
- Type: `string`
- Required: for data-carrying fields and repeaters
- Meaning: stored key in submission data
- Notes:
  - used in validation and rendering
  - headings and HTML blocks may still have a key in builder data, but it is not required for runtime behavior

`label`
- Type: `string`
- Required: recommended
- Meaning:
  - user-facing label for normal fields
  - heading text for `heading`
  - HTML content for `html`

`type`
- Type: `string`
- Allowed values:
  - `text`
  - `email`
  - `date`
  - `checkbox`
  - `checkbox_group`
  - `radio`
  - `textarea`
  - `heading`
  - `html`
  - `repeater`
- Default: `text`

`required`
- Type: `bool`
- Required: no
- Default:
  - usually `true` for normal input fields created by the builder
  - effectively ignored for `heading` and `html`
- Meaning: whether the field must be filled when visible

`condition`
- Type: `array`
- Required: no
- Meaning: optional conditional visibility rule for the field

## Field type extensions

### `text`

Additional optional properties:

- `min_length` `int`
- `max_length` `int`

### `textarea`

Additional optional properties:

- `min_length` `int`
- `max_length` `int`

### `email`

Additional optional properties:

- `min_length` `int`
- `max_length` `int`

Validation:

- must be a valid email address if non-empty

### `date`

Additional optional properties:

- `min_date` `string` in `Y-m-d`
- `max_date` `string` in `Y-m-d`
- `date_after_field` `string`
- `date_before_field` `string`

Validation:

- must be a valid HTML date value in `Y-m-d`
- can be constrained by absolute min/max dates
- can be constrained relative to another field using dot notation paths

### `checkbox`

Additional properties:

- none

Stored value:

- typically `'1'` when checked

### `checkbox_group`

Additional optional properties:

- `options` `array<int, string|checkbox-option-array>`

Checkbox group options may be plain strings:

```php
[
    'options' => [ 'Zaterdag heen', 'Maandag terug' ],
]
```

Or structured labels and submitted values:

```php
[
    'key' => 'drive_days',
    'label' => 'Beschikbaar op',
    'type' => 'checkbox_group',
    'required' => true,
    'options' => [
        [
            'label' => 'Zaterdag 18 juli (heen)',
            'value' => 'saturday_to',
        ],
        [
            'label' => 'Maandag 20 juli (terug weekendkamp)',
            'value' => 'monday_back',
        ],
    ],
]
```

Stored value:

- array of selected option values

Validation:

- if `required` is true, at least one checkbox in the group must be selected
- selected values must exist in the configured options

### `radio`

Additional optional properties:

- `options` `array<int, string|radio-option-array>`

Radio options may be plain strings:

```php
[
    'options' => [ 'Ja', 'Nee' ],
]
```

Or structured options with labels, submitted values, defaults, and visually nested subfields:

```php
[
    'options' => [
        [
            'label' => 'Ja, ik kan rijden en ben beschikbaar op:',
            'value' => 'Ja',
            'default' => true,
            'fields' => [
                [
                    'key' => 'drive_days',
                    'label' => 'Beschikbaar op',
                    'type' => 'checkbox_group',
                    'required' => true,
                    'options' => [
                        [
                            'label' => 'Zaterdag 18 juli (heen)',
                            'value' => 'saturday_to',
                        ],
                    ],
                ],
            ],
        ],
        [
            'label' => 'Nee, ik ben niet beschikbaar',
            'value' => 'Nee',
        ],
    ],
]
```

Validation:

- submitted value must be one of the configured option values
- subfields are rendered indented below their option and are validated only when that option is selected

### `heading`

Additional properties:

- none

Behavior:

- renders a non-input heading block
- ignored by validation

### `html`

Additional properties:

- none

Behavior:

- renders HTML content from `label`
- ignored by validation

### `repeater`

Additional optional properties:

- `max_entries` `int`
- `fields` `array<int, field-array>`

Behavior:

- stores nested rows under the repeater key
- each row is an array keyed by subfield key
- subfields use the same field schema recursively

Validation:

- if required, at least one non-empty row must exist
- if `max_entries > 0`, row count must not exceed that number
- visible subfields inside each non-empty row are validated recursively

## Condition schema

Conditions are used on both steps and fields.

```php
[
    'field' => 'group',
    'operator' => 'equals',
    'value' => 'A',
]
```

### Condition properties

`field`
- Type: `string`
- Required: yes to activate the condition
- Meaning: dot-notation path to a previously collected value
- Examples:
  - `group`
  - `email`
  - `kids.0.name`

`operator`
- Type: `string`
- Required: yes to activate the condition
- Allowed values:
  - `equals`
  - `not_equals`
  - `contains`
  - `not_contains`
  - `empty`
  - `not_empty`

`value`
- Type: `string|scalar`
- Required:
  - yes for `equals`, `not_equals`, `contains`, `not_contains`
  - no for `empty`, `not_empty`

### Condition behavior

If `condition.field` or `condition.operator` is missing/empty, the node is treated as visible.

Conditions are evaluated through `CJW_Wizard_Conditions` using:

- exact normalized match for `equals`
- inverse match for `not_equals`
- substring/list membership for `contains`
- inverse of that for `not_contains`
- emptiness checks for `empty` and `not_empty`

Normalization rules:

- string comparison is case-insensitive
- surrounding whitespace is trimmed
- booleans are normalized to `'1'` or `'0'`

## Settings schema

The runtime `settings` array uses this shape:

```php
[
    'submit_label' => 'Volgende',
    'complete_label' => 'Afronden',
    'previous_label' => 'Vorige',
    'show_previous_button' => true,
    'success_message' => 'Bedankt! Je formulier is succesvol afgerond.',
    'use_summer_camp_settings' => false,
    'show_summer_camp_intro' => true,
    'send_admin_notification' => false,
    'notification_email' => '',
    'enable_csv_export' => false,
]
```

### Settings properties

`submit_label`
- Type: `string`
- Meaning: label for the next-step button

`complete_label`
- Type: `string`
- Meaning: label for the submit/finish button on summary steps

`previous_label`
- Type: `string`
- Meaning: label for the previous-step button

`show_previous_button`
- Type: `bool`
- Meaning: whether the previous-step button is shown

`success_message`
- Type: `string`
- Meaning: message shown after successful completion

`use_summer_camp_settings`
- Type: `bool`
- Meaning: whether this form reads shared status, intro copy, dates, prices, and theme tokens from the shared summer camp service (`inc/shared/`)

`show_summer_camp_intro`
- Type: `bool`
- Meaning: whether the summer camp intro text is rendered above the form when the summer camp integration is enabled

`send_admin_notification`
- Type: `bool`
- Meaning: whether to send an admin email on completion

`notification_email`
- Type: `string`
- Meaning: optional custom destination email
- Notes: falls back to the WordPress admin email when empty

`enable_csv_export`
- Type: `bool`
- Meaning: whether CSV export is enabled for this form

## Summer camp token integration

When `use_summer_camp_settings` is enabled and the summer camp settings are configured, string values in the runtime config can use these tokens:

- `{camp_theme_name}`
- `{camp_theme_year}`
- `{camp_hero_title}`
- `{camp_hero_subtitle}`
- `{camp_start_date}`
- `{camp_start_date_iso}`
- `{camp_end_date}`
- `{camp_end_date_iso}`
- `{camp_date_range}`
- `{camp_price_week}`
- `{camp_price_week_raw}`
- `{camp_price_weekend}`
- `{camp_price_weekend_raw}`
- `{camp_intro_text}`

The wizard also respects the summer camp registration status when enabled. If registrations are closed, the form is replaced by the summer camp closed-registration text. A valid private `cjw_late_registration` token generated by the summer camp settings bypasses that closed state, so admins can share a unique late-registration URL without reopening public registration.

Runtime config values may also be locale keyed. When a string value is provided as a map such as `['en_US' => 'Next', 'nl_NL' => 'Volgende']`, the shared `cjw_localize_value()` helper selects the value matching the active WordPress locale before tokens are replaced.

## Submission data shape

Submitted data is stored as nested arrays keyed by field key.

Example:

```php
[
    'participant_name' => 'Sam',
    'email' => 'sam@example.com',
    'kids' => [
        [
            'name' => 'Anna',
            'birthdate' => '2015-05-01',
        ],
        [
            'name' => 'Milan',
            'birthdate' => '2017-09-12',
        ],
    ],
]
```

For repeater validation, rendering, and CSV export:

- nested structures are preserved in saved payloads
- CSV export flattens nested keys using dot notation
- example flattened keys:
  - `kids.0.name`
  - `kids.1.birthdate`

## Builder-only transient properties

The builder uses temporary properties that must not be treated as canonical persisted schema:

- `_uid`
- `_collapsed`

These are removed before saving by the builder state sync logic.

## Minimal saved config example

```json
{
  "steps": [
    {
      "key": "participant",
      "label": "Deelnemer",
      "type": "default",
      "condition": {},
      "fields": [
        {
          "key": "name",
          "label": "Naam",
          "type": "text",
          "required": true,
          "condition": {},
          "min_length": 2
        },
        {
          "key": "email",
          "label": "E-mail",
          "type": "email",
          "required": true,
          "condition": {}
        },
        {
          "key": "needs_transport",
          "label": "Vervoer nodig",
          "type": "checkbox",
          "required": false,
          "condition": {}
        },
        {
          "key": "pickup_location",
          "label": "Opstapplaats",
          "type": "text",
          "required": true,
          "condition": {
            "field": "needs_transport",
            "operator": "equals",
            "value": "1"
          }
        }
      ]
    },
    {
      "key": "summary",
      "label": "Samenvatting",
      "type": "summary",
      "condition": {}
    }
  ]
}
```

## Maintenance notes

When changing the schema in the future:

1. Update this document first.
2. Update builder serialization/defaults.
3. Update `CJW_Wizard_Config_Normalizer`.
4. Update renderer, validator, conditions, and submissions if the new property affects runtime behavior.
5. Add or update tests for the changed shape.
