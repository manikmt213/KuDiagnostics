# Ecosystem specification: gap analysis and plan (2026-11-16, from v1.3.5)

Source: the owner's "Master Development Specification — WordPress MCP Plugin Integration Ecosystem" (Parts 1–25), compared with the code in `src/`, the generated `docs/TOOLS.md` (243 tools), `INTEGRATION-MATRIX.md`, `FEATURE-GAP.md` and `IMPLEMENTATION-PLAN.md` (phases A–L).

Most of the specification is already planned in phases A–L. This file does three things:

1. Lists where the specification **conflicts with decisions already made**, so the owner can decide once (§1).
2. Maps every part of the specification to **what exists, what is planned, and what is new** (§2–§4).
3. Proposes the **order of work** from here, reusing phases C, E, G, I, J, K, L and adding phases M–P for the new items (§5).

Nothing in this file is built yet. Status words follow `FEATURES.md`.

---

## 1. Decisions (conflicts with existing rules or decisions)

**Decided by the owner on 2026-11-16:**

| # | Decision |
|---|---|
| D43 | **`kuduy-*` names, one tool set per plugin** (e.g. `kuduy-gravity-forms-list`, `kuduy-acf-field-group-save`, `kuduy-yoast-term-update`), following the spec's per-plugin lists. Existing shared tools (`kuduy-forms-*`, `kuduy-seo-*`, `kuduy-acf-*` …) stay unchanged for backward compatibility; new per-plugin tools reuse the existing adapters underneath, so logic is not duplicated. Tools stay hidden when their plugin is inactive. Phase K measures the size of `tools/list` per site and may add a compact listing mode. |
| D45 | Confirmation tokens for the high-risk list (§3.4) only; low-risk tools keep `confirm: true`. |
| D46 | Offered behind D45 tokens + `kuduy:admin` + the WordPress capability: permanent delete of media, posts and products (usage check and snapshot/export first); user delete with content reassignment (never self, never the last administrator); plugin delete and uninstall as two separate operations (KuDiagnostics itself protected); **WooCommerce refunds** through `wc_create_refund()` — the preview shows amount, items, and whether the gateway will send money back (`api_refund`) or the refund is recorded only; refund amount can never exceed the remaining refundable total; audit row keeps the confirmation reference. |
| D53 | Memory scoped by site (own table per site), project, and user (shared with all connected users or private to its author); status current/outdated, version, optional expiry. |
| D44, D47–D52, D54, D55 | Recommended option below. |

Options as proposed (recommended first). Numbers continue after D42.

| # | Specification asks | Today | Recommendation |
|---|---|---|---|
| **D43** | snake_case tool names per plugin (`woocommerce_list_products`, `acf_create_field_group` …), one tool set per plugin | `kuduy-*` names (CLAUDE.md convention); shared tools with adapters (forms, SEO) | **Keep `kuduy-*` and shared tools.** Part 25.2 of the spec itself says to keep the `kuduy-*` convention. Per-plugin tool sets would mean ~900 tools; shared tools with an `integration` argument keep it near 400. Add per-plugin tools only for features no shared tool can express (Code Snippets, WPML, popups, PDF templates, …). |
| **D44** | Central discovery: `wp_integrations_list`, `_get_status`, `_get_capabilities`, `_refresh`, `_diagnose` | `kuduy-integrations` (catalogue, installed/active/version, support class, tools) | **Extend, not duplicate:** add `kuduy-integration-get` (status, edition, dependencies, available and missing operations, tool names, optional schemas) and `kuduy-integration-diagnose`. Refresh is not needed: detection is live per request. |
| **D45** | Two-stage confirm with a short-lived, user-scoped confirmation token; "a dry_run flag is not a security boundary" | `confirm: true` flag on risky tools | **Adopt the token** for the high-risk list in §3.4 only (D33 Ghost mode becomes this design): call without `confirm_token` → preview + token bound to user, connection, tool, arguments hash and object version, 10 min TTL, single use; call again with the token → executes only if nothing changed. Keep `confirm: true` for low-risk tools. |
| **D46** | Permanent deletes (posts, products, users, media, plugins), refunds | Deliberately not offered (trash + undo only) | **Offer behind D45 tokens and `kuduy:admin`**, with a snapshot or export first where possible: media delete, plugin delete (files only, uninstall separate), user delete with reassignment, product permanent delete. **Refunds stay out** (money movement; no undo). Owner to confirm. |
| **D47** | Plugin upload from ZIP, uninstall, update history | Install by wordpress.org slug only | **ZIP upload from the media library only** (admin uploads the file in WordPress, the tool takes the attachment id): never a URL. Validated with core `Plugin_Upgrader`, path traversal check, slug match. Uninstall = WordPress' own `delete_plugins()` (runs `uninstall.php`), D45 token. |
| **D48** | Full-site backups (create, verify, restore) | Operation snapshots only | **Through a backup provider's own API** (UpdraftPlus free first; research which others expose one). Never call a snapshot a backup. Restore = D45 token + admin. No own backup engine. |
| **D49** | Generic plugin settings get/update | Settings allow-list for core | **Discovery only is generic** (registered settings via `register_setting`, REST routes, abilities). **Writes only through a known adapter or a setting registered with a `sanitize_callback` and `show_in_rest`** — never arbitrary `update_option`. Secrets are write-only and never returned. |
| **D50** | `tools/list_changed` notifications, cursor pagination of `tools/list` | `listChanged: false`; stateless transport (MCP 2026-07-28) | **Keep `false`.** A stateless HTTP server has no channel to push notifications (UNVERIFIED against the 2026-07-28 spec text: re-read before closing). Add `tools/list` cursor pagination only if phase K's measurement shows clients need it. |
| **D51** | MCP resources and prompts | Not implemented | **Implement both** (phase N): read-only resources for site overview, integrations, content model, memory summary; prompts for the workflows in Part 3.10. D32 (skills as prompts) folds in here. |
| **D52** | Standard error codes (`PLUGIN_NOT_ACTIVE`, `LICENSE_REQUIRED`, …) and `{success, data, meta}` envelope | `ToolError` codes; `structuredContent` per tool output schema | **Map, do not rewrap:** add the spec's codes to `ToolError` (keep existing ones as aliases), return them in `structuredContent.error`. Do not wrap every existing result in a new envelope — that breaks the output schemas of 243 tools (backward-compatibility rule). |
| **D53** | Memory scoped by site, project, user, tenant | D31: own table, kind/title/body/tags | **D31 plus** `project` and `user_id` columns, `status` (current/outdated), `version`, `expires_at`; site isolation is natural (one table per site; multisite uses the blog prefix). |
| **D54** | "Spectra Blocks" and "Spectra" as two separate plugins | Matrix: one plugin (`ultimate-addons-for-gutenberg`) under two names | **Research first:** check wordpress.org for a separate Spectra 3 / `spectra-blocks` slug. If it is one plugin, one adapter with the spec's popup and settings tools. |
| **D55** | Unlimited Elements: install widgets from the remote library | — | **Not offered** (downloads and runs remote code). Read installed widgets only. |

Rules that do not change: rule 6 (no SQL writes, code only behind `KUDUY_MCP_ALLOW_CODE_EXEC` + `kuduy:code`), rule 8 (no copied reference code — the novamira.ai pages are a feature checklist only), rule 9 (no claims before verification), paid products only with a licensed copy.

---

## 2. Platform parts of the specification

| Spec part | Exists | Gap | Phase |
|---|---|---|---|
| 3.1 MCP audit | Done (`AUDIT.md`, 2026-11-10) | Refresh numbers (181 → 243 tools) | M |
| 3.2–3.3 Tool contract (name, title, description, schemas, annotations, permission) | Every tool has these (`Mcp/Tool`) | `title` field: check it is sent in `tools/list`; descriptions to state edition and limits consistently | M |
| 3.4–3.5 Response and error contract | `structuredContent` + text; `isError` | Spec error codes (D52); `retryable` flag | M |
| 3.6 Annotations | Present | Audit that every delete/trash tool is `destructiveHint` | M |
| 3.7 Discovery tools | `kuduy-integrations` | `-get`, `-diagnose` (D44) | M |
| 3.8 Dynamic registration | Tools of inactive plugins hidden | listChanged (D50) | — |
| 3.9 Resources | None | D51 | N |
| 3.10 Prompts | None | D51 | N |
| 4 Shared framework (registry, permissions, pagination, cache, audit, snapshots) | All exist except a shared cursor helper and schema cache | Cursor helper for new list tools (keep page/offset on existing ones); cache only what measurement justifies | M |
| 4.4 Edition awareness | Per adapter | `edition` + `requires` on every catalogue entry; `LICENSE_REQUIRED` error | M |
| 14 Memory | None | Whole feature (D53) | I |
| 15 Cross-plugin intelligence | None | `kuduy-integration-dependencies`, `-compatibility`, `-conflicts`, ecosystem audit (derived from the catalogue + live checks) | O |
| 16 Block editing | `kuduy-block-types`, `kuduy-post-blocks` (read) | Block-level insert/update/move/remove/replace, validate, patterns, usage search | C |
| 17 Security | OAuth, scopes, caps, redaction, audit, rate limit | Confirmation tokens (D45); audit row gets confirmation reference | J |
| 18 Performance / tokens | Hidden inactive tools, paging | Measure, then trim (phase K) | K |
| 19 Testing | Playground CI, 28+ jobs, smoke, release ZIP | Test matrix per plugin: not installed / inactive / active / free / pro / no permission (spec 19.1) as a reusable test helper | M |
| 20 Admin dashboard | Connections page | Integrations tab on the existing page (data from `kuduy-integrations`), last diagnostic | P |
| 20.2–20.3 Docs | `docs/TOOLS.md` (generated), `DEVELOPER.md` | Per-integration section generated from the catalogue; adapter how-to | P |
| 21 Extensibility | Modules + `Tool` interface | Document the adapter contract; catalogue entry per adapter | M, P |
| 25.3 Access profiles | Scopes read ⊂ write ⊂ admin, code separate | Consent screen: name "Full Site Administration" for `kuduy:admin`, explain code access separately (wording only) | P |
| 25.9 Backups | Snapshots | D48 | O |
| 25.11 Connections UI | List, revoke | Per-connection scopes shown, recent activity, high-risk actions filter | P |

---

## 3. WordPress core and administration (spec Part 25)

| Area | Exists | Gap | Phase |
|---|---|---|---|
| Site settings (25.4A) | `kuduy-settings-get/-update` (allow-list), site info, health | `kuduy-site-settings-schema` (registered settings with types); privacy page, permalink structure if not on the allow-list | M |
| Content (25.4B) | Posts/CPT CRUD, trash, revisions, meta, terms, comments | Duplicate post (planned C); permanent delete (D46); bulk update via jobs | C |
| Media (25.4C) | 8 tools incl. replace, rename, regenerate, usage | Delete with usage check (D46); unused-attachment finder (job) | C |
| Navigation / Site Editor (25.4D) | Templates, Global Styles, nav menus, widgets, classic menus | Template parts and patterns CRUD, style variations | C |
| Users and roles (25.4E) | List, get, create, update, roles list | Password-reset email (confirm-first), delete with reassignment (D46), last-admin and self-promotion guards checked in tests | O |
| Plugin lifecycle (25.5) | List, install (slug), activate, deactivate (impact report), update | ZIP upload (D47), delete/uninstall (D46/D47), dependencies (WP 6.5 `Requires Plugins` header), update history from audit log | O |
| Plugin discovery (25.6) | Hooks inspect | `kuduy-plugin-inspect`: version, edition, REST routes of its namespace, registered abilities, registered settings, capabilities it adds (D49) | O |
| Abilities hub | Used internally (Elementor v4, Astra, Bricks, Oxygen, Breakdance) | General list/run of public abilities (D34) | J |
| Developer (25.8) | Files list/read/search, log, DB schema/query; write + PHP behind D4 | File rename/move/delete behind D4; diff against snapshot | O |
| Maintenance | Cache, rewrite, cron list, site health, update check | Cron run/unschedule (admin, confirm), object-cache flush | O |

### 3.4 High-risk operations that get D45 confirmation tokens

Permanent delete (post, product, media, user), bulk delete, form delete, entry delete, field-group delete, taxonomy delete, SEO bulk apply, Code Snippets activate/replace, plugin delete/uninstall/ZIP install, backup restore, sensitive customer updates.

---

## 4. Integrations: specification against today

Status today from `INTEGRATION-MATRIX.md` plus the builders round to 2026-11-16. "Copy": free = testable here, paid = needs the owner's licensed copy.

### 4.1 Blocks (spec Part 5)

| Plugin | Copy | Today | Spec adds | Plan |
|---|---|---|---|---|
| Spectra / Spectra Blocks | free | Detection | Block catalogue with schemas, enable/disable blocks, popups CRUD, settings, global styles | D54 research, then C |
| GenerateBlocks | free | Detection | Block schemas/defaults, page blocks, usage search, markup validation | C (shared block tools + `integration` filter) |
| Kadence Blocks | free | Detection | Same as GenerateBlocks; keep separate from Kadence theme | C |

Core block editing tools (phase C) work for every block: `kuduy-block-schema`, `kuduy-block-usage`, `kuduy-block-edit` (insert/update/move/remove/replace), `kuduy-block-validate`, `kuduy-patterns`. Per D43 each block plugin also gets its own discovery set (`kuduy-generateblocks-*`, `kuduy-kadence-blocks-*`, `kuduy-spectra-*`: status, blocks, schema, controls, defaults, usage, validate, diagnostics), built on the core tools — no second block editor.

### 4.2 WooCommerce (Part 6) — 33 tools today, Full

| Spec area | Gap | Plan |
|---|---|---|
| Products | Search by SKU (check), duplicate, restore from trash, permanent delete (D46), bulk update (job), grouped/external types (check), audit | G |
| Variations | Delete, bulk create, matrix generation, validate, default attributes | G |
| Categories / tags | Product-specific tools not needed: `kuduy-term-*` covers `product_cat`/`product_tag`; add assign/reorganize helpers only if term tools can't | G |
| Global attributes + terms | Not built (already in FEATURE-GAP) | G |
| Shipping classes | Check: zones/methods exist; classes CRUD missing | G |
| Coupons | Done | — |
| Orders / customers | Order update (fields), refunds list (read), refund create (D46, token); customer create/delete | G |
| Store settings | Writes (already in FEATURE-GAP) | G |

### 4.3 Forms (Part 7) — 9 shared tools, 6 adapters

| Plugin | Copy | Today | Gap | Plan |
|---|---|---|---|---|
| Contact Form 7 | free | Full | Form-tag list, field-level add/update/delete, messages, markup validate | B2 |
| Fluent Forms | free | Partial | Form/field create and change, notifications, confirmations; payments/quizzes read (Pro detect) | B2 |
| Ninja Forms | free | Partial | Same + export/import | B2 |
| Formidable | free | Partial | Same + entry status, styles, actions; Pro payments/applications | B2, L |
| WPForms | Lite free / Pro paid | Partial | Form/field changes (Lite); Pro entries verify | B2, L |
| Gravity Forms | paid | Partial (stand-in) | Everything to verify; form/field/notification CRUD via `GFAPI` | L |
| **Form migration** (7.9) | — | None | Canonical export, field-type map, preview, execute, verify — **new** | B3 (after B2) |

Per D43, each plugin gets its own tool set from the spec's list for that plugin (`kuduy-fluent-forms-save`, `kuduy-ninja-forms-field-add` …), built on the existing form adapters. A tool is registered only for operations the plugin really supports; Pro-only operations are hidden or return `LICENSE_REQUIRED` on the free edition.

### 4.4 Fields and content model (Part 8)

| Plugin | Copy | Today | Gap | Plan |
|---|---|---|---|---|
| ACF | free (Pro paid) | Partial | Field group CRUD, field CRUD, ACF post types/taxonomies (ACF 6.1+ internal post types), options pages (Pro), bulk values, audit | E |
| Meta Box | free | None | Adapter: field groups (MB Builder is paid — free Meta Box registers in code: read only), values via `rwmb_*` | E |
| Pods | free | None | Pods CRUD via `pods_api()`, fields, items, values | E |
| ACPT | Lite free | None | Post types, taxonomies, group → box → field hierarchy, options pages, values | E |
| ASE | free (fields Pro) | None | Settings, modules; fields need Pro | E (detect + settings), L |
| JetEngine | paid | None | Everything | L |

Per D43, one tool set per plugin (`kuduy-acf-*`, `kuduy-meta-box-*`, `kuduy-pods-*`, `kuduy-acpt-*`, `kuduy-ase-*`) following the spec's lists; plus one cross-plugin tool `kuduy-content-model` (who registers which post type and field, duplicates).

### 4.5 Elementor and Bricks add-ons (Part 9)

The spec's shared Elementor layer (9.1) **already exists**: `kuduy-elementor-get` (tree), `-edit` (insert/update/move/remove), `-widgets`, `-widget-schema`, `-dynamic-tags`, `-dynamic-set`, `-theme-templates`, `-verify`, Kit tools. Nine free add-ons are tested through it (EL9/EL9b). Per-add-on gaps are only the features that are not widgets:

| Add-on | Copy | Gap beyond generic widget tools | Plan |
|---|---|---|---|
| Element Pack, Happy, HT Mega, PowerPack, Qi, Xpro, UAE, Piotnet | free | `kuduy-elementor-widgets` filter by add-on (check exists); extensions list (controls added to standard widgets); module enable/disable for HT Mega/Element Pack where they have a settings API; Happy mega-menu read | F2 |
| Unlimited Elements | free | Run EL9 with a widget installed; custom widget definitions read; no remote install (D55) | F2 |
| Dynamic Content for Elementor | paid | Widgets/tags via generic tools; PDF template CRUD | L |
| JetElements | paid | Generic tools; blank-page diagnosis | L |
| Bricksforge | paid, needs Bricks | Popups, elements, email templates | L |

Shared new tool: `kuduy-elementor-usage` (find pages using widget X) — serves every add-on.

### 4.6 Dynamic data, code (Parts 10–11)

| Plugin | Copy | Today | Plan |
|---|---|---|---|
| Dynamic Shortcodes | paid | None | L |
| Shortcodes (core list) | core | None | G: `kuduy-shortcodes` (registered tags, owning plugin) |
| Code Snippets | free | None | G: list/get/export read under `kuduy:read`; create/update/duplicate/delete of **HTML/CSS/JS** under admin + D45; **PHP create/activate/replace only behind `KUDUY_MCP_ALLOW_CODE_EXEC` + `kuduy:code` + D45** with `php -l`-equivalent syntax check (`token_get_all` + parse via `PhpParser` is not a dependency → use the plugin's own validation), dangerous-pattern report, revision before change |

### 4.7 SEO (Part 12) — 12 shared tools, four adapters, Full for meta

| Gap | Plan |
|---|---|
| Term SEO get/update for all four | G |
| Site settings (title templates, archives) read; writes only through each plugin's settings API | G |
| Yoast schema type; redirects for Yoast Premium / AIOSEO Pro / SEOPress Pro (paid) | G, L |
| Audits: missing titles/descriptions/canonicals, noindex finder, fix plan, preview + bulk apply (D45) | G |

### 4.8 Translation (Part 13)

| Plugin | Copy | Today | Plan |
|---|---|---|---|
| Polylang | free | Full (beyond spec) | — |
| WPML | paid | None | L: languages, translation links, missing translations via `wpml_*` hooks |
| Weglot | free, cloud | None | K: status, languages, switcher settings read; no claim that a local action translates |

---

## 5. Order of work

Owner's standing order (2026-11-14): builders and themes first, then C → G → E → I → J → K; L when copies arrive. Remaining builder items: live checks of Elementor Pro popups, Brizy, Kirki, themes. The specification adds phases M–P. Proposed order:

| # | Phase | Scope | Exit gate | Size |
|---|---|---|---|---|
| 0 | Builders round close | Live checks still open (Elementor Pro popups, Brizy, Kirki, themes) | Recorded in `TESTING.md` | Small |
| 1 | **M. Contract and discovery** | D43–D45, D52 decided; spec error codes; `kuduy-integration-get`, `-diagnose`; edition/requires in catalogue; cursor helper; per-plugin state test helper (not installed/inactive/active/no-permission); D45 confirmation tokens in the executor | Token tests (expiry, replay, changed object, other user); all 243 existing tools unchanged in schema | Medium |
| 2 | **C. Blocks** (as planned + spec 5, 16) | Block-level editing, patterns, validation, usage; GenerateBlocks, Kadence Blocks, Spectra in CI; Spectra popups/settings (after D54); duplicate post; media delete (D46) | Unchanged posts round-trip byte for byte | Medium |
| 3 | **G. Store, SEO, code** (+ spec 6, 10–12) | WooCommerce gaps in §4.2; SEO term/settings/audits; Code Snippets; shortcodes; job retry | Tests per item | Large |
| 3b | **Q. Builders and themes completion** (owner-approved 2026-11-17, §8) | The ten builder and theme gaps in §8 | Live check on the owner's test site per item | Large |
| 4 | **B2. Form editing** (+ spec 7) | Shared form/field/notification tools for CF7, Fluent, Ninja, Formidable, WPForms Lite | CI per plugin | Large |
| 5 | **E. Fields** (+ spec 8) | ACF CRUD; Meta Box, Pods, ACPT adapters; ASE settings; content-model audit | CI per plugin | Large |
| 6 | **F2. Add-on extras** | Elementor usage search, extensions, module toggles, Unlimited Elements | EL9 extended | Small–medium |
| 7 | **I. Memory and AI layer** (+ spec 14) | D53 memory: 16 tools reduced to ~8 (`kuduy-memory-save/-get/-search/-list/-delete/-export/-import/-context`); conflict flag against live detection; design scan | Spec 19.5 list: persists across reconnect and new session, never stores secrets, relevance search | Large |
| 8 | **N. Resources and prompts** (+ spec 3.9–3.10, D51, D32) | `resources/list/read`, `prompts/list/get`; site overview, integrations, content model, memory summary; workflow prompts | Listed and read by claude.ai (rule 9) | Medium |
| 9 | **O. Administration** (+ spec 15, 25) | Plugin ZIP/uninstall/delete/inspect (D46, D47, D49); user delete with reassignment/reset; cron actions; backup provider (D48); cross-plugin dependency/conflict tools; file move/delete under D4 | Guards tested (last admin, self, own plugin) | Large |
| 10 | **J. Modes** | Site-wide read-only switch; abilities hub (D34); snapshot diff | Every write tool blocked in read-only | Medium |
| 11 | **K. Tokens and translation** | Measure, trim; Weglot | Numbers before/after | Small–medium |
| 12 | **B3. Form migration** | Canonical export, mapping, preview, execute, verify | CF7 → Fluent round-trip with report | Medium — **built 2026-11-21** (D83, `docs/plans/phase-b3-form-migration.md`) |
| 13 | **P. Admin UI and docs** | Integrations tab, consent wording "Full Site Administration", connection activity, per-integration docs generated from the catalogue, adapter developer guide | Screens checked in browser | Medium |
| — | **L. Paid products** | Gravity Forms, WPForms Pro, JetEngine, JetElements, DCE, Bricksforge, Dynamic Shortcodes, WPML, ACF Pro, ASE Pro, SEO Pro editions | Tested on the licensed copy | Per copy |

Process per phase is unchanged (memory `v1-round-workflow`): research from source into `RESEARCH.md`, failing test, code, full test round, security pass, update `FEATURES.md` / `INTEGRATION-MATRIX.md` / `TESTING.md` / `CHANGELOG.md`, regenerate `docs/TOOLS.md`, one push; ask before tagging.

## 6. Size and honesty

- Tool count: 243 today; with one tool set per plugin (D43) the full catalogue grows toward **~900**. Only active plugins' tools are listed, so a typical site with 5–10 of these plugins sees roughly 300–450. Phase K measures `tools/list` size on such a site before and after and decides on a compact listing mode.
- Because the tool count per plugin is large, phase K (measurement) moves up to run right after phase M, so the first per-plugin sets are sized against real numbers.
- Free and testable here: about 30 of the 38 spec integrations. Paid and blocked until a copy arrives: Gravity Forms, JetEngine, JetElements, DCE, Bricksforge, Dynamic Shortcodes, WPML, plus Pro editions (WPForms, ACF, ASE, Formidable, SEO plugins).
- The final matrix (spec Part 23) is produced from `kuduy-integrations` with the spec's states (`IMPLEMENTED_AND_TESTED`, `REQUIRES_PRO`, `UNSUPPORTED_BY_PLUGIN`, …) mapped from the existing support classes.
- Each large phase is several working sessions; every phase is useful on its own.

## 7. Research notes (2026-11-16)

- **D54 settled:** wordpress.org lists two separate plugins: `spectra-blocks` (Spectra Blocks 1.0.15) and `ultimate-addons-for-gutenberg` (Spectra Legacy 2.20.6). They get two catalogue rows and two adapters.
- Free copies confirmed on wordpress.org today: GenerateBlocks 2.5.1, Kadence Blocks 3.9.1, WPForms Lite 2.0.4, Contact Form 7 6.2.5, Flamingo 2.6.4, Fluent Forms 6.2.17, Ninja Forms 3.16.1, Formidable 7.0, ACF 6.8.11, Meta Box 5.15.5 (+ MB Custom Post Types 2.13.1), Pods 3.3.9.2, ACPT Lite 2.1.2, ASE 9.3.3, Element Pack Lite 10.0.8, Happy Addons 3.51.3, HT Mega 3.2.9, Piotnet 2.4.37, PowerPack Lite 3.2.1, Qi Addons 1.11.2, Ultimate Addons for Elementor 2.9.7, Unlimited Elements 2.0.28, Xpro 1.7.11, Code Snippets 4.1.0, Yoast 28.8, Rank Math 1.0.282, AIOSEO 5.0.5.1, SEOPress 10.4, Weglot 6.4.3, UpdraftPlus 1.26.14.
- Not on wordpress.org (paid or closed): JetEngine, JetElements, Dynamic Content for Elementor, Dynamic Shortcodes (listing closed), Bricksforge, WPML, Gravity Forms.

## 8. Phase Q — builders and themes completion (added 2026-11-17)

Approved by the owner on 2026-11-17 ("Yes, send them"), relayed from the session that ran the live builder and theme checks of 2026-11-16/17. Built after phase G (G3). None of these is covered today. Each item is researched from the product's source first and checked live on the owner's test site with a temporary OAuth connection (revoked afterwards; the robsdogs.com connector is never touched).

| # | Item | Today | Approach to verify first |
|---|---|---|---|
| Q1 | Divi 5: edit pages | Read only (`Blocks/BlockBuilderRead`) | Divi 5 pages are WordPress blocks: check whether the core block tools (`kuduy-block-edit`) can change them safely, and what Divi needs after a save |
| Q2 | Kirki 6: edit pages | Read only (`Kirki/KirkiRead`) | Through Kirki's stages (`kirki_stages` / `kirki_stage_data`, its Page facade), never raw guessed writes |
| Q3 | Etch: edit pages | Read only | As Q1 (block-based) |
| Q4 | Avada theme: change Global Options | Design settings read only | Through Avada's own options API |
| Q5 | Elementor Pro: forms (form widgets, submissions) and Pro dynamic tags | Popups done 2026-11-16 (live on Pro 4.0.4) | Pro's form and submissions APIs |
| Q6 | WPBakery: templates | Not covered | WPBakery's template storage and API (live copy 9.1 on the test site) |
| Q7 | Site Editor: create and change template parts and synced patterns | Templates only | Core REST controllers in process |
| Q8 | Bricks, Oxygen 6, Breakdance: site-wide settings | Site-wide abilities refused (`Core/BuilderAbilities`) | Run them behind D45 confirmation tokens with a settings snapshot where possible; PHP and code-execution abilities stay refused |
| Q9 | Spectra Legacy: its other settings screens | Block switches only (C2) | Its admin helper, as in C2 |
| Q10 | Catalogue notes in `Core/IntegrationCatalog` | Outdated | Live checks now cover Bricks 2.4.5, Oxygen 6, Breakdance, WPBakery 9.1 and Elementor Pro 4.0.4 popups ("tested against a stand-in" to go), Elementor Pro "Not yet: popups" is outdated, Divi 5.19 checked live — confirm each against `TESTING.md` and `COMPATIBILITY.md` before changing a note |

Documented limits (not built): Brizy layout editing (Brizy compiles pages in its own editor); Weglot translations (kept in Weglot's cloud); paid products without a copy (Voxel, MyListing, Mosaic and phase L); Oxygen 4 classic (optional, low value).
