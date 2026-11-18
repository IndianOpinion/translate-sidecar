=== translate-sidecar ===
Contributors: indianopinion
Tags: translation, multilingual, hreflang, sitemap, review
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Post translations in their own table, served at /xx/slug/, with hreflang, canonical, per-language sitemaps, guards and human review.

== Description ==

translate-sidecar keeps each translation of a post as a row keyed on post and language, instead of a duplicate post. It serves the translation at `/xx/slug/` as a view of the original, sharing its images, author, dates, comments and structured data, and emits a self-referencing canonical, a complete hreflang cluster, Open Graph locales and a sitemap per language.

Written from scratch; not derived from any third-party plugin.

Every machine translation passes guards: every segment back once, no truncation, placeholders intact, the same structure, a sane length ratio, not left in the source language, numbers kept, the declared language right, and the target script present. The script guard proves the script, not the language.

Nothing publishes on its own. Every translation is a candidate until a reviewer approves it, and every language starts with publish and index switches off. A daily call cap and a kill switch stop spend.

Translation backends plug in through an adapter interface. A manual adapter for human translators ships with the plugin; a model adapter runs on any caller you supply through a filter.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/translate-sidecar/` and activate it.
2. Make sure pretty permalinks are on.
3. Go to Translations > Settings, add a language and choose an adapter.
4. Request a translation from the post editor, review it under Translations > Review, then turn on publish for the language.

== Frequently Asked Questions ==

= Does it create a post per language? =

No. Translations live in their own table.

= Does it redirect by browser language? =

No. The language is in the URL only.

= What is sent to the provider? =

Post text only: title, body, excerpt and alt text.

== Changelog ==

= 0.1.0 =
* First release.
