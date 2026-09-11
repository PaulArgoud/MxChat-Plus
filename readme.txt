=== MxChat Plus ===
Contributors: paulargoud
Tags: mxchat, vector-search, duckdb, prompt-caching, csv-export
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Three modules for MxChat: a DuckDB / MotherDuck vector store replacing Pinecone, Anthropic prompt caching, and a CSV export of selected transcripts.

== Description ==

**MxChat Plus** is a companion plugin for [MxChat](https://mxchat.ai/) (`mxchat-basic`).
It bundles three independent modules and wires them all in through WordPress hooks only —
**no file of the host plugin is ever modified**.

= Module 1 — DuckDB / MotherDuck vector store =

Stores MxChat's vector knowledge base in an embedded `.duckdb` file or in MotherDuck
cloud, instead of Pinecone or the slow MySQL fallback. It emulates the Pinecone wire
protocol over a REST endpoint, so MxChat needs no changes to use it.

* Two backends — embedded `.duckdb` file, or MotherDuck via DuckDB's native `ATTACH 'md:…'`.
* HNSW-indexed similarity search through DuckDB's VSS extension.
* Local mirror for MotherDuck installs: a local `.duckdb` shadow with HNSW for fast reads,
  while MotherDuck stays the canonical store. Resumable bootstrap, drift detection.
* Optional hybrid BM25 + vector retrieval via DuckDB FTS.
* Query result cache keyed by embedding hash + filter + bot, invalidated on write.
* Per-source dedup and a custom reranker hook.
* Four ingestion paths: MySQL bulk sync, synchronous post reprocess, async reprocess via
  Action Scheduler, and a one-shot Pinecone import that does not re-embed.
* Parquet export/import for backups and moves between backends.
* Optional INT8 quantization — 4x smaller vectors, marginal recall loss.
* Per-namespace REST tokens, rate limiting, rolling p50/p95/p99 latency metrics,
  `/health` endpoint, daily orphan compactor, multisite-aware uninstall.
* WP-CLI: `wp mxchat-plus duckdb {test|stats|sync|reprocess|async-reprocess|compact|metrics|cache|export|import|migrate-from-pinecone}`.

= Module 2 — Anthropic prompt caching =

Injects `cache_control` breakpoints into MxChat's outbound Claude requests so identical
prefixes are billed at roughly a tenth of the input price, and measures cached tokens
across every supported provider.

* Up to 4 breakpoints: tool definitions, the system prompt, and a *rolling* pair on the
  last two user messages — the write breakpoint of one turn becomes the read breakpoint
  of the next, so the hit rate stays flat as the conversation grows.
* Per-model minimum-prefix thresholds, because Anthropic ignores `cache_control`
  silently when the prefix is too short and the minimum varies **by model version**.
* Injection on Anthropic only. OpenAI, OpenRouter, xAI, DeepSeek and Gemini cache
  server-side on their own; the module measures their cached tokens for a unified
  savings dashboard without touching their requests.
* Admin dashboard widget plus `wp mxchat-plus promptcache {stats|debug|reset}`.
* Four filters to tune behaviour without forking.

= Module 3 — Transcripts CSV export =

Adds an export button to MxChat's transcripts screen (**admin.php?page=mxchat-transcripts**),
next to the host's **Delete Selected** control. MxChat's own export dumps the whole
transcripts table and ignores the checkboxes; this one exports what you actually asked for.

* Tick one or more conversations and click the button to export exactly those.
* Click it with nothing ticked and a modal — styled like the host's own dialogs — asks for
  a start and an end date, then exports every conversation in that period. Both bounds are
  inclusive, from 00:00:00 on the first day to 23:59:59 on the last.
* CSV columns: Session ID, Email, User identifier, Role, Message, Timestamp — one row per
  message, ordered by conversation then chronologically, with a UTF-8 BOM so Excel reads
  accented characters correctly.
* Hardened: `manage_options`, its own nonce, prepared statements whose placeholder list is
  built from the *count* of session ids, strict date validation, a ceiling of 2000
  conversations per export, and neutralisation of CSV formula injection — chat messages are
  visitor-written, and a cell starting with `=`, `+`, `-` or `@` executes in Excel and
  Sheets.
* Nothing to configure: the module is enabled from **MxChat Plus &rarr; Modules** and that
  is the whole setup.

= Requirements =

* PHP 8.1+
* WordPress 6.0+
* [MxChat](https://wordpress.org/plugins/mxchat-basic/) (`mxchat-basic`) 3.2.21 or newer
* For the vector store: the PECL `duckdb` extension (preferred) or the `duckdb` CLI binary
* For MotherDuck: a token from [app.motherduck.com](https://app.motherduck.com)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the Plugins
   screen.
2. Activate **MxChat Plus** — after MxChat itself.
3. For the vector store: go to **MxChat &rarr; DuckDB / MotherDuck**, choose a backend,
   click **Test connection**, then pick an ingestion strategy.
4. For prompt caching: turn **off** streaming in the MxChat settings (see the FAQ), then
   do nothing else — it engages automatically.
5. For the transcripts export: nothing to set up — open MxChat's transcripts screen and use
   the download button next to **Delete Selected**.

== Frequently Asked Questions ==

= Prompt caching reports zero savings. Why? =

Almost always because MxChat streaming is still enabled. MxChat streams Claude responses
with raw `curl_exec()` rather than `wp_remote_post()`, which bypasses the WordPress HTTP
API — the `http_request_args` filter the module uses is never called, so nothing can be
injected. Disable streaming in **MxChat &rarr; Settings** and the chat falls back to the
`wp_remote_post()` branch, which is fully interceptable. You lose the typewriter effect
and gain roughly 85-95% input-token cost reduction once the cache is warm.

= Retrieval returns no matches after switching to DuckDB. Why? =

The default integration makes MxChat call back into this site over REST, impersonating
Pinecone. That needs three things: HTTPS that works for the server calling **its own**
domain (MxChat hardcodes `https://`), pretty permalinks (not the "Plain" setting), and
`/wp-json/` not blocked by a security plugin or WAF. Check with
`curl -I https://your-site/wp-json/mxchat-plus/v1/health` **from the server**.

= Does this modify MxChat? =

No. Never. All three modules attach through WordPress hooks. The host plugin's own options,
filters, AJAX actions and nonces are used verbatim and are never renamed. An optional
patch shipped under `tools/patches/` would let the vector store skip one HTTP hop, but it
targets a filter that does not exist in mxchat-basic 3.2.21, it is inert by default, and
applying it means editing the host plugin (which a MxChat update will revert).

= The CSV export button does not show up. Why? =

Three usual causes. The module may be off — check **MxChat Plus &rarr; Modules**. You may
not be on MxChat's transcripts screen: the script is only loaded on
`admin.php?page=mxchat-transcripts`. Or your account lacks `manage_options`, in which case
nothing is loaded at all. The button is injected next to the host's **Delete Selected**
control, and re-injected automatically after paging, sorting or searching re-renders the
list.

= Which backend should I pick? =

Embedded for a single server: zero cost, HNSW available, lowest latency. MotherDuck when
several servers must share one knowledge base — and enable the local mirror past roughly
100k vectors, because MotherDuck cloud does not currently support the VSS extension.

= Does reprocessing cost money? =

Yes. Reprocessing posts calls the embedding API configured in MxChat (OpenAI, Voyage,
Gemini...). Typical cost is a few cents for 100-500 posts on a small embedding model.
Syncing from an existing MySQL table re-uses stored embeddings and costs nothing.

= What happens when I delete the plugin? =

`uninstall.php` removes the plugin's own options, transients, scheduled events and the
embedded data directory, with multisite support. The host plugin's data is left alone.

= Is it translated? =

Source strings are English (vector store) and French (prompt cache); a complete French
catalogue ships compiled. The `.pot` template is included for other locales. The text
domain is `mxchat-plus` and nothing else.

== Changelog ==

= 1.0.0 =
* Fixed (inherited from the pre-merge plugins): orphan compaction skipped up to 1000
  vectors per batch; the max-deletes cap could be overshot by up to 99 deletions;
  vectors imported from Pinecone were treated as orphans and deleted the night after
  the import; a boot fatal on installs made without Composer.
* First release of the merged plugin: the former **MxChat DuckDB / MotherDuck** (0.13.0)
  and **MXChat Prompt Cache** (0.8.0) now ship as two modules of one plugin, joined by a
  new third one.
* New **transcripts CSV export** module: a button on MxChat's transcripts screen that
  exports the conversations you ticked, or — with nothing ticked — every conversation
  between two dates chosen in a modal. UTF-8 BOM for Excel, CSV formula injection
  neutralised, `manage_options` + dedicated nonce + prepared statements, 2000 conversations
  per export. The host's own export ignores the selection and dumps the whole table.
* Unified naming: `MxChat_Plus_*` classes, `MXCHAT_PLUS_*` constants, `mxchat_plus_*`
  options and hooks, and a single `mxchat-plus` text domain.
* Unified WP-CLI root: `wp mxchat-plus duckdb …` and `wp mxchat-plus promptcache …`.
* PHP floor raised to 8.1; WordPress floor 6.0.
* Complete French catalogue: every user-facing string of both modules is now extracted
  and translated, and the compiled `.mo` ships in the zip.
* Documentation of the two silent-failure prerequisites that were previously undocumented:
  streaming must be off for prompt caching, and the Pinecone proxy needs a working HTTPS
  loopback with pretty permalinks.

The per-module history before the merge is preserved in `CHANGELOG.md`.

== Upgrade Notice ==

= 1.0.0 =
Replaces the separate mxchat-duckdb and mxchat-promptcache plugins. Deactivate and delete
both before activating MxChat Plus, then re-check your settings: option keys moved to the
`mxchat_plus_*` namespace. Requires PHP 8.1 and mxchat-basic 3.2.21+.
