# Loupe Cross-Site Search

Cross-site search for WordPress multisite, built as an add-on to
[Loupe Search](https://github.com/soderlind/loupe-search). It maintains **one
combined [Loupe](https://github.com/loupe-php/loupe) index** across the sites in
your network and exposes a single search endpoint on a designated **hub site**,
so a visitor can search every participating site at once and get one ranked list
of results.

## How it works

- **Combined index.** Instead of querying each site's index at search time, the
  add-on mirrors documents from every participating site into a single index
  (one Loupe database per post type) stored at a network-global path. Ranking and
  pagination are then a single, native operation. _(ADR 0001)_
- **In-context mirroring, no `switch_to_blog`.** Each site mirrors its own posts
  into the combined index from within its own request context, reusing Loupe
  Search's document builder so per-site options and filters apply. Backfill runs
  each site in its native context via a separate WP-CLI bootstrap. _(ADR 0002)_
- **Composite identity.** Documents are keyed `{blog_id}_{post_id}` and store
  `blog_id`, `blog_name`, and `url`, so results carry site attribution and link
  correctly across the network.
- **Public, published content only.** Mirrors what Loupe Search itself exposes.
- **Complete search block.** A ready-to-use `Cross-Site Search` block ships with
  the plugin: site and post-type facets, sorting, highlighted snippets,
  pagination, and result attribution — all configurable in the block inspector.

Design decisions are recorded in [`docs/adr/`](docs/adr) and the domain glossary
in [`CONTEXT.md`](CONTEXT.md).

## Requirements

- WordPress **multisite** 6.9+
- [Loupe Search](https://github.com/soderlind/loupe-search) active on the network
- PHP 8.3+ with `pdo_sqlite`, `intl`, `mbstring` (same as Loupe Search)

## Installation

1. Download [`loupe-cross-site-search.zip`](https://github.com/soderlind/loupe-cross-site-search/releases/latest/download/loupe-cross-site-search.zip)
2. Go to **Network Admin → Plugins** and upload `loupe-cross-site-search.zip`, then activate the plugin network-wide.
3. Go to **Network Admin → Settings → Cross-Site Search** and configure the hub
   site, participation, language, and post types.
4. Build the combined index:

   ```bash
   wp loupe-cross-site reindex
   ```

## Configuration

### Network Admin → Settings → Cross-Site Search

| Setting | Description |
| --- | --- |
| **Hub site** | The site that exposes the cross-site search REST endpoint. Defaults to the network's main site. |
| **Participation** | `All public sites`, an **allowlist**, or a **blocklist** of sites. |
| **Index language** | Two-letter code used to tokenize the combined index (single language for the whole index). _(ADR 0004)_ |
| **Post types** | Which post types are mirrored into the combined index. |

After changing participation, language, or post types, run
`wp loupe-cross-site reindex`.

## Developer documentation

The REST API, search block, WP-CLI commands, extension filters, and test
commands are documented in the [developer guide](docs/developer.md). See also the
[architecture overview](docs/architecture.md), [decisions](docs/adr/), and the
domain glossary in [CONTEXT.md](CONTEXT.md).

## License

GPL-2.0-or-later. Copyright © Per Søderlind.
