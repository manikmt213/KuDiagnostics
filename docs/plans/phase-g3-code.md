# Phase G3 — Code Snippets, shortcodes, job retry (2026-11-17)

## Code Snippets (4.1.0 source, namespace `Code_Snippets`)

| Tool | Access | Through |
|---|---|---|
| `kuduy-code-snippets-list`, `-get` | `kuduy:read` + its capability (`code_snippets()->get_cap()`, manage_options by default) | `get_snippets()`, `get_snippet()`; `get` adds a syntax check (PHP's own parser, `token_get_all( …, TOKEN_PARSE )`, nothing is run) and risky calls |
| `kuduy-code-snippets-save` (HTML, CSS, JS) | `kuduy:admin` + confirmation token (D45) | `save_snippet()`; refuses PHP scopes and switching on an existing PHP snippet |
| `kuduy-code-snippets-php-save` | `kuduy:code` + `KUDUY_MCP_ALLOW_CODE_EXEC` (D4) + confirmation token | `save_snippet()`; code with a syntax error is not saved active. Code Snippets runs an active PHP snippet once when it is saved (`test_snippet_code()`), which is why this is code execution |
| `kuduy-code-snippets-deactivate`, `-trash` | `kuduy:admin`, undo | `deactivate_snippet()`, `trash_snippet()` / `restore_snippet()` |

Code Snippets' own `Validator` only checks for duplicate function and class names; syntax is checked with PHP's parser. A clean check does not mean safe code, and every preview says so.

Not built: import/export, Pro conditions, cloud snippets.

## Shortcodes

`kuduy-shortcodes`: `$shortcode_tags` with the source of each callback (core, a plugin folder, the theme), found by reflection; nothing is run.

## Job retry

`kuduy-job-retry`: a new job with a finished job's failed items and those it never reached, same tool, each item checked again; refused while the job is queued or running and when nothing failed.
