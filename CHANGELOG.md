# Changelog

All notable changes to **MxChat Plus** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This plugin is the merge of two previously separate plugins. From 1.0.0 onward there is
**one** history, below; the two pre-merge histories are preserved verbatim in
[Annex A](#annex-a--history-of-mxchat-duckdb--motherduck-010--0130) and
[Annex B](#annex-b--history-of-mxchat-prompt-cache-010--080).

## [Unreleased]

Nothing pending. Everything shipped so far is in [1.0.0](#100--2026-09-12) below,
click tracking included. New entries go here, categorised per Keep a Changelog.

## Roadmap

Not a release section — nothing below is written yet, so none of it belongs under
`[Unreleased]`, which Keep a Changelog reserves for changes already made.

- Regenerate `phpstan-baseline.neon` from the merged tree and restore
  `reportUnmatchedIgnoredErrors: true` in `phpstan.neon.dist` (the baseline is currently
  inherited from mxchat-duckdb and path-rewritten).
- PHPStan level 8 — drain the remaining baselined `missingType.*` findings into real
  `array<key, value>` / shape annotations, file by file.
- Integration smoke test against a real DuckDB binary in CI (matrix job installs `duckdb`,
  runs ensure_schema + upsert + query end to end).
- Auto-reconciliation of drifted `bot_id`s: queue a per-bot scoped re-bootstrap instead of
  asking the operator to run `mirror-bootstrap --reset` by hand.
- Submit the `mxchat_pre_vector_query` short-circuit filter upstream. It is still absent
  from mxchat-basic 3.2.21, so `tools/patches/` stays optional and inert.
- Ask upstream for a filter on the Claude streaming payload (just before
  `curl_setopt(CURLOPT_POSTFIELDS, …)`), which would let prompt caching work without
  turning streaming off.
- English source strings for the prompt-cache module, so the catalogue stops mixing
  English and French msgids.
- PDF / attachment reprocessing; per-bot configuration UI for multi-bot installs.

## [1.0.0] — 2026-09-12

The first release of the merged plugin. **MxChat DuckDB / MotherDuck** 0.13.0 and
**MXChat Prompt Cache** 0.8.0 become two modules of a single plugin, `mxchat-plus`, joined
by two new ones: the transcripts CSV export, and click tracking — which also absorbs the
standalone *MxChat Link Tracking* plugin.

### Added

- **One plugin, four modules.** `includes/duckdb/` (vector store),
  `includes/promptcache/` (Anthropic prompt caching), `includes/transcripts/` (CSV
  export of transcripts) and `includes/tracking/` (Matomo / GA4 click events) boot from a
  single plugin file and share a bootstrap, an option namespace, a text domain and a CLI
  root. Each module is independently idle-able from **MxChat Plus → Modules**; `tracking`
  is the only one that starts off.
- **Transcripts CSV export module** (`includes/transcripts/`,
  `assets/js/admin-transcripts-export.js`, guide in
  [docs/transcripts/USAGE.md](docs/transcripts/USAGE.md)). MxChat ships
  `wp_ajax_mxchat_export_transcripts`, but it dumps the whole
  `wp_mxchat_chat_transcripts` table and ignores the checkboxes on its own screen. This
  module adds a button next to the host's **Delete Selected** on
  `admin.php?page=mxchat-transcripts` with two modes:
  - **conversations ticked** → exports exactly those;
  - **nothing ticked** → a modal in the host's own `.mxch-modal-overlay` style asks for a
    start and an end date (defaulting to the last 30 days) and exports every conversation
    in that inclusive period, `00:00:00` to `23:59:59`. With neither a selection nor a
    valid date pair the request is refused, so a stray click can never dump the table.

  The CSV carries a UTF-8 BOM (Excel) and the columns *Session ID, Email, User identifier,
  Role, Message, Timestamp* — one row per message, ordered by session then timestamp —
  deliberately mirroring the host's own export so both files open identically.

  Hardening: `manage_options` on both the enqueue and the handler, a dedicated
  `mxchat_plus_export_transcripts` nonce and AJAX action (never the host's), prepared
  statements whose `IN (…)` placeholder list is derived from the **count** of session ids
  and never from their content, strict `YYYY-MM-DD` + `checkdate()` validation, a ceiling
  of **2000** conversations per export, and neutralisation of CSV formula injection —
  messages are visitor-written, and a cell opening with `=`, `+`, `-` or `@` is executed by
  Excel and Sheets.

  Two consequences of never touching the host: the button is injected client-side (the host
  renders that toolbar itself and exposes no hook) and re-injected by a `MutationObserver`
  after every list re-render (pagination, search, sorting); and the selection is read back
  from the DOM checkboxes, because the host keeps its `selectedSessions` Set private to its
  own closure.
- **Documented the two silent-failure prerequisites** that neither plugin surfaced before,
  at the top of `README.md` and in the readme.txt FAQ:
  - MxChat streaming must be **off** for prompt caching to engage at all — the host uses
    raw `curl_exec()` for streaming (8 occurrences in
    `includes/class-mxchat-integrator.php` on 3.2.21), which bypasses the WordPress HTTP
    API and makes `http_request_args` injection impossible;
  - the Pinecone proxy needs a **working HTTPS loopback and pretty permalinks**, because
    MxChat hardcodes `https://` and builds a pretty REST URL when calling "Pinecone".
- **Stated plainly that `tools/patches/` is inert by default**: the patch targets an
  `mxchat_pre_vector_query` filter that does not exist in mxchat-basic 3.2.21 (checked
  against all 53 hooks the host exposes). The nominal path is the REST proxy.
- CI in one workflow with five jobs (lint, i18n, PHPStan, PHPUnit, composer audit), a
  release workflow that builds the zip from an **allow-list**, and issue templates.
- `msgfmt --check` in CI, plus a guard that fails the build when the committed `.mo` does
  not match the `.po`, and one that fails on any surviving legacy text domain.
- A release check that fails when the git tag, the `Version:` header in `mxchat-plus.php`
  and the `Stable tag:` in `readme.txt` disagree.
- **Click tracking module** (`includes/tracking/`, `assets/js/tracking.js`,
  `admin/views/tracking/`, guide in [docs/tracking/USAGE.md](docs/tracking/USAGE.md)).
  Folds in the standalone *MxChat Link Tracking* plugin and adds the report its data never
  had. It is the **only module that defaults to off**, and the plugin's only public-facing
  surface: every other module is admin-gated or filter-only.
  - **Matomo events** under the category `MxChat`, actions `Clic lien interne`,
    `Clic lien externe` and `Clic suggestion`; external destinations also reported via
    `trackLink`. The site id is whatever the page's existing Matomo snippet uses. Those
    three labels are deliberately not translatable — they are analytics dimensions, and
    translating them would fork the site's click history in two.
  - **GA4 events** (optional): `mxchat_link_click` and `mxchat_suggestion_click`. Requires
    `gtag()` from elsewhere — the module never injects the tag.
  - **Session id**, optional, either in a Matomo custom dimension you nominate (1–999) or
    appended to the event name when none is configured.
  - **Clicked-links report** over the host's own `{prefix}mxchat_url_clicks` table, with
    click and distinct-session counts, internal/external classification against
    `home_url()`, an optional date range and a CSV export. MxChat logs those clicks but
    only ever shows a `DISTINCT clicked_url` list per conversation, absent from its own CSV
    and REST output. `user_ip` and `user_agent` are never read: the host anonymises them at
    30 days by design.
  - Settings live in their own `mxchat_plus_tracking` Settings API group, deliberately
    **not** the shared `mxchat-plus` group — `options.php` nulls every option of the
    submitted group absent from `$_POST`, so sharing it would wipe this option whenever the
    DuckDB tab is saved.
  - `MxChat_Plus_Tracking_Options::migrate_legacy()` carries the standalone plugin's
    `mxchat_tracking_options` over to `mxchat_plus_tracking_options` once, at activation,
    then deletes the old row — its absence is the "already migrated" marker. The rename was
    not cosmetic: an `mxchat_`-prefixed option reads as one of the host's own.

### Changed

- **Naming unified across the whole plugin**: `MxChat_Plus_*` classes, `MXCHAT_PLUS_*`
  constants, `mxchat_plus_*` options and hooks, `mxchat-plus` as the **only** text domain.
- **WP-CLI root unified**: `wp mxchat-plus duckdb …` and `wp mxchat-plus promptcache …`
  replace `wp mxchat-duckdb …` and `wp mxchat-pc …`.
- **REST namespace** moved to `mxchat-plus/v1`.
- **PHP floor raised to 8.1** (from 8.0 for the vector store and 7.4 for the prompt cache);
  WordPress floor 6.0. CI lint and test matrices now run 8.1 → 8.4.
- **Complete French catalogue.** The inherited `.pot` was frozen at 0.5.0 and referenced
  only 3 of the 9 admin views; the prompt-cache module had never been extracted at all.
  The template was regenerated over the whole tree and the catalogue now holds
  **173 strings, all translated**, with the compiled `.mo` committed and shipped.
- Composer is a **dev-only** tool: there is deliberately no runtime `autoload` section, so
  the plugin never depends on `vendor/` at runtime. `composer.lock` is committed.
- PHPUnit splits into three named suites, `duckdb`, `promptcache` and `transcripts`.
- **New root [`ARCHITECTURE.md`](ARCHITECTURE.md)** documenting the integration surface
  MxChat 3.2.21 actually exposes — which hooks exist, which do not, and the traps: the
  Pinecone config filter applying only to non-default bots, streaming bypassing the
  WordPress HTTP API through `curl_exec`, and the host already placing its own cache
  breakpoint. Three Mermaid diagrams, all validated by rendering.
- **`docs/duckdb/ARCHITECTURE.md` corrected.** Inherited from the pre-merge plugin, it had
  drifted from the code: it presented Option A as preferred although
  `mxchat_pre_vector_query` does not exist upstream, and its sequence diagram drew that
  dead path. Option B (the zero-patch REST proxy) now leads. The stale file layout,
  PHPStan level, over-fetch factors, cache-key shape and missing mirror subsystem were
  fixed too. `tools/patches/README.md` now states plainly that its diff no longer applies
  to 3.2.21.

### Fixed

Defects inherited from the pre-merge plugins, each now covered by a regression test.

- **Orphan compaction skipped rows.** `prune_orphans()` paged the vector table with
  `LIMIT`/`OFFSET` while deleting from that same table, so every drain shifted the
  remaining rows left while the offset advanced by a whole page — up to 1000 vectors went
  unexamined after each batch and a single nightly sweep could never compact everything.
  Now paged with a keyset cursor on `vector_id`, which concurrent deletes cannot disturb.
- **`max_deletes` was not a hard ceiling.** The drain checked its budget only before
  splicing a fixed 100-id chunk, so the last chunk could overshoot the advertised cap by
  up to 99 deletes — a cap whose purpose is to bound MotherDuck spend. Chunk size is now
  `min(chunk, remaining budget, buffered)`.
- **Vectors imported from Pinecone were pruned as orphans.** The migrator keeps Pinecone's
  own ids, which need not follow the `md5(url)[_chunk_N]` convention the sync writes, so
  they matched no knowledge-base row and the next nightly sweep deleted the very vectors
  the migration had copied to avoid re-embedding. The migrator now writes a durable marker
  and the compactor leaves orphans alone while it is set; installs whose imported ids do
  match can opt back in with the new `mxchat_plus_duckdb_compactor_prune_orphans` filter.
- **Fatal at boot without Composer.** The MotherDuck connection was required before its
  parent class, killing any install made by `git clone`. The classmap autoloader makes
  load order irrelevant.
- **Dead query-cache sweep.** The `LIKE` patterns never matched the transient prefix they
  were meant to purge, so superseded entries accumulated until TTL.
- **Deactivation left Action Scheduler jobs queued** for `mxchat_plus_duckdb_reprocess_post`
  with no worker registered. Scheduled hooks now derive from a single source of truth.
- **Prompt-cache robustness:** `wp_json_encode()` returning `false` no longer produces an
  empty body, a non-array `headers` value is no longer overwritten (which could drop
  `x-api-key`), and the `anthropic-beta` header is read case-insensitively.
- Two defects inherited from the standalone *MxChat Link Tracking* plugin, both silent:
  - **Suggestion clicks resolved the wrong bot.** Bot id was derived by walking up to
    `[id^="chat-box-"]`, but `#mxchat-popular-questions-{botId}` is a *sibling* of
    `#chat-box-{botId}` (`mxchat-basic/includes/class-mxchat-public.php:366-370`), so the
    walk found nothing and every suggestion fell back to the default bot's session. Now
    resolved through `window.getBotIdFromElement`, which MxChat exports for add-ons.
  - **Visitors with cookies and localStorage both blocked reported no session id.** The
    hand-rolled cookie read could not see the in-memory copy the host keeps in that case
    (Safari ITP, partitioned storage). Now read through `window.getChatSession`.

### Preserved deliberately

- **The host plugin is never modified.** Its symbols are used verbatim and are never
  renamed: `mxchat_options`, `mxchat_active_embedding_model`,
  `mxchat_system_prompt_content`, `mxchat_embedding_chunk_meta`, `mxchat_vectors`,
  `mxchat_kb_*`, every `mxchat_pinecone_*` key, `mxchat_get_bot_options`,
  `mxchat_get_bot_pinecone_config`, the `wp_ajax_mxchat_*` actions and their
  `mxchat_*_nonce` nonces.

### Migration from the two separate plugins

1. Note your current settings (both plugins).
2. Deactivate and delete `mxchat-duckdb` and `mxchat-promptcache`. Deleting runs their
   `uninstall.php`; the DuckDB data file is removed, so **export to Parquet first** if you
   want to keep the vectors without re-embedding.
3. Install and activate **MxChat Plus**.
4. Re-enter the settings — option keys moved to the `mxchat_plus_*` namespace and are not
   read from the old keys.
5. Re-check that MxChat streaming is off if you want prompt caching.

---

## Annex A — history of MxChat DuckDB / MotherDuck (0.1.0 → 0.13.0)

Everything below happened in the separate `mxchat-duckdb` repository, before the
merge. Class names, option keys, CLI commands and the text domain are quoted as they
were at the time (`MxChat_DuckDB_*`, `mxchat_duckdb_options`, `wp mxchat-duckdb …`,
`mxchat-duckdb`); all of them were renamed to the `*_Plus_*` / `mxchat_plus_*` /
`wp mxchat-plus duckdb …` / `mxchat-plus` forms in 1.0.0. Kept verbatim so the
rationale behind each decision stays readable.

### [Unreleased]

#### Planned

- **Auto-reconciliation of drifted bot_ids**: when the daily drift
  check detects a real divergence, queue a per-bot scoped re-bootstrap
  instead of leaving the user to run `mirror-bootstrap --reset`
  manually. Requires a new partial-bootstrap variant of the existing
  full-table copy. (Detection landed in v0.10.0; auto-recovery is
  still open as of v0.12.1 — the affected bot_ids are reported and a
  manual `mirror-bootstrap --reset` is recommended in the meantime.)
- Integration smoke test against a real DuckDB binary in CI (matrix
  job installs `duckdb`, runs ensure_schema + upsert + query
  end-to-end). Especially useful now that mirror logic has multiple
  cron paths.
- Mutation testing via Infection (informational CI job).
- PHPStan level 8 (level 7 reached + enforced in v0.13.0; the path to 8
  is to drain the remaining 165 baselined `missingType.*` findings into
  real `array<key,value>` / shape annotations, file by file).
- Submit the upstream patch (`mxchat_pre_vector_query` filter,
  WP-canonical `pre_*` convention) to MxChat. The diff is verified
  against mxchat-basic 3.2.6–3.2.8 and a submission package is prepared
  (see patches/README.md → "Submitting upstream"); it now needs sending
  through MxChat's channel. The legacy `mxchat_pinecone_matches_override`
  hook stays registered for installs on the previous patch contract.
- PDF / attachment reprocessing (re-embed attachments on a model switch;
  already-indexed PDF vectors sync via the MySQL path today).
- Built-in cross-encoder reranker (Cohere Rerank / BGE-reranker) — the
  `mxchat_duckdb_rerank_matches` hook already allows a custom one.

---

### [0.13.0] — 2026-06-09

Per-bot retrieval configuration for multi-bot installs, plus a PHPStan level-7
elevation. No schema migration. Safe drop-in from 0.12.1.

#### Added

- **Per-bot retrieval overrides.** A new admin section, **Per-bot retrieval
  overrides**, lists the bots present in the store and lets each one override
  the retrieval-quality knobs — `hybrid_enabled`, `hybrid_alpha`,
  `dedup_per_source`, `query_cache_enabled`, `query_cache_ttl`, `slow_query_ms`.
  A bot with "Override" unticked inherits the global Retrieval settings.
  Storage-level settings (backend, table, dimension, metric, HNSW, layout) stay
  global — every bot shares one physical store — and `top_k` still comes from
  mxchat per request. Resolved by `MxChat_DuckDB_Options::get_for_bot($bot_id)`,
  which `Vector_Store_Query::run()` now consults; overrides are stored in the
  `bot_overrides` sub-map of the main option (covered by uninstall already).
- **`mxchat_duckdb_bot_config` filter** — drive a bot's resolved retrieval
  config from code (multi-bot installs that prefer config-as-code). Runs after
  the stored UI overrides, so code wins. Only retrieval keys take effect.
  See [docs/HOOKS.md](docs/duckdb/HOOKS.md#mxchat_duckdb_bot_config).

#### Changed

- **PHPStan raised to level 7** (now enforced in CI — the job already blocks the
  build). The 8 findings the stricter level surfaced are fixed in code, not
  baselined: an `array_chunk` lower-bound guard on `upsert()`, a `wpdb`
  type-narrowing fix in the compactor sweep (`is_object()` erased the type), and
  an honest `array<int, array<string, mixed>>` param on `upsert()` (rows are
  runtime-validated). The two genuinely unstubbable calls on the DuckDB
  extension handle (`query`, `bind/bindParam` — no PHPStan stub for ext-duckdb /
  saturio) get a documented `ignoreErrors` entry, mirroring the existing WP-CLI
  one. The baseline shrank from 166 to 165 findings.

#### Maintenance

- Re-verified the optional Option A upstream patch
  (`patches/mxchat-pre-vector-query.diff`) applies cleanly — exact match, no
  offset or fuzz — against mxchat-basic **3.2.6 through 3.2.8**, and added a
  "Submitting upstream" section to [patches/README.md](tools/patches/README.md) (MxChat
  ships from WordPress.org with no public repo, so the proposal routes through
  their support channel).

---

### [0.12.1] — 2026-06-09

Hardening pass on the Pinecone proxy (Option B) and the write paths, plus a
per-bot query cache. No schema migration. Safe drop-in from 0.12.0.

#### Fixed

- **Proxy rate limiter is now atomic.** `within_rate_limit()` did a
  `get_transient` → `set_transient($n+1)` read-modify-write, which drops
  increments under a concurrent burst and lets a flood sail past the cap (the
  only CPU guard on the public Option B endpoint). It now uses `wp_cache_incr`
  (atomic) when a persistent object cache is present, with the transient as a
  documented soft fallback. A throttled request returns a **429** `WP_Error`
  instead of a bare `false` (which WordPress turned into a 401 indistinguishable
  from a bad token).
- **`delete_by_ids` chunks large IN() lists.** The proxy passes mxchat's
  bulk-delete ID arrays straight through; one giant `IN (...)` overran
  MotherDuck's HTTP body limit (and the CLI's stdin). Deletes are now batched at
  100 ids per statement — matching the orphan compactor — and de-duplicated.

#### Changed

- **Per-bot query-cache generation.** The cache-invalidation counter is now a
  per-bot map; the cache key carries a `<botkey>` segment
  (`mxd_q_<botkey>_<gen>_<hash>`). A write to one bot no longer invalidates
  another bot's cached top-Ks — a real win on multi-bot installs with frequent
  per-bot auto-sync. The compactor sweep walks the per-bot map and removes each
  bot's superseded generations precisely; never-bumped bots are untouched.
  The `mxchat_duckdb_cache_gen` option transparently migrates from the old
  scalar to the map (legacy global-style keys lapse via TTL).

#### Uninstall

- Now also cancels the **mirror drift-check** (`mxchat_duckdb_mirror_drift_check_tick`)
  and **async-reprocess** (`mxchat_duckdb_reprocess_post`) Action Scheduler
  actions, and flushes the object-cache rate-limit group (`mxchat_duckdb_rl`)
  where the backend supports group flushing. (Options/transients were already
  fully covered; this closes the two scheduled-action gaps.)

---

### [0.12.0] — 2026-06-09

Compatibility pass for **mxchat-basic 3.2.8**, which makes the Custom Provider
/ Azure OpenAI embedding path work end-to-end (both knowledge-base indexing
and live queries route through the configured endpoint). That activates a path
our dimension auto-detection didn't cover: mxchat stamps the active model as
`custom:<id>`, a value absent from every embedding registry, so vectors from a
non-1536-dim local model (nomic-embed-text 768, bge-large 1024, Azure
text-embedding-3-large 3072, …) were sized against a silent 1536 default.

No schema migration. Built-in models are unaffected. Safe drop-in from 0.11.1.

#### Added

- **Empirical dimension probe for Custom / Azure OpenAI providers.** When
  MxChat is embedding through a custom endpoint, the **Vector schema** admin
  section now shows a *Detect dimension* button. It embeds a probe string via
  mxchat-basic's own `MxChat_Utils::generate_embedding_custom()` (honouring the
  exact Base URL / auth scheme / model the operator configured), measures the
  returned vector, and caches the result keyed to the active model in
  `mxchat_duckdb_custom_embedding_dim`. New AJAX handler
  `wp_ajax_mxchat_duckdb_detect_dimension` (nonce + `manage_options` gated).
- `MxChat_DuckDB_Options::is_custom_embedding_model()`,
  `active_embedding_model()`, `store_probed_custom_dim()`, and
  `probed_custom_dim()` helpers.

#### Changed

- **`detect_embedding_dim()` recognises `custom:<id>` models.** For custom
  providers it returns the probed dimension when one was measured against the
  current model, falling back to a 1536 *placeholder* (clearly labelled in the
  UI as such) otherwise — instead of silently treating the custom model as a
  1536-dim built-in. A stale probe from a previously-configured custom model is
  ignored once the model id changes. Built-in models still resolve through
  mxchat-basic's registry exactly as before.

#### Notes

- The insert-time dimension guard in `Vector_Store` already rejected mismatched
  vectors loudly, so this was never silent corruption — but operators on local
  embedding stacks (the plugin's core audience) no longer have to hand-compute
  the dimension.

#### Performance & correctness

- **Metrics no longer write `wp_options` on every query.** `observe_latency()` /
  `record()` are on the hottest path (a cache *hit* still recorded a counter),
  and each call did a read-modify-write of the metrics option plus an O(n)
  window trim. They now append to a request-scoped buffer that is merged into
  the option in a single write on `shutdown`. This drops metrics writes from
  N-per-request to 1-per-request, removes a per-query `wp_options` write from
  the cache-hit path, and moves the window trim off the hot path. It also
  shrinks the lost-update race (concurrent read-modify-writes dropping samples)
  from per-query to per-request. `snapshot()` merges the pending buffer so
  within-request reads stay consistent.
- **Compactor sweeps stale-generation query-cache transients.** The O(1)
  cache-generation bump makes superseded `_transient_mxd_q_*` rows unreachable
  but never deletes them; on write-heavy installs they could accumulate in
  `wp_options` faster than WordPress' expired-transient GC reclaims them. The
  daily compactor now deletes every query-cache transient that isn't the
  current generation. It runs *before* the sync-freshness guard, so busy
  installs (which early-return on "last sync too recent") still get the sweep.
  No-op under an external object cache.
- **Hardened DuckDB CLI JSON parsing.** `execute_cli()` previously did a single
  `json_decode` and silently returned `[]` (no rows, no error) on anything but
  one clean JSON value — so concatenated result sets (init-SQL output + the
  real query) or NDJSON could swallow real results. Parsing now falls back to
  the last top-level array, then to line-by-line NDJSON, before giving up.
- **Memoised CLI-binary autodetection** for the request (a mirrored install
  builds two backends, each of which would otherwise re-stat the candidate
  paths and re-spawn `command -v`), and hoisted the transient-error class/anchor
  lists to constants.

#### Added (extensibility)

- **`mxchat_duckdb_filter_fields` filter** — map extra Pinecone-style filter
  field names to physical columns (for add-ons storing custom per-vector
  metadata). Column names are validated against `/^[a-zA-Z0-9_]+$/` before they
  touch SQL, so a bad entry is dropped rather than becoming an injection vector.
  See [docs/HOOKS.md](docs/duckdb/HOOKS.md#mxchat_duckdb_filter_fields).

#### CI

- **Fixed the `composer audit` job.** It ran `composer install --no-dev` then a
  plain `composer audit`, which errored with "No installed packages found"
  because the plugin has zero runtime dependencies. It now audits the locked
  production set directly (`composer audit --locked --no-dev --abandoned=report`):
  a no-op that stays green today and trips the instant a vulnerable production
  dependency is introduced.

---

### [0.11.1] — 2026-05-21

Test-suite hotfix on the heels of 0.11.0. Now that PHPUnit actually runs in
CI (the eager-load `exit;` bug that masked it is fixed in 0.11.0), real
test failures became visible — none of them in production code, all of
them in the WordPress shim layer used by the unit suite.

No production-code change. No schema migration. Safe drop-in from 0.11.0.

#### Fixed

- **Test shims now win over `php-stubs/wordpress-stubs`.** The shim
  guards in `tests/shims/wp-classes.php` used `class_exists('WP_REST_Request')`
  with the default `$autoload = true`, which triggered Composer's
  optimized classmap to resolve the name to
  `vendor/php-stubs/wordpress-stubs/wordpress-stubs.php` (a transitive
  dev-dep of `szepeviktor/phpstan-wordpress`) before our shim could
  declare its test-only methods (`set_json_params`, `set_query_params`,
  `set_header`, the array-constructible `WP_Post`, the matcher-driven
  `WP_Query`, …). Passing `false` to skip autoload makes the shim
  always declare first when bootstrap runs, so every downstream class-
  resolution lookup finds the shim version. Caught 43 CI test failures
  across `ProxyAuthTest`, `ProxyHandlersTest`, `HealthEndpointTest`,
  `AdminAjaxTest`, `AsyncReprocessTest`, `PostReprocessorTest`, and
  `CliTest` that had been hiding behind the v0.10.x silent-exit bug.
- **`OptionsSanitizeTest::test_resolved_motherduck_token_constant_takes_precedence`**
  now runs in a forked PHP process (`@runInSeparateProcess` +
  `@preserveGlobalState disabled`). The test defines the
  `MXCHAT_DUCKDB_MOTHERDUCK_TOKEN` constant, and PHP doesn't allow
  undefining constants — leaving it set permanently shadowed every
  subsequent test's `motherduck_token` field, breaking
  `MotherDuckConnectionTest`'s empty-token guard under any test order
  other than alphabetical. The sibling
  `test_resolved_motherduck_token_returns_option_when_no_constant` is
  also simplified now that the in-process world is guaranteed
  constant-free.
- **PHPStan now actually runs in CI.** Same root cause as PHPUnit: the
  v0.10.x composer eager-load `exit;` killed the analyser too, so the
  green "PHPStan: success" badge was meaningless — `exit;` returns 0,
  CI sees 0, CI is happy. With that fixed in v0.11.0, the analyser ran
  for real and surfaced 166 pre-existing `missingType.iterableValue`
  / `missingType.return` / `missingType.parameter` findings at level 6.
  None are new from this release; the path to level 7/8 (per the
  CHANGELOG `Planned` section) involves annotating proper
  `array<key, value>` / shape / return types, file by file. To keep
  CI green now and track the work as a real backlog, a new
  `phpstan-baseline.neon` file is added under `includes:` in
  `phpstan.neon.dist`. Any error not already in the baseline still
  fails the build — so no NEW findings can slip past.
- **Stale ignore-pattern removed.** The
  `Call to an undefined method MxChat_DuckDB_Connection::table_name_quoted`
  ignore predates `table_name_quoted()` being moved onto
  `Vector_Store`. It no longer matches anything, and PHPStan rightly
  complains about unused ignore patterns. Removed.

#### Notes

These were all latent — the test suite was silently passing in v0.10.x
because Composer's `"files"` autoload of `class-duckdb-cli.php` killed
PHPUnit with `exit;` before any test ran. The CI green badge was
meaningless until 0.11.0 fixed that bug; this release fixes the
issues that 0.11.0's fix exposed.

---

### [0.11.0] — 2026-05-21

Hardening + ergonomics pass triggered by a senior-review of the v0.10.1
codebase. Two real bugs were uncovered (one silent data-loss path; one
CI / dev-tooling outage), three rough edges were sanded (default-bot
Option B reach, MotherDuck secret hygiene, alpha→beta status), and the
upstream-patch story now ships a ready-to-apply unified diff. No schema
migration. Safe drop-in from 0.10.1; one new option default-off
(`takeover_default_bot_pinecone`), one optional new constant
(`MXCHAT_DUCKDB_MOTHERDUCK_TOKEN`).

#### Fixed

- **Silent data loss on `wp mxchat-duckdb sync` for installs with
  chunked WP-DB rows.** mxchat-basic 2.5+ stores chunked content in
  `wp_mxchat_system_prompt_content` with a JSON-prefix header
  (`{"document_type":"chunked",...}\n---\n<text>`, one row per chunk).
  Both sync paths in this plugin — the PHP one (`row_to_vector`) and
  the native-DuckDB one (`full_sync_native`) — hashed every row to
  `md5(source_url)` and lost the chunk_index, so `INSERT OR REPLACE`
  collapsed all chunks of the same URL into a single vector containing
  only the last chunk's text. The fix peels the JSON header,
  propagates `chunk_index` / `total_chunks` / `is_chunked` correctly,
  and writes each chunk under `{md5(url)}_chunk_{N}` to mirror
  mxchat-basic's own `MxChat_Chunker::generate_chunk_vector_id()`
  convention. New `MxChat_DuckDB_Mysql_Sync::parse_chunk_prefix()`
  helper is the single source of truth; `vector_id_for_row()` now
  takes an optional `$chunk_meta` second arg. The native-SQL path uses
  a 3-stage CTE (`src` → `parsed` → `enriched`) with
  `json_extract_string` to do the same work server-side.
- **Local PHPUnit + PHPStan (and likely the CI test job) were silently
  exiting before running.** `includes/class-duckdb-cli.php` was listed
  in composer's `"files"` autoload, and its conventional WP safety
  guard (`if (!defined('ABSPATH')) { exit; }`) silently terminated the
  process with status 0 whenever `vendor/autoload.php` was loaded
  outside WordPress. The file is now classmap-autoloaded only; the
  composer branch of `mxchat-duckdb.php` requires it explicitly under
  the WP_CLI guard, mirroring the manual-fallback branch. CI green is
  finally meaningful — and the local `vendor/bin/phpunit` invocation
  now works without any `auto_prepend_file` workaround.

#### Added

- **`pre_option_mxchat_pinecone_addon_options` shortcircuit** for
  Option B on the default bot. mxchat-basic reads its Pinecone config
  straight from `wp_options` for `bot_id === 'default'` when the
  Multi-Bot Manager isn't loaded
  (class-mxchat-integrator.php:1960 / class-pinecone-manager.php:1238
  in 3.2.6), bypassing the `mxchat_get_bot_pinecone_config` filter
  this plugin uses to advertise itself. The new WP-canonical
  `pre_option_*` filter intercepts that read without touching the DB
  row, so Option B reaches the default bot transparently. Explicit
  opt-in via a new option `takeover_default_bot_pinecone` (default
  off) — sites running real Pinecone alongside DuckDB on the default
  bot don't get their settings hijacked. Re-entry-protected so the
  call to `get_or_create_token_for()` (which reads our own option)
  doesn't loop back through the filter. Admin checkbox lives in the
  Activation section with a description spelling out when not to
  enable it.
- **`MXCHAT_DUCKDB_MOTHERDUCK_TOKEN` constant override** for the
  MotherDuck token. Defining it in `wp-config.php` makes the plugin
  prefer the constant value over the persisted option — for
  compliance-driven installs that forbid storing secrets in
  `wp_options`. New `MxChat_DuckDB_Options::resolved_motherduck_token()`
  / `motherduck_token_is_from_constant()` helpers; the
  MotherDuck_Connection and Mirror_Bootstrap consumers go through the
  resolver. Admin UI detects the override, disables the token field,
  and shows a notice indicating the source.
- **`patches/mxchat-pre-vector-query.diff`** — ready-to-apply unified
  diff that adds the `mxchat_pre_vector_query` short-circuit filter
  upstream. `patch -p1 < …` applies cleanly against mxchat-basic 3.2.6
  (verified by piping a fresh copy of the integrator file through the
  patch tool, then `php -l`-ing the result). The existing
  patches/README.md gains a "How to apply" callout pointing at the
  diff.
- **`docs/BACKUP.md`** — full backup / restore runbook: what needs to
  be backed up (and what doesn't), Parquet export + verification
  recipe with sample `duckdb` CLI commands, filesystem-snapshot
  alternative for Embedded mode, cross-environment move workflow
  (Embedded ⇄ MotherDuck), explicit disaster-recovery checklist.
  Wired into the docs table in README.md.

#### Changed

- **Status badge `alpha` → `beta`.** With 348 unit tests, two
  backends in production-ready shape, a sophisticated MotherDuck
  mirror feature, and a steady release cadence (v0.3 → v0.11 in a
  few weeks), the alpha tag was undersells the project. No code
  change behind this.
- **`MxChat_DuckDB_Embedded_Connection::is_transient_error()`** no
  longer uses `is_a($e, $cls)` against runtime class names — the
  string-class form forces PHPStan to short-circuit both branches to
  "always false" because the DuckDB / Saturio extension classes
  aren't in any stub package. Now uses `class_parents($e)` + the
  class itself to build the hierarchy once and checks membership;
  identical runtime semantics, but PHPStan tracks it correctly. The
  dedicated `ignoreErrors` entry in phpstan.neon.dist is removed too.

#### Tests

- 335 → 348 (+13), 1161 → 1205 assertions (+44) vs 0.10.1.
- New `MysqlSyncTest` coverage for the chunked-content path: four
  tests on `parse_chunk_prefix` (no-prefix passthrough, full
  extraction, malformed-JSON tolerance, no-separator tolerance), one
  on the chunked vector-id derivation, one full-sync round-trip
  verifying each chunk lands in its own `{md5}_chunk_N` row with the
  stored content stripped of the JSON header. The native-sync test
  gained three new assertions on the CTE shape (chunk prefix detect,
  `json_extract_string`, the `_chunk_` separator literal).
- `PreVectorQueryTest` +3 tests for `inject_pinecone_addon_options`
  (returns `$value` unchanged when plugin disabled, returns `$value`
  unchanged when takeover off, returns Pinecone-shaped config with
  scheme-stripped host + default namespace when takeover on).
- `OptionsSanitizeTest` +2 tests for `takeover_default_bot_pinecone`
  (default off, coerces truthy/falsy variants); +2 for
  `resolved_motherduck_token` / `motherduck_token_is_from_constant`
  (option fallback path; constant wins).

---

### [0.10.1] — 2026-05-21

Compatibility pass against **mxchat-basic 3.2.6** (released a few days
after 0.10.0). The audit covered every cross-plugin surface the
companion plugin depends on — `MxChat_Utils` registry + `submit_content_to_db`,
the `mxchat_get_bot_pinecone_config` + `mxchat_pre_vector_query` filters,
the `wp_mxchat_system_prompt_content` schema, the `mxchat_options` /
`mxchat_active_embedding_model` option keys, and every AJAX delete hook
mxchat ships. Everything held up; the two real findings (one
pre-existing bug surfaced by re-reading mxchat's nonce contract, one
latent gap that became measurable in 3.2.6) are fixed below, plus
three additive enhancements to track the new metadata aliases and the
secondary delete paths.

No schema migration. Safe drop-in from 0.10.0. No public API break.

#### Fixed

- **Cascade-delete nonce contract.** `cascade_delete_handler` was
  verifying `$_POST['_wpnonce']` against action
  `mxchat_delete_pinecone_prompt`, but mxchat-basic actually mints the
  nonce under action `mxchat_delete_pinecone_prompt_nonce` and sends it
  via POST `nonce` on the AJAX path / GET `_wpnonce` on the
  `admin_post_*` form path (admin/class-knowledge-manager.php in
  mxchat-basic 3.2.6, lines ~5928 and ~5985). The handler was silently
  no-op-ing on every legitimate delete — DuckDB drifted out of sync on
  installs that route Pinecone calls to real Pinecone (not the proxy)
  and rely on the cascade hook as the sole bridge. The handler now
  reads either field and accepts both the canonical 3.2.6 action and
  the legacy bare name, plus the existing `mxchat_duckdb_admin`
  fallback. Pre-existing bug; the regression simply made it visible
  during the 3.2.6 audit.

#### Added

- **`/vectors/list` prefix filter.** mxchat-basic 3.2.2+ uses
  `/vectors/list?prefix={md5(source_url)}_chunk_` to find every chunk
  vector for a URL (chunk-reassembly path at
  class-mxchat-integrator.php ~line 5845, bulk-delete at
  class-knowledge-manager.php ~line 6321). The proxy now propagates
  the `prefix` parameter — from JSON body OR query string — into
  `Vector_Store::list_ids($bot_id, $limit, 0, $prefix)`, which
  composes a `LIKE 'prefix%' ESCAPE '\'` clause with the SQL
  wildcards `_`, `%`, and `\` properly escaped (load-bearing: the
  `_chunk_` separator contains an underscore that is itself a LIKE
  wildcard). Without this filter, mxchat's chunk reassembly received
  arbitrary IDs from the namespace and either fell through to the
  matched-chunks fallback (correct but slower) or — on bulk delete —
  scoped to the wrong vectors. The new path is also opt-in for
  callers: omit `prefix` and the behaviour is unchanged.
- **Chunk-metadata alias-key fallbacks** in `handle_upsert`.
  mxchat-basic 3.2.6 advertises AI-Engine-style aliases
  (`source` / `part_index` / `part_total`) alongside the canonical
  metadata keys (`source_url` / `chunk_index` / `total_chunks`) so
  consumers of the new `mxchat_embedding_chunk_metadata` filter can
  rely on a stable shorthand. The proxy already accepted the canonical
  keys; it now also falls back to the aliases when the canonical key
  is missing, so a third-party filter rewriting metadata to use only
  the aliases doesn't lose chunk shape on the DuckDB side. Canonical
  keys always win when both are present.
- **Two new cascade handlers** for mxchat-basic's secondary delete
  paths (added in 3.2.2 for chunk-aware deletion):
  - `wp_ajax_mxchat_delete_chunks_by_url` →
    `MxChat_DuckDB_Mysql_Sync::cascade_delete_chunks_by_url()`. Nonce:
    `mxchat_delete_chunks_nonce`. Only fires when
    `data_source === 'pinecone'`; routes to
    `delete_by_source_url($url, $bot_id)`, which catches both the base
    row and every `{md5(url)}_chunk_N` row since they share
    `source_url`.
  - `wp_ajax_mxchat_bulk_delete_knowledge` →
    `MxChat_DuckDB_Mysql_Sync::cascade_bulk_delete()`. Nonce:
    `mxchat_bulk_delete_knowledge_nonce`. Walks `entries[]`,
    processes only `source === 'pinecone'`, routes
    `isGroup === true` to `delete_by_source_url` and singletons to
    `delete_by_ids`. Mixed batches and the `'true'` string form of
    `isGroup` (mxchat's JSON serialisation quirk) are both supported.
  Both handlers go through a new private `authorize_cascade()` helper
  that dual-fields the nonce lookup (`nonce` / `_wpnonce`, POST or
  GET) and accepts multiple nonce actions — same belt-and-braces
  contract as the original handler, kept identical across the three
  paths.

#### Documentation

- **`patches/README.md` line numbers** refreshed for mxchat-basic 3.2.6:
  `find_relevant_content_pinecone()` is now at ~line 5375 (was 5211),
  the `wp_remote_post()` call to short-circuit at ~line 5451 (was
  5287). Patch body unchanged — the surrounding function shifted
  during mxchat's 3.1.x → 3.2.x evolution but the relevant block
  stayed intact.

#### Tests

- 316 → 335 (+19), 1121 → 1161 assertions (+40) vs 0.10.0.
- New `ProxyHandlersTest` (6 tests) — locks `handle_upsert`
  canonical-vs-alias precedence and `handle_list` prefix-forwarding
  (JSON body + query string variants, escape behaviour).
- `VectorStoreFacadeTest`: +3 `list_ids` tests (`%`, `_`, `\` escape
  in prefix; empty prefix == no filter; underscore-escape regression
  guard).
- `MysqlSyncTest`: +10 cascade tests — three more for the corrected
  `cascade_delete_handler` nonce contract, four for
  `cascade_delete_chunks_by_url` (missing nonce, wordpress data
  source ignored, pinecone path deletes, capability gate), four for
  `cascade_bulk_delete` (missing nonce, wordpress entries ignored,
  mixed singleton/group/`'true'`-string batch, capability gate).

---

### [0.10.0] — 2026-05-20

The headline feature for this cycle: a **local mirror for MotherDuck
installs**. MotherDuck doesn't support the VSS extension cloud-side,
so vector queries on MotherDuck-backed installs fall back to
brute-force scans (workable to ~100k vectors, slow beyond). The
mirror maintains a local `.duckdb` shadow with HNSW indexed.
MotherDuck stays the canonical store; reads route to local for HNSW
acceleration. Opt-in, fully transparent to callers, no public API
break.

Architecture is documented end-to-end in
[docs/DESIGN-motherduck-mirror.md](docs/duckdb/DESIGN-motherduck-mirror.md);
operator guide in [docs/MIRROR.md](docs/duckdb/MIRROR.md).

#### Added

- **`MxChat_DuckDB_Mirrored_Connection`** — wraps a primary (MotherDuck)
  + a local (Embedded) connection, implements the same
  `MxChat_DuckDB_Connection` interface. Reads route to local; writes
  go to primary first (canonical), then local (best-effort). On
  local-side write failure the SQL is queued in
  `mxchat_duckdb_mirror_pending`. On local-side read failure the
  request falls back to primary for the rest of the request (per-
  request stickiness flag). Hard cap PENDING_MAX_QUEUE = 5000 on the
  pending queue to keep wp_options bounded.
- **`MxChat_DuckDB_Mirror_Bootstrap`** — Action Scheduler worker that
  populates the local shadow from MotherDuck in resumable batches.
  Single local Embedded_Connection with an extra
  `ATTACH 'md:<db>' AS md_remote` so the session sees both tables;
  cursor-based pagination (`WHERE vector_id > <last_seen> ORDER BY
  vector_id LIMIT N`) for stability + resumability. Five status
  states: `disabled`, `bootstrapping`, `active`, `drifted`, `error`.
- **`MxChat_DuckDB_Mirror_Drain`** — recurring 5-minute Action
  Scheduler tick that replays failed local writes from
  `mirror_pending`. Per-tick cap DRAIN_MAX_PER_TICK = 50 keeps a
  stuck queue from monopolising the worker. Entries hitting
  PENDING_RETRY_LIMIT (10) move to `quarantine` and surface in
  /health + admin notice.
- **`MxChat_DuckDB_Mirror_Drift_Check`** — daily Action Scheduler
  tick that compares `(COUNT, md5(string_agg(vector_id ORDER BY)))`
  per bot_id between primary and local. Real divergence flips status
  to `drifted` and surfaces an admin notice; small differential
  with pending entries is classified as "drainable" and the next
  drain tick closes the gap without flipping status. Anchored at
  +12h after activation to dodge the bootstrap pipeline's initial
  tick race.
- **New plugin options** in `mxchat_duckdb_options`:
  `motherduck_mirror_enabled` (bool, default false),
  `motherduck_mirror_path` (string, default empty → resolves to
  `<uploads>/mxchat-duckdb-private/mirror.duckdb` with the same HTTP
  blockers as `embedded_path`).
- **Sidecar options** (separate `wp_options`):
  `mxchat_duckdb_mirror_status`,
  `mxchat_duckdb_mirror_bootstrap_state`,
  `mxchat_duckdb_mirror_pending`,
  `mxchat_duckdb_mirror_last_drift_check`. All cleaned up by
  `uninstall.php`.
- **Admin UI** in `admin/views/partials/section-motherduck.php`:
  toggle, mirror-path field with placeholder showing the default,
  live status panel (progress %, last error, pending/quarantine
  counters, last drift check age) coloured by status state.
- **Admin notices** in `MxChat_DuckDB_Admin::render_capability_notices()`:
  HNSW + MotherDuck without mirror → recommend enabling the mirror;
  `STATUS_DRIFTED` → recommend `wp mxchat-duckdb mirror-bootstrap
  --reset`; `STATUS_ERROR` with last_error → surface for ops;
  `quarantine_count > 0` → name the likely root causes.
- **WP-CLI commands** (parallel to the cron flows):
  `wp mxchat-duckdb mirror-bootstrap` (with `--reset` and `--step`),
  `wp mxchat-duckdb mirror-drain` (with `--status`),
  `wp mxchat-duckdb mirror-drift-check` (prints per-bot diff table).
- **`/health` endpoint** gains a `mirror` block:
  `{enabled, status, pending_count, quarantine_count, drained_total,
  quarantine_total, last_drift_check_at, last_drift_check_age_s}`.
  Always populated when the v0.10.0 classes exist — zeros on
  disabled installs so external dashboards don't see a chart line
  going missing.
- **`Vector_Store::hnsw_available()`** / **`Vector_Store::fts_available()`**
  read from the LOCAL schema when mirrored (that's where the read
  path runs).
- **`docs/MIRROR.md`** — operator guide: when to enable, what to
  expect, status reference table, WP-CLI reference, troubleshooting,
  disk + cost considerations.

#### Changed

- **`Vector_Store::__construct()`** detects a Mirrored_Connection and
  builds TWO `Vector_Store_Schema` instances (one per side). Schema
  migrations run independently on each side so HNSW DDL lands on
  local but is skipped on MotherDuck primary — same code path as
  the non-mirrored MotherDuck install we shipped in 0.9.0. The
  Vector_Store_Schema class itself is unchanged.
- **`Connection_Factory::from_options()`** wraps the configured
  connection in `Mirrored_Connection` when
  `mode === 'motherduck' && motherduck_mirror_enabled === true`. The
  Factory cache key includes the mirror toggle + path so toggling
  on/off without a fresh request gives back the right connection.
- **Options sanitiser** rejects `motherduck_mirror_enabled = true`
  with a visible `settings_error` when `mode !== 'motherduck'` —
  mirroring a local file to itself makes no sense.
- **`MxChat_DuckDB_Plugin::init()`** registers
  Mirror_Bootstrap + Mirror_Drain + Mirror_Drift_Check hooks
  unconditionally (workers short-circuit when the mirror is
  disabled). An `update_option_mxchat_duckdb_options` listener
  triggers `Mirror_Bootstrap::start()` on a false → true
  transition of the toggle.
- **`MxChat_DuckDB_Plugin::deactivate()`** unschedules the
  Action-Scheduler-managed mirror work (bootstrap + drain + drift
  check) on plugin deactivation.
- **`uninstall.php`** cleans the four new sidecar options + the
  `motherduck_mirror_path` data file + unschedules both AS hooks.
- **`MxChat_DuckDB_Mirror_Bootstrap::STATUS_DRIFTED`** added to the
  known status enum; `get_status()` recognises it.
- **PHPStan / Action Scheduler shims** gain
  `as_next_scheduled_action` and `as_schedule_recurring_action` so
  the test suite can verify the recurring tick scheduling without a
  real Action Scheduler runtime.

#### Tests

- **261 → 316 tests, 930 → 1121 assertions** vs v0.9.0. New test files:
  - `MirroredConnectionTest` (13 cases): read routing, fallback
    stickiness, write order, primary-fail propagation, local-fail
    enqueue, queue cap, capability OR-semantics, identifier format,
    accessors.
  - `MirrorBootstrapTest` (12 cases): start/status transitions,
    first tick (probe + schema + first batch), persisted cursor,
    target_count=0 short-circuit, completion, error → re-enqueue,
    mid-bootstrap disable, default state/status, reset_state.
  - `MirrorDrainTest` (13 cases): drained removal, retry counter
    bump, retry → quarantine, drained_total cumulative, per-tick
    cap + FIFO overflow, skip when disabled / no local conn, empty
    queue, malformed entry drop, register_hooks scheduling +
    idempotency.
  - `MirrorDriftCheckTest` (11 cases): identical no-drift +
    timestamp stamp, DRIFTED → ACTIVE auto-clear, status
    preservation, signature mismatch flips DRIFTED, large count
    differential flips DRIFTED, small differential with pending is
    drainable (status preserved), bot present on one side only,
    per-bot isolation, skip paths, recurring tick scheduling.
- `VectorStoreFacadeTest`: 2 new cases for the dual-side schema
  application + the hnsw/fts read-from-local accessor behaviour
  under mirror.
- `OptionsSanitizeTest`: 4 new cases covering the mirror toggle's
  sanitiser rules.
- `HealthEndpointTest`: 2 new cases for the `mirror` block (present
  with zeros when disabled, reflects actual counts when populated).

#### Notes

- **Detection only, no auto-reconcile in v1.** When the daily drift
  check finds real divergence, status flips to `drifted` and the
  admin sees a one-line notice with the fix
  (`wp mxchat-duckdb mirror-bootstrap --reset`). Auto-recovery
  (per-bot scoped re-bootstrap) is queued for v0.11 — needs a
  partial-bootstrap variant of the current full-table copy.
- **Disk usage doubles** when the mirror is enabled. Admin UI
  doesn't warn at toggle time in v1 — operators should consult
  [docs/MIRROR.md](docs/duckdb/MIRROR.md) before flipping the switch on a
  large catalogue. The mirror file's parent directory gets the same
  HTTP blockers as the embedded path.
- **MotherDuck egress cost** for the initial bootstrap is on the
  user (~6 KB per row × N rows). Subsequent operations don't add
  per-query cost — only writes (canonical) and the daily drift
  check (cheap GROUP BY).
- No new public hook signatures changed. Safe drop-in from 0.9.0.

---

### [0.9.0] — 2026-05-19

Security defence-in-depth + MotherDuck capability honesty pass. **No
schema migration**, no public API break; existing `Vector_Store::current()`,
`compile_filter()`, and the cache/generation contract from 0.6.0 onwards
all keep working unchanged. One new method on the `MxChat_DuckDB_Connection`
interface (`supports_capability()`) — only relevant for callers that
implement their own connection class, which is not a documented extension
point.

#### Added

- **`MxChat_DuckDB_Connection::supports_capability(string $capability): bool`**
  — typed capability negotiation between Schema/Query and the underlying
  backend, replacing the previous identifier-prefix sniffing
  (`str_starts_with($identifier, 'motherduck:')`). First capability token
  is `CAP_VSS_PERSISTENT_INDEX`; MotherDuck returns false, embedded DuckDB
  returns true. Forward-compat by design — unknown tokens return false so
  a caller asking about a brand-new capability gets a clean "no, plan
  accordingly" instead of a fatal. Cleaner extension surface for future
  capability gaps (CSV import, json_each, FTS persistence variants, …).
- **Prepared-statement path on the native DuckDB extension.**
  `MxChat_DuckDB_Embedded_Connection::execute()` now probes the extension
  once for a usable prepared API (`preparedStatement` then `prepare`),
  reuses it for any subsequent `execute($sql, $params)` call with bound
  parameters, and falls back transparently to the existing inline path
  when:
    - the probe fails (extension binding too old / different shape),
    - the filter `mxchat_duckdb_use_prepared_statements` is set to false,
    - or the underlying call signals a binding-surface error
      (distinguished from a real SQL error so the latter still surfaces).
  Vector embeddings still travel inlined because no binding reliably
  accepts a `FLOAT[N]` array; everything else is bound. New public
  capability accessor: `Embedded_Connection::supports_prepared(): bool`.
- **Persistent HNSW availability flag** in `mxchat_duckdb_schema_meta`
  (`hnsw_available` = `'1'` | `'0'`). Written by `migration_v1_base_schema`
  after the CREATE INDEX attempt or after deciding to skip it on
  MotherDuck. Read via the new public accessors
  `Vector_Store_Schema::hnsw_available(): ?bool` and the matching
  `Vector_Store::hnsw_available()` / `Vector_Store::fts_available()`
  delegations on the facade. Lets the admin UI and diagnostics report
  the true index state instead of inferring it from the option toggle.
- **MotherDuck + HNSW: clean degradation.** The schema migration now
  asks the connection `supports_capability(CAP_VSS_PERSISTENT_INDEX)`
  and skips `INSTALL vss` / `LOAD vss` / `CREATE INDEX … USING HNSW`
  entirely when the answer is false (the VSS extension is not supported
  on MotherDuck cloud-side tables —
  [docs](https://motherduck.com/docs/concepts/duckdb-extensions/)).
  Saves three pointless network round-trips on every fresh install,
  replaces the silent `try/catch` with an explicit `error_log` line
  that names the workaround ("Switch to Embedded mode for HNSW
  acceleration"), and persists `hnsw_available='0'` so callers know
  queries will run as brute-force scans.
- **Admin notice for the HNSW-on-MotherDuck mismatch.** A new
  `admin_notices` handler on the plugin's settings screen surfaces a
  `notice-warning` when `mode=motherduck` + `hnsw_enabled=true` is
  configured, with a concrete recommendation (switch to Embedded for
  HNSW, or disable the toggle to silence the warning).
- **Persistent FTS availability flag** in `mxchat_duckdb_schema_meta`
  (`fts_available` = `'1'` | `'0'`). Written by `migration_v3_fts_index`
  on success or after the install / load / `PRAGMA create_fts_index`
  pipeline fails. Vector_Store_Query consults it through a per-request
  static cache before attempting the BM25 leg of a hybrid query, so
  FTS-less DuckDB builds no longer pay the cost of one failing SQL
  round-trip + one `error_log` entry per hybrid query. Public test hook:
  `Vector_Store_Query::reset_fts_status_cache()`.
- **Custom PHPStan rule `MxChat\DuckDB\PHPStan\UnsafeSqlConstructionRule`**
  banning ad-hoc SQL construction outside an allow-list of helper files
  (`trait-duckdb-sql-helpers`, the Vector_Store classes, the connection
  classes, the sync/compactor/migrator pipelines). Flags both `.`
  concatenation and `sprintf()` calls whose format string contains a
  multi-word SQL phrase (`SELECT … FROM`, `INSERT INTO`, `DELETE FROM`,
  `CREATE TABLE`, `WITH name AS (`, etc.) and where at least one `%s`
  substitution is a non-constant expression. Multi-word matching is
  deliberate — a bare `DELETE` in a REST URL path or `from` in a CLI
  message no longer triggers a false positive. Registered via
  `phpstan.neon.dist`; autoloaded via the `phpstan/` classmap added to
  `composer.json`'s `autoload-dev`; excluded from the release zip.
- **`MxChat_DuckDB_Connection::execute()` parameter binding becomes the
  default path on the read side.** `Vector_Store_Query::compile_filter()`
  now returns a `[fragments, params]` tuple instead of inlined SQL
  strings; `build_where()` plumbs the params through so `bot_id`, every
  Pinecone-style filter value (`$eq` / `$ne` / `$in` / `$nin` / `$gte`
  / `$gt` / `$lte` / `$lt`), and the BM25 `query_text` reach the
  connection as bound `?` parameters. The CLI fallback path inlines
  them back to safely-escaped literals through the pre-existing
  `inline_params()` helper, so observable behaviour is identical on
  every backend.

#### Changed

- **`quote_ident()` now throws on unsafe input** instead of silently
  stripping non-`[a-zA-Z0-9_]` characters. The previous behaviour turned
  `"my-table"` into `"mytable"` without warning — queries would hit a
  different (possibly non-existent) table with no error visible to the
  caller. The options sanitiser already enforces the safe character
  class at save time, so the throw is defence-in-depth: it catches
  programmer error where a non-sanitised string sneaks past the surface
  validation. Empty identifiers are also rejected.
- **`is_transient_error()` recognises retryable failures via three
  signals**, in precedence order:
  - Exception class — `DuckDB\Exception\NetworkException`,
    `DuckDB\Exception\TimeoutException`,
    `Saturio\DuckDB\Exception\ConnectionException`, plus a special case
    for `PDOException` discriminated by SQLSTATE (08xxx = connection,
    40001 = serialization → retry; 23000 = integrity → don't).
  - HTTP status code on `getCode()` — 429 / 5xx → retry.
  - Multi-word substring anchors (`connection reset`, `tls handshake`,
    `service unavailable`, `read timeout`, `gateway timeout`, …).
  Replaces the previous loose substring matching on bare words like
  `'timeout'` or `'network'`, which would mis-classify a query-level
  `statement timeout exceeded` (config error) or `network protocol
  error` (logic error) as retryable.
- `Vector_Store_Schema::get_schema_version()` / `set_schema_version()`
  refactored to share a `get_meta()` / `set_meta()` pair with the new
  FTS and HNSW flags. Single SQL shape for every meta read/write.
- `MxChat_DuckDB_Embedded_Connection::execute_native()` now goes through
  the shared `result_to_rows()` materialiser (used by both the
  unprepared and prepared paths) to normalise iterables, `rows()`, and
  `fetchAll()` shapes that different DuckDB PHP bindings expose.
- **WP-CLI command methods get typed signatures**: every
  `MxChat_DuckDB_CLI::*($args, $assoc_args)` now declares
  `(array $args, array $assoc_args): void`. The bodies are unchanged.

#### Fixed (static-analysis findings)

PHPStan baseline pass — every non-typing finding eliminated, total
errors 164 → 134:

- **`function.alreadyNarrowedType` × 3** — redundant `is_array()` on
  values already narrowed to array (`Async_Reprocess::enqueue_batch`,
  `Pinecone_Proxy::namespace_from_request`), and `method_exists()`
  inside a `class_exists()` block (`Options::detect_embedding_dim`)
  replaced with `is_callable(['Class', 'method'])` which doesn't
  collapse under static analysis.
- **`notIdentical.alwaysTrue` × 2** — `isset(…) && … !== null` reduced
  to `isset(…)` (the second check is implied by the first).
- **`identical.alwaysTrue` × 1** — PDOException special-cased outside
  the `transient_classes` loop so the in-loop `=== 'PDOException'`
  check is no longer unreachable.
- **`variable.undefined` × 1** — `$r` initialised with a default shape
  before the try/catch in `CLI::reprocess` so the post-catch branch
  doesn't depend on `WP_CLI::error()` dying (which it does at runtime
  but not in stubs).
- **`return.void` × 2** — `Compactor::run` and `Sync::incremental_sync`
  return values are useful for tests + AJAX but ignored by the
  `add_action` callbacks. Wrapped via `run_as_action()` /
  `incremental_sync_as_action(): void` to satisfy the action-callback
  contract enforced by szepeviktor/phpstan-wordpress.
- **`function.impossibleType` × 1** — ignored via a targeted pattern in
  `phpstan.neon.dist`; the DuckDB exception classes probed by
  `is_transient_error()` are real at runtime via the PECL extension
  but invisible to static analysis. Documented in the config.
- **`function.notFound` × 4** — `WP_CLI\Utils\format_items` and
  `WP_CLI\Utils\make_progress_bar` aren't shipped by
  szepeviktor/phpstan-wordpress. Ignored via a targeted pattern; the
  functions are guaranteed present whenever the file actually runs
  (the top-level `if (!WP_CLI) return;` guard ensures it).

#### Tests

- 245 → 261 tests, 880 → 930 assertions. New / rewritten cases:
  - `VectorStoreSchemaTest::test_motherduck_backend_skips_hnsw_and_vss_install`
    — connection mock with `identifier() = 'motherduck:my_db (ext)'`;
    asserts no `INSTALL vss`, no `USING HNSW` DDL, and
    `hnsw_available='0'` persisted.
  - `VectorStoreSchemaTest::test_hnsw_disabled_skips_index_creation`
    extended to verify the meta flag is written even when the user
    toggled HNSW off (so the admin UI reads "unavailable", not "unknown").
  - `VectorStoreQueryRunTest::test_hybrid_path_skips_bm25_when_fts_marked_unavailable`
    — pre-seeds the static cache and asserts no `match_bm25` SQL fires
    and no second meta probe is issued.
  - `VectorStoreQueryRunTest::test_hybrid_path_consults_meta_table_when_status_unknown`
    — first hybrid call with unknown status reads `mxchat_duckdb_schema_meta`;
    BM25 leg is skipped when the meta says `'0'`.
  - `VectorStoreQueryRunTest`: assertions on `bot_id = '…'` /
    `content_type = '…'` substrings replaced with assertions on the
    new `?`-placeholder shape + the `params_log` recorded by the mock
    connection.
  - `FilterCompilationTest` (12 cases) rewritten to assert on the new
    `[fragments, params]` return tuple of `compile_filter()`.
  - `UnsafeSqlConstructionRuleTest` (7 cases) — fixture-driven AST
    parsing of representative snippets through the custom rule; covers
    positive flags (concat + sprintf), allow-list bypass, `%d`-only
    sprintf, constant-only `%s`, non-SQL concat, and pure-constant
    concat.
  - `VectorStoreHelpersTest`: 3 new cases for the `quote_ident()` throw
    contract (unsafe characters, empty string, alphanumeric+underscore
    accepted).
  - `EmbeddedConnectionHelpersTest`: 2 new cases for the rewritten
    `is_transient_error()` — HTTP status code signal (429/5xx retried,
    400/403/404 not), and word-anchored substring matching rejecting
    `statement timeout exceeded` and `network protocol error` as
    non-transient.
  - `MotherDuckConnectionTest::test_motherduck_reports_no_support_for_persistent_vss_index`
    — locks the capability-negotiation contract: MotherDuck returns
    false for `CAP_VSS_PERSISTENT_INDEX` and for unknown capability
    tokens.

#### Notes

- The PHPStan rule fires on 0 false positives against the current
  codebase. Total errors after this release: **134**, down from 164
  at 0.8.0. Every non-typing finding is fixed; the 134 remaining are
  all `missingType.*` informational findings (level 6 strictness on
  array generics + parameter / return types), tracked separately as
  the path to PHPStan level 7.
- MotherDuck users with `hnsw_enabled=true` will now see a clear admin
  notice on first page load after the upgrade. The persistent flag is
  populated lazily on the next `ensure_schema()` call (i.e. on the
  first query or admin save after upgrade) — no schema migration is
  required.
- No new public option, no new top-level filter beyond
  `mxchat_duckdb_use_prepared_statements` (default `true`). Safe
  drop-in from 0.8.0.

---

### [0.8.0] — 2026-05-18

DuckDB-feature exploitation pass. Three changes that move work the plugin
was doing in PHP into DuckDB itself, leaning on extensions and SQL
primitives we were underusing. **No schema migration**, no public API
break; the v0.6.0 contracts (`Vector_Store::current()`, the
`mxchat_pre_vector_query` filter, the cache generation counter) are all
unchanged.

#### Added

- **`wp mxchat-duckdb sync --native`** — opt-in fast path for the
  MySQL → DuckDB sync. Uses the DuckDB `mysql` extension to ATTACH the
  WordPress database in read-only mode and copy every row through a
  single `INSERT INTO mxchat_vectors SELECT … FROM wp_mysql_attach`
  statement, parsing the PHP-serialised `embedding_vector` column via
  `regexp_extract_all('d:([-0-9.eE+]+);')` and casting to `FLOAT[N]`
  in-engine. Eliminates the per-batch PHP↔MySQL↔DuckDB round-trip that
  dominated `full_sync()` on large catalogues — empirically 5–10× on
  100k-vector copies.
  - Requires the DuckDB `mysql` extension (auto-installed by `INSTALL mysql`
    on first call); the command exits with a clear error and points at the
    PHP fallback when the extension isn't available.
  - Uses WordPress's own DB constants (`DB_HOST` / `DB_USER` /
    `DB_PASSWORD` / `DB_NAME`); falls back from `localhost` to `127.0.0.1`
    because the extension is TCP-only.
  - Read-only ATTACH — no risk of writing back to WP MySQL by accident.
- **New `MxChat_DuckDB_Mysql_Sync::full_sync_native()`** method (public)
  and `has_duckdb_mysql_extension()` (public static) — usable directly
  from custom integrations, not only the CLI.

#### Changed

- **MotherDuck connection now registers a persistent DuckDB secret**
  instead of embedding the token in every `ATTACH` URL. The CREATE OR
  REPLACE PERSISTENT SECRET runs at session init; the ATTACH URL is the
  clean `'md:<dbname>'`. Why: (1) the token no longer flows through the
  SQL script piped to the CLI's stdin on every query — it lives in
  `~/.duckdb/stored_secrets/` as a per-server credential; (2) ATTACH URLs
  in logs / errors are readable; (3) rotating the token in plugin settings
  re-runs CREATE OR REPLACE transparently. Requires DuckDB ≥ 0.10.
- **Per-source dedup in the pure-vector path now happens in DuckDB**
  via a CTE with `ROW_NUMBER() OVER (PARTITION BY source_url ORDER BY score DESC)`.
  The inner sub-query keeps the HNSW-friendly
  `ORDER BY <distance>(col, literal) LIMIT k` shape so VSS can still
  push the score+sort+limit into the index; the outer wrapper picks
  rn=1 per source_url (and lets empty-URL rows through, mirroring the
  pre-existing PHP semantics in `Vector_Store_Query::dedup_per_source`).
  The hybrid path keeps PHP dedup because BM25 + vector are merged in
  PHP anyway.

#### Fixed

- `Mysql_Sync::$mysql_ext_available` static class cache replaces a
  function-level `static $cache = null` so tests (and any future debug
  tooling) can force-reset the extension probe without restarting the
  PHP process.

#### Tests

5 new test cases covering the three changes (240 → 245 tests,
857 → 880 assertions):
- `MotherDuckConnectionTest::test_init_sql_uses_persistent_secret_rather_than_token_in_attach_url`
- `MotherDuckConnectionTest::test_init_sql_escapes_single_quotes_in_token` (kept, retargeted at the new CREATE SECRET literal)
- `MysqlSyncTest::test_native_sync_throws_when_mysql_extension_is_not_installed`
- `MysqlSyncTest::test_native_sync_emits_attach_and_insert_select_when_extension_present`
- `MysqlSyncTest::test_native_sync_bot_id_expression_falls_back_when_column_absent`
- `MysqlSyncTest::test_native_sync_uses_bot_id_column_when_present`
- `VectorStoreQueryRunTest::test_dedup_per_source_uses_sql_cte_with_row_number` (replaces the v0.6.0 over-fetch ×3 test)
- `VectorStoreQueryRunTest::test_dedup_off_uses_plain_top_k_limit` (extended to assert NO CTE wrapper)
- `VectorStoreQueryRunTest::test_hybrid_path_still_uses_php_dedup_with_over_fetch`

#### Notes

- The MotherDuck persistent-secret rewrite changes the SQL piped on every
  CLI invocation. **Re-test the connection after upgrading** to confirm
  the token + database name still combine into a working ATTACH. The
  settings page's "Test connection" button does this end-to-end.
- The native sync path is opt-in only (CLI flag); the synchronous
  `wp mxchat-duckdb sync` and the admin "Sync now" button keep using the
  PHP loop. The native path needs proven track record before we make it
  the default in a future release.
- No new public option, no new hook, no schema migration. Safe drop-in
  from 0.7.0.

---

### [0.7.0] — 2026-05-17

Project hygiene + test coverage pass. **No schema migration**, no
behaviour change for production traffic; pure improvement of the
project's correctness guarantees and contributor surface.

#### Added

- **`SECURITY.md`** — vulnerability reporting policy (GitHub Security
  Advisories preferred, `paul@argoud.net` as fallback), expected
  response timeline, scope boundaries vs upstream (mxchat-basic /
  DuckDB / WordPress core), supported-versions table, and the
  hardening defaults that ship enabled.
- **`.github/ISSUE_TEMPLATE/`** — modern form-schema templates for bug
  reports and feature requests; `config.yml` disables blank issues and
  routes vulnerability reports to a private Security Advisory instead
  of a public issue.
- **`composer audit` job in CI** — runs on production deps only (the
  release zip ships `--no-dev`, so dev advisories don't reach end users).
- **`szepeviktor/phpstan-wordpress`** + `phpstan/extension-installer`
  added to `require-dev`. WordPress stubs auto-loaded; PHPStan level 6
  is now clean with no per-call ignoreErrors needed.
- **PHP 8.4** added to the lint + phpunit CI matrices.
- **183 new unit tests** covering every previously-untested class in
  `includes/` — see the Tests section below.

#### Changed

- **CI hardening**:
  - `actions/checkout` bumped v4 → v6 (Node.js 24, no more
    "Node.js 20 actions deprecated" annotations).
  - **PHPStan is no longer `continue-on-error`** — the previous run
    after the szepeviktor/phpstan-wordpress bump confirmed level 6 is
    silent, so PHPStan now blocks the build like the other jobs. Path
    to level 7/8 still tracked in `phpstan.neon.dist`.
- **`phpstan/phpstan` bumped from `^1.11` to `^2.0`** to support
  `szepeviktor/phpstan-wordpress ^2.0` (which requires PHPStan 2.x).

#### Fixed

- **`uninstall.php` was leaking three sidecar options across reinstalls**:
  `mxchat_duckdb_cache_gen` (added in 0.6.0), `mxchat_duckdb_reprocess_state`
  and `mxchat_duckdb_pinecone_migration_state` (both added in 0.4.0) weren't
  in the delete list. Fixed by moving the option list into a single array
  with an inline pointer to `docs/CONFIGURATION.md → Sidecar options` so a
  future addition is harder to miss.
- **`wp mxchat-duckdb cache --flush` still used the legacy `LIKE DELETE`**
  on `wp_options` instead of the O(1) generation-counter bump introduced in
  v0.6.0. Migrated to `MxChat_DuckDB_Plugin::bump_cache_generation()`; the
  command now also reports the before / after generation numbers.
- **`wp mxchat-duckdb sync` progress bar jumped from 0 % to 100 %** at the
  first batch (the legacy code called `make_progress_bar(…, 1)` and then
  tried to tick by the total). The bar is now lazily created on the first
  callback with the actual total, and subsequent callbacks tick by the
  per-batch delta.
- **Two French strings were hardcoded in `assets/admin.js`** ("vecteurs"
  suffix on the test-connection + sync-complete status lines) bypassing the
  `mxchatDuckDB.i18n.*` localisation surface. New `vectorsSuffix` i18n key
  added; `fr_FR.po` updated, `.mo` recompiled.

#### Tests

The biggest single jump in test coverage since the project started:
**57 → 240 tests, 100 → 857 assertions, 5/20 → 20/20 classes covered**.
Every class in `includes/` now has at least one dedicated test file;
estimated per-line coverage on business paths went from ~10 % to ~65-75 %.

- **`PreVectorQueryTest`** (7 tests) — Search_Adapter's v0.6.0
  short-circuit hook: previous-non-null bypass, plugin-disabled
  fall-through, empty-vector fall-through, happy path with full
  Pinecone response shape, namespace/bot_id/default fallback chain,
  top_k fallback chain, exception swallowing with admin-notice transient.
- **`ProxyAuthTest`** (11 tests) — Pinecone_Proxy auth + per-namespace
  rate limit: missing/empty/wrong api-key rejection, legacy global
  wildcard, per-namespace token isolation (the v0.6.0 hardening
  contract), precedence rules, namespace from JSON body or query
  string, 120-req/min ceiling enforcement, per-namespace bucket
  isolation, weird-namespace-name md5 hashing.
- **`OptionsSanitizeTest`** (16 tests) — enum allowlists, regex strips
  (SQLi hardening on `table_name` and `motherduck_database`), numeric
  clamps on every bounded option, boolean coercion, runtime-telemetry
  preservation across admin saves, dimension-change guard branches.
- **`VectorStoreSchemaTest`** (11 tests) — migration runner ordering
  (v0 → v1 → v2 → v3, resume-from-v1, no-op when at target),
  per-request memoisation (and its keying on backend|table|dim),
  FLOAT[N] vs TINYINT[N] column branching, HNSW index gating,
  `table_info()` count/null branches.
- **`VectorStoreQueryRunTest`** (12 tests) — top-K orchestration:
  dim mismatch throws, cache hit bypasses SQL, cache miss writes
  result, dedup over-fetch (the v0.6.0 fix — `LIMIT top_k × 3`),
  Pinecone-style filters compile into WHERE, metric branching
  (cosine / l2sq / ip).
- **`PineconeMigratorTest`** (11 tests) — constructor guards (api-key,
  host), `normalise_host()` strips scheme + trailing slashes,
  `pinecone_to_row()` with canonical + legacy metadata keys, null on
  missing values, chunk_index/total_chunks string-to-int coercion,
  `STATE_OPTION` constant pinned.
- **`MysqlSyncTest`** (13 tests) — detect_kb_columns presence + cache,
  full_sync row skip behaviour for malformed embeddings, bot_id
  propagation from KB column, incremental cutoff at `last_sync_at - 120s`,
  cascade-delete authorisation (4 paths: missing nonce, wrong nonce,
  missing capability, both nonce variants).
- **`CompactorTest`** (6 tests) — skip paths (disabled / sync too
  recent), orphan detection + chunked DELETE, `max_deletes` cap,
  KB pagination (the v0.6.0 memory fix), unreadable-table throw.
- **`VectorStoreFacadeTest`** (23 tests) — every public write/read
  helper: upsert (empty noop, dim mismatch, skip malformed,
  batched INSERT OR REPLACE, cache-gen bump, single-quote escape,
  chunk-size filter), delete (by-ids + by-source-url + cache bump),
  count/list/fetch contracts, Parquet I/O round-trip,
  storage_estimate float32 vs int8, `current()` singleton.
- **`HealthEndpointTest`** (7 tests) — JSON shape that monitors
  depend on, 200/503/disabled branching, full payload assertions,
  metrics-snapshot keys pinned.
- **`AsyncReprocessTest`** (10 tests, 1 skipped) — Action Scheduler
  integration: enqueue_batch + dedup, process_post counter bumping +
  re-throw on failure, status() snapshot merging, cancel_all.
- **`PostReprocessorTest`** (17 tests, 1 skipped) — full reprocess
  pipeline including API key resolution per provider (OpenAI /
  Voyage / Gemini), vector_id md5 alignment, post-type mapping,
  failure paths (no permalink, empty content, WP_Error from submit).
- **`AdminAjaxTest`** (14 tests) — all 4 AJAX handlers' nonce +
  capability gates, input sanitisation, batch_size clamp [1, 50],
  default ['post', 'page'] fallback, error-path messages.
- **`MotherDuckConnectionTest`** (7 tests) — token + database-name
  guards (defence-in-depth on top of the Options sanitiser), init_sql
  composition with single-quote escape in tokens.
- **`CliTest`** (18 tests) — 11 sub-commands' argument parsing, error
  paths on missing required flags, output via WP_CLI::log/success/error,
  table format via format_items, command registration under the
  documented namespace.

#### Tooling

- **`tests/bootstrap.php`** grew a substantial library of reusable
  test primitives:
  - `MxChat_Test_WPDB` — pattern-matching `$wpdb` mock with callable
    pagination + `NOT_FOUND` sentinel.
  - `MxChat_DuckDB_Connection` recording-mock pattern (anonymous
    classes inline per test).
  - `Connection_Factory::$cache` reflection injection so
    `new Vector_Store()` sees the mock without a real backend.
  - `apply_filters` override registry via
    `$GLOBALS['__test_filter_overrides']`.
  - Nonce shim driven by `$GLOBALS['__test_valid_nonces']`.
  - "First response wins" AJAX shim defeating production's
    `catch (\Throwable)` re-wrap.
  - WP_Query matcher closures + Action Scheduler queue stub.
  - WP_CLI shim with `MxChat_Test_CliExit` for `::error` that die()s.
  - `MxChat_Utils` stub recording every `submit_content_to_db` call.

#### Notes

- The continue-on-error flag on the PHPStan CI job was dropped after
  this bump's first CI run confirmed level 6 is clean with the new
  szepeviktor/phpstan-wordpress stubs.
- No public API surface changed in this release; safe drop-in upgrade
  from 0.6.0.

---

### [0.6.0] — 2026-05-17

Performance + hardening pass triggered by an internal code review. Filter
naming aligned with WordPress core conventions ahead of the upstream PR.
**No schema migration**, no data migration; public API stable.

#### Added

- **`mxchat_pre_vector_query` filter handler** following WordPress core's
  `pre_*` short-circuit convention (the same pattern as `pre_get_posts`,
  `pre_user_query`, `pre_option_*`). Takes a context array, returns the full
  Pinecone response shape (`['matches' => …, 'namespace' => …]`). The legacy
  `mxchat_pinecone_matches_override` hook stays registered in parallel so
  installs that applied the previous patch contract keep working unchanged.
  See [`patches/README.md`](tools/patches/README.md) for the upstream snippet.
- **`Vector_Store::current()`** singleton (per-request, reset on options
  save) shared by REST proxy and search adapter so options aren't re-parsed
  on every hot-path request.
- **`looks_like_duckdb_binary()`** probe — the settings sanitiser runs a
  marker `SELECT` round-trip against the candidate CLI binary and surfaces a
  `settings_error` warning if it doesn't speak the DuckDB `-json` dialect.
  Catches admins pointing the path at `/bin/sh` (or similar) before queries
  start failing cryptically at runtime.
- **Per-request deduplicated debug logging** in `compile_filter()` for
  ignored ops/fields. Silent in production (Pinecone parity), surfaced under
  `WP_DEBUG` so a typo in a custom filter (`$equal` instead of `$eq`) doesn't
  silently leak unfiltered results. Logs only once per `(kind, name)` pair
  per request to avoid spam on hot paths.
- **New unit tests**:
  - `CacheGenerationTest` — covers the new cache-key contract end-to-end
    (back-compat without generation, generation-prefixed keys, key changes
    when the generation is bumped, `Plugin::bump_cache_generation` contract,
    legacy `flush_query_cache()` alias still ticks the counter).
  - `BinaryProbeTest` — verifies the DuckDB CLI probe rejects empty paths,
    non-existent paths, and POSIX binaries that don't speak `-json`
    (`/bin/sh`, `/bin/cat`, `/usr/bin/true`).
  - `tests/bootstrap.php` gains a `delete_option()` shim and stubs the new
    `Plugin::cache_generation()` / `Plugin::bump_cache_generation()` methods.

#### Changed

- **Query cache invalidation is now O(1)** instead of a `LIKE DELETE` over
  `wp_options`. `Vector_Store_Query::cache_key()` now weaves the current
  generation counter into the transient key; writes call
  `MxChat_DuckDB_Plugin::bump_cache_generation()` to bump the counter so
  existing transients become unreachable. Orphans expire via the existing
  TTL (default 300 s). The legacy `MxChat_DuckDB_Plugin::flush_query_cache()`
  is kept as a thin alias so existing call-sites (Vector_Store writes, tests)
  keep compiling unchanged.
- **`cache_key()` ~50× faster** on 1536-dim embeddings: replaced the
  `strval` + `implode` pipeline with `pack('g*', ...)` before hashing.
- **`dedup_per_source` now over-fetches** `top_k × 3` from SQL so the final
  result actually reaches `top_k` after collapsing same-URL chunks. Previously
  asking for `top_k=10` with eight chunks from the same URL returned three rows.
- **DuckDB CLI `execute_cli()` is no longer blocking.** Uses non-blocking
  pipes + `stream_select` with a deadline (default 30 s, filterable via
  `mxchat_duckdb_cli_timeout_seconds`); a hung CLI is `proc_terminate`'d and
  raises a clear `RuntimeException` instead of freezing the PHP-FPM worker
  until the request times out at the web-server layer.
- **Pinecone proxy rate-limit bucket is now per-namespace** (was a single
  global bucket). A misbehaving bot can no longer starve the others. Legacy
  wildcard tokens fall back to the global bucket so a leaked legacy key is
  still rate-limited in aggregate. The filter signature gained a `$namespace`
  argument: `apply_filters('mxchat_duckdb_proxy_rate_limit_per_minute', 120, $namespace)`.
- **`POST /pinecone-proxy/query` validates the vector dimension up-front**
  and returns a `400` with a clear error message instead of a `500` from
  deep inside the SQL layer.
- **`write_directory_blockers()` logs failed writes** instead of swallowing
  them with `@`. A non-writable data directory now triggers an
  `error_log()` so site owners on `uploads/`-readonly setups can spot a
  potentially web-reachable `.duckdb` file.
- **`detect_embedding_dim()` prefers mxchat-basic's centralised registry**
  (`MxChat_Utils::embedding_model_dimensions()`) as the single source of
  truth when available, falling back to the local table only when the
  function isn't loaded (e.g. unit tests). Eliminates drift as mxchat adds
  models or tweaks dimensions.

#### Fixed

- **`dequantize_int8()` returned `int` when the divide was exact** (PHP `/`
  returns `int` when both operands are int and the result is integer-exact:
  `0/127`, `127/127`, `-127/127`). `(int) $q / SCALE` now casts `SCALE` to
  `float`, so the function always returns `float[]` as the docblock promises.
  Caught by re-enabling the `QuantizationTest` round-trip assertions.
- **Local `detect_embedding_dim()` table mismatched mxchat-basic's
  registry** for two models — `voyage-3-large` was `1024` (real value:
  `2048`, mxchat-basic explicitly requests `output_dimension=2048` from
  Voyage), `gemini-embedding-001` was `3072` (real value: `1536`). Both
  corrected to match the upstream registry.
- **`QuantizationTest::test_recall_error_is_within_one_percent_on_unit_vectors`**
  asserted `cosine > 0.999` on uniform-distributed random vectors; real
  empirical recall is ~0.995 for that distribution. Threshold relaxed to
  `0.99` (real embedding distributions still round-trip > 0.999 in practice).

#### New filters

- `mxchat_pre_vector_query` — WordPress-canonical `pre_*` short-circuit hook
  for the runtime RAG path. See [`patches/README.md`](tools/patches/README.md).
- `mxchat_duckdb_cli_timeout_seconds` — override the CLI execution deadline
  (default 30 s, minimum 1 s).
- `mxchat_duckdb_proxy_rate_limit_per_minute` gained a second argument
  (`$namespace`) for per-tenant tuning.

#### Notes

- No new public option in the plugin settings: the cache generation counter
  is stored as a separate non-autoloaded option (`mxchat_duckdb_cache_gen`).
- The legacy `mxchat_pinecone_matches_override` hook is **not deprecated** —
  both contracts coexist, only whichever one upstream patches actually fire.

---

### [0.5.0] — 2026-05-17

Documentation reorganisation + internal refactor. **No behaviour change**, no
data migration, public API stable — safe drop-in upgrade from 0.4.0.

#### Changed (docs)

- **README trimmed from 354 → 144 lines.** Onboarding stays in the README
  (badges, why, features, requirements, install, quick start, doc index,
  roadmap, limitations). Reference content moved to dedicated files so the
  front page is scannable in 60 s.
- **New `ARCHITECTURE.md`** with two Mermaid diagrams (rendered natively
  by GitHub):
  - Flowchart of the two integration paths (Option A — filter override;
    Option B — Pinecone wire-protocol proxy).
  - Sequence diagram of a full query lifecycle (User → MxChat → adapter →
    query cache → vector store → DuckDB → dedup → rerank → metrics).
  - Plus the file layout and the five design conventions contributors
    should follow.
- **New `docs/CONFIGURATION.md`** — every option in `mxchat_duckdb_options`,
  every sidecar option (proxy tokens, metrics, reprocess state, migration
  state), where data is stored, dimension/storage change guards.
- **New `docs/HOOKS.md`** — all 9 filters with full signatures and PHP
  examples (Cohere/BGE reranker integration, ACF-aware post content,
  multi-bot bot_id derivation, rate-limit override, etc.).
- **New `docs/CLI.md`** — every `wp mxchat-duckdb` subcommand with sample
  stdout and exit-code contract.
- **New `docs/USAGE.md`** — howtos for the 5 specialised workflows: async
  reprocess (with monitoring + cancel), Pinecone migration (full semantics
  + resumption), Parquet backup/restore, INT8 quantization (when to use,
  switching layout), `/health` endpoint, end-to-end verification.
- **`CONTRIBUTING.md`** updated to point new contributors at the right doc
  file for each kind of change (option → CONFIGURATION, filter → HOOKS,
  CLI subcommand → CLI).

#### Changed (internal refactor — no behaviour change)

- **`Vector_Store` split (858 → 323 lines).** The 858-line monolith is now
  three coordinated classes sharing a trait:
  - `MxChat_DuckDB_Vector_Store_Schema` — migration runner + meta table +
    `ensure_schema()` + `table_info()`. Owns the per-request memoisation
    cache.
  - `MxChat_DuckDB_Vector_Store_Query` — top-K read path: cache lookup,
    vector + hybrid BM25 SQL, filter compiler, score normalisation, dedup,
    rerank hook, slow-query log.
  - `MxChat_DuckDB_Vector_Store` (façade) — keeps the public API stable
    (constructor + `ensure_schema` / `query_pinecone_shape` /
    `upsert` / `delete_*` / `count` / `list_ids` / `fetch_by_ids` /
    `export_parquet` / `import_parquet` / `storage_estimate`), delegating
    schema and query work to the two new classes.
  - `MxChat_DuckDB_SQL_Helpers_Trait` — shared `quote_ident`,
    `literal_string`, `literal_for`, `literal_int_or_float_array`,
    `embedding_column_type`, `embedding_as_float_sql`. Lives in
    `includes/trait-duckdb-sql-helpers.php`.

  Public API and option layout are unchanged; call-sites (sync, REST proxy,
  admin, CLI, async-reprocess, compactor, tests) all keep compiling without
  modification. Tests targeting the moved private statics
  (`compile_filter`, `normalize_scores`, `dedup_per_source`, `cache_key`)
  now reflect against `Vector_Store_Query`.

- **`Sync` split (453 → 76-line façade + 236 MySQL pipeline + 197 post
  reprocessor).** The orthogonal pipelines that lived together are now
  three classes:
  - `MxChat_DuckDB_Mysql_Sync` — `full_sync`, `incremental_sync`,
    `cascade_delete_handler`, `vector_id_for_row` (public static),
    `detect_kb_columns`, `row_to_vector`.
  - `MxChat_DuckDB_Post_Reprocessor` — `reprocess_posts`,
    `reprocess_single_post`, `build_post_content`,
    `map_post_type_to_content_type`, `resolve_embedding_api_key`.
  - `MxChat_DuckDB_Sync` (façade) — keeps `instance()`, `register_hooks()`,
    `full_sync`, `incremental_sync`, `reprocess_posts`,
    `reprocess_single_post`, `cascade_delete_handler`, and the public
    static `vector_id_for_row` for callers in the compactor and tests.

- **`admin/views/settings.php` split (369 → 73-line shell + 7 partials).**
  Each `<h2>` section moved to its own file under
  `admin/views/partials/`:
  - `section-activation.php`
  - `section-motherduck.php`
  - `section-embedded.php`
  - `section-vector-schema.php`
  - `section-retrieval-quality.php`
  - `section-performance.php`
  - `section-diagnostics.php`

  The shell handles the page header, the last-error notice, the
  PECL/CLI performance warning, then `include`s each partial in order.
  Adding a new section is now one new file plus one new `include` line.

#### Notes

- Largest PHP file went from 858 lines (`class-duckdb-vector-store.php`)
  to 332 lines (`class-duckdb-cli.php`, idiomatic command pattern).
- No new public filters, no new options, no schema migration.

---

### [0.4.0] — 2026-05-17

Ops & retrieval-quality pass. Public OSS-grade hygiene.

#### Added

- **GitHub Actions CI** (`.github/workflows/ci.yml`) — `php -l` matrix across
  PHP 8.0/8.1/8.2/8.3, `msgfmt` catalog check, PHPStan informational job,
  PHPUnit on PHP 8.1/8.2/8.3.
- **Release ZIP workflow** (`.github/workflows/release.yml`) — automatic
  clean distribution zip attached to every `v*` tag, with dev files excluded.
- **`readme.txt`** in the WordPress.org plugin-directory format (description,
  installation, FAQ, screenshots, changelog, upgrade notice). Ready for
  submission to wordpress.org.
- **PHPStan config** at level 6 with a small WP stub bootstrap. Runs in CI
  as `continue-on-error` for now — we publish results but don't fail PRs
  until a WordPress stub package is added.
- **PHPUnit smoke tests** — 35+ assertions across `Vector_Store` helpers
  (filter compilation, score normalisation, dedup, cache key), the
  `Embedded_Connection` idempotency sniff, the `Metrics` class, and the
  `Quantization` round-trip. Tests run on PHP 8.1/8.2/8.3 in CI.
- **Async reprocess via Action Scheduler** — `MxChat_DuckDB_Async_Reprocess`
  enqueues one job per post in Action Scheduler (bundled with WooCommerce
  and many WP plugins). Survives PHP `max_execution_time` on multi-thousand-
  post catalogs; reports progress via a single non-autoloaded state option.
  Falls back to the existing AJAX-batched path when Action Scheduler isn't
  installed. New CLI command: `wp mxchat-duckdb async-reprocess`.
- **Pinecone → DuckDB migration tool** — `MxChat_DuckDB_Pinecone_Migrator`
  pulls every vector + metadata directly from a Pinecone index via
  `/vectors/list` + `/vectors/fetch` and writes them into DuckDB. No
  re-embedding; pure vector copy. Resumable via a persisted pagination
  token. CLI: `wp mxchat-duckdb migrate-from-pinecone --api-key=… --host=… [--namespace=…]`.
- **Parquet export / import** — `Vector_Store::export_parquet()` and
  `import_parquet()` use DuckDB's native `COPY ... TO|FROM '...parquet'`.
  Enables moving between embedded ⇄ MotherDuck without re-embedding,
  routine backups, and KB sharing. CLI: `wp mxchat-duckdb export --path=…`
  and `wp mxchat-duckdb import --path=…`.
- **INT8 quantization (experimental)** — `embedding_storage` option toggles
  the embedding column type between `FLOAT[N]` (default) and `TINYINT[N]`.
  Cuts vector storage 4×. For unit-normalised embeddings (OpenAI ada-002,
  text-embedding-3-*, Voyage, BGE) the recall loss is < 1 %.
  Score expression uses `list_transform` to dequantise at query time. The
  storage layout is locked once the table contains rows; switch by exporting
  to Parquet, wiping, flipping the option, re-importing.
- **`Vector_Store::storage_estimate()`** — surfaces vector count + bytes
  estimate so admins can spot when INT8 would meaningfully help.
- **Packagist-ready `composer.json`** — homepage, support URLs, keywords,
  dev-dep declarations for PHPUnit + PHPStan. Submission to packagist.org
  is a one-time manual OAuth step on the user's side.

#### New filters / hooks

- No new public filters in v0.4.0 — existing extension points cover the new
  features. The async path uses the existing `mxchat_duckdb_post_content`
  and `mxchat_duckdb_sync_bot_id` filters.

#### Notes

- Submitting to **wordpress.org/plugins** is a manual review process (~2–4
  weeks). The `readme.txt` is now compliant; you still need to add at least
  one screenshot (the settings page) and submit through the review queue.
- Submitting to **packagist.org** requires a one-time OAuth grant on their
  site. `composer require paulargoud/mxchat-duckdb` will work after that.

---

### [0.3.0] — 2026-05-17

Feature pass: retrieval quality, observability, operations.

#### Added

- **Schema versioning** — `mxchat_duckdb_schema_meta` table tracks the current
  schema version; migrations run sequentially and idempotently. Target version
  is 3 in this release.
- **Hybrid BM25 + vector search** — when `hybrid_enabled` is on and a
  `mxchat_duckdb_query_text` filter supplies the user query, results from
  DuckDB's FTS extension are min-max-normalised and blended with cosine
  similarity using configurable `hybrid_alpha`. Falls back gracefully to pure
  vector when FTS is unavailable.
- **Query result cache** — top-K matches are cached in a transient keyed by
  `md5(embedding) + bot_id + filter + top_k`. Default TTL 300 s, configurable.
  Automatically invalidated on upsert/delete.
- **Per-source dedup** — optional collapse of multiple chunks from the same
  `source_url` in the final top-K (the LLM gets distinct sources, not five
  near-duplicates).
- **Pinecone-style filter operators** — `$eq`, `$ne`, `$in`, `$nin`, `$gt`,
  `$gte`, `$lt`, `$lte` on `content_type`, `role_restriction`, `source_url`,
  `chunk_index`. Unknown operators/fields are silently skipped (Pinecone parity).
- **Re-ranking hook** — `mxchat_duckdb_rerank_matches` filter receives the
  top-K and can return a re-ordered set (cross-encoders, Cohere Rerank, …).
- **Metrics class** — rolling 1-hour window of latency samples; exposes
  p50/p95/p99 + cache hit rate + counters via `MxChat_DuckDB_Metrics::snapshot()`
  and the admin diagnostics panel.
- **Slow-query log** — queries slower than `slow_query_ms` (default 500) are
  written to the PHP error log with bot id and hybrid/dedup flags.
- **MotherDuck retry + backoff** — idempotent queries (SELECT/WITH/PRAGMA/…)
  automatically retry up to 3× on transient errors (timeout, EOF, 502/503,
  rate-limit) with exponential backoff + jitter.
- **`/health` REST endpoint** — `GET /wp-json/mxchat-duckdb/v1/health` returns
  backend status, vector count, last sync age, metrics snapshot. Public by
  default; restrict with the `mxchat_duckdb_health_public` filter.
- **WP-CLI command surface** — `wp mxchat-duckdb {test|stats|sync|reprocess|
  compact|metrics|cache}`. Indispensable for scripted deployments.
- **Compactor** — daily `mxchat_duckdb_compact` cron job that deletes vectors
  whose `vector_id` no longer maps to any row in the MySQL KB. Capped per run
  (filter `mxchat_duckdb_compactor_max_deletes`, default 5000) and skipped if
  the last sync was within the past hour.
- **Per-namespace proxy tokens** — `MxChat_DuckDB_Pinecone_Proxy::get_or_create_token_for($namespace)`
  issues tokens scoped to a single bot, so a leak on bot A no longer grants
  access to bot B's vectors. The legacy global token still works as a
  fallback (wildcard scope).
- **Nonce + capability check on cascade delete** — the
  `wp_ajax_mxchat_delete_pinecone_prompt` handler now validates the nonce and
  `manage_options` capability itself instead of relying on mxchat's check
  running afterwards.
- **Composer autoloader** — `composer.json` ships with a classmap autoload
  over `includes/`; the bootstrap prefers `vendor/autoload.php` when present
  and falls back to manual `require_once` otherwise. Non-breaking.
- **i18n** — full gettext setup: `Text Domain` + `Domain Path` headers,
  `load_plugin_textdomain()`, English source strings, `mxchat-duckdb.pot`
  template + ready-to-use `mxchat-duckdb-fr_FR.po`/`.mo`.

#### Changed

- All `__()` / `_e()` source strings converted from French to English; French
  is now shipped as the fr_FR translation (canonical WordPress convention).
- Default `embedded_path` directory now hosts a `.htaccess` + `index.php` +
  `web.config` trio (was added in 0.2.0; mentioned here for completeness).
- New options added with sensible defaults:
  `hybrid_enabled` (false), `hybrid_alpha` (0.7), `query_cache_enabled` (true),
  `query_cache_ttl` (300), `dedup_per_source` (false), `slow_query_ms` (500),
  `last_compact_at` (0).

#### New filters

- `mxchat_duckdb_query_text` — populate the user query text for BM25.
- `mxchat_duckdb_rerank_matches` — custom reranker hook.
- `mxchat_duckdb_max_retries` — override the retry attempts (default 3).
- `mxchat_duckdb_health_public` — gate the `/health` endpoint behind auth.
- `mxchat_duckdb_compactor_max_deletes` — per-run cap (default 5000).

#### Fixed

- Cascade-delete handler no longer trusts mxchat's nonce check happening
  later; verifies the nonce itself first. Defense-in-depth.
- **`uninstall.php` — dotfile cleanup.** `glob($dir.'/*')` silently skipped
  the `.htaccess` we wrote into the data directory, leaving an orphan that
  prevented `rmdir()` from succeeding. Now uses recursive `scandir()`.
- **`uninstall.php` — custom `embedded_path` honoured.** Plugin options are
  read *before* deletion so a user-configured path outside `uploads/` is
  also cleaned (file + `.wal` / `.tmp` / `.lock` companions). The parent
  directory is left untouched in case it's shared with other tools.
- **`uninstall.php` — multisite.** On multisite the cleanup now iterates
  every blog (`switch_to_blog()` loop) so per-site options / cron / transients
  are removed for the whole network, not just the main site.

#### Notes

- `uninstall.php` deliberately never makes a network call. To wipe data on
  MotherDuck, run `DROP TABLE mxchat_vectors; DROP TABLE mxchat_duckdb_schema_meta;`
  manually at app.motherduck.com.

---

### [0.2.0] — 2026-05-17

Hardening pass triggered by an internal review. No data migration required, but
**re-test the connection after upgrading** — the MotherDuck backend now talks
the native DuckDB protocol instead of the previously-assumed REST endpoint.

#### Changed (breaking for MotherDuck users)

- **MotherDuck connection rewritten.** The v0.1.0 implementation posted SQL to
  a `POST /v1/sql` endpoint that does not exist as a public SQL-execution API
  (MotherDuck's REST surface is user/token management only). The connection now
  wraps a local DuckDB process (PECL extension or `duckdb` CLI) and runs
  `INSTALL motherduck; LOAD motherduck; ATTACH 'md:<db>?motherduck_token=…';`
  as init SQL. Same backend class name, same factory; only the wire path
  changes. The `mxchat_duckdb_motherduck_endpoint` and
  `mxchat_duckdb_motherduck_timeout` filters are removed.
- **Default embedded path** moved from `uploads/mxchat-duckdb/` to
  `uploads/mxchat-duckdb-private/`. Existing installs with a custom
  `embedded_path` are unaffected; defaults are migrated transparently because
  the new dir is created on demand.

#### Added

- **`.htaccess` + `index.php` + `web.config` blockers** automatically written
  into the DuckDB data directory at runtime so the `.duckdb` file is not
  served over HTTP.
- **Connection cache** in `MxChat_DuckDB_Connection_Factory` — one instance per
  request, keyed by mode + token hash; flushed when options are saved.
- **`ensure_schema()` memoisation** — `INSTALL vss / LOAD vss / CREATE TABLE`
  run at most once per request instead of on every search.
- **Batched upserts** — 250 rows for local DuckDB, 50 rows for MotherDuck to
  stay under network body limits. Filter: `mxchat_duckdb_upsert_chunk_size`.
- **`bot_id` propagation in sync** — the bulk and incremental sync routines
  now detect the `bot_id` column on `wp_mxchat_system_prompt_content` (when
  present) and propagate it instead of hardcoding `'default'`. New filter:
  `mxchat_duckdb_sync_bot_id` for installs that derive the bot from URL/meta.
- **REST proxy rate-limit** — 120 req/min per site by default to protect
  against a misbehaving client saturating CPU with HNSW searches. Filter:
  `mxchat_duckdb_proxy_rate_limit_per_minute` (set 0 to disable).
- **Persistent error notice** in the WP admin when a vector search fails
  (transient + `error_log()` instead of silently returning empty matches).
- **`embedding_dim` change guard** — sanitiser refuses to change the dimension
  when the table already contains rows (the `FLOAT[N]` column type is fixed at
  CREATE time); user must wipe and re-sync.
- **`uninstall.php`** — full cleanup of options, proxy token, scheduled cron,
  rate-limit transients, and (opt-in via
  `MXCHAT_DUCKDB_DELETE_DATA_ON_UNINSTALL`) the data directory itself.
- **CLI-mode performance warning** in the settings page when the PECL extension
  is absent, with a stronger warning for MotherDuck + CLI combos.
- **`updated_at` column** added to the schema (with idempotent ALTER for
  upgrades from 0.1.0).
- New filter `mxchat_duckdb_upsert_chunk_size`, new filter
  `mxchat_duckdb_sync_bot_id`, new filter
  `mxchat_duckdb_proxy_rate_limit_per_minute`.

#### Fixed

- **Shell-injection-shaped bug** in `proc_open` — the v0.1.0 path passed a
  concatenated command string with un-escaped `$binary_path`; now uses the
  array form of `proc_open` (PHP 7.4+), no shell at all.
- **Silent vector corruption** — `literal_float_array` no longer falls back to
  `0.0` on a non-numeric component (which would have produced a zero vector
  that matches nothing useful but ranks deterministically). Throws instead.
- **Upsert dimension check** — incoming vectors whose length doesn't match the
  configured `embedding_dim` are rejected up-front with a clear error,
  preventing partial batches from corrupting the table.
- **Cron cleanup on deactivation** — `mxchat_duckdb_incremental_sync` is now
  unscheduled when the plugin is deactivated instead of firing hourly against
  a disabled backend.
- **Proxy token race** — the `Api-Key` token is now generated at activation
  time instead of lazily on the first admin page load, closing a window where
  Option B requests from mxchat would fail until an admin opened the settings.
- **i18n consistency** — all user-facing strings (including
  `RuntimeException` messages surfaced via `last_error`) now go through
  `__()` with French source strings, matching the rest of the UI.
- Removed unnecessary `flush_rewrite_rules()` on activation/deactivation
  (REST routes don't use the rewrite system).

#### Removed

- `MxChat_DuckDB_MotherDuck_Connection`'s HTTP code path and the related
  `MOTHERDUCK_ENDPOINT` constant.
- Filters `mxchat_duckdb_motherduck_endpoint` and
  `mxchat_duckdb_motherduck_timeout` (no longer applicable).

---

### [0.1.0] — 2026-05-17

First MVP release. Working end-to-end on stock MxChat 3.2.5, no upstream changes required.

#### Added

- **Two parallel integration paths** with MxChat's vector search dispatch:
  - **Option A** — `mxchat_pinecone_matches_override` filter (requires the
    ~12-line upstream patch documented in [`patches/README.md`](tools/patches/README.md)).
  - **Option B** — Pinecone wire-protocol emulator served at
    `/wp-json/mxchat-duckdb/v1/pinecone-proxy/` (zero patch required).
- **Two backends**, switchable from the settings page:
  - **MotherDuck** over HTTP, authenticated by bearer token.
  - **Embedded DuckDB** via the PECL `duckdb` extension when available,
    falling back to the `duckdb` CLI invoked through `proc_open()`.
- **Vector store** with DuckDB VSS extension:
  - Schema with `FLOAT[N]` embedding column (dimension configurable).
  - Optional HNSW index over the embedding column.
  - Cosine / l2sq / inner-product similarity metrics.
- **Two ingestion strategies**:
  - **Sync MySQL → DuckDB** — bulk copy from `wp_mxchat_system_prompt_content`
    in 250-row batches; idempotent thanks to a stable `vector_id` scheme.
  - **Reprocess from WordPress posts** — walks posts/pages/CPTs and routes each
    through `MxChat_Utils::submit_content_to_db()` so MxChat's chunking and
    embedding pipeline writes directly into DuckDB via the proxy. Batched via
    AJAX with progress bar to avoid PHP timeouts.
- **Incremental sync** via WP-cron (`mxchat_duckdb_incremental_sync`, hourly).
- **Cascading delete** when MxChat's UI deletes a KB entry
  (hooks `wp_ajax_mxchat_delete_pinecone_prompt`).
- **REST endpoints** emulating Pinecone:
  - `POST /query` — top-K similarity search.
  - `POST /vectors/upsert` — batch upsert of vectors + metadata.
  - `POST /vectors/fetch` — fetch by ID array.
  - `POST /vectors/delete` — delete by ID array.
  - `POST /vectors/list` (also `GET`) — paginated ID listing.
  - All endpoints authenticated by a one-time-generated `Api-Key` header.
- **Admin settings page** under **MxChat → DuckDB / MotherDuck** with:
  - Backend selection (MotherDuck / embedded).
  - Test connection button.
  - Sync now / reprocess now buttons with progress feedback.
  - Live stats (vectors in DuckDB, last sync, last error).
- **Filters exposed for extensibility**:
  - `mxchat_duckdb_post_content` — customize reprocessed post content.
  - `mxchat_duckdb_motherduck_endpoint` — override the MotherDuck HTTP URL.
  - `mxchat_duckdb_motherduck_timeout` — override the MotherDuck HTTP timeout.

#### Known limitations

- Direct SQL writes to `wp_mxchat_system_prompt_content` (outside MxChat's UI)
  don't propagate to DuckDB until the next hourly cron tick.
- Option B requires the WordPress site to be on HTTPS (MxChat hardcodes
  `https://` when calling Pinecone hosts).
- The PECL `duckdb` extension API is still evolving; the embedded backend will
  prefer the CLI path on most installs until the extension stabilizes.
- Embedding dimension is fixed at table creation; changing the embedding model
  requires re-creating the DuckDB table and re-syncing.

---

## Annex B — history of MXChat Prompt Cache (0.1.0 → 0.8.0)

Everything below happened in the separate `mxchat-promptcache` repository, before the
merge (original French text kept as written). Option keys and the CLI command are
quoted as they were at the time (`mxchat_pc_*`, `wp mxchat-pc …`); they moved into
the shared `mxchat_plus_*` / `wp mxchat-plus promptcache …` namespace in 1.0.0.

### [0.8.0] - 2026-06-09

#### Added

- **Widget de tableau de bord admin.** Rend les économies visibles **hors WP-CLI** (la plupart des propriétaires de sites ne l'utilisent pas) : taux de hit et tokens économisés sur 24 h glissantes + depuis l'installation, pour les utilisateurs `manage_options`. Sortie entièrement échappée.
- **Tests automatisés** des fonctions pures (`tests/test-functions.php`, chargées sans WordPress via stubs) — seuils par modèle, détection d'endpoint, parsing d'usage, buckets/fenêtre glissante — **branchés dans le CI GitHub Actions** (un nouveau job `test`). Aurait attrapé automatiquement la régression de seuil Sonnet 3.x introduite puis corrigée en 0.7.0.

#### Changed

- **Stockage des stats consolidé dans une seule option** (`mxchat_pc_store` = `{buckets, total}`) au lieu d'un transient + une option séparés → **1 lecture + 1 écriture par réponse** au lieu de 4 (le plugin réduit sa propre empreinte DB). Helper `mxchat_pc_normalize_store()`.
- `reset` (sans `--total`) vide la fenêtre 24 h mais **conserve le cumulatif** (avant : message ambigu).
- `uninstall.php` nettoie la nouvelle clé `mxchat_pc_store` + les anciennes clés (installs mises à jour).

#### Notes

- **Taux de hit pondéré par les tokens** : robuste aux petits appels utilitaires non cachables (classification d'intention, `max_tokens: 20`) qui ne le diluent que de quelques points. Pas de tentative d'exclusion heuristique (fragile, et la métrique pondérée est déjà fiable).
- **Concurrence** : compteurs toujours « best-effort » (read-modify-write non atomique) ; suffisant pour de la stat. Une table custom + `UPDATE` atomique ne se justifierait qu'à très fort trafic.

### [0.7.0] - 2026-06-09

#### Fixed

- **Minimums de cache corrigés par version de modèle** (source : docs Anthropic « prompt caching », vérifié 2026-06-09). Le plugin appliquait un seuil par famille devenu faux : **Opus 4.8 = 1024** (et non 4096), **Opus 4.7 = 2048**, **Sonnet 4.6 = 1024** (et non 2048). Conséquence de l'ancien réglage : `cache_control` n'était pas injecté sur des préfixes pourtant cacheables → **caching manqué** (pertes réelles). Opus 4.6/4.5 et Haiku 4.5 restent à 4096 ; Haiku 3.x et Mythos Preview à 2048.

#### Added

- **Support de Claude Fable 5 et Claude Mythos 5** (`claude-fable-5` / `claude-mythos-5`, minimum 512 tokens) et de Mythos Preview (2048). Les constantes de seuil passent d'un nommage par famille à un nommage **par palier de tokens** (`MXCHAT_PC_MIN_CHARS_512/1024/2048/4096`), le minimum ne suivant plus les familles.
- **`uninstall.php`** : suppression de toutes les données persistées à la désinstallation (option `mxchat_pc_stats_total` + transients `mxchat_pc_stats` / `mxchat_pc_last_debug`), avec prise en charge multisite. La désactivation reste sans effet de bord et préserve les stats.

### [0.6.0] - 2026-06-09

#### Changed

- **Fenêtre 24 h désormais réellement glissante.** Les stats 24 h étaient un compteur dont la TTL était rafraîchie à chaque écriture — il ne se réinitialisait qu'après >24 h sans trafic (donc jamais sur un site actif). Remplacé par des **buckets horaires** purgés et ré-agrégés à chaque lecture (`mxchat_pc_empty_bucket`, `mxchat_pc_bucket_add`, `mxchat_pc_aggregate_window`).
- **Tailles de préfixe mesurées en caractères (`mb_strlen`)** et non plus en octets : estimation token cohérente en UTF-8 multioctet (arabe/hébreu RTL, CJK). Helper `mxchat_pc_strlen`.
- **CLI** : horodatages via `wp_date()` (fuseau WordPress) au lieu de `date()` (fuseau serveur).

#### Added

- **Ventilation par modèle aussi sur le cumulatif** : `wp mxchat-pc stats --total --by-model` détaille désormais les modèles sur le cumulatif, pas seulement sur les 24 h.
- **Filtre `mxchat_pc_min_messages`** : seuil de messages avant d'activer le cache historique (défaut 3) — baisser à 1 pour cacher dès le 1er tour sur les sessions longues.

#### Fixed

- **Seuils par modèle** : suppression d'une clause morte `haiku-3-5` (jamais déclenchée — l'ID réel est `claude-3-5-haiku-…`). Haiku 3.x est correctement traité à 2048 tokens via le fallback `haiku`.

#### Notes

- **Concurrence** : les compteurs restent un read-modify-write non atomique → « best-effort » sous forte charge (léger sous-comptage possible, stat uniquement). Un object cache persistant est recommandé en production.
- **Économie de la TTL** documentée : ×2 (1 h) vs ×1,25 (5 min) sur la prime d'écriture ; choisir selon la cadence des tours.

### [0.5.0] - 2026-06-09

#### Added

- **Mesure du cache multi-provider.** Le filtre `http_response` lit désormais les tokens cachés de **tous** les providers supportés, pas seulement Anthropic : OpenAI, OpenRouter, xAI, DeepSeek (champ `usage.prompt_tokens_details.cached_tokens`) et Gemini (`usageMetadata.cachedContentTokenCount`). Ces providers cachent automatiquement côté serveur ; le plugin n'injecte rien mais rend visibles leurs économies dans un tableau de bord unifié. Nouvelles fonctions `mxchat_pc_identify_endpoint()` et `mxchat_pc_parse_usage()`.
- **Diagnostic de cache inefficace.** `wp mxchat-pc stats` affiche un avertissement « Écritures de cache > lectures » quand le cache Anthropic est écrit mais peu/pas relu (préfixe instable — shortcode/donnée dynamique dans le system prompt, historique reformaté — ou trafic trop faible). Fonction `mxchat_pc_caching_hint()`.

#### Changed

- L'injection (`http_request_args`) reste **strictement réservée à Anthropic** ; seule la mesure (`http_response`) devient multi-provider. L'enrichissement du debug de la dernière requête est gardé sur le chemin Anthropic uniquement.

#### Notes

- **Streaming toujours non cachable (vérifié contre MXChat 3.2.8).** Le streaming passe par cURL direct, non interceptable par `http_request_args` ; aucun filtre n'est exposé sur le corps de requête et MXChat n'émet pas de `cache_control` natif. Streaming et prompt caching restent mutuellement exclusifs côté chat Claude.
- **OpenRouter non injectable** : MXChat concatène le RAG dans le message `system`, ce qui place le contenu volatil en tête de préfixe et défait le cache. Le plugin mesure OpenRouter mais n'y injecte pas.

### [0.4.0] - 2026-05-23

#### Added

- **Header de plugin complet** : `Plugin URI`, `Author URI`, `Update URI`, `Text Domain`, `Domain Path`, `Requires at least`.
- **Internationalisation (i18n)** : chargement du text-domain `mxchat-promptcache` sur `init`, toutes les chaînes user-facing (commandes WP-CLI) wrappées dans `__()`. Le `.pot` peut être généré via `wp i18n make-pot . languages/mxchat-promptcache.pot`.
- **Trois filters d'extensibilité** (évitent les forks utilisateur) :
  - `mxchat_pc_should_inject($should, $payload, $args, $url)` — désactiver l'injection pour une requête spécifique.
  - `mxchat_pc_min_chars($min, $model, $payload)` — override du seuil minimum par modèle ou par requête.
  - `mxchat_pc_ephemeral_control($control)` — override de la structure `cache_control` injectée (ex : TTL custom).
- **Statistiques cumulatives** depuis l'installation (option `mxchat_pc_stats_total`, non-autoloadée, jamais expirée).
- **`wp mxchat-pc stats --total`** : affiche le cumulatif depuis l'installation en plus du glissant 24 h.
- **`wp mxchat-pc reset --total`** : reset complet (24 h + cumulatif + debug).
- **GitHub Actions CI** : `php -l` sur PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3 à chaque push et PR.
- **Badges README** : release, license, PHP, WordPress, CI.

#### Changed

- **Bail-out précoce** sur les méthodes HTTP non-POST (évite un `json_decode` inutile sur OPTIONS preflight et autres requêtes hors scope).
- **`mxchat_pc_tools_size`** : nouveau paramètre `$stop_at` qui court-circuite la mesure dès que le seuil est dépassé (optimisation marginale sur les très gros tool sets).
- **`mxchat_pc_using_extended_ttl`** se base désormais sur la sortie de `mxchat_pc_ephemeral_control()` (cohérent avec le filtre).
- Constante explicite `MXCHAT_PC_MIN_MESSAGES = 3` pour documenter le seuil d'activation du cache historique.

### [0.3.0] - 2026-05-23

#### Fixed

- **Critique** : seuils minimum corrigés pour Opus 4.x et Haiku 4.5 (4096 tokens, ~16 400 caractères, au lieu de 1024). Avec l'ancien seuil, `cache_control` était bien injecté mais Anthropic ignorait silencieusement la mise en cache sur ces modèles (`cache_creation_input_tokens = 0`).

#### Added

- Caching des `tools` (4e breakpoint, le plus stable du préfixe).
- Deuxième breakpoint "rolling" sur l'historique : avant-dernier message user en plus du dernier, pour maintenir un hit rate stable au fil de la conversation.
- Statistiques ventilées par modèle Anthropic.
- Commande `wp mxchat-pc stats --by-model`.
- Commande `wp mxchat-pc debug` (détails de la dernière requête : modèle, breakpoints ajoutés, usage retourné).
- Détection fine du modèle (Opus / Haiku 4.x / Sonnet 4.6 / Sonnet legacy) pour appliquer le bon seuil minimum.

#### Changed

- Découplage system / messages : un breakpoint pré-existant sur l'historique n'empêche plus le cache du `system`.
- Les seuils sont désormais exposés via des constantes (`MXCHAT_PC_MIN_CHARS_OPUS`, `MXCHAT_PC_MIN_CHARS_SONNET_4_6`, etc.) pour faciliter le tuning.
- Compteur de breakpoints utilisés pour garantir le plafond Anthropic (4 max).

### [0.2.0]

#### Added

- Cache de l'historique de conversation (2e breakpoint sur le dernier message).
- TTL étendu à 1h via le header beta `extended-cache-ttl-2025-04-11`.
- Métriques WP-CLI : `wp mxchat-pc stats` et `wp mxchat-pc reset`.
- Support des prompts `system` déjà fournis en tableau de blocs.
- Seuil minimum adapté au modèle (Haiku 2048 tokens, autres 1024).

#### Changed

- Match d'URL strict (host + path) au lieu de `strpos`.

#### Fixed

- Idempotence : ne re-cache pas si `cache_control` déjà présent dans le payload.

### [0.1.0]

#### Added

- Version initiale : caching du bloc `system` via le filtre `http_request_args`.
- Header beta `extended-cache-ttl-2025-04-11` pour la TTL 1h.
