# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Automatic updates from GitHub releases via
  [soderlind/wordpress-github-updater](https://github.com/soderlind/wordpress-plugin-github-updater)
  (bundles `plugin-update-checker`).
- Internationalization: `languages/` POT, `i18n:*` build scripts, `i18n-map.json`,
  and text-domain loading for PHP and JS strings.

### Changed

- Block and network-settings app are now built from `src/` with
  `@wordpress/scripts` (`npm run build`), replacing the hand-written JS and
  `*.asset.php` files. Dependencies and cache-busting versions are generated;
  `build/` is git-ignored and produced by CI for release zips.

## [1.0.0] - 2026-08-08

First stable release.

### Added

- **Reindex now** button on the network settings screen: queues a background
  reindex via bundled **Action Scheduler** (one async job per participating
  site), with a `GET`/`POST /wp-json/loupe-cross-site/v1/reindex` endpoint and
  live progress polling. See [ADR 0005](docs/adr/0005-background-reindex-action-scheduler.md).
- Bundled `woocommerce/action-scheduler` as a runtime dependency (shipped in the
  release zip via `composer install --no-dev`).

### Changed

- Network settings screen rebuilt as a WordPress React app
  (`@wordpress/components`) backed by a `manage_network_options` REST endpoint
  (`GET`/`POST /wp-json/loupe-cross-site/v1/settings`): card layout, searchable
  site allow/block list, and inline save with a success notice.

### Fixed

- `Document_Builder` rebuilds its cached Loupe Search indexer when the blog
  context changes, preventing one site's post-type-keyed schema cache from
  leaking into another when several sites are reindexed in one request.

## [0.2.0] - 2026-08-08

Initial public release.

### Added

- Combined cross-site Loupe index across a multisite network, with one SQLite
  database per post type at a network-global path.
- In-context mirroring: each participating site mirrors its own published
  content into the combined index via lifecycle hooks (no `switch_to_blog`),
  reusing Loupe Search's document builder.
- Composite document identity (`{blog_id}_{post_id}`) with stored `blog_id`,
  `blog_name`, `url`, and `date` for cross-site attribution.
- Hub-only REST endpoint `POST/GET /wp-json/loupe-cross-site/v1/search`,
  mirroring Loupe Search's request/response schema plus a `blog_id` filter and
  facet.
- Network Admin settings: hub site, participation mode (all public / allowlist /
  blocklist), index language, and covered post types.
- Site lifecycle handling: purge a site's documents on delete, archive, spam, or
  when it becomes non-public.
- WP-CLI commands: `reindex`, `verify` (with `--repair`), and `purge`.
- Complete **Cross-Site Search** block: site and post-type facets (with counts),
  sorting (relevance/newest/oldest/title), match highlighting with cropped
  snippets, per-result site/type/date metadata, loading/empty/error states, and
  full block-inspector configuration.
- Filters: `loupe_cross_site_is_participating`, `loupe_cross_site_document`, and
  `loupe_cross_site_db_path`.
- Test suites: PHP (Pest + Brain Monkey) and JavaScript (Vitest + jsdom).

[Unreleased]: https://github.com/soderlind/loupe-cross-site-search/compare/1.0.0...HEAD
[1.0.0]: https://github.com/soderlind/loupe-cross-site-search/compare/0.2.0...1.0.0
[0.2.0]: https://github.com/soderlind/loupe-cross-site-search/releases/tag/0.2.0
