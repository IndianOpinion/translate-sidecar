# PRD: translate-sidecar

Status: draft for review. Version 0.1.0. Plugin type: WordPress plugin.

Written from scratch; not derived from any third-party plugin.

One line: keeps post translations in their own table instead of duplicate
posts, routes `/xx/slug/` to them, shares images and schema with the
original, emits hreflang and canonical tags, and builds a sitemap per
language. Guards against partial and wrong-script translations.

Throughout this document `xx` stands for any language prefix the operator
configures. No language is built in or enabled by default.

## 1. Problem

The common way to translate a WordPress post is to create a second post for
each language and link the copies together. That has four costs.

- Every language multiplies the posts table, the post meta table, the
  attachment links and the revisions. Queries that were cheap become slow,
  and every plugin that loops over posts now loops over copies.
- The copies drift. A correction to the original does not reach the copies,
  a featured image changed on the original stays old on the copies, and
  structured data on each copy describes a separate article instead of a
  translation of one.
- Machine translation fails in ways that look fine at a glance. A model
  stops halfway through and the copy ends mid-sentence. A model answers in
  the source language, or in a neighbouring script, and the page is
  published under the wrong language code. A model drops a paragraph and
  nobody notices.
- Search engines are told about every copy at once. Announcing a large set
  of thin machine-translated pages in one go risks a site-wide ranking
  demotion.

A lesson from production: a sitemap query that found language-marked posts
by joining the post meta table was far too slow on a large site, while the
same filter run against an indexed list of ids was fast. Keeping
translations in one narrow, indexed table avoids that class of query
entirely.

translate-sidecar stores each translation as a row keyed on post and
language, serves it at `/xx/slug/` as a view of the original post, reuses
the original's images, author, dates and schema, emits correct canonical and
hreflang tags, builds one sitemap per language, and refuses to publish a
translation that is partial or in the wrong script. Nothing reaches the
public until a person approves it and the operator has switched that
language on.

## 2. Goals and non-goals

Goals:

1. Store translations of posts in a dedicated table, one live row and at
   most one candidate row per post and language. Never create a post per
   language.
2. Route `/xx/slug/` (and `/xx/parent/child/` for hierarchical types) to
   the original post rendered with the translated title, excerpt and body.
3. Share images, author, dates, categories, comments and structured data
   with the original. Translate only text.
4. Emit a self-referencing canonical, an `hreflang` cluster that is the
   same on every member, `og:locale` and `og:locale:alternate`, and the
   `lang` and `dir` attributes on `<html>`.
5. Build one sitemap per language, through the WordPress core sitemap
   system when it is on, or through the plugin's own route when it is off.
6. Translate through an adapter interface that any LLM or machine
   translation API can implement. Ship a model adapter that uses
   `indianopinion/llm-json-php` when present, a filter so a site can plug
   in its own caller without that library, and a manual adapter for human
   translators.
7. Guard every machine translation: completeness, structure, placeholder
   integrity, length ratio, untranslated output, and script, using Unicode
   ranges.
8. Hold every translation for review. Publish per language behind two
   switches: one that makes the routes live, and one that tells search
   engines.
9. Cap model calls per day and provide a kill switch, both in the plugin's
   own settings, and honour `gen-queue` and `spend-meter` when present.

Non-goals:

- No duplicate posts, ever, and no import or export of other multilingual
  plugins' data. A migration tool is out of scope for 0.1.0.
- No translation of menus, widgets, theme strings, taxonomy terms, archive
  pages, author pages, search results or feeds. The site chrome stays in
  the site language. Gettext (`.po`/`.mo`) is the right tool for chrome.
- No translated slugs. A translation lives at the original slug under the
  language prefix.
- No redirect by `Accept-Language`, cookie or IP. The language is in the
  URL and only in the URL.
- No automatic language identification beyond the script and unchanged
  checks in section 4.6. A site can plug in its own detector.
- No front-end language picker widget beyond a small optional template tag
  and block (case 63). Theming is the site's job.
- No custom post type for translations and no translation of custom fields
  in 0.1.0, except the excerpt, the SEO title and the meta description
  this plugin owns.
- No WooCommerce, no forms, no e-mail templates.
- No vendor-specific adapter for any third-party multilingual or SEO
  plugin. Integration is through this plugin's own filters (section 5).

## 3. Users and setting

- **Operator**: installs the plugin, registers languages, chooses an
  adapter, sets the cap, and flips the per-language switches. Holds
  `manage_options`.
- **Editor or translator**: requests translations, reviews candidates,
  edits text, approves, rejects or withdraws. Holds the review capability
  (default `edit_others_posts`).
- **Reader**: visits `/xx/slug/` and sees the post in that language, with a
  link to the original.
- **Search engine crawler**: reads canonical, hreflang and the per-language
  sitemap.

Setting: a single WordPress site or a multisite network (settings, tables,
cap and switches are per site), pretty permalinks, PHP 8.1 or later,
WordPress 6.4 or later. Usually behind a page cache or a CDN. Translation
jobs run on WP-Cron or on a real system cron that calls WP-CLI.

## 4. Behaviour

### 4.1 Language registry

1. The operator registers languages on the settings screen or through the
   `translate_sidecar_languages` filter. Each entry has:
   - `prefix`: the URL segment, lower case, matching
     `^[a-z]{2,3}(-[a-z0-9]{2,8})?$`.
   - `hreflang`: a BCP 47 tag, for example `fr` or `pt-BR`. It may differ
     from the prefix (prefix `pt-br`, hreflang `pt-BR`).
   - `locale`: a WordPress or Open Graph locale such as `fr_FR`, used for
     `og:locale`.
   - `name` (in the site language) and `native_name`.
   - `dir`: `ltr` or `rtl`.
   - `scripts`: one or more script names from the built-in table in
     section 4.6 (for example `Latin`, `Cyrillic`, `Arabic`). Defaults
     from a built-in map of common language codes to scripts; the
     operator may override it.
   - `publish`: off by default (case 33).
   - `index`: off by default (case 35).
   - `auto`: off by default (case 16).
   - `original_link_label`: a static label for the "read the original"
     link (case 31). Defaults to a neutral English label; the operator
     sets the translated text. No model call ever writes it.
2. Validation refuses a language when: the prefix is malformed; the prefix
   or the hreflang tag is already registered; the hreflang tag is not a
   well-formed BCP 47 tag or is `x-default`; the prefix equals the site's
   own source language prefix (case 5); the prefix collides with a
   top-level page slug, a registered rewrite base (category, tag, author,
   search, feed, `wp-json`, `wp-admin`, `wp-content`, `wp-includes`,
   `wp-login.php`), or an existing rewrite rule that this plugin did not
   add. The error names the collision.
3. A language with no `scripts` entry cannot be saved unless the operator
   explicitly sets "no script guard" for it. The settings screen explains
   that this disables case 49 for that language.
4. Removing a language from the registry stops its routes, head tags and
   sitemap at once. Its rows stay in the table until the operator purges
   them from the settings screen or with WP-CLI, so a slip can be undone.
5. The site's source language is the site locale by default, with its own
   hreflang tag, and can be set on the settings screen. A post may carry a
   different source language in the plugin's post meta (case 12).
6. Saving the registry flushes rewrite rules once, on the next request, not
   on every page load.

### 4.2 Which posts can be translated

7. Translatable post types default to `post`. The filter
   `translate_sidecar_post_types` adds or removes types. Hierarchical
   types are routed by full path (case 22).
8. A post is eligible when it is published, not password protected, not
   private, and the filter `translate_sidecar_is_eligible` does not
   return false. Drafts, scheduled posts, private posts and protected
   posts are never sent to an adapter.
9. A minimum source length applies (default 150 words of body text after
   stripping markup; counted as whitespace-separated tokens, or as
   characters divided by 4 for scripts written without spaces). Shorter
   posts are refused with reason `too_short`. A thin page multiplied by
   every language is the content search engines demote.
10. A post can be excluded with a per-post "do not translate" flag in the
    editor sidebar. Excluded posts refuse new jobs; existing live rows keep
    serving until withdrawn, and the settings screen shows them.
11. Requesting a translation into the post's own source language is refused
    with reason `same_language`.
12. A post written in a registered non-source language (its source language
    meta names that language) is an original in that language. It is not
    moved and not copied. Its hreflang entry uses its own language tag. Its
    translations go into the other registered languages and the site source
    language, and a translation into the site source language is routed at
    the source language prefix only if the operator has registered one;
    otherwise it is not routable and the request is refused with reason
    `no_route`.

### 4.3 Requesting translations

13. An editor requests a translation from the post editor sidebar, a row
    action in the posts list, a bulk action, the REST endpoint or WP-CLI.
    Each request names a post and one or more languages.
14. A request creates or reuses a job keyed on post, language and the hash
    of the source content. Requesting the same thing twice while a job is
    queued or running is a no-op that reports the existing job.
15. A request while a candidate already waits for review asks for
    confirmation (or `--replace` on the CLI). Replacing discards the old
    candidate only when the new one is produced, never before.
16. With `auto` on for a language, publishing an eligible post queues a job
    for that language. Updating a published post queues one only when the
    post is stale (case 39) and the change policy says so.
17. A dry run (`--dry-run` on the CLI, a preview button in the sidebar)
    reports the segments, the number of calls the job would make, and
    whether the cap allows it today. It makes no call, writes no row and
    does not move the cap counter.

### 4.4 Segmenting and reassembly

18. The body is parsed with the block parser when it contains block
    markup, and as classic HTML otherwise. Text is cut into segments at
    block level: paragraph, heading, list item, quote, table cell, caption,
    button text and similar. Each segment has a stable id and a hash of its
    normalised source text.
19. Inline markup inside a segment (`a`, `strong`, `em`, `code`, `br`,
    `sup`, `sub`, `span` and the like) is replaced with numbered
    placeholder tokens before sending and restored after. URLs, `href`,
    `src`, class names, block comments and block attributes never go to
    the adapter.
20. Not translated, ever: `pre` and `code` blocks, shortcodes and their
    attributes, embeds, HTML blocks marked "do not translate", URLs, e-mail
    addresses, and any text inside an element with `translate="no"`. These
    are copied verbatim. A shortcode's output is translated only if it is
    plain text produced at render time, which the plugin cannot see, so it
    is left alone.
21. Images are shared. The same attachment, the same `src`, `srcset` and
    sizes are kept. Image `alt` text and figure captions are translated as
    their own segments when the "translate alt text" setting is on
    (default on). The attachment's own alt in the media library is never
    changed.
22. The title, the excerpt (when the post has one), and the SEO title and
    meta description fields are their own segments. When the original has
    no manual excerpt, the translated excerpt is generated from the
    translated body by trimming (case 30), not by a separate call.
23. Segments are grouped into calls up to a size budget (default 6,000
    source characters per call, filterable). A segment larger than the
    budget goes alone. Calls of one job may run in sequence only; the job
    is complete when every call has returned and passed the guards.
24. A translation memory keeps the last accepted translation of each
    segment hash per language. On a re-translation, unchanged segments are
    taken from memory and only changed or new segments are sent. Memory
    entries are reused only when they came from an approved translation.
25. Reassembly puts translated text back into the original structure,
    restores placeholders and verbatim parts, then sanitises the result
    with the same rules as post content (`wp_kses_post`) before it is
    stored. Model output is untrusted input.

### 4.5 Routing and rendering

26. `/xx/slug/` resolves when: `xx` is a registered language with
    `publish` on; the slug resolves to a post of a translatable type; the
    post is published and publicly viewable; and a live approved
    translation exists for that post and language. The response is 200 and
    renders the original post's template with the swaps in cases 27 to 31.
27. Swaps, scoped strictly to the queried post in the main query:
    - The title, through `the_title`, only when the post id equals the
      queried object and the call is for the main post. Titles of other
      posts in menus, widgets and related lists stay as they are.
    - The body, through `the_content` at an early priority so every later
      consumer (embeds, shortcodes, related links) works on the
      translated body.
    - The excerpt, through `get_the_excerpt`, for the queried post only.
    - `<html lang="…" dir="…">`, through `language_attributes`.
    - The document title, through `pre_get_document_title` and
      `document_title_parts`, using the SEO title when present.
28. Internal links in the translated body that point at another post of a
    translatable type are rewritten to that post's `/xx/` URL when it has a
    routed translation in the same language, and left pointing at the
    original otherwise.
29. Comments shown on a translation page are the original post's comments.
    The comment form posts to the original post. No comment is translated.
30. When a field has no translation (no excerpt, no SEO title, no meta
    description), the fallback is derived: SEO title from the translated
    title plus the site name; meta description from the translated excerpt
    or body, trimmed to 155 characters by grapheme, never by byte, and cut
    at a word boundary where the script has spaces.
31. Each translation page carries a link to the original, with the static
    per-language label, placed after the body. It is not shown when the
    original is in the same language as the page.
32. `/xx/slug/` for a post that is eligible and published but has no live
    approved translation answers 302 to the original. The URL may become
    real later, so it is not a 404. The 302 is not cached for longer than
    the page cache's short default (`Cache-Control: max-age=300`).
33. With `publish` off for a language, every `/xx/…` URL answers 302 to the
    original, no head tag names that language, and no sitemap lists it.
    Readers lose nothing. This is the state of every new language.
34. `/xx/slug/` for a post whose own source language is `xx` answers 301 to
    the post's original URL. That URL never redirects back, so there is no
    loop.
35. With `publish` on and `index` off, translation pages render with
    `noindex, follow` in the robots meta and an `X-Robots-Tag` header,
    are left out of every hreflang cluster, and their sitemap answers 404.
    Canonical, `og:url`, `og:locale`, titles and descriptions still
    describe the page itself, so social cards and other agents read it
    correctly.
36. `/xx/slug/` where the translation was live and has been withdrawn, or
    where the original was trashed or deleted after a translation went
    live, answers 410 Gone. A slug that never had a live translation is
    case 32.
37. When the original's slug changes, the old `/xx/old-slug/` answers 301
    to `/xx/new-slug/`, using the old slugs WordPress already records for
    the original.
38. `/xx/` alone (the language home) is optional and off by default. When
    on, it lists the latest routed translations in that language with
    status 200, through the theme's home or archive template, and it must
    not be treated as a 404 by the theme or by SEO plugins (the plugin
    sets the query flags and the status explicitly). When off, `/xx/`
    answers 302 to the site home.

### 4.6 Guards

Every machine translation passes all guards before it becomes a candidate.
A failed guard marks the job `failed` with a machine-readable reason and the
detail. Nothing from a failed job is stored as a candidate or written to
the translation memory.

39. Staleness: each live row stores the source hash it was made from. When
    the original's title, body or excerpt changes, the row is stale. The
    change policy (setting `stale_policy`) decides what readers see:
    - `serve` (default): keep serving the live translation, show it as
      stale in the admin, and queue a re-translation when `auto` is on.
    - `hold_major`: as `serve`, unless the title changed or more than 20%
      of segments changed (filterable), in which case the translation stops
      routing (case 32 behaviour) until a new one is approved.
    - `hold`: any change stops routing until a new one is approved.
40. Completeness: every segment id sent must come back exactly once, with
    no extra ids. A non-empty source segment must not come back empty.
    Reason `missing_segments`, `extra_segments` or `empty_segment`.
41. Truncation: a provider finish reason of length, a truncated JSON body,
    or a truncation error from the model library fails the call. Reason
    `truncated`. A segment of 200 or more source characters whose
    translation is under 25% of its length fails as `truncated` too.
42. Placeholders: every placeholder token in a source segment appears
    exactly once in its translation, properly nested, and no new token
    appears. Order may change. Reason `placeholder_mismatch`.
43. Structure: the reassembled body has the same number of blocks,
    headings, list items, table cells, figures and links as the original.
    Reason `structure_mismatch`.
44. Length ratio: the ratio of letters in the translated body to letters in
    the source body must lie inside the language pair's bounds (default
    0.4 to 3.0, configurable per language). Reason `length_ratio`.
45. Unchanged output: a segment whose normalised translation equals its
    normalised source counts as unchanged, unless it is short (under four
    words), only digits, punctuation, names in a do-not-translate list, or
    a URL. If more than 20% of eligible segments are unchanged, or the
    title is unchanged, the job fails with reason `untranslated`. This is
    the guard that catches a model answering in the source language when
    both languages share a script.
46. Numbers: every run of two or more digits in the source appears in the
    translation, after mapping native digit sets to ASCII. Missing numbers
    are a warning by default (dates and units may be reformatted
    legitimately); the setting `numbers_guard` can make it a failure.
47. Declared language: when the adapter reports a language for its output
    and that language does not match the target, the job fails with reason
    `wrong_language`.
48. Detector hook: the filter `translate_sidecar_detect_language` lets a
    site plug in its own language identifier. A returned tag that does not
    match the target fails the job with reason `wrong_language`.
49. Script: for each translated text field (title, excerpt, body text, SEO
    title, description), letters are counted by script using the Unicode
    range table below. Combining marks count with the script of their
    block, so a script that writes vowels as marks is not undercounted.
    Digits, punctuation, spaces, emoji, joiners and other common characters
    are left out of the count. Then:
    - The target script share of counted letters must be at least 0.70
      for the title and 0.60 for the body (configurable). Latin letters
      from names, brands and acronyms are why the body bound is lower.
    - Letters from any third script (neither the target script nor Latin)
      must be at most 0.05 of counted letters. This catches one script
      bleeding into another, such as the right language family answered in
      a neighbouring script.
    - For a Latin-script target, the Latin share must be at least 0.90.
      This catches a model that answers in a non-Latin source script.
    - A field with fewer than 8 counted letters skips the share tests but
      must still contain no third-script letters. One stray glyph in a
      long text is never enough to fail it; a real presence is.
    - Reason `wrong_script`, with the measured shares in the detail.
50. The built-in range table covers at least: Latin (Basic Latin letters,
    Latin-1 Supplement, Latin Extended-A and -B, Latin Extended Additional),
    Greek, Cyrillic, Armenian, Hebrew, Arabic (with its supplements and
    presentation forms), Syriac, Thaana, Devanagari, Bengali, Gurmukhi,
    Gujarati, Oriya, Tamil, Telugu, Kannada, Malayalam, Sinhala, Thai, Lao,
    Tibetan, Myanmar, Georgian, Hangul (syllables and jamo), Ethiopic,
    Khmer, Mongolian, Han (CJK unified ideographs and extension A),
    Hiragana and Katakana. A language may list several scripts (Japanese:
    Han, Hiragana, Katakana); their shares add. The table is filterable
    as ranges of code points; Unicode script properties in PCRE are an
    acceptable equivalent implementation if the tests in section 12 pass.
51. Some scripts serve several languages. The script guard proves the
    script, not the language. A wrong language in the right script is
    caught only by cases 45, 47 and 48. The README must say so.
52. A guard that throws (bad input, a regular expression error) fails the
    job with reason `guard_error`. Guards fail closed.
53. A human edit to a candidate re-runs the guards and shows the results as
    warnings. A reviewer may approve despite warnings only by ticking
    "approve with warnings" and giving a reason, which is stored.

### 4.7 Review and publication

54. Row states: `queued`, `running`, `failed`, `candidate`, `live`,
    `rejected`, `withdrawn`. Only `live` rows ever route. There is at most
    one `live` row and at most one `candidate` row per post and language.
55. The review screen lists candidates by language, oldest first, with a
    side-by-side view of source and translation by segment, the guard
    report, and the diff against the current live row when one exists.
56. Preview: a reviewer can open the candidate at its future URL with a
    nonce parameter. The preview response is `noindex, nofollow`,
    `Cache-Control: private, no-store`, and only for users with the review
    capability. An invalid or expired nonce gets the normal response for
    that URL.
57. Approve: the candidate becomes `live`, the previous live row (if any)
    is kept as the single prior version for rollback, its segments go into
    the translation memory, `approved_at` and `approved_by` are recorded,
    and the purge action fires (section 10).
58. Reject: the candidate becomes `rejected` with an optional reason. The
    live row, if any, is untouched.
59. Withdraw: the live row becomes `withdrawn`. The URL answers 410 from
    then on (case 36), the language drops out of the post's hreflang
    cluster on every member, and the purge action fires.
60. Roll back: when a prior version exists, the reviewer can restore it as
    the live row. One step only.
61. Manual translation: a translator can type or paste a translation into
    the editor for any segment set. It is stored as a candidate with
    adapter `manual`, runs the guards as warnings (case 53), and is
    reviewed like any other.
62. Self-approval is allowed only when the filter
    `translate_sidecar_allow_self_approval` returns true (default true for
    single-author sites, the operator may turn it off). The approver is
    always recorded.
63. A template tag and a small block list the post's routed translations
    as links with `hreflang` and `lang` attributes, for themes that want a
    language picker on the post. Nothing is printed when there are none.

### 4.8 Head tags and structured data

64. On a translation page the canonical is the page's own absolute URL,
    never the original. On the original the canonical is unchanged.
65. The hreflang cluster for a post holds: the original with its own
    language tag, every language with `publish` and `index` on and a live,
    non-held translation of that post, and `x-default` pointing at the
    original (filterable). The same cluster is printed on the original and
    on every member, so the annotations are bidirectional.
66. A cluster with only the original in it prints nothing.
67. Each URL in the cluster is absolute, uses the site's scheme and host,
    and follows the permalink structure's trailing-slash rule.
68. `og:locale` on a translation page is the language's locale, and
    `og:locale:alternate` lists the other cluster members' locales.
    `og:url`, `og:title`, `og:description` and the Twitter equivalents
    describe the translation page.
69. Head output mode (setting `head_mode`):
    - `core` (default when no SEO plugin is detected): the plugin removes
      core's `rel_canonical` on translation pages and prints its own
      canonical, hreflang and Open Graph tags.
    - `filters`: the plugin prints only hreflang links and exposes every
      value through filters (section 5.4) for an SEO plugin to consume.
      The operator wires those values into their SEO plugin with that
      plugin's own documented filters.
    - `rewrite`: the plugin buffers only the `wp_head` output on
      translatable pages and rewrites `rel="canonical"`, `og:url`,
      `og:locale`, the title and description tags in place, and removes
      any `link` element carrying an `hreflang` attribute that it did not
      print. It never touches `rel="alternate"` links without `hreflang`
      (feeds and oEmbed use them).
    The settings screen shows which mode is active and, on a sample post,
    the exact tags that will be printed.
70. Structured data: on a translation page the plugin adjusts the post's
    JSON-LD graph (its own minimal output in `core` mode, or the graph
    passed through `translate_sidecar_schema_graph` in other modes):
    `inLanguage` becomes the language tag, `headline`, `description`,
    `url` and `mainEntityOfPage` describe the translation, and
    `translationOfWork` references the original by its `@id`. Images,
    author, publisher, `datePublished` and `dateModified` stay the
    original's. On the original, `workTranslation` lists the indexable
    live translations.

### 4.9 Sitemaps

71. With core sitemaps on, the plugin registers one provider with one
    subtype per language that has `publish` and `index` on. Each page of
    each subtype lists live, non-held translations of eligible, published
    posts, newest first, with `lastmod` set to the later of the
    translation's approval time and the original's modified time.
72. With core sitemaps off, the plugin serves `/translations-sitemap.xml`
    as an index and `/translations-sitemap-xx-N.xml` as children, and adds
    the index to `robots.txt` through the `robots_txt` filter. Both routes
    can be renamed by filter.
73. Each sitemap file holds at most 2,000 URLs by default (filterable, hard
    ceiling 50,000) and stays under 50 MB uncompressed, per the sitemaps
    protocol.
74. All dates are emitted in UTC in W3C format from GMT columns. Never
    convert a GMT value through a function that applies the site's time
    zone; that stamps every entry hours early or late.
75. A language with `index` on and no URLs answers a valid empty
    `urlset` with 200. A language with `index` off, `publish` off, or no
    registry entry answers 404 and is not listed in the index.
76. Optionally (setting `sitemap_alternates`, default off) each `url` entry
    carries `xhtml:link` alternates for its cluster. Off by default because
    head tags already carry the cluster and duplicate signals that disagree
    after a withdrawal are worse than one signal.
77. Sitemap bodies are cached with a built-at time. The built-at time, not
    the body, decides freshness, so an empty list is a valid cached
    answer. A rebuild takes a named lock; a request that cannot get the
    lock serves the previous body if there is one, and builds without the
    lock only when there is none. Approve, withdraw, roll back and
    original status changes mark the affected language and page dirty.

### 4.10 Jobs, cap and kill switch

78. Jobs run on a recurring tick (default every 5 minutes) that takes a
    lock, processes up to N jobs (default 5) within a time budget (default
    50 seconds), and releases the lock. Overlapping ticks exit at once.
79. Before every call the plugin checks, in order: its own kill switch, its
    own daily cap, then `spend-meter` when present (case 86). Any doubt
    stops the call. The checks are repeated inside a queued job, because
    the state may have changed since it was queued.
80. The daily cap is a number of adapter calls per site per day (default
    100). It also has an optional character cap per day (default off). The
    counter is incremented atomically before the call; a call that would
    pass the cap is not made, the job stays `queued`, and the tick stops
    with reason `capped`. A failed call still counts. A cap of 0 means no
    calls. There is no unlimited value; the operator sets a large number.
    The day boundary is midnight in the site time zone.
81. The kill switch is an option on the settings screen and the constant
    `TRANSLATE_SIDECAR_DISABLED`. When on, no adapter call is made, ticks
    exit, and queued jobs stay queued. Review, approve, reject, withdraw,
    routing, head tags and sitemaps keep working: the switch stops spend,
    not reading.
82. Provider errors: a retryable error (timeout, 429, 5xx) puts the job
    back with backoff of 5 minutes, 30 minutes, 2 hours and 12 hours, then
    marks it `failed` with reason `provider_error`. A non-retryable error
    (400, 401, 403, a refusal, a content filter) marks it `failed` at once.
    An exhausted-credit error pauses all calls until the next day and
    shows an admin notice.
83. Partial jobs never assemble. If any call of a job fails or any guard
    fails, the whole job fails and none of its output is stored. Segments
    from earlier successful calls in that job are discarded.
84. A job whose source changed while it ran is discarded with reason
    `source_changed` and requeued once when `auto` is on.
85. When `gen-queue` is active and the setting `use_queue` is on, each job
    is enqueued as type `translate_sidecar_translate` through
    `gen_queue_enqueue_job` with an idempotency key of post id, language
    and source hash. When the filter returns null (gen-queue absent), the
    plugin's own tick runs it. The cap and kill switch apply either way.
86. When `spend-meter` is active, each call follows its reservation
    protocol: `spend_meter_pre_spend` with purpose
    `translate_sidecar.translate` and caller `translate-sidecar`; if
    `allowed` is not exactly true, the call is not made and the job stays
    queued with reason `spend_denied`; when a reservation is returned,
    exactly one `spend_meter_record` or `spend_meter_release` follows. An
    unknown reason code is a deny.

### 4.11 Lifecycle of the original

87. Original moved to draft, pending, private or scheduled: translations
    stop routing. Their URLs answer 302 to the original URL, which itself
    answers whatever WordPress answers. Rows are kept.
88. Original trashed: translation URLs answer 410. Restoring from the
    trash restores routing for rows that are still `live`.
89. Original deleted permanently: its rows and its translation memory
    links are deleted in the same request, and the affected sitemaps are
    marked dirty.
90. Original made password protected: translations stop routing (302) and
    the editor sidebar explains why.
91. A post type removed from the translatable list: its translations stop
    routing; rows are kept.

### 4.12 Edge cases

92. Plain permalinks: routes cannot exist. The plugin shows an admin
    notice, registers no routes, prints no hreflang and serves no
    sitemaps. Review and translation still work.
93. A root-level page whose slug equals a language prefix created after the
    language was registered: the settings screen and Site Health flag the
    collision. The language route wins for `/xx/slug/`; the page at `/xx/`
    still renders when the language home is off.
94. Query state read too early: some screens (early `wp_die()` pages)
    print `language_attributes()` before the main query exists. The
    current-language helper returns "no language" in that case and does
    not cache the answer, so the real request later gets the right one.
95. A post with no body text (only images or an embed): refused with reason
    `too_short` unless the operator lowers the minimum.
96. A segment that is only an emoji, a number or a URL is copied, not sent.
97. RTL target: `dir="rtl"` on `<html>`, and the body stays wrapped in its
    original block markup. The plugin does not mirror layouts; that is the
    theme's job.
98. A model that wraps the JSON in a code fence or adds prose before it is
    handled by the model library or the site's caller; if the result is
    still not the expected shape, the job fails with reason `bad_shape`.
99. Very long posts (over 200 segments) still translate in several calls;
    the dry run shows the call count so the cap can be judged.
100. Multisite: every setting, table, counter, cap, kill switch and
     sitemap is per site. A network-wide kill switch option, when set by a
     network admin, is honoured by every site.
101. Object cache absent: per-request static caches still keep a page to a
     bounded number of queries (section 9).
102. Two editors approve the same candidate at once: the second sees "already
     approved" and nothing changes twice. State changes use a conditional
     update on the current state.

### 4.13 Failure modes, open or closed

- Routing fails closed: any doubt about a row, the original's status or a
  switch means a 302 to the original. An unapproved translation is never
  served.
- Head tags fail closed: if the cluster lookup fails, no hreflang is
  printed and the canonical falls back to core behaviour.
- Sitemaps fail closed: a language whose build fails is left out of the
  index and its file answers 503 with `Retry-After`, never a partial list.
- The original post is never broken by the plugin. Every hook on an
  original page catches its own exceptions, logs them, and returns the
  unmodified value.
- Spend fails closed: any doubt about the cap, the kill switch or the spend
  meter means no call.
- Guards fail closed (case 52).

### 4.14 Schema installation and verification

103. On activation and on each upgrade the plugin runs `dbDelta` for the
     three tables.
104. The plugin records its schema version only after verification passes.
105. After `dbDelta`, the plugin compares `SHOW COLUMNS` for each table
     with the expected column list. A missing column blocks every write,
     shows an admin notice and fails Site Health.
106. `wp translate-sidecar verify-schema` runs the same check and exits 1
     on a mismatch, naming the table and column.

## 5. Public interface

Namespace `IndianOpinion\TranslateSidecar`. Function prefix
`translate_sidecar_`. Option and meta keys start with `translate_sidecar_`
(meta keys with a leading underscore). The names below are the contract.

### 5.1 Adapter interface

```
interface Translator
  id(): string                      // short slug, e.g. "llm-json", "manual"
  label(): string
  translate(TranslationRequest $r): TranslationResponse   // throws TranslatorException
  maxCharsPerCall(): int            // hint for case 23
```

`TranslationRequest` (immutable): `sourceLang` (BCP 47), `targetLang`
(BCP 47), `segments` (ordered map of segment id to text with placeholder
tokens), `context` (array: `title`, `post_type`, optional `glossary` as a
map of source term to target term, `do_not_translate` list), `jobId`.

`TranslationResponse` (immutable): `segments` (map of id to text),
`declaredLang` (nullable), `finishReason` (`stop`, `length`, `other`),
`provider`, `model`, `usage` (array: `input_units`, `output_units`,
optional `raw`).

`TranslatorException` carries `retryable` (bool), `creditExhausted`
(bool) and an `errorCode` (string up to 64 chars).

Shipped adapters:

- `LlmJsonTranslator`: active when `indianopinion/llm-json-php` is
  installed. Uses a JSON schema of the shape
  `{"language": string, "segments": [{"id": string, "text": string}]}`
  with `additionalProperties: false`. The prompt is generic: translate
  each segment from the source language into the target language, keep
  every placeholder token exactly, do not add, merge, split or omit
  segments, do not translate items in the do-not-translate list, apply
  the glossary, and return the language tag of the output. The prompt
  template is filterable and stored nowhere else.
- `CallerTranslator`: wraps a callable supplied by the filter
  `translate_sidecar_llm_caller`, with the signature
  `fn(string $system, string $user, array $jsonSchema, array $meta): array`
  returning the decoded JSON. This lets a site run without
  `llm-json-php`.
- `ManualTranslator`: no API; used by case 61.

Other adapters (any machine translation API) register through the filter
`translate_sidecar_translators` (array of id to `Translator`). The active
adapter is chosen on the settings screen, per language if wanted.

### 5.2 Functions

| Function | Returns | Notes |
|---|---|---|
| `translate_sidecar_request(int $post_id, array $langs, array $args = [])` | `array` of job results keyed by lang | args `replace`, `dry_run`, `source` |
| `translate_sidecar_get(int $post_id, string $lang, string $state = 'live')` | `?Translation` | per-request cached |
| `translate_sidecar_url(int $post_id, string $lang)` | `?string` | null unless routed |
| `translate_sidecar_cluster(int $post_id)` | `array` hreflang tag to URL | empty when only the original |
| `translate_sidecar_current_language()` | `string` | prefix, or empty string |
| `translate_sidecar_languages(bool $public_only = false)` | `array` | registry entries |
| `translate_sidecar_approve(int $row_id, array $args = [])` | `true` or `WP_Error` | args `with_warnings`, `reason` |
| `translate_sidecar_reject(int $row_id, string $reason = '')` | `true` or `WP_Error` | |
| `translate_sidecar_withdraw(int $row_id)` | `true` or `WP_Error` | |
| `translate_sidecar_rollback(int $post_id, string $lang)` | `true` or `WP_Error` | |
| `translate_sidecar_run(array $opts = [])` | `RunSummary` | opts `max`, `time`, `lang` |
| `translate_sidecar_guard(string $source, string $translated, string $lang, string $field)` | `GuardReport` | pure, no I/O |
| `translate_sidecar_script_shares(string $text)` | `array` script to share | pure |
| `translate_sidecar_is_enabled()` | `bool` | fresh read of kill switch and constant |
| `translate_sidecar_cap_remaining()` | `int` | calls left today |
| `translate_sidecar_language_switcher(int $post_id = 0, array $args = [])` | `string` HTML | case 63 |

Admin actions check capabilities themselves and return `WP_Error` on
refusal.

### 5.3 Actions

| Action | Arguments |
|---|---|
| `translate_sidecar_job_queued` | `int $job_id, int $post_id, string $lang` |
| `translate_sidecar_job_failed` | `int $job_id, string $reason, array $detail` |
| `translate_sidecar_candidate_ready` | `int $row_id, int $post_id, string $lang` |
| `translate_sidecar_approved` | `int $row_id, int $post_id, string $lang` |
| `translate_sidecar_rejected` | `int $row_id, string $reason` |
| `translate_sidecar_withdrawn` | `int $row_id, int $post_id, string $lang` |
| `translate_sidecar_purge_urls` | `array $urls, string $why` (section 10) |
| `translate_sidecar_capped` | `string $day, int $calls` |
| `translate_sidecar_tick_finished` | `RunSummary $summary` |

### 5.4 Filters

| Filter | Arguments | Default |
|---|---|---|
| `translate_sidecar_languages` | `array $registry` | settings |
| `translate_sidecar_post_types` | `array $types` | `['post']` |
| `translate_sidecar_is_eligible` | `bool $ok, WP_Post $post, string $lang` | case 8 |
| `translate_sidecar_translators` | `array $translators` | shipped adapters |
| `translate_sidecar_llm_caller` | `?callable $caller` | null |
| `translate_sidecar_prompt` | `array $messages, TranslationRequest $r` | generic prompt |
| `translate_sidecar_segment_budget` | `int $chars, string $lang` | 6000 |
| `translate_sidecar_guard_thresholds` | `array $t, string $lang` | section 4.6 |
| `translate_sidecar_script_ranges` | `array $ranges` | built-in table |
| `translate_sidecar_detect_language` | `?string $tag, string $text` | null |
| `translate_sidecar_cluster` | `array $cluster, int $post_id` | case 65 |
| `translate_sidecar_x_default` | `?string $url, int $post_id` | original URL |
| `translate_sidecar_canonical` | `string $url, int $post_id, string $lang` | own URL |
| `translate_sidecar_head_values` | `array $values, int $post_id, string $lang` | canonical, og values, title, description |
| `translate_sidecar_schema_graph` | `array $graph, int $post_id, string $lang` | case 70 |
| `translate_sidecar_sitemap_max_urls` | `int $n` | 2000 |
| `translate_sidecar_sitemap_routes` | `array $routes` | case 72 |
| `translate_sidecar_allow_self_approval` | `bool $ok, int $user_id, int $row_id` | true |
| `translate_sidecar_capability` | `string $cap, string $action` | actions `request`, `review`, `settings`; `edit_others_posts`, `edit_others_posts`, `manage_options` |
| `translate_sidecar_original_link` | `string $html, int $post_id, string $lang` | case 31 |

### 5.5 REST API

Namespace `translate-sidecar/v1`.

- `GET /posts/{id}/translations`: public; lists routed translations
  (language, URL, approved time). Nothing about candidates.
- `POST /posts/{id}/translations`: request jobs (`request` capability,
  REST nonce).
- `GET /rows/{id}`: a row with its guard report (`review` capability).
- `POST /rows/{id}/approve`, `/reject`, `/withdraw`, `/rollback`
  (`review` capability).

### 5.6 WP-CLI

`wp translate-sidecar` with subcommands: `languages` (list, validate),
`request <post-id>... --lang=<xx>[,<yy>] [--replace] [--dry-run]`, `run
[--max=<n>] [--time=<s>]`, `status [--lang=<xx>]`, `approve <row-id>
[--with-warnings --reason=<text>]`, `reject <row-id> [--reason=<text>]`,
`withdraw <row-id>`, `rollback <post-id> --lang=<xx>`, `guard <row-id>`
(re-run guards, print report), `sitemap rebuild [--lang=<xx>]`,
`verify-schema` (case 106), `purge-language <xx> --yes`. Exit codes: 0 ok,
1 error, 2 refused by cap, kill switch or spend meter.

## 6. Data

Three tables, created with `dbDelta` and verified afterwards (case 106).

`{prefix}translate_sidecar_rows`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned, PK | |
| `post_id` | bigint unsigned | indexed |
| `lang` | varchar(16) | registry prefix |
| `state` | varchar(16) | case 54 |
| `title`, `excerpt`, `seo_title`, `meta_description` | text | translated |
| `content` | longtext | sanitised HTML |
| `alt_text` | longtext | JSON map of attachment id to alt |
| `source_hash` | char(64) | SHA-256 of the normalised source fields |
| `segment_hashes` | longtext | JSON list |
| `adapter`, `provider`, `model` | varchar(64) / varchar(64) / varchar(128) | |
| `guard_report` | longtext | JSON |
| `reason` | varchar(64) | failure, rejection or warning-approval reason code |
| `note` | text | reviewer note |
| `attempts` | smallint | |
| `next_attempt_gmt`, `created_gmt`, `updated_gmt`, `approved_gmt` | datetime | GMT |
| `approved_by` | bigint unsigned | |
| `is_prior` | tinyint | the one prior version for rollback |

Indexes: `(post_id, lang, state)`, `(lang, state, approved_gmt)`,
`(state, next_attempt_gmt)`. A uniqueness rule (enforced in code under a
lock, and by a unique key on a generated slot column where the database
supports it) keeps at most one `live`, one `candidate` and one prior row
per post and language.

`{prefix}translate_sidecar_memory`: `segment_hash` char(64), `lang`
varchar(16), `text` longtext, `row_id`, `last_used_gmt`. Primary key
`(segment_hash, lang)`. Entries unused for 180 days (filterable) are pruned
by a daily event.

`{prefix}translate_sidecar_counters`: `day` date, `metric` varchar(16)
(`calls`, `chars`), `n` bigint. Primary key `(day, metric)`. Increment with
`INSERT ... ON DUPLICATE KEY UPDATE`. Rows older than 90 days are pruned.

Options: settings (one array, autoloaded), registry (one array,
autoloaded), schema version, kill switch, sitemap cache entries (not
autoloaded), credit pause until. Post meta: source language, do-not-
translate flag.

Retention: rejected and failed rows are kept 30 days (filterable), then
deleted by the daily event. Withdrawn rows are kept so their URLs keep
answering 410; the operator can purge them per language.

Uninstall (`uninstall.php`): drops the three tables, deletes every option
and post meta key with the plugin's prefix, clears scheduled events, and
flushes rewrite rules. On multisite it does this for every site.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Languages | none | case 1 |
| Per-language `publish` | off | case 33 |
| Per-language `index` | off | case 35; turn on one language at a time |
| Per-language `auto` | off | case 16 |
| Source language | site locale | case 5 |
| Post types | `post` | case 7 |
| Minimum source words | 150 | case 9 |
| Adapter | none until chosen | section 5.1 |
| Daily call cap | 100 | case 80 |
| Daily character cap | off | case 80 |
| Kill switch | off | case 81 |
| `stale_policy` | `serve` | case 39 |
| Translate alt text | on | case 21 |
| `numbers_guard` | `warn` | case 46 |
| Script share, title / body | 0.70 / 0.60 | case 49 |
| Third-script ceiling | 0.05 | case 49 |
| Length ratio | 0.4 to 3.0 | case 44 |
| Unchanged ceiling | 0.20 | case 45 |
| `head_mode` | `core`, or `filters` when an SEO plugin is detected by the presence of its own canonical output on a sample request | case 69 |
| Language home | off | case 38 |
| Sitemap URLs per file | 2000 | case 73 |
| `sitemap_alternates` | off | case 76 |
| Tick interval, jobs per tick, time budget | 5 min, 5, 50 s | case 78 |
| `use_queue` | off | case 85 |
| Retention of failed and rejected rows | 30 days | section 6 |

Constants override settings: `TRANSLATE_SIDECAR_DISABLED`,
`TRANSLATE_SIDECAR_DAILY_CAP`. API keys are read from constants or
environment variables named by the adapter, never stored in plain text in
an option by default.

## 8. Security and privacy

- Post text (title, body, excerpt, alt text) is sent to the chosen
  provider. Nothing else: no author names, no e-mail, no comments, no user
  data. The settings screen says so plainly next to the adapter choice.
- Model output is untrusted. It is sanitised with `wp_kses_post` before
  storage and escaped again on output where it lands in attributes.
  Translated titles and descriptions are stored as text, not HTML.
- Source content may contain instructions aimed at the model. The prompt
  presents segments as data, and the guards reject output whose shape,
  length or script drifts. The README says that guards reduce, not
  remove, this risk, and that review is the control.
- Every admin action checks a capability and a nonce. REST routes use
  permission callbacks. The preview nonce is per user, per row, and
  expires in one hour.
- No `eval`, no shell, no remote code, no `@` suppression.
- API keys are never printed back on the settings screen; a set key shows
  as set.
- Personal data exporter and eraser: not needed; the plugin stores no
  personal data beyond the approving user id, which follows the user's
  own lifecycle.

## 9. Performance budget

- Original post page, object cache on: at most 1 extra query (all live rows
  for the post, one indexed query) and under 2 ms added. Without object
  cache: still 1 query, cached per request.
- Translation page: at most 3 extra queries (resolve route, live rows for
  the post, link rewrites batched in one query for all linked posts).
- No query joins the post meta table to find translations.
- Sitemap file of 2,000 URLs: one indexed query plus one batched permalink
  lookup, built in under 1 second and under 32 MB peak.
- Admin review screen paginates at 20 rows.
- A tick never runs longer than its time budget and holds at most one job
  body in memory at a time.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later. MySQL 5.7+ or MariaDB 10.4+.
- Pretty permalinks required for routing (case 92).
- Page caches and CDNs: language lives in the path, so each translation is
  a separate cache key and no `Vary` header is needed. On approve,
  withdraw, roll back or a cluster change, the plugin fires
  `translate_sidecar_purge_urls` with every URL in the cluster (the
  original and every translation), because the hreflang block changed on
  all of them. The plugin calls core's `clean_post_cache` for the original.
  Cache plugins hook the action to purge.
- SEO plugins: see `head_mode` (case 69). The plugin never writes another
  plugin's options.
- Other multilingual plugins: running two is unsupported. When a rewrite
  rule for a registered prefix exists that this plugin did not add, the
  plugin refuses to save that language (case 2).
- Block themes and classic themes: both. The original's template renders;
  the plugin only filters values.
- Works as a regular plugin. Not supported as a must-use plugin in 0.1.0,
  because it needs activation hooks for its tables.

## 11. Observability

- Settings screen dashboard: per language, counts by state (queued,
  failed, candidate, live, withdrawn, stale), today's calls and characters
  against the cap, kill switch state, credit pause, last 20 failures with
  reason codes, and the next tick time.
- Each row keeps its guard report (shares, ratios, counts, reasons) so a
  reviewer sees why a job failed.
- Site Health: tests for pretty permalinks, prefix collisions, schema
  version, WP-Cron reachability (last tick time older than 3 intervals is
  a warning), and adapter configured when any language has `auto` on.
- WP-CLI `status` prints the same numbers as the dashboard.
- Logging goes through the actions in 5.3 and, when `WP_DEBUG_LOG` is on,
  one line per job outcome with job id, post id, language, adapter, reason
  code and duration. Never post text, never keys.

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit with a stub
layer; guard and script tests are pure.

1. Registry validation accepts a well-formed language and rejects a
   malformed prefix, duplicate prefix, duplicate hreflang, `x-default`,
   source-language prefix and each collision type, with the colliding name
   in the error. (2)
2. A language with no scripts cannot be saved without the explicit "no
   script guard" setting. (3)
3. Removing a language stops routes, head tags and sitemap and leaves rows
   in place; purging removes them. (4)
4. Source language defaults to the site locale and can be overridden per
   post. (5, 12)
5. Saving the registry schedules exactly one rewrite flush. (6, 1)
6. Post type filter adds a hierarchical type; `/xx/parent/child/` routes.
   (7, 22)
7. Draft, scheduled, private and protected posts are refused and never
   reach the adapter (adapter spy records zero calls). (8)
8. A 100-word post is refused `too_short`; a post in a script without
   spaces is measured by the character rule. (9, 95)
9. The do-not-translate flag refuses new jobs and leaves live rows
   serving. (10)
10. Same-language request is refused `same_language`. (11)
11. A post written in a non-source registered language is not moved, uses
    its own hreflang, and a request into the source language without a
    source prefix is refused `no_route`. (12)
12. Requests through each entry point create one job; a duplicate request
    reports the existing job. (13, 14)
13. A replace request keeps the old candidate until the new one exists.
    (15)
14. Auto on: publishing queues one job; an unchanged update queues none;
    a changed update queues per policy. (16)
15. Dry run reports segments and call count, writes nothing, and leaves
    the counter unchanged. (17)
16. Segmenting a block document and a classic document gives the expected
    ids; inline tags become placeholders; URLs and attributes never appear
    in the request. (18, 19)
17. `pre`, `code`, shortcodes, embeds, `translate="no"` and do-not-
    translate blocks are copied verbatim. (20, 96)
18. Images keep `src` and `srcset`; alt and captions are translated when
    the setting is on and untouched when off; the media library alt is
    unchanged. (21)
19. Title, excerpt, SEO title and description are separate segments; a
    missing excerpt is derived by trimming. (22, 30)
20. Segments group by budget; an oversize segment goes alone. (23, 99)
21. Re-translation sends only changed segments; memory from a rejected
    candidate is never reused. (24)
22. A script tag in model output is removed before storage. (25)
23. `/xx/slug/` with all conditions met returns 200 with swapped title,
    body, excerpt, `lang`, `dir` and document title. (26, 27, 97)
24. A related-posts list on a translation page keeps other posts' titles.
    (27)
25. Internal links are rewritten only to routed translations. (28)
26. The comment form on a translation page targets the original. (29)
27. Description fallback trims a multi-byte string by grapheme without a
    broken character. (30)
28. The original link shows the static label and is absent when the page
    language equals the original's. (31)
29. No live translation: 302 to the original with a short max-age. (32)
30. Publish off: 302, no hreflang, no sitemap. (33)
31. A native post on its own language route answers 301 to its URL and the
    target does not redirect back. (34)
32. Publish on, index off: noindex, follow in meta and header; canonical
    and og values still self-describe; not in any cluster; sitemap 404.
    (35)
33. Withdrawn live translation and trashed original answer 410. (36, 59,
    88)
34. Old slug answers 301 to the new slug under the prefix. (37)
35. Language home on returns 200, not treated as a 404; off returns 302.
    (38)
36. Each stale policy behaves as specified on a title change and on a
    small body change. (39)
37. Missing, extra and empty segments fail with their reasons. (40)
38. Finish reason length and a short long segment fail `truncated`. (41)
39. Dropped, duplicated, unnested and invented placeholders fail. (42)
40. A dropped paragraph fails `structure_mismatch`. (43)
41. Ratios outside bounds fail `length_ratio`; per-language bounds
    override. (44)
42. A Latin-to-Latin translation returned in the source language fails
    `untranslated`; short and name-only segments are exempt. (45)
43. Missing numbers warn by default and fail when set; native digits map
    to ASCII. (46)
44. Declared and detected wrong languages fail `wrong_language`. (47, 48)
45. Script guard fixtures: a correct non-Latin translation passes; a body
    left in Latin fails; a neighbouring script bleeding in above 5% fails;
    one stray glyph in a long text passes; a short title with a third-script
    letter fails; a Latin target answered in a non-Latin script fails; a
    script that writes vowels as combining marks is not undercounted; a
    multi-script language adds its shares. (49, 50)
46. README states that the script guard proves script, not language
    (documentation check). (51)
47. A guard that throws fails the job `guard_error`. (52)
48. A human edit with warnings needs the tick box and a reason, which is
    stored. (53, 61)
49. State machine: only `live` routes; one live, one candidate and one
    prior per post and language. (54, 102)
50. Review screen lists candidates and shows guard report and diff. (55)
51. Preview works with a valid nonce and capability, carries noindex and
    no-store, and falls back to the normal response otherwise. (56)
52. Approve, reject, withdraw and roll back move state, record user and
    time, feed memory on approve only, and fire the purge action with the
    whole cluster. (57, 58, 59, 60)
53. Self-approval off refuses the author's own approval. (62)
54. Template tag prints links with `hreflang` and `lang`, and nothing when
    there are no translations. (63)
55. Canonical on a translation page is its own URL; the original's is
    unchanged. (64)
56. Cluster is identical on every member, includes `x-default`, excludes
    unindexed and held languages, and is empty with only the original.
    (65, 66)
57. Cluster URLs are absolute and follow the trailing-slash setting. (67)
58. `og:locale` and alternates are correct. (68)
59. Each head mode prints or rewrites the expected tags; `rewrite` removes
    foreign hreflang links and leaves feed and oEmbed alternates. (69)
60. Schema graph on a translation has `inLanguage`, `translationOfWork`,
    the original's images and dates; the original lists
    `workTranslation`. (70)
61. Core sitemap provider lists only indexable languages, newest first,
    with the later lastmod. (71)
62. Own sitemap routes and robots line work with core sitemaps off. (72)
63. Pagination respects the per-file limit and the hard ceiling. (73)
64. A GMT value is emitted unchanged in W3C UTC under a non-UTC site time
    zone. (74)
65. Empty indexable language gives an empty urlset with 200; others 404.
    (75)
66. Alternates appear in sitemap entries only when the setting is on.
    (76)
67. An empty cached list counts as fresh; a locked rebuild serves the old
    body; approve marks the right file dirty. (77)
68. Overlapping ticks: the second exits at once; a tick respects job count
    and time budget. (78)
69. Order of checks: kill switch, then cap, then meter; checks repeat
    inside a queued job. (79)
70. Cap: increments before the call, refuses the call past the cap, counts
    failures, 0 means none, resets at local midnight. (80)
71. Kill switch by option and by constant stops calls but not review,
    routing, head tags or sitemaps. (81)
72. Retryable errors back off on the schedule then fail; non-retryable
    fail at once; credit exhaustion pauses until the next day. (82)
73. A failure in the second call of a job stores nothing from the first.
    (83)
74. A source change during a job discards it `source_changed`. (84)
75. gen-queue present: job enqueued with the idempotency key; absent:
    filter returns null and the own tick runs it. (85)
76. spend-meter: deny stops the call; a reservation is followed by exactly
    one record or release; an unknown reason is a deny. (86)
77. Original to draft, private, scheduled or protected: 302; deleted: rows
    gone and sitemaps dirty; type removed: no routes. (87, 89, 90, 91)
78. Plain permalinks: notice, no routes, no hreflang, no sitemaps, review
    still works. (92)
79. A page created later at a prefix slug is flagged in Site Health. (93)
80. `language_attributes()` called before the main query returns no
    language and a later call returns the right one. (94)
81. A code-fenced or prose-wrapped reply that cannot be read fails
    `bad_shape`. (98)
82. Multisite: settings and counters are per site; the network kill switch
    stops every site. (100)
83. A translation page runs within the query budget with no object cache.
    (101, section 9)
84. Uninstall drops tables, options, meta and events on every site.
    (section 6)
85. Every admin action refuses without the capability or nonce. (section
    8)
86. Activation runs `dbDelta`; a missing column blocks writes, withholds
    the schema version, shows a notice, and makes `verify-schema` exit 1
    naming it. (103, 104, 105, 106)

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. Should `stale_policy` default to `hold_major` rather than `serve`, given
   that a correction to the original may matter more than uptime of the
   translation?
2. Is a single prior version enough for rollback, or should the table keep
   a short history (for example the last three approved versions)?
3. Should the language home (case 38) be in 0.1.0 at all, or deferred so
   the first release only routes posts?
4. Should the script table use code point ranges only, or Unicode script
   properties through PCRE where available, and how are the two kept in
   agreement in tests?
5. How should `head_mode` detection work without naming any SEO plugin:
   by a loopback request for a sample post, or by inspecting registered
   `wp_head` callbacks?
6. Should the uniqueness of one live and one candidate row be enforced by a
   generated column and unique key, or by code under a named lock only,
   given MySQL and MariaDB differences?
7. Should translated `alt` text live in the row's JSON map, or be written
   into the translated body only, so images outside the body keep the
   original alt?
8. Should the hard ceiling on segments per job (case 99) be a setting, so
   one very long post cannot use a large share of the daily cap?

## 14. Lessons from production

Written as rules.

1. Never announce many machine translations to search engines at once.
   Ship every language with indexing off, review a sample, then turn on
   one language at a time.
2. Keep translations out of the posts and post meta tables. Any query that
   joins post meta to find language rows will be slow on a large site.
3. Serve a missing translation as a temporary redirect to the original,
   not a 404. The page may exist within the hour.
4. Serve a withdrawn translation as 410, so crawlers drop it quickly.
5. A post written natively in another language is an original. Do not
   route it through the translation path, and redirect its language route
   to its real URL without any chance of a loop.
6. Do not read the main query's variables before the main query exists.
   Return "no language" and do not cache that answer.
7. Swap the title only for the queried post in the main query. A global
   `the_title` swap translates menus and related lists into the wrong
   post's language.
8. Swap the body early in `the_content` so later filters work on the
   translated text.
9. Canonical on a translation points at itself. A translation canonicalised
   to the original makes search engines ignore its hreflang.
10. Every hreflang cluster includes self and `x-default`, and is printed
    identically on every member. A cluster of one prints nothing.
11. When indexing is off, keep the self-describing canonical and Open Graph
    values. Pages still need to describe themselves to readers, social
    cards and other agents.
12. Strip only `link` tags that carry `hreflang`. `rel="alternate"` also
    carries feeds and oEmbed.
13. A change to any member's hreflang block is a change on every member.
    Purge the whole cluster.
14. Emit sitemap dates from GMT columns in UTC. Never run a GMT value
    through a time-zone-aware formatter.
15. An empty list is a valid cached answer. Let the build time decide
    freshness, not the presence of content.
16. A language home can render through a not-found context. Set the query
    flags and status yourself or SEO plugins will mark it noindex.
17. Count combining marks with their script. A naive letter count makes
    scripts that write vowels as marks look far less like themselves.
18. Require a real presence of a script, not one stray glyph, before
    calling text wrong-script.
19. Some scripts serve several languages. A script check proves script;
    a language check needs more.
20. Never assemble a translation from a partly failed job. All calls pass,
    or nothing is stored.
21. Labels such as "read the original" are static per language. No model
    call writes them.
22. Model output is untrusted HTML. Sanitise before storage.
23. `dbDelta` can skip a column add without an error. After every schema
    change, check the columns that exist (`SHOW COLUMNS`) against the
    expected list, record the schema version only when they match, and
    refuse to write and show a notice when they do not (cases 103 to 106).
24. Trim multi-byte strings with multi-byte functions, by grapheme where
    the result is shown to readers.
25. Never redirect by browser language. Crawlers do not send it and
    readers who chose a URL should get that URL.
