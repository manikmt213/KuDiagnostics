# Phase I — Memory and design scan (started 2026-11-20)

From `IMPLEMENTATION-PLAN.md` (I) as narrowed by `docs/plans/ecosystem-spec-gap.md` (row 7, D31, D53): memory that persists across connections and sessions, never stores secrets, has relevance search and a conflict flag against what is live on the site; plus a design scan. Skills and prompts moved to phase N (D51). Same workflow as F2 and R.

## Memory (D31 + D53 → D70)

Table `{prefix}kuduy_memory` (DB version 3; multisite: one per site through the blog prefix):

| Column | Meaning |
|---|---|
| `id` | key |
| `kind` | `note`, `fact`, `decision`, `preference`, `design`, `task` or another short word |
| `title`, `body`, `tags` (JSON list) | content; body up to 20,000 characters |
| `project` | optional grouping ("redesign-2026", "shop") |
| `user_id` | 0 = shared with every connected user who can edit content; otherwise private to that user |
| `refs` (JSON list) | integration ids from the catalogue the memory is about (e.g. `elementor`, `woocommerce`); used for the conflict flag |
| `status` | `current` or `outdated` |
| `version` | starts at 1, +1 on every change; a save may name the version it read (stale → CONFLICT) |
| `expires_at` | optional; expired memories are left out unless asked for |
| `created_by`, `created_at`, `updated_at` | who and when |

Tools (8): `kuduy-memory-save` (create or change, undo for changes), `-get`, `-search` (relevance: title ×3, tags ×2, body; filters kind, project, status), `-list`, `-delete` (one, or a whole project / all with `confirm`, admins only; undo puts them back), `-export` (JSON), `-import` (JSON, admins; each item checked like a save), `-context` (what to know at the start of a session: current, unexpired memories for a project, newest first, each with conflicts — referenced integrations that are not active now).

Secrets are refused, not masked: a save or import whose text the redactor or the credential masker would change, or that contains a well-known key format (`sk-…`, `sk_live_…`, `ghp_…`, `github_pat_…`, `xox?-…`, `AKIA…`, `AIza…`, PEM private keys, JWTs, our own `kmcp_` tokens, password hashes), is refused with the reason. Access: reading needs `edit_posts` (shared and own memories only); saving shared memories `edit_posts`; changing or deleting someone else's needs `manage_options`; bulk delete and import need `kuduy:admin` + `manage_options`. Uninstall drops the table with the others.

## Design scan (D71)

`kuduy-design-scan` (read): the site's design tokens in one shape — colors (theme.json palette, global styles background/text/link, Elementor Kit system and custom colors, Customizer background/header text), fonts and font sizes (theme.json font families and sizes, Elementor Kit typography), spacing sizes and layout widths (theme.json), site title, tagline, logo and icon. Each token names its source. A `summary` text is ready to be saved with `kuduy-memory-save` (kind `design`) — the scan itself writes nothing.

## Tests

`MemoryTest` (ME1–ME7) and `DesignScanTest` (DS2–DS3) in the bare suites on both pairs (Elementor 4.3.6 active there); `check:release` and the uninstall test count the new table.

## Status

Built and tested 2026-11-20 (ME1–ME7, DS2–DS3 on both pairs; results in `TESTING.md`). Released in 1.6.0 (2026-11-20).
