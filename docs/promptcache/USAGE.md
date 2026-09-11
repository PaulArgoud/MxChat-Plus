# Prompt caching — operator guide

The prompt-cache module makes MxChat's Claude traffic reuse identical prompt prefixes
instead of paying full price for them on every turn. Cached input tokens bill at roughly
**0.1×** the normal input rate, and time-to-first-token drops when the prefix comes from
cache.

It attaches to two WordPress filters and touches nothing else:

| Filter | Role |
|---|---|
| `http_request_args` | **Injection** — detects POSTs to `api.anthropic.com/v1/messages` and adds `cache_control` breakpoints plus the extended-TTL beta header. **Anthropic only.** |
| `http_response` | **Measurement** — reads cached-token counters from **every** supported provider's `usage` object and accumulates per-model statistics. |

---

## Before anything else: turn streaming OFF

This is the single reason a correctly installed module reports zero savings.

MxChat streams Claude responses with raw `curl_exec()` — **8 occurrences** in
`mxchat-basic/includes/class-mxchat-integrator.php` on version 3.2.21, the relevant one
inside `mxchat_generate_response_claude_stream()` (line 11444). Raw cURL bypasses the
WordPress HTTP API, so `http_request_args` is **never called** and there is no payload to
modify. Nothing errors; the feature simply does nothing.

**Fix:** switch streaming off in **MxChat → Settings**. Internally that is the
`enable_streaming_toggle` key of the host's `mxchat_options`. MxChat then routes through
`mxchat_generate_response_claude()` (line 12411), which uses `wp_remote_post()` and is
fully interceptable.

**Trade-off:** you lose the typewriter effect. You gain ~85–95 % input-token cost
reduction once the cache is warm — typically after 3–5 requests.

### What is cacheable while streaming stays ON

| Source | Mechanism | Cacheable? |
|---|---|---|
| Main chat, streaming | `curl_exec` | ✗ bypasses the WP HTTP API |
| Non-streamed fallback (on stream error) | `wp_remote_post` | ✓ |
| Content Generator (admin) | `wp_remote_post` | ✓ |
| "Test API" button (admin) | `wp_remote_post` | ✓ one-shot |
| "Test streaming" button (admin) | `curl_exec` | ✗ |
| Intent classification | `wp_remote_post` | ✓ technically, but the prompt is far below the minimum prefix |

The clean long-term fix is upstream: a filter on the streaming payload immediately before
`curl_setopt($ch, CURLOPT_POSTFIELDS, …)` would let the module cache with streaming left
on. That request is tracked in `CHANGELOG.md` under *Planned*.

---

## Breakpoint strategy

Anthropic allows **4** `cache_control` breakpoints per request. The module places them
from the most stable prefix outward:

| # | Target | Why | Conditions |
|---|---|---|---|
| 1 | Last entry of `tools[]` | Tool definitions almost never change | `tools` present **and** size ≥ threshold |
| 2 | Last block of `system` | The big static instruction block | size ≥ threshold |
| 3 | Second-to-last user message | **Read** point on the next turn | ≥ 3 messages in history |
| 4 | Last user message | **Write** point for the current turn | idem |

Breakpoints 3 and 4 are a *rolling* pair: this turn's write point becomes next turn's read
point, so the hit rate stays flat as the conversation grows rather than decaying.

> **Verified against mxchat-basic**: the chat sends no `tools[]` to Anthropic — RAG context
> arrives as the final `user` message, not through tool calling. Breakpoint #1 is therefore
> **defensive**; it only fires if an add-on (MCP, for instance) introduces tools. The
> `system` block is stable turn to turn, which is what makes the rolling strategy work.

---

## Minimum prefix per model

Anthropic **silently ignores** `cache_control` when the prefix is too short: no error, just
`cache_creation_input_tokens = 0`. The minimum depends on the model **and its version** —
it is not constant within a family. The module reads the `model` field of each request and
applies the matching threshold.

| Model | Minimum tokens | ≈ characters |
|---|---|---|
| Fable 5 / Mythos 5 | 512 | 2 050 |
| Opus 4.8 / 4.1 / 4.0 | 1 024 | 4 000 |
| Sonnet 4.6 / 4.5 / 4 / 3.x | 1 024 | 4 000 |
| Opus 4.7 / Mythos Preview / Haiku 3.x | 2 048 | 8 200 |
| Opus 4.6 / 4.5 / Haiku 4.5 | 4 096 | 16 400 |
| Unknown model (default) | 4 096 | 16 400 |

Two things to keep in mind:

- **The minimum changes by version.** Opus 4.8 is 1 024 but Opus 4.6 is 4 096; Sonnet 4.6
  is 1 024. Assuming "Opus = 4096" is how caching gets silently missed.
- Size is measured in **characters** (`mb_strlen`, not bytes) so multibyte text — Arabic and
  Hebrew RTL, CJK — is counted consistently. Recent models (Opus 4.7+, Fable, Mythos) use a
  denser tokenizer, which makes the character thresholds slightly conservative. That is the
  safe direction: a threshold set too low costs nothing, because Anthropic just ignores the
  breakpoint.

---

## Configuration

One constant, set in `wp-config.php` **before** the plugin loads:

```php
// Fall back to the standard 5-minute TTL (and stop sending the beta header).
define('MXCHAT_PLUS_PROMPTCACHE_EXTENDED_TTL', false);
```

Use it if your Anthropic account rejects the `extended-cache-ttl-2025-04-11` beta header,
**or for cost reasons**: the cache-write premium is **×2** at the 1-hour TTL versus
**×1.25** at 5 minutes. The 1-hour default protects conversations with pauses longer than
5 minutes (it avoids rewriting the whole history when the visitor comes back); for fast
back-and-forth chat (turns under 5 minutes) the 5-minute TTL is enough and cheaper.
Per-request tuning is available through the `ephemeral_control` filter below.

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

// Minimum number of messages before the history is cached (default 3).
// Lower to 1 to cache from the first turn on long sessions; keep >= 3 so one-shot
// chats don't pay the write premium for a cache nobody reads.
add_filter('mxchat_plus_promptcache_min_messages', function ($min, $payload) {
    return 1;
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
wp mxchat-plus promptcache debug              # last request: model, breakpoints, usage
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

Measurement reads the `usage` object each API returns: Anthropic
(`cache_read_input_tokens`, `cache_creation_input_tokens`, `input_tokens`),
OpenAI-compatible providers (`prompt_tokens_details.cached_tokens`) and Gemini
(`cachedContentTokenCount`).

### "Cache writes > reads"

`stats` prints this warning when the module is paying the write premium for prefixes that
are never read back. Two usual causes:

1. **An unstable prefix.** `get_system_instructions()` applies `{visitor_name}`, the
   `mxchat_system_instructions` filter and `do_shortcode()`. A shortcode or filter that
   injects volatile data — the current time, stock levels, a random greeting — into the
   system prompt invalidates the cache on every request. Move volatile content to the end
   of the conversation, after the last breakpoint.
2. **Traffic too low** to amortise the writes within the TTL. Consider the 5-minute TTL
   (cheaper writes) or accept that caching will not pay off at that volume.

---

## Known limits

- **Streaming is not interceptable** — see the top of this page.
- **OpenRouter cannot be injected into, by construction.** On the OpenRouter path MxChat
  concatenates the RAG context *into* the `system` message
  (`$system_prompt_instructions . " " . $relevant_content`), which puts volatile content at
  the head of the prefix and defeats prefix matching. Injecting there would only buy
  writes that are never read, so OpenRouter is **measured** (it caches server-side anyway)
  and never injected.
- **Multi-provider measurement covers non-streamed responses only** (`wp_remote_post`).
  Streamed cURL responses from those providers are invisible to the module.
- **Counters are best-effort under concurrency.** They accumulate by read-modify-write
  without a lock, so simultaneous requests can slightly undercount. Statistics only — the
  cache itself is unaffected. A persistent object cache keeps these writes in memory
  rather than in the database.
- **History must be stable.** The conversation breakpoints assume MxChat sends the same
  messages in the same order from one call to the next. Reformatting or truncating history
  between turns invalidates the cache.
- **Idempotent by design.** If a future MxChat version adds its own `cache_control` to the
  history, the module detects it and leaves the messages alone, while still caching
  `tools` and `system`.

## Uninstalling

Deactivating has no side effects — the module only registers filters, which stop being
applied. Statistics are kept (WordPress convention: deactivation does not delete data).
Deleting the plugin runs `uninstall.php`, which removes the persisted statistics with
multisite support. No custom tables, no cron events, no files on disk.
