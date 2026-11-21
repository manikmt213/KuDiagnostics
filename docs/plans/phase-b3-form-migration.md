# Phase B3 — form migration (started 2026-11-21)

From `docs/plans/ecosystem-spec-gap.md` (row 12, spec 7.9): canonical export, field-type map, preview, execute, verify. Exit gate: a Contact Form 7 → Fluent Forms migration with a report. Built on phase B2's editors (`FormEditor`: each plugin's own save path, D57), so a migration writes exactly what `kuduy-P-form-create` and `kuduy-P-form-edit` would.

## Research (2026-11-21)

Field types and the properties each takes were read from the plugins themselves through the B2 editors (`field_types()`, Playground, WP 7.1.5) — Contact Form 7 6.2.4.1, Fluent Forms 6.2.17, WPForms Lite 2.0.4, Ninja Forms 3.16.1, Formidable 7.0; Gravity Forms from the B2 stand-in (types from its source, phase L):

| Canonical | CF7 | Fluent Forms | WPForms Lite | Ninja Forms | Formidable | Gravity Forms |
|---|---|---|---|---|---|---|
| text | text | input_text, input_mask | text | textbox | text | text |
| email | email | input_email | email | email | email | email |
| textarea | textarea | textarea | textarea | textarea | textarea | textarea |
| number | number, range | input_number | number | number | number | number |
| tel | tel | phone (Pro) | phone (Pro) | phone | phone | phone |
| url | url | input_url | url (Pro) | — | url | website |
| date | date | input_date | date-time (Pro) | date | date | date |
| select | select | select | select | listselect | select | select |
| radio | radio | input_radio | radio | listradio | radio | radio |
| checkbox | checkbox | input_checkbox | checkbox | listcheckbox | checkbox | checkbox |
| acceptance | acceptance | gdpr_agreement, terms_and_condition | — | checkbox (single) | gdpr | consent |
| file | file | input_file (Pro) | file-upload (Pro) | file_upload (Pro) | file | fileupload |
| hidden | hidden | input_hidden | hidden (Pro) | hidden | hidden | hidden |

Properties: label (Formidable writes it as `name`), required, placeholder, description (Fluent `help_message`, Ninja `help_text`), default (Fluent `value`, Gravity `defaultValue`, others `default_value`), choices (Ninja and Formidable `options`), key (Fluent `name`, Ninja `key`). A property is only sent when the target type lists it. CF7: label = text inside the tag's `<label>`, placeholder = first value with the `placeholder` option, choices from values (`label|value` pipes split), acceptance text = the tag's content, submit text = the submit tag's value, confirmation = message `mail_sent_ok`.

## Decision D83 — how forms are migrated (recommended option, for review)

| Option | What it means |
|---|---|
| **A. Canonical model over the B2 editors (recommended)** | `kuduy-forms-export` reads a form of any of the six plugins into the canonical model above (fields, submit text, confirmation message; notifications only counted). `kuduy-forms-migrate` maps it onto the target plugin's types and properties, falls back where the target lacks a type (tel, url, date, number, email → text without that validation; acceptance → a one-choice checkbox), and lists what it cannot carry (e.g. a CF7 quiz, a WPForms Lite file field). Without `confirm: true` it only returns that plan; with it, it creates a **new** form through the target editor (`create()` + one `apply()`) and verifies it by reading the new form back. The source form, its entries and its notifications are not touched or moved; notifications are named for re-creation with `kuduy-P-notification-save` (admin, merge tags differ per plugin). Targets: the five B2 editors (CF7 is a source only). No undo needed: nothing existing changes. |
| B. Direct plugin-to-plugin converters | 30 pairs, no shared verification |
| C. Import files of each plugin | Not every plugin has an importer API; rule 4 |

## Tools

| Tool | Access | Through |
|---|---|---|
| `kuduy-forms-export` | `kuduy:read`; the plugin's own edit permission for that form | B2 editors' `form()`; CF7 tags (`Cf7Tags::tags()`) and messages |
| `kuduy-forms-migrate` | `kuduy:write`; edit permission on the source, create permission in the target | target editor's `check_properties()`, `create()`, `apply()`; verify with `form()` |

## Tests

`FormMigrationTest` (FM1–FM4) in the forms rows (CF7, Fluent, WPForms Lite; Ninja, Formidable): FM1 CF7 export (labels, required, placeholder, choices, acceptance, submit text, confirmation; quiz skipped); FM2 CF7 → Fluent plan changes nothing, then migration creates the form, keys kept as Fluent names, verify all ok, source unchanged (exit gate); FM3 Fluent → WPForms Lite with fallbacks (url → text) reported; FM4 permissions (author refused, contributor cannot export) and an unknown form refused. Ninja and Formidable as targets in their row (FM5).

## Status

Built and tested 2026-11-21 (`TESTING.md`, Phase B3). Exit gate (CF7 → Fluent Forms with report) passed in Playground on both pairs. Not yet live-checked on the owner's site.
