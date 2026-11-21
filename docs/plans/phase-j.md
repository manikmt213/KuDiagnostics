# Phase J — Modes (started 2026-11-21)

From `IMPLEMENTATION-PLAN.md` (row J) and `docs/plans/ecosystem-spec-gap.md` (row 10): a site-wide read-only switch, a comparison of a snapshot with the current state, and an abilities hub (D34). Ghost mode (D33) is not built separately: D45 replaced it with the confirmation tokens built in phase M (preview → token → apply exactly what was previewed, refused when the target changed). Exit gate: every write tool is blocked in read-only mode, tested over all tools. Same workflow as the earlier rounds (build all, then test; decisions take the recommended option, for review).

## Research (2026-11-21)

- **Abilities API** (WordPress 7.1.5 source, `wp-includes/abilities-api/`, same contract since 6.9): `wp_get_abilities()`, `wp_get_ability( $name )`, `WP_Ability::get_meta()` / `get_meta_item()`, `get_input_schema()`, `execute( $input )` = normalize → validate input → `check_permissions()` (the ability's own `permission_callback`, as the current user) → run → validate output; failures are `WP_Error`. Plugins mark abilities for MCP with `meta.mcp.public = true` (WooCommerce 11.2.2 `Internal/Abilities/Domain/*`: `'mcp' => array( 'public' => true, 'type' => 'tool' )`; WPCode, Masteriyo, Breakdance/Oxygen do the same, `RESEARCH.md` §10e, §10q). Annotations sit in `meta.annotations` (`readonly`, `destructive`, `idempotent`; unset ones are null).
- **Existing pieces:** `Snapshottable::snapshot( $input )` returns the state a tool is about to change, in the same shape it stores; called again with the stored input it gives the current state, so a comparison needs no per-tool code. `Executor` is the single path for tools/call, jobs and resources.

## Decisions

| # | Decision | Choice |
|---|---|---|
| D80 | Read-only switch | Option `kuduy_mcp_read_only`, set by a site administrator on the KuDiagnostics screen (nonce, `manage_options`); constant `KUDUY_MCP_READ_ONLY` in wp-config.php wins over the option either way. When on: tools/list shows only tools annotated `readOnlyHint: true`; the executor refuses every other tool (also inside jobs, so queued jobs stop changing things); `kuduy-site-info` and the server instructions say so. It cannot be switched over MCP. Invariant tested over every tool: a read-only tool is never Snapshottable or Confirmable. |
| D81 | Snapshot comparison | `kuduy-snapshot-diff` (`kuduy:read`; the snapshot's owner or a site manager, and the same scope and object capability as the tool that made it, like restore): the stored state against the tool's own `snapshot()` of the stored input now; changed, added and removed paths with before/after values (long values shortened), redacted. |
| D82 | Abilities hub (D34) | `kuduy-abilities` (list / one with its input schema), `kuduy-ability-read`, `kuduy-ability-run`. Only abilities with `meta.mcp.public = true`. Namespaces that have their own KuDiagnostics tools (builders, Astra, Elementor, WooCommerce, SEO plugins, WPCode, WP Rocket, Meta Box, Masteriyo, and — after the live check of 2026-11-21 — the forms plugins) are listed as covered and not run here, so their snapshots and refusals stay in force. Abilities whose names suggest running code (php, eval, sql, shell, exec) or sending reports out are never run. Read = only abilities annotated `readonly: true`, `kuduy:read`. Run = every other public ability, `kuduy:admin`, always confirm-first (D45 token: no undo is possible for an unknown ability); each ability runs through `WP_Ability::execute()`, so its own permission callback decides. |

## Tools

| Tool | Access | Through |
|---|---|---|
| `kuduy-snapshot-diff` | `kuduy:read` + owner or `manage_options` + original tool's scope and capability | `Snapshottable::snapshot()` of the stored input |
| `kuduy-abilities` | `kuduy:read` + `read` | `wp_get_abilities()` filtered to `mcp.public` |
| `kuduy-ability-read` | `kuduy:read` + `read`; the ability's permission callback | `WP_Ability::execute()` |
| `kuduy-ability-run` | `kuduy:admin` + `read`; token; the ability's permission callback | `WP_Ability::execute()` |

## Tests

`ModesTest` (MJ1–MJ7): MJ1 read-only hides every non-read tool from tools/list; MJ2 every non-read tool is refused by tools/call in read-only (loop over the whole registry, no tool executes); MJ3 jobs stop in read-only; MJ4 constant wins over the option, site-info reports it; MJ5 read-only tools are never Snapshottable or Confirmable; MJ6 snapshot diff shows the change and is refused to other users; MJ7 abilities hub: only public abilities listed, covered namespaces and code abilities refused, read refuses a write ability, run needs a token and the ability's own permission callback, read-only mode blocks run.

## Status

Built and tested 2026-11-21 (`TESTING.md`, Phase J: 321 tests green on both pairs). Not yet live-checked on the owner's site.
