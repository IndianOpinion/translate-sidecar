# translate-sidecar

Keeps post translations in their own table instead of duplicate posts, routes `/xx/slug/` to them, shares images and schema with the original, emits hreflang and canonical tags, and builds a sitemap per language. Every machine translation passes guards against partial and wrong-script output, and nothing reaches readers until a person approves it and the operator switches the language on.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

**Measured result:** on a large site, finding language-marked posts by joining the post meta table was far too slow for a sitemap, while the same filter over an indexed list of ids was fast. translate-sidecar keeps every translation in one narrow, indexed table, so that class of query never happens.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Hooks and functions](#hooks-and-functions)
- [WP-CLI](#wp-cli)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Contributing](#contributing)
- [Licence](#licence)

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later, MySQL 5.7+ or MariaDB 10.4+, pretty permalinks.

**With Composer.** The model adapter depends on [`indianopinion/llm-json-php`](https://github.com/IndianOpinion/llm-json-php), which is not on Packagist yet. Composer reads repositories only from the root project, so add it to yours first:

```sh
composer config repositories.llm-json-php '{"type": "vcs", "url": "https://github.com/IndianOpinion/llm-json-php", "no-api": true}'
composer require indianopinion/translate-sidecar
```

With `composer/installers` the package lands in `wp-content/plugins/`. Activate it as usual. Activation creates three tables and checks them with `SHOW COLUMNS`. Inside the plugin's own checkout, `composer install` installs the library into `vendor/` and the plugin loads it from there.

**Without Composer.** Download a release, unzip it into `wp-content/plugins/translate-sidecar/` and activate it. The plugin loads its own autoloader (`src/autoload.php`) and, when present, `vendor/autoload.php`. Without llm-json-php the plugin still runs: the manual adapter works, and a model can be plugged in with the `translate_sidecar_llm_caller` filter (see `examples/llm-caller.php`). To use the llm-json-php adapter without Composer, require the library's `bootstrap.php` from a must-use plugin.

**As a must-use plugin.** Not supported in 0.1.0, because must-use plugins get no activation or uninstall hooks. `examples/mu-loader.php` is a loader stub for those who accept that: the schema installs on the first admin request, and you drop the tables yourself on removal.

## Quick start

1. Activate the plugin. Go to **Translations > Settings**.
2. Add a language: a prefix such as `fr`, an hreflang tag, a locale, a "read the original" label in that language. The scripts default from a built-in map of common languages; check them. Publish, index and auto start **off**.
3. Choose an adapter. Out of the box there is the **manual** adapter (human translators). For a language model, define `TRANSLATE_SIDECAR_LLM_KEY` and `TRANSLATE_SIDECAR_LLM_MODEL` in `wp-config.php` (or hand over your own llm-json-php client, see `examples/llm-json-client.php`) and choose **Language model (llm-json-php)**. Sites without the library supply a caller (see `examples/llm-caller.php`) and choose **Site caller**.
4. Open a post. In the **Translations** box, click **Preview cost**, then **Request**. Or run `wp translate-sidecar request 123 --lang=fr`.
5. Wait for a tick (every 5 minutes) or run `wp translate-sidecar run`.
6. Go to **Translations > Review**. Read the side-by-side view and the guard report, preview the page at its future URL, then approve or reject.
7. When a sample looks right, turn **publish** on for the language. Readers can now reach `/fr/slug/`. Pages carry `noindex, follow` until you also turn **index** on. Turn indexing on for one language at a time.

## Configuration

Everything lives on the settings screen (`manage_options`). Constants override settings.

| Setting | Default | What it does |
|---|---|---|
| Languages | none | Prefix, hreflang, locale, names, direction, scripts, original-link label, optional ratio bounds and adapter |
| Per-language `publish` | off | Routes `/xx/slug/`; off means a 302 to the original |
| Per-language `index` | off | Hreflang, sitemap and indexing; off means `noindex, follow` |
| Per-language `auto` | off | Queue a job when an eligible post is published or a live translation goes stale |
| Source language | site locale | BCP 47 tag of the originals; a post can override it in its box |
| Source language prefix | none | Only to route translations of posts written natively in another language |
| Post types | `post` | Also the `translate_sidecar_post_types` filter |
| Minimum source words | 150 | Characters divided by 4 for scripts written without spaces |
| Maximum segments per job | 200 | Longer posts are refused `too_long`, so one post cannot eat the cap |
| Source characters per call | 6000 | Segments are grouped up to this size; a bigger segment goes alone |
| Adapter | none | `manual`, `llm-json`, `caller`, or any adapter added by filter |
| Daily call cap | 100 | Counted before each call; `0` means no calls; `TRANSLATE_SIDECAR_DAILY_CAP` overrides |
| Daily character cap | off | Optional second cap |
| Kill switch | off | Stops every call; also `TRANSLATE_SIDECAR_DISABLED`, and a network option on multisite |
| `stale_policy` | `hold_major` | `serve`, `hold_major` (title change or over 20% of segments) or `hold` |
| Translate alt text | on | Image alt and captions as their own segments |
| `numbers_guard` | `warn` | `warn` or `fail` when numbers go missing |
| Script share, title / body | 0.70 / 0.60 | Minimum share of the target script |
| Third-script ceiling | 0.05 | Maximum share of letters from any other non-Latin script |
| Length ratio | 0.4 to 3.0 | Letters in the translation over letters in the source |
| Unchanged ceiling | 0.20 | Share of segments allowed to come back unchanged |
| `head_mode` | detect | `core`, `filters` or `rewrite`; detect picks `filters` when another plugin prints a canonical |
| Sitemap URLs per file | 2000 | Hard ceiling 50,000 |
| `sitemap_alternates` | off | `xhtml:link` alternates in sitemap entries |
| Tick | 5 min, 5 jobs, 50 s | Interval, jobs per tick, time budget |
| `use_queue` | off | Hand jobs to `gen-queue` when it is present |
| Retention | 30 days | Failed and rejected rows; withdrawn rows are kept for their 410 |
| Prior versions | 3 | Kept for rollback |
| Self-approval | on | Also `translate_sidecar_allow_self_approval` |
| Do-not-translate terms | none | Names and brands the model must keep |

API keys never live in an option. The `llm-json` adapter reads these constants, or environment variables of the same name:

| Constant | What it does |
|---|---|
| `TRANSLATE_SIDECAR_LLM_KEY` | API key for the provider |
| `TRANSLATE_SIDECAR_LLM_MODEL` | Model id, or a comma-separated fallback chain (`a/model, b/model`) |
| `TRANSLATE_SIDECAR_LLM_BASE_URL` | Optional. Any chat completions server; OpenRouter when unset |

The adapter built from them makes one attempt per model and gives up after two minutes, because the plugin's own backoff handles retries. For anything else (your own chain, a file or object cache store, a logger) return an `IndianOpinion\LlmJson\Client` from `translate_sidecar_llm_client`. A Site caller reads its keys however you write it.

## How it works

- **Storage.** One row per translation in `{prefix}translate_sidecar_rows`, keyed on post and language, with states `queued`, `running`, `failed`, `candidate`, `live`, `rejected`, `withdrawn` and `prior`. At most one live and one candidate row per post and language: a unique key on a generated column where the database supports it, and a lock in code everywhere. A translation memory table and a counters table complete the schema. No post is ever created per language and nothing joins post meta.
- **Segmenting.** The body is cut at block level. Inline tags become numbered placeholders such as `{1}text{/1}`; URLs, attributes, block comments, code, shortcodes, embeds and anything marked `translate="no"` never reach the model. The markup between segments is kept byte for byte.
- **Guards.** Every call must return every segment once, untruncated, with its placeholders. The assembled result must keep the structure, stay inside the length ratio, not come back unchanged, keep its numbers, match the declared and detected language, and be written in the target script. Any failure fails the whole job and stores nothing.
- **Review.** Candidates wait on the review screen with the guard report and a diff against the live version. Approving keeps the previous live version for rollback and feeds the translation memory.
- **Routing.** `/xx/slug/` renders the original post's template with the title, body, excerpt, document title, `lang` and `dir` swapped, only for the queried post. Images, author, dates, comments and categories stay the original's. No translation yet: 302 to the original. Withdrawn, or the original trashed: 410. Native post on its own prefix: 301 to its URL.
- **Head.** A self-referencing canonical, one hreflang cluster printed identically on every member with `x-default`, `og:locale` and alternates, and JSON-LD with `inLanguage` and `translationOfWork`.
- **Sitemaps.** One core sitemap provider with a subtype per indexable language, or the plugin's own `/translations-sitemap.xml` when core sitemaps are off.

**The script guard proves the script, not the language.** Several languages share a script. A wrong language in the right script is caught only by the unchanged check, the language the adapter declares, and a detector you plug in with `translate_sidecar_detect_language`.

Guards reduce, not remove, the risk of instructions hidden in source text steering the model. Review is the control.

## Hooks and functions

Functions: `translate_sidecar_request()`, `_get()`, `_url()`, `_cluster()`, `_current_language()`, `_languages()`, `_approve()`, `_reject()`, `_withdraw()`, `_rollback()`, `_run()`, `_run_job()`, `_guard()`, `_script_shares()`, `_is_enabled()`, `_cap_remaining()`, `_language_switcher()`.

Actions: `translate_sidecar_job_queued`, `_job_failed`, `_candidate_ready`, `_approved`, `_rejected`, `_withdrawn`, `_purge_urls` (every URL of the cluster, for your cache), `_capped`, `_tick_finished`, `_run_job` (run one job by id, for external queues).

Filters: `translate_sidecar_languages`, `_post_types`, `_is_eligible`, `_source_fields` (feed your SEO title and description), `_translators`, `_llm_client`, `_llm_caller`, `_prompt`, `_glossary`, `_segment_budget`, `_max_segments`, `_verbatim_blocks`, `_guard_thresholds`, `_script_ranges`, `_detect_language`, `_major_change_share`, `_cluster`, `_x_default`, `_canonical`, `_head_values`, `_schema_graph`, `_sitemap_max_urls`, `_sitemap_routes`, `_allow_self_approval`, `_capability`, `_original_link`, `_spend_allow_reasons`, `_retention_days`, `_memory_days`.

REST, under `translate-sidecar/v1`: `GET /posts/{id}/translations` (public, routed only), `POST /posts/{id}/translations`, `GET /rows/{id}`, `POST /rows/{id}/approve|reject|withdraw|rollback`.

The `llm-json` adapter maps llm-json-php's failures onto the plugin's: an exhausted credit pauses calls until the next day, a truncated reply fails `truncated`, a reply that is not the JSON shape fails `bad_shape`, a refusal or a bad key fails at once, and rate limits, server errors and timeouts retry on the backoff schedule.

For sites without llm-json-php, a model caller has the signature `fn(string $system, string $user, array $jsonSchema, array $meta): array` and returns the decoded JSON. Throw `TranslatorException` (`fromHttpStatus()` maps 429 and 5xx to retry and 402 to a credit pause).

## WP-CLI

```sh
wp translate-sidecar languages [validate]
wp translate-sidecar request <post-id>... --lang=<xx>[,<yy>] [--replace] [--dry-run]
wp translate-sidecar run [--max=<n>] [--time=<s>]
wp translate-sidecar status [--lang=<xx>]
wp translate-sidecar approve <row-id> [--with-warnings --reason=<text>]
wp translate-sidecar reject <row-id> [--reason=<text>]
wp translate-sidecar withdraw <row-id>
wp translate-sidecar rollback <post-id> --lang=<xx>
wp translate-sidecar guard <row-id>
wp translate-sidecar sitemap rebuild [--lang=<xx>]
wp translate-sidecar verify-schema
wp translate-sidecar purge-language <xx> --yes
```

Exit codes: 0 ok, 1 error, 2 refused by the cap, the kill switch or the spend meter. A real system cron can replace WP-Cron: `*/5 * * * * wp translate-sidecar run`.

## Limitations

- Only post text is translated: title, body, excerpt, alt text and the SEO title and description you feed in. Menus, widgets, terms, archives, feeds and theme strings stay in the site language.
- Translations live at the original slug under the prefix. No translated slugs.
- The language home (`/xx/`) redirects to the site home in 0.1.0.
- Classic `[caption]` text is translated; the text inside any other shortcode is not.
- With core sitemaps on, an empty language is answered by core, which sends 404 for an empty page.
- When the original is deleted permanently its rows go with it, so its translation URLs answer 404, not 410.
- Running another multilingual plugin at the same time is unsupported.

## FAQ

**Why not one post per language?** Copies multiply every table and drift from the original. A translation here is a view of the original with its text swapped.

**Does it redirect by browser language?** No. The language is in the URL and only there.

**Does it work behind a page cache or CDN?** Yes. Each language is its own path. Hook `translate_sidecar_purge_urls` to purge, because a change to one member changes the hreflang block on all of them.

**Can it publish on its own?** No. Every translation is a candidate until a reviewer approves it, and every language starts with publish and index off.

**What does it send to the provider?** Post text only. No author names, e-mail addresses, comments or user data.

**My SEO plugin prints its own canonical.** Use `filters` mode and wire the values from `translate_sidecar_head_values` into its filters, or `rewrite` mode to have them edited in place.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
