=== Loupe Cross-Site Search ===
Contributors: perssoderlind
Tags: multisite, search, loupe, cross-site, network
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.3
Requires Plugins: loupe-search
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Cross-site search for WordPress multisite. Maintains one combined Loupe index across sites and exposes a search endpoint on a hub site.

== Description ==

Loupe Cross-Site Search is an add-on to [Loupe Search](https://wordpress.org/plugins/loupe-search/). It lets a single query return ranked results from many sites in a WordPress multisite network.

Rather than querying each site's index separately at search time, the add-on maintains **one combined Loupe index** across the participating sites (one SQLite database per post type) and exposes a single search endpoint on a designated **hub site**.

**How it works**

* **Combined index.** Documents from every participating site are mirrored into one index, so ranking and pagination are a single, native operation.
* **In-context mirroring.** Each site mirrors its own posts into the combined index from within its own request context (no `switch_to_blog`), reusing Loupe Search's document builder so each site's options and filters apply.
* **Site attribution.** Each document stores its `blog_id`, site name, and URL, so results link correctly across the network and can be filtered or faceted by site.
* **Public, published content only** — matching what Loupe Search itself exposes.

**Features**

* Network-wide combined search index built on Loupe Search.
* Public REST endpoint on the hub site, mirroring Loupe Search's request/response schema plus a `blog_id` filter and facet.
* Participation control: all public sites, an allowlist, or a blocklist.
* WP-CLI commands to reindex, verify/repair drift, and purge a site.
* A complete Cross-Site Search block: site and post-type facets, sorting, highlighted snippets, pagination, and result attribution.

**Developer documentation**

The REST API, filters, and WP-CLI commands are documented in the plugin's README.

== Installation ==

1. Install and network-activate [Loupe Search](https://wordpress.org/plugins/loupe-search/).
2. Upload the `loupe-cross-site-search` folder to `/wp-content/plugins/`, or install through the Plugins screen.
3. **Network-activate** Loupe Cross-Site Search.
4. Go to **Network Admin → Settings → Cross-Site Search** and set the hub site, participation, index language, and post types.
5. Build the initial index with WP-CLI:

   `wp loupe-cross-site reindex`

== Frequently Asked Questions ==

= Does this work on a single-site install? =

No. It requires a WordPress multisite network. On single site, use Loupe Search directly.

= Do I still need Loupe Search? =

Yes. Loupe Cross-Site Search is an add-on and requires Loupe Search to be active on the network. Sites without a ready Loupe Search index are skipped.

= Which sites are searched? =

By default, all public sites. You can instead choose an allowlist or a blocklist in Network Admin → Settings → Cross-Site Search.

= Where is the search endpoint? =

On the hub site you designate: `POST /wp-json/loupe-cross-site/v1/search`. Search is public and returns published content only.

= How do I keep the index in sync? =

Content is mirrored in real time as posts are published, updated, unpublished, trashed, or deleted. After bulk changes or settings changes, run `wp loupe-cross-site reindex`, and use `wp loupe-cross-site verify --repair` to reconcile any drift.

= Can I use the search block on a subdomain multisite? =

The block queries the hub site's endpoint. On subdomain networks this is a cross-origin request and may be blocked by CORS unless the block is on the hub site (or you add CORS headers). Subdirectory networks are same-origin and work out of the box.

== Changelog ==

= 0.1.0 =
* Initial release: combined cross-site index, in-context mirroring, hub REST endpoint with `blog_id` filter/facet, participation controls, WP-CLI reindex/verify/purge, and a complete Cross-Site Search block (facets, sorting, highlighting, pagination).
