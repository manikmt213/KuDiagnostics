# Phase E — fields and the content model (started 2026-11-18)

Spec part 8 and `ecosystem-spec-gap.md` §4.4. Free plugins, tested in Playground with the real ZIPs and live on the owner's site (all four are installed there).

## Each plugin's own path (read from source, 2026-11-18)

| Plugin (version) | Definitions | Values | Own abilities |
|---|---|---|---|
| ACF 6.8.11 | `acf_import_field_group()` (group with its fields, by key; what ACF's own create-field-group ability calls), `acf_trash_field_group()` / `acf_untrash_field_group()`; ACF post types and taxonomies (6.1+) through `acf_import_internal_post_type( $post, 'acf-post-type' / 'acf-taxonomy' )` | existing `kuduy-acf-get` / `-update` | field groups, post types, taxonomies — only when "Allow AI Access" is on |
| Meta Box 5.15.5 | registered in code (`rwmb_get_registry( 'meta_box' )`); the builder is the paid MB Builder, so read only | `meta-box/get-field-value`, `update-field-value`, `delete-field-value` abilities (always registered; Meta Box's own normalizing and permission per object) | yes (values) |
| Pods 3.3.9.2 | `pods_api()->save_pod()`, `save_field()`, `delete_field()`, `load_pod()` / `load_pods()` | `pods( $pod, $id )->save()` / `->field()`; `pods_api()->save_pod_item()` | no |
| ACPT Lite 2.1.3 | public API `functions/_inc`: `register_acpt_post_type()`, `register_acpt_taxonomy()`, `save_acpt_meta_group()`, `save_acpt_meta_box()`, `save_acpt_meta_field()`, deletes; `flush_acpt_caches()` | `get_acpt_field()`, `save_acpt_meta_field_value()`, `delete_acpt_meta_field_value()` | no |
| ASE 9.3.3 | — | one option `admin_site_enhancements`, registered with `Settings_Sanitization::sanitize_for_options` (run here explicitly: the setting is registered on admin screens only) | no |

## Decision D58 — scope (recommended option taken, for review)

| Option | What it means |
|---|---|
| **A. Definitions where the free plugin has a save path, values everywhere (recommended)** | ACF: save and trash field groups, save ACF post types and taxonomies. Meta Box: read boxes and fields; read, change and delete values through its abilities. Pods: save pods and fields, items and values. ACPT: post types, taxonomies, groups, boxes, fields and values. ASE: settings and modules through ASE's sanitizer; **refused:** code insertion (head/body/footer code, custom JS), the custom login URL and REST/XML-RPC switches (they would cut KuDiagnostics off or run code), and anything holding a key or password. Every change snapshots first; undo through the same API. Deleting a definition that has values is refused (values stay; the plugin's own screen deletes). `kuduy-content-model`: which plugin registers which post type, taxonomy and field (name, type, location), and clashes (same post type key or meta key from two sources) |
| B. Read only for definitions | Values only; definitions stay in each plugin's screen |

## Tools

| Tool | Scope |
|---|---|
| `kuduy-acf-field-group-save`, `kuduy-acf-field-group-trash`, `kuduy-acf-post-type-save`, `kuduy-acf-taxonomy-save` | admin (definitions change the editing screens site-wide) |
| `kuduy-meta-box-boxes`, `kuduy-meta-box-get`, `kuduy-meta-box-update` | read / read / write |
| `kuduy-pods-list`, `kuduy-pods-get`, `kuduy-pods-save`, `kuduy-pods-field-save`, `kuduy-pods-item-get`, `kuduy-pods-item-save` | read, read, admin, admin, read, write |
| `kuduy-acpt-list`, `kuduy-acpt-post-type-save`, `kuduy-acpt-taxonomy-save`, `kuduy-acpt-group-save`, `kuduy-acpt-values-get`, `kuduy-acpt-values-update` | read, admin, admin, admin, read, write |
| `kuduy-ase-settings`, `kuduy-ase-settings-update` | read, admin |
| `kuduy-content-model` | read |

## Tests (FE1–FE6), each with the real plugin in Playground, then live on the owner's site

FE1 ACF groups/post types/taxonomies with undo and refusals; FE2 Meta Box values (a test box registered in code); FE3 Pods; FE4 ACPT; FE5 ASE settings with the refused keys; FE6 content model with a deliberate clash.

## Order

E1 ACF → E2 Meta Box → E3 Pods → E4 ACPT → E5 ASE → E6 content model; full round, security pass, records, one push.
