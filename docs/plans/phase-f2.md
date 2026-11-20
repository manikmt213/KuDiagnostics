# Phase F2 — Elementor add-on extras (started 2026-11-20)

From `docs/plans/ecosystem-spec-gap.md` (row 6): Elementor usage search, extensions, module switches, Unlimited Elements. Workflow as phase R: research first, build all with tests, one test round, security review, one push; new decisions take the recommended option and are marked **for review**.

## Research (2026-11-20, wordpress.org ZIPs; details in `RESEARCH.md` §10f2)

| Product | Version read | Where its switches live | Save path used |
|---|---|---|---|
| HT Mega | 3.2.9 | Options `htmega_element_tabs` (101 widgets) and `htmega_advance_element_tabs` (10 extensions + 4 modules with nested settings), values `on`/`off`; field list from `HTMegaOpt\Admin\Options_Field::instance()->get_registered_settings()` (Pro items flagged `is_pro`) | Its own REST route `POST htmegaopt/v1/settings` (manage_options + nonce `htmegaopt_verifynonce`); the route replaces the whole section with what is sent, so every switch of the section is sent |
| Element Pack Lite | 10.0.12 | Tiers `element_pack_active_modules` (core), `element_pack_third_party_widget`, `element_pack_elementor_extend` (extensions) | Its own REST routes `GET/POST element-pack/v1/modules` (`tier` + full `{slug: on/off}` map; capability check; asset optimizer refreshed as its screen does) |
| Happy Addons | 3.52.0 | Inactive lists `happyaddons_inactive_widgets`, `_features`, `_extensions` | `Happy_Addons\Elementor\Classes\Widgets_Manager::save_inactive_widgets()`, `Extensions_Manager::save_inactive_features()` / `save_inactive_extensions()`; maps from `Widgets_Manager::get_widgets_map()` (minus the base key), `Extensions_Manager::get_features_map()`, `get_extensions_map()`; always-on features kept on. Mega menu: not in the free plugin (only a nav-menu walker) — not built |
| Unlimited Elements | 2.0.28 | Widgets in its table `addonlibrary_addons` (type `elementor`), Elementor name `ucaddon_{alias}` | Read only: `UniteCreatorAddons::getArrAddons( '', array(), GlobalsUC::ADDON_TYPE_ELEMENTOR )`, `UniteCreatorAddon` getters (title, name, alias, active, category, description, params). Templates (HTML/CSS/JS) are not returned. No remote library installs (D55) |

## Tools

| Tool | Access | Through |
|---|---|---|
| `kuduy-elementor-usage` | `kuduy:read` + `edit_posts`; only posts the user can edit | Elementor data of every built post; per widget type: count, providing plugin, pages; for one widget or one add-on: the pages using it |
| `kuduy-elementor-widgets` (`plugin` filter added) | as before | — |
| `kuduy-elementor-extensions` | `kuduy:read` + `manage_options` | Callbacks on Elementor's element and render hooks (`elementor/element/…`, `elementor/frontend/…`, `elementor/widget/…`), grouped by the plugin that registered them, with the elements and sections they extend |
| `kuduy-elementor-addon-modules`, `-addon-module-set` | read: `kuduy:read`; set: `kuduy:admin`; both `manage_options` | Each add-on's own list and save path (table above); undo puts the previous switches back through the same path |
| `kuduy-unlimited-elements-widgets` | `kuduy:read` + `edit_posts` | Its own classes, metadata and parameter definitions only |

## Decisions

D67 usage search and extensions list as described (reads, no per-add-on code). D68 module switches only through each add-on's own save path; HT Mega's nested modules (theme builder, mega menu, …) and Pro-locked items are left to its screen. D69 Unlimited Elements read-only, no templates returned (they are code), no remote installs (D55 stands).

## Tests

`ElementorExtrasTest` (EX1–EX6) in the existing add-on CI rows (add-ons B: Happy, HT Mega; add-ons C: Element Pack) and a new row for Unlimited Elements 2.0.28 (a widget created with its own `UniteCreatorAddon::addSmall()`, as its widget creator does).

## Status

Built and tested 2026-11-20 (EX1–EX6 on both pairs; results in `TESTING.md`). Released in 1.6.0 (2026-11-20).
