# Phase C — blocks (2026-11-17)

From `docs/plans/ecosystem-spec-gap.md` §4.1 and spec Part 16. Split in two:

- **C1 (this round): core block tools** that every block plugin uses — block-level editing, exact block schemas, validation, patterns, usage search, duplicate a post.
- **C2 (next): per-plugin block tools** (D43) for GenerateBlocks, Kadence Blocks, Spectra Legacy and Spectra Blocks: status with edition and settings, enabled/disabled blocks, Spectra popups. Needs each plugin's source read first.

## Verified facts (WordPress 6.9 source, `wp-includes/blocks.php`, `class-wp-block-type.php`, `class-wp-block-patterns-registry.php`)

- `serialize_block()` rebuilds a block from `innerContent`, where `null` marks the place of each inner block in order; strings are the block's own HTML. Editing inner blocks therefore means editing `innerBlocks` and the `null` markers together.
- `get_comment_delimited_block_content()` writes `<!-- wp:name {attrs} -->…<!-- /wp:name -->` (or `/-->` when empty) and drops the `core/` namespace. `serialize_block_attributes()` re-encodes attributes with `--`, `<`, `>`, `&`, `\"`, `\\` as unicode escapes.
- `WP_Block_Type::prepare_attributes_for_render()` validates each attribute with `rest_validate_value_from_schema( $value, $definition )` — the same check is used here before saving.
- Attribute definitions with a `source` (`html`, `text`, `rich-text`, `attribute`, `query`) are read from the block's HTML, not from the comment: setting them as attributes does nothing visible. They can only be changed by replacing the block's markup.
- `WP_Block_Type` has `parent`, `ancestor`, `allowed_blocks` (nesting rules), `supports`, `styles`, `example`, `get_variations()`.
- `WP_Block_Patterns_Registry::get_all_registered()` / `get_registered()` return `content` (loaded from `filePath` when needed). Synced patterns are `wp_block` posts.

## Limits (said in the tool descriptions)

- KuDiagnostics cannot run a block's JavaScript `save()`: for static blocks (whose HTML is generated in the editor) the caller supplies the markup; changing an attribute that only affects the HTML of a static block makes the editor show "unexpected content". `kuduy-block-edit update` therefore refuses sourced attributes and says so in its result for static blocks.
- Untouched blocks are re-serialized by WordPress' own serializer; their text can change only in attribute escaping (the same as saving in the editor). The result says when that happened.

## Tools (C1)

| Tool | Does |
|---|---|
| `kuduy-block-schema` | One block type exactly as registered: every attribute definition (type, enum, default, source, selector), supports, styles, variations, parent/ancestor/allowed blocks, dynamic or static, example |
| `kuduy-block-edit` | 1–50 operations on one post's blocks, saved once: `insert` (markup or a registered pattern, under a parent path at an index), `replace`, `update` (set/unset attributes, validated), `move`, `duplicate`, `remove`; paths as in `kuduy-post-blocks`; optional `expect` name per operation; snapshot and undo; builder pages refused |
| `kuduy-block-validate` | A post or a piece of markup: unknown blocks, invalid attribute values, nesting rules broken, stray HTML outside blocks, whether WordPress would serialize it the same |
| `kuduy-patterns` | Registered patterns (name, title, categories, block types) and synced patterns; with `name` the pattern's markup |
| `kuduy-block-usage` | Posts that use a block type (or any block of a namespace), with counts per post |
| `kuduy-post-duplicate` | Copies a post or page into a new draft: content, excerpt, terms, featured image, page template, custom fields (not edit locks or old slugs). Elementor pages → `kuduy-elementor-duplicate`; products refused |

## Tests

`BlockEditTest` (BK-E1…): each operation, nested insert into an empty group, moves, refused cases (unknown block, invalid attribute, sourced attribute, nesting rule, stale path, builder page, no permission), undo, untouched blocks unchanged. `BlockInfoTest`: schema, validate, patterns, usage, duplicate. A CI job with GenerateBlocks 2.5.1, Kadence Blocks 3.9.1 and Spectra Legacy 2.20.6 inserts and validates one block of each.
