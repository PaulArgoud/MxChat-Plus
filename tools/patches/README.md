# Upstream patch for MxChat (Option A)

> **Status: optional, unverified against the current host, and not recommended.**
>
> `mxchat_pre_vector_query` does **not** exist in mxchat-basic (verified absent
> in 3.2.25) — the filter this patch adds is our proposal, not an upstream API.
> Applying it means editing a third-party plugin that auto-updates: the next
> release reverts it silently, and retrieval falls back to Option B without
> warning.
>
> The diff and the line numbers below were taken on **3.2.6**. It does **not**
> apply to 3.2.21 or later: `patch --dry-run` reports 1 of 2 hunks failed on
> 3.2.21 and **2 of 2 hunks failed on 3.2.25**, where
> `MxChat_Integrator::find_relevant_content_pinecone()` now builds its request
> body through the `mxchat_pinecone_query_body` filter. The manual route below is
> the only option, and it is deliberately not re-based: maintaining a working
> host patch would go against this project's rule of never patching the host.
>
> Use Option B (the REST proxy) unless you have measured the round-trip and
> found it to matter. This directory is excluded from the release zip.

The plugin works **without** any patch via the Pinecone-proxy path (Option B):
MxChat is told that DuckDB is a Pinecone backend, and it talks to a local REST
endpoint we expose. This adds an HTTP round-trip and forces a JSON serialization
of the embedding vector, which is wasteful for large knowledge bases.

The patch below removes that overhead by giving MxChat a way to short-circuit
the Pinecone HTTP call with a PHP filter (`mxchat_pre_vector_query`). When the
filter returns an array, MxChat uses it directly and never fires
`wp_remote_post()`.

The filter follows WordPress core's `pre_*` short-circuit convention (the same
pattern used by `pre_get_posts`, `pre_user_query`, `pre_option_*`): return
`null` for default behaviour, return a value to bypass the rest of the function.

## How to apply

A ready-to-apply unified diff lives next to this file:
[`mxchat-pre-vector-query.diff`](mxchat-pre-vector-query.diff). From a checkout
of mxchat-basic:

```bash
cd path/to/mxchat-basic
patch -p1 < /path/to/mxchat-plus/tools/patches/mxchat-pre-vector-query.diff
```

Verified to apply cleanly (exact match, no offset or fuzz) against mxchat-basic
**3.2.6 through 3.2.8** only; it fails on 3.2.21 and 3.2.25 (see above). The patch
only touches `includes/class-mxchat-integrator.php` and adds ~14 lines.

### What changed upstream since the diff was written

- **`mxchat_pinecone_query_body`** (filter, MxChat 3.2.20+) now wraps the `/query`
  body in `find_relevant_content_pinecone()`. It does **not** replace this patch:
  it can only rewrite the body (and MxChat forces `vector`, `includeMetadata` and
  `includeValues` back and clamps `topK` after it), and the HTTP call still happens.
- **A second retrieval path exists**: `find_relevant_content_pinecone_documents()`
  (3.2.22+), taken when the bot's Pinecone index is a *document* index
  (`MxChat_Pinecone_Documents::is_document_index()`, opt-in). A complete Option A
  would need the same guard there. The DuckDB proxy never sends MxChat down that
  path — every config it hands out says index type `vector` — so it would matter
  only to another vector-store adapter.

## Submitting upstream

The fix is generic — any companion vector-store adapter (Qdrant, pgvector, …)
benefits — so it's worth landing in mxchat-basic itself. MxChat ships from the
[WordPress.org plugin directory](https://wordpress.org/plugins/mxchat/); there
is no public source repository to open a pull request against, so route the
proposal through MxChat's own channel (support form / contact on
[mxchat.ai](https://mxchat.ai/), or a WordPress.org support-forum thread) with:

- **Title:** `Add mxchat_pre_vector_query short-circuit filter to find_relevant_content_pinecone()`
- **Body:** the rationale + diff from
  [`mxchat-pre-vector-query.diff`](mxchat-pre-vector-query.diff) (the commentary
  above the `---` is written as a ready-to-paste PR description).
- **Compatibility note:** applies cleanly to 3.2.6–3.2.8 only (not 3.2.21 or later); follows the
  WordPress-core `pre_*` short-circuit convention; the existing
  `wp_remote_post()` flow is unchanged (just moved into the `else` branch), so
  there is no behavior change when no filter is registered.

Until it lands upstream, Option B (the Pinecone-proxy path) works with **zero**
patching — this is purely a latency optimization for installs that can patch.

## What it changes

File: `mxchat-basic/includes/class-mxchat-integrator.php`

Find the function `MxChat_Integrator::find_relevant_content_pinecone()` (around
line 5375 in mxchat-basic 3.2.6). Look for the block that calls
`wp_remote_post($api_endpoint, ...)` (around line 5451 in 3.2.6 — the line that
builds `https://{host}/query`). On 3.2.20+ that call follows the
`mxchat_pinecone_query_body` filter; wrap the call, not the filter.

**Wrap the HTTP block in an `else` branch** preceded by the filter call:

```php
// ──────────────── BEGIN PATCH ────────────────
// Let companion plugins (e.g. mxchat-plus) short-circuit the Pinecone HTTP
// call. Returning an array bypasses the network entirely; returning null
// falls through to the existing wp_remote_post() behavior.
$pre = apply_filters('mxchat_pre_vector_query', null, array(
    'vector'    => $user_embedding,
    'top_k'     => $request_body['topK'],
    'namespace' => $namespace,
    'bot_id'    => $bot_id,
));

if (is_array($pre)) {
    $results = $pre; // Expected shape: ['matches' => [...]]
} else {
// ───────────────── END PATCH (top) ────────────

    $response = wp_remote_post($api_endpoint, array(
        'headers' => array(
            'Api-Key' => $api_key,
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ),
        'body' => wp_json_encode($request_body),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        $this->current_valid_urls = [];
        return '';
    }

    $response_code = wp_remote_retrieve_response_code($response);

    if ($response_code !== 200) {
        $response_body = wp_remote_retrieve_body($response);
        $this->current_valid_urls = [];
        return '';
    }

    $response_body = wp_remote_retrieve_body($response);
    $results = json_decode($response_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $this->current_valid_urls = [];
        return '';
    }

// ──────────────── BEGIN PATCH (bottom) ────────
}
// ───────────────── END PATCH ──────────────────

if (empty($results['matches'])) {
    $this->current_valid_urls = [];
    return '';
}
// ... rest of function unchanged ...
```

The total change is ~12 lines added (a filter call + an `if/else` wrapper).
Everything downstream — chunk reassembly, role checks, similarity analysis,
RAG context assembly — is reused as-is.

## Contract

| Filter return | Effect |
|---|---|
| `null` (default) | Existing behavior: `wp_remote_post()` to Pinecone runs as before. |
| `['matches' => [...]]` | Short-circuit: the array is used as the Pinecone response. Each match must follow the Pinecone shape: `{id, score, metadata: {…}}`. |
| Anything else | Treated as `null` (defensive fall-through to HTTP). |

## How to verify the patch is active

After applying, open the admin page **MxChat → DuckDB / MotherDuck** and click
**Test connection**. Then send a message through the chatbot and watch the
proxy: with the patch active, a chat answer produces **no** `POST
/wp-json/mxchat-plus/v1/pinecone-proxy/query` request in the web server's access
log. If that request appears, the patch is not active and the plugin is operating
via the Option B proxy path. (The host's own `MXCHAT DEBUG: About to call Pinecone
API` log line, which older notes used for this check, is commented out in
3.2.25.)

## Backward compatibility

The companion plugin also hooks the legacy `mxchat_pinecone_matches_override`
filter (older patch contract — positional args, matches array returned
directly; also absent upstream in 3.2.25). Both hooks coexist safely: only whichever one the upstream patch
actually calls will fire. Installs that applied the previous patch keep
working without any change on their side.

## Submitting upstream

If MxChat maintainers accept this filter, the plugin will detect it
automatically and prefer Option A on every install. The same filter
naming convention naturally extends to the four admin-side `POST /query`
call-sites in `admin/class-pinecone-manager.php` —
`mxchat_semantic_search_pinecone()`, `mxchat_text_search_fallback()`,
`mxchat_query_based_list()` and `mxchat_get_recent_entries_safe()` — and to
sibling `mxchat_pre_vector_fetch` / `mxchat_pre_vector_delete` hooks for the
rest of the Pinecone wire protocol. Once a minimum supported MxChat version
ships the hook, the Option B REST emulation layer in `mxchat-plus` can be
deprecated.
