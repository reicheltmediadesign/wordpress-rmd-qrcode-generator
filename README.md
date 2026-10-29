# RMD QR Code Generator

WordPress plugin to create QR codes in the admin area – for links, text, calendar events and Girocode (SEPA credit transfer). Built by [reichelt media.design](https://reicheltmedia.design).

## Features

- **QR code types:** link/URL, free text, event (vCalendar) and Girocode (EPC QR code for SEPA transfers, IBAN checksum validated).
- **PNG or SVG**, size 100–1000 px, custom foreground and background colours with a contrast warning.
- **Presets:** quick-select buttons that fill the form, managed per site under *QR Generator → Settings*. Preset fields may contain shortcodes (e.g. from a global variables plugin), resolved when the form is opened.
- **Role access:** administrators always; further roles can be enabled in the settings.
- **Clean-up page** to review and delete generated files by month.
- Filters `rmd_qr_presets` and `rmd_qr_allowed_roles` for presets and roles from code.

The admin interface is in German.

## Installation

1. Download `rmd-qrcode-generator.zip` from the latest [release](https://github.com/reicheltmediadesign/wordpress-rmd-qrcode-generator/releases). Do not use the “Source code” downloads – their folder name differs from the plugin's.
2. In WordPress go to Plugins → Add New → Upload Plugin, choose the zip and activate *QR Code Generator*.
3. Optional: set up roles and presets under *QR Generator → Settings*.

## Documentation

See [doc/presets.md](doc/presets.md) for settings, Girocode and the filters.

## Privacy

QR codes are rendered by the external service [goQR.me / api.qrserver.com](https://goqr.me/api/). The content of each code (URL, text, event data, or Girocode payment data incl. IBAN) is sent to that service over HTTPS. Generated files are stored in the WordPress uploads folder.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
