# Click tracking — operator guide

The tracking module has two halves that do not talk to each other:

| Half | What it does |
|---|---|
| **`assets/js/tracking.js`** | Sends a **Matomo** and/or **GA4** event when a visitor clicks a link inside a bot answer, or clicks a suggested question. |
| **Click tracking tab** | A report over `wp_mxchat_url_clicks` — the table **MxChat itself** fills server-side — with a CSV export. |

They measure different populations and will not agree. That is not a bug; see
[The overlap with MxChat's own tracking](#the-overlap-with-mxchats-own-tracking), which is
the first question this module raises.

---

## Enabling it

**MxChat Plus → Modules → *Link and suggestion click tracking*.** **Off by default** — it
is the only module that puts code on public pages, and the only one that sends data to a
third party. Both are decisions for the site owner to make deliberately.

Once enabled, a **Click tracking** tab appears next to the others
(`admin.php?page=mxchat-plus&tab=tracking`). Nothing is sent until at least one of the two
destinations on that tab is ticked.

### Coming from the standalone "MxChat Link Tracking" plugin

The settings option was renamed from `mxchat_tracking_options` to
`mxchat_plus_tracking_options` — the `mxchat_` prefix belongs to the host plugin. The old
value is copied across **once**, on the first load of MxChat Plus, whether or not the
module is enabled; the old row is then deleted, and its absence is the "already migrated"
marker. Only the three settings the standalone plugin had (Matomo, GA4, debug) are carried
over; *Include the session id* and the custom dimension take this module's defaults.

> **Deactivate the standalone plugin.** With both running and the module enabled, every
> click produces **two** Matomo events — the two scripts do not know about each other and
> neither deduplicates against the other.

---

## What is tracked

Both listeners are registered on `document` in the **capture phase**, which is mandatory:
MxChat binds its own click handler directly on each `<a>` in a bubble and calls
`stopPropagation()` (`mxchat-basic/js/chat-script.js:2193`), so a normal bubble-phase
listener never sees a link click at all.

The handler **never calls `preventDefault()`**. MxChat cancels the event itself and
navigates programmatically from its own AJAX callback; cancelling the links it does *not*
bind — every relative one — would strand the visitor on the page with nothing happening.

| Tracked | Not tracked |
|---|---|
| `<a href>` inside `.bot-message` / `.agent-message` | Links inside `.user-message` — a URL the visitor typed or pasted is never shipped to analytics |
| Relative links (`/contact`), which MxChat's own log ignores | `mailto:`, `tel:`, `sms:`, `javascript:` |
| `<button class="mxchat-popular-question">` suggestions | Bare anchors (`#top`) and same-page fragment links |
| Middle-click and open-in-new-tab (`auxclick`) | Anything outside the widget's own DOM |

Two clicks on the **same** link within 500 ms count once — mobile browsers fire a
synthesized click after a touch sequence, and a double-tap sends two. The guard is keyed on
the link, so two quick clicks on two different links both count.

`auxclick` is bound unfiltered, so in engines that fire it for button 2 a **right-click**
also registers as one event.

---

## The settings

### Send events to Matomo

Default **on**. Events go through the `_paq` tracker already on the page; there is no site
id to enter here. On a page with no Matomo snippet nothing is sent and, with debug on, one
console line says so.

### Send events to Google Analytics 4

Default **off**. Requires `gtag()` to have been loaded by something else — a GA4 plugin, a
tag manager, or the snippet in your theme. This module never injects the GA4 tag.

### Include the MxChat session id in each event

Default **on**. The session id is the join key into MxChat's `wp_mxchat_chat_transcripts`
table: with it, a click can be traced back to the conversation that produced it. It is also
the one field here that leaves the site and outlives the host's own erasure path — see
[Privacy](#privacy).

The check is made at the source: with this off, no id reaches Matomo or GA4 by **any**
route — no custom dimension, no event-name suffix, no GA4 field.

### Matomo custom dimension

Default **0**, meaning none. Valid ids are **1–999**; anything else collapses to 0 and the
settings screen says so after saving. It has no effect while *Include the session id* is
off.

### Log every event to the browser console

Default **off**. See [Troubleshooting](#troubleshooting). It logs for **every visitor**,
not only for administrators.

---

## Matomo

Events are pushed onto the site's existing `_paq` queue, so the site id, the tracker URL
and any consent configuration are whatever your Matomo snippet already sets.

```
_paq.push(['trackEvent', 'MxChat', <action>, <name>]);
```

| Category | Action | Name |
|---|---|---|
| `MxChat` | `Clic lien interne` | The link's text, whitespace-collapsed and cut at 120 characters — or the resolved URL when the link has no text (an icon or image link) |
| `MxChat` | `Clic lien externe` | idem |
| `MxChat` | `Clic suggestion` | The suggested question's text, same treatment |

> **The three action labels are French and are deliberately left untranslated.** They are
> not UI strings — they are the row keys of your existing Matomo reports. Renaming them (or
> passing them through the i18n layer, which would make them vary by visitor locale) starts
> a fresh set of rows and forks the history in two on the day of the change.

Internal vs external is decided in the browser by **strict hostname equality** against the
current page. A subdomain is external; so is `www.example.com` on a site served from
`example.com`. (The server-side report normalises a leading `www.` and will therefore
disagree with the browser on exactly that case.)

For **external** links only, a second push follows:

```
_paq.push(['trackLink', <resolved url>, 'link']);
```

Internal links are left out on purpose: they already produce a pageview when they land, and
reporting them as outlinks too would count the same visit twice.

### The custom dimension, and what happens without it

When a session id is available **and** a dimension id is configured, it is pushed just
before the event:

```
_paq.push(['setCustomDimension', <id>, <session id>]);
```

Create that dimension in Matomo with **Action** scope, so the value attaches to the event
rather than to the whole visit. Note that the script never calls `deleteCustomDimension`:
if the visitor stays on the page, the value rides along on any further actions of that
pageview. In practice a tracked click navigates away.

**With no dimension configured**, the id has nowhere structured to go and is appended to
the event name instead:

```
Documentation — installing [session: 6f2a…]
```

That keeps the click joinable to a transcript, at a real cost: the Matomo *Events* report
explodes into roughly one row per visitor instead of grouping by label, and the session id
is spread through a dimension you cannot exclude from exports. If you want the join, create
the dimension; if you do not, turn *Include the session id* off.

---

## GA4

Nothing is sent unless `typeof window.gtag === 'function'` at click time.

| Event | Parameters |
|---|---|
| `mxchat_link_click` | `link_url` (resolved, absolute), `link_text` (may be empty), `link_scope` (`internal` / `external`), `link_target` (the `target` attribute, or `_self`), `mxchat_bot_id`, `mxchat_session_id` |
| `mxchat_suggestion_click` | `question_text`, `mxchat_bot_id`, `mxchat_session_id` |

`mxchat_session_id` is **omitted entirely** when there is no id or when the setting is off —
never sent as an empty string, which GA4 would treat as a legitimate blank dimension member
sitting next to the real ids.

`link_url`, `link_text`, `link_scope`, `link_target`, `mxchat_bot_id` and
`mxchat_session_id` are custom parameters: GA4 does not report on them until you register
each one as an **event-scoped custom dimension** in *Admin → Custom definitions*. Until you
do, the events are recorded but the reports show only their names.

---

## The overlap with MxChat's own tracking

MxChat has logged link clicks server-side since long before this module: its chat script
POSTs `mxchat_track_url_click`, and the handler inserts a row into
`{prefix}mxchat_url_clicks` (`mxchat-basic/includes/class-mxchat-integrator.php:14752`).

**The two counts measure different populations and will never reconcile.** In both
directions:

| Situation | MxChat's table | Our Matomo/GA4 event |
|---|---|---|
| Click on an absolute `http(s)` link in an answer | ✓ | ✓ |
| Click on a **relative** link (`/contact`) | ✗ — the host only binds absolute links (`chat-script.js:2188`) | ✓ |
| Click on a **suggested question** | ✗ — not a link, never tracked by the host | ✓ |
| Click **before a session exists** | ✗ — the handler rejects an empty `session_id` | ✓ (without the id) |
| Visitor runs an ad-blocker or tracker blocker | ✓ — it is a same-origin admin-ajax POST | ✗ — `matomo.php` / `gtag` are blocked, usually silently |
| Visitor has Matomo/GA4 blocked by a consent banner | ✓ | ✗ or queued, depending on the tracker's own consent mode |
| Internal vs external | not recorded — computed by us when reporting | recorded on the event |

So on a typical site the host's table will be *higher* than Matomo for plain outbound links
(blockers) and *much lower* overall (no relative links, no suggestions). Comparing the two
numbers is not a health check for either.

The one number that does mean something: if the host's table is growing and Matomo shows
nothing at all, the events are being blocked or the snippet is missing — see
[Troubleshooting](#troubleshooting).

---

## The clicked-links report

**MxChat Plus → Click tracking → Clicked links.** This section reads the host's table only;
it knows nothing about what Matomo received.

The table is created by MxChat's **activation** routine, so it can legitimately be absent —
on an install updated in place and never reactivated, for instance. Every reader inside the
host guards with a `SHOW TABLES LIKE`, and so does this one: when the table is missing the
tab says so instead of raising a database error.

- Up to **50 links**, most-clicked first, ties broken by most recent click.
- Columns: **URL**, **Scope**, **Clicks**, **Sessions** (distinct conversations),
  **Last click**.
- The totals line above the table covers the **whole table**, not just the 50 rows shown.
- `Last click` is stored by the host as UTC (`current_time('mysql', 1)`) and is converted
  to the site's timezone on screen. The CSV keeps UTC, and says so in its header.
- Internal vs external is computed in PHP at read time against `home_url()`, because the
  host never recorded it. A leading `www.` is ignored; a subdomain is external; a URL with
  no host is internal.
- **`user_ip` and `user_agent` are never read**, here or in the export. The host anonymises
  the IP and empties the agent 30 days after the click and deletes the row after a year
  (`MxChat_Privacy::sweep_url_clicks`). A downloaded CSV is swept by nobody, so copying
  either out of that retention window is not on offer.

### CSV export

The **Export CSV** button posts to `admin-ajax.php` (action and nonce action both
`mxchat_plus_tracking_export`, `manage_options` re-checked server-side) and downloads
**`mxchat-link-clicks-YYYY-MM-DD.csv`**: UTF-8 BOM so Excel reads accents, then `URL`,
`Scope`, `Clicks`, `Sessions`, `Last click (UTC)`.

Cells that begin with `=`, `+`, `-`, `@`, a tab or a carriage return are prefixed with a tab
so a spreadsheet does not execute them as formulas — a clicked URL is text the answer put
there, not something to hand Excel unexamined.

Limits: **5000 rows** per file (the aggregate is one row per distinct URL, so this is a
ceiling few sites reach), and the button exports **everything** — the handler accepts
optional `date_from` / `date_to` (`YYYY-MM-DD`, both or neither) but the screen has no date
fields to send them.

---

## What this module does not do

- **No consent management.** The only consent signal the host exposes is its Complianz
  integration: with `complianz_toggle` on, MxChat hides the widget unless
  `cmplz_has_consent('marketing')` returns true (`chat-script.js:3307-3366`). This module
  does **not** read that signal. In practice a hidden widget produces no clicks, but that
  is a side effect of the widget being hidden, not a consent check on our side — and MxChat
  keeps writing its own server-side click rows regardless of it.
  **Gate these events with your tracker's own consent mode**, which is where the decision
  belongs: with Matomo's `requireConsent`, our pushes simply queue in `_paq` and Matomo
  decides whether and when to send them; with a tag manager, load `gtag` only after
  consent and the GA4 half stays silent on its own.
- **No server-side forwarding.** Events are sent from the visitor's browser to whatever
  tracker is on the page. There is no Matomo HTTP API call, no Measurement Protocol, no
  queue and no retry: an event lost to a blocker or a closed tab is lost.
- **Nothing outside the chatbot's own DOM.** No pageviews, no scroll or time-on-page, no
  clicks elsewhere on the site, no message content. The module adds no table of its own and
  writes nothing to the database on the front end.
- **No filters or actions.** Unlike the other modules, this one exposes no extension points
  yet.

---

## Privacy

What leaves the site on a tracked click, and nothing else:

- the event category, action and name (the link's or suggestion's **visible text**, cut at
  120 characters — assistant-written text, but it can quote the visitor's question);
- the destination URL, resolved to absolute form;
- the bot id (GA4 only);
- **the MxChat session id**, when *Include the session id* is on and one exists.

No message body, no IP or user agent from us (the tracker on the page collects its own,
under its own settings), and no email or lead data.

The session id is a **pseudonymous join key**: it identifies the conversation in
`wp_mxchat_chat_transcripts` and in `wp_mxchat_url_clicks`, and anyone holding both the
analytics export and the WordPress database can read a visitor's conversation from an
analytics row. Turning *Include the session id* off is the conservative choice, and the
events remain useful without it.

**An erasure request satisfied through WordPress does not reach Matomo or GA4.** MxChat
registers a personal-data eraser that deletes the visitor's transcripts and the matching
`mxchat_url_clicks` rows (`mxchat-basic/includes/class-mxchat-privacy.php:424`); it has no
knowledge of the analytics vendors, and neither has this module. If you send session ids,
that id remains in Matomo or GA4 until you delete it there with the vendor's own tooling —
and with no custom dimension configured it sits inside the *event name*, which is harder to
target than a dimension. Record this in the site's privacy policy alongside the tracker
itself.

---

## Troubleshooting

Tick **Log every event to the browser console**, reload a front-end page and open DevTools.
Every line is prefixed `[MxChat Plus tracking]`.

On load:

```
[MxChat Plus tracking] Ready {matomo: true, ga4: false, sessionId: true, dimension: 0}
```

On a successful click — this is what a correct line looks like:

```
[MxChat Plus tracking] Matomo Clic lien externe Our pricing page [session: 6f2a…] https://example.com/pricing
```

(after the destination: the action, the event name, and the outlink URL when there is one).

### Nothing at all in the console

The script was not enqueued. In order of likelihood: the **module is off**
(*MxChat Plus → Modules*); **both destinations are off** — with neither Matomo nor GA4
ticked the file is not shipped at all, since it would be pure weight on every page; the
**debug setting is off**; or the page is a feed or `robots.txt`, which the module skips.

### `Matomo not on this page (_paq undefined), skipping`

The Matomo tracker is not on that page when the click happens. Either the snippet is not
installed there (a landing-page template, an AMP or cached variant), or a browser extension
removed it, or a consent script has not injected it yet. The event is dropped — it is not
queued for later.

### `GA4 not on this page (gtag undefined), skipping`

Same, for `gtag()`. This module never loads the GA4 tag; install a GA4 plugin or a tag
manager first.

### `Matomo disabled, skipping` / `GA4 disabled, skipping`

The destination's checkbox is unticked. Expected; the other one is presumably doing the
work.

### The console shows the event but Matomo/GA4 does not

The push happened and the request was blocked downstream. Check the Network tab for
`matomo.php` (or `g/collect`): a blocked or failed request there is an ad-blocker, a
tracker-blocking DNS resolver, or a Matomo `requireConsent` still waiting for consent.
Nothing in this module can see that outcome — which is exactly why the host's table and
Matomo will not agree.

### No `[session: …]` and no `mxchat_session_id`

MxChat creates the session **lazily** — on the first message sent, or on widget open only
when chat persistence is on (`chat-script.js:3505`). A click on a suggested question, or on
a link in the intro message, legitimately happens before any session exists. The id is
re-looked-up on every click and cached only once found, so later clicks in the same
conversation do carry it. Cookies and `localStorage` both being blocked (private mode,
partitioned storage) is the other cause; the host's in-memory copy covers most of those,
and is read first.

### `Duplicate link click ignored`

The 500 ms guard fired: a synthesized mobile click, a double-tap, or a `click` following an
`auxclick` on the same link. Only one event was sent, which is the intent.

### Suggestions attributed to the wrong bot

Should no longer happen. The standalone plugin resolved the bot by walking up to
`#chat-box-{botId}`, which never matches a suggested question — `#mxchat-popular-questions-{botId}`
is a **sibling** of the chat box, not a child (`mxchat-basic/includes/class-mxchat-public.php:366-370`).
The module now asks the host's own `window.getBotIdFromElement()` first and falls back to
`[data-bot-id]`, then the chat box, then `default`.

### The report is empty but Matomo shows clicks

Expected on most sites, and the reverse is expected too. Read
[The overlap with MxChat's own tracking](#the-overlap-with-mxchats-own-tracking) — the two
sides count different things by construction.

---

## Uninstalling

Turning the module off stops the script being enqueued and removes the tab; nothing is
deleted. Deleting the plugin removes the module's settings option (and the standalone
plugin's, if it is still lying around), on every site of a multisite network.

`wp_mxchat_url_clicks` is **never touched** — it is MxChat's table, created and written by
MxChat, and it predates this module and outlives it.
