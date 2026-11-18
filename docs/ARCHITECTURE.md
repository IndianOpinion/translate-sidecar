# Architecture

This document describes how translate-sidecar 0.1.0 is built, how data
flows through it, and every decision taken while building it from
[PRD.md](PRD.md), including the rulings on the PRD's open questions.

## Modules

All code lives under `src/`, namespace `IndianOpinion\TranslateSidecar`.

| Module | Classes | Role |
|---|---|---|
| Container | `Plugin` | Builds every service, wires hooks, activation, cron |
| Settings | `Settings` | One autoloaded option with defaults; kill switch and credit pause read fresh |
| Languages | `Language\Language`, `Language\Registry` | Registry entries, validation, collisions, the source-language entry |
| Posts | `Posts` | Source fields, source language, eligibility and refusal reasons |
| Scripts | `Script\ScriptTable`, `Script\ScriptCounter` | Code point range table, letter counts and shares |
| Segments | `Segment\Segmenter`, `Segment\Reassembler`, `Segment\Segment`, `Segment\SegmentedPost`, `Segment\SourceFields`, `Segment\Assembled` | Cut a post into segments, put translations back |
| Guards | `Guard\Guards`, `Guard\GuardReport`, `Guard\Thresholds` | Every check of section 4.6; pure |
| Adapters | `Translator\*` | Interface, request and response, `LlmJsonTranslator`, `CallerTranslator`, `ManualTranslator`, registry |
| Storage | `Storage\Store`, `Storage\WpdbStore`, `Storage\MemoryStore`, `Storage\Schema`, `Storage\Row` | Tables, verification, rows, memory, counters, locks |
| Jobs | `Job\Requester`, `Job\Runner`, `Job\Gate`, `Job\Plan`, `Job\GuardsFactory`, `Job\RunSummary` | Requests, ticks, cap, kill switch, spend meter, queue bridge |
| Review | `Review\Review` | Approve, reject, withdraw, roll back, manual translations |
| Read side | `Translations`, `Purger`, `Lifecycle` | Live rows per request, staleness, URLs, clusters, purges, original post changes |
| Front end | `Front\Router`, `Front\Decision`, `Front\Frontend`, `Front\Preview` | Routing decisions, value swaps, preview tokens, switcher |
| Head | `Head\Head`, `Head\StructuredData` | Canonical, hreflang, Open Graph, robots, JSON-LD, head modes |
| Sitemaps | `Sitemap\Sitemaps`, `Sitemap\Cache`, `Sitemap\CoreProvider` | Core provider, own routes, cached lists |
| Admin | `Admin\Admin`, `Admin\Actions`, `Admin\Dashboard`, `Admin\SiteHealth` | Screens, actions behind nonce and capability, numbers, Site Health |
| REST and CLI | `Rest\Controller`, `Cli\Command` | `translate-sidecar/v1` and `wp translate-sidecar` |
| Support | `Support\Text`, `Support\Clock`, `Support\Log` | Multi-byte text, time, one-line job log |

`src/functions.php` holds the public functions of PRD section 5.2.

## Data flow

1. **Request.** An editor, the REST API, WP-CLI, a bulk action or the
   auto rule calls `Requester::request()`. It resolves the language,
   applies the refusals of cases 8 to 12, segments the post, applies the
   segment ceiling, builds a plan (memory hits plus calls grouped by the
   character budget), and either reports a dry run or inserts a `queued`
   row. With `use_queue` on and gen-queue present, the row is parked for
   the external queue.
2. **Tick.** WP-Cron (or `wp translate-sidecar run`) calls
   `Runner::tick()`. It takes the `tick` lock, checks the gate, and runs up
   to N due jobs inside the time budget.
3. **Job.** `Runner` moves the row `queued` to `running` with a
   conditional update, re-checks eligibility, re-segments the current
   source, and pre-checks that the cap allows every call. Before each call
   it checks the kill switch and credit pause, counts the call (and its
   characters) atomically, and asks the spend meter. Each response passes
   the response guards. After all calls, `Reassembler` rebuilds the body,
   strips markup from model output, escapes it, restores placeholders and
   runs `wp_kses_post`. The assembled guards run. The source hash is read
   again; a change discards the job. Only then, under the post and
   language lock, the old candidate becomes `rejected` (`replaced`) and the
   job row becomes the `candidate`.
4. **Review.** `Review::approve()` moves the candidate to `live` under the
   lock with a conditional update, turns the old live row into a `prior`,
   keeps the newest three priors, writes the segments into the memory,
   then `Purger` fires `translate_sidecar_purge_urls` for the whole
   cluster, cleans the post cache and marks the language's sitemap dirty.
5. **Request for `/xx/slug/`.** The rewrite rule sets `ts_lang` and
   `ts_path`. The `request` filter asks `Router::decide()`. A 200 maps the
   query to the original post; anything else becomes a 404 query that
   `template_redirect` turns into the redirect or 410. `Frontend` swaps
   values only for the queried post; `Head` prints the head block for the
   active mode.

## Rulings on the open questions

1. **`stale_policy` defaults to `hold_major`** (ruling 1). A correction to
   the original matters more than the uptime of a translation that now
   says something else. Small edits keep serving; a new title or a change
   to more than 20% of segments holds the translation (302) until a new
   one is approved. The PRD's configuration table said `serve`; the ruling
   wins.
2. **Keep the last three versions** per post and language for rollback
   (ruling 2). Superseded live rows get the state `prior` (an eighth state
   added to the PRD's seven, with `is_prior = 1`). Rollback restores the
   newest prior and sets the current live row aside as `rejected`
   (`rolled_back`), so repeated rollbacks walk back through the history.
   Older priors are marked `rejected` (`expired_prior`) and retention
   removes them. The count is the `keep_versions` setting (default 3).
3. **The language home waits** (ruling 3). `/xx/` answers 302 to the site
   home; a listing page is a roadmap item. The `^xx/?$` rule is not added
   when a top-level page owns that slug, so the page still renders
   (case 93).
4. **Script guards use code point ranges only** (ruling 4).
   `ScriptTable` holds the ranges; `ScriptCounter` binary-searches them.
   Letter and mark detection uses the general categories `\p{L}` and
   `\p{M}` (not script properties). A mark outside every range takes the
   script of the letter before it; a letter outside every range counts as
   `Other`, which is a third script. `tests/Unit/ScriptGuardTest.php` is
   table-driven: one sample per script in the table, a check that every
   table entry has a sample, and the fixtures of acceptance test 45.
5. **`head_mode` detection inspects `wp_head` callbacks** (ruling 5); no
   loopback request. An SEO plugin is assumed when core's `rel_canonical`
   was removed by someone else, or when a callback from outside this
   plugin has `canonical`, `opengraph` or `seo` in its function, method or
   class name. The result is cached for the request, because this plugin
   itself removes `rel_canonical` on translation pages in `core` mode.
6. **Uniqueness by generated column plus code lock** (ruling 6). After
   `dbDelta`, `Schema::addSlot()` adds `slot` as
   `CASE WHEN state IN ('live','candidate') THEN state ELSE NULL END`
   (virtual) and a unique key on `(post_id, lang, slot)`. NULLs never
   collide, so other states are free. If the database refuses, the option
   `translate_sidecar_unique_slot` is 0 and the per post and language lock
   alone enforces the rule. `slot` is not in the verified column list, so
   its absence never blocks writes. `SHOW COLUMNS` verification runs after
   every install; a missing column stores the error, withholds the schema
   version, blocks every write with `SchemaException`, shows an admin
   notice, fails Site Health and makes `verify-schema` exit 1 naming the
   table and column. Verified against MySQL 8.0 in
   `tests/Integration/WpdbStoreTest.php`.
7. **Translated alt text lives in the row's JSON map** (ruling 7), keyed by
   attachment id (from `wp-image-N` or `data-id`). The map serves images
   outside the body, such as the featured image, through
   `wp_get_attachment_image_attributes`. The translated alt is also written
   into the stored body so the body is complete on its own, including
   images without an attachment id.
8. **A configurable ceiling on segments per job, default 200** (ruling 8).
   Setting `max_segments` and filter `translate_sidecar_max_segments`. A
   longer post is refused `too_long` at request time and failed `too_long`
   if it grew while queued. This narrows case 99: such a post no longer
   translates in several calls unless the operator raises the ceiling.

## Other decisions

- **llm-json-php is optional, not required.** The model adapter takes its
  transport from the `translate_sidecar_llm_caller` filter and registers
  as `caller`. `LlmJsonTranslator` holds the prompt, schema and reply
  parsing and accepts any callable; `CallerTranslator` is the same class
  registered from the filter. `composer.json` lists llm-json-php under
  `suggest` only: a `vcs` repository entry made every `composer install`
  clone it, which fails without GitHub credentials. The README shows how
  to add it. Without it the plugin runs with any caller.
- **Caller reply metadata.** A caller may add `_meta` with
  `finish_reason`, `provider`, `model` and `usage`. Exceptions other than
  `TranslatorException` from a caller are treated as retryable.
- **Source SEO fields** come from the `translate_sidecar_source_fields`
  filter (empty by default), so a site can feed the SEO title and
  description its SEO plugin stores, without this plugin reading another
  plugin's data.
- **Extra column `created_by`** on rows records who requested a job or
  typed a manual translation; self-approval (case 62) compares against it.
- **`segment_hashes` holds a JSON list of `{id, hash, text}`**: the hash for
  staleness and memory, the text so approval can feed the memory and the
  review screen can diff against the live row.
- **Memory "last used"** is refreshed whenever an approval writes the
  segment again; entries not approved again for 180 days are pruned.
- **Inline code, inline elements marked `translate="no"`, URLs, e-mail
  addresses, registered shortcodes and literal text that looks like a
  placeholder** become atom placeholders `{n/}` and are restored verbatim.
  Classic `[caption]` shortcodes are boundaries, so caption text is
  translated. Verbatim blocks: `core/code`, `core/preformatted`,
  `core/shortcode`, `core/embed`, any block with `"translate":false` or a
  `notranslate` class, filterable with `translate_sidecar_verbatim_blocks`.
- **Classic content is run through `wpautop`** before segmenting, so
  paragraphs exist; the stored translation carries `<p>` tags.
- **The unchanged rule** exempts segments under four words, segments
  without letters, URLs, and segments made only of do-not-translate
  terms. The title is checked on its own and only the last three
  exemptions apply to it.
- **Language tags match** when equal ignoring case, or when one is the
  other plus more subtags (`pt` matches `pt-BR`; `pt-PT` does not).
- **Spend meter protocol.** Present when `spend_meter_pre_spend` has a
  callback. The call is made only when the answer is an array with
  `allowed === true` and no reason or a reason in `ok`, `allowed`,
  `within_budget` (filterable). Any reservation is followed by exactly one
  `spend_meter_record` (success) or `spend_meter_release` (failure or
  deny).
- **gen-queue bridge.** `gen_queue_enqueue_job` is called with the type,
  `{job_id, post_id, lang}` and `{idempotency_key: post:lang:hash}`. A
  non-null answer parks the row (next attempt in the far future) and the
  queue runs it with the action `translate_sidecar_run_job` or the function
  `translate_sidecar_run_job()`. The cap and kill switch apply either way.
- **Character cap** is checked before the call cap; a refused call refunds
  the characters it reserved. The counters column is signed for that.
- **Backoff**: retryable errors wait 5 minutes, 30 minutes, 2 hours and 12
  hours, then fail `provider_error` on the fifth failure. HTTP 402 is an
  exhausted credit: calls pause until the next local midnight.
- **Locks** are option rows taken with `INSERT IGNORE` and taken over when
  expired with a compare-and-set update. They work on every MySQL setup
  and survive across connections, unlike `GET_LOCK`.
- **Deleted originals answer 404**, not 410: case 89 deletes the rows, and
  without a row there is nothing that remembers the slug. Trashed
  originals answer 410 (WordPress renames them `slug__trashed`, which the
  router looks up).
- **Empty languages under core sitemaps** are answered by core, which
  sends 404 for an empty page. The plugin's own routes answer an empty
  `urlset` with 200 (case 75).
- **Sitemap failures** are remembered for an hour; the language is left
  out of the index and its file answers 503 with `Retry-After: 600`.
- **Preview tokens** are an HMAC of user, row and expiry with the nonce
  salt, valid for one hour. An invalid, expired or capability-less request
  gets the normal response.
- **Must-use loading** stays unsupported as the PRD says;
  `examples/mu-loader.php` documents the trade-offs for those who want it.
- **Uninstall** deletes plugin options and post meta by prefix with
  prepared `LIKE` queries, drops the tables, clears events and the
  rewrite rules option, on every site of a network.

## Testing

- `tests/stubs/` is a small WordPress stand-in (hooks, options, posts,
  meta, permalinks, nonces, capabilities, multisite switching).
- `tests/Unit/` maps every acceptance test of PRD section 12 to a test
  method; each docblock names the test number.
- `tests/Integration/WpdbStoreTest.php` runs the database store against a
  real MySQL or MariaDB when `TS_MYSQL_SOCKET` or `TS_MYSQL_HOST` is set,
  and is skipped otherwise. No test uses the network.
