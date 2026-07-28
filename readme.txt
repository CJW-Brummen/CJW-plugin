=== CJW Zomerkampen ===
Contributors: Imre Boersma
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.5
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Summer camp settings, a shortcode-driven multistep form builder, and the shared services both rely on.

== Description ==

This plugin owns all camp data and behaviour for the CJW Brummen site. Presentation lives in
the `cjw-theme` theme, which reads from this plugin and degrades gracefully when it is inactive.

It replaces the three plugins `cjw-common`, `cjw-summer-camp` and `cjw-form-builder`, which were
always installed together and could not be used apart. Merging them removed the cross-plugin
activation guards and the Composer path repository that made the separate repositories awkward
to build and test.

The plugin is organised as modules under `inc/`:

* `inc/shared/` the summer camp service, the `CampType` enum, and localization helpers used by both features
* `inc/summer-camp/` the camp settings screen, admin assets, dashboard widget, Site Health checks, help tabs, and sponsor admin
* `inc/form-builder/` the wizard: post types, meta boxes, builder UI, rendering, validation, sessions, and submissions
* `assets/summer-camp/` and `assets/form-builder/` the browser assets for each module

Modules may use `inc/shared/`. They never reach into each other except through the documented
integration in `inc/form-builder/integrations/`.

Summer camp features:

* one settings screen for dates, yearly theme, hero, colors, prices, and the signup call to action
* a late-registration link with a rotating token
* theme tokens exposed to the front end as CSS custom properties
* dashboard widget, Site Health checks, and contextual help tabs
* sponsor admin that warns about published sponsors without a logo

Form builder features:

* visual step and field builder in wp-admin
* conditional visibility for steps and fields
* back/previous-step navigation
* front-end nonce protection
* field validation for email, length, dates, and repeaters
* saved submissions in wp-admin
* optional coupling to the shared summer camp service for registration status, intro copy, dates, prices, and theme tokens
* optional admin email notifications
* CSV export per form

Developer documentation is included with the plugin:

* `docs/config-schema.md` for the canonical config schema
* `docs/development-overview.md` for a quick architecture and data-flow overview
* `tests/README.md` for PHPUnit usage

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/cjw` folder.
2. Activate the plugin in WordPress.
3. Configure the camp under `Zomerkamp-instellingen`.
4. Create a new form under `Formulieren`.
5. New forms start empty by default, so add your own steps and fields in the builder.
6. Add the shortcode shown in the form sidebar to any post or page.

Upgrading from the three separate plugins: deactivate `CJW Form Builder`, `CJW Zomerkamp
instellingen` and `CJW Common`, then activate this plugin. All stored data is reused as-is —
the option key, post types, and meta keys are unchanged.

== Development ==

`composer install && npm ci`, then:

* `composer check` runs lint, PHPCS, formatting, PHPStan, PHPUnit, and the security audit
* `composer test` runs the PHPUnit suite
* `npm run lint` runs ESLint and Stylelint over `assets/`

== Changelog ==

= 2.0.0 =
* Merge `cjw-common`, `cjw-summer-camp` and `cjw-form-builder` into a single plugin with one
  toolchain and one CI pipeline. No data migration is required.
* Consolidate the three text domains into `cjw`.
* Single `CJW_VERSION` now drives asset cache-busting for every enqueue.

= 1.3.0 =
* Last release of the separate `cjw-summer-camp` plugin.

= 1.2.0 =
* Add optional integration with CJW Zomerkamp settings.
* Add summer camp token replacement for wizard labels, HTML blocks, and validation strings.

= 1.0.0 =
* Initial public release.
