# QR Code Generator – Presets und Zugriff

## Einstellungen

Unter **QR Generator → Einstellungen** (nur für Administratoren) wird das Plugin pro Website eingerichtet.

### Zugriff

Administratoren haben immer Zugriff. Zusätzlich lassen sich beliebige Rollen freischalten, z. B. eigene Redakteursrollen der Kundenwebsite. Freigeschaltete Rollen sehen die Seiten „Erstellen“ und „Aufräumen“, nicht aber die Einstellungen.

### Presets

Presets erscheinen auf der Seite „Erstellen“ als Schnell-Auswahl und füllen das Formular vor. Jedes Preset hat eine Bezeichnung, einen Typ und einen Status (aktiv/inaktiv). Inaktive Presets werden ausgegraut angezeigt.

| Typ | Felder |
| --- | --- |
| Link/URL | URL |
| Text | Text (max. 500 Zeichen) |
| Event/Termin | Titel, Ort, Start, Ende, Beschreibung |
| Girocode | Empfänger, IBAN, BIC (optional), Betrag (optional), Verwendungszweck |

Presets ohne Bezeichnung werden beim Speichern verworfen. Bei einer ungültigen IBAN erscheint ein Hinweis, das Preset wird trotzdem gespeichert.

### Shortcodes in Presets

Alle Textfelder eines Presets dürfen Shortcodes enthalten, z. B. aus dem Plugin „Custom Global Variables“:

| Feld | Wert |
| --- | --- |
| Empfänger | `[cgv vereinsname]` |
| IBAN | `[cgv iban]` |
| Verwendungszweck | `Spende an [cgv vereinsname]` |

Werte mit Shortcode werden unverändert gespeichert und erst beim Öffnen der Seite „Erstellen“ aufgelöst. Ändert sich der Wert der Variable, zieht das Preset automatisch nach. HTML aus der Shortcode-Ausgabe wird entfernt; IBAN, BIC und Betrag werden danach normalisiert. Die IBAN-Prüfung findet in diesem Fall erst beim Erzeugen des QR-Codes statt.

Datumsfelder (Start/Ende eines Termins) unterstützen keine Shortcodes.

## Girocode

Girocode ist der EPC-QR-Code (EPC069-12, Version 002) für SEPA-Überweisungen. Banking-Apps übernehmen daraus Empfänger, IBAN, Betrag und Verwendungszweck. Die BIC ist innerhalb des EWR optional. Bleibt der Betrag leer, wird er in der App eingegeben – praktisch z. B. für ein Spendenkonto.

Das Plugin prüft die IBAN-Prüfsumme und das BIC-Format, bevor der Code erzeugt wird.

## Presets per Code

Für Presets, die nicht über die Oberfläche gepflegt werden sollen (z. B. aus einem Theme oder Must-Use-Plugin), gibt es den Filter `rmd_qr_presets`:

```php
add_filter('rmd_qr_presets', function ($presets) {
    $presets[] = array(
        'type' => 'link',
        'label' => 'Website',
        'disabled' => false,
        'data' => array(
            'type' => 'link',
            'url' => 'https://example.com',
            'title' => 'Website'
        )
    );
    return $presets;
});
```

Schlüssel in `data` je Typ:

- `link`: `url`
- `text`: `text-content`
- `event`: `event-title`, `event-location`, `event-start`, `event-end`, `event-description` (Datum im Format `YYYY-MM-DDTHH:MM`)
- `girocode`: `giro-name`, `giro-iban`, `giro-bic`, `giro-amount` (z. B. `25.00`), `giro-purpose`

`title` wird in der Erfolgsmeldung nach dem Laden angezeigt. Der Filter läuft erst beim Aufruf der Seite „Erstellen“, Callbacks aus der `functions.php` des Themes greifen also.

## Rollen per Code

Der Filter `rmd_qr_allowed_roles` erhält die in den Einstellungen gewählten Rollen-Slugs und kann sie ergänzen:

```php
add_filter('rmd_qr_allowed_roles', function ($roles) {
    $roles[] = 'editor';
    return $roles;
});
```
