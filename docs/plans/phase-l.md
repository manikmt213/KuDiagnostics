# Phase L — paid products and the rest of the catalogue (started 2026-11-18)

Owner's direction (2026-11-18, relayed): every listed integration built in full. Licensed copies of most paid products are now installed (inactive) on the owner's test site; six products are not available and are built from official docs or source with stand-ins. A GitHub release follows once everything is finished and verified (confirmed with the owner in the session before tagging).

## How each product is built

1. **Source first.** Paid code is read on the test site through the plugin/theme file editor (logged-in browser pane), free code from wordpress.org ZIPs. Findings go to `RESEARCH.md`; anything not read is marked UNVERIFIED.
2. **The plugin's own API.** As D57: the plugin's own save path, abilities or documented API (`GFAPI`, `jet_engine()`, WPML's `wpml_*` hooks, `pll_*` functions, Rank Math/SEOPress/Yoast/AIOSEO Pro APIs …), never guessed meta keys.
3. **Tests.** Playground with the real ZIP when one is in the repo folder (git-ignored, `KUDUY_LOCAL_PLUGINS`, as Elementor Pro today); otherwise a stand-in in `tests/integration/fakes/` built from the source read on the site, in CI. Then a **live check** on the test site (temporary OAuth, the owner approves, revoked afterwards; one stack active at a time; WPML never with Polylang, Polylang never with Polylang Pro; test content removed; no code or PHP abilities).
4. Records per product: `FEATURES.md`, `INTEGRATION-MATRIX.md`, catalogue note, `TESTING.md`, `COMPATIBILITY.md` (version and date), `CHANGELOG.md`.

## Order

| # | Group | Products (version on the site) | Scope |
|---|---|---|---|
| L1 | Forms | Gravity Forms 3.2.2; WPForms Pro (`wpforms`); Formidable Pro 7.0.1 | Verify the Gravity adapter (built from docs) on 3.2.2; Gravity form editing per D57 through `GFAPI`; WPForms Pro entries verified; Formidable Pro fields and entry status |
| L2 | SEO Pro | Rank Math Pro 3.0.125, SEOPress Pro 10.2.1, Yoast Premium 28.8, AIOSEO Pro 5.0.5 | Redirects (all four), Pro schema types, Yoast Premium related keyphrases |
| L3 | Code and dynamic data | Code Snippets Pro 3.10.2, Dynamic Shortcodes 1.9.4 | Pro snippet types (CSS/JS) with the D4 rules; dynamic shortcodes listed and checked |
| L4 | Translation | WPML 5.3.0; Polylang Pro 3.9.2, Polylang for WooCommerce 2.2.7 | Languages, translation links, missing translations; Pro string translation; product translations |
| L5 | Cache | WP Rocket 3.24 | Status, purge (page, all), preload, settings read |
| L6 | Fields | JetEngine 3.8.15.4 (+ ACF Pro, ASE Pro by stand-in); free Meta Box, Pods, ACPT, ASE in Playground (phase E) | Post types, meta boxes / field groups, values through each plugin's API |
| L7 | Elementor add-ons | JetElements 2.9.2.2, Dynamic Content for Elementor 3.4.20 | Widget lists and settings through Elementor's own document API; DCE PDF templates |
| L8 | Block Pro | GenerateBlocks Pro 2.8.0, Spectra Pro 1.2.11, Kadence Shop Kit 3.0.10 (+ Kadence Blocks Pro by stand-in) | Pro settings and Pro block data through each plugin's save path |
| L9 | Builders and themes | Brizy Pro 2.8.13, Builder Mosaic 3.6.2, Oxygen Classic 4.9.6, MyListing 3.2; add-ons Advanced Themer, BricksExtras, Divi Pixel, Divi Supreme Pro, Mega Addons and Ultimate Addons for WPBakery (+ Etch, Voxel, Bricksforge by stand-in) | Detection and catalogue rows for every add-on; reading and editing where each product has a save path |

Each group is planned in more detail when it starts (its own section here), built test-first, and live-checked before the next group.

## Blocked until a copy arrives

Etch, Voxel, Bricksforge, ACF Pro, ASE Pro, Kadence Blocks Pro: built from official docs or source with stand-ins and marked "tested against a stand-in" in the catalogue and `FEATURES.md`.

## Status (2026-11-18)

Built from source read on the owner's site and tested against stand-ins (full round green on both pairs):

| # | Done | Left |
|---|---|---|
| L1 | Gravity form editing (fields, settings, notifications, undo) | WPForms Pro and Formidable Pro checks need their licensed ZIPs or a live check |
| L2 | Redirects for Yoast Premium, SEOPress PRO, AIOSEO Pro (D59) | Pro schema types; Yoast Premium related keyphrases |
| L3 | Dynamic Shortcodes (types, strict check, Power Shortcodes); Code Snippets Pro conditions listed; snippet credential masking fixed | Saving Pro conditions |
| L4 | WPML backend (D60); missing translations; Polylang string translations | Polylang for WooCommerce product sync (unverified); WPML String Translation |
| L5 | WP Rocket settings, changes with undo, cache state (its abilities); purge verified | — |
| L6 | JetEngine features registry (D61) | ACF Pro repeater / flexible content, ASE Pro (no copies) |
| L7 | JetElements widgets through the Elementor tools (registration read); DCE features and PDF templates | — |
| L8 | GenerateBlocks Pro switches via the GenerateBlocks settings tool; Spectra Pro via block tools; Kadence Shop Kit modules (read only) | Shop Kit module changes (save path not found); Kadence Blocks Pro (no copy) |
| L9 | Catalogue rows: Oxygen Classic, Mosaic, Bricks/Divi/WPBakery add-ons (detection) | Reading Oxygen Classic pages; Mosaic deeper (next round); Etch, Voxel, Bricksforge (no copies) |

Live checks on the owner's site (temporary OAuth, one stack at a time) are still to do for every product above.
