# Loupe Cross-Site Search

An add-on to Loupe Search that lets a single query return results from many
sites in a WordPress multisite network, by maintaining one combined search
index across those sites.

## Language

**Cross-site search**:
A single search whose results come from more than one site in the network,
ranked together as one list.
_Avoid_: global search, federated search, network-wide search

**Combined index**:
The single Loupe (SQLite) index this add-on maintains, holding documents copied
from every participating site.
_Avoid_: unified index, global index, network index, aggregate index

**Participating site**:
A subsite whose published content is included in cross-site search. Public sites
(`blog_public = 1`) participate by default; a network-level allowlist/blocklist
overrides this.
_Avoid_: member site, source site, indexed site

**Hub site**:
The single designated subsite that exposes the cross-site search REST endpoint
(and the example search block).
_Avoid_: primary site, main site, search site

**Mirror**:
To copy a participating site's document into the combined index, done in that
site's own request context so its options and filters apply.
_Avoid_: sync, replicate, push, export

**Loupe Search**:
The upstream single-site plugin this add-on depends on; it owns each site's own
per-site index. Cross-site search reuses its indexing and query building.
_Avoid_: base plugin, parent plugin, host plugin
