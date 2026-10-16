# Prompt caching — operator guide

The prompt-cache module makes MxChat's Claude traffic reuse identical prompt prefixes
instead of paying full price for them on every turn. Cached input tokens bill at roughly
**0.1×** the normal input rate, and time-to-first-token drops when the prefix comes from
cache.

It attaches to three filters and touches nothing else:

| Filter | Role |
|---|---|
| `http_request_args` | **Injection** — detects POSTs to `api.anthropic.com/v1/messages` and adds `cache_control` breakpoints (plus, for compatibility, the legacy extended-TTL beta header). **Anthropic only.** |
| `http_response` | **Measurement** — reads cached-token counters from **every** supported provider's `usage` object and accumulates per-model statistics. |
| `mxchat_system_instructions` | **Observation only** — MxChat's own filter, read at the latest priority and returned unchanged. It tells the module which value MxChat substituted for `{current_datetime}`, so a system prompt that changes every minute is recognised (see [Volatile system prompts](#volatile-system-prompts)). |

Host functions are cited below by name and MxChat version rather than by line number:
line numbers move with every MxChat release, function names rarely do.

---

## Before anything else: streaming

Streaming is the single reason a correctly installed module reports zero savings.

MxChat streams Claude responses with raw `curl_exec()` inside
`MxChat_Integrator::mxchat_generate_response_claude_stream()` (MxChat 3.2.25; there are
**8** `curl_exec()` call sites in `class-mxchat-integrator.php`). Raw cURL bypasses the
WordPress HTTP API, so `http_request_args` is **never called** and there is no payload to
modify. Nothing errors; the module simply does not see the request.

The streamed payload still carries **MxChat's own** system-prompt breakpoint (5-minute
TTL): since 3.2.21 the stream function builds its system with
`mxchat_anthropic_system_blocks()`, like the other Claude paths. What a streamed reply
loses is only this module's additions — the history breakpoints, the tool-definitions
breakpoint and the 1-hour TTL.

**How MxChat decides.** The server streams only when the host option
`enable_streaming_toggle` (in `mxchat_options`) is exactly `'on'`
(`MxChat_Integrator::mxchat_handle_chat_request()`, MxChat 3.2.21 → 3.2.25). A missing key
means *not* streaming on the server, even though the settings checkbox and the widget show
streaming as on by default when the key is absent. With the Multi-Bot add-on, a bot with
its own options replaces the global options for its requests, so one bot can stream while
another does not.

**Fix:** switch streaming off in **MxChat → Settings**. MxChat then answers through
`MxChat_Integrator::mxchat_generate_response_claude()` (MxChat 3.2.25), which posts through
`mxchat_provider_call_with_retry()`. That wrapper calls `wp_remote_post()` and, unless the
host option `auto_retry_on_transient_error` is `'0'`, retries up to twice on transient
errors (after 750 ms, then 2 s). Every attempt goes through `http_request_args` and
`http_response`, so the module's filters run once per attempt.

**Trade-off:** you lose the typewriter effect. You gain ~85–95 % input-token cost
reduction once the cache is warm — typically after 3–5 requests.

### With AI Tools active, streaming matters much less (MxChat ≥ 3.2.22)

When **Actions → AI Tools** has at least one tool enabled and the model is a `claude-*`
model, MxChat runs its function-calling loop (`mxchat_fc_loop_anthropic()`) *before* the
streaming decision, whatever the streaming setting. The loop is buffered: it posts through
`mxchat_fc_post()` → `mxchat_provider_call_with_retry()` → `wp_remote_post()`, which this
module intercepts. Since 3.2.22, a first hop that calls no tool is kept as the answer
(the host filter `mxchat_fc_keep_first_hop_text` defaults to `true`), so **most** turns
are answered by the loop and are cacheable even with streaming on.

Turns that still go to the stream (and are not cached by this module):

- turns answered earlier by intents, agent mode or a pre-processing filter;
- turns where the first hop fails, or returns empty text without using a tool (MxChat
  then falls back to the normal path);
- requests where no `claude_api_key` is configured for the bot;
- **every** no-tool turn when an add-on returns `false` from
  `mxchat_fc_keep_first_hop_text` — then only turns where Claude calls a tool are buffered;
- on MxChat 3.2.21, every no-tool turn (the first-hop behaviour did not exist yet).

The loop only runs when MxChat's own gate passes: AI Tools enabled, at least one tool
currently offered (a live-agent handover tool is withheld outside staffed hours, so the
answer can depend on the time of day), and a model the catalog marks as tool-capable.
AI Tools is **off by default**.

The **Modules** screen (MxChat Plus → Modules → Environment) mirrors these rules: it reads
`enable_streaming_toggle` the way the server does and, with streaming on, says whether the
AI Tools loop covers most turns. It reads the global settings only; with Multi-Bot it says
so.

### What is cacheable

| Source | Transport | Cached by this module? |
|---|---|---|
| Main chat, streaming on, no AI Tools | `curl_exec` | ✗ — MxChat's own 5-minute system breakpoint still applies |
| Main chat, streaming off | `wp_remote_post` (with retries) | ✓ |
| Main chat with AI Tools active (≥ 3.2.22), any streaming setting | buffered tool loop, `wp_remote_post` | ✓ most turns (only tool turns if `mxchat_fc_keep_first_hop_text` returns `false`) |
| Non-streamed fallback (stream error, headers already sent, no cURL) | `wp_remote_post` | ✓ |
| Content Generator (admin) | `wp_remote_post` | ✓ (MxChat marks its own system prompt, 5 min) |
| Editor assistant, REST (admin) | `wp_remote_post` | ✓ |
| Editor assistant, streamed (admin) | `curl_exec` | ✗ |
| Transcript translation (admin) | `wp_remote_post` | technically, but the prompt is far below the minimum prefix |
| Intent classification | `wp_remote_post` | technically, but the prompt is far below the minimum prefix |
| "Test API key" button (admin) | `wp_remote_get` on `/v1/models` | ✗ no generation, nothing to cache |
| "Test streaming" button (admin) | `curl_exec` | ✗ |

The clean long-term fix is upstream: a filter on the streaming payload immediately before
`curl_setopt($ch, CURLOPT_POSTFIELDS, …)` would let the module cache with streaming left
on. That request is tracked in the *Roadmap* section of `CHANGELOG.md`.

---

## Breakpoint strategy

Anthropic allows **4** `cache_control` breakpoints per request, **including** the one
MxChat already puts on its system block (3.2.21+). The module counts the breakpoints
already present and only spends what is left.

### The shape of MxChat's requests

Every Claude chat request MxChat builds — the non-streamed path and the first hop of the
AI Tools loop — has the same shape (MxChat 3.2.21 → 3.2.25):

```
[ note, history…, u_N, context block ]
```

- **note** — `Preserved code blocks are marked with [CODE BLOCK PRESERVED]. …`, added by
  `mxchat_fetch_conversation_history_for_ai()` with role `system` and turned into a
  `user` message by the Claude handlers. Identical every turn.
- **u_N** — the visitor's raw message. MxChat saves it to the transcript *before* it reads
  the history back, so it is already part of the history.
- **context block** — built by `mxchat_handle_chat_request()`: the current date/time line
  (3.2.22+), `User asked: '…'`, then the knowledge-base excerpt, approved URLs and page
  data. Typically thousands of tokens. MxChat **never stores it** in the transcript, so the
  next turn does not send it again.

Later hops of the AI Tools loop append `[assistant(tool_use), user(tool_result)]` pairs;
the last hop no longer offers `tools` (depth or call budget reached).

The module recognises the context block by the host literal `User asked: '` (searched
anywhere in the message, since the date line precedes it and the `mxchat_prepare_context`
filter can rewrite the block) or, failing that, by structure — two `user` messages in a
row at the end of the request. It recognises the tool loop by `tool_use` / `tool_result`
blocks, not by the presence of `tools`, because the last hop has none.

### Where the breakpoints go

| Request | Breakpoints added by the module (in priority order) | TTL |
|---|---|---|
| Any | last entry of `tools[]`, when the definitions reach the threshold | module TTL |
| Any | last block of `system`, when it reaches the threshold and MxChat did not mark it already | module TTL |
| Chat, no AI Tools | **u_N** (write point), then **u_{N-1}** (reads the previous turn's write exactly). The context block is **never** marked: it is billed as plain input. | module TTL |
| AI Tools, first hop | **u_N**, then the **context block**, then **u_{N-1}** | u_N / u_{N-1}: module TTL; context block: 5 min |
| AI Tools, later hops (tools still offered) | the last `user` turn (the newest `tool_result`, write) and the one before it (the context block or the previous `tool_result`, read). The write is skipped when the next hop cannot offer tools any more (call budget or depth reached, or `tool_choice.disable_parallel_tool_use` set by MxChat ≥ 3.2.23), because that hop would never read it | 5 min |
| AI Tools, last hop (no `tools`) | nothing in `messages` | — |
| Anything else (other plugins, utility calls) | the last two `user` messages | module TTL |

Why the history pair sits on u_N and not on the context block: a breakpoint on the context
block would pay the write premium on thousands of tokens every turn for an entry no later
request contains. On u_N, this turn's write is next turn's read, so the hit rate stays
flat as the conversation grows.

Why the tool loop uses 5 minutes: its hops are seconds apart, so a 1-hour write (×2) would
buy nothing over a 5-minute one (×1.25). The context block is marked on the first hop
because the second hop reads it back when Claude calls a tool; when Claude answers without
a tool, the loss is limited to 0.25× that block. The last hop is never marked: without
`tools` its prefix differs from every first hop, so a write there would never be read.

**Minimum history.** The history pair is only placed once there are at least 3 messages
(user, assistant, user) **not counting** the host's note and the trailing context block,
so a one-message chat pays no history write (filter
`mxchat_plus_promptcache_min_messages`). The breakpoints inside the AI Tools loop do not
depend on it: they serve the turn in progress.

**Tool definitions.** MxChat's system breakpoint already covers the tool definitions,
because Anthropic renders `tools` before `system`. The module's own `tools` breakpoint pays
off when the system prompt varies from request to request (`{context}`, `{visitor_name}`,
`{current_date}`, `{current_datetime}`): the tools prefix then stays cached on its own.

### TTL ordering

Anthropic forbids a 1-hour breakpoint after a 5-minute one (render order: tools → system →
messages). MxChat marks its system block with the default 5-minute TTL, so with the
module's 1-hour default the module lengthens the host's marker to 1 hour — the system
prompt, shared by every visitor, is the prefix that gains most from lasting — and then
checks the whole request, third-party markers included, lengthening any shorter TTL that
precedes a longer one. The tool-loop breakpoints (5 minutes) come last, which is a valid
order. A **volatile** system prompt is not lengthened (see below).

### Empty JSON objects

The module decodes the request body into PHP arrays and re-encodes it when it adds a
breakpoint. Empty JSON objects — an AI Tool without arguments (`"properties": {}`), a
`tool_use` with `"input": {}` — would come back as `[]`, which Anthropic expects as an
object. The module records where the objects were before decoding and restores them
before re-encoding. It also repairs a `tool_use` block whose `input` MxChat itself sent as
`[]` (`mxchat_fc_post()` decodes Anthropic's reply into arrays, MxChat 3.2.21 → 3.2.25).

---

## Volatile system prompts

Some system prompts change on every request. Caching them only buys writes that are never
read, and so does caching the history that follows them in the prefix. When the module
detects one, it:

- adds no breakpoint on `system` and none on the history;
- leaves MxChat's own 5-minute marker untouched (it is not lengthened to 1 hour);
- still caches `tools`, which come before the system prompt.

It detects two cases:

| Cause | Detection | Why it is volatile |
|---|---|---|
| `{context}` in the instructions | the knowledge-base headers `===== OFFICIAL KNOWLEDGE DATABASE CONTENT =====` / `===== NO RELEVANT CONTENT FOUND IN KNOWLEDGE DATABASE =====` in the system block | MxChat substitutes the retrieval result of each question; it does not mark that system block itself. |
| `{current_datetime}` in the instructions (MxChat ≥ 3.2.22) | the value MxChat substituted, seen through `mxchat_system_instructions`; if that filter did not run, the value MxChat would produce now, 60 s ago or 120 s ago (site date and time formats, site timezone) | The value changes **every minute**. MxChat's 5-minute entry already covers its whole useful life; a 1-hour write (×2 instead of ×1.25) plus history writes behind it would only add cost. |

`{current_date}` is **not** treated as volatile: it changes once a day, so the 1-hour TTL
and the history breakpoints still pay off. The first request after midnight simply writes
a new entry.

A shortcode or a third-party filter that injects the time, stock levels or a random
greeting into the system prompt is not detected. Use the filter to force the volatile
path:

```php
add_filter('mxchat_plus_promptcache_volatile_system', function ($volatile, $payload) {
    return true; // this site's system prompt changes on every request
}, 10, 2);
```

The same filter can return `false` to cache a system prompt the module considers volatile.
`wp mxchat-plus promptcache debug` shows whether the last request was treated as volatile,
and why.

A volatile system prompt only removes the **cross-turn** history pair (u_N / u_{N-1}).
Inside one AI Tools turn, MxChat computes the system prompt once and resends it unchanged
on every hop, so the 5-minute loop marks are kept: hop 2 still reads what hop 1 wrote.

---

## Minimum prefix per model

Anthropic **silently ignores** `cache_control` when the prefix is too short: no error, just
`cache_creation_input_tokens = 0`. The minimum depends on the model **and its version** —
it is not constant within a family. The module reads the `model` field of each request and
applies the matching threshold.

| Model | Minimum tokens | ≈ characters |
|---|---|---|
| Fable 5 / 5.1, Mythos 5 / 5.1, Opus 5 / 5.5, Sonnet 5.5, Haiku 5.5 | 512 | 2 050 |
| Opus 4.8 / 4.1 / 4, Sonnet 5 / 4.6 / 4.5 / 4 / 3.x | 1 024 | 4 000 |
| Opus 4.7, Mythos Preview, Haiku 3.x | 2 048 | 8 200 |
| Opus 4.6 / 4.5, Haiku 4.5 | 4 096 | 16 400 |
| Unknown model (default) | 4 096 | 16 400 |

Three things to keep in mind:

- **The minimum changes by version, and not monotonically.** Opus 5 is 512, Opus 4.8 is
  1 024, Opus 4.6 is 4 096; Sonnet 5.5 is 512 but Sonnet 5 is 1 024. Assuming one value
  per family is how caching gets silently missed. MxChat remaps retired Opus and Sonnet
  ids to `claude-opus-5` / `claude-sonnet-5` before calling the API
  (`MxChat_Model_Catalog::resolve_retired()`), and the module reads the id actually sent.
- A threshold set too **low** costs nothing (Anthropic ignores the breakpoint). A
  threshold set too **high** silently withholds caching from a block that qualified — which
  is what an unknown model gets until the table learns it.
- Size is measured in **characters** (`mb_strlen`, not bytes) so multibyte text — Arabic and
  Hebrew RTL, CJK — is counted consistently. Recent models (Opus 4.7+, generation 5) use a
  denser tokenizer, which makes the character thresholds slightly conservative.

---

## Configuration

One constant, set in `wp-config.php` **before** the plugin loads:

```php
// Use the standard 5-minute TTL instead of 1 hour.
define('MXCHAT_PLUS_PC_EXTENDED_TTL', false);
```

`MXCHAT_PLUS_PROMPTCACHE_EXTENDED_TTL`, the name this guide published in 1.0.0, is accepted
too.

Choose by cost: the cache-write premium is **×2** at the 1-hour TTL versus **×1.25** at
5 minutes. The 1-hour default protects conversations with pauses longer than 5 minutes (it
avoids rewriting the whole history when the visitor comes back) and keeps the shared system
prompt warm between visits on a quiet site; for fast back-and-forth chat (turns under
5 minutes) the 5-minute TTL is enough and cheaper. The AI Tools loop always uses 5 minutes
inside a turn, whatever this setting. Per-request tuning is available through the
`ephemeral_control` filter below.

**The beta header is legacy.** Anthropic has not required a beta header for the 1-hour TTL
since 13 August 2025. The module still adds `anthropic-beta: extended-cache-ttl-2025-04-11`
when the request carries a 1-hour breakpoint, for older accounts and proxies, but only
when it can be added without disturbing the existing headers; when it cannot, the
breakpoints are sent anyway. To stop sending it:

```php
add_filter('mxchat_plus_promptcache_send_extended_ttl_header', '__return_false');
```

> Constant and hook names are given here in the merged plugin's `mxchat_plus_*` namespace.
> If you are porting configuration from the standalone `mxchat-promptcache` plugin, its
> `MXCHAT_PC_*` constants and `mxchat_pc_*` filters were renamed in 1.0.0 — see
> `CHANGELOG.md`.

### Extensibility filters

```php
// Skip injection for a specific request.
add_filter('mxchat_plus_promptcache_should_inject', function ($should, $payload, $args, $url) {
    if (($payload['metadata']['user_id'] ?? '') === 'no-cache-user') {
        return false;
    }
    return $should;
}, 10, 4);

// Override the minimum prefix (per model, or per request).
add_filter('mxchat_plus_promptcache_min_chars', function ($min, $model, $payload) {
    return $model === 'claude-haiku-4-5' ? 12000 : $min;
}, 10, 3);

// Override the injected cache_control value (e.g. force the 5-minute TTL).
add_filter('mxchat_plus_promptcache_ephemeral_control', function ($control) {
    return ['type' => 'ephemeral'];
});

// Minimum number of messages before the history is cached (default 3, counted
// without MxChat's leading note and trailing context block). Lower to 1 to cache
// from the first turn on long sessions; keep >= 3 so one-shot chats don't pay the
// write premium for a cache nobody reads.
add_filter('mxchat_plus_promptcache_min_messages', function ($min, $payload) {
    return 1;
}, 10, 2);

// Treat the system prompt as changing on every request (or not). See above.
add_filter('mxchat_plus_promptcache_volatile_system', function ($volatile, $payload) {
    return $volatile;
}, 10, 2);

// Stop sending the legacy extended-TTL beta header.
add_filter('mxchat_plus_promptcache_send_extended_ttl_header', function ($send, $args) {
    return false;
}, 10, 2);
```

---

## Reading the numbers

Statistics cover **every measured provider**, not just Anthropic. The "24 h" window is a
genuine sliding window (hourly buckets, re-aggregated on every read — not a frozen
counter).

```bash
wp mxchat-plus promptcache stats              # 24 h sliding window, all providers
wp mxchat-plus promptcache stats --by-model   # split by model
wp mxchat-plus promptcache stats --total      # cumulative since install + 24 h
wp mxchat-plus promptcache debug              # last Anthropic request (see below)
wp mxchat-plus promptcache reset              # clear the 24 h counters + debug
wp mxchat-plus promptcache reset --total      # clear everything, cumulative included
```

No terminal? A **dashboard widget** shows the hit rate and tokens saved over the sliding
24 h and since installation, for users with `manage_options`.

**The hit rate is token-weighted** (tokens read from cache ÷ total input tokens), not
request-weighted. Small uncacheable utility calls — intent classification with
`max_tokens: 20`, for instance — therefore dilute it only marginally, by a few points.
That is deliberate: trying to exclude them by inspecting responses would be a fragile
heuristic, and the weighted metric is already robust.

### What is measured

Measurement reads the `usage` object each API returns, for non-streamed responses only:

| Provider / endpoint | Fields read |
|---|---|
| Anthropic `/v1/messages` | `input_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens` |
| OpenAI `/v1/chat/completions`, OpenRouter, xAI, DeepSeek | `prompt_tokens`, `prompt_tokens_details.cached_tokens` |
| OpenAI `/v1/responses` | `input_tokens`, `input_tokens_details.cached_tokens` |
| Gemini `:generateContent` | `usageMetadata.promptTokenCount`, `cachedContentTokenCount` |
| Gemini's OpenAI-compatible endpoint (`…/openai/chat/completions`) | as OpenAI, **recorded only when the response reports `cached_tokens`** |
| MxChat's custom provider (the host option `custom_provider_base_url`, read only) | as OpenAI, **recorded only when the response reports `cached_tokens`** |

- **OpenAI Responses API.** MxChat uses it for OpenAI replies when web search is on
  (`mxchat_generate_response_openai_web_search()`, measured when the reply is not
  streamed), and for the Vector Store retrieval (`file_search`) it sends before each
  answer on Vector Store sites. The retrieval is real spend, so it is counted,
  but under its own label — `<model> (file_search)` in `stats --by-model` — so it is not
  mistaken for the answer. The `input_tokens_details.cached_tokens` field comes from the
  OpenAI API, not from MxChat's code; it has not been checked against a live response here.
- **Custom provider and Gemini compat.** Many OpenAI-compatible servers (Ollama, LM
  Studio, vLLM…) report no cached tokens at all. Counting their requests would show a
  0 % hit rate that means "not measured", so a response without `cached_tokens` is simply
  not recorded. Only the global custom-provider URL is recognised (both forms MxChat builds:
  base + `/chat/completions`, or a base that already ends in `chat/completions`; the query
  string, such as Azure's `api-version`, is ignored). A Multi-Bot bot pointing elsewhere is
  not.

### `promptcache debug`

The debug command describes the **last Anthropic request** the module saw:

| Field | Meaning |
|---|---|
| Payload shape | `plain` (chat, no AI Tools), `fc_first_hop`, `fc_loop`, `fc_final_hop`, or `generic` |
| Volatile system | `yes` / `no`, with the reason: knowledge-base block (`{context}`), `{current_datetime}`, or the `mxchat_plus_promptcache_volatile_system` filter |
| TTLs lengthened | markers lengthened to keep the TTL order valid (usually MxChat's own system marker) |
| Tool inputs fixed | `tool_use` inputs MxChat sent as `[]` and the module sent as `{}` |
| Breakpoints added | `tools`, `system`; `prev_user` / `last_user` — the rolling pair (u_{N-1} / u_N on the chat path, never the context block; the previous and last `user` turns in the tool loop); `context_block` — the context block on the first AI Tools hop |
| Usage | the token counts of the matching response |

### "Cache writes > reads"

`stats` prints this warning when the module is paying the write premium for prefixes that
are never read back. Usual causes:

1. **An unstable prefix.** `get_system_instructions()` applies `{visitor_name}`,
   `{current_date}` / `{current_datetime}` (3.2.22+), the `mxchat_system_instructions`
   filter and `do_shortcode()`. `{current_datetime}` and `{context}` are detected (see
   [Volatile system prompts](#volatile-system-prompts)); a shortcode or filter that injects
   volatile data is not, and invalidates the cache on every request. Move volatile content
   out of the system prompt, or force the volatile path with
   `mxchat_plus_promptcache_volatile_system`.
2. **Traffic too low** to amortise the writes within the TTL. Consider the 5-minute TTL
   (cheaper writes) or accept that caching will not pay off at that volume.
3. **Short chats.** Visitors who leave after one or two questions never read back the
   history written on their last turn. The `min_messages` threshold limits this; raising it
   trades a little hit rate on long chats for fewer wasted writes.

---

## Known limits

- **Streaming is not interceptable** — see the top of this page. With AI Tools active,
  most turns avoid the stream anyway.
- **OpenRouter cannot be injected into, by construction.** On the OpenRouter path MxChat
  concatenates the RAG context *into* the `system` message
  (`$system_prompt_instructions . " " . $relevant_content`), which puts volatile content at
  the head of the prefix and defeats prefix matching. Injecting there would only buy
  writes that are never read, so OpenRouter is **measured** (it caches server-side anyway)
  and never injected.
- **Multi-provider measurement covers non-streamed responses only** (`wp_remote_post`).
  Streamed cURL responses from those providers are invisible to the module.
- **Retries count twice.** When MxChat retries a request after a transient error, each
  attempt is a separate API call and is measured as such.
- **The Modules screen and the custom-provider match read the global settings.** A
  Multi-Bot bot with its own model, streaming setting or provider URL can behave
  differently.
- **Counters are best-effort under concurrency.** They accumulate by read-modify-write
  without a lock, so simultaneous requests can slightly undercount. Statistics only — the
  cache itself is unaffected. A persistent object cache keeps these writes in memory
  rather than in the database.
- **History must be stable.** The conversation breakpoints assume MxChat sends the same
  messages in the same order from one call to the next. Reformatting or truncating history
  between turns invalidates the cache. MxChat drops history entries whose HTML its
  sanitiser would alter; the module copes with the resulting shape, but the cache only
  helps as far as the history stays identical.
- **Host shape recognition.** The context block is recognised by the untranslated host
  literal `User asked: '` or by structure. If a future MxChat stops ending its requests
  with that block, the module falls back to marking the last two `user` messages, its
  behaviour for any unrecognised request.
- **Idempotent by design.** If a future MxChat version adds its own `cache_control` to the
  history, the module detects it and leaves the messages alone, while still caching
  `tools` and `system`.
- **Not verified in a live WordPress.** The behaviour above is covered by unit tests built
  on MxChat's real payload shapes, not by requests against the Anthropic API.

## Uninstalling

Deactivating has no side effects — the module only registers filters, which stop being
applied. Statistics are kept (WordPress convention: deactivation does not delete data).
Deleting the plugin runs `uninstall.php`, which removes the persisted statistics with
multisite support. No custom tables, no cron events, no files on disk.
