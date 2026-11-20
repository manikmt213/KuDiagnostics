# Phase R — WPCode, Masteriyo, Tutor LMS, Themify Builder and Mosaic (started 2026-11-20)

Owner's order (2026-11-18, relayed): after the 1.4.0 release, a round of its own with research first: WPCode, Mosaic deeper, Masteriyo, Tutor LMS, and LearnWorlds only after asking. Workflow as before: build everything (tests written with the code), then one full test round, a security review, one push; new decisions take the recommended option and are marked **for review**; ask before tagging.

## Research (2026-11-20, source read; details in `RESEARCH.md` §10r)

| Product | Version read | What it offers | Our path |
|---|---|---|---|
| WPCode (`insert-headers-and-footers`) | 2.4.2 (wordpress.org) | Abilities `wpcode/*`, all marked MCP-public: list-snippets, get-snippet, detect-snippet-errors, get-error-logs, get-settings, search-library (reads); create-snippet, update-snippet, set-snippet-status, install-library-snippet, get/update-headers-footers (writes registered only with WPCode's "AI & Automation Access" setting). Snippets are posts of type `wpcode` (publish = active), code type in taxonomy `wpcode_type`. Read needs `wpcode_edit_snippets`; activation `wpcode_activate_snippets`; headers/footers `unfiltered_html` + `wpcode_activate_snippets`. Activating a PHP or universal snippet test-runs it (`test_run`). | D62 |
| Masteriyo (`learning-management-system`) | 3.5.2 (wordpress.org) | About 80 abilities `masteriyo/*` proxying its REST controllers (same permission checks): courses, sections, lessons, quizzes, questions, categories, tags, difficulties, enrollments, progress, Q&A, reviews, orders, users, settings, course/quiz builder. Payment, email and advanced settings updates are not registered unless a filter opts in. Items by `id`; deletes trash unless `force`. Posts `mto-course`, `mto-section`, `mto-lesson`, `mto-quiz`, `mto-question`. | D63 |
| Tutor LMS (`tutor`) | 4.2.1 (wordpress.org) | No abilities; REST `tutor/v1` is read-only behind its own token sign-in. PHP: `CourseModel`, `EnrollmentModel::do_enroll()` / `update_enrollments()` / `is_enrolled()`, `QuizModel`, `tutor_utils()->get_topics()`, `get_course_contents_by_topic()`, `get_course_completed_percent()`, `can_user_manage()`. Its own builder creates topics (`topics`, parent = course, `get_next_topic_order_id`) and lessons (`lesson`, parent = topic, `get_next_course_content_order_id`, action `tutor/lesson/created`) with `wp_insert_post`. | D64 |
| Themify Builder (`themify-builder`) | 7.8.5 (wordpress.org) | Layout JSON in post meta `_themify_builder_settings_json`, read with `ThemifyBuilder_Data_Manager::get_data()`, written with `save_data( $data, $post_id, 'backend' )` (transaction, revision, static content, cache). Rows → cols → modules (`mod_name`, `element_id`, `mod_settings`). | D65 |
| Builder Mosaic | 3.6.2 (owner's site, file editor) | Themify Builder module `mosaic`; `mod_settings.tiled_posts_display` names the provider (posts, terms, text, gallery, wc, portfolio, ptb, tep, nextgen, rss, files — each only when available); presets are posts of type `tftp_template`. Needs Themify Builder, which is not installed on the owner's site. | D65 |
| LearnWorlds | — | Hosted service; API reached with a client id and secret. Official docs not found at the expected address; third-party pages disagree on the token endpoint and API host. **UNVERIFIED.** | D66: not built |

## Tools

| Tool | Access | Through |
|---|---|---|
| `kuduy-wpcode-snippets`, `-snippet-get` | `kuduy:read` + `wpcode_edit_snippets` | `wpcode/list-snippets`, `wpcode/get-snippet` (+ `detect-snippet-errors`); credentials in code masked; syntax check and risky calls as Code Snippets |
| `kuduy-wpcode-snippet-save` (HTML, CSS, JS, text, blocks) | `kuduy:admin` + confirmation token (D45) | `wpcode/create-snippet` / `update-snippet`; PHP and universal refused; snapshot for undo |
| `kuduy-wpcode-snippet-php-save` | `kuduy:code` + `KUDUY_MCP_ALLOW_CODE_EXEC` (D4) + token | same abilities for PHP and universal (WPCode test-runs them when switched on) |
| `kuduy-wpcode-snippet-deactivate` | `kuduy:admin`, undo | `wpcode/set-snippet-status` active false |
| `kuduy-wpcode-headers-footers`, `-headers-footers-save` | read: `kuduy:admin`; save: `kuduy:admin` + token; both `unfiltered_html` | `wpcode/get-headers-footers`, `update-headers-footers` (append or replace); undo replaces with the previous text |
| `kuduy-masteriyo-abilities`, `-ability-get`, `-ability-run` | list/get `kuduy:read`; run `kuduy:admin`; Masteriyo checks the user | its abilities; writes limited to an allowlist (below); post-backed changes snapshotted |
| `kuduy-tutor-courses`, `-course-get`, `-students`, `-quiz-attempts` | `kuduy:read`; course content only for users who can manage the course | Tutor's models |
| `kuduy-tutor-enroll`, `-enrollment-status`, `-curriculum-add` | `kuduy:write` + `can_user_manage( 'course' )` | `EnrollmentModel`; topics and lessons as Tutor's builder makes them; undo |
| `kuduy-themify-builder-get`, `-module-update` | read `kuduy:read` + `edit_post`; update `kuduy:write` + `edit_post` | `ThemifyBuilder_Data_Manager`; snapshot of the layout meta |
| `kuduy-mosaic-sources` | `kuduy:read` + `edit_posts` | Mosaic's providers and presets; Mosaic module settings change through `-module-update`, with the provider checked |

Masteriyo writes allowed: create (course, section, lesson, quiz, question, course-category, course-tag, course-difficulty, enrollment), clone (lesson, quiz), update (course, section, lesson, quiz, question with a post snapshot; category, tag, difficulty with a term snapshot; enrollment), delete without `force` and restore (course, lesson). Not run: orders, users, settings updates, course/quiz builder saves (they rewrite many posts at once), reviews and Q&A writes, progress updates, permanent deletes, every other delete.

## Tests

Real plugins in Playground: WPCode 2.4.2, Masteriyo 3.5.2, Tutor LMS 4.2.1, Themify Builder 7.8.5 (each its own CI row). Mosaic: stand-in `tests/integration/fakes/mosaic.php` from the source read on the site. Then a live check on the owner's site (temporary OAuth; Themify Builder installed there only with the owner's OK).

## Status

Built and tested 2026-11-20 (WC1–WC5, MY1–MY4, TU1–TU4, TB1–TB3 on both pairs; results in `TESTING.md`). Live-checked on the owner's site 2026-11-20 (results in `TESTING.md`); gap found: no trash tool for WPCode snippets. LearnWorlds open (D66).
