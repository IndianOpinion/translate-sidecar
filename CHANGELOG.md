# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- Translations stored in their own table, one live and one candidate row per post and language, enforced by a unique key on a generated column where supported and a lock in code.
- Language registry with validation of prefixes, BCP 47 tags, scripts and collisions with pages, bases and rewrite rules.
- Block-level segmenting with placeholder tokens; code, shortcodes, embeds, URLs and `translate="no"` content copied verbatim; alt text and captions translated.
- Guards: completeness, truncation, placeholders, structure, length ratio, unchanged output, numbers, declared and detected language, and a script guard on code point ranges.
- Review screen with side-by-side segments, guard report, diff against the live version, preview at the future URL, approve with warnings, reject, withdraw and rollback over the last three versions.
- Routing of `/xx/slug/` with 302, 301 and 410 answers, value swaps scoped to the queried post, internal link rewriting and a "read the original" link.
- Canonical, hreflang, Open Graph and JSON-LD output in `core`, `filters` or `rewrite` mode.
- Per-language sitemaps through core or the plugin's own routes, cached by build time.
- Adapters: a model adapter on `indianopinion/llm-json-php` (key and model from constants, or your own client by filter), a site caller filter for sites without the library, and a manual adapter.
- Daily call and character caps, kill switch (option, constant, network), credit pause, spend-meter and gen-queue integration.
- Recovery of jobs left running by a process that died mid-call.
- REST API, WP-CLI commands, Site Health tests, dashboard and uninstall for every site.
