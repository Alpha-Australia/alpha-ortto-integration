# Changelog

All notable changes to this plugin are documented here. This project follows
[Semantic Versioning](https://semver.org/) and tags releases as `vMAJOR.MINOR.PATCH`.

## [Unreleased]

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
