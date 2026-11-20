# Phase N — MCP resources and prompts (started 2026-11-20)

From `docs/plans/ecosystem-spec-gap.md` (row 8, D51, D32): read-only resources (site overview, integrations, content model, memory summary) and prompts for common workflows; skills (D32) fold in. Exit gate: listed and read by a real Claude client (rule 9) — until that is verified, the records say "not verified with a client".

## Spec (2026-07-28 and 2025-11-25, read 2026-11-20; `RESEARCH.md` §5)

Capabilities `resources: { subscribe, listChanged }`, `prompts: { listChanged }` (both false here). Methods `resources/list` (cursor → `resources`, `nextCursor`), `resources/read` (`uri` → `contents[]` with `uri`, `mimeType`, `text`), `resources/templates/list` (`resourceTemplates`), `prompts/list` (`prompts[]`: `name`, `title`, `description`, `arguments[]` with `name`, `description`, `required`), `prompts/get` (`name`, `arguments` → `description`, `messages[]` with `role` and `content { type: "text", text }`). 2026-07-28: `resultType` on every result, `ttlMs` / `cacheScope` on list and read results, `Mcp-Name` header carries the URI or prompt name. Unknown resource: `-32602` (2026-07-28; `-32002` in 2025-11-25, clients accept both), never an empty `contents`; unknown prompt or missing required argument: `-32602`.

## Resources (D72)

| URI | Backed by | Needs |
|---|---|---|
| `kuduy://site/overview` | `kuduy-site-info` | as the tool |
| `kuduy://site/integrations` | `kuduy-integrations` | as the tool |
| `kuduy://site/content-model` | `kuduy-content-model` | as the tool |
| `kuduy://memory/context` | `kuduy-memory-context` | as the tool |
| `kuduy://design/tokens` | `kuduy-design-scan` | as the tool |

A resource is listed only when its tool is available and the connection's scope and the user's capability allow it; reading runs the tool through the executor (scope, capability, redactor, audit row, rate limit), and the text is the tool's JSON result (`application/json`). `ttlMs` 0, `cacheScope` private (results depend on the user). No templates (`resourceTemplates` empty).

## Prompts and skills (D73)

Built-in workflow prompts, each a user message that names the tools to use in order: `site-audit` (focus), `build-page` (goal, builder), `seo-review` (post), `safe-change` (change), `start-session` (project), `match-design` (task). Site skills: shared, current memories of kind `skill` become prompts named `skill-{id}` (title and body from the memory; the body already passed the memory secret check). For clients without prompt support: `kuduy-skills` (list built-in prompts and site skills) and `kuduy-skill-get` (the filled-in text of one). Saving a skill is `kuduy-memory-save` with kind `skill`.

## Tests

`ResourcesPromptsTest` (RP1–RP6) through the real REST endpoint in both protocol eras; real-HTTP smoke extended with `resources/list`, `resources/read` and `prompts/list` over OAuth.

## Status

Built and tested 2026-11-20 (RP1–RP6 on both pairs, smoke 29/29; results in `TESTING.md`). Not released; **not yet verified with a Claude client** (rule 9).
