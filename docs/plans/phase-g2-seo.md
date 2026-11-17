# Phase G2 — SEO (2026-11-17)

Per D43 one tool set per SEO plugin, through each plugin's own API (sources: Yoast SEO 28.8, Rank Math 1.0.281, All in One SEO 5.0.5, SEOPress 10.4; facts in `RESEARCH.md`).

| Tools | Yoast SEO | Rank Math | SEOPress |
|---|---|---|---|
| `kuduy-{plugin}-term-seo` / `-term-seo-update` (categories, tags, any taxonomy) | `WPSEO_Taxonomy_Meta::get_term_meta()` / `set_values()`, then `Indexable_Term_Watcher::build_indexable()` | REST `rankmath/v1/updateMeta`, objectType "term" | abilities `seopress/get-|update-term-title-description`, `-robots-settings`, `-social-settings` |
| `kuduy-{plugin}-settings` (read) | `WPSEO_Options::get_all()`, title/description templates, noindex per type and archive, separator, site/company name | `rank-math-options-titles` | `seopress_titles_option_name` |

All in One SEO: term SEO exists only in its Pro edition (the free plugin has no Term model), so no term tools.

`kuduy-seo-audit` gains `check`: "missing" (as before), "noindex" (posts hidden by their own setting), "duplicates" (shared SEO titles or descriptions among the 500 newest posts).

Not yet (later round): settings changes through each plugin's settings API, heading/link audits, Premium/Pro redirects.
