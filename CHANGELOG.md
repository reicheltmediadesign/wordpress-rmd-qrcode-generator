# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.6.0] – 2026-10-29

### Added

- Automatic updates from GitHub releases (Dashboard → Updates), via plugin-update-checker and the `update.json` attached to each release.

### Changed

- Plugin name is now “RMD QR Code Generator”.

## [1.5.0] – 2026-10-29

### Added

- Shortcodes in preset fields, e.g. `[cgv iban]`; stored as entered and resolved when the generator page opens.
- Mini QR code as menu icon.

### Fixed

- `rmd_qr_presets` filters from a theme's `functions.php` now take effect (presets are loaded lazily).

## [1.4.0] – 2026-10-29

### Added

- Settings page (administrators only) for allowed roles and presets.
- Girocode (EPC QR code for SEPA credit transfers) with IBAN checksum and BIC validation.
- Filters `rmd_qr_presets` and `rmd_qr_allowed_roles`.

### Changed

- Site-specific roles and presets are no longer hard-coded.
- QR codes are requested from api.qrserver.com over HTTPS.
