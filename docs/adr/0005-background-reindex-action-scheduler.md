# Background reindex via bundled Action Scheduler

The "Reindex now" button on the network settings screen queues a background
reindex using Action Scheduler (bundled with the plugin): one async job per
participating site, processed on the hub site's queue. Each job reindexes its
site by switching into that site's context for the pass.

## Status

accepted

## Considered Options

- **Per-site cron queues** — enqueue each job on its own site's queue so it runs
  in that site's native request (full fidelity, matches ADR 0002).
- **Central queue + `switch_to_blog`** (chosen) — one queue on the hub site; the
  handler switches into each site to index it.
- **No background option** — rely only on the WP-CLI `reindex` command.

## Consequences

- Reliable from the admin UI: the hub's Action Scheduler runner drains the whole
  queue without depending on low-traffic subsites' cron firing.
- Uses `switch_to_blog()` at index time. Per-site `get_option()` values, post
  meta, terms, and permalinks are correct under the switch; only indexing
  *filters* registered solely in a site's own request (not network-wide) would
  differ. The WP-CLI `reindex` command (a fresh process per site) remains the
  fully faithful path — see ADR 0002.
- Because several sites can be indexed in one request, `Document_Builder` rebuilds
  its cached Loupe Search indexer when the blog changes, so one site's
  post-type-keyed schema cache cannot leak into another.
- Action Scheduler is bundled (`woocommerce/action-scheduler`); the build ships
  it via `composer install --no-dev`. Bundling is safe — every copy registers and
  the newest version boots across all plugins.
