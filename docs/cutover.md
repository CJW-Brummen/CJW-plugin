# Cutover: three plugins to one

`cjw` replaces `cjw-common`, `cjw-summer-camp` and `cjw-form-builder`. No data
migration is required — every persistent key is unchanged.

## What is guaranteed to be identical

| Kind | Value |
| --- | --- |
| Option | `cjw_summer_camp_settings` |
| Post types | `cjw_wizard`, `cjw_wizard_submission` |
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

## Rollback

Deactivate `cjw` and reactivate the three old plugins. Nothing in the database
was renamed, so the old code reads the same rows.

## Translations

The three text domains (`cjw-common`, `cjw-summer-camp`, `cjw-form-builder`)
became one: `cjw`. Only `.pot` templates existed and no compiled `.po`/`.mo`
files, so no translations were lost. Regenerate with `composer make-pot`.

## Known pre-existing bug, not fixed here

`cjw_wizard_submission` is **21 characters**. WordPress limits post type names
to 20, so `register_post_type()` has always returned
`post_type_length_invalid` and the submissions post type has never registered.
Saved submissions are therefore not listed in wp-admin. This dates back to the
form builder's initial commit and is unrelated to the merge; it is preserved
verbatim so the merge stays behaviour-for-behaviour.

Fixing it means renaming the post type to at most 20 characters, for example
`cjw_wizard_entry`, plus a one-off update of existing rows:

```sql
UPDATE wp_posts SET post_type = 'cjw_wizard_entry' WHERE post_type = 'cjw_wizard_submission';
```

Check the row count on production before deciding — the local site has 0.
