# Combined index over query-time fan-out

Cross-site search maintains a single combined Loupe index holding documents from
every participating site, rather than querying each site's own index at search
time and merging the results.

## Status

accepted

## Considered Options

- **Query-time fan-out** — on each search, query every participating site's own
  Loupe index and merge/re-rank the results in the add-on.
- **Combined index** (chosen) — mirror documents from all sites into one index,
  searched once.

## Consequences

- Native, single-pass ranking and cheap pagination; no N-way merge heuristics.
- Requires keeping the combined index in sync with every site (see ADR 0002)
  and roughly doubles index storage.
- Search latency is independent of the number of participating sites.
