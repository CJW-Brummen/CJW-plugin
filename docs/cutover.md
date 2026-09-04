# Cutover: three plugins to one

`cjw` replaces `cjw-common`, `cjw-summer-camp` and `cjw-form-builder`. No data
migration is required — every persistent key is unchanged.

## What is guaranteed to be identical

| Kind | Value |
| --- | --- |
| Option | `cjw_summer_camp_settings` |
| Post types | `cjw_wizard` (see "Submissions post type" below for the other one) |
| Post meta | `_cjw_wizard_config`, `_cjw_wizard_settings`, `_cjw_wizard_payload`, `_cjw_wizard_snapshot`, `_cjw_wizard_id` |
| Shortcode | `[cjw_wizard]` |
| Admin page | `admin.php?page=cjw-summer-camp` |
| Transients | `cjw_wizard_<token>` |
| Query arg | `cjw_late_registration` |
| Theme bridge | `cjw_summer_camp()`, `CJW_Summer_Camp_Service`, `CampType` |

The `cjw-theme` theme needs **no changes**. It only ever used
`cjw_summer_camp()` and the `CJW_Summer_Camp_Service` class name, both of which
still exist with the same names.

## Steps

1. Deploy the `cjw` plugin directory to `wp-content/plugins/cjw`.
2. Deactivate the three old plugins, then activate the new one:

   ```bash
   wp plugin deactivate cjw-form-builder cjw-summer-camp cjw-common
   wp plugin activate cjw
   ```

   Deactivate first. Activating `cjw` while the old plugins are still active
   would redeclare `CJW_Summer_Camp_Service` and fatal.

3. Confirm the settings survived:

   ```bash
   wp eval '$o = get_option("cjw_summer_camp_settings"); echo count($o), " keys\n";'
   ```

   Expect the same key count as before the switch (68 on the current site).

4. Spot-check the camp settings screen, the wizard list, and the public
   registration page.
5. Once satisfied, delete the three old plugin directories and archive their
   GitHub repositories.

## Old URLs

The addresses of the legacy site, and where each should redirect to after the
switch, are listed in [legacy-urls.md](legacy-urls.md). The redirects belong to
the hosting, not to this plugin.

## Rollback

Deactivate `cjw` and reactivate the three old plugins. Nothing in the database
was renamed, so the old code reads the same rows.

## Translations

The three text domains (`cjw-common`, `cjw-summer-camp`, `cjw-form-builder`)
became one: `cjw`. Only `.pot` templates existed and no compiled `.po`/`.mo`
files, so no translations were lost. Regenerate with `composer make-pot`.

## Submissions post type

The submissions post type was renamed from `cjw_wizard_submission` to
**`cjw_wizard_entry`**. This is the one persistent key the merge does not keep
identical, and it is deliberate.

`cjw_wizard_submission` is **21 characters**. WordPress limits post type names
to 20, so `register_post_type()` always returned `post_type_length_invalid` and
the post type never registered. `wp_insert_post()` does not care, so
submissions were written to `wp_posts` and were simply unreachable in wp-admin —
the CSV export was the only way to them, and only because `get_posts()` queries
by the raw string. The bug dates back to the form builder's initial commit.

`PostTypeNameTest` asserts the name stays inside the 20-character limit, so this
cannot regress silently the way it did the first time.

### No data migration ships with this

Because the post type never registered, the only rows that could exist under the
old name are ones the broken build wrote. There are none: the local site has 0,
and the site is pre-launch, so no real registration was ever taken. Shipping a
migration for a case that cannot occur would be code nobody can test and nobody
will ever exercise, so there isn't one.

If a row ever does turn up under the old name — check with:

```sql
SELECT COUNT(*) FROM wp_posts WHERE post_type = 'cjw_wizard_submission';
```

then move it by hand. Take a database backup first, and flush the object cache
afterwards:

```sql
UPDATE wp_posts SET post_type = 'cjw_wizard_entry' WHERE post_type = 'cjw_wizard_submission';
```
