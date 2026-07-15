# Changelog

All notable changes to this plugin are documented here. This project follows
[Semantic Versioning](https://semver.org/) and tags releases as `vMAJOR.MINOR.PATCH`.

## [Unreleased]

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
