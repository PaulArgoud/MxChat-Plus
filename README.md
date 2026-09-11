# MxChat Plus

<p align="left">
  <a href="#"><img alt="Plugin version" src="https://img.shields.io/badge/version-1.0.0-blue.svg"></a>
  <a href="#"><img alt="PHP" src="https://img.shields.io/badge/php-%E2%89%A5%208.1-777BB4.svg?logo=php&logoColor=white"></a>
  <a href="#"><img alt="WordPress" src="https://img.shields.io/badge/wordpress-%E2%89%A5%206.0-21759B.svg?logo=wordpress&logoColor=white"></a>
  <a href="https://mxchat.ai/"><img alt="MxChat" src="https://img.shields.io/badge/mxchat--basic-%E2%89%A5%203.2.21-2c3e50.svg"></a>
  <a href="https://duckdb.org/"><img alt="DuckDB" src="https://img.shields.io/badge/duckdb-VSS-FFF000.svg?logo=duckdb&logoColor=black"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img alt="License: GPL v2+" src="https://img.shields.io/badge/license-GPL%20v2%2B-green.svg"></a>
  <a href="https://github.com/paulargoud/mxchat-plus/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/paulargoud/mxchat-plus/actions/workflows/ci.yml/badge.svg"></a>
</p>

> **One companion plugin, three modules**, for the third-party [MxChat](https://mxchat.ai/)
> chatbot plugin (`mxchat-basic`) — a **DuckDB / MotherDuck vector store** that replaces
> Pinecone, **Anthropic prompt caching** that cuts input-token cost on Claude calls, and a
> **CSV export of the transcripts you actually selected**. It hooks MxChat entirely through
> WordPress filters and **never modifies a single file** of the host plugin.

---

## Read this first — two silent-failure prerequisites

The vector-store and prompt-cache modules degrade **silently** when their prerequisite is
missing: nothing errors, nothing is logged, the feature simply never engages. Check these
before filing a bug. (The transcripts module has no such prerequisite.)

### (a) MxChat streaming must be OFF for prompt caching to do anything

MxChat streams Claude responses with **raw `curl_exec()`**, not `wp_remote_post()` —
**8 occurrences** in `mxchat-basic/includes/class-mxchat-integrator.php` on version
3.2.21, including the one inside `mxchat_generate_response_claude_stream()` (line 11444).
That bypasses the WordPress HTTP API entirely, so the `http_request_args` filter this
module relies on is **never invoked** and no `cache_control` breakpoint can be injected.

**Action required:** turn streaming off in **MxChat → Settings** (the
`enable_streaming_toggle` key inside the host's `mxchat_options`). MxChat then falls back
to `mxchat_generate_response_claude()` (line 12411), which goes through `wp_remote_post()`
and is fully interceptable.

**Trade-off:** you lose the typewriter effect; you gain ~85–95 % input-token cost
reduction once the cache is warm (typically 3–5 requests).

Cacheable **even with streaming ON**: the admin Content Generator, the admin "Test API"
one-shot, and the non-streaming error fallback — all three use `wp_remote_post()`.
Not cacheable: the main chat while streaming, and the admin "Test streaming" button.

### (b) The Pinecone proxy needs a working HTTPS loopback and pretty permalinks

The DuckDB module's default integration (**Option B**) impersonates Pinecone over REST:
MxChat is told its "Pinecone host" is this site, and it calls back into
`/wp-json/mxchat-plus/v1/…`. Three things must hold, or every retrieval silently returns
zero matches:

1. **HTTPS must work, including loopback.** MxChat hardcodes `https://` when building the
   Pinecone URL. A self-signed certificate, an HTTP-only site, or a host that blocks the
   server calling its own domain all break the round-trip.
2. **Pretty permalinks must be on.** The plain `?rest_route=` form is not what MxChat
   constructs; `Settings → Permalinks` must be anything other than "Plain".
3. **The REST namespace must be reachable** — some security plugins and WAFs block
   `/wp-json/` wholesale. `curl -I https://your-site/wp-json/mxchat-plus/v1/health` from
   the server itself is the one-line check.

### A third thing worth knowing: `tools/patches/` targets a filter that does not exist

`tools/patches/mxchat-pre-vector-query.diff` adds an `mxchat_pre_vector_query` filter to
`find_relevant_content_pinecone()` so the DuckDB module can short-circuit the HTTP hop
(**Option A**). That filter is **not present in mxchat-basic 3.2.21** — verified against
all 53 hooks the host exposes. The patch is a **proposal for upstream**, it is **optional
and inert by default**, and applying it means modifying the host plugin, which this
project otherwise never does (and which an MxChat update will revert).

**The nominal, supported path is the REST proxy (Option B).** It needs no patch.

---

## The three modules

| Module | What it does | Where it lives |
|---|---|---|
| **DuckDB / MotherDuck vector store** | Stores MxChat's vector knowledge base in an embedded `.duckdb` file or in MotherDuck cloud, with HNSW-indexed similarity search, hybrid BM25 + vector retrieval, a query cache, Parquet export/import and a Pinecone → DuckDB migrator. Presents itself to MxChat as a Pinecone endpoint. | `includes/duckdb/`, `admin/views/duckdb/`, [`docs/duckdb/`](docs/duckdb/) |
| **Prompt caching** | Injects Anthropic `cache_control` breakpoints (tools, system, and a rolling pair on the last two user messages) into outbound Claude requests, and measures cached tokens across Anthropic, OpenAI, OpenRouter, xAI, DeepSeek and Gemini. | `includes/promptcache/`, [`docs/promptcache/USAGE.md`](docs/promptcache/USAGE.md) |
| **Transcripts CSV export** | Adds an export button to MxChat's transcripts screen: exports the conversations you ticked, or — with nothing ticked — every conversation between two dates, through a modal styled like the host's own. UTF-8 BOM for Excel, CSV-injection neutralised, 2000 conversations per export. | `includes/transcripts/`, [`docs/transcripts/USAGE.md`](docs/transcripts/USAGE.md) |

The modules are independent: each can be left idle without affecting the others. They share
only the plugin bootstrap, the `mxchat_plus_*` option namespace, the `mxchat-plus` text
domain and the `wp mxchat-plus` CLI root.

### Why a vector store

MxChat ships two backends: **MySQL** (embeddings in `LONGTEXT`, cosine similarity computed
in PHP — simple, slow past a few thousand rows) and **Pinecone** (fast, managed,
proprietary, per-record pricing). This adds a third: **DuckDB / MotherDuck** — an
analytical columnar database with a native [VSS](https://duckdb.org/docs/extensions/vss)
extension. Open source, $0 for embedded mode.

### Why prompt caching

MxChat resends a large `system` prompt and the full conversation history on every turn.
Anthropic's [prompt caching](https://docs.claude.com/en/docs/build-with-claude/prompt-caching)
reuses identical prefixes at roughly **0.1×** the input-token price, and cuts
time-to-first-token. The module places up to the 4 breakpoints Anthropic allows, using a
*rolling* pair on the conversation so the write breakpoint of turn *N* becomes the read
breakpoint of turn *N+1* — the hit rate stays flat as the conversation grows.

### Why a transcripts export

MxChat already ships `wp_ajax_mxchat_export_transcripts`, but it dumps the **whole**
`wp_mxchat_chat_transcripts` table unconditionally: the checkboxes on its own transcripts
screen have no effect on it, and there is no date filter. This module exports exactly what
was asked for — the ticked conversations, or a date range — under its own AJAX action and
its own nonce. The host renders that toolbar itself and exposes no hook to render into, so
the button is injected client-side and re-injected after each list re-render; the selection
is read back from the checkboxes, because the host keeps its `selectedSessions` Set private
to its own closure.

---

## Requirements

| Component | Version |
|---|---|
| PHP | ≥ 8.1 |
| WordPress | ≥ 6.0 |
| MxChat (`mxchat-basic`) | ≥ 3.2.21 |
| Site protocol | HTTPS with working loopback (DuckDB module, Option B) |
| Permalinks | Pretty (not "Plain") — DuckDB module, Option B |
| DuckDB runtime | PECL `duckdb` extension (preferred) **or** the `duckdb` CLI binary |
| MotherDuck (optional) | A token from [app.motherduck.com](https://app.motherduck.com) |
| Prompt caching | MxChat streaming **disabled** (see above) |

There are **no Composer runtime dependencies**. `composer.json` exists purely for the dev
toolchain (PHPUnit, PHPStan); the release zip ships no `vendor/` directory and the plugin
boots through its own `require_once` chain.

## Installation

```bash
cd wp-content/plugins/
git clone https://github.com/paulargoud/mxchat-plus.git
```

Activate **MxChat Plus** in the WordPress plugins screen, *after* MxChat itself. Or
download `mxchat-plus-x.y.z.zip` from [Releases](https://github.com/paulargoud/mxchat-plus/releases)
and use **Plugins → Add New → Upload Plugin**.

## Quick start

**DuckDB module**

1. **MxChat → DuckDB / MotherDuck** in the admin.
2. Pick a backend — *MotherDuck* (token + database name) or *Embedded* (leave the path
   empty for the default under `wp-content/uploads/mxchat-plus-private/`, protected by an
   auto-written `.htaccess` + `index.php` + `web.config`).
3. **Test connection.**
4. Ingest: *Sync MySQL → DuckDB* if MxChat already has embeddings in
   `wp_mxchat_system_prompt_content`, or *Reprocess all posts* if you are coming from
   Pinecone-only. Reprocessing calls the embedding API configured in MxChat and costs
   real money (typically a few cents for 100–500 posts).
5. Verify: `wp mxchat-plus duckdb stats`.

**Prompt-cache module**

1. Turn **off** streaming in MxChat settings (see the prerequisite above).
2. Nothing else to configure.
3. After a handful of chat turns: `wp mxchat-plus promptcache stats`, or read the
   dashboard widget.

**Transcripts module**

1. Nothing to configure — it is on by default in **MxChat Plus → Modules**.
2. Open **admin.php?page=mxchat-transcripts** (MxChat's transcripts screen).
3. Tick some conversations and click the download icon next to **Delete Selected** to
   export those; click it with nothing ticked to pick a date range instead.

## Relationship to the host plugin

`mxchat-basic` is a **third-party plugin and is never modified.** Everything happens
through WordPress hooks: `http_request_args` / `http_response` for prompt caching,
MxChat's own Pinecone-configuration filters plus a REST endpoint for the vector store, and
`admin_enqueue_scripts` plus an own-namespace `wp_ajax_` action for the transcripts export
(whose button is injected client-side, because the host's toolbar offers no hook).

Consequently the host's own symbols are used **verbatim, never renamed**: `mxchat_options`,
`mxchat_active_embedding_model`, `mxchat_system_prompt_content`, `mxchat_embedding_chunk_meta`,
`mxchat_vectors`, `mxchat_kb_*`, `mxchat_pinecone_*`, `mxchat_get_bot_options`,
`mxchat_get_bot_pinecone_config`, the `wp_ajax_mxchat_*` actions and their
`mxchat_*_nonce` nonces. Everything this plugin *owns* is prefixed `mxchat_plus_` /
`MXCHAT_PLUS_` / `MxChat_Plus_`. If you see an unprefixed `mxchat_` symbol in this
codebase, it belongs to the host and must stay exactly as it is.

## Documentation

| Doc | What's in it |
|---|---|
| [docs/duckdb/ARCHITECTURE.md](docs/duckdb/ARCHITECTURE.md) | How the vector store wires into MxChat, query lifecycle, file layout, design conventions. |
| [docs/duckdb/CONFIGURATION.md](docs/duckdb/CONFIGURATION.md) | Every option, sidecar options, where data lives, dimension/storage change guards. |
| [docs/duckdb/HOOKS.md](docs/duckdb/HOOKS.md) | Filters and actions the DuckDB module exposes. |
| [docs/duckdb/CLI.md](docs/duckdb/CLI.md) | `wp mxchat-plus duckdb …` reference with sample output. |
| [docs/duckdb/USAGE.md](docs/duckdb/USAGE.md) | Async reprocess, Pinecone migration, Parquet backup/restore, INT8 quantization, `/health`. |
| [docs/duckdb/MIRROR.md](docs/duckdb/MIRROR.md) | Local mirror for MotherDuck installs: when to enable, status states, troubleshooting. |
| [docs/duckdb/BACKUP.md](docs/duckdb/BACKUP.md) | Backup + restore workflow and disaster-recovery checklist. |
| [docs/promptcache/USAGE.md](docs/promptcache/USAGE.md) | Breakpoint strategy, per-model thresholds, filters, CLI, reading the hit rate. |
| [docs/transcripts/USAGE.md](docs/transcripts/USAGE.md) | The two export modes, the exact CSV format, the 2000-conversation cap, security, troubleshooting. |
| [tools/patches/README.md](tools/patches/README.md) | The optional, currently-inapplicable upstream patch (Option A). |
| [CHANGELOG.md](CHANGELOG.md) | Release history, including both pre-merge histories. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Filing bugs, sending PRs, running the test suite. |
| [SECURITY.md](SECURITY.md) | Reporting a vulnerability, scope, hardening defaults. |

## Limitations

**Vector store**

- **Shared hosting**: the PECL `duckdb` extension is rarely available; the CLI fallback
  uses `proc_open()`, which some hosts disable. CLI mode adds ~50–200 ms per query.
- **MotherDuck + CLI**: every query re-runs `ATTACH 'md:…'` (1–3 s handshake). Install the
  PECL extension for production traffic.
- **MotherDuck cloud has no VSS extension**, so HNSW is unavailable there. Enable the local
  mirror (recommended past ~100k vectors) or use the embedded backend; otherwise queries
  are brute-force scans. The admin surfaces a notice in that combination.
- **HNSW + `bot_id` filter**: DuckDB VSS does not push arbitrary `WHERE` clauses into the
  index, so multi-tenant queries fall back to a scan.
- **Embedding dimension** must match MxChat's active model; changes are blocked once the
  table holds vectors (wipe and re-sync to switch).
- **Direct SQL writes** to `wp_mxchat_system_prompt_content` outside MxChat's UI only
  propagate at the next incremental cron tick.

**Prompt caching**

- **Streaming is not interceptable** — see prerequisite (a).
- **OpenRouter cannot be injected into, by construction**: MxChat concatenates the RAG
  context *into* the `system` message there, putting volatile content at the head of the
  prefix and defeating prefix matching. OpenRouter is measured, never injected.
- **Multi-provider measurement is non-streamed only** (`wp_remote_post` responses); their
  streamed cURL responses are invisible.
- **Counters are best-effort** under concurrency: read-modify-write without a lock, so
  heavy parallel traffic can slightly undercount (statistics only — the cache is unaffected).
- **A dynamic system prompt kills the cache.** A shortcode or filter injecting the time,
  stock levels or `{visitor_name}` into the system prompt invalidates the prefix every
  turn; `stats` flags this as "cache writes > reads".

**Transcripts export**

- **2000 conversations per export.** A larger selection is truncated to the first 2000
  rather than rejected, and the truncation is silent — split the work into several exports.
- **The button is injected into markup this plugin does not own.** It anchors on the host's
  `#mxch-delete-selected` element; if a future MxChat release renames it, the button simply
  stops appearing (no error, nothing logged).
- **Reading `wp_mxchat_chat_transcripts` directly** is the one accepted coupling to the
  host's storage — it is the same schema the host's own export reads.
- **One synchronous request, no progress indicator.** A wide date range on a busy install
  can produce a very large file.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). TL;DR: PHP 8.1+, `composer lint`, `composer test`,
`composer stan`, one `## [Unreleased]` entry per user-visible change, and every
user-facing string through `__()` with the `mxchat-plus` domain — then re-run `msgfmt`
and commit the `.mo`.

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html), same as MxChat itself.

## Acknowledgements

- [MxChat](https://mxchat.ai/) — the chatbot plugin this companion extends (and never edits).
- [DuckDB](https://duckdb.org/) and the [VSS extension](https://duckdb.org/docs/extensions/vss).
- [MotherDuck](https://motherduck.com/) for the hosted DuckDB experience.
- [Anthropic](https://docs.claude.com/en/docs/build-with-claude/prompt-caching) for prompt caching.
