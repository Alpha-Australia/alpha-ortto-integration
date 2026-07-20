# Alpha Ortto Integration

A WordPress plugin that adds an **Ortto** feed to Gravity Forms. Map form
fields to Ortto person fields and send contacts to Ortto's
`v1/person/merge` API directly on submission — no Webhooks add-on or
blur-capture required.

> This lives in its own plugin (not the theme) because Gravity Forms' Add-On
> Framework registers on the `gform_loaded` action, which fires while plugins
> load — before the active theme's `functions.php` runs.

## Requirements

- WordPress 6.0+
- PHP 8.2+
- Gravity Forms 2.5+

## Installation

1. Download `alpha-ortto-integration.zip` from the
   [latest release](https://github.com/Alpha-Australia/alpha-ortto-integration/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip,
   and activate.
3. Add your Ortto Private API key under **Forms → Settings → Ortto**.
4. On any form, open **Settings → Ortto** to create a feed and map fields.

Once installed, the plugin updates itself: new GitHub releases show up as
normal plugin updates in **Dashboard → Updates**.

## Configuration

**Account-wide** (Forms → Settings → Ortto):

- **Ortto Private API Key** — from Ortto: Data sources → your Custom API data
  source → Configuration.
- **Region** — only if your Ortto account is on a regional instance (AU/EU).

**Per-form** (Forms → [Form] → Settings → Ortto):

- **Merge by** — the Ortto field used to match existing contacts (usually
  `str::email`).
- **Field mapping** — left column is an Ortto field (`str::email`, `str::first`,
  a custom `str:cm:your-field`, or the special keys `location.source_ip` and
  `tag`); right column is the Gravity Forms field/meta to pull from.
- **Form Submit Activity** — when enabled, also records a "Form Submit"
  activity against the contact in Ortto whenever this feed sends
  successfully, attached to the same contact via the same field mapping.
  The activity id (below, defaults to `act:cm:form-submit`) must already
  exist in Ortto (CDP → Activities) before enabling this, or the activity
  call will fail (this never affects the contact sync itself). Off by
  default for feeds that existed before this setting.
- **Activity ID** — the Ortto custom activity id to record, if Form Submit
  Activity is enabled.
- **Condition** — optionally only send entries that meet a condition.

## Development

Install dev tooling and run the checks locally:

```bash
composer install
composer run lint      # WordPress Coding Standards (PHPCS)
composer run lint:fix  # auto-fix what PHPCBF can
composer run compat    # PHP 8.2+ compatibility check
```

## Releasing

Releases are automated. Bump the version, tag it, and push the tag:

1. Update the version in `alpha-ortto-integration.php` (both the plugin header
   and the `ALPHA_ORTTO_ADDON_VERSION` constant) and add a `CHANGELOG.md` entry.
2. Commit, then:

   ```bash
   git tag v1.1.0
   git push origin v1.1.0
   ```

The **Package WordPress Plugin** workflow builds a clean zip and attaches it to
a GitHub release. Sites running the plugin pick up the update automatically.

## Continuous integration

Every push and pull request runs three checks (`.github/workflows/ci.yml`):

- **PHP Lint** — syntax check on PHP 8.2 and 8.3.
- **WordPress Coding Standards** — PHPCS with the `WordPress-Extra` ruleset.
- **PHP Compatibility** — flags anything incompatible with PHP 8.2+.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
