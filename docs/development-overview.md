# CJW Form Builder Development Overview

This document is a short orientation guide for developers working on the plugin.

## Main folders

`assets/admin/`
- builder UI JavaScript and CSS for the wp-admin form editor

`assets/frontend/`
- public-facing wizard JavaScript and CSS

`inc/bootstrap/`
- plugin bootstrapping and dependency loading

`inc/admin/`
- WordPress admin concerns such as post type setup, meta boxes, and asset loading

`inc/domain/`
- shared defaults, config loading, and runtime normalization

`inc/frontend/`
- runtime form rendering, navigation, validation, conditions, and session handling

`inc/submissions/`
- submission persistence, admin display, export, and notifications

`tests/`
- PHPUnit-based automated tests with a lightweight WordPress shim

## Data flow

The plugin’s main flow is:

1. The builder writes `_cjw_wizard_config` JSON and `_cjw_wizard_settings` meta.
2. `CJW_Wizard_Config::get()` loads the saved values.
3. `CJW_Wizard_Config_Normalizer` converts saved builder JSON into the runtime config shape.
4. `CJW_Wizard_Renderer` outputs the current step.
5. `CJW_Wizard_Engine` merges submitted data and moves forward/backward.
6. `CJW_Wizard_Validator` validates the active step.
7. When completed, `CJW_Wizard_Submissions` saves the payload and may trigger email/export features.

## Important references

`docs/config-schema.md`
- canonical reference for the saved and runtime config shape

`inc/domain/class-wizard-defaults.php`
- single source of truth for shared form-level default settings

`inc/domain/class-wizard-config-normalizer.php`
- single place where saved builder config is normalized for runtime use

`tests/README.md`
- how to run automated tests

## Current testing approach

Tests are written in PHPUnit and cover:

- conditional logic
- config normalization
- step navigation
- field validation
- renderer output and completion flow
- submission data flattening
- shared defaults/settings behavior

This is currently a plugin-local PHPUnit setup, not the full official WordPress core integration test suite.
