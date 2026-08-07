# Mirror documents in each site's own context, without switch_to_blog

Each participating site mirrors its own posts into the combined index from
within that site's normal request context (via network-activated lifecycle
hooks), reusing Loupe Search's `WP_Loupe_Indexer::prepare_document()`. We do not
build documents from a central hub loop using `switch_to_blog()`.

## Status

accepted

## Considered Options

- **Hub-side `switch_to_blog()` loop** — one process iterates every site and
  switches context to build each document.
- **In-context mirroring** (chosen) — each site indexes its own content where it
  is saved; backfill runs per-site via separate CLI bootstraps (`wp --url=…`).

## Consequences

- Higher fidelity: `prepare_document()` depends on the site's `get_option()`
  values and its *registered* `loupe_search_field_*` / `loupe_search_schema_*`
  filters. `switch_to_blog()` does not load another site's per-site filters
  (hooks are process-global), so a hub loop would produce degraded documents.
- Avoids per-site `switch_to_blog()` overhead entirely for real-time sync.
- Requires the add-on's loader to be network-activated so hooks run on every
  participating site, and requires the combined index to live at a
  network-global path (not a per-site path).
- The combined index must store `url`, `blog_id`, and site name at index time,
  because permalinks cannot be resolved for a foreign post ID at query time.
