# Security Policy

## Reporting a vulnerability

If you believe you've found a security vulnerability in **MxChat Plus**, please report it
privately so it can be fixed before details become public.

**Preferred channel** — open a private
[GitHub Security Advisory](https://github.com/PaulArgoud/mxchat-plus/security/advisories/new).
This keeps the report confidential, lets us collaborate on a fix in a private fork, and
produces a CVE-friendly disclosure trail.

**Email fallback** — `paul@argoud.net`. PGP available on request.

Please **do not** open a public GitHub issue, post on social media, or discuss the
vulnerability in a public chat until a patched release ships.

### What to include

A useful report contains, at minimum:

- The plugin version (`MXCHAT_PLUS_VERSION` in `mxchat-plus.php`).
- **Which module** is affected — the DuckDB / MotherDuck vector store or the prompt cache.
- For the vector store: the backend (`embedded` or `motherduck`) and whether the PECL
  `duckdb` extension is loaded.
- A reproduction scenario — concrete enough that a developer can follow it on a clean
  WordPress install with `mxchat-basic` activated.
- The observed impact (read of data outside scope, write/delete without auth, remote code
  execution, secret disclosure, etc.).
- A suggested fix, if you have one.

### What we commit to

- **Acknowledgement** of the report within **5 business days**.
- **Triage + impact assessment** shared back within **10 business days**.
- **Patched release** for confirmed vulnerabilities as fast as the fix complexity allows —
  typically days for an isolated bug, longer if it touches the data layer or the
  integration with the host plugin.
- **Credit in the release notes** if you'd like (and a CVE filed when warranted), unless
  you prefer to stay anonymous.

## Scope

In scope:

- The plugin's REST endpoints under `/wp-json/mxchat-plus/v1/*` (Pinecone proxy + health).
- The admin AJAX handlers registered by the plugin.
- The WP-CLI commands (`wp mxchat-plus …`).
- The SQL construction in `Vector_Store`, `Vector_Store_Query`, `Vector_Store_Schema`,
  `Mysql_Sync`, `Compactor`, `Pinecone_Migrator`.
- The proxy authentication path (`MxChat_Plus_DuckDB_Pinecone_Proxy::check_token()`).
- The directory blockers written by `MxChat_Plus_DuckDB_Options::write_directory_blockers()`.
- The outbound-request rewriting done by the prompt-cache module on `http_request_args` —
  in particular anything that could leak an API key or one tenant's conversation into
  another's cached prefix.

Out of scope (please report to upstream instead):

- Vulnerabilities in `mxchat-basic` itself — report to that project. This plugin never
  modifies it; a finding that requires the optional `tools/patches/` diff to be applied to
  the host is an upstream issue, not one here.
- Vulnerabilities in DuckDB, the DuckDB VSS extension, MotherDuck, or the PECL `duckdb`
  extension — report to those projects.
- Vulnerabilities in Anthropic's or any other provider's API — report to that provider.
- Vulnerabilities in WordPress core — report via the
  [WordPress Security Team](https://wordpress.org/about/security/).
- Misconfiguration on the operator's side that isn't enabled by an unsafe plugin default
  (e.g. running an HTTP-only site and exposing the `.duckdb` file after bypassing the
  auto-written blockers).

## Supported versions

Only the latest **minor** release is supported with security fixes:

| Version | Status |
|---|---|
| 1.0.x | ✅ supported |
| mxchat-duckdb 0.13.x and earlier | ❌ superseded by MxChat Plus 1.0.0 |
| mxchat-promptcache 0.8.x and earlier | ❌ superseded by MxChat Plus 1.0.0 |

The two predecessor plugins are end-of-life. Migrate to MxChat Plus 1.0.0 to keep
receiving fixes.

## Hardening defaults

The plugin ships with these protections enabled by default — disabling them is explicitly
your choice:

- `.htaccess` + `index.php` + `web.config` blockers auto-written to the embedded data
  directory.
- Per-namespace REST tokens (random `bin2hex(random_bytes(24))`), constant-time comparison
  via `hash_equals()`.
- Per-namespace rate limit on the Pinecone-proxy REST endpoints (120 req/min by default).
- Nonce + `manage_options` capability check on every admin AJAX endpoint.
- Cascade-delete handler re-checks both the nonce and the capability before touching DuckDB.
- DuckDB CLI invocation uses `proc_open()` in array form (no shell) with a 30 s
  non-blocking deadline.
- The `embedded_binary` setting probes the candidate path with a marker `SELECT`, so a
  non-DuckDB binary (e.g. `/bin/sh`) surfaces a settings warning on save.
- The prompt-cache module never logs request or response bodies; its debug record keeps
  only the model name, breakpoint flags and token counts, and it never persists an API key.
