# Contributing to MxChat Plus

Thanks for thinking about contributing! Issues and pull requests are both welcome. This
file documents the conventions the project follows so reviews stay short.

## The one rule that is not negotiable

**`mxchat-basic` is a third-party plugin and is never modified.** Every integration point
goes through a WordPress hook. A change that only works if the user edits files under
`mxchat-basic/` is not mergeable here — it belongs upstream.

That extends to naming. Host symbols are used **verbatim** and must never be renamed or
prefixed:

```
mxchat_options                    mxchat_active_embedding_model
mxchat_system_prompt_content      mxchat_embedding_chunk_meta
mxchat_vectors                    mxchat_kb_*
mxchat_pinecone_*                 mxchat_get_bot_options
mxchat_get_bot_pinecone_config    wp_ajax_mxchat_bulk_delete_knowledge
wp_ajax_mxchat_delete_chunks_by_url  wp_ajax_mxchat_delete_pinecone_prompt
…and their mxchat_*_nonce nonces
```

Everything this plugin owns carries a `Plus`:

| Kind | Convention | Example |
|---|---|---|
| Classes | `MxChat_Plus_*` | `MxChat_Plus_DuckDB_Vector_Store` |
| Constants | `MXCHAT_PLUS_*` | `MXCHAT_PLUS_VERSION` |
| WP options | `mxchat_plus_*` | `mxchat_plus_duckdb_options` |
| Hooks we expose | `mxchat_plus_*` | `mxchat_plus_duckdb_bot_config` |
| Text domain | `mxchat-plus` — the **only** one | `__('…', 'mxchat-plus')` |

CI fails the build if a legacy `mxchat-duckdb` / `mxchat-promptcache` text domain survives.

## Reporting bugs

Use the [issue templates](.github/ISSUE_TEMPLATE). Include:

1. **Plugin version**, **WordPress version**, **PHP version**, **mxchat-basic version**.
2. **Which module** — vector store, prompt cache, or both.
3. For the vector store: the backend (MotherDuck / embedded) and whether the PECL `duckdb`
   extension is loaded or the CLI fallback is in use.
4. For the prompt cache: **whether MxChat streaming is on**. It is the cause of most
   "nothing is cached" reports — see the README.
5. **What you did**, **what you expected**, and **the last error** (admin page or
   `wp-content/debug.log` with `WP_DEBUG_LOG` on).
6. A `wp mxchat-plus duckdb stats` / `wp mxchat-plus promptcache stats` dump — it captures
   the most useful diagnostic in one paste. Redact tokens.

For security issues, email **paul@argoud.net** or open a private
[Security Advisory](https://github.com/PaulArgoud/mxchat-plus/security/advisories/new)
instead of a public issue. See [SECURITY.md](SECURITY.md).

## Pull requests

1. Fork, branch off `main`.
2. Keep PHP changes compatible with **PHP 8.1+**. CI lints and tests on 8.1, 8.2, 8.3 and
   8.4 — do not use syntax newer than 8.1.
3. Match the existing style: no tabs, 4-space indent, full `<?php` opening tags, and an
   `if (!defined('ABSPATH')) exit;` guard at the top of every included file.
4. Touch user-facing strings? Run them through `__()` / `esc_html__()` with the
   **`mxchat-plus`** domain, then refresh the catalogue (see below). CI fails if the
   committed `.mo` does not match the `.po`.
5. Add an entry to [`CHANGELOG.md`](CHANGELOG.md) under `## [Unreleased]`, categorised
   `Added` / `Changed` / `Fixed` / `Removed`. The two annexed pre-merge histories are
   frozen — never edit them.
6. New hook (filter / action / cron / REST route)? Document it in the module's docs page
   and add a one-line `@since` comment in the code.
7. New unit tests go under `tests/unit/duckdb/` or `tests/unit/promptcache/` so they land
   in the right PHPUnit suite.

## Running the checks

The toolchain is real and enforced in CI — there is a PHPUnit suite, a PHPStan
configuration at level 7 with a baseline, and a five-job GitHub Actions workflow
(`lint`, `i18n`, `phpstan`, `phpunit`, `audit`) on every push and PR.

```bash
composer install          # dev dependencies only; there are no runtime deps
composer lint             # php -l over the tree
composer test             # PHPUnit, both suites
composer stan             # PHPStan level 7

vendor/bin/phpunit --testsuite duckdb        # one module at a time
vendor/bin/phpunit --testsuite promptcache
```

Composer is **development-only**. `composer.json` deliberately declares no runtime
`autoload` section: the plugin boots through its own `require_once` chain and the release
zip ships no `vendor/` directory. Do not add a classmap over `includes/`.

A full functional test still needs a real WordPress install with `mxchat-basic` — the unit
suite runs against shims in `tests/shims/`, not against WordPress.

## Translations

The catalogue lives in `languages/` and uses a single domain, `mxchat-plus`.

```bash
# 1. Refresh the template (requires wp-cli; falls back to xgettext, see below)
wp i18n make-pot . languages/mxchat-plus.pot --domain=mxchat-plus \
    --exclude=tests,tools,phpstan,docs,mxchat-basic,vendor

# 2. Merge into the French catalogue
msgmerge --update --no-wrap --backup=none languages/mxchat-plus-fr_FR.po languages/mxchat-plus.pot

# 3. Translate the new entries, then compile — the .mo is committed on purpose
msgfmt --check --statistics languages/mxchat-plus-fr_FR.po -o languages/mxchat-plus-fr_FR.mo
```

Without wp-cli, `xgettext --language=PHP` with the WordPress keyword list
(`__:1`, `_e:1`, `esc_html__:1`, `esc_html_e:1`, `esc_attr__:1`, `esc_attr_e:1`, `_x:1,2c`,
`_n:1,2`, …) over `includes/` + `admin/` + the root PHP files produces the same template.

**Never commit a `.po` without recompiling the `.mo`.** The plugin ships as a zip and end
users never run `msgfmt`; a stale `.mo` means the French UI silently falls back to English
and nothing warns you.

## Design conventions

See [docs/duckdb/ARCHITECTURE.md](docs/duckdb/ARCHITECTURE.md) for the full list. The
non-negotiables for the vector store:

- No HTTP fan-out for MotherDuck — always go through DuckDB native + `ATTACH 'md:…'`.
- Always obtain backend handles through `MxChat_Plus_DuckDB_Connection_Factory::current()`
  so the per-request cache works.
- Schema changes go through a numbered migration on
  `MxChat_Plus_DuckDB_Vector_Store::TARGET_SCHEMA_VERSION`. Migrations are idempotent.
- Cache invalidation lives in the writer — every new write path must flush the query cache.
- Surfacing > swallowing. Use `error_log()` + the `last_error` option + the admin notice
  transient.

For the prompt cache:

- Inject on **Anthropic only**. Other providers cache server-side; measure them, never
  rewrite their requests.
- Never exceed Anthropic's 4 breakpoints, and never inject below the per-model minimum
  prefix — it is a silent no-op that still costs a write.
- Be idempotent: if `cache_control` is already present, leave the payload alone.

## Where the docs live

- [README.md](README.md) — onboarding, the two modules, and the operational prerequisites.
- [docs/duckdb/](docs/duckdb/) — architecture, configuration, hooks, CLI, usage, mirror,
  backup.
- [docs/promptcache/USAGE.md](docs/promptcache/USAGE.md) — breakpoints, thresholds,
  filters, CLI.
- [tools/patches/README.md](tools/patches/README.md) — the optional upstream patch.
- [CHANGELOG.md](CHANGELOG.md) — one `## [Unreleased]` entry per user-visible change.

New option → `docs/duckdb/CONFIGURATION.md`. New filter or action → the module's hooks
page. New CLI subcommand → `docs/duckdb/CLI.md` or `docs/promptcache/USAGE.md`.

## License

By contributing, you agree that your contributions will be licensed under the
[GPL v2 or later](LICENSE).
