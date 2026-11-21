# KuDiagnostics — developer guide

How the plugin is built, how to add a tool or a whole integration adapter, and how to test. Design decisions and their reasons: `ARCHITECTURE.md`; threat model: `SECURITY.md`; every tool: `docs/TOOLS.md` and every integration: `docs/INTEGRATIONS.md` (both generated).

## Layout

| Path | What |
|---|---|
| `kuduy-mcp.php` | Plugin header (`Version`, `Update URI`), autoload, activation/deactivation hooks, `Plugin::boot` on `plugins_loaded` |
| `src/Mcp/` | JSON-RPC endpoint (`RestController`, `Server`), `ToolRegistry`, `Executor`, `Tool` interface, `ToolError` |
| `src/OAuth/` | Authorization server: metadata, client registration and CIMD, PKCE codes, token rotation, revocation, consent screen, scopes |
| `src/Audit/` | `AuditLog`, `Redactor` (secrets out of results and audit rows), `MasksAuditArgs` |
| `src/Execution/` | `Snapshots`, `Snapshottable` (undo), `Confirmable` and `Confirmation` (confirm-first), `Diff` (snapshot comparison) |
| `src/Policy/` | Rate limit, read-only mode (D80) |
| `src/Jobs/` | Background jobs (`kuduy_jobs`, Action Scheduler or WP-Cron) |
| `src/Modules/<Area>/` | Tools per area: Core, Elementor, Seo, Media, Woo, Acf, Forms, Blocks, Cache, Multilingual, Dev, Types |
| `src/Admin/` | Tools → KuDiagnostics page (Connections, Activity and Integrations tabs), hidden mode, WP-CLI command |
| `src/Lifecycle/`, `src/Update/` | Migrations, cleanup, uninstall; GitHub updater |
| `tests/integration/` | PHPUnit tests that run inside WordPress Playground |
| `scripts/` | Test runner, smoke test, release build and checks, tool reference |

Rules: PHP 8.1+, namespace `Kuduy\Mcp`, one class per file; modules depend on `Mcp`, `Audit`, `Execution` and `Modules\Core\Content`, not on each other.

## How a call runs

`Server` checks the OAuth scope (403 `insufficient_scope`) and the rate limit (429), then `Executor::call()`:

1. `is_available()` — e.g. "WooCommerce is not active" (unavailable tools are not listed).
2. Read-only mode (D80): tools without `readOnlyHint: true` are refused while it is on.
3. Input validated and sanitized against `input_schema()`.
4. `current_user_can( capability( $input ) )` — the WordPress user's own rights, per object.
5. If the tool is `Confirmable`, the first call returns a preview and a token (see below).
6. If the tool is `Snapshottable` **and** its `destructiveHint` is true: `snapshot()` stores what undo needs.
7. `execute()`; a `ToolError` becomes an `isError` result with its message.
8. `Redactor` removes secrets from the result; an audit row is written (arguments through `MasksAuditArgs` when the tool implements it).

## Adding a tool

Implement `Kuduy\Mcp\Mcp\Tool` (and `Snapshottable` when it changes data) and register it:

```php
add_action( 'kuduy_mcp_register_tools', function ( Kuduy\Mcp\Mcp\ToolRegistry $registry ) {
	$registry->register( new My\Plugin\HelloTool() );
} );
```

```php
final class HelloTool implements Kuduy\Mcp\Mcp\Tool {
	public function name(): string { return 'myplugin-hello'; }          // [a-z0-9-], ≤ 64 chars
	public function title(): string { return 'Say hello'; }
	public function description(): string { return 'Returns a greeting for the signed-in user.'; }
	public function input_schema(): array { return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ); }
	public function output_schema(): array { return array( 'type' => 'object', 'properties' => array( 'text' => array( 'type' => 'string' ) ), 'required' => array( 'text' ) ); }
	public function annotations(): array { return array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ); }
	public function scope(): string { return 'kuduy:read'; }              // read | write | admin | code
	public function capability( array $input ): string|array { return 'read'; } // or array( 'edit_post', $id )
	public function is_available(): bool|string { return true; }          // or a reason string
	public function execute( array $input ): array { return array( 'text' => 'Hello ' . wp_get_current_user()->display_name ); }
}
```

Guidelines from the brief, enforced by review: use the integration's own API (never guessed meta keys); check the per-object capability; never return secrets; snapshot before destructive changes; say "Nothing was changed." in refusals; descriptions tell Claude what happens and how to undo.

## Filters, actions, constants

| Hook / constant | Purpose |
|---|---|
| `kuduy_mcp_register_tools` (action) | Register extra tools |
| `kuduy_mcp_rate_limit` (filter, 120) | Calls per minute per user and app |
| `kuduy_mcp_audit_retention_days` (90), `kuduy_mcp_snapshot_retention_days` (30) | Retention |
| `kuduy_mcp_allowed_origins` | Browser origins allowed to call the endpoint |
| `kuduy_mcp_require_https` | HTTPS requirement (off for local sites) |
| `kuduy_mcp_registration_limit` (1000) | Unused dynamically registered clients kept |
| `kuduy_mcp_update_channel`, `KUDUY_MCP_UPDATE_CHANNEL` | `stable` or `beta` updates |
| `KUDUY_MCP_ALLOW_CODE_EXEC`, `KUDUY_MCP_SHOW_UI`, `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL`, `KUDUY_MCP_LIST_UNAVAILABLE` | See `docs/INSTALL.md` |

## Development setup and tests

PHP 8.3 and Composer for static checks, Node for WordPress Playground (`npm install`, `composer install`).

| Purpose | Command |
|---|---|
| Code style | `php vendor/bin/phpcs -q` (fix: `php vendor/bin/phpcbf -q <files>`) |
| Static analysis | `php vendor/bin/phpstan analyse --memory-limit=1G` (level 6) |
| Integration tests | `KUDUY_WP=7.1 KUDUY_PHP=8.3 node scripts/test-integration.mjs` (also `KUDUY_WP=6.9 KUDUY_PHP=8.1`) |
| With plugins | `KUDUY_PLUGINS=woocommerce@11.2.2 KUDUY_HPOS=off …`, `KUDUY_PLUGINS=polylang@3.9.2 …`, `KUDUY_LOCAL_PLUGINS=elementor-pro.zip …` |
| Code tools on | `KUDUY_CODE_EXEC=1 …` |
| One test class | `KUDUY_FILTER=SiteEditorTest …` |
| Real HTTP + OAuth smoke | `node scripts/smoke-mcp.mjs` |
| Tool and integration reference | `npm run docs:tools` (writes `docs/TOOLS.md` and `docs/INTEGRATIONS.md`) |
| Release ZIP | `php scripts/build-release.php --expect-version=X.Y.Z`, then `npm run check:release` |

Tests are PHPUnit classes in `tests/integration/` that call tools through the real MCP endpoint (`CallsTools::ok()` / `fails()`), validate results against the tool's output schema, and check WordPress state. A class that needs a plugin skips when it is not active; CI (`.github/workflows/ci.yml`) runs every plugin combination.

## Error categories and confirmation (phase M)

- **Error categories.** Throw `ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No such item.' )` (or `new ToolError( $message, $category )`) to tell the client what kind of failure it is; a plain `new ToolError( $message )` is `OPERATION_FAILED`. The executor adds the category as a second text block and in `_meta["kuduy/error"]`; the message stays the first block.
- **High-risk tools.** Implement `Execution\Confirmable`: `preview( $input )` returns what would happen plus a `version` fingerprint of the target and changes nothing. Add `Confirmation::ARG => Confirmation::token_input()` to the input schema and `Confirmation::output_properties()` to the output schema, with no top-level `required` in the output. The executor does the rest: first call → preview + token; second call with the token → `execute()`. See `Modules/Media/MediaDelete`.
- **Dependencies between integrations** go in `IntegrationCatalog::REQUIRES`; `kuduy-integration-get` and `-diagnose` read them.

## Writing an integration adapter (phase P, D86)

An adapter is the set of tools for one product (a page builder, theme, forms plugin, …) plus its row in the catalogue. Most adapters in `src/Modules/` follow the same steps.

1. **Read the product first.** Find the API its own editor or admin screen calls to save (a model class, a REST controller, a CRUD object, the abilities it registers). Write what you found, with versions, in `RESEARCH.md`; mark anything not checked against source or docs as **UNVERIFIED**. Never write guessed meta keys or serialized data (rule 4).
2. **Choose the tools.** Usually one class per product with modes (`new FooTools( 'list' )`, `'get'`, `'edit'`), names `kuduy-<product>-<action>`. Reads take `kuduy:read`; content changes `kuduy:write`; site-wide settings, routing and anything permanent `kuduy:admin`. `capability()` returns the WordPress capability for the object (`array( 'edit_post', $id )`), and `execute()` also runs the product's own permission check when it has one.
3. **Make changes undoable.** A tool that changes something implements `Snapshottable` with `destructiveHint: true`: `snapshot()` returns the state `execute()` is about to change, `restore()` puts it back through the same API. When undo is impossible (a permanent delete, an email sent, a refund), implement `Confirmable` instead so the user confirms a preview first.
4. **Say what is unavailable.** `is_available()` returns `true` or the reason ("Bricks 2.4 or later is not active on this site."); unavailable tools are not listed to Claude.
5. **Mark reads honestly.** `readOnlyHint: true` only when the tool changes nothing: read-only mode (D80) lets those tools run, and `ModesTest` checks that no read tool is destructive or confirm-first.
6. **Add the catalogue row** in `Modules/Core/IntegrationCatalog::ROWS`: id, name, category, kind, plugin or theme folders, support class, tool name prefixes, free or paid, phase, and a note that says exactly what is and is not covered. Support is `full` only after a test against the real product; a stand-in (below) gives `partial`. Dependencies on other products go in `REQUIRES`.
7. **Register** the tools in `ToolRegistry::create()` (or, outside this plugin, on the `kuduy_mcp_register_tools` action).
8. **Test.** A class in `tests/integration/` per product, skipped when the product is not active; add a CI row in `.github/workflows/ci.yml` that installs it (`KUDUY_PLUGINS=slug@version`). A paid product without a copy gets a stand-in in `tests/integration/fakes/<name>.php` built from its source (`KUDUY_FAKES=<name>`), and the catalogue note says so. Cover: the happy path, undo, a refusal for a user without the right, and secrets never returned.
9. **Document.** Update `FEATURES.md`, `TESTING.md` and `SECURITY.md` in the same commit, then run `npm run docs:tools` so `docs/TOOLS.md` and `docs/INTEGRATIONS.md` follow the code.
