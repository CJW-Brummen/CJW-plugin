=== CJW Zomerkampen ===
Contributors: Imre Boersma
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.5
Stable tag: 2.5.0
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

= 2.5.0 =
* Payments per registration. "Betaald" was one radio button, so part payments, cash at the drive
  and a grandparent paying for one child could not be recorded at all.
* The amount owed is frozen in cents when the registration is taken. It used to be recomputed from
  the payload on every view, and the retention sweep erases that payload — so a camp year's figures
  quietly became zero once its registrations were anonymised.
* Each payment is a row: date, amount, method, note and who recorded it. A negative amount is a
  refund. The amount owed can be corrected for a reduced rate, with the form's own figure kept
  beside it.
* The Betaald status follows the balance, but only when the ledger itself moved, so a status
  chosen by hand is not overruled by arithmetic.
* A column on the registrations list and a total for the camp year above it.

= 2.4.0 =
* A status route at `cjw/v1/status` for an uptime monitor outside the site, because everything
  else this plugin reports it reports by mail from inside itself. An anonymous caller learns
  whether the site is up and whether it considers itself healthy; the token from the Beheer tab
  unlocks what is actually wrong. Graded ok / warning / critical, `?strict=1` answers 503 on a
  critical verdict, and the checks are cached for thirty seconds.
* Fatal errors are recorded and reported once per place in the code, so a crash that takes a page
  down mid-render is no longer invisible to everybody but the visitor.
* An administrators-only dashboard widget: the verdict with the problems named, this camp year's
  registration numbers, when each scheduled job last ran and when it is next due, and whether this
  installation is the live site or a copy.
* The registration form now says what happens to the data it asks for and requires the parent to
  agree. The wording is the organisers' to write, with tokens so the retention period on the page
  cannot drift from the one the sweep applies; the moment and the exact wording shown are stored
  with the registration and survive anonymisation.

= 2.3.0 =
* One click on the Beheer tab creates what a fresh site is missing: the registration page, the
  front page, the two card pages, a draft privacy page and the main menu. Nothing that already
  exists is touched, and it deliberately does not run on activation.
* A copy of the site recognises itself and sends every outgoing mail to the administrator with
  [TEST] in the subject, so rehearsing an update on a restored backup cannot reach real parents.
* docs/legacy-urls.md records all 61 addresses of the old site and where each should point after
  the switch, with a paste-ready .htaccess block. The redirects belong to the hosting, not here.

= 2.2.0 =
* One canonical address for the registration form. Every "Schrijf je in" button links there, and
  when the setting is blank the published page carrying the shortcode is found rather than guessed.
  The old `#inschrijven` anchor, which no template ever had, is gone.
* The photo pickers say how many children of this camp year may not appear in a photo, and name
  them for anybody who may open the children list anyway.
* A seventh field role, "E-mailadres van de ouder", so the confirmation and any AVG request stop
  depending on a guess about which e-mail field belongs to whom.

= 2.1.0 =
* A registration is never reported as succeeded when it was not stored. A mistyped shortcode id,
  a form with no steps and an unpublished form each say so plainly instead, with the technical
  reason shown only to somebody who may edit the form.
* The success screen quotes the registration number, names the address the confirmation went to,
  and says so honestly when that send failed.
* Mail leaves with the site's own sender name and the camp's reply address, and the reason a send
  failed is captured rather than discarded.
* Faults that used to stay silent now report themselves once: a confirmation a parent never got,
  and a scheduled job that has stopped running.
* The session stops holding a child's answers the moment the registration is stored.

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
