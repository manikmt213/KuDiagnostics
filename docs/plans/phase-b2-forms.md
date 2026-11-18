# Phase B2 — form editing (built and tested 2026-11-18)

Spec part 7 and `ecosystem-spec-gap.md` §4.3: change forms, fields, confirmations and notifications in Contact Form 7, WPForms Lite, Fluent Forms, Ninja Forms and Formidable Forms. Entries already have shared tools (`kuduy-forms-*`, phase 7); Gravity Forms and the Pro editions stay in phase L.

## What each plugin's own editor calls (read from source, 2026-11-18)

| Plugin (version read) | Form and fields | Confirmation | Email notifications | Field types |
|---|---|---|---|---|
| WPForms Lite 2.0.4 | Form data is JSON in the `wpforms` post. The builder's save (`wpforms_save_form()`, `includes/admin/ajax-actions.php`) prepares and sanitizes the data and calls `wpforms()->obj('form')->update( $id, $data, ['context' => 'save_form'] )`; new forms `->add( $title, [], ['builder' => true] )`; field ids from `->next_field_id( $form_id )`. Capabilities: `wpforms_current_user_can( 'create_forms' / 'edit_form_single', $id )` | `settings.confirmations[n]` (type message/page/redirect) | `settings.notifications[n]` (email, subject, sender_name, sender_address, replyto, carboncopy, message); `settings.notification_enable` | field classes in `includes/fields/` register through `wpforms_builder_fields_buttons`; new-field defaults through `wpforms_field_new_default` |
| Fluent Forms 6.2.17 | `FormService::store()` (new, from a predefined blank form) and `FormService::update( ['form_id','title','formFields'] )` → `Updater` (duplicate-name check, field sanitizing without `unfiltered_html`). Capability: `Acl::hasPermission( 'fluentform_forms_manager', $form_id )` | `formSettings.confirmation` meta through `SettingsService::store()` (validated by `Validator::validate('confirmations')`) | `notifications` meta rows through `SettingsService::store()` / `remove()` (validated, sanitized) | `FormService::components( $form_id )`: every field with its full default structure |
| Ninja Forms 3.16.1 | Builder save (`NF_AJAX_Controllers_Form::save`): `Ninja_Forms()->form( $id )->get()->update_settings()->save()`, fields through `->field()->get()` models, `->get_field( $id )->delete()`, then the form cache (`WPN_Helper::build_nf_cache()`). Capability: `manage_options` filtered by `ninja_forms_admin_all_forms_capabilities` | `successmessage` action (`success_msg`) | `email` actions (to, from_name, from_address, reply_to, email_subject, email_message, cc, bcc, active) through `->get_action()` models | `Ninja_Forms()->fields` (registered field classes with their settings) |
| Formidable 7.0 | `FrmForm::create` / `FrmForm::update`, `FrmFieldsHelper::setup_new_vars( $type, $form_id )` + `FrmField::create`, `FrmField::update`, `FrmField::destroy` (**also deletes every entry's value for the field**). Capability: `frm_edit_forms` | `on_submit` form action (`FrmOnSubmitAction`) | `email` form actions (`FrmFormAction`: `prepare_new`, `save_settings`, `get_all`) | `FrmField::field_selection()` (free) |
| Contact Form 7 6.2.4.1 | Form markup of form-tags, saved with `wpcf7_save_contact_form()` (existing `kuduy-forms-cf7-save`) | `messages` (existing) | `mail` / `mail_2` (existing `kuduy-forms-cf7-mail`) | `WPCF7_FormTagsManager::get_instance()->get_tag_types()`; `WPCF7_ConfigValidator` for checks |

## Decision D57 — how forms are edited (recommended option taken, for review)

| Option | What it means |
|---|---|
| **A. Per plugin, through each editor's own save path (recommended)** | One small tool set per plugin (D43) on the calls in the table: list field types, create a form, edit a form with operations (add, change, move and remove fields; title, submit button, confirmation message), save an email notification. New fields start from the plugin's own defaults. Snapshot first, undo with `kuduy-snapshot-restore`. **Removing a field is refused while any entry holds a value for it** (Formidable deletes those values with the field; others would orphan them). **Notification routing** (recipient, sender, Cc/Bcc, reply-to, on/off) needs `kuduy:admin` and is confirm-first, as CF7 mail (D16 A). Forms are not deleted here. Redirect confirmations are not set here (a redirect to any URL is outside a form edit) |
| B. One shared `kuduy-forms-edit` with a plugin argument | Fewer tools, but each plugin's field model differs so much that one schema would be vague, and D43 asks for per-plugin sets |
| C. Raw JSON writes of each plugin's stored form | Rejected: rule 4 (official APIs only) |

## Tools (19)

For P in `wpforms`, `fluent-forms`, `ninja-forms`, `formidable`:

| Tool | Scope | Capability | Undo |
|---|---|---|---|
| `kuduy-P-field-types` — field types the plugin offers on this site, with the settings a new field of that type has | read | plugin's form-edit capability | — |
| `kuduy-P-form-create` — new form with title and fields | write | plugin's create capability | none (as CF7: forms are not deleted here) |
| `kuduy-P-form-edit` — up to 30 operations at once: `add` (type, label, required, placeholder, description, choices, default, settings limited to the type's own keys, index), `update` (field id + the same), `move`, `remove`; `form` (title, submit button text, confirmation message). Checked before anything is saved; one save | write | plugin's form-edit capability, per form | whole form restored through the same API |
| `kuduy-P-notification-save` — create or change one email notification | **admin** | as edit + `manage_options` | previous notification restored; a created one removed |

Contact Form 7 (markup of form-tags; mail and messages stay in the existing tools):

| Tool | Scope | Undo |
|---|---|---|
| `kuduy-cf7-form-tags` — tag types CF7 offers on this site, and the tags of a form with name, type, required, options, values | read | — |
| `kuduy-cf7-form-edit` — `add` (type, name, required, options, values, label wrapped as CF7's template does, index or after a tag), `update`, `remove`, `move` of form-tags by name; saved through `wpcf7_save_contact_form()`; returns CF7's configuration errors | write | form restored with `save()` |
| `kuduy-cf7-validate` — CF7's own configuration check of a form or of new markup (not saved) | read | — |

## Tests (Playground, real plugins; CI rows already install them)

| # | Test |
|---|---|
| FB1 | WPForms Lite: types listed; form created with name, email, select with choices; edit adds, changes, moves, removes a field, sets submit text and confirmation; the front end renders the new field; undo; removal refused with an entry (Lite keeps no entries, so tested by a stored entry count stand-in only when Pro is present — otherwise skipped and recorded) |
| FB2 | Fluent Forms: same, plus duplicate name refused by Fluent's own check; removal refused while an entry has a value; notification saved (confirm-first for routing), undo |
| FB3 | Ninja Forms: same; the cache matches the models after a save; notification (email action) and success message |
| FB4 | Formidable: same; removal refused with an entry value (its values would be deleted); email action and on-submit message |
| FB5 | CF7: tag types; add/update/move/remove tags; invalid tag refused with CF7's validator; undo |
| FB6 | Permissions: author and editor refused per plugin; notification needs admin scope; routing change without `confirm` changes nothing |

Process: memory `build-first-test-last` (tests written with the code, suites run once at the end), then security pass, records, one push.
