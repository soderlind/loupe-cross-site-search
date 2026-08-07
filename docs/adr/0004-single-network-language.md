# Single network-level language for the combined index (v1)

The combined index is configured with one network-level language (a Network
Admin setting, defaulting to the hub/main-site locale), rather than honoring each
participating site's own locale.

## Status

accepted

## Considered Options

- **Single network language** (chosen) — one language/typo-tolerance config for
  the whole combined index.
- **One combined index per language** — keyed by each site's locale.

## Consequences

- Simple: one index per post type, one analyzer config.
- Mixed-language networks get best results only for the configured language;
  tokenization/typo tolerance is suboptimal for other languages.
- Reversing to per-language indexes means rebuilding the combined index, so the
  choice is not cheap to change later.
