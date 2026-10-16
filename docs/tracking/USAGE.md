# Click tracking — operator guide

The tracking module has two halves that do not talk to each other:

| Half | What it does |
|---|---|
| **`assets/js/tracking.js`** | Sends a **Matomo** and/or **GA4** event when a visitor clicks a link inside a bot answer, or clicks a suggested question — and, if you opt in, when a visitor opens the chat window. |
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

Both click listeners (`click` and `auxclick`) are registered on `document` in the
**capture phase**, which is mandatory:
MxChat binds its own click handler directly on each `<a>` in a bubble and calls
`stopPropagation()` (`attachLinkTracking()` in `js/chat-script.js`, MxChat 3.2.25), so a
normal bubble-phase listener never sees a link click at all.

The handler **never calls `preventDefault()`**, and is registered `passive` so that it
cannot. Since MxChat 3.2.24 the host cancels no link click anywhere: it records the click
with `navigator.sendBeacon` (`trackLinkClick()`) and lets the browser follow the anchor
itself. Nobody navigates on the visitor's behalf any more, so a cancelled click would
strand the visitor on **every** link in an answer, absolute ones included, with nothing
happening and no error anywhere. Holding the navigation back and resuming it from a
callback is not an option either: a `window.open()` made outside the visitor's tap is what
Safari's pop-up blocker drops without a word, the very bug MxChat 3.2.24 fixed.

(Before 3.2.24, MxChat cancelled absolute links itself and navigated from its own AJAX
callback. The rule here was the same; only its reason has changed.)

| Tracked | Not tracked |
|---|---|
| `<a href>` inside `.bot-message` / `.agent-message` | Links inside `.user-message` — a URL the visitor typed or pasted is never shipped to analytics |
| Relative links (`/contact`), which MxChat's own log ignores | `mailto:`, `tel:`, `sms:`, `javascript:` |
| `<button class="mxchat-popular-question">` suggestions, on a plain click | Bare anchors (`#top`) and same-page fragment links |
| Middle-click and open-in-new-tab on a link (`auxclick`) | A middle- or right-click on a suggestion — MxChat sends the question on `click` only, so nothing was asked |
| *Opt-in:* a floating chat window being opened (see [below](#send-an-event-when-the-chat-window-opens)) | An embedded chatbot being shown at page load |
| | Anything else outside the widget's own DOM |

Two clicks on the **same** link within 500 ms count once — mobile browsers fire a
synthesized click after a touch sequence, and a double-tap sends two. The guard is keyed on
the link, so two quick clicks on two different links both count.

`auxclick` is bound unfiltered for links, so in engines that fire it for button 2 a
**right-click** on a link also registers as one event.

A suggestion's event is sent a moment after the click (on the next task, never
synchronously): MxChat's own handler, which runs after ours, is what creates the
conversation's session id for a visitor who has never chatted, and waiting for it is what
lets that first click carry the id. A suggestion is a button, so nothing navigates in the
meantime. Link clicks are never delayed this way.

---

## The settings

### Send events to Matomo

Default **on**. Events go through the `_paq` tracker already on the page; there is no site
id to enter here. On a page with no Matomo snippet nothing is sent and, with debug on, one
console line says so.

### Send events to Google Analytics 4

Default **off**. Requires `gtag()` to have been loaded by something else — a GA4 plugin, a
tag manager, or the snippet in your theme. This module never injects the GA4 tag.

### Send an event when the chat window opens

Default **off**. When ticked, a Matomo event with the action `Ouverture chat` and a GA4
event `mxchat_chat_open` are sent when a visitor opens a chatbot's floating window, **at
most once per chatbot per page load**. It needs **MxChat 3.2.22 or later**, which
announces the opening with its `mxchat:opened` page event; on an older MxChat nothing is
sent. It has no effect while both destinations are off.

What counts as "opened" is MxChat's own definition, and it is worth knowing before you
build a funnel on it:

- **Embedded chatbots are never reported.** MxChat announces them as opened at page load,
  on every page view, because they are on screen from the start. Reported, they would turn
  every single-page visit to such a page into a non-bounce in Matomo, which counts every
  event as an interaction.
- **A floating window counts however it was revealed** — its launcher, the pre-chat
  bubble, or a script MxChat does not own (the Trigger add-on, a theme button, an
  auto-open), which MxChat detects by watching the window. An automatic opening is
  therefore reported as an opening.
- **The session id is usually absent**, as for any event before the first message: MxChat
  creates the session when the visitor first sends something (or on open, with chat
  persistence on). Build an open → click funnel on the analytics visit, not on the session
  id.

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
| `MxChat` | `Ouverture chat` | The chatbot's id (`default` for the main one). Only with the opt-in setting above |

> **The action labels are French and are deliberately left untranslated.** They are
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
| `mxchat_link_click` | `link_url` (resolved, absolute), `link_text` (may be empty), `link_scope` (`internal` / `external`), `link_target` (the tab the link actually opens in — see below), `mxchat_bot_id`, `mxchat_session_id` |
| `mxchat_suggestion_click` | `question_text`, `mxchat_bot_id`, `mxchat_session_id` |
| `mxchat_chat_open` | `mxchat_bot_id`, `mxchat_session_id`. Only with the opt-in setting above |

`link_target` is `_blank` when the link has `target="_blank"`, **or** when MxChat will give
it that target as the click goes through: with *Open links in new tab* on, MxChat
retargets every absolute `http(s)` link in a chat box (`prepareTrackedLink()` in
`js/chat-script.js`, MxChat 3.2.25). For most links it does so before any click; for the
intro message, replayed history and a click in the first moments after an answer appears,
it does so after our listener has run, so the module applies the same rule itself — on
the raw `href`, so a relative link is never counted as one MxChat retargets. Otherwise it
is the `target` attribute, or `_self`.

`mxchat_session_id` is **omitted entirely** when there is no id or when the setting is off —
never sent as an empty string, which GA4 would treat as a legitimate blank dimension member
sitting next to the real ids.

`link_url`, `link_text`, `link_scope`, `link_target`, `question_text`, `mxchat_bot_id` and
`mxchat_session_id` are custom parameters: GA4 does not report on them until you register
each one as an **event-scoped custom dimension** in *Admin → Custom definitions*. Until you
do, the events are recorded but the reports show only their names.

---

## The overlap with MxChat's own tracking

MxChat has logged link clicks server-side since long before this module: its chat script
sends `mxchat_track_url_click` — with `navigator.sendBeacon` since 3.2.24, a plain AJAX POST
as the fallback (`trackLinkClick()` in `js/chat-script.js`) — and the handler inserts a row
into `{prefix}mxchat_url_clicks` (`MxChat_Integrator::mxchat_track_url_click()`, MxChat
3.2.25). Links in a regular answer are bound by `attachLinkTracking()`; links in a streamed
answer, the intro message or restored history are caught by its delegated fallback on
`.chat-box a[href]`.

**The two counts measure different populations and will never reconcile.** In both
directions:

| Situation | MxChat's table | Our Matomo/GA4 event |
|---|---|---|
| Click on an absolute `http(s)` link in an answer | ✓ | ✓ |
| Click on a **relative** link (`/contact`) | ✗ — both host paths only record links whose `href` starts with `http://` or `https://` | ✓ |
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

### Reading the report across a MxChat update

The host's counts are **not comparable across the MxChat 3.2.24 and 3.2.25 updates — in
either direction**:

- **More clicks counted.** Before 3.2.24, the delegated fallback marked a link after its
  first click and then ignored it, so a visitor clicking the same link in a streamed or
  restored answer three times produced one row. From 3.2.24 every click is a row.
- **Fewer clicks counted.** Before 3.2.24, with *Open links in new tab* on, Safari's pop-up
  blocker silently swallowed the new tab, and visitors who clicked again produced one row
  per retry. From 3.2.24 the first click opens.
- **New and split rows.** 3.2.25 turns more addresses into links (one that opens a
  paragraph or sits in bold), and stops garbling some it used to store wrongly — an address
  followed by `**`, or a `www.` address at the end of a paragraph stored with a trailing
  `%3C/p%3E…`. The old, garbled spelling and the correct one then appear as **two rows**
  for the same page.

The **Sessions** column (distinct conversations) is the steadier one for comparing months
across the update: repeated clicks move *Clicks*, not *Sessions*. The report deliberately
does not merge the garbled spellings: no portable SQL can normalise them on the MySQL
versions WordPress supports, and merging after the query's limit would miss rows.

---

## The clicked-links report

**MxChat Plus → Click tracking → Clicked links.** This section reads the host's table only;
it knows nothing about what Matomo received.

The table is created by MxChat's `mxchat_activate()`, which runs when MxChat is activated
**and again on the first request after every MxChat update** (`mxchat_check_for_update()`).
So when it is missing, its creation failed (a `dbDelta` error) or the table was removed
since; deactivating and reactivating MxChat creates it at once, and the next MxChat update
would too. Every reader inside the host guards with a `SHOW TABLES LIKE`, and so does this
one: when the table is missing the tab says so instead of raising a database error.

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

The file is RFC 4180: every `"` inside a cell is doubled, whatever precedes it (PHP's
`fputcsv()` is called with an empty escape character).

Limits: **5000 rows** per file (the aggregate is one row per distinct URL, so this is a
ceiling few sites reach), and the button exports **everything** — the handler accepts
optional `date_from` / `date_to` (`YYYY-MM-DD`, both or neither) but the screen has no date
fields to send them. Those dates are the **site's** calendar days, converted to UTC before
they are compared with the host's UTC timestamps, exactly as the transcripts export does
(`MxChat_Plus_Transcripts_Export::local_range_to_gmt()`).

---

## What this module does not do

- **No consent management.** The only consent signal the host exposes is its Complianz
  integration: with `complianz_toggle` on, MxChat hides the widget unless
  `cmplz_has_consent('marketing')` returns true (`initializeChatVisibility()` in
  `js/chat-script.js`, MxChat 3.2.25). This module
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
  clicks elsewhere on the site, no message content — the opt-in *chat opened* event is the
  only one that is not a click. The module adds no table of its own and writes nothing to
  the database on the front end.
- **No filters or actions.** Unlike the other modules, this one exposes no extension points
  yet.

---

## Privacy

What leaves the site on a tracked event, and nothing else:

- the event category, action and name (the link's or suggestion's **visible text**, cut at
  120 characters — assistant-written text, but it can quote the visitor's question; for
  the opt-in *chat opened* event, the chatbot's id);
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
`mxchat_url_clicks` rows (`MxChat_Privacy::erase_personal_data()`, MxChat 3.2.25); it has no
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
[MxChat Plus tracking] Ready {matomo: true, ga4: false, sessionId: true, dimension: 0, chatOpen: false}
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
**debug setting is off**; the page has **no chatbot** and MxChat's *Only load chatbot files
where the chatbot appears* is on (see below); or the page is a feed or `robots.txt`, which
the module skips.

A page served from a full-page cache (WP Rocket, LiteSpeed, …) keeps the settings it was
cached with. MxChat clears those caches when its own settings are saved, and this module
asks it to do the same when the tracking settings or the module switches are saved. If
you turned that clearing off in MxChat (*Settings → Optimization → Page Cache
Compatibility*, MxChat 3.2.25), purge the cache yourself after a change.

### Which pages get the script

By default, **every** front-end page: MxChat prints the widget itself late in the page, so
whether it will be there is not known when scripts are queued, and the script costs nothing
until something happens inside the widget.

With MxChat's **Only load chatbot files where the chatbot appears** on (*Settings →
Optimization → Script Loading*), the module follows MxChat's own decision instead, so the
optimisation you switched on is not undone by us:

- when MxChat decides the chatbot will appear on the page, the script is queued at once;
- when it skips its own files, the script is held back, and added only if the chatbot
  renders after all — MxChat then loads its stylesheet late, which is the signal used —
  either in time for the footer scripts or, for a chatbot rendered at the very end of the
  page, printed right after them.

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

A link that opens **in the same tab** unloads the page as soon as the click is handled, so
its event survives only if the tracker sends it in a way that outlives the page
(`sendBeacon`). Matomo 4 and later does so by default — do not call
`disableAlwaysUseSendBeacon` — and GA4's `gtag.js` normally does too; a Matomo 3 tracker
may lose same-tab clicks. (This depends on the tracker's own runtime behaviour, which this
module cannot observe.) This was always true of relative links; since MxChat 3.2.24 it is true of
absolute ones too, because MxChat no longer holds the page while it records the click. The
module will never delay the navigation to make up for it.

### No `[session: …]` and no `mxchat_session_id`

MxChat creates the session **lazily** — on the first message sent, or on widget open only
when chat persistence is on. A click on a link in the intro message, or a *chat opened*
event, legitimately happens before any session exists. A click on a suggested question
does carry the id, even for a first-time visitor: the event waits for MxChat's own handler,
which sends the question and creates the session. The id is looked up afresh on every
event, never cached, so an event after **Start new chat** carries the new conversation's
id. Cookies and `localStorage` both being blocked (private mode, partitioned storage) is
the other cause; the host's in-memory copy covers most of those, and is read first.

### No `Ouverture chat` / `mxchat_chat_open` event

Check, in order: the setting is ticked; MxChat is 3.2.22 or later; the chatbot is a
**floating** one (an embedded chatbot is never reported, by design); and the window was not
already opened once on this page — the event is sent once per chatbot per page load. With
debug on, an embedded chatbot logs `Embedded chatbot shown at load, not reported as an
open`.

### `Duplicate link click ignored`

The 500 ms guard fired: a synthesized mobile click, a double-tap, or a `click` following an
`auxclick` on the same link. Only one event was sent, which is the intent.

### Suggestions attributed to the wrong bot

Should no longer happen. The standalone plugin resolved the bot by walking up to
`#chat-box-{botId}`, which never matches a suggested question — `#mxchat-popular-questions-{botId}`
is a **sibling** of the chat box, not a child (`MxChat_Public::render_chatbot_shortcode()`
prints it after the chat box closes, MxChat 3.2.25).
The module now asks the host's own `window.getBotIdFromElement()` first and falls back to
`[data-bot-id]`, then the chat box, then `default`.

### Checking it after a MxChat update

Several behaviours depend on the timing of MxChat's own script, and the plugin's unit
tests can only check the code that produces them, not the result in a browser. After a
MxChat update, or before trusting a new funnel, check them by hand, with **Log every event
to the browser console** ticked, in a fresh private window:

1. Before typing anything, click a **suggested question**. The event carries a session id:
   `[session: …]` in the Matomo line when no custom dimension is set, `mxchat_session_id`
   in the GA4 payload.
2. Click **Start new chat**, then a suggestion or a link again. The session id is a new
   one.
3. With MxChat's *Open links in new tab* on, click a **relative** link (`/contact`) in an
   answer: the GA4 `link_target` is `_self`. An absolute `https://` link reads `_blank`.
4. Click any link in an answer. The browser follows it: an event line must never come
   with a link that does nothing.
5. With *Send an event when the chat window opens* ticked, open a floating chatbot: one
   `Ouverture chat` line. Close and reopen it: nothing more until the page reloads.

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
