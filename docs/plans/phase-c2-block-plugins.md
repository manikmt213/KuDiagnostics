# Phase C2 — block plugins' own tools (2026-11-17)

Per D43, each block plugin gets tools for what it manages itself; placing and editing its blocks stays with the core block tools of C1. Source read from the wordpress.org ZIPs (GenerateBlocks 2.5.1, Kadence Blocks 3.9.1, Spectra Legacy 2.20.6, Spectra Blocks 1.0.15); facts in `RESEARCH.md`.

| Plugin | Tools | Save path used |
|---|---|---|
| GenerateBlocks | `kuduy-generateblocks-status`, `-settings-update` | Its REST route `generateblocks/v1/settings` (manage_options; only keys of `generateblocks_get_option_defaults()`; its sanitizers) |
| Kadence Blocks | `kuduy-kadence-blocks-status`, `-settings-update` | `/wp/v2/settings` for its `register_setting( show_in_rest )` options, as its settings screen; keys with API keys or secrets are write-only and not changed here |
| Spectra Legacy | `kuduy-spectra-status`, `kuduy-spectra-block-toggle`, `kuduy-spectra-popups`, `-popup-get`, `-popup-save`, `-popup-trash` | `UAGB_Admin_Helper::update_admin_settings_option( '_uagb_blocks' )` then `create_specific_stylesheet()` when it builds one file, as its Blocks dashboard; popups are `spectra-popup` posts with three registered fields |
| Spectra Blocks | `kuduy-spectra-blocks-abilities`, `-ability-get`, `-ability-run`, `kuduy-spectra-blocks-popups`, `-popup-get`, `-popup-save`, `-popup-trash` | Its own WordPress abilities (off until the owner switches them on), run with the existing builder-abilities rules (one page or popup at a time, snapshot first, site-wide and deleting abilities refused); popups are `spectra-blocks-popup` posts |

Not built, with reason: Kadence block hiding (its dashboard no longer offers it); GenerateBlocks Pro, Kadence Blocks Pro and Spectra Pro features (no copies); Spectra Legacy's other settings screens (next round).

Tests: `BlockPluginsTest` BP1–BP7 against the real plugins (CI job "+ block plugins", both pairs).
