# Changelog

All notable changes to this plugin are documented here. This project follows
[Semantic Versioning](https://semver.org/) and tags releases as `vMAJOR.MINOR.PATCH`.

## [Unreleased]

### Added
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
