# Transcripts CSV export — operator guide

The transcripts module adds **two buttons** to MxChat's own transcripts screen
(**admin.php?page=mxchat-transcripts**):

- a download icon right after the host's **Delete Selected** control, which exports the
  conversations you ticked — or, with nothing ticked, every conversation inside a date
  range;
- an **Export this conversation (CSV)** button right after the host's own **Export**, in
  the details drawer of the open conversation, which exports that conversation only.

It adds nothing else: no settings tab, no cron, no REST route, no CLI command. Enabling
the module is the whole configuration.

---

## Why it exists

MxChat already ships an export, `wp_ajax_mxchat_export_transcripts`. That one dumps the
**entire** `wp_mxchat_chat_transcripts` table unconditionally — the checkboxes on the
screen have no effect on it, and there is no date filter. The **Export** button inside an
open conversation calls the same action, so it too hands you every conversation on the
site. On an install with months of traffic, "give me the five conversations from last
Tuesday" means exporting everything and filtering by hand.

This module exports **exactly what you asked for**, under its own AJAX action
(`mxchat_plus_export_transcripts`) and its own nonce, so it never collides with the host's.
The host's buttons are left exactly as they are.

---

## Enabling it

**MxChat Plus → Modules → *CSV export of selected transcripts*.** On by default.

The module only registers its hooks in the admin (`is_admin()`), and its script is only
enqueued when the `page` query argument is exactly `mxchat-transcripts` **and** the current
user can `manage_options`.

---

## The three ways to export

### Mode 1 — export the selection

Tick one or more conversations, then click the download icon next to **Delete Selected**.
Those conversations are exported immediately; no dialog appears.

**The selection is the one MxChat counts**, across pages. MxChat keeps your ticks when you
change page, search, sort or click Refresh — its toolbar keeps saying "5 selected" and
**Delete Selected** would delete all five — even though only the current page's rows are on
screen. The export follows the same rule: tick three conversations on page 1, two on page 2,
and the file holds all five. **All** follows MxChat too: ticking it adds the conversations on
screen, and ticking it while the list shows none (while it loads, or on "No chats found")
adds nothing and leaves the selection alone. Unticking **All** clears the whole selection,
other pages included, exactly as it does in MxChat; so does a successful delete.

Three safety nets may ask for confirmation before the file is requested:

- *"MxChat shows N selected conversations, but this export will contain M. Export anyway?"*
  — the module keeps its own copy of the selection (MxChat's is private to its script), and
  compares it with MxChat's counter before exporting. They should always agree; if they do
  not, cancel, reload the screen and tick again.
- *"MxChat shows N selected conversations, but this export lost track of them. […] Export by
  date range instead?"* — the same check when the module's copy is empty while MxChat
  still counts a selection. **OK** opens the date-range dialog, **Cancel** does nothing; to
  export those conversations, reload the screen and tick them again.
- *"Only the first 2000 of the N selected conversations will be exported. Continue?"* — see
  [Limits](#limits).

Unlike the host's **Delete Selected**, this button stays clickable with an empty selection
— that is deliberate, because an empty selection is the entry point to mode 2. "Empty"
means nothing ticked on *any* page: ticks left on another page are exported, not ignored.

### Mode 2 — export a date range

Click the button with **nothing** ticked and a modal opens (styled with the host's own
`.mxch-modal-overlay` / `.mxch-modal-content` classes, so it looks like MxChat's other
dialogs). It asks for a **Start date** and an **End date**, pre-filled with **the last 30
days, ending today** — computed from the browser's *local* date, so it does not slip a day
for anyone far from Greenwich.

Both bounds are **inclusive** and are **days in the site's timezone** (Settings → General),
the same timezone MxChat's panel displays times in: the range runs from `00:00:00` on the
start day to `23:59:59` on the end day, site time. MxChat stores every timestamp in UTC, so
the module converts both bounds to UTC before querying. On a Paris site in October (UTC+2),
"the 16th to the 16th" therefore selects `2026-10-15 22:00:00` to `2026-10-16 21:59:59` UTC —
which is exactly the conversations the panel shows on the 16th. Each bound gets the offset
in force at that moment, so a range across a daylight-saving change stays exact.

The end of a day is taken as one second before the *next* day's midnight, not as `23:59:59`
converted on its own. The two differ only where the clocks change at midnight: on a Chilean
site the clocks go back from 00:00 to 23:00 in early April, so 23:00–23:59 happens twice,
and on a Greenland site they jump from 23:00 to 00:00 in late March, so 23:00–23:59 never
happens. Either way, exporting one day and then the next covers every message exactly once.

Picking the same date twice exports that whole day — without the end-of-day stretch, a bare
date would compare against midnight on a `TIMESTAMP` column and return nothing.

An end date far in the future, such as `9999-12-31`, works as "from the start date
onwards". West of Greenwich the last second of that day falls in the year 10000 in UTC, a
date MySQL cannot spell, so the module caps the bound at `9999-12-31 23:59:59` UTC.

The dialog closes on **Cancel**, on the **×**, on **Escape**, or on a click outside the
box. It refuses to submit when a date is missing (*"Please provide both a start and an end
date."*) or when the start is after the end (*"The start date must be before the end
date."*). Should reversed bounds reach the server anyway, it swaps them rather than
failing — the intent is unambiguous.

> There is no "export everything" mode, by design. With no selection *and* no valid date
> pair the request is rejected with a 400, so a stray click can never dump the whole table.

### Mode 3 — export the open conversation

Open a conversation, show its details (the ⓘ button), and click **Export this conversation
(CSV)**, next to MxChat's own **Export**. Only that conversation is exported.

The button is greyed out while no conversation is loaded: before you open one, while one
is loading, after a load failed, after the open conversation was deleted, and after the
mobile back button. It works the same for a conversation opened from the **Leads** tab's
*View latest conversation* button.

MxChat's own **Export** button, right beside it, is untouched and still exports the whole
table.

---

## The file you get

The response is sent as a download — `Content-Type: text/csv; charset=utf-8`, with
`Content-Disposition: attachment` and the filename
**`mxchat-transcripts-YYYY-MM-DD.csv`** (today's UTC date).

It opens in a new tab, because the export is submitted as a real hidden form POST rather
than a `fetch()`: the browser's own download machinery handles the response, so nothing has
to be buffered in memory as a blob.

The file starts with a **UTF-8 BOM** — without it Excel mis-decodes accented characters —
then a header row, then **one row per message** (not per conversation):

| # | Column | Content |
|---|---|---|
| 1 | `Session ID` | The conversation identifier, the same value the host's checkboxes carry. |
| 2 | `Email` | `user_email` as stored by MxChat, when the visitor gave one. |
| 3 | `User identifier` | `user_identifier` as stored by MxChat (a user login, or the visitor's IP for guests). |
| 4 | `Role` | Who wrote the message — MxChat's own `role` value, written as stored (see below). |
| 5 | `Message` | The message body: a visitor's message as the panel shows it, every other row as stored (see below). |
| 6 | `Timestamp (UTC)` | The row's `timestamp`, as stored. MxChat stores **UTC**; its panel shows site time, so these read offset by the site's UTC offset. |
| 7 | `Name` | `user_name`: the name the visitor gave in the pre-chat form, when MxChat asked for one. |
| 8 | `Originating page` | `originating_page_url`: the page the conversation started on. |

**Role** takes four values in MxChat:

| Value | Meaning |
|---|---|
| `user` | The visitor. |
| `bot` | The AI's answer, or a canned reply. A product card is saved as an extra `bot` row holding its HTML, right after the text. |
| `agent` | A live agent's reply (Slack or Telegram handoff). Telegram replies are prefixed `Agent: <name> - `. |
| `system` | Mode-switch notices such as *"Switched to AI chat mode"*. |

MxChat never stores `assistant`: the word only appears as a label in its emailed transcripts
and in the requests it sends to AI providers. Since MxChat 3.2.22, add-ons can append rows through
`MxChat_Utils::save_session_message()`, which only accepts these four values; a row
written to the table with direct SQL could still carry anything, and is exported as is.

**Message.** A visitor's message (`user` rows) is stored the way WordPress hands it to
MxChat: with backslashes before quotes (`I\'m`, `\"`) and with `&` encoded as `&amp;`.
MxChat's panel undoes both when it displays the message, and so does the export, for
`user` rows only. Bot, agent and system rows are exported **exactly as stored**: they are
never slashed, so stripping backslashes would destroy real ones (code samples, Windows
paths), and they may legitimately contain HTML.

Rows are ordered by **session, then timestamp, then row id**, so each conversation reads
top to bottom as it happened — including the messages MxChat writes within the same second,
such as a bot answer and its product card, which a timestamp alone does not order.

**How this compares with MxChat's own export.** Columns 1–6 are the host's columns, in the
host's order, so a spreadsheet built on MxChat's file reads ours by the same column
numbers. Columns 7 and 8 are additions — the host's file has no name, and since MxChat
3.2.22 a lead can be captured with a name and *no* email, in which case the name is the only
identity the conversation carries. They are appended at the end, never inserted, for the
same reason. On an install whose table lacks one of those columns, the column is still in
the header and its cells are empty, so every file has the same shape. Three further
differences are deliberate: the `(UTC)` in the timestamp header, the visitor-message
decoding above, and the id tie-breaker in the ordering (the host's file orders by timestamp
only, while MxChat itself replays a conversation by row id).

The quoting follows **RFC 4180**: every `"` inside a cell is doubled. MxChat's own export
uses PHP's default backslash escape instead, which leaves a stored `\"` undoubled — Excel
and other RFC 4180 readers then shift that row's columns, or split it at the next line
break.

---

## Security

| Guard | What it does |
|---|---|
| **Capability** | `manage_options`, checked both when enqueuing the script and again in the export handler. A user without it gets a 403. |
| **Nonce** | A dedicated `mxchat_plus_export_transcripts` nonce, verified with `check_admin_referer()`. It is this module's own — the host's nonces are never reused. |
| **Prepared SQL** | Session ids come from the browser, so every value is bound. The `IN (…)` placeholder list is built from the **count** of ids, never from their content. The column list is built from a fixed list of known names, never from input. |
| **Date validation** | Strict `YYYY-MM-DD` plus `checkdate()`, so `2026-02-30` is rejected as firmly as `2026-01-01' OR '1'='1`. |
| **Export ceiling** | At most **2000** conversations per export (see below). |
| **CSV injection** | Neutralised — see below. |

### CSV injection

Chat messages are written by site visitors. A spreadsheet treats a cell starting with
`=`, `+`, `-` or `@` (and `\t` or `\r`) as a **formula**, so a visitor could type
`=HYPERLINK("http://evil.test","click")` or `=cmd|' /C calc'!A0` into the chat widget and
have it execute on an admin's machine when the export is opened.

Every cell a visitor can influence is therefore prefixed with a tab character whenever it
begins with one of those: the message, but also the email (a valid address may start with
`=`, `+` or `-`), the user identifier (a login may start with `-` or `@`), the name, the
originating page and the session id. The text stays readable; it is simply no longer the
first thing the spreadsheet parses. Ordinary values — including `a=b` and `1+1`, where the
character is not in first position — are written untouched.

The check runs **after** the visitor-message decoding described above, because decoding can
itself produce a leading `=` (`&#061;` is an encoded `=`).

---

## Limits

- **2000 conversations per export.** Beyond that the list is truncated to its first 2000
  entries rather than rejected, so a "select all" on a large install still produces a
  usable file. The button warns before it happens (*"Only the first 2000 of the N selected
  conversations will be exported."*); split the work into several exports, or use the
  date-range mode over narrower periods.
- **The cap counts conversations, not messages.** A range export is bounded by its dates,
  not by this ceiling — a wide range on a busy install can produce a very large file.
- **Large selections are sent as one field.** The selected ids travel as a single
  JSON-encoded field (`session_ids_json`), not one form field per id: PHP silently drops
  every field past `max_input_vars` (1000 by default), which a selection kept across pages
  can exceed.
- **Range bounds follow the site's timezone, not the browser's.** If your computer and the
  site are in different timezones, the dates you pick are read as the site's days — the
  same days the panel groups conversations by.
- **A visitor message written by an add-on may lose a backslash.** Since MxChat 3.2.22, an
  add-on can write a `user` row through `MxChat_Utils::save_session_message()` without the
  backslashes WordPress adds to form input, and nothing in the row says which path wrote it.
  Undoing the backslashes then removes a genuine one — exactly as MxChat's own panel does.
- **No progress indicator.** The export is a single synchronous request; on a large range
  the new tab simply takes a moment before the download starts.
- **The table is the host's.** Reading `wp_mxchat_chat_transcripts` directly is the one
  coupling this module accepts — it is the only way to honour the request, and it is the
  same schema the host's own export reads.

---

## Troubleshooting

### The button does not appear

In order of likelihood:

1. **The module is off.** Check **MxChat Plus → Modules** and tick *CSV export of selected
   transcripts*.
2. **Wrong screen.** The script is only enqueued on `admin.php?page=mxchat-transcripts` —
   the host's transcripts screen, not the chat settings.
3. **Insufficient rights.** Without `manage_options` (editors, shop managers, most custom
   roles) the script is never enqueued at all.
4. **The host's markup changed.** The toolbar button is injected next to the element with
   id `mxch-delete-selected`, and the conversation button next to `mxch-export-current`. If
   a future MxChat release renames them, there is nothing to anchor to — the console will
   be silent, because the injection simply does nothing.

### The button disappears when I change page or search

It should not. MxChat's toolbar is static markup: paging, sorting and searching only
re-render the list of conversations and the pagination below it, never the toolbar the
button sits in. A `MutationObserver` also re-injects the button if a future MxChat version
starts rebuilding that toolbar — a safety net, not something today's MxChat needs. If the
button really is gone, reload the screen and report it.

### *"MxChat shows N selected conversations, but this export will contain M."*

The module's copy of the selection and MxChat's own counter disagree, which should not
happen in normal use. Cancel, reload the screen, tick the conversations again and export.

### *"MxChat shows N selected conversations, but this export lost track of them."*

The same disagreement, with nothing at all on the module's side: the button would otherwise
have offered the date-range dialog and claimed that nothing was selected. Reload the screen
and tick the conversations again, or click **OK** to export a date range instead.

### "Export this conversation (CSV)" is greyed out

No conversation is loaded in the panel — it is still loading, its load failed, it was just
deleted, or you went back to the list on a phone. Open the conversation again.

### "Select at least one conversation, or provide a valid start and an end date."

The request arrived with no session ids and no usable date pair — normally an invalid date
that the browser let through. Re-open the dialog and pick the dates with the date fields.

### "No messages found…" / "No conversations found in that date range."

The query ran and matched nothing. For a selection, the conversations have no rows left in
`wp_mxchat_chat_transcripts` (deleted since the list was rendered). For a range, no message
carries a timestamp between the two bounds — remember that the bounds are the site's days,
converted to UTC.

### The times in the file are off by a few hours

The `Timestamp (UTC)` column is UTC, as MxChat stores it; the panel shows the site's time.
The difference is the site's UTC offset. MxChat's own export writes the same UTC values.

### Accents look wrong in Excel

The file carries a UTF-8 BOM precisely to prevent this. If you see mojibake, the file has
most likely been through an intermediate tool that stripped the BOM — open the original
download, or import it explicitly as UTF-8.

### Nothing downloads and a blank tab stays open

The export opens in a new tab; a pop-up blocker or a 403/404 body will leave that tab
showing an error message rather than saving a file. Read the message in the tab — it names
the actual cause.
