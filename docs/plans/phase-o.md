# Phase O — Administration (started 2026-11-20)

From `docs/plans/ecosystem-spec-gap.md` (row 9; spec 15 and 25; D46–D49): plugin ZIP upload, delete/uninstall and inspect; permanent post delete; user delete with reassignment and password reset; cron actions; backups through a provider; cross-plugin dependencies and conflicts; file move/delete under D4. Exit gate: the guards are tested (last administrator, self, KuDiagnostics itself). Same workflow as the earlier rounds.

## Research (2026-11-20; `RESEARCH.md` §10o)

- **WordPress core:** `Plugin_Upgrader::install( $package )` (unpacks, checks, refuses an existing folder unless `overwrite_package`), `validate_plugin()`, `delete_plugins( array $files )` (refuses active plugins; runs the plugin's uninstall routine — `uninstall.php` or `register_uninstall_hook` — then removes the files; there is no core "uninstall without deleting"), `get_plugin_data()` (`RequiresPlugins`, `RequiresWP`, `RequiresPHP`), `wp_delete_post( $id, true )`, `wp_delete_user( $id, $reassign )`, `retrieve_password( $login )`, `wp_get_scheduled_event()`, `wp_unschedule_event()`, `wp_schedule_single_event()`, `spawn_cron()`, `rest_get_server()->get_routes()`, `wp_get_abilities()`, `get_registered_settings()`.
- **UpdraftPlus 1.26.16** (`updraftplus`, free): no abilities. Backups start by firing `updraft_backupnow_backup_all` / `updraft_backupnow_backup` (files) / `updraft_backupnow_backup_database` with options `nocloud`, `use_nonce` (`$updraftplus->backup_time_nonce()`), `always_keep` (`UpdraftPlus_Admin::request_backupnow()`); its own command layer (`UpdraftPlus_Commands::backupnow`) ends in `die` and cannot run inside a REST request, so the event is scheduled for WP-Cron as its scheduled backups are. History: `UpdraftPlus_Backup_History::get_history()`. Permission: `UpdraftPlus_Options::user_can_manage()` (manage_options). Restore is a multi-step flow in its screen with no command — **not offered**.

## Tools

| Tool | Access | Through |
|---|---|---|
| `kuduy-plugin-upload` (D74) | `kuduy:admin` + `install_plugins` + token (D45) | A `.zip` already in the media library (attachment id; never a URL), `Plugin_Upgrader::install()`; new plugins only (an existing folder is refused); never activates; KuDiagnostics' own folder refused |
| `kuduy-plugin-delete` (D74) | `kuduy:admin` + `delete_plugins` + token | `delete_plugins()`: must be inactive; the preview says whether the plugin has an uninstall routine (its data may be removed) and that this cannot be undone; KuDiagnostics refused |
| `kuduy-plugin-inspect` (D74, D49) | `kuduy:read` + `activate_plugins` | Header (version, requirements, Requires Plugins with their state), plugins that require it, REST routes and abilities whose callbacks live in its folder, its WP-Cron hooks, registered settings it owns (by option name prefix, values not returned), its update history from the audit log |
| `kuduy-post-delete` (D46) | `kuduy:admin` + `delete_post` + token | Only a post already in the trash; snapshot of the post, its meta and terms first (undo re-creates it with the same id) |
| `kuduy-user-delete` (D75) | `kuduy:admin` + `delete_users` + token | `wp_delete_user( $id, $reassign )`; never yourself, never the last administrator; content must be reassigned or the preview lists what would be deleted; no undo (a snapshot would hold the password hash) |
| `kuduy-user-password-reset` (D75) | `kuduy:admin` + `edit_user` + `confirm` | `retrieve_password()` (WordPress sends the user its reset email); the password is never set or seen |
| `kuduy-cron-run`, `kuduy-cron-unschedule` (D76) | `kuduy:admin` + `manage_options` | Run = schedule a one-off of the same hook and arguments now and `spawn_cron()` (as WP Crontrol does; nothing runs inside the request); unschedule = `wp_unschedule_event()` with undo (re-scheduled at its time and recurrence) |
| `kuduy-backup-status`, `kuduy-backup-start` (D77) | read / `kuduy:admin`; `manage_options` | UpdraftPlus: history and running jobs; start = its backup event scheduled for WP-Cron; restore not offered |
| `kuduy-integration-dependencies`, `kuduy-integration-conflicts` (D78) | `kuduy:read` + `activate_plugins` | Requires Plugins headers and the catalogue's dependencies: what is missing or inactive; conflicts: more than one active SEO plugin, page cache plugin, or translation plugin of the catalogue |
| `kuduy-dev-file-move`, `kuduy-dev-file-delete` (D79) | D4 (`kuduy:code` + constant) | Same roots and refusals as `kuduy-dev-file-write`; active plugin/theme files need `confirm`; snapshot first (delete: the file, up to 1 MB), undo |

## Tests

`AdminToolsTest` (AO1–AO6) in the bare suites; `BackupTest` (AO7) with UpdraftPlus 1.26.16 (new CI rows on both pairs); file move/delete (AO8) in `DeveloperTest`'s code-execution job. AO6's conflict case runs in the existing Rank Math + Yoast row.

Not in this round (from the gap table): a separate `-compatibility` tool and an ecosystem audit (dependencies and conflicts cover the checks the catalogue can make), a diff against a snapshot, object-cache flush, and a self-promotion guard (`kuduy-user-update` already refuses changing your own role).

## Status

Built and tested 2026-11-20 (see `TESTING.md`, Phase O); live-checked on the owner's test site the same day (one finding fixed: cron undo could duplicate an event its plugin had already re-created). Backups live-checked afterwards with UpdraftPlus 1.26.16 (one finding fixed: running jobs follow UpdraftPlus' own rule).
