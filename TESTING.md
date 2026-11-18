# KUDUY MCP — Testing

A PHP function existing is not proof that a feature works. A row is `pass` only with a passing automated test or recorded manual evidence, with the date and versions. Rows without a result have **not been run**.

## 1. Strategy

| Layer | Tool | What it proves | Where |
|---|---|---|---|
| Syntax | `php -l` on PHP 8.1, 8.2, 8.3, 8.4 | Code parses on every supported PHP | CI |
| Coding standards | PHPCS with WordPress Coding Standards 3.4.1 | Escaping, sanitizing, nonces, prepared SQL, i18n | Local + CI |
| Static analysis | PHPStan 2.3.5 level 6 + phpstan-wordpress | Types and WordPress API misuse | Local + CI |
| Integration | PHPUnit 10.5 **inside WordPress Playground** (`scripts/test-integration.mjs`; Elementor installed and active first, version from `KUDUY_ELEMENTOR`, default 4.3.6, `none` to skip; more wordpress.org plugins with `KUDUY_PLUGINS=slug@version,…`; licensed ZIPs kept git-ignored in the repo folder with `KUDUY_LOCAL_PLUGINS=file.zip,…`, local runs only) | Real WordPress: REST dispatch, OAuth, DB tables, cron, updater hooks. Pass/fail read from the JUnit report; the WP/PHP versions actually used are printed | Local + CI: WP 6.9 / PHP 8.1 and WP 7.1 / PHP 8.3 |
| Real HTTP smoke | `scripts/smoke-mcp.mjs` | The endpoint over real HTTP with Elementor + WooCommerce active and a noisy mu-plugin; the whole OAuth sign-in with a real `wp-login.php` login | Local + CI |
| Release | `scripts/build-release.php` + `scripts/check-release.mjs` | The ZIP installs as an upload, activates, creates tables and routes, ships no dev files | CI on every push and before every release |
| Real update | `scripts/check-update.mjs <from> <to>` | Install an older GitHub release, let core find and install the newer one, data kept | Manual |
| Security probe | Throwaway local site + scripted HTTP requests (kept out of the repo) | Confirms or rules out exploit paths found in reviews | Manual, per audit |
| End to end | Real Claude clients against a public HTTPS staging site | claude.ai, Desktop, Claude Code sign in and call tools | Manual (O11) |

Process: test first (red), then code (green); every new security fix gets a regression test; manual checks are written down here with the date.

## 2. Commands

See `CLAUDE.md` → Commands. Integration: `KUDUY_WP=<6.9|7.1> KUDUY_PHP=<8.1|8.3> node scripts/test-integration.mjs`. Smoke: `node scripts/smoke-mcp.mjs`.

## 3c. WPBakery round (2026-11-10, for 1.1.0)

PHPCS: no errors. PHPStan level 6: no errors in the committed code. Integration, full suite with the WPBakery stand-in and Elementor 4.3.6 active (`KUDUY_FAKES=wpbakery`): **214 tests, 0 failures** on WordPress 6.9.11 / PHP 8.1.34 and on WordPress 7.1.4 / PHP 8.3.36 (8 skipped: classes for plugins not in the run); this includes the 29 WPBakery tests (WB1-WB12), the Elementor suites, `FuzzTest` over the new tools and `LoadTest`. Base suite without the stand-in on WordPress 6.9.11 / PHP 8.1.34 / Elementor 3.35.9: **204 tests, 0 failures** (13 skipped; the parser tests WB1-WB3 run, the tool tests skip). First WPBakery run: 28 of 29, the failure being a wrong expectation in the test. Two runs died before PHPUnit started because a plugin download from wordpress.org broke off; both passed when repeated. **Not tested: anything against a licensed WPBakery (WB13).**

## 3b. Road-to-1.0 round (2026-11-10, after 0.8.1)

PHPCS and PHPStan level 6: no errors. Smoke over real HTTP incl. OAuth: **26 / 26**. Release ZIP (0.8.1 build): installs, 5 tables, routes, no dev files. Integration: 26 combinations, the same set as CI (WP 6.9.11 / PHP 8.1.34 and 7.1.4 / PHP 8.3.36; base, Elementor 3.35.9, free Elementor add-ons, Rank Math, Yoast, AIOSEO, SEOPress, Rank Math + Yoast conflict, WooCommerce HPOS on/off, ACF, forms, LiteSpeed Cache, Polylang, D4 code tools): 185–236 tests per run. New tests: `HiddenModeTest` (H1–H3), navigation in `SiteEditorTest`, `WooSettingsWriteTest` (W15–W17), `RankMathExtrasTest`, media replace/rename in `MediaLibraryTest`, `FuzzTest` (Z1–Z2), `LoadTest` (Z3). The round found two product bugs, fixed and re-run green: `kuduy-dev-php` left its time limit on the request; Rank Math redirect sources read back empty. Other first-run failures were test mistakes (fixed). Final confirmations: base on both pairs, Rank Math on both pairs and the code-tools run with 0 failures; earlier one-off: `LifecycleTest` uninstall failed once in the add-ons run (known intermittent). Load (Z3): `tools/list` avg 6.8 ms, `kuduy-site-info` 27.9 ms, `kuduy-post-list` 35.7 ms, peak 101 MB.

## 3a. Phase 7 round (2026-11-10, 0.7.0 + Phase 7 work)

PHPCS and PHPStan level 6: no errors. Smoke over real HTTP: **26 / 26**. Integration (each run = full suite): base, forms, LiteSpeed Cache, Polylang, ACF 6.8.11 and WooCommerce (11.2.2 on 7.1, 11.0.1 on 6.9, HPOS on and off) on WP 7.1.4 / PHP 8.3.36 and WP 6.9.11 / PHP 8.1.34 (Elementor 4.3.6), plus the code-tools run (D4 constant on, WP 7.1.4): 174–223 tests per run. The first round had the same three test-side failures everywhere (a test expecting a refusal where a hidden tool answers "Unknown tool"; the widget test reading a stale per-request global; the OAuth metadata test not expecting `kuduy:code` with the constant on); after the test fixes the affected tests pass on both pairs and in the code run.

### Phase 7 on Hostinger staging with claude.ai (2026-11-10, KUDUY 0.8.0)

Staging (WP 7.1.4, Hello Elementor theme) with Contact Form 7 6.2.4.1 + Flamingo 2.6.4, Fluent Forms 6.2.17, Polylang 3.9.2 (English, Deutsch) and LiteSpeed Cache 7.9.3 installed for the run and removed afterwards; one test submission per form. The user ran a 12-step prompt in claude.ai web (Kuduy connector, admin scope): **11 / 12 pass**.

| Step | Result |
|---|---|
| Forms status/list/get (no secrets), entries masked, `include_pii`, read/starred and trash with undo, note with undo, CF7 mail routing reported only (done: false) | pass (F1–F6 on a real host) |
| Polylang: status, set, translate, get | pass; **undo of `kuduy-lang-set` on a page that had no language left English set — fixed 2026-11-10 (restore removes the language with Polylang's model), test added to L2** |
| LiteSpeed status and post purge | pass (C1, C3) |
| Block types (116 core), post blocks, Global Styles (empty on Hello Elementor) | pass; template and widget tools correctly not listed (classic theme without widget areas) |
| Content types list; dev DB query (`user_pass` redacted), `wp-config.php` refused, schema (123 tables), log | pass (D1, D3) |
| Code tools absent without the D4 constant | pass (D4) |

Notes from the run: claude.ai showed the new tools only after its connector tool list refreshed; `kuduy-block-types` now also returns `count`.

## 3. Latest results (2026-11-05, v0.4.0)

| Check | Result |
|---|---|
| PHPCS (WPCS) | 0 errors, 0 warnings |
| PHPStan level 6 | No errors |
| `composer audit` | No advisories |
| `npm audit` | 3 moderate in `@wp-playground/cli` dependencies (dev only, not shipped) |
| Integration, WordPress 6.9.11 / PHP 8.1.34 + Elementor 4.3.6 | **145 / 145 pass** |
| Integration, WordPress 6.9.11 / PHP 8.1.34 + Elementor 3.35.9 (CI pairing) | **148 pass, 8 skipped** (v4-only and add-on tests) |
| Integration, WordPress 7.1.4 / PHP 8.3.36 + Elementor 3.35.9 | **141 pass, 1 skipped** (before the v4 tools; v4-only tests skip) |
| Integration, WordPress 7.1.4 / PHP 8.3.36 + Elementor 4.3.6 + 3 free add-ons (CI pairing) | **148 / 148 pass** (Theme Builder tests skip: no Pro in CI) |
| Integration, WordPress 7.1.4 / PHP 8.3.36 + Elementor 4.3.6 + Elementor Pro 4.3.2 (local, 2026-11-05) | **151 pass, 3 skipped** (add-ons not installed in this run) |
| Integration, WordPress 6.9.11 / PHP 8.1.34 + Elementor 4.3.6 + Elementor Pro 4.3.2 (local, 2026-11-05) | **151 pass, 3 skipped** (add-ons not installed in this run) |
| Integration with SEO plugins (local, 2026-11-06): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Rank Math 1.0.281 | **157 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Rank Math 1.0.281 | **157 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Yoast 28.8 | **155 pass, 5 skipped** (counted from the JUnit test cases, before the runner fix below) |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Yoast 28.7 | **156 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Rank Math + Yoast (conflict) | **153 pass, 4 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no SEO plugin (CI pairing) | **153 pass, 9 skipped** |
| Integration (local, 2026-11-08, Phase 6 complete code): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Rank Math 1.0.281 | **163 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Rank Math 1.0.281 | **163 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Yoast 28.8 | **162 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Yoast 28.7 | **162 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + AIOSEO 5.0.5 | **161 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + AIOSEO 5.0.5 | **161 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + SEOPress 10.4 | **161 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + SEOPress 10.4 | **161 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Rank Math + Yoast (conflict) | **158 pass, 4 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no SEO plugin | **158 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-08) | **26 / 26 pass** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 2) | **26 / 26 pass** |
| Integration (local, 2026-11-09, Phase 7 step 3: orders): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on / off | **180 pass, 4 skipped** each (184 tests). With the order tools unregistered all 8 `WooOrdersTest` tests failed and nothing else did |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on / off | **180 pass, 4 skipped** each |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6, no WooCommerce | First run: **1 failure** — `LifecycleTest::test_uninstall_with_opt_in_drops_tables_and_options` (`kuduy_oauth_clients still exists`), the same intermittent failure seen once on 2026-11-08; re-run: **156 pass, 4 skipped**. Not caused by this change (no lifecycle code touched); open item: intermittent `DROP TABLE` in Playground's SQLite layer, UNVERIFIED cause |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no WooCommerce | **151 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 3) | **26 / 26 pass** |
| Integration (local, 2026-11-09, Phase 7 step 4: coupons, customers, reviews): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on / off | **193 pass, 4 skipped** each (197 tests). With the tools unregistered all 13 new tests failed and nothing else did; the first green run had one test error (it read the minimum spend in view context, where WooCommerce shows "0" for empty) |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on / off | **193 pass, 4 skipped** each |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6, no WooCommerce; WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no WooCommerce | **156 pass, 4 skipped**; **151 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 4) | **26 / 26 pass** |
| Integration (local, 2026-11-09, Phase 7 step 5: shipping, tax, gateways, reports): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on / off | **201 pass, 4 skipped** each (205 tests). With the tools unregistered all 8 new tests failed and nothing else did; the first green run showed report rows carry product names only with `extended_info` (now always requested) |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on / off | **201 pass, 4 skipped** each |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6, no WooCommerce; WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no WooCommerce | **156 pass, 4 skipped**; **151 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 5) | **26 / 26 pass** |
| Integration (local, 2026-11-09, Phase 7 step 6: WooCommerce jobs): WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on / off | **204 pass, 4 skipped** each (208 tests; `WooJobsTest`: bulk sale prices with one failing item and per-item undo, bulk stock, order status/notes/trash refused as jobs). Before the change the two job tests failed |
| Integration without WooCommerce (WP 7.1.4 + Elementor 4.3.6; WP 6.9.11 + Elementor 3.35.9) | **156 pass, 4 skipped**; **151 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 6) | **26 / 26 pass** |
| Phase 7 with a real client (claude.ai web → Hostinger staging, KUDUY 0.6.0, WP 7.1.4 / PHP 8.3.36, WooCommerce 11.2.2 with HPOS on and sync off, BDT store in coming-soon mode, 2026-11-09 08:36–08:38 UTC) | **11 / 14 steps pass, 3 not testable on this store**: status; product 411 created as draft, sale price + stock management (snapshot 8), stock set 5 then −2 → 3 (snapshots 9, 10), get; `kuduy-post-update` on the product refused naming the kuduy-woo tools (W1); coupon 412 created, found by code in upper case, trashed (snapshot 11); shipping (2 zones, free shipping in BD), tax (off, 3 classes); gateways bacs/cheque/cod listed, none has a secret field; revenue report 0 orders; cleanup: product and coupon in trash. Not testable: order and customer masking (store has no orders or customers), gateway secret masking (no gateway with secrets) — covered in Playground (W6, W10, W13). Found: the 30-day revenue report listed only 10 daily intervals (wc-analytics pages intervals, 10 by default) — fixed after the run (`WooReportsTest::test_daily_intervals_cover_the_whole_range`) |
| Integration (local, 2026-11-09, report intervals fix): WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on / off | **205 pass, 4 skipped** each (209 tests). Before the fix the new test failed with 10 of 30 intervals. Smoke **26 / 26** |
| Phase 7 re-run on staging (claude.ai web, driven from the KUDUY session in the built-in browser; KUDUY 0.6.0, HPOS on, 2026-11-09) | **7 / 7 pass**. Setup in WP admin: test customer #3 (`kuduy.e2e.customer@example.test`, no welcome mail), product 413, pending order #414 with test billing data, Stripe 11.1.1 installed (test mode, not connected: its settings offer only Stripe sign-in, no manual key fields). Order list/get masked (`k***@example.test`, no street, phone, IP or order key); with `include_pii` full street, email, phone; status to processing without `confirm` → `done: false`, "Processing order" email listed, order still pending, no note added; customer list/get masked, full with `include_pii`; Stripe: `test_secret_key`, `secret_key`, `test_webhook_secret`, `webhook_secret`, `api_credentials` have `value: null` (empty — no keys stored, so a stored secret was not shown on the real host; covered by W13 in Playground). Cleanup: order #414 and product 413 trashed, customer #3 deleted, Stripe deactivated and deleted. Found (fixed after the run, test first): Stripe's `sepa_tokens_for_*` checkboxes were hidden by the name rule; order notes showed the author's display name, an email address, unmasked; with `include_pii` the top-level order `email` stayed masked |
| Integration (local, 2026-11-09, fixes from the staging re-run): WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on / off | **205 pass, 4 skipped** each (209 tests). Before the fixes the three new assertions failed (`WooOrdersTest` ×2, `WooStoreSettingsTest`) and nothing else did. PHPCS, PHPStan clean; smoke **26 / 26** |
| Integration (local, 2026-11-09, Phase 7 ACF): WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + ACF 6.8.11 | **165 pass, 4 skipped** each (169 tests). With the tools unregistered the 9 `AcfTest` tests failed, plus `CoreEditorialTest::test_meta_set_get_undo_and_protected_keys`: its meta key `subtitle` matched a test ACF field, and ACF denied the raw write (test fields renamed `kacf_*`) |
| Integration: WP 7.1.4 + WooCommerce 11.2.2 (HPOS on); WP 7.1.4 and WP 6.9.11 + Elementor 3.35.9 without plugins | **205 pass, 4 skipped**; **156 pass, 4 skipped**; **151 pass, 9 skipped**. PHPCS, PHPStan clean; smoke **26 / 26** |
| ACF with a real client (claude.ai web → Hostinger staging, KUDUY 0.7.0, WP 7.1.4, ACF 6.8.11 free, 2026-11-09) | **7 / 7 pass**. Setup in WP admin: field group "KUDUY test" (post 417; `kuduy_subtitle` text required, `kuduy_price` number min 0, `kuduy_color` select red/blue, `kuduy_secret` password; posts) and draft post 422 (`kuduy-post-create`). Status (6.8.11, free, abilities off, 1 group); field groups on post 422; update of all four fields (snapshot 17); get: values back, `kuduy_secret` `value: null`, `has_value: true`, the secret text nowhere in any result; refused with nothing changed: color "green" ("use one of red, blue"), price −5 ("equal to or higher than 0"), empty subtitle ("value is required"), unknown `page_only`; `kuduy-post-meta-set` on `kuduy_subtitle` refused naming `kuduy-acf-update`; undo → all four `has_value: false`. Note: number values come back as strings ("12.5"), as ACF stores them. Cleanup: post 422 and the field group trashed |
| Integration (local, 2026-11-08, after the Phase 6 close-out fixes: job results name their item, search-visibility note, audit `total` described): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Yoast 28.8 | **158 pass, 5 skipped** (163 tests). Before the fix the two new assertions failed as expected; that run also had one `LifecycleTest` uninstall failure (`kuduy_oauth_clients still exists`) that did not repeat in any later run |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Yoast 28.7 | **158 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6, no SEO plugin | **155 pass, 4 skipped** |
| Integration (local, 2026-11-09, Phase 7 step 1): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on | **162 pass, 4 skipped** (166 tests; `WooGuardsTest` 6/6). First run: one `CoreUsersTest` failure (WooCommerce refuses non-admins editing admins before KUDUY's last-admin rule; test adjusted) |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS off | **162 pass, 4 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on | **162 pass, 4 skipped** (after `AUTOMATIC_UPDATER_DISABLED`; before it, a background update left `.maintenance` and PHPUnit never ran) |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS off | **162 pass, 4 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6, no WooCommerce | **156 pass, 4 skipped** each (`WooGuardsTest` skips; run before the `AUTOMATIC_UPDATER_DISABLED` step, which the WooCommerce runs cover) |
| Integration (local, 2026-11-09, Phase 7 step 2: status + product tools): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on / off | **172 pass, 4 skipped** each (176 tests). Before the tools were registered all 10 `WooProductsTest` tests failed and nothing else did; the first green run found the trash id bug (`WC_Data::delete()` resets the id) and a formatted parent price |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on / off | **172 pass, 4 skipped** each |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6, no WooCommerce; WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no WooCommerce | **156 pass, 4 skipped**; **151 pass, 9 skipped** (Woo tests skip) |
| Runner fix (2026-11-06) | `test-integration.mjs` now counts the `<testcase>`, `<failure>`, `<error>`, `<skipped>` elements. It used to read the first `<testsuite>` attributes, which PHPUnit 10 leaves off the root when a class skips in `setUpBeforeClass()`, so a failure in another class could have passed unnoticed |
| Smoke over real HTTP (2026-11-06, after the executor change) | **26 / 26 pass** |
| Smoke over real HTTP (WP 7.1.4 / PHP 8.3, Elementor 4.3.6 + WooCommerce 11.2.1 active) | **26 / 26 pass** |
| Security probe (read-only token on core routes, `rest_route` override, PATH_INFO, unticked consent, unauthenticated `initialize`, root AS metadata) | All refused / as expected on 0.1.2 |
| GitHub CI on `3591d92` | All 9 jobs green; release workflow for `v0.1.2` green |
| Real update v0.1.0 → v0.1.1 | Pass (2026-11-05) |
| Real update v0.5.0 → v0.5.1 on Hostinger staging (2026-11-08) | **Pass**: offered after `kuduy-check-update` with `refresh: true` (the 12 h release cache still held 0.5.0), installed from the Plugins screen by the user; `kuduy-site-info` then reported 0.5.1 over the same claude.ai connection |
| Release v1.0.0 (2026-11-10) | CI green on `f5bfa9a`; `release.yml` published `kuduy-mcp.zip` (331 104 bytes) + `.sha256`; both download from GitHub. Hostinger staging (0.8.0) was offered 1.0.0 (`kuduy-check-update`, `refresh: true`) and the user installed it from Dashboard → Updates; afterwards `kuduy-site-info` reports 1.0.0 (WP 7.1.4, PHP 8.3.36) and `kuduy-check-update` reports no update. **Update on a real host: pass.** `scripts/check-update.mjs` could not finish locally: the Playground site finds the update but its download times out after 10 s (cURL 28); the older 0.8.0 → 0.8.1 pair fails the same way, so this is the local test network, not the release |
| Release v1.1.0 (2026-11-10, WPBakery tools) | CI green on `93767c1` (incl. both `+ WPBakery (stand-in)` jobs); `release.yml` published `kuduy-mcp.zip` (361 881 bytes) + `.sha256`; downloaded ZIP matches its checksum, contains the five `Modules/WpBakery` files and reports version 1.1.0. Hostinger staging (1.0.0) is offered 1.1.0 (`kuduy-check-update`, `refresh: true`); not installed there yet, and staging has no WPBakery, so the new tools stay hidden on it |
| Release v1.2.0 (2026-11-13, Avada tools + integration registry) | Full suite with the Avada stand-in and Elementor: 217 tests, 0 failures on WP 7.1.4 / PHP 8.3.36 and WP 6.9.11 / PHP 8.1.34. ZIP built from the committed tree and install-checked (installs, version, tables, routes, no dev files). CI green on `a842cc8`; `release.yml` published `kuduy-mcp.zip` (393 842 bytes) + `.sha256`; the downloaded ZIP matches its checksum, has the four `Modules/Avada` files and no unreleased forms adapters, and reports 1.2.0. Not yet installed on an Avada site (AV11 open) |
| Release v1.2.1 (2026-11-14, Avada fixes from the real-site check) | Avada and registry tests green (WP 7.1.4); ZIP built from the committed tree and install-checked; CI green on `b2aff16`; `release.yml` published `kuduy-mcp.zip` (393 962 bytes) + `.sha256`; the downloaded ZIP matches its checksum, reports 1.2.1 and has no unreleased forms adapters |
| Release v1.2.2 (2026-11-14, Avada: quotes and brackets refused in settings) | Avada tests green (WP 7.1.4); ZIP built from the committed tree and install-checked; CI green on `fe75653`; `release.yml` published `kuduy-mcp.zip` (394 178 bytes) + `.sha256`; the downloaded ZIP matches its checksum, reports 1.2.2 and has no unreleased forms adapters |
| Release v1.3.0 (2026-11-15, builders, themes and forms: Beaver Builder, Breakdance/Oxygen abilities, Brizy and Kirki read tools, Elementor completion, theme settings incl. Astra abilities, four more forms plugins) | Full suites green locally on `840d5e3`: 226 tests, 0 failures on WP 7.1.4 / PHP 8.3.36 and WP 6.9.11 / PHP 8.1.34 (Elementor 3.35.9), plus the per-product runs recorded in their sections. ZIP built from the committed tree and install-checked (installs, version 1.3.0, 5 tables, routes, no dev files). CI green on `840d5e3` and `da41841`; `release.yml` published `kuduy-mcp.zip` (449 612 bytes) + `.sha256`; the downloaded ZIP matches its checksum, has version 1.3.0 and the new modules. Update 1.2.2 → 1.3.0 through the built-in updater on the owner's premium test site (Hostinger, WP admin, 2026-11-15): the update was offered after a forced check, installed, the plugin stayed active, the home page answered 200 and the MCP route answered (405 to GET, as designed). Not yet done: the owner's checks of the new tools on the real paid products |
| Release v1.3.1 (2026-11-15, fixes from the live checks + Bricks, Divi 5, Etch tools) | Full suites green locally: 227 tests, 0 failures on WP 7.1.4 / PHP 8.3.36 and WP 6.9.11 / PHP 8.1.34 (Elementor 3.35.9). CI on `90f109a` failed in PHPCS (`stop_redirect` declared a return type but only throws; the local check had printed it and it was missed); fixed in the next commit (style rule disabled for that method with the reason, since PHPStan needs the filter callback to declare its type), `composer test:static` passes locally. ZIP built and install-checked; CI green on `c0905b4`; `release.yml` published `kuduy-mcp.zip` (459 116 bytes) + `.sha256`; the downloaded ZIP matches its checksum and has 1.3.1 and the new files. Update 1.3.0 → 1.3.1 on the owner's test site through the built-in updater: active, home page 200, MCP route answers |
| Release v1.3.2 (2026-11-15, fixes from the live builder checks: Bricks abilities list, plain apostrophes in refusals, Bricks page-level allow-list) | BuilderAbilitiesTest + BricksTest green (WP 7.1.4 / PHP 8.3.36, stand-ins for Breakdance and Bricks; BA1 failed without the properties fix), PHPCS + PHPStan clean. ZIP built from the committed tree and install-checked (installs, version 1.3.2, 5 tables, routes, no dev files). CI green on `85cc674`; `release.yml` published `kuduy-mcp.zip` (459 770 bytes) + `.sha256`; the downloaded ZIP matches its checksum, reports 1.3.2 and has the fix. Update 1.3.1 → 1.3.2 on the owner's test site through the built-in updater: `kuduy-site-info` reports 1.3.2, home page 200, MCP route answers (405 to GET). Not yet done: the Bricks list and allow-list on the live site with 1.3.2 |
| Release v1.3.3 (2026-11-16, Divi 5 module text + internal posts left out; Bricks popup settings off the page-level list) | BlockBuildersTest, BuilderAbilitiesTest, BricksTest, IntegrationsTest green (14 tests, WP 7.1.4 / PHP 8.3.36, stand-ins), PHPCS + PHPStan clean. ZIP built from the committed tree and install-checked (installs, version 1.3.3, 5 tables, routes, no dev files). CI green on `85611c3`; `release.yml` published `kuduy-mcp.zip` (460 367 bytes) + `.sha256`; the downloaded ZIP matches its checksum, reports 1.3.3 and has the Divi fix. Update 1.3.2 → 1.3.3 on the owner's test site through the built-in updater: `kuduy-site-info` reports 1.3.3, home page 200, MCP route answers (405 to GET) |
| Release v1.3.4 (2026-11-16, Beaver Builder access with WooCommerce) | BeaverTest green with the real Beaver Lite 2.11.1.2 (7 tests incl. BB7), local HTTP repro with WooCommerce 11.3.0 passes, PHPCS + PHPStan clean. ZIP built from the committed tree and install-checked (installs, version 1.3.4, 5 tables, routes, no dev files). CI green on `113b6e1`; `release.yml` published `kuduy-mcp.zip` (460 916 bytes) + `.sha256`; the downloaded ZIP matches its checksum, reports 1.3.4 and has the fix. Update 1.3.3 → 1.3.4 on the owner's test site through the built-in updater: `kuduy-site-info` reports 1.3.4; the Beaver round trip then passed live |
| Release v1.3.5 (2026-11-16, Elementor Pro popups) | Full suite on WP 7.1.4 / PHP 8.3.36 with the popup stand-in: 231 tests, 0 failures, 17 skipped; PHPCS + PHPStan clean. ZIP built from the committed tree and install-checked (installs, version 1.3.5, 5 tables, routes, no dev files). CI green on `461a209`; `release.yml` published `kuduy-mcp.zip` (466 181 bytes) + `.sha256`; the downloaded ZIP matches its checksum, reports 1.3.5, has `ElementorPopup.php` and no test stand-ins. Update 1.3.4 → 1.3.5 on the owner's test site through the built-in updater: `kuduy-site-info` reports 1.3.5; EP5 then passed live |
| Release v1.3.6 (2026-11-16, manual ZIP: the GitHub account was suspended, so no tag or GitHub release) | PHPCS + PHPStan clean; BrizyTest and KirkiTest (real Kirki 6.4.2) green; the {} change passed the full suite earlier. ZIP built from the committed tree with `build-release.php` (COMPOSER_BIN set to composer.bat) and install-checked (installs, version 1.3.6, 5 tables, routes, no dev files); SHA-256 e23dd11a2ee5e82ad278d85f3ee816863c1d52249b41c6142720a2ee20100cc5. Uploaded by the owner on the test site (Plugins → Upload Plugin → replace): `kuduy-site-info` and the plugin list report 1.3.6 active; the installed files have the Kirki staging and Redactor changes; home page 200 with the Elementor header, MCP route 405 to GET, OAuth metadata 200. `kuduy-check-update` still reads GitHub's latest release (1.3.5) and offers nothing |
| Release v1.3.7 (2026-11-17, manual ZIP; the git history was re-created by the owner after the GitHub suspension, so no tag) | Built from a clean copy of the 1.3.7 commit with LF line endings. PHPStan found two issues in the Blocksy fix (call_user_func on an unknown function, then a redundant is_object()) → `blocksy_manager()` declared in the PHPStan stub and called directly; PHPStan + PHPCS clean. ThemeSettingsTest with Blocksy 2.1.62: 5 tests, 0 failures, 1 skipped (a test for another theme). ZIP install check: installs, version 1.3.7, 5 tables, routes, no dev files; SHA-256 2ead7998989848dd0cd8ae5c3d2acdf5e81391636afb688aa048cccb0c2bc84b. Uploaded by the owner on the test site: `kuduy-site-info` reports 1.3.7. Blocksy re-check live (temporary connection, then revoked): `narrowContainerWidth` 750 → 760 → page CSS 760px at once; undo → 750px. The page CSS had still said 760px before the run: the undo of the 1.3.6 test had restored the setting but Blocksy rebuilt its CSS from its stale cache; the 1.3.7 undo rebuilt it correctly. Hello Elementor active again |
| Release v1.3.8 (2026-11-17, tag `v1.3.8` on `7540ead`, GitHub release published by `release.yml` on `manikmt213/KuDiagnostics`, run 44029360130 success; published ZIP SHA-256 f5afcd82ac585f82a5f97611f067690ea3debb1bdcede82837698d49f4910c87 matches its `.sha256` and holds version 1.3.8 + the new repo) | Branch `release/1.3.8` = 1.3.7 commit `981d4e0` + repo switch + version bump; the phase M WIP on `main` is not included. PHPCS + PHPStan clean. Full integration suite: WP 7.1.4 / PHP 8.3.36 and WP 6.9.11 / PHP 8.1.34, 228 tests each, 0 failures, 18 skipped (optional plugins not loaded). ZIP install check: installs, version 1.3.8, 5 tables, routes, no dev files; ZIP holds `REPO = 'manikmt213/KuDiagnostics'` and the new `Update URI`, no `kuduyDigital` references, no `Confirmation.php`. local build SHA-256 c23ad48b731f94d35bdb0b64ed121abe33a275218c2064ca547a9ce57613950b. Not yet installed on any site |
| Real Claude clients (O11) | **claude.ai: pass** (Hostinger, 0.1.2 and 0.2.0). **Revoke with claude.ai: pass 2026-11-10** (staging, 0.8.0): `kuduy-site-info` worked → the connection was revoked on Tools → KUDUY MCP → the next call failed with "MCP server Kuduy needs you to sign in again" and claude.ai showed a Reconnect card → after reconnecting the call worked again. Desktop, Claude Code's own sign-in, refresh after 1 h: not yet run |
| EL10 with a real client (claude.ai web → Hostinger staging, 0.4.0, Elementor 4.3.6 + Pro 4.0.4, 2026-11-05) | **15 / 16 steps pass**; `kuduy-elementor-theme-conditions` not available (grant has no `kuduy:admin`). **Step 6 re-run 2026-11-09 on 0.6.0 with `kuduy:admin`: pass** (conditions set, conflict with live header 386 refused, cleared) — all 16 steps now passed on staging. Claude desktop app sessions could not run it: their Kuduy connector stayed on a stale entry (33 tools, every call 404, no calls reached the site) |
| Phase 6 with a real client (claude.ai web → Hostinger staging, 0.5.0, WP 7.1.4 / PHP 8.3.36, Yoast 28.8, 2026-11-08 15:24–15:26 UTC) | **8 / 8 steps pass**: 74 tools listed; `kuduy-seo-status` yoast, no conflict; `kuduy-seo-update` + `-get` (stored = rendered); `kuduy-seo-verify` live 200, no mismatches (S4 E); image shown to Claude (S6 M); job run by WP-Cron (S9 E); `kuduy-seo-audit` did not list the post (it had all fields); cleanup by snapshots 3–5 and trash (snapshot 6 kept). Open: robots mismatch (S4 note). `kuduy-seo-audit` returns `total` = posts checked next to an empty `posts`, which reads like a count of problems. Claude Code desktop sessions could not run it: their Kuduy connector stayed at 42 tools (the pre-0.5.0 list) after reconnecting, revoking and toggling it, while `kuduy-site-info` reported 0.5.0 |
| Real host after release (Hostinger, 0.2.0, from outside) | Unauthenticated `initialize` → 401 + challenge; root AS metadata 200; token with `rest_route` override → 401; plain HTTP → 301 to HTTPS |

## 3d. Tool list size (phase K, 2026-11-17)

`node scripts/measure-tools.mjs` registers every tool in Playground (WordPress 7.1, PHP 8.3) and measures the JSON each one adds to `tools/list`. Sites are built from the areas a site of that kind activates; the code-execution tools are left out (they exist only with `KUDUY_MCP_ALLOW_CODE_EXEC`).

Measured 2026-11-17 on 244 tools (code-execution tools left out). Bytes are the exact JSON of each tool's `tools/list` entry; tokens are estimated as bytes / 4.

| Site | Tools | List size | Est. tokens |
|---|---:|---:|---:|
| Bare WordPress (block theme) | 93 | 97.2 KB | 24,883 |
| Blog: + one SEO plugin + forms + cache | 111 | 119.0 KB | 30,470 |
| Elementor site: + Elementor + SEO + forms + cache | 132 | 142.8 KB | 36,559 |
| Shop: + WooCommerce + Elementor + SEO + forms + ACF + cache | 169 | 198.8 KB | 50,898 |
| Everything registered (never happens on one site) | 244 | 290.6 KB | 74,399 |

| Area | Tools | List size | Avg per tool | Description | Input schema | Output schema |
|---|---:|---:|---:|---:|---:|---:|
| Core | 67 | 69.8 KB | 1067 B | 22% | 25% | 33% |
| Woo | 33 | 52.7 KB | 1635 B | 16% | 36% | 35% |
| Avada | 20 | 32.1 KB | 1643 B | 29% | 37% | 20% |
| WpBakery | 16 | 28.4 KB | 1817 B | 27% | 42% | 19% |
| Elementor | 21 | 23.8 KB | 1160 B | 28% | 28% | 24% |
| Blocks | 19 | 14.8 KB | 800 B | 27% | 26% | 20% |
| Seo | 12 | 12.7 KB | 1088 B | 25% | 30% | 26% |
| Forms | 9 | 12.3 KB | 1404 B | 26% | 43% | 16% |
| Media | 9 | 10.3 KB | 1174 B | 28% | 21% | 32% |
| Beaver | 7 | 6.8 KB | 995 B | 27% | 40% | 10% |
| Dev | 6 | 5.0 KB | 854 B | 29% | 25% | 22% |
| Jobs | 3 | 4.1 KB | 1391 B | 18% | 15% | 52% |
| Multilingual | 5 | 4.0 KB | 820 B | 19% | 28% | 28% |
| Types | 3 | 3.3 KB | 1141 B | 26% | 36% | 19% |
| Acf | 4 | 3.3 KB | 847 B | 29% | 22% | 25% |
| Kirki | 3 | 2.0 KB | 680 B | 37% | 28% | 5% |
| Brizy | 3 | 1.9 KB | 645 B | 38% | 25% | 5% |
| Cache | 2 | 1.8 KB | 937 B | 34% | 23% | 20% |
| Bricks | 2 | 1.4 KB | 704 B | 31% | 35% | 5% |

Across all tools: descriptions 24%, input schemas 31%, output schemas 27% of the list.

Ten largest entries: `kuduy-woo-product-update` 3.9 KB, `kuduy-woo-product-create` 3.8 KB, `kuduy-wpbakery-add-column` 3.0 KB, `kuduy-wpbakery-add-row` 3.0 KB, `kuduy-avada-add-column` 2.9 KB, `kuduy-woo-product-get` 2.8 KB, `kuduy-avada-add-container` 2.8 KB, `kuduy-wpbakery-create` 2.5 KB, `kuduy-woo-variation-create` 2.5 KB, `kuduy-woo-variation-update` 2.5 KB.

Reading: a client sees only the tools of active plugins, so a bare site costs about 25k tokens of tool list and a WooCommerce + Elementor shop about 51k. Descriptions are only a quarter of that; input and output schemas are the rest. The largest single entries are the WooCommerce product tools and the WPBakery/Avada layout tools. Decision D56 (no trimming yet; measure again with each new tool set) is in `ARCHITECTURE.md`.

## 4. Feature test matrix

Legend: **U** unit, **I** integration (Playground), **E** end-to-end with a real Claude client, **M** manual check. Feature status per row lives in `FEATURES.md`.

## Foundation

| # | Feature | Tests | Type |
|---|---|---|---|
| F1 | Activation / deactivation / uninstall | tables created/upgraded; cron cleared on deactivate; uninstall drops tables + options only when `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL` | I — **pass 2026-11-04** (`tests/integration/LifecycleTest.php`, WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36) and "cron cleared on deactivate" **pass 2026-11-05** (`SafetyTest`) |
| F2 | Output guard | stray `echo` from another plugin on `init` does not break JSON | I — **pass 2026-11-04** (`npm run smoke:mcp`, real HTTP, mu-plugin echoes + `E_USER_NOTICE` with `WP_DEBUG_DISPLAY`) |
| F3 | `server/discover` (2026-07-28) | returns supported versions + capabilities | I — **pass 2026-11-04** |
| F4 | Legacy `initialize` (2025-11-25) | handshake + `tools/list` (no `Mcp-Session-Id`: sessions are optional in 2025-11-25) | I — **pass 2026-11-04** (also over real HTTP) |
| F5 | Header validation | missing `Mcp-Method` / mismatched `Mcp-Name` → 400 `-32020`; bad version → 400 `-32022` | I — **pass 2026-11-04** (incl. base64 `Mcp-Name`) |
| F6 | Origin validation | foreign Origin → 403 | I — **pass 2026-11-04** |
| F7 | `tools/list` | names valid, schemas valid JSON Schema, unavailable integrations hidden | U, I — **pass 2026-11-04** (I only; filtered by capability) |
| F8 | `tools/call` | input validation errors and exceptions are tool errors (`isError: true`, per MCP); exception internals never returned | I — **pass 2026-11-04** |
| F9 | Audit log | every call logged; secrets redacted; retention purge | I — **pass 2026-11-05** (`SafetyTest`: ok/denied/error rows, redacted args and results, 90-day purge) |
| F10 | Snapshots | destructive tool writes snapshot; restore works | I — **pass 2026-11-05** (restores once; others cannot restore) |
| F11 | Rate limit | burst over limit → 429 with retry hint | I — **pass 2026-11-05** (`Retry-After`) |
| F13 | Output schemas | every listed tool has an object `outputSchema`; `structuredContent` of each core tool validates against it | I — **pass 2026-11-05** |
| F12 | Undo needs the original grant | write-scope token cannot restore an admin-scope tool's snapshot; admin-scope token can | I — **pass 2026-11-05** (0.1.2) |

## OAuth

| # | Feature | Tests | Type |
|---|---|---|---|
| O1 | 401 challenge | `WWW-Authenticate` has `resource_metadata` + `scope` | I — **pass 2026-11-05** (also real HTTP) |
| O2 | Metadata | RFC 9728 + 8414 at `.well-known` and alias paths; `resource` equals MCP URL exactly | I — **pass 2026-11-05** (root `.well-known` + REST `{issuer}/.well-known/openid-configuration` over real HTTP) |
| O3 | CIMD | Claude Code metadata URL fetched, cached, redirect URIs enforced; SSRF guard (HTTPS only, no private IPs, size/time caps) | I — **pass 2026-11-05** with a mocked fetch (`wp_safe_remote_get`, no redirects, 64 KB, 5 s, `client_id` must equal URL). Real Claude Code URL: in O11 |
| O4 | DCR | register returns client; invalid redirect rejected | I — **pass 2026-11-05** |
| O5 | Authorize | S256 required; plain rejected; consent shows redirect host; login redirect; `iss` returned | I, M — **pass 2026-11-05** (I + real `wp-login.php` login and consent form over HTTP; visual check still manual) |
| O6 | Loopback | `localhost` and `127.0.0.1` matched on any port | I — **pass 2026-11-05** |
| O7 | Token | code single-use; verifier mismatch → `invalid_grant`; `resource`/audience bound | I — **pass 2026-11-05** (code reuse also revokes its tokens; raw tokens never stored) |
| O8 | Refresh | rotation; reuse of old refresh revokes family | I — **pass 2026-11-05** (also real HTTP) |
| O9 | Revoke | RFC 7009; revoked token → 401 | I — **pass 2026-11-05** |
| O10 | Scopes | token without `kuduy:write` calling a write tool → 403 `insufficient_scope` | I — **pass 2026-11-05** (hierarchy write ⊃ read; consent limited by caps) |
| O11 | Real clients | connect + call from claude.ai, Desktop, Claude Code; refresh after 1 h; revoke | E — **claude.ai pass 2026-11-05**: claude.ai custom connector on the user's Hostinger staging site (OAuth done by claude.ai), synced into a Claude Code desktop session: tools listed (4 on 0.1.2) and `kuduy-site-info` + `kuduy-check-update` called as user `admin`, on 0.1.2 and again on 0.2.0. Before: v0.1.1 was detected as "No sign-in" → "Couldn't reach" (fixed in 0.1.2, O14). **Still open:** Claude Desktop, Claude Code's own sign-in (`claude mcp add` + CIMD), refresh after 1 h, revoke from Tools > KUDUY MCP |
| O12 | Token bound to the MCP route | token refused on core routes, with `rest_route` in query or body, and on PATH_INFO tails; plain-permalink `?rest_route=/kuduy-mcp/v1/mcp` still works | I, M — **pass 2026-11-05** (0.1.2; exploit probe over real HTTP: all 401) |
| O13 | Consent with nothing ticked | approve with every box unticked → `access_denied`, no code | I — **pass 2026-11-05** (0.1.2) |
| O14 | Admin opt-in when the client does not ask | admin sees `kuduy:admin` unticked; left as rendered → read + write; ticked → admin granted; `kuduy:code` never offered unasked; editor not offered admin and a forged admin box is ignored | I — **pass 2026-11-05** (`test_admins_may_opt_in_to_admin_scope_the_client_did_not_request`; failed before the change) |
| O14 | Sign-in detection | unauthenticated `initialize` → 401 + challenge, so claude.ai detects OAuth | I — **pass 2026-11-05** (0.1.2, also real HTTP); claude.ai check in O11 |
| O15 | Registration cap | unused DCR clients capped; at cap the oldest unused (> 1 h) is evicted, else 429; not per IP | I — **pass 2026-11-05** (0.1.2) |
| O17 | HTTPS required | with HTTPS required (non-local site): token, registration and authorize refused; bearer over plain HTTP gives no user and a 401 mentioning HTTPS; local sites allowed | I — **pass 2026-11-05** |
| O18 | Early user check | a bearer token authenticates even before WordPress creates `$wp_rewrite` (another plugin calling `wp_get_current_user()` on `plugins_loaded`). Before the fix, `rest_url()` there threw `Call to a member function using_index_permalinks() on null` (reproduced on WP 7.1.4) | I — **pass 2026-11-05** |
| O16 | No anonymous outbound fetch | logged-out authorize request with a CIMD `client_id` → login redirect, zero HTTP requests | I — **pass 2026-11-05** (0.1.2) |

## Permissions

| # | Tests | Type |
|---|---|---|
| P1 | Author can edit own post, not others' | I — **pass 2026-11-05** (author cannot list, read or update other people's drafts/posts; contributor cannot trash others' posts) |
| P2 | Editor cannot install plugins | I — **pass 2026-11-05** (editor refused for plugin/theme list, plugin install, theme switch) |
| P3 | Subscriber token sees only read tools it can use | I — **pass 2026-11-05** (`CoreDiagnosticsTest`: a subscriber's `tools/list` contains site info, post list/get, own user and comment reply, and none of plugin/theme/user/settings/diagnostics/content-write/menu/term tools; plus per-tool refusals in every Phase 4 test file and scope filtering O10) |
| P4 | No response or log contains wp-config secrets, password hashes, app passwords, gateway keys | U, I — **pass 2026-11-05** (`CoreDiagnosticsTest` sweep: 11 read tools as administrator + the last 50 audit rows contain none of the salts/keys, `DB_PASSWORD`, the user's password hash, an application password or its hash; a `kmcp_at_` token in post meta is masked. Gateway keys: re-test when WooCommerce tools land, Phase 7) |

## Core WordPress

| # | Feature | Type |
|---|---|---|
| C1 | Posts/pages CRUD, status, schedule, search, count | I — read part **pass 2026-11-05** (`CoreContentTest`: list/search/paging, drafts and private by role, `read_post` per post, password-protected content, unknown type); writes **pass 2026-11-05** (`CoreWriteTest`: create draft with terms + featured image, contributor cannot publish, kses without `unfiltered_html`, scheduling needs a future date, `edit_post` per object, update snapshot + undo restores fields/status/terms, invalid terms/images refused before writing, Elementor content guard, trash + undo restores status). Count not run |
| C2 | CPT + custom taxonomies, terms CRUD | I — read part **pass 2026-11-05** (`kuduy-post-types` hides types without admin UI; `kuduy-term-list` public vs non-public taxonomy); writes **pass 2026-11-05** (`CoreEditorialTest`: author cannot create categories, editor creates parent/child, a term cannot be its own parent, rename + undo, delete keeps posts and undo recreates the term and reassigns posts, default category protected) |
| C3 | Comments moderate/reply | I — **pass 2026-11-05** (subscriber reply held, admin reply approved and threaded, closed comments refused; subscriber sees only approved comments of readable posts and no emails; held/all need `moderate_comments`; contributor cannot moderate others' comments; spam + undo) |
| C4 | Post meta (protected keys blocked), revisions list/restore | I — **pass 2026-11-05** (meta set/get incl. arrays, `_` keys neither read nor written, undo, null deletes, contributor refused; revisions listed with content, contributor refused, restore + undo) |
| C5 | Featured image set/unset | I |
| C6 | Users/roles create/update (no password echo) | I — **pass 2026-11-05** (`CoreUsersTest`: subscriber sees only themselves; list/get never contain the password hash or activation key; role list shows caps and assignable roles; editor cannot create users; admin creates an author with a random, never-returned password; unknown role refused; role change + undo; editor cannot promote; nobody changes their own role; last administrator cannot be demoted) |
| C7 | Plugins/themes list/install/update/activate; essential-plugin guard | I — **pass 2026-11-05** (`CorePackagesTest`, offline through core's real `Plugin_Upgrader` / `Theme_Upgrader` with local ZIPs: list with versions and `Requires Plugins`; install by slug, refuse URLs, unknown and duplicate slugs; activate + undo; deactivate dry run names the dependent plugin and changes nothing, confirm deactivates, undo re-activates; KUDUY cannot deactivate or update itself; update dry run shows 1.0 → 2.0, confirm updates and the plugin stays active; theme switch dry run + confirm + undo; theme install and update) |
| C8 | Menus, widgets, permalinks, site health | I — menus and settings **pass 2026-11-05** (`CoreSiteTest`: editors refused; menu with page link, custom link and submenu; drafts and incomplete links refused; item update keeps other fields + undo; delete + undo; location assign + undo; unknown location refused; settings read/update + undo; URLs, admin email, membership, default role and permalinks refused; bad timezone and front page refused). Diagnostics **pass 2026-11-05** (`CoreDiagnosticsTest`: managers only; Site Health runs in-process checks only (no `rest_availability` loopback) with matching counts; cron lists events with argument counts, never values; rewrite check + flush; hooks inspector names KUDUY's callback with priority, source and relative file). Widgets: Phase 7 |

## Elementor

| # | Feature | Type |
|---|---|---|
| EL1 | List documents/templates, read tree (v3 sections/columns, containers, atomic) | I — **pass 2026-11-05** on Elementor 4.3.6 (`ElementorReadTest`: list with document type, subscriber refused, tree with counts, outline without settings, subtree by id, author refused on someone else's page, unregistered add-on widget named) |
| EL2 | Widget schema discovery v3 (`get_controls`) and atomic (`get_props_schema`) | I — **pass 2026-11-05** on 4.3.6 (heading: `title`, responsive `align` with `align`/`align_tablet`/`align_mobile`; `e-heading` props as JSON Schema with the `$$type` envelope and `anyOf` for overridable values; unknown type refused) |
| EL3 | Add / update / move / duplicate / remove element; IDs unique | I — **pass 2026-11-05** on 4.3.6 (`ElementorWriteTest`: add at index, duplicate with fresh id, move, remove, all ids unique; widget at top level, column in a container and unknown widget refused; one invalid operation saves nothing) |
| EL4 | Patch edit on desktop does not change tablet/mobile values | I — **pass 2026-11-05** (title + `align_tablet` changed, `align` and `align_mobile` kept; `unset align_mobile` removes only that key) |
| EL5 | Save via `Document::save`; revision created; non-`unfiltered_html` user gets kses | I — **pass 2026-11-05** (all saves through `Document::save()`; author's `<script>` stripped; author refused on someone else's page; pages with an inactive add-on widget refused and left untouched; undo restores the previous tree). Revision count not asserted |
| EL6 | Post CSS regenerated; frontend HTML contains edited element ID and text | I — **pass 2026-11-05** (`kuduy-elementor-verify`: rendered HTML contains the element id and texts; missing text reported; drafts checked by rendering only). Live-page fetch: E on staging |
| EL7 | Kit global colors/typography/breakpoints read + update | I — **pass 2026-11-05** (read: Kit id, breakpoints, colors; update: custom color added and read back, undo removes it, unknown id refused, editors refused) |
| EL8 | Theme Builder templates list/conditions (Pro) | I — **pass 2026-11-05** (`ElementorThemeBuilderTest`, Elementor Pro 4.3.2 from the user's licensed ZIP via `KUDUY_LOCAL_PLUGINS`, on WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34, both with Elementor 4.3.6: header created as `elementor_library` with type meta; listed with location, conditions, live flag; `include/general` + an exclude saved through Pro and read back; a second header with the same condition refused naming the first, then allowed with `allow_conflicts` and the conflict reported; undo restores empty conditions; `[]` clears; 5 invalid conditions, a normal page and an unknown type refused; editor refused for conditions and template creation). Local only: CI has no Pro. Pro 4.3.2 needs Elementor core 4.0+, so it does not load next to 3.35.9 |
| EL9 | Addon adapters (EA, UAE, Premium, Jet) widget discovery | I — **pass 2026-11-05** (`ElementorAddonsTest`, WP 7.1 + Elementor 4.3.6 + Essential Addons 6.8.8, header-footer-elementor 2.9.6, Premium Addons 4.11.117: widgets listed with folder and plugin name; schema; page created with an add-on widget and kept on save; text setting edited; rendered with its element id). JetElements: blocked (paid) |
| EL11 | v4 global classes and variables through Elementor's own abilities | I — **pass 2026-11-05** on 4.3.6 (`ElementorAbilitiesTest`: Elementor's input schema used; variable create, read back, undo; editors refused; class create; class snapshot is a copy and says so; invalid input refused). On 3.35.9 the tools report themselves unavailable (tests skip) |
| EL9b | Seven more free add-ons through the generic tools (Happy Addons 3.51.3, HT Mega 3.2.8, PowerPack Lite 3.1.4, Qi Addons 1.11.2, Xpro 1.7.11, Element Pack Lite 10.0.5, Piotnet 2.4.37) | I — **pass 2026-11-14** on WP 7.1.4 + Elementor 4.3.6 (`ElementorAddonsTest`, two CI jobs). Unlimited Elements 2.0.28 registers no widgets on a fresh install: left out |
| EL12 | Duplicate a whole document: elements, page settings and page template copied into a draft; refusals | I — **pass 2026-11-14** on WP 7.1.4 + Elementor 4.3.6 and WP 6.9.11 + Elementor 3.35.9 (`ElementorDocsTest`) |
| EL13 | Document settings: read, patch keeps unnamed settings, unset, undo (also back to none); post fields, unknown names and the Kit refused | I — **pass 2026-11-14**, same runs (`ElementorDocsTest`) |
| EL14 | Dynamic tags: list, set, rendered by Elementor, usage, clear, undo; wrong kind, unknown tag/setting/element refused | I — **pass 2026-11-14**, same runs (`ElementorDocsTest`, with a tag the test registers; Elementor Pro's tags not run) |
| EP1 | Popup settings read: stored triggers/timing, every setting with type, options, limits and the group it belongs to; labels and hidden values left out; a page is refused | I — **pass 2026-11-16** (`ElementorPopupTest`, WP 7.1.4 + Elementor 4.3.6, stand-in `KUDUY_FAKES=elementor-popup` shaped like Pro 4.0.4's popup document; full suite with it: 231 tests, 0 failures, 17 skipped) |
| EP2 | Popup triggers and timing set (switchers from true/"yes", numbers, multi-selects), partial change keeps the rest, unset, undo twice back to nothing | I — **pass 2026-11-16**, same run |
| EP3 | Wrong values refused with nothing saved: unknown name, above max / below min, not a number, unknown option, single value for a list, label or hidden control, bad date, unknown unset, empty call, one bad value among good ones; messages plain text | I — **pass 2026-11-16**, same run |
| EP4 | An author may not change someone else's popup | I — **pass 2026-11-16**, same run |
| EP5 | On Elementor Pro: create a popup, list it, set conditions (shared with another popup), settings tools on Pro's real controls | E — **pass 2026-11-16** on the owner's site (KuDiagnostics 1.3.5, Elementor 4.4.0 + Pro 4.0.4, temporary connection): two draft popups created (`template_type: popup`) and listed with location popup; `kuduy-elementor-popup-settings` showed Pro's real controls with options and limits over REST (14 trigger and 25 timing settings; the hidden server_datetime left out); triggers and timing set, partial change with unset, six wrong values refused against Pro's options (scroll offset over 100, unknown direction, device, role, a hidden control, a bad date); undo twice back to no settings; both popups took include/general with no conflict, then cleared; popups trashed, token revoked. **Found:** empty triggers/timing came back as `[]` instead of `{}` (the redactor turned empty objects into lists) → empty objects stay objects (EP1 checks the JSON text; full suite with the change: 231 tests, 0 failures, 17 skipped) |
| EL10 | Build a full page from a prompt on 3.x and 4.3 | E — **pass on 4.3 2026-11-05**, claude.ai web chat → Hostinger staging (KUDUY MCP 0.4.0, WP 7.1.4, Elementor 4.3.6, Elementor Pro 4.0.4, WooCommerce 11.2.2; admin user, grant `kuduy:read kuduy:write`). Draft page 400 created from a prompt (hero heading/text/button, 3 features, CTA; 11 elements); heading `align` center / `align_tablet` start / `align_mobile` end; desktop `align` edited to end → tablet/mobile unchanged; snapshot restore → back to center, tablet/mobile unchanged; `kuduy-elementor-verify` 11/11 ids, 7/7 texts (draft: render only); draft header template 404 created; page trashed (undo snapshot 2). Step 6 was not covered then (`kuduy-elementor-theme-conditions` needs `kuduy:admin`, which that grant lacked). **Step 6 re-run 2026-11-09 — pass** (claude.ai "Kuduy" connector with `kuduy:admin` granted, called from a Claude Code session in the Claude desktop app; KUDUY MCP 0.6.0, WP 7.1.4, Elementor 4.3.6, Elementor Pro 4.0.4): draft page 415 created; draft header 404 given `include/singular/page/415` → saved, no conflicts (snapshot 13); `include/general` → refused, naming live header 386 (`include/general`), nothing changed; conditions cleared with `[]` (snapshot 15); header 386 unchanged; page 415 trashed (snapshot 16). Evidence: this tool-call log, recorded by the Claude Code session that ran it. Elementor 3.x: integration tests only, no real-client run. Findings: v4 heading `align` takes start/center/end/justify (no left/right); `_title` on a container is refused by validation (first create saved nothing, retry without it passed). Evidence: claude.ai run report pasted by the user |

## Avada (D31)

Runs with `KUDUY_FAKES=avada` (a stand-in for Avada Builder's registry with real parameter shapes, `tests/integration/fakes/avada.php`; Elementor active). AV1–AV3 need no plugin and run in every job. Fixture: `tests/integration/fixtures/avada-page.txt`, the structure of a real Avada 7.16 page with neutral content.

| # | Feature | Type |
|---|---|---|
| AV1 | Real-structure page and edge cases round-trip byte for byte | I — `AvadaParserTest::test_real_page_round_trips_byte_for_byte` |
| AV2 | Tree shape: containers, rows, columns, nested columns, global element as a single tag, third-party and unknown shortcodes, duplicated and responsive attributes, base64 settings decoded | I — `AvadaParserTest::test_tree_shape`, `test_widths` |
| AV3 | An edit rewrites only the changed tag; quotes and brackets encoded; a duplicated attribute changes in place | I — `AvadaParserTest::test_an_edit_rewrites_only_the_changed_tag` |
| AV4 | Status (combination, versions, Library, Layouts, other builders) and element discovery incl. a third-party element, options value → label, defaults, responsive | I — `AvadaTest::test_status_and_elements` |
| AV5 | Tree with labels and ids, structure with parent ids, responsive values, unregistered settings, global id; one part by id; raw content; Library | I — `test_tree_structure_content_and_library` |
| AV6 | Dry-run create saves nothing; create from containers (exact markup); add container at a position, column via container id, nested columns, element, global element; wrong parents and widths refused, nothing changed | I — `test_create_page_and_add_containers_columns_elements` |
| AV7 | Update with dry run (before/after report, nothing saved), in-place change with responsive removal, verified, undo to the exact page; button, container and column updates keep everything else; tag check, global content, stale hash refused | I — `test_update_with_dry_run_change_report_and_undo` |
| AV8 | Move between columns (unknown shortcodes intact), duplicate and delete a container with undo, only column and container row protected, replace with option warnings and undo, invalid layouts never saved, validate warnings, page CSS with tags stripped and undo | I — `test_move_duplicate_delete_replace_and_page_css` |
| AV9 | Global Options: design settings returned, API keys and captcha secrets never, unrelated settings left out; authors refused | I — `test_shared_items_permissions_and_other_builders` |
| AV10 | Library item change refused without `confirm_shared`; author and invalid ids refused; Elementor and WPBakery pages refused with Elementor data untouched; `kuduy-post-update` refused on an Avada page | I — same test |
| AV11 | Real Avada: detection, element discovery, create, render, Avada's own editor, every edit tool, undo, guards | E — **pass** (staging, 2026-11-13: Avada 7.16.4 + Builder 3.16.4 + Core 5.16.3, WP 7.1.4, KuDiagnostics 1.2.0). `kuduy-avada-status`: theme_and_builder, 181 elements registered via `awb_maybe_init_element_maps()`; `kuduy-avada-elements` gives real settings (button: 40, options, defaults, groups); Global Options: 400 design keys, none secret. Test page created from the fixture (`verified: true`); front end renders containers, rows, columns, nested columns, titles, button, no raw shortcodes; Avada's backend builder opens it (2 containers, 5 columns, 13 elements). Dry run, title + responsive, button link/target, container background, add container, add element, nested columns, move, duplicate, delete, page CSS: all saved and `verified`, all visible on the page (H1, link, background, CSS printed by Avada). Invalid replace refused. Undo restored the page byte for byte and removed the CSS. `kuduy-post-update` refused on the Avada page. Test page trashed, test sign-in revoked. Found and fixed: tree labels showed `&amp;`; Global Options cap now keeps the alphabetically first 400 keys. Second round (2026-11-14, 1.2.1): Library item created as `fusion_element` and listed; page with `[fusion_global]` and tabs with two child tabs created, validated clean, rendered (global text, both tabs, no raw shortcodes); Library change refused without `confirm_shared` ("used on 1 page"), done with it and visible on the page; one child tab renamed in place and undone; both trashed, sign-in revoked (old token 401) — 15/15. Third round (2026-11-14): 40 WooCommerce Avada elements discovered, product grid added and shown by Avada ("Please add products", none on staging); Layout section (`fusion_tb_section`) created, listed, change refused without `confirm_shared`; no Avada Layout on staging, so the Layout refusal stays CI-only; the page opened in Avada's front-end Live Builder (both containers, title, button, no errors); re-saved from Avada's backend builder: structure kept and defaults added, but a setting value written with `&quot;`/`&#91;` came back raw and broke the title tag (caught by `kuduy-avada-validate`). **Fixed:** double quotes and square brackets in Avada setting values are now refused. Not checkable here: other Avada versions, pages built by hand in Avada's editor |

## Themes (D38)

Runs: `KUDUY_THEME=<slug>@<version>` — `astra@4.14.2`, `kadence@1.6.0`, `blocksy@2.1.61`, `generatepress@3.6.1`, `oceanwp@4.2.8`, `ollie@1.6.6`, `spectra-one@1.2.6`; `ThemeSettingsTest`, `AstraAbilitiesTest`, `SiteEditorTest`.

| # | Feature | Type |
|---|---|---|
| TH1 | Theme facts match the active theme; Customizer details only with `edit_theme_options`; registry row active | I — **pass 2026-11-15** on WP 7.1.4 with all seven themes and the default block theme |
| TH2 | Core setting (site title) round trip through the Customizer path, undo; unknown ids and menu settings refused; one bad id saves nothing; editor refused | I — **pass 2026-11-15** on every theme except Astra (managed with abilities) |
| TH3 | A select/radio setting of the theme: set another choice, later read, storage changed, undo restores storage exactly | I — **pass 2026-11-15** on GeneratePress; skipped where the theme has no plain select/radio control (Kadence, OceanWP, Blocksy use their own control types) |
| TH4 | A known theme setting lands in the theme's own storage and undo restores it | I — **pass 2026-11-15**: GeneratePress `generate_settings[container_width]` (option), Kadence `scroll_up` (theme mod), OceanWP `ocean_main_container_width` (theme mod) |
| TH5 | Astra abilities listed with kind and input schema; facts point to them | I — **pass 2026-11-15** on Astra 4.14.2 (abilities switched on by the runner) |
| TH6 | Astra write ability changes Astra's stored option; undo restores it exactly | I — **pass 2026-11-15** |
| TH7 | Astra validates its own input; read/write tools refuse the other kind; post-meta ability left to post tools; editor refused | I — **pass 2026-11-15** |
| S1–S5 on Ollie and Spectra One | Site Editor tools on two more block themes | I — **pass 2026-11-15** (`SiteEditorTest`) |

Found on the way: themes rely on core's Customizer settings existing before their own `customize_register` callbacks (core registers first on the real screen); Astra builds its settings only inside the Customizer and a Customizer-path save did not reach its option, so Astra goes through its abilities; Blocksy loads its registration only when the manager exists at theme load, so the tools include that file (added 2026-11-15, TH4 passes). Undo is checked against stored values because `WP_Customize_Setting` and Astra cache per PHP process and all tests share one process. WP 6.9.11 / PHP 8.1 (2026-11-15): Astra, GeneratePress, OceanWP, Ollie and Spectra One pass the same tests. Kadence 1.6.0 could not be run on PHP 8.1: Playground's PHP 8.1 build crashes ("memory access out of bounds") on every request with Kadence active, including tests that do not touch the theme tools; Kadence passes on WP 6.9.11 with PHP 8.3. Blocksy was not run on 6.9.

## Checks on the owner's premium test site (2026-11-15)

Site: the owner's Hostinger test site (WP 7.1.4, PHP 8.3, KuDiagnostics 1.3.0), connected to the session. Each product was activated alone, checked through WordPress' own Abilities REST API in the logged-in browser and with `kuduy-dev-db-query`, then deactivated; test pages were trashed. KuDiagnostics' builder tools themselves could not be called: the client keeps the tool list it had when it connected, before these plugins were active.

| Product | Checked | Result |
|---|---|---|
| Breakdance 3.0.0-beta.4 | 42 abilities under `breakdance/`; annotations (`readonly` true on reads, null otherwise); inputs (`post_id`, `operations`); create-post and edit-post run; page data in `_breakdance_data` (+ `_breakdance_css_file_paths_cache`, `_breakdance_dependency_cache`); `Breakdance\Render\generateCacheForPost()` exists | Matches. **Two defects found and fixed:** `report-bug` is marked read-only (our read tool would have sent data to the vendor) → refused in every mode; `set-home-page` takes a `post_id` but changes the site → refused as site-wide. **Third defect:** first activation runs `exit( wp_redirect( …onboarding ) )` in an `activated_plugin` callback, so `kuduy-plugin-activate` returned the redirect page instead of its result → redirects are now stopped and reported during activation (CorePackagesTest) |
| Oxygen 6.2.0 | 51 abilities under `oxygen/`; same annotations; `report-bug` read-only, `set-home-page` with `post_id`; `run-site-tool` takes `post_ids` (treated as site-wide); create-post and edit-post run; page data in `_oxygen_data` | Matches, with the fixes above |
| WPBakery 9.1 | Source: `WPBMap::getAllShortCodes()` expands lean-mapped elements (`generateData()` → `setElementSettings()`), `addAllMappedShortcodes()`, `getShortCode()`, `exists()`; mapping runs on `init` in every request (`Vc_Manager::init()` → `mapper()->init()`); `WPB_VC_VERSION` 9.1; meta keys `_wpb_vc_js_status`, `_wpb_shortcodes_custom_css` | Matches the stand-in's contract |
| Bricks 2.4.5 (theme, inactive) | Source through the theme file viewer: storage constants `BRICKS_DB_PAGE_HEADER/CONTENT/FOOTER` = `_bricks_page_header_2` / `_content_2` / `_footer_2`; `includes/abilities/manager.php` registers 167 abilities (only when `abilitiesApi` is on in Bricks' global settings; diagnostics always on); reads carry `readonly: true`, Bricks sets `destructive: false` when omitted; the page is `postId` (or `slug`, `path`, `title`); writes include delete-post (with `forceDelete`), delete-media, restore-revision, import-transfer-package | Read tool matches. **Defect found and fixed before use:** with 167 abilities, "any write that takes a post" let delete-post, restore-revision and similar run → Bricks writes are now an explicit page-level allow-list, and every post write must name the page by its numeric id (slug/path/title would have skipped the snapshot) (BricksTest) |
| Divi 5.19.0 (theme, inactive) | Source: 116 `module.json` files, module names `divi/section`, `divi/row`, `divi/column`, `divi/text`, …, registered as WordPress block types (`ModuleRegistration::register_block_type_from_metadata()`) | Matches the read tools' `divi/` prefix and detection |

### KuDiagnostics' builder tools end to end on that site (2026-11-15, KuDiagnostics 1.3.1)

A temporary OAuth connection (the plugin's normal flow: client registration, PKCE, the owner's approval on the consent screen; scopes read, write, admin) called the tools over real HTTP as JSON-RPC, on two new draft pages. Afterwards the access and refresh tokens were revoked (the endpoint then refused the token) and both pages trashed.

| Product | Run | Result |
|---|---|---|
| Breakdance 3.0.0-beta.4 | `kuduy-breakdance-abilities`: 42 abilities, 22 read, 12 write, 8 not run (report-bug, set-home-page, set-global-settings, CSS variables/selectors/stylesheet, mark-submission-read). `-ability-run` create-post → page; edit-post insert of a Heading (snapshot); `-ability-get` get-post-tree shows it; edit-post update → text changed in the stored tree and on the rendered page; `kuduy-snapshot-restore` → old text in the tree and on the rendered page. Refusals: report-bug, set-home-page, set-global-settings, a write through `-ability-get` | Pass. **Defect found:** refusal texts showed `&#039;` for an apostrophe (HTML escaping of JSON text) → fixed, assertion added (BA3) |
| WPBakery 9.1 | `kuduy-wpbakery-elements` (89 elements from the real `WPBMap`, e.g. `vc_column_text` with its params); `-create` from shortcodes; `-tree`; `-add-element` (`vc_btn`); `-update-element` (text); rendered page shows both; stale `if_hash` refused; unknown parent id refused; `-validate` valid; undo of both changes in reverse order → stored content byte-identical to before | Pass |
| Oxygen 6.2.0 (alone; Breakdance and WPBakery deactivated, then back on) | Second temporary connection, same flow. `kuduy-oxygen-abilities`: 51 abilities, 28 read, 14 write, 9 not run (report-bug, set-home-page, set-settings, run-site-tool, CSS variables/selectors/stylesheet, mark-submission-read); the Breakdance tools are gone while Oxygen is the active mode. create-post → page; edit-post insert of `OxygenElements\Text`; get-post-tree shows it and the rendered page has it; edit-post update → stored tree and rendered page changed; `kuduy-snapshot-restore` → both back. Refusals: report-bug (run and get), set-home-page, set-settings, run-site-tool, a write through `-ability-get` | Pass (the site runs 1.3.1, so its refusals still show the `&#039;` fixed on main) |
| Bricks 2.4.5 (theme switched from Hello Elementor; "Enable Bricks abilities" on under Bricks > AI; Breakdance and WPBakery off; all put back afterwards) | Third temporary connection. Bricks then registered 170 abilities. `-ability-run` create-post → page 525; add-element (heading, snapshot); `kuduy-bricks-get` and `-ability-get` get-page-elements read it; update-element → stored elements and rendered page changed (Bricks also wrote a revision); `kuduy-snapshot-restore` → both back; `kuduy-bricks-pages` finds the page. Refusals: set-global-settings, a write through `-ability-get`. delete-post was **not** tried: 1.3.1 on the site predates the page-level allow-list on main | Page round trip passes. **Defect found:** `kuduy-bricks-abilities` (the list, and `name` = a no-input write such as regenerate-css-files) and `-ability-run` on such an ability failed with an exception: Bricks declares an empty `properties` as an object (stdClass) and `blocked()` indexed it as an array. Fixed (properties read as an array); the Breakdance stand-in's set-global-settings now has an object `properties` (BA1 failed without the fix), the Bricks stand-in has regenerate-css-files with Bricks' own description (BR2). On main the Bricks allow-list also stops before that line |
| Bricks 2.4.5 re-check on KuDiagnostics 1.3.2 (same setup, put back afterwards) | Fourth temporary connection. `kuduy-bricks-abilities`: all 170 listed (75 read, 13 run here, 82 not run), regenerate-css-files listed with the page-level reason. Refused with nothing changed: delete-post (with postId), restore-revision, regenerate-css-files, execute-php, update-element naming the page by title. create-post → add-element → update-element → `kuduy-snapshot-restore` round trip on page 528 passes | Pass. **Found:** update-popup-settings was on the page-level list but names the popup by `templateId`/`popupId`, so it was refused with the wrong reason ("site-wide settings"), and its template settings sit outside the `_bricks_page_` data a snapshot keeps → taken off the list (BR4). Also seen: descriptions lose a literal `<style>` mention (tag stripping); the text otherwise reads right |
| Divi 5.19.0 (theme; Breakdance and WPBakery off; all put back on 2026-11-16) | No page on the site had Divi 5 content. Draft page 531 was built in Divi's own Visual Builder (it starts only in a wide window, 1440 px; at 800 px it stays a plain page): section > row > column > heading + button, saved as draft. Stored as `divi/placeholder` > `divi/section` > `divi/row` > `divi/column` > `divi/heading` + `divi/button`, text under `<field>.innerContent.desktop.value` (string, or `{text}` for the button). Temporary connection (read scope + admin box ticked on the consent screen), then revoked; page trashed | Structure read right (6 blocks, tree as stored). **Found:** module text was missing (Divi 5 keeps it in attributes, not block HTML) → `kuduy-divi-get` now reads it from `innerContent.desktop.value`; `kuduy-divi-pages` listed Divi's internal post (`_et_pb_speculation`, "Visual Builder Asset Prerender") → post types without an admin screen are left out. BK1 now uses the markup Divi saved |
| Beaver Builder Lite 2.11.1.2 (Breakdance and WPBakery off, Hello Elementor, WooCommerce 11.3.0 active; put back afterwards; KuDiagnostics 1.3.3) | Temporary connection. `kuduy-beaver-status` and `-modules` read (18 modules); `-layouts` empty. **Defect found:** `can_build` false for the administrator and `kuduy-beaver-create` refused ("does not allow your role"); `post_types` listed fl-builder-template twice. Nothing was created on the site | Cause found by a local repro over real HTTP (Playground server, same Beaver and WooCommerce versions, a probe mu-plugin logging Beaver's access cache per hook; script not kept in the repo): Beaver caches its user-access table on `init` from get_editable_roles(); over REST the user is not known yet then (Application Password and OAuth alike), and WooCommerce's `editable_roles` filter drops "administrator" for a guest → no entry → refused for the whole request. Without WooCommerce: works. Fixed: `Beaver::can_build()` applies Beaver's own rule to the actual user with its public data (saved value per role, else the registered default roles) when Beaver's cached answer is no; post types de-duplicated. BB7 rebuilds the cache as a guest without the administrator role and checks status and create; the repro with WooCommerce then gives can_build true and creates a layout over both OAuth and Application Password. **Still to do on the live site after a release:** create, edit, undo |
| Beaver Builder Lite 2.11.1.2 re-check on KuDiagnostics 1.3.4 (same setup, WooCommerce 11.3.0 active; put back afterwards) | Temporary connection. `kuduy-beaver-status`: can_build true, post types listed once. `-create` → page 535 with a heading and a rich-text module (5 nodes); `-get` reads the tree; rendered page shows both texts; `-edit` update of the heading → stored and rendered text changed (snapshot); `kuduy-snapshot-restore` → old text stored and rendered; an unknown node id is refused with nothing saved; `-layouts` lists the page. Token revoked, page trashed | Pass |
| Elementor 4.4.0 + Elementor Pro 4.0.4 (Hello Elementor; Breakdance and WPBakery off; Elementor was inactive before and is again; KuDiagnostics 1.3.4) | Temporary connection (read, write, admin). 16 Elementor tools listed; `kuduy-elementor-widgets` 175 widgets incl. Pro (form, posts, nav-menu). `-create` → page 540 (container, heading, text editor); `-get` reads it; `-verify` finds ids and texts in the rendered HTML; `-edit` (update heading + add a button) → `-verify` finds the new texts; `kuduy-snapshot-restore` → rendered page has the old heading and no button; unknown element id refused, nothing saved. Theme Builder: `-create` header template 544 (draft); `-theme-conditions` include/general refused as a conflict with the site's live header 386 (unchanged); include/singular/page/540 set and listed, then cleared. Page and template trashed, token revoked | Pass. **Found:** titles in `-theme-templates` came back as HTML (`&#8211;` for "-") and the conflict message escaped quotes (`&quot;`) → plain text now (assertions added to the Theme Builder test, which needs Elementor Pro locally: not run, the Pro ZIP is not on the machine). **Not built yet:** Pro popups (no tool reads or sets popup triggers and timing) |
| Brizy 2.8.26 free (alone: Elementor and Pro off, then back on; KuDiagnostics 1.3.5; 2026-11-16) | No page used Brizy. Draft page 551 built in Brizy's own editor (one free block: label, heading, button, three icon-text items), saved as draft; Brizy stored `brizy`, `brizy_enabled`, compiled sections. Temporary read-only connection, then revoked; page trashed | Pass: `kuduy-brizy-status` (2.8.26, editor 383-wp, free, can_build true); `-pages` lists the page as compiled; `-get` reads the tree as Brizy stored it (31 elements: Section > SectionItem > Row > Column > Wrapper > RichText / Cloneable > Button, Spacer, IconText > Icon + RichText) with the texts; `include_values` returns each element's stored settings. **Found:** `post_types` listed Brizy's own types twice, and rich text of several paragraphs ran together ("Implement NextTechnologies") → de-duplicated, paragraphs keep a space (BZ1, BZ2). Also seen: Brizy's save stores the page title texturized (`&#8211;`); the tools report the stored title |
| Kirki 6.4.2 (alone: Elementor and Pro off, then back on; KuDiagnostics 1.3.5; 2026-11-16) | No Kirki page existed. Draft page 559 built in Kirki's builder from its prebuilt page "Innovation in every single detail" (Header, Hero, About, Feature, Key Metrics, CTA, Footer) and saved. Temporary read-only connection | `kuduy-kirki-status` (6.4.2, can_build true) and `-pages` (lists page 559) pass. `-get` returned 0 blocks: Kirki's Save adds an unpublished staged version (`kirki_stages` version 2, 82 KB of blocks) while the published version 1 is empty, and the tool read only the published one. **Changed:** `kuduy-kirki-get` now says `unpublished_changes` and reads the newest unpublished version with `version: latest`, through Kirki's own `HelperFunctions::is_kirki_type_data( $id, $version )` and its page facade (KK4, against the real Kirki 6.4.2: published and latest read correctly). Not run live: the live site has 1.3.5 without the option, and publishing in Kirki would have made the page public. Page trashed. The temporary connection's access token expired; its refresh token (row 178) could not be revoked from the page because the browser lost it during a pause, and the connections page is hidden (hidden mode) |
| Blocksy 2.1.62 (theme switched from Hello Elementor through the site's own connector, KuDiagnostics 1.3.6; 2026-11-16) | `kuduy-theme-facts` (946 Customizer settings, 4 menu locations); `-settings` lists `narrowContainerWidth` (750); `-settings-update` to 760 → stored 760, but the page CSS still said `--theme-narrow-container-max-width:750px` (no page cache: `DYNAMIC`, logged in too); undone | **Defect found:** Blocksy keeps theme mods in memory for the whole request outside the admin, the Customizer and admin-ajax (`inc/classes/database.php`); an MCP call is a REST request, so the CSS Blocksy rebuilt on `customize_save_after` (transient `blocksy_dynamic_styles_descriptor`) used the old value → Blocksy's own `blocksy_manager()->db->wipe_cache()` is called first (TH5 fails without it: rebuilt CSS kept 1290px; passes with it, Blocksy 2.1.62). Live re-check after the next ZIP |
| GeneratePress 3.6.1 (live, 1.3.6, 2026-11-16) | `kuduy-theme-settings` lists `generate_settings[container_width]` (1200); `-settings-update` to 1180 → page CSS `max-width:1180px` at once; `kuduy-snapshot-restore` → 1200px again | Pass |
| OceanWP 4.2.8 (live, same run) | `ocean_main_container_width` 1200 → 1180 → page has `.container{width:1180px}`; undo removes it | Pass |
| Kadence 1.6.0 (live, PHP 8.3, same run) | `content_width` {size 1290, unit px} → 1180 → `--global-content-width:1180px`; undo → 1290px | Pass |
| Ollie 1.6.6 and Spectra One 1.2.6 (block themes, live, same run) | `kuduy-theme-facts` points to Global Styles; `kuduy-global-styles-update` background `#fafbfe` → on the rendered page; undo → styles empty again | Pass |
| Astra 4.14.3 (live, KuDiagnostics 1.3.7, 2026-11-17) | With Astra's abilities off, `kuduy-astra-abilities` says so. Switched on for the check with Astra's own admin action (`astra_update_admin_setting`, enable_abilities + enable_edit_abilities; the Settings screen of 4.14.3 showed no switch for them) and off again afterwards. 74 abilities listed (reads and writes sorted); `astra/get-paragraph-margin` 1.6em; `astra/update-paragraph-margin` 2.2 → page CSS `margin-bottom:2.2em`; `kuduy-snapshot-restore` → 1.6em and the rule gone; a write through `kuduy-astra-ability-get` refused. Hello Elementor active again | Pass. Note: the tool's message sends owners to Astra > Settings, where 4.14.3 shows no switch (Astra also has `wp astra abilities` commands) |
| Avada | Not switched in this run: checked on real Avada 7.16.4 on staging on 2026-11-13 (AV11) | — |
| After the theme run | Hello Elementor active again; home page 200 with the Elementor header | — |


## Bricks, Divi 5, Etch (D41)

Run: `KUDUY_FAKES=bricks,blockbuilders` (`BricksTest`, `BlockBuildersTest`).

| # | Feature | Type |
|---|---|---|
| BR1 | Bricks read tool: pages with areas, element tree with labels and text, settings on request, missing area refused, author refused | I — **pass 2026-11-15** on WP 7.1.4 and 6.9.11, stand-in |
| BR2 | Bricks abilities listed: read by annotation or by name when unannotated; set-page-elements runs here; set-global-settings and execute-php do not | I — **pass 2026-11-15**, stand-in |
| BR3 | get-page-elements and set-page-elements through the tools; undo restores the Bricks meta | I — **pass 2026-11-15**, stand-in |
| BR4 | PHP and site-wide abilities refused and not run; Bricks refuses invalid elements; editor refused | I — **pass 2026-11-15**, stand-in |
| BK1 | Divi 5 pages listed (blocks and Divi 4 shortcodes), block tree, attributes on request, Divi 4 page reported without tree | I — **pass 2026-11-15**, stand-in |
| BK2 | Etch pages listed and read as a block tree with text | I — **pass 2026-11-15**, stand-in |
| BK3 | Wrong builder and plain pages refused; author refused and not listed; reading changes nothing | I — **pass 2026-11-15**, stand-in |

Found on the way: WordPress fills unset ability annotations with null, so an annotation counts only when the builder set it. Not run: the real Bricks, Divi or Etch.

## Breakdance 3 and Oxygen 6 (D40)

Runs: `KUDUY_FAKES=breakdance` and `KUDUY_FAKES=oxygen` (`BuilderAbilitiesTest`); the stand-in registers abilities with the names, meta and permission read in the real plugins.

| # | Feature | Type |
|---|---|---|
| BA1 | Abilities listed with kind and whether they run here; input schema; registry row partial | I — **pass 2026-11-15**, stand-in, both builders |
| BA2 | Read abilities; edit-post stores the tree in the builder's meta; undo removes it and asks the builder to rebuild; create-post | I — **pass 2026-11-15**, stand-in |
| BA3 | Site-wide write and bug report are not run | I — **pass 2026-11-15**, stand-in |
| BA4 | Read/write tools refuse the other kind; unknown ability; the ability validates its input; editor refused by the builder's own permission | I — **pass 2026-11-15**, stand-in |

Not run: the real Breakdance or Oxygen (the owner's test site has them installed but inactive, and runs KuDiagnostics 1.2.2, which does not have these tools).

## Brizy and Kirki (D39)

| # | Feature | Type |
|---|---|---|
| KK1 | Kirki status; registry row active, partial, tools available | I — **pass 2026-11-15** on WP 7.1.4 and 6.9.11 with `kirki@6.4.2` (`KirkiTest`) |
| KK2 | List Kirki pages; block tree with names, tags and text; properties on request | I — **pass 2026-11-15**, both pairs. The fixture page is stored as Kirki stores one and read back through Kirki's helper |
| KK3 | Not a Kirki page refused; author refused and not listed; reading changes nothing | I — **pass 2026-11-15**, both pairs |
| KK4 | A newer unpublished Kirki version: published read by default with `unpublished_changes: true`, `version: latest` reads the unpublished blocks (fixture saved through Kirki's own staging code) | I — **pass 2026-11-16** (`KirkiTest`, Kirki 6.4.2, WP 7.1.4) |
| BZ1 | Brizy status; registry row partial, tools available | I — **pass 2026-11-15** on both pairs against a **stand-in** (`BrizyTest`, `KUDUY_FAKES=brizy`) |
| BZ2 | List Brizy pages; structure with type, id, text; values on request | I — **pass 2026-11-15**, stand-in |
| BZ3 | Not a Brizy page refused; author refused; reading changes nothing | I — **pass 2026-11-15**, stand-in |

Brizy 2.8.26 itself fails on the SQLite test site (`mysqli_query()` on the database handle in its migrations), so it is not verified here. Blocksy on WP 6.9: TH1, TH2, TH4 pass (2026-11-15).

## Beaver Builder (D37)

Run: `beaver-builder-lite-version@2.11.1.2` (real plugin), `BeaverTest`.

| # | Feature | Type |
|---|---|---|
| BB1 | Status, modules, module / row schema; registry row active with tools available | I — **pass 2026-11-14** on WP 7.1.4 and 6.9.11 |
| BB2 | Create builds layout data, builder flag and post content; tree read, outline, list; invalid module creates nothing | I — **pass 2026-11-14**, both pairs |
| BB3 | Edit: update, add module at a position, duplicate, add row; move module and row, remove; two undos restore layout and content | I — **pass 2026-11-14**, both pairs |
| BB4 | One failing operation saves nothing and leaves no stale draft | I — **pass 2026-11-14**, both pairs |
| BB5 | Refusals: unknown node, row as module parent, column width, module type change, not a Beaver page, unpublished builder changes, author, subscriber | I — **pass 2026-11-14**, both pairs |
| BB6 | Script markup refused without `unfiltered_html`; plain markup saved | I — **pass 2026-11-14**, both pairs |

First run: 5 of 6 passed; the failure was the test changing the draft behind Beaver Builder's in-memory cache and through its shallow copies. Not run: a real client, the paid version.

## WPBakery Page Builder (D29)

Runs with `KUDUY_FAKES=wpbakery` (a stand-in for WPBakery's element registry, `tests/integration/fakes/wpbakery.php`; Elementor stays active). WB1–WB3 need no plugin and run in every job. Not verified against a licensed WPBakery.

| # | Feature | Type |
|---|---|---|
| WB1 | Parse → serialize is byte-identical: basic page, nested rows/columns, several levels, self-closing, special characters, whitespace, third-party, unknown and loose text, raw HTML, empty, no shortcodes | I — `WpBakeryParserTest::test_round_trip_is_byte_identical` (11 layouts) |
| WB2 | Tree shape: nested rows and columns, several levels, self-closing and attribute forms, encoding, unknown elements kept, same-tag pairing like WordPress | I — `WpBakeryParserTest` |
| WB3 | An edit rewrites only the changed tag; 3,200 elements parse in under 2 s | I — `WpBakeryParserTest` |
| WB4 | Elements discovered from the registry incl. an add-on's; settings with type, default, options, dependency; search; unknown tag refused | I — `WpBakeryTest::test_elements_come_from_the_registry` |
| WB5 | Tree with ids and drawing, full structure, one part by id, raw content and hash | I — `test_structure_tree_and_content` |
| WB6 | Create a page from rows: design CSS + page meta, responsive offset, link / checkbox / repeating-group values, quotes and brackets encoded; malformed nesting and unknown template refused | I — `test_create_a_page_from_rows` |
| WB7 | Add row at a position, column into a row, element into a column, inner row, top-level element wrapped; wrong parents refused and nothing changed | I — `test_add_rows_columns_and_elements` |
| WB8 | Update element / row / column: only that tag changes, null removes, design merges, layout sets widths; undo restores the exact content; tag check, stale hash and CSS injection refused | I — `test_update_touches_only_the_addressed_element_and_can_be_undone` |
| WB9 | Move between columns and reorder (unknown element intact), duplicate column and row, delete with undo; only column, move into itself, column to top level and missing id refused | I — `test_move_duplicate_and_delete` |
| WB10 | Replace with warnings for unregistered attributes and option values, undo; invalid layouts never saved; validate reports orphan closing tags, misplaced inner rows, unknown elements, loose text | I — `test_replace_and_validate` |
| WB11 | Raw HTML decoded and re-encoded; adding it needs `unfiltered_html`; WordPress filters script tags for such accounts; invalid page and element ids, bad attribute names; author and subscriber refused | I — `test_raw_html_is_decoded_and_needs_unfiltered_html`, `test_permissions_and_invalid_ids` |
| WB12 | Elementor page: WPBakery writes refused, Elementor data and meta untouched; `kuduy-post-update` content refused on a WPBakery page, other fields allowed; Elementor suites green in the same run | I — `test_elementor_pages_and_plain_updates_are_left_alone` + `Elementor*Test` |
| WB13 | Real WPBakery: discovery, editor round trip, frontend rendering | E — **open** (needs a licensed copy) |

## Integration registry (D30)

| # | Feature | Type |
|---|---|---|
| IR1 | Catalogue is consistent: unique ids, known categories and classes, every tool prefix matches a registered tool, a row called full or partial names tools and a row with tools is not called unsupported, partial and detection rows say what is missing, at least the 58 targets present | I — `IntegrationsTest::test_catalogue_is_consistent` (every job) |
| IR2 | Reports what is on the site: WordPress itself, Elementor active with its version and tools; uninstalled ones only with `all`; an unimplemented builder is `none` with no tools; WPBakery is `partial` and its tools count as available only while the plugin is loaded; category and search filters | I — `test_reports_what_is_on_this_site` |
| IR3 | Versions only for users who can manage plugins; subscriber refused; unknown category refused | I — `test_versions_only_for_those_who_manage_plugins` |

## Tool contract, confirmation tokens, discovery (phase M, D44–D46, D52)

Run 2026-11-17 on both pairs (WordPress 6.9.11 / PHP 8.1.34 and 7.1.4 / PHP 8.3.36): 236 tests, 0 failures; `ContractTest` 8 tests, 217 assertions; smoke 26/26.

| # | Feature | Type |
|---|---|---|
| CT1 | Tool errors name their category in a second text block and `_meta`, first block unchanged, no `structuredContent`: invalid arguments → `INVALID_ARGUMENT`, missing object → `RESOURCE_NOT_FOUND`, capability → `PERMISSION_DENIED`; unknown categories fall back to `OPERATION_FAILED` | I — `ContractTest::test_errors_name_their_category` |
| CT2 | A tool whose plugin is not loaded answers `PLUGIN_NOT_ACTIVE` or `FEATURE_NOT_SUPPORTED` | I — `test_unavailable_tool_names_its_category` (skipped only if every tool is available) |
| CT3 | `kuduy-media-delete`: first call changes nothing and returns preview (uses listed) + token; with the token the attachment and its file are gone; the token is never in the audit log, a reference is | I — `test_media_delete_needs_a_confirmation` |
| CT4 | A token is refused (`CONFLICT`, nothing deleted) for another item, on second use, for another account, after the item changed, after expiry; a malformed token is refused by the schema | I — `test_a_token_fits_one_call_only` |
| CT5 | `kuduy-media-delete` needs `kuduy:admin`, is marked destructive, has no required output fields | I — `test_media_delete_is_an_admin_scope_tool` |
| CT6 | `kuduy-integration-get`: live state, required integrations with their state, each tool with availability and scope; schemas only on request; versions only for those who manage plugins | I — `test_integration_get` |
| CT7 | `kuduy-integration-diagnose`: one integration or all installed; findings with levels; refused without `activate_plugins` | I — `test_integration_diagnose` |
| CT8 | Every required integration exists in the catalogue; Spectra Blocks and Spectra Legacy are separate rows | I — `test_catalogue_dependencies_exist` |

## Blocks (phase C1)

Run 2026-11-17: full suite 247 tests, 0 failures on WordPress 6.9.11 / PHP 8.1.34 and 7.1.4 / PHP 8.3.36; with GenerateBlocks 2.5.1, Kadence Blocks 3.9.1 and Spectra Legacy 2.20.6 active on both pairs, 0 failures and `test_block_plugins_when_active` ran (38 assertions on 6.9).

| # | Feature | Type |
|---|---|---|
| BK-E1 | Editor-made markup round-trips through WordPress' serializer; `kuduy-block-validate` reports it valid | I — `BlockEditTest::test_unchanged_content_round_trips` |
| BK-E2 | Insert at the top level, inside a group and at the end; separators and the group's own HTML kept | I — `test_insert_at_top_and_inside_a_group` |
| BK-E3 | Insert into an empty group (before its closing tag) | I — `test_insert_into_an_empty_group` |
| BK-E4 | replace, update (attribute validated), move out of a group, duplicate, remove in one call; note for blocks with saved HTML; undo restores the content byte for byte | I — `test_replace_update_move_duplicate_remove` |
| BK-E5 | Refused, nothing saved (not even earlier operations): unknown block, invalid attribute value, unknown attribute, attribute kept in HTML, nesting rule, stale path (expect), missing path, HTML outside blocks, move into itself | I — `test_refusals_save_nothing` |
| BK-E6 | Someone else's page refused for an author; Elementor pages refused | I — `test_permissions_and_builder_pages` |
| BK-E7 | `kuduy-block-schema`: attributes with type and source, dynamic flag, parent rule; unknown type refused. `kuduy-block-validate` finds wrong attribute type, HTML outside blocks, unknown block | I — `test_schema_and_validate` |
| BK-E8 | `kuduy-patterns` lists and returns a registered pattern; `kuduy-block-edit` inserts it | I — `test_patterns_and_insert_a_pattern` |
| BK-E9 | `kuduy-block-usage` counts a block per post, core names without `core/`, no prefix matches | I — `test_usage` |
| BK-E10 | `kuduy-post-duplicate`: draft copy with content, structured custom fields, current author; no edit lock; Elementor pages refused | I — `test_duplicate_a_post` |
| BK-E11 | GenerateBlocks, Kadence Blocks and Spectra Legacy: schema, insert, validate and usage of one of their blocks | I — `test_block_plugins_when_active` (job "+ block plugins") |

## Block plugins' own tools (phase C2)

Run 2026-11-17 with GenerateBlocks 2.5.1, Kadence Blocks 3.9.1, Spectra Legacy 2.20.6 and Spectra Blocks 1.0.15 active (abilities switched on): 254 tests, 0 failures on WordPress 6.9.11 / PHP 8.1.34 and 7.1.4 / PHP 8.3.36, no `BlockPluginsTest` skips; bare suites 254 tests, 0 failures on both pairs; Breakdance, Oxygen and Bricks stand-in jobs green. Playground exited without a report on about one run in three on this machine (no failing test; a rerun of the same job passed each time) — noted under known limits.

| # | Feature | Type |
|---|---|---|
| BP1 | GenerateBlocks: status (version, settings, blocks); settings changed through its route with its sanitizer; unknown key refused; undo; editor refused | I — `BlockPluginsTest::test_generateblocks_status_and_settings` |
| BP2 | Kadence Blocks: status with blocks and settings; a secret shows only that it is set and never appears in the result; setting changed and undone; secret and unknown keys refused | I — `test_kadence_status_and_settings` |
| BP3 | Spectra Legacy: status with blocks and switches; a block switched off and on again by undo; unknown block and contradictory lists refused | I — `test_spectra_status_and_block_switch` |
| BP4 | Spectra Legacy popups: create (draft, fields), list, change content and switch, wrong content refused, undo, trash and undo, unknown popup, editor refused | I — `test_spectra_legacy_popups` |
| BP5 | Spectra Blocks popups: same as BP4 | I — `test_spectra_blocks_popups` |
| BP6 | Spectra Blocks abilities listed and read; delete-popup and update-plugin-setting are not run | I — `test_spectra_blocks_abilities` |
| BP7 | Each plugin's tools are offered only while it is active | I — `test_tools_hidden_without_their_plugin` |

## WooCommerce gaps (phase G1)

Run 2026-11-17, full suite: 312 tests, 0 failures with WooCommerce 11.0.1 on WordPress 6.9.11 / PHP 8.1.34 and 11.2.2 on 7.1.4 / PHP 8.3.36, each with HPOS on and off; bare 7.1 suite 254 tests, 0 failures. Two runs started at the same moment share `build/it` and break each other's report — run them one after another.

| # | Feature | Type |
|---|---|---|
| WG1 | Duplicate (draft, "(Copy)", new SKU); restore from trash and undo; refused when not in the trash; permanent delete only after the confirmation step; editor refused | I — `WooCatalogTest::test_duplicate_restore_and_delete` |
| WG2 | Generate all size variations, no duplicates on a second run, undo deletes them; variation delete and undo recreates it; default attributes set and read back; simple product refused | I — `test_variations_generate_delete_and_defaults` |
| WG3 | Global attribute created, duplicate slug refused, renamed and undone, deleted with confirmation; editor refused | I — `test_global_attributes` |
| WG4 | Shipping class created and used, listed with product count, deleted (product loses it), undo recreates and re-assigns; duplicate name refused | I — `test_shipping_classes` |
| WG5 | Refund: over the remaining amount refused; automatic refund refused for cash on delivery; preview says no money is sent; refund recorded after confirmation; a token from before another refund is refused (CONFLICT) and refunds nothing; editor refused | I — `test_refund_with_confirmation` |
| WG6 | Order addresses and customer note changed, result masked, not in the audit log, undo | I — `test_order_address_update` |
| WG7 | Store settings read with options; a shop manager changes the number of decimals; settings outside the group refused; undo; editor refused | I — `test_store_settings` |

## SEO terms, settings, audits (phase G2)

Run 2026-11-17: `SeoTermsTest` in each SEO plugin's job — Rank Math 1.0.281, Yoast 28.7 (WP 6.9) / 28.8 (WP 7.1), All in One SEO 5.0.5, SEOPress 10.4 — on WordPress 6.9.11 / PHP 8.1.34 and 7.1.4 / PHP 8.3.36: 0 failures; Rank Math full suite on 7.1: 262 tests, 0 failures.

| # | Feature | Type |
|---|---|---|
| SG1 | Term SEO read, written through the plugin (title, description, noindex, Open Graph title), read back, one field cleared while the others stay, both changes undone; unknown term and unknown field refused; author refused (Yoast, Rank Math, SEOPress) | I — `SeoTermsTest::test_term_seo_round_trip` |
| SG2 | All in One SEO free: no term tools offered | I — same test, AIOSEO job |
| SG3 | Settings (title templates) read for Yoast, Rank Math, SEOPress | I — `test_settings` |
| SG4 | Audit: two posts with the same SEO title reported as duplicates; a noindexed post found by the noindex check, the other not | I — `test_audit_noindex_and_duplicates` (all four plugins) |

## Code Snippets, shortcodes, job retry (phase G3)

Run 2026-11-17: `CodeAndJobsTest` with Code Snippets 4.1.0 on WordPress 6.9.11 / PHP 8.1.34 and 7.1.4 / PHP 8.3.36, 0 failures; with code execution allowed (7.1) the PHP snippet test ran (12 assertions); bare full suites 259 tests, 0 failures on both pairs.

| # | Feature | Type |
|---|---|---|
| CS1 | CSS snippet created after the confirmation step (preview: create, css, "does not make code safe"), listed, read with its code, changed and undone, switched off and undone, trashed and undone | I — `CodeAndJobsTest::test_css_snippet_lifecycle` |
| CS2 | HTML/CSS/JS tool refuses PHP scopes and refuses switching on an existing PHP snippet; PHP snippet read with syntax "ok"; PHP tool needs `kuduy:code` and exists only with code execution allowed | I — `test_php_is_guarded` |
| CS3 | With code execution: broken PHP refused when saved active; PHP snippet saved inactive after confirmation, preview lists base64_decode as risky | I — `test_php_snippet_with_code_execution` (job "+ code tools (D4) + Code Snippets") |
| SC1 | Shortcodes listed with source: gallery from core, a test shortcode from the kuduy-mcp plugin folder; search | I — `test_shortcodes` |
| JR1 | Retry refused while queued; after a run with one failed item, retry starts a job with only that item; run by a site manager it succeeds; nothing left to retry afterwards | I — `test_job_retry` |

## Phase Q — builders and themes completion

Run 2026-11-18: full bare suites 262 tests, 0 failures on WordPress 6.9.11 / PHP 8.1.34 and 7.1.4 / PHP 8.3.36; Kirki 6.4.2 and Spectra Legacy 2.20.6 suites on both pairs; real-HTTP smoke 26/26; each suite below on WordPress 7.1.4 / PHP 8.3.36 — `SiteEditorTest` (bare), `BlockPluginsTest` with Spectra Legacy 2.20.6, `KirkiTest` with Kirki 6.4.2 (real plugin), `BuilderAbilitiesTest` / `BricksTest` with the Breakdance, Oxygen and Bricks stand-ins, `ElementorFormsTest` with the Elementor Pro forms stand-in, `WpBakeryTest` and `AvadaTest` with their stand-ins, `BlockBuildersTest` / `BlockEditTest` with the Divi/Etch stand-in: 0 failures.

| # | Feature | Type |
|---|---|---|
| TP1 | Template part created with area (custom, not a theme copy), listed; same slug, unknown area and a slug the theme has refused; template created using the part; editor refused | I — `SiteEditorTest::test_create_template_part_and_template` |
| SP1 | Synced pattern created, used_in counts only exact references, changed (unsynced) and undone, sync toggled, trashed with used_in and undone; refusals (no content, not a pattern, contributor) | I — `test_synced_patterns_create_change_trash_with_undo` |
| SL1 | Spectra Legacy settings listed (no secrets), changed and undone (option removed again), unknown/secret/bad values refused, maintenance needs a published page, coming soon set with its page and undone | I — `BlockPluginsTest::test_spectra_legacy_settings` |
| KK5 | Kirki edit: text, attributes, duplicate (inline span gets a new id), move saved as an unpublished version (published blocks unchanged); publish refused while unpublished changes exist; undo removes the version; publish writes the published blocks; undoing a publish puts them back | I — `KirkiTest::test_kk5_edit_as_unpublished_version_and_undo` (Kirki 6.4.2) |
| KK6 | Kirki edit refusals (root, unknown block, event handler, javascript: URL, text with inline elements, move into itself); nothing saved; author refused | I — `test_kk6_edit_refusals` |
| BA5 | Site-wide ability with confirmation: preview changes nothing, confirmed run changes the builder option, token used once, undo removes the new options, keys neither saved nor restored; report-bug, page-level and read abilities refused; editor refused | I — `BuilderAbilitiesTest::test_ba5_site_wide_writes_with_confirmation_and_undo` (Breakdance and Oxygen stand-ins) |
| BR5 | Bricks global settings through the site tool with confirmation and undo; execute-php, delete-post and page-level abilities refused | I — `BricksTest::test_br5_site_wide_settings_with_confirmation_and_undo` |
| EF1 | Elementor Pro form widgets listed from page data (fields, options, required, actions, submission count); editors see forms without counts | I — `ElementorFormsTest::test_ef1_forms_listed_from_page_data` (stand-in) |
| EF2 | Submissions listed by form with personal data masked (email and name fields, IP), full with include_pii, action log, search; editor refused | I — `test_ef2_submissions_masked_and_read` |
| EF3 | Submission marked read and trashed, both undone; nothing to change and unknown id refused | I — `test_ef3_mark_read_trash_and_undo` |
| WB13 | WPBakery template saved from shortcodes and from a page, listed and searched, renamed and undone, deleted and undone; broken or empty layouts refused; subscriber refused | I — `WpBakeryTest::test_templates_save_read_delete_with_undo` (stand-in) |
| AO1 | Avada Global Options changed (sub-values merged, other settings kept), caches reset, undo; secrets, unknown names, wrong kinds refused; author refused | I — `AvadaTest::test_global_options_update_with_undo` (stand-in) |
| BK4 | Divi 5 text module updated with merge (other breakpoints kept), undo; Divi 4 page refused by kuduy-block-edit and kuduy-post-update | I — `BlockBuildersTest::test_bk4_divi5_block_edit_with_merge_and_divi4_refused` (stand-in) |

Live check 2026-11-18 on the owner's test site (WordPress 7.x on Hostinger, current `main` uploaded as 1.3.8 over 1.3.7; temporary OAuth connection approved by the owner, both tokens revoked afterwards and checked refused; every test page, template, pattern and submission deleted; plugins and theme put back as found). Each product was switched on alone for its check:

| # | Product (real) | Result |
|---|---|---|
| LQ1 | Elementor Pro 4.0.4 (Elementor 4.4.1) | **pass**: a form widget made with `kuduy-elementor-create`, one entry sent through Pro's own AJAX handler as a visitor; `kuduy-elementor-forms` lists it with fields, actions and count 1; the entry is read with name, email, IP and user agent masked; marked read, trashed and restored with undo; Pro's own `elementor/v1/form-submissions` shows the same entry |
| LQ2 | WPBakery 9.1 | **pass**: template saved, read, renamed, broken layout refused, deleted and restored with undo; the template shows in WPBakery's My Templates panel in the editor; 75 shipped templates named |
| LQ3 | Divi 5.19.0 | **pass**: a `divi/*` block page is listed by `kuduy-divi-pages`, rendered by Divi on the front end; `kuduy-block-edit` update with merge keeps the module's admin label, duplicate works, the front end shows both changed modules; undo restores. The Visual Builder did not start in the narrow browser pane (not checked there) |
| LQ4 | Avada 7.16 / Fusion Builder 3.16.4 | **pass**: `site_width` and the h1 font size changed (other h1 sub-values kept); Avada's generated `fusion-styles` CSS carries `--site_width:1180px` and `--h1_typography-font-size:60px`; undo puts 1200px / 64px back in the options and the CSS; a key setting refused |
| LQ5 | Kirki 6.4.2 | **pass** on a page built in Kirki's editor (a pre-built hero section): text, attribute and duplicate saved as an unpublished version and shown in Kirki's editor; publishing refused while Kirki's own unpublished changes exist; undo. **Found:** `kuduy-kirki-edit` was missing from `tools/list` (fixed, TL1) |
| LQ6 | Breakdance 3.0.0-beta.4 | **pass**: `insert-css-variables` through `kuduy-breakdance-site-ability-run` (preview with 14 options saved, then the token; a reused token refused); Breakdance's `get-css-variables` shows the variable; undo removes it |
| LQ7 | Oxygen 6.2.0 | **pass**: the same with `oxygen/insert-css-variables` (17 options saved) |
| LQ8 | Bricks 2.4.5 | **pass**: `set-global-variables` with Bricks' ownership guards; listed by `list-global-variables`; undo removes the variable and puts Bricks' version counter back to 0. **Found:** the site tool offered abilities with effects outside Bricks' options (fixed, BR6) |
| LQ9 | WordPress core, Ollie theme | **pass**: template part (area footer) and template created and listed, duplicate slug and unknown area refused; synced pattern created, used by a page, changed (front end shows it), undone, unsynced and synced again, trashed with `used_in`. **Found:** creating with `sync: "synced"` refused by core (fixed, SP2) |
| — | Spectra Legacy | not installed on the site; covered by SL1 against the real plugin in Playground |

Also seen: a browser request that sends both the WordPress login cookie and a Bearer token is answered "Authentication required" (core's REST cookie check without a nonce runs after the token); MCP clients send no cookies, so this only affects tests run from a logged-in page (`credentials: 'omit'` avoids it).

| # | Feature | Type |
|---|---|---|
| TL1 | Every tool available on the site passes its own capability check without arguments for an administrator, so `tools/list` shows it | I — `ToolListingTest::test_f9_every_available_tool_is_listed_for_an_administrator` (failed for `kuduy-kirki-edit` with Kirki 6.4.2 before the fix) |
| BR6 | Bricks site-level abilities that change roles, builder capabilities, menus, files or indexes are not run by the site tool and are listed as "not run here" | I — `BricksTest::test_br2_…`, `test_br5_…` (Bricks stand-in; names from Bricks 2.4.5) |
| SP2 | Synced pattern created with `sync: "synced"`; synced again with nothing to remove | I — `SiteEditorTest::test_synced_patterns_create_change_trash_with_undo` |

Run after the fixes (2026-11-18): full suites 263 tests, 0 failures on WordPress 7.1.4 / PHP 8.3.36 and 6.9.11 / PHP 8.1.34; with all builder stand-ins active (Bricks, Divi/Etch, Breakdance, Oxygen, Avada, WPBakery, Elementor Pro forms) 298 tests, 0 failures (TL1 covers every listed builder tool); Kirki 6.4.2 suite with TL1 on 6.9 / 8.1 and 7.1 / 8.3: 0 failures; real-HTTP smoke 26/26; PHPCS and PHPStan clean.

## Phase B2 — form editing

Run 2026-11-18 on WordPress 7.1.4 / PHP 8.3.36 with the real plugins (wordpress.org ZIPs installed with `KUDUY_LOCAL_PLUGINS` after two downloads broke off): WPForms Lite 2.0.4 (2 tests); Contact Form 7 6.2.4.1, Fluent Forms 6.2.17, Ninja Forms 3.16.1 and Formidable 7.0 (7 tests): 0 failures. First runs found: Formidable needs its tables installed in the test setup; Ninja Forms keeps one model factory per form for the whole request, so the editor reads through a new factory and refreshes the shared one before Ninja's own functions run (they also load only when the ability registry starts); Ninja's option items take label, value and calc only; CF7's configuration check does not flag mail tags that no longer exist (test corrected).

| # | Feature | Type |
|---|---|---|
| FB1 | WPForms Lite: types from WPForms' registry (Pro types absent); form created with name, email and select; one edit adds two fields (one by position), changes, moves and removes fields and sets title, submit text and confirmation; WPForms renders the new field; undo; Pro type, wrong property, missing field and a field removed earlier in the same call refused with nothing changed; author refused; notification recipient confirm-first, subject not, undo; Bcc and a second notification refused on Lite; editor refused | I — `FormEditWpformsTest` |
| FB2 | Fluent Forms: types from Fluent's elements; form created through FormCreator with unique storage names; edit, Fluent renders it, undo; removal refused while an entry holds a value; Fluent's own duplicate-name check; unknown type and property refused; author refused; notification created after confirmation, Bcc changed and undone, empty subject refused by Fluent's validator | I — `FormEditFluentTest` |
| FB3 | Ninja Forms: types from Ninja's registry; form created by Ninja's create-form (submit button, success message, admin email); edit with options, move, remove, title, submit text, success message; undo (a removed field comes back with its key); removal refused while a submission holds a value; textbox with options refused; editor refused; email action routing confirm-first, Cc, undo; a second email action created | I — `FormEditNinjaTest` |
| FB4 | Formidable: types (aliases left out); form created and edited through Formidable's ability controllers; Formidable renders the new field; undo; removal refused while an entry holds a value (Formidable would delete it); raw field_options refused; author refused; email action created after confirmation with the sender split into name and address, switched off and undone | I — `FormEditFormidableTest` |
| FB5 | Contact Form 7: tag types; tags of the default template with labels; add (with label, before submit), update options and values, move with its label, remove; CF7 renders the new tag; undo restores the markup byte for byte; taken name, unknown type, bad name, quote in a value, rename and move without a place refused with nothing changed; validate on unsaved markup reports CF7's warning and saves nothing; author refused | I — `Cf7TagsTest` |
| FB6 | Permissions: each plugin's own check (WPForms `edit_form_single`, Fluent `fluentform_forms_manager`, Ninja `manage_options`, Formidable's ability permission, CF7 meta caps); notifications need the admin scope and manage_options | I — in FB1–FB5 |

Full round after B2 (2026-11-18): bare suites 263 tests, 0 failures on WordPress 7.1.4 / PHP 8.3.36 and 6.9.11 / PHP 8.1.34; with CF7 (6.2.4.1 on 7.1, 6.1.7 on 6.9), Flamingo 2.6.4, Fluent 6.2.17 and WPForms Lite 2.0.4: 286 tests, 0 failures on both pairs; with Ninja 3.16.1 and Formidable 7.0: 273 tests, 0 failures on both pairs; all builder stand-ins: 298 tests, 0 failures; real-HTTP smoke 26/26; PHPCS and PHPStan clean.

## CI repair (2026-11-18)

GitHub CI had been red since phase C1 (265933f) in three jobs; found from the job names (logs need a login) and reproduced locally:

- **PHPCS + PHPStan:** 17 PHPCS warnings (CI fails on warnings; local checks had used `-n`). All fixed; `phpcs -q` now reports nothing.
- **WP 7.1 + Rank Math + Yoast (conflict):** `SeoTerms::is_available()` threw when two SEO plugins were active, so `tools/list` failed (10 errors, 4 failures). Fixed; `SeoConflictTest` now checks that no tool's availability check throws. `RankMathExtrasTest` skips when several SEO plugins are active. Run: 263 tests, 0 failures (Rank Math 1.0.281 + Yoast 28.8, WP 7.1.4 / PHP 8.3.36); Rank Math alone: 7 of 7 pass.
- **WP 7.1 + add-ons B:** `CodeAndJobsTest::test_shortcodes` expected only core's `[gallery]` for the search "gallery"; Qi Addons registers its own. Test corrected. Run: 263 tests, 0 failures (Happy 3.51.3, HT Mega 3.2.8, PowerPack Lite 3.1.4, Qi 1.11.2).

## SEO + Image SEO

| # | Feature | Type |
|---|---|---|
| S1 | Resolver picks the single active plugin; two active → refuses writes | I — **pass 2026-11-06** (`SeoConflictTest` with Rank Math 1.0.281 + Yoast 28.8 on WP 7.1.4: status shows the conflict, update refused naming both, post meta unchanged; without an SEO plugin only `kuduy-seo-status` is available) |
| S2 | Rank Math: title, description, focus + extra keywords, canonical, robots, OG/Twitter, schema, redirects | I — **pass 2026-11-06** for title, description, keywords, canonical, robots, OG/X, cornerstone (`SeoRankMathTest`, Rank Math 1.0.281 on WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34: stored as its editor stores them, read back through its own ability, null clears, undo, authors refused for robots without `rank_math_onpage_advanced`, others' posts refused, bad canonical refused). Schema, redirects: not built |
| S3 | Yoast: title, metadesc, focus keyphrase, canonical, robots, social, schema | I — **pass 2026-11-06** except schema (`SeoYoastTest`, Yoast 28.8 on WP 7.1.4, 28.7 on WP 6.9.11: stored in `_yoast_wpseo_*` through `WPSEO_Meta`, rendered values current right after the write, noindex true/false/null, advanced robots, null clears, undo, `wpseo_edit_advanced_metadata` enforced for authors, related keyphrases refused) |
| S4 | Output appears in rendered `<head>` | I — **pass 2026-11-08** for all four plugins: in-process head (`RendersHead`) has title, description, robots, og:title, and `kuduy-seo-verify` reads the same head as the live page with no mismatches (`SeoVerifyTest` + each plugin test). E (live page on staging): **pass 2026-11-08** (claude.ai web → Hostinger staging, KUDUY 0.5.0, WP 7.1.4/PHP 8.3.36, Yoast 28.8, post 407): `kuduy-seo-verify` `live_checked: true`, `http_status: 200`, no mismatches; live head had the title, description and og:title just written (site fetched itself; no stale cache seen). Open: live `robots` was `noindex, nofollow` while `kuduy-seo-get` rendered `noindex, follow` and stored noindex was null. Likely cause: the site still had "Discourage search engines" on (core `wp_robots` adds `nofollow`); the user has since switched indexing on. Re-check 2026-11-08 (from outside, no KUDUY write): `kuduy-settings-get` shows `blog_public: "1"`; the live home page and `/shop/` now serve `index, follow, max-image-preview:large, …`. This supports that cause, but the post check was not repeated (this session's connector had no SEO tools). `kuduy-seo-get`'s rendered robots comes from the SEO plugin and leaves out core's `wp_robots` additions, so `kuduy-seo-status` and `kuduy-seo-verify` now add a note while search engines are discouraged (`SeoVerifyTest`, verified against core `wp_robots_noindex()` / `wp_robots_no_robots()` in WP 7.1.4) |
| S5 | Media list, dimensions, metadata; alt/title/caption/description updates | I — **pass 2026-11-06** (`MediaTest`: list filters by type and missing alt; 1600 × 900 PNG returned as an MCP image block ≤ 1024 px with matching MIME; alt/caption update keeps the title; undo; alt null removes; kses strips `<script>` for authors; others' media and contributors refused) |
| S6 | Decorative flag → empty alt; no invented alt text | I — decorative → empty alt and not "missing", decorative + alt refused: **pass 2026-11-06**. M (Claude looks at the image before writing alt text): **pass 2026-11-08** on staging via claude.ai web: `kuduy-media-get` with `include_image: true` on ids 384 (logo) and 383 (favicon) showed Claude the actual image, not just a link; the alt text was written from what it showed ("YNNP. logo: bold black letters next to a red broadcast-signal icon", "Red broadcast-signal icon: a dot with curved waves on both sides") and then restored |
| S7 | Image usage scan (content, Elementor, featured, Woo gallery) | I — **pass 2026-11-08** for featured image, block content, Elementor data; authors do not see uses in posts they cannot read (`MediaLibraryTest`). Woo gallery, site icon/logo: code path not exercised |
| S8 | Replace image keeps original, updates references; thumbnail regen job | I — regenerate restores a missing size: **pass 2026-11-08**; upload from URL (mocked download) with alt/title/parent, plain HTTP, non-image and contributors refused: **pass**. Replace image: not built |
| S9 | Bulk operations run as jobs with progress | I — **pass 2026-11-08** (`JobsTest`: every item validated before start, only listed tools, queued on WP-Cron or Action Scheduler (Rank Math/AIOSEO), batch runs each item as the owner — the item on someone else's image fails, others succeed with their own undo snapshot; owner/site manager only; cancel; revoked connection and read-only scope stop the job). E (real host): **pass 2026-11-08** on Hostinger staging via claude.ai web: `kuduy-job-start` with 2 × `kuduy-media-update` → job 1 `queued` at 15:25:17Z; WP-Cron ran it unforced at 15:25:57Z; `done` 2, `failed` 0 by 15:26:20Z; item snapshots 4 and 5, both restored. `job-status` results gave only `snapshot_id` per item; since this close-out they also give `index` and `object_id` (`JobsTest`) |
| S10 | AIOSEO and SEOPress adapters | I — **pass 2026-11-08** (`SeoAioseoTest`: saved in AIOSEO's own table, explicit index vs default, undo; `SeoSeopressTest`: stored in its meta keys through its abilities, target keywords, unsupported cornerstone refused, undo, ownership) |
| S11 | `kuduy-seo-audit` lists posts missing SEO data | I — **pass 2026-11-08** with each SEO plugin (`SeoAuditTest`) |

## WooCommerce

Ids from `docs/plans/phase-7-woocommerce.md`. Every order test runs with HPOS on and off (`KUDUY_HPOS`).

| # | Feature | Type |
|---|---|---|
| W1 | Core post tools refuse WooCommerce types for writes; reads and plain posts unchanged; the run's HPOS mode is the one asked for | I — **pass 2026-11-08** (`WooGuardsTest`, 6 tests: `kuduy-post-update` / `-create` (product, coupon) / `-trash` / `-meta-set` refused naming the `kuduy-woo-*` tools, product unchanged; `kuduy-post-get` on a product and updates of plain posts still work) on WP 7.1.4 + WooCommerce 11.2.2 with HPOS on and off; 6.9 pair: see §3 |
| W2 | Secrets never in results, errors or audit rows (gateway credentials, API keys, webhook secrets, payment tokens, order keys; fields of type `password` whatever their name) | I — Redactor part **pass 2026-11-08** (`SafetyTest::test_redactor_masks_woocommerce_secrets`); end-to-end **pass 2026-11-09**: gateway secrets (W13) and order keys (W6) never in results or audit rows. No tool reads REST API keys, webhooks or payment tokens |
| W3 | Product CRUD round trip through `wc_get_product()`, lookup table updated | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooProductsTest`: create as draft, read back through `wc_get_product()`, SKU and price in `wc_product_meta_lookup`; duplicate SKU refused with nothing created; update patches only sent fields, undo restores name, sale price, short description and status; list by SKU, get, non-product refused; trash + undo restores status and variations; editor and author refused; create and publish need `publish_products`) |
| W4 | Variations + parent sync | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooProductsTest`: custom attribute with variation flag, two variations, parent price range synced after create and update, undo, unknown option refused) |
| W5 | Stock set/increase/decrease + undo | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooProductsTest`: refused while stock is not managed; set 10, decrease 3 → 7; undo → 10) |
| W6 | Order read and PII masking, HPOS on and off | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai, HPOS on). I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooOrdersTest`: list and get mask email, address, phone and IP; order key never in any result; `include_pii` gives full billing data and IP to a shop manager and is refused to a role that can only read orders) |
| W7 | Order status confirm-first, emails captured, undo | I, E — E (confirm-first report, nothing changed): **pass 2026-11-09** on Hostinger staging. I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooOrdersTest`: processing → completed reports the customer email and changes nothing; with `confirm` one email reaches the customer (`pre_wp_mail`); undo restores processing with no customer email; processing → on-hold runs at once; `refunded` refused with no refund created; private note sends nothing, customer note confirm-first then one email) |
| W8 | Per-object order capabilities (shop manager yes, editor and author no), HPOS on and off | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooOrdersTest`: editor and author refused list, get and status; order unchanged) |
| W9 | Coupons: unique code, undo | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooCouponsTest`: create reads back through `WC_Coupon`; same code in another case refused; percent over 100 refused; update + undo incl. expiry and minimum spend; list, get by code, trash + undo; editor and author refused) |
| W10 | Customer address update; shop manager limited to customers | I, E — E (read and masking): **pass 2026-11-09** on Hostinger staging. I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooCustomersTest`: list and get masked, full with `include_pii`; billing update + undo, untouched fields kept, new address absent from audit rows; shop manager refused on an editor; author refused; invalid billing email refused) |
| W11 | Reviews moderation + rating cache | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooReviewsTest`: list with rating and masked email; approve → review count 1, average 4; undo → pending, count 0; reply by the current user under the review; plain comment refused; editor without `edit_products` refused) |
| W12 | Shipping and tax read | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooStoreSettingsTest`: zone with region country:DE and a flat-rate method with its cost, rest-of-world zone last; DE 19 % rate in the standard class, shipping flag; editor refused) |
| W13 | Gateway settings masked by field type | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooStoreSettingsTest`: a fake extension gateway with a `password` field under a free name and a text field named `api_signature` → both `value: null`, `has_value: true`; neither secret in the result or the audit rows; title and test mode visible; editor refused) |
| W14 | Report numbers match the fixture orders | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooReportsTest`: after WooCommerce's own order import, revenue for today counts both orders and at least 100 gross; products report lists the item with 4 sold; customer and order reports show `r***@example.test` and no city; author refused) |

## ACF

Ids from `docs/plans/phase-7-acf.md`. Field groups registered in the test with `acf_add_local_field_group()`.

| # | Feature | Type |
|---|---|---|
| A1 | Status and field groups by location | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: version, abilities off, no Google Maps key; groups by post type and by post) |
| A2 | Values read on posts, users, terms; password never returned | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: raw and formatted values; password field `value: null`, `has_value: true`, secret absent from the result) |
| A3 | Update + undo on posts, users, terms | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: text, number, select, true/false written with ACF's reference key; undo restores the old value and removes values that did not exist; user and term fields by name and by key) |
| A4 | Refusals write nothing | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: field of another location, unknown name (no dummy meta), required empty, select option not in choices, number below minimum, invalid email, one bad value of two; author on an editor's post and on another user) |
| A5 | Raw meta writes to ACF fields refused | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: `kuduy-post-meta-set` names `kuduy-acf-update`; non-ACF meta still works. ACF 6.8.11 itself also denies `edit_post_meta` on its field keys) |
| A6 | Bulk update as a job; password kept out of audit rows | I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`) |

## Forms

Ids from `docs/plans/phase-7-forms.md`. Runs: `contact-form-7@6.2.4.1` (7.1) / `6.1.7` (6.9), `flamingo@2.6.4`, `fluentform@6.2.17`, `wpforms-lite@2.0.4`.

| # | Feature | Type |
|---|---|---|
| F1 | Status and list across plugins; unreadable forms left out | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (`FormsCf7Test`, `FormsFluentTest`, `FormsWpformsTest`) |
| F2 | Form details (CF7 tags, Fluent fields in containers, WPForms fields/notifications); no integration keys | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 |
| F3 | Entries masked by field kind; full data with `include_pii` + permission; password fields never shown | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (Flamingo, Fluent) |
| F4 | Read / starred / spam / trash with undo; Flamingo → Akismet confirm-first, undo sends nothing | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (Akismet stand-in counts calls) |
| F5 | Notes with undo; note text not in audit rows | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (Fluent) |
| F6 | CF7 save + undo; markup filtered without `unfiltered_html`; mail routing confirm-first, admin scope, addresses not in audit rows | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 |
| F7 | Refusals (editor/author/subscriber without the plugin's permission, unknown plugin/form/entry, no permanent delete) | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11. Fluent per-form manager scope: not tested (Fluent's own ACL) |
| F8 | Ninja Forms 3.16.1 (real plugin): status, list, form; submissions masked, passwords never shown; trash and restore with undo; no read/starred/spam/notes (refused) | I — **pass 2026-11-14** on WP 7.1 and 6.9 (`FormsNinjaTest`; run `ninja-forms@3.16.1,formidable@7.0`) |
| F9 | Formidable Forms 7.0 (real plugin): status, list, form; entries masked, passwords never shown; entries read only (changes refused) | I — **pass 2026-11-14** on WP 7.1 and 6.9 (`FormsFormidableTest`; same run) |
| F10 | Gravity Forms: status, list, form; entries masked; read/starred/spam/trash and notes with undo | I — **pass 2026-11-14** on WP 7.1 and 6.9 against a **stand-in** (`FormsGravityTest`, `KUDUY_FAKES=gravity`). Not verified against Gravity Forms itself |
| F11 | WPForms Pro entries: masked; read/starred/spam/trash and notes with undo | I — **pass 2026-11-14** on WP 7.1 and 6.9 against a **stand-in** on WPForms Lite 2.0.4 (`FormsWpformsProTest`, `KUDUY_FAKES=wpforms-pro`). Not verified against WPForms Pro itself |

## Site Editor, Global Styles, widgets

| # | Feature | Type |
|---|---|---|
| S1 | Block types; a post's block tree | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (`SiteEditorTest`) |
| S2 | Template update (theme copy), undo removes the copy; custom copy undo; revert + undo | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (Twenty Twenty-Five) |
| S3 | Global Styles patch + undo; custom CSS refused without `edit_css` (core's route does not check it) | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 |
| S4 | Block widget add / change / move to inactive, each undone | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (test simulates a fresh request for the widget list: core's `$sidebars_widgets` global is per request) |
| S5 | Editor refused (`edit_theme_options`); unknown template | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 |

## Cache

| # | Feature | Type |
|---|---|---|
| C1 | Status; object-cache flush | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (`CacheTest`) |
| C2 | Author: no whole-site purge or object flush, own post allowed; URL outside the site refused; no plugin → clear message | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 |
| C3 | LiteSpeed Cache 7.9.3 purges through its own actions (all, post, URL) | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11. W3TC, WP Super Cache, WP Fastest Cache: API read only; WP Rocket: docs only (UNVERIFIED) |

## Multilingual (Polylang 3.9.2)

Correction 2026-11-10: in the first Phase 7 round `MultilingualTest` did not run at all (Polylang loads its API only once a context exists; the class skipped in `setUpBeforeClass()`, which leaves no test case in the report). The runner now adds the languages first, and the test fails instead of skipping when Polylang is active without its API. The results below are from the corrected runs.

| # | Feature | Type |
|---|---|---|
| L1 | Languages and translated types | I — **pass 2026-11-10 (corrected run)** on WP 7.1.4 and 6.9.11 (`MultilingualTest`) |
| L2 | Set language + undo (also back to no language); unknown language refused | I — **pass 2026-11-10 (corrected run)** on WP 7.1.4 and 6.9.11 |
| L3 | Link + get + undo; mismatched languages, duplicate post, another user's post refused | I — **pass 2026-11-10 (corrected run)** on WP 7.1.4 and 6.9.11 |
| L4 | Translation draft created and linked; duplicate language refused | I — **pass 2026-11-10 (corrected run)** on WP 7.1.4 and 6.9.11. WPML: not supported (needs a licensed copy) |

## Developer mode and content types

| # | Feature | Type |
|---|---|---|
| D1 | Files: list/read inside the roots; `wp-config.php`, `.env`, `../`, absolute paths, binary files refused; credentials masked | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (`DeveloperTest`) |
| D2 | Search skips secret files | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 |
| D3 | DB schema; queries mask `user_pass`, activation keys, secret options and serialized secrets; writes, two statements, `INTO OUTFILE`, `SLEEP`, comments refused; 200-row cap | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 |
| D4 | Code tools hidden without the constant; with it (`KUDUY_CODE_EXEC=1`): sandbox write + undo, parse check, KUDUY / mu-plugins / uploads / loose / secret / traversal / binary refused, active plugin confirm-first, PHP result/output/exception captured, editor refused | I — **pass 2026-11-10** on WP 7.1.4 (code run) and both pairs (constant off) |
| T1–T3 | Content types: define post type + taxonomy, use with `kuduy-post-create`, remove keeps content, undo; reserved/core/long keys and editors refused | I — **pass 2026-11-10** on WP 7.1.4 and 6.9.11 (`ContentTypesTest`) |

## Hidden mode, updater, lifecycle

| # | Feature | Type |
|---|---|---|
| H1 | Hide UI removes menu, pages, widgets, notices, admin-bar node, row links | I, M |
| H2 | Plugin row still visible in Plugins list | I |
| H3 | Tool list, OAuth, jobs, audit identical with UI hidden | I |
| H4 | `wp kuduy ui show` and `KUDUY_MCP_SHOW_UI` restore UI | I |
| G1 | Updater detects newer GitHub release, shows in Updates | I — **pass 2026-11-05** (`UpdaterTest` through core `wp_update_plugins()`, also when wordpress.org is unreachable) |
| G2 | Checksum mismatch aborts install | I — **pass 2026-11-05** |
| G3 | Update from release ZIP keeps data and settings | I, E — release ZIP install + activation **pass 2026-11-05** (`npm run check:release`, WP 6.9/PHP 8.1 and WP 7.1/PHP 8.1); real update v0.1.0 → v0.1.1 from GitHub Releases **pass 2026-11-05** (`npm run check:update`: core offered it, checksum verified, `Plugin_Upgrader` installed, settings + rows kept). Note: after this programmatic upgrade core left the plugin inactive (reactivated by the script); Plugins-screen and auto-update paths still to observe. **Real host 2026-11-05:** Hostinger staging updated 0.1.2 → 0.2.0 through WordPress's updater minutes after the release; plugin stayed active and the Claude connection kept working. |
| G4 | GitHub API failure / rate limit → no error spam, cached result | I — **pass 2026-11-05** (12 h cache, ETag/304, 1 h retry after failure) |
| G5 | "Check again" on Dashboard → Updates re-reads GitHub; opening the screen alone uses the cache | I — **pass 2026-11-05** |
