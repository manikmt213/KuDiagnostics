# Phase Q — builders and themes completion (built 2026-11-17/18)

The ten items approved by the owner on 2026-11-17 (`ecosystem-spec-gap.md` §8). Sources were read from the free
plugins' wordpress.org ZIPs, and for paid products through the plugin and theme file viewers on the owner's test site
(read only, nothing changed there). Live checks of the new tools with a temporary OAuth connection are still to do.

| # | Item | Built | Through | Tests |
|---|---|---|---|---|
| Q1 | Divi 5: edit pages | `kuduy-block-edit` works on Divi 5 pages; new `merge` option for nested settings; Divi 4 shortcode pages refused by content writes | Divi 5.19 registers every module as a WordPress block type (`ModuleLibrary\ModuleRegistration::register_module()`), and `ET_Core_PageResource::save_post_cb()` clears its static CSS on `save_post` | BK4 (stand-in) |
| Q2 | Kirki 6: edit pages | `kuduy-kirki-edit`: text, attribute, remove, move, duplicate; unpublished version by default, `publish` optional | Kirki 6.4.2 page manager facade `Kirki\App\Supports\Facades\Page`: `get_stage_version_for_save()`, `save_blocks()`, `set_last_edited_datetime_of_stage_version()`, `save_editor_mode()`, `publish_stage_version()` | KK5, KK6 (real Kirki 6.4.2) |
| Q3 | Etch: edit pages | As Q1 (block based); Etch block names still unverified | — | BK4 covers the shared path |
| Q4 | Avada Global Options | `kuduy-avada-global-options-update` | `Fusion_Settings` (Avada 7.16 `includes/lib/inc/class-fusion-settings.php`): option `get_option_name()`; all changes merged and saved once (set() saves one key from values loaded at start); `get_available_options()`, `fusion_reset_all_caches()` | AO1 (stand-in) |
| Q5 | Elementor Pro forms, Pro dynamic tags | `kuduy-elementor-forms`, `-form-submissions`, `-form-submission-get`, `-form-submission-update`; Pro dynamic tags already go through `kuduy-elementor-dynamic-tags` / `-dynamic-set` | `ElementorPro\Modules\Forms\Submissions\Database\Query` (Pro 4.0.4): `get_submissions()` filters status/form/search, `get_submission()`, `update_submission()`, `move_to_trash_submission()`, `restore()`; REST permission `manage_options` | EF1–EF3 (stand-in) |
| Q6 | WPBakery templates | `kuduy-wpbakery-templates`, `-template-get`, `-template-save`, `-template-delete` | WPBakery 9.1 `Vc_Templates_Panel_Editor`: option `wpb_js_templates` (id => name, template), id `sanitize_title( name ) . '_' . wp_rand()`, access edit_posts/edit_pages + Role Manager part `templates` | WB13 (stand-in) |
| Q7 | Template parts and synced patterns | `kuduy-site-template-create` (templates and parts with area); `kuduy-synced-pattern-get`, `-save`, `-trash` | Core `/wp/v2/templates`, `/wp/v2/template-parts`, `/wp/v2/blocks` in process; meta `wp_pattern_sync_status` (`unsynced`, or none for synced) | TP1, SP1 |
| Q8 | Bricks, Oxygen 6, Breakdance site-wide settings | `kuduy-{bricks,breakdance,oxygen}-site-ability-run` (D45 confirmation token) | The builders' own abilities; options with the builder's prefix and the home page settings snapshotted (credentials left out); delete, restore, import, upload, run, PHP and report abilities refused | BA5, BR5 (stand-ins) |
| Q9 | Spectra Legacy settings | `kuduy-spectra-settings-update`, status shows the settings | One option each, `UAGB_Admin_Helper::update_admin_settings_option()` as `admin-core/ajax/common-settings.php` (2.20.6); secrets, social, Instagram, global block styles and font lists left out | SL1 (real Spectra Legacy 2.20.6) |
| Q10 | Catalogue notes | Notes rewritten against `TESTING.md` / `COMPATIBILITY.md` evidence | — | IntegrationsTest |

Not built (documented limits): Brizy layout editing, Weglot translations, paid products without a copy, Oxygen 4;
Kirki styles, classes and new elements; Elementor Pro submission export and permanent delete; WPBakery shared
(cloud) templates.
