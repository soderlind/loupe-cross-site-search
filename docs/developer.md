# Developer guide

Reference for building on Loupe Cross-Site Search: the search REST API, the
example block, WP-CLI commands, extension filters, and running the tests.

See also: [architecture](architecture.md), [decisions](adr/), and the domain
glossary in [CONTEXT.md](../CONTEXT.md).

## REST API

The hub site exposes:

```text
POST /wp-json/loupe-cross-site/v1/search
GET  /wp-json/loupe-cross-site/v1/search?q=…   (legacy: query + pagination only)
```

Search is public and returns published content only.

### Request (POST)

```json
{
  "q": "wordpress",
  "postTypes": "all",
  "page": { "number": 1, "size": 10 },
  "filter": {
    "type": "and",
    "items": [
      { "type": "pred", "field": "blog_id", "op": "in", "value": [ 2, 3 ] },
      { "type": "pred", "field": "post_date", "op": "gte", "value": "2025-01-01" }
    ]
  },
  "sort": [ { "by": "_score", "order": "desc" } ],
  "facets": [ { "type": "terms", "field": "blog_id" } ],
  "attributesToHighlight": [ "post_title" ]
}
```

Filterable / sortable / facetable fields are limited to `post_type`, `blog_id`,
`blog_name`, and `post_date`.

### Response

```json
{
  "hits": [
    {
      "id": 45,
      "blog_id": 2,
      "blog_name": "Marketing",
      "post_type": "post",
      "post_type_label": "Post",
      "title": "Getting started",
      "excerpt": "…",
      "url": "https://marketing.example.com/getting-started",
      "_score": 12.34
    }
  ],
  "facets": {
    "blog_id": { "type": "terms", "buckets": [ { "value": "2", "count": 8 } ] }
  },
  "pagination": { "total": 42, "per_page": 10, "current_page": 1, "total_pages": 5 },
  "tookMs": 6
}
```

## Search block

The plugin ships a complete **Cross-Site Search** block (`loupe-cross-site/search`)
— a full search experience, not just a demo. It queries the hub endpoint and
provides:

- debounced search-as-you-type with a clear button and loading state;
- **site** and **post-type** facets (checkboxes with counts) that filter results;
- **sorting** (Relevance / Newest / Oldest / Title);
- **highlighting** with cropped snippets (`<mark>`), sanitized client-side;
- per-result site badge, post-type label, and date;
- pagination and a result count with timing;
- empty / error states and ARIA live regions.

Everything is configurable from the block inspector:

| Attribute | Default | Purpose |
| --- | --- | --- |
| `heading` | `""` | Optional heading above the search |
| `placeholder` | `Search…` | Input placeholder |
| `perPage` | `10` | Results per page (1–50) |
| `showSiteFilter` | `true` | Show the site facet |
| `showTypeFilter` | `true` | Show the post-type facet |
| `showSort` | `true` | Show the sort control |
| `defaultSort` | `relevance` | `relevance` \| `newest` \| `oldest` \| `title` |
| `showExcerpt` | `true` | Show highlighted snippets |
| `showDate` | `true` | Show the result date |
| `highlight` | `true` | Request and render match highlighting |

The front-end logic is plain JavaScript in
[blocks/cross-site-search/view.js](../blocks/cross-site-search/view.js) — no build
step. `file:` block scripts require companion `*.asset.php` files (present in
[blocks/cross-site-search/](../blocks/cross-site-search)); without them WordPress
silently skips script registration. Prefer the block, or build your own UI on the
REST API for anything more specialized.

> On **subdomain** multisite, a block placed on a non-hub site makes a
> cross-origin request to the hub and may be blocked by CORS. Place the block on
> the hub site, use a subdirectory network, or add CORS headers.

## WP-CLI

```bash
# Rebuild the combined index for all participating sites (or a subset).
wp loupe-cross-site reindex
wp loupe-cross-site reindex --sites=2,5 --post-types=post

# Reconcile drift between a site and the combined index.
wp loupe-cross-site verify
wp loupe-cross-site verify --site=5 --repair

# Remove a single site's documents.
wp loupe-cross-site purge --site=5
```

`reindex` and `verify` process each site in its own context by launching a
separate `wp` process per site (`--url=<site>`), which is why they must resolve
the database the same way a normal request does.

> **Local by Flywheel caveat.** Local serves MySQL over a unix socket configured
> in the site's `php.ini`, and launched child processes don't inherit it, so the
> per-site subprocess step fails with "Error establishing a database
> connection". Run the in-context workers directly instead:
>
> ```bash
> wp --url=http://your-site.local/ loupe-cross-site reindex-site --force
> wp --url=http://your-site.local/ loupe-cross-site verify-site --repair
> ```
>
> On standard hosts (TCP `DB_HOST`) the top-level `reindex` / `verify` commands
> work as-is.

## Filters

| Filter | Description |
| --- | --- |
| `loupe_cross_site_is_participating` | `bool $participating, int $blog_id` — override whether a site participates. |
| `loupe_cross_site_document` | `array $document, WP_Post $post, int $blog_id` — adjust a document before it is written. |
| `loupe_cross_site_db_path` | `string $path` — move the combined index directory (default `WP_CONTENT_DIR/loupe-cross-site-db`). |

## Testing

```bash
composer install && composer test   # PHP: Pest + Brain Monkey (WordPress mocked)
npm install && npm test             # JS: Vitest + jsdom (example block)
```
