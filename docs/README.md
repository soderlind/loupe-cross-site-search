# Documentation

Documentation for the Loupe Cross-Site Search plugin. Domain vocabulary lives in
[CONTEXT.md](../CONTEXT.md).

## Guides

- [Architecture](architecture.md) — responsibilities, behavior, and constraints of the implemented system.
- [Developer guide](developer.md) — search REST API, example block, WP-CLI commands, extension filters, and tests.

## Architecture decision records

- [ADR 0001 — Combined index over query-time fan-out](adr/0001-combined-index-over-fan-out.md)
- [ADR 0002 — Mirror documents in each site's own context, without switch_to_blog](adr/0002-in-context-mirroring.md)
- [ADR 0003 — Own query/write gateway instead of reusing Loupe Search's engine](adr/0003-own-query-write-gateway.md)
- [ADR 0004 — Single network-level language for the combined index (v1)](adr/0004-single-network-language.md)
- [ADR 0005 — Background reindex via bundled Action Scheduler](adr/0005-background-reindex-action-scheduler.md)
