# Phase M — tool contract and discovery (2026-11-16)

From `docs/plans/ecosystem-spec-gap.md`, decisions D44, D45, D46 (first tool), D52. Existing tool names, input and output schemas stay unchanged.

## M1. Error codes (D52)

- `ToolError` takes an optional code from the spec's list (`PLUGIN_NOT_INSTALLED`, `PLUGIN_NOT_ACTIVE`, `PLUGIN_VERSION_UNSUPPORTED`, `PLUGIN_DEPENDENCY_MISSING`, `LICENSE_REQUIRED`, `FEATURE_NOT_SUPPORTED`, `PERMISSION_DENIED`, `RESOURCE_NOT_FOUND`, `INVALID_ARGUMENT`, `VALIDATION_FAILED`, `DUPLICATE_RESOURCE`, `CONFLICT`, `RATE_LIMITED`, `OPERATION_FAILED`, `INTERNAL_ERROR`); default `OPERATION_FAILED`. `retryable` follows from the code (`CONFLICT`, `RATE_LIMITED` true).
- Executor maps its own failures: unavailable → `PLUGIN_NOT_ACTIVE` when the reason says the plugin is not active, otherwise `FEATURE_NOT_SUPPORTED`; invalid arguments → `INVALID_ARGUMENT`; capability → `PERMISSION_DENIED`; unexpected exception → `INTERNAL_ERROR`.
- An error result keeps its first text block unchanged (the message) and adds a second text block `{"error":{"code":…,"retryable":…}}` plus `_meta["kuduy/error"]`. No `structuredContent` on errors (clients may check it against the output schema). Audit codes are unchanged.
- Scope and rate-limit refusals stay JSON-RPC errors (protocol level), as today.

## M2. Confirmation tokens (D45)

- `Execution\Confirmable` (tool opts in): `preview( $input )` returns what would change and a `version` fingerprint of the target; it changes nothing.
- `Execution\Confirmation`: issues a random token, stores only its SHA-256 as a transient for 10 minutes with user id, OAuth client, tool name and a hash of the arguments (without the token); `consume()` deletes it and succeeds once.
- Executor, for a `Confirmable` tool, after the capability check:
  - no `confirm_token` → returns `{ confirmation_required: true, preview, confirm_token, expires_in }`; audit row `ok` / `confirmation_required`;
  - with a token → token must exist, be unused, match user, client, tool and arguments, and the fresh preview's `version` must match; otherwise `CONFLICT` (token used up, ask again). Then the normal snapshot + execute; the audit row's arguments carry the token reference (first 12 hex of its hash), never the token.
- Tools include `Confirmation::token_input()` in their input schema and `Confirmation::output_properties()` in their output schema, and have no top-level `required` in the output.

## M3. First token tool: `kuduy-media-delete` (D46)

Permanently deletes a media item and its files (`wp_delete_attachment( $id, true )`). `kuduy:admin`, `delete_post` on the item. Preview: title, file, size, MIME type, every place it is used (the `kuduy-media-usage` scan), and a warning when used. Not undoable — said in the description and the preview.

## M4. Discovery (D44)

- `IntegrationCatalog::REQUIRES`: parent integrations (Elementor Pro and Elementor add-ons → Elementor; Bricksforge → Bricks; Dynamic Content → Elementor).
- `kuduy-integration-get` (`kuduy:read`, `edit_posts`): one catalogue row with installed/active/version, support class, copy (free/paid), note, required parents and their state, and every tool behind it with availability (or the reason it is not available), scope, read-only/destructive, and — with `include_schemas` — the input schema.
- `kuduy-integration-diagnose` (`kuduy:read`, `activate_plugins`): for one integration or every installed one: installed but inactive, parent missing or inactive, tools unavailable with the reason, paid product not verified here, support only detection. Findings with level `error` / `warning` / `info`; nothing is changed.

## Tests (written with the code, run at the end of the phase)

`ContractTest`: error codes for unavailable / invalid / denied / ToolError / exception, second content block, first block unchanged. Token: preview without token changes nothing; token runs once; replay refused; other user refused; other arguments refused; changed object refused; expired token refused. `kuduy-media-delete`: preview lists usage; confirmed call deletes files; editor without `delete_post` on another's item refused; read scope refused. `IntegrationsTest` extended: get for an active and a missing integration, requires, diagnose finds an inactive parent.

## Records

`FEATURES.md`, `TESTING.md` (matrix rows CT1–CT8), `SECURITY.md` (token threat notes), `ARCHITECTURE.md` §6, `docs/DEVELOPER.md` (how a tool opts into confirmation and error codes), regenerated `docs/TOOLS.md`, `CHANGELOG.md`.
