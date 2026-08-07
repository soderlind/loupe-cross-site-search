# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Complete **Cross-Site Search** block replacing the minimal example: site and
  post-type facets (with counts), sorting (relevance/newest/oldest/title),
  match highlighting with cropped snippets, per-result site/type/date metadata,
  loading/empty/error states, and full block-inspector configuration.
- `date` field added to each REST search hit.

### Fixed

- Block scripts now register in the editor: added the required `index.asset.php`
  and `view.asset.php` companion files (WordPress silently skips `file:` block
  scripts that lack them).
- `blog_id` facet values are normalized from floats (`"4.0"`) to integers
  (`"4"`).

## [0.1.0] - 2026-08-08

### Added

- Combined cross-site Loupe index across a multisite network, with one SQLite
  database per post type at a network-global path.
- In-context mirroring: each participating site mirrors its own published
  content into the combined index via lifecycle hooks (no `switch_to_blog`),
  reusing Loupe Search's document builder.
- Composite document identity (`{blog_id}_{post_id}`) with stored `blog_id`,
  `blog_name`, and `url` for cross-site attribution.
- Hub-only REST endpoint `POST/GET /wp-json/loupe-cross-site/v1/search`,
  mirroring Loupe Search's request/response schema plus a `blog_id` filter and
  facet.
- Network Admin settings: hub site, participation mode (all public / allowlist /
  blocklist), index language, and covered post types.
- Site lifecycle handling: purge a site's documents on delete, archive, spam, or
  when it becomes non-public.
- WP-CLI commands: `reindex`, `verify` (with `--repair`), and `purge`.
- A minimal, pageable example search block that queries the hub endpoint.
- Filters: `loupe_cross_site_is_participating`, `loupe_cross_site_document`, and
  `loupe_cross_site_db_path`.
- Test suites: PHP (Pest + Brain Monkey) and JavaScript (Vitest + jsdom).

[Unreleased]: https://github.com/soderlind/loupe-cross-site-search/compare/0.1.0...HEAD
[0.1.0]: https://github.com/soderlind/loupe-cross-site-search/releases/tag/0.1.0
