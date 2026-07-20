# Changelog

All notable changes to this plugin are documented here. This project follows
[Semantic Versioning](https://semver.org/) and tags releases as `vMAJOR.MINOR.PATCH`.

## [Unreleased]

## [1.5.0]

### Added
- Web session linking: ties a visitor's anonymous Ortto tracking-code
  session to the contact identified on form submit, via a new
  account-wide "Enable Web Session Linking" + "Web Session Field ID"
  setting. When enabled, a small script (enqueued only on pages rendering
  a Gravity Form) mints or reuses a per-browser id, writes it into any
  Hidden field whose Default Value is set to the documented sentinel
  string, and tags the current Ortto tracking session with it via
  `ap3c.track()`. That field then just needs mapping to the configured
  Ortto field id (already configured in Ortto as an allowed tracking-code
  merge key) in the form's existing field mapping -- no other plugin code
  is involved once that's wired up. See the README's "Web session
  linking" section for the full setup recipe. Off by default.
- Per-form **Form Submit Activity** setting: when enabled, also records a
  custom "Form Submit" activity against the contact in Ortto (a separate
  call to `v1/activities/create`) whenever the feed sends successfully, with
  `str:cm:form-name`, `int:cm:form-id`, and `str:cm:entry-id` attributes.
  Attaches to the same contact the person merge targeted, and reuses any
  geolocation mapped via `location.source_ip`. The activity id is
  configurable per feed (defaults to `act:cm:form-submit`) and must already
  exist in Ortto (CDP -> Activities) or the activity call will fail --
  this never affects the contact sync itself. The entry-detail meta box
  and Resend button now cover the activity send alongside the contact
  sync. Off by default for feeds saved before this setting existed, so
  upgrading doesn't retroactively start sending activities for every
  existing feed at once.
- Per-form **Tags** feed setting: apply one or more fixed tags
  (comma-separated) in Ortto to every contact a form sends, regardless of
  what was submitted. Tags are added on top of any tag pulled from a field
  via the existing `tag` mapping key, and de-duplicated before sending.
- Field mapping's Ortto field column is now a dropdown of common fields
  (Email, First/Last name, Phone, City, State/region, Country, Postal code,
  External ID) plus the existing Tag / Geolocation special actions, with a
  **Custom field…** option for anything else (e.g. `str:cm:your-field`) --
  instead of requiring every mapping to be hand-typed.

### Fixed
- Picking "Custom Value" for a mapping row's value column (a raw string or
  merge tag, rather than a form field) silently sent nothing for that field:
  `send_to_ortto()` passed GF's literal `"gf_custom"` placeholder straight to
  `get_field_value()`, which can't resolve it, so the value came back empty
  and the row was dropped with no error. Now resolves `custom_value` (with
  merge tags replaced) the same way GF's own
  `GFAddOn::get_generic_map_fields()` does. No existing feed currently uses
  "Custom Value" on the value column, so nothing was actually being lost in
  practice, but this has been broken since 1.0.0.

## [1.4.2]

### Fixed
- Ortto's own "Verify"/Test-webhook click sends a generic test payload
  that doesn't carry real Account-journey data (no `account_id`, and/or
  no valid `id_to_convert`), and treats any non-2xx response from the
  endpoint as the webhook itself being broken -- blocking the journey
  step from being saved. A payload mismatch like this is no longer
  reported as a 400: the endpoint now returns 200 with
  `{"status": "skipped", "reason": "..."}` explaining what didn't match,
  and reserves non-2xx responses for things that are actually wrong on
  our end (auth failures, or the upstream call to Ortto's own API
  failing). A successful write now returns `{"status": "ok", ...}` for
  the same reason -- consistent shape either way.

## [1.4.1]

### Fixed
- Account Salesforce ID sync's `v1/accounts/merge` call always failed with
  `400 No valid accounts provided (can not apply mutation for
  str:oib:...)`: the 15 character field is synced by the Intercom data
  source integration, and Ortto rejects any merge that lists such a field
  in `fields` at all, even to set its own unchanged value. Now matches
  the Account by Ortto's own internal account id instead (included
  automatically as the reserved `account_id` key on every Account-journey
  webhook call) via `str:o:account_id`, never referencing the Intercom
  field. The plugin no longer needs (or has) an "Account field: 15 char
  Salesforce ID" setting -- the Salesforce ID value now comes directly
  from the webhook payload's `id_to_convert` field, and matching no
  longer depends on knowing which field holds it on the Account.

## [1.4.0]

### Added
- Account Salesforce ID sync now only writes the 18 character field if it's
  currently empty on the matching Account. Looks the Account up via Ortto's
  `v1/accounts/get` (filtering on the 15 character field, requesting only
  the 18 character field back) before deciding whether to call
  `v1/accounts/merge` -- so a re-delivered or re-run webhook never
  overwrites a value that's already there.

### Fixed
- The GitHub release cache (a 6 hour transient) was independent of
  WordPress's own update-check cadence, so clicking "Check again" on the
  Updates screen could keep showing a stale "no update" result for up to
  6 hours after a new release was published. Clicking "Check again" now
  bypasses the cache.

## [1.3.3]

### Changed
- Account Salesforce ID sync webhook now requires the mapped payload field
  to use the exact key name `id_to_convert`, instead of accepting generic
  names like `id` or `account_id`. Those collide with keys Ortto's webhook
  envelope already uses for its own purposes (e.g. the delivery's own
  internal id), which was causing silent misreads. Confirmed via a live
  test that renamed the key to a deliberately unrelated value
  (`bag_of_chickens`) and observed it correctly rejected.

## [1.3.2]

### Fixed
- Account Salesforce ID sync webhook (`/wp-json/alpha-ortto/v1/update-account-sf-id`)
  read the wrong id entirely: Ortto's standard webhook payload nests any
  mapped field inside a top-level `contact` object, but `WP_REST_Request::
  get_param()` only sees top-level keys -- so it was matching the payload's
  unrelated top-level `id` (the webhook delivery's own internal 24 character
  event id) instead of the mapped `contact.account_id` field, on every call.
  Now reads from inside `contact` first, falling back to the top level only
  for callers using a fully custom payload shape without that wrapper.

## [1.3.1]

### Fixed
- Account Salesforce ID sync webhook (`/wp-json/alpha-ortto/v1/update-account-sf-id`)
  only read the incoming ID from a JSON key named `id`, but Ortto's classic
  webhook action sends whatever key name you configure for that field (e.g.
  `account_id`), so every real call 400'd. Now accepts either `account_id`
  or `id`. Also clarified the settings copy: the two "Account field" id
  settings need the Ortto field id (`field_id`, e.g. `str:oib:...`), not the
  webhook payload's key name (`key_name`).

## [1.3.0]

### Added
- Account Salesforce ID sync: `POST /wp-json/alpha-ortto/v1/update-account-sf-id`
  (`X-Api-Key` header, JSON body `{ "id": "<15 char id>" }`). Ortto's "dynamic"
  webhook action (wait for response, update fields) only exists for Person
  journeys, not Account journeys, so this instead calls Ortto's
  `v1/accounts/merge` API directly to write the converted 18 character ID
  onto the matching Account. Configured via two new field-id settings
  ("Account field: 15/18 char Salesforce ID") under Forms → Settings → Ortto;
  the endpoint is disabled (403) until the webhook secret, API key, and both
  field ids are all set.

## [1.2.0]

### Added
- Salesforce 15 → 18 character ID converter webhook: `GET/POST /wp-json/alpha-ortto/v1/convert-id?id=...`
  with an `X-Api-Key` header, returning `{ "id_15": ..., "id_18": ... }`. Lets an
  Ortto webhook resolve the case-safe 18 character ID (needed for deduping)
  from a 15 character ID sent by an upstream system. Configured via a new
  "Webhook Secret" field under Forms → Settings → Ortto; the endpoint is
  disabled (403) until a secret is set.

## [1.1.1]

### Fixed
- Field mapping was never resolving to a value on send, for any form or
  feed, since 1.0.0: `send_to_ortto()` read the mapping through
  `GFAddOn::get_generic_map_fields()` without passing `$form`/`$entry`,
  which returns a different (already "resolved") array shape than the
  code expected, so every mapped field was silently skipped and every
  send failed with "No Ortto fields resolved to a value for this entry."
  The mapping rows are now read directly from the feed meta instead.
- Entry ID was always 0 in the Resend to Ortto meta box, because the
  object `do_meta_boxes()` passes to entry-detail meta box callbacks is
  `array( 'form' => ..., 'entry' => ..., 'mode' => ... )`, not the entry
  itself.

## [1.1.0]

### Added
- Resend to Ortto: each entry's detail page now shows an **Ortto** meta box
  (beneath Notifications) listing every Ortto feed's last send status, with a
  **Resend to Ortto** button to retry failed or missed sends.
- Per-entry send status is recorded (sent/failed, HTTP code, message, time) on
  both submission and manual resend, and each resend is logged as an entry note.
- GitHub-based auto-updater: releases published on GitHub appear as normal
  plugin updates in the WordPress dashboard.
- Release packaging workflow that builds a clean plugin zip on every version tag.
- CI workflow: PHP lint (8.2/8.3), WordPress Coding Standards, PHP 8.2 compatibility.
- Project scaffolding: `.gitignore`, `composer.json`, `phpcs.xml.dist`, README, LICENSE.

## [1.0.0]

### Added
- Ortto feed add-on for Gravity Forms: map form fields to Ortto person fields
  and send contacts to Ortto's `v1/person/merge` API on submission.
- Account-wide API key and region settings under Forms → Settings → Ortto.
- Per-form field mapping, merge-by key, tags, and geolocation support.
