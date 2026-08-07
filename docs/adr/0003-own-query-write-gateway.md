# Own query/write gateway instead of reusing Loupe Search's engine

The add-on creates its own Loupe instances for the combined index (directly via
the Loupe library) rather than reusing `WP_Loupe_Search_Engine` /
`WP_Loupe_Factory`. It still reuses `WP_Loupe_Indexer::prepare_document()` to
build core field values, then projects them onto the fixed combined schema.

## Status

accepted

## Considered Options

- **Reuse loupe-search's engine/factory**, pointed at the combined path.
- **Own thin gateway** (chosen) over the Loupe library.

## Consequences

Reuse is unsafe because, in loupe-search: `WP_Loupe_Factory` caches instances by
`{post_type}:{lang}` regardless of path (so it would return the site's own index
instance), strictly type-hints the singleton `WP_Loupe_DB` whose base path is set
by the global `loupe_search_db_path` filter (redirecting it moves the site's own
index), and `WP_Loupe_Search_Engine` derives its config from the current site's
`loupe_search_fields` option (not the combined schema).

- We own the combined index's config, primary key (`{blog_id}_{post_id}`), and
  the `blog_id`/`url`/`blog_name` attributes.
- loupe-search's `SearchParameters` construction is copied as a reference, so we
  inherit a version coupling to the Loupe library's query API.
- `prepare_document()` is still reused, keeping core-field cleaning faithful to
  each site; site-specific extra fields are dropped by the projection.
