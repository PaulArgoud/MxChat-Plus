# Transcripts CSV export — operator guide

The transcripts module adds **one button** to MxChat's own transcripts screen
(**admin.php?page=mxchat-transcripts**), right after the host's **Delete Selected**
control. It exports conversations to CSV — either the ones you ticked, or every
conversation inside a date range.

It adds nothing else: no settings tab, no cron, no REST route, no CLI command. Enabling
the module is the whole configuration.

---

## Why it exists

MxChat already ships an export, `wp_ajax_mxchat_export_transcripts`. That one dumps the
**entire** `wp_mxchat_chat_transcripts` table unconditionally — the checkboxes on the
screen have no effect on it, and there is no date filter. On an install with months of
traffic, "give me the five conversations from last Tuesday" means exporting everything and
filtering by hand.

This module exports **exactly what you asked for**, under its own AJAX action
(`mxchat_plus_export_transcripts`) and its own nonce, so it never collides with the host's.

---

## Enabling it

**MxChat Plus → Modules → *CSV export of selected transcripts*.** On by default.

The module only registers its hooks in the admin (`is_admin()`), and its script is only
enqueued when the `page` query argument is exactly `mxchat-transcripts` **and** the current
user can `manage_options`.

---

## The two export modes

Both start the same way: open **admin.php?page=mxchat-transcripts** and click the download
icon next to **Delete Selected**. What happens next depends on whether anything is ticked.

### Mode 1 — export the selection

Tick one or more conversations, then click the button. Those conversations are exported
immediately; no dialog appears.

Unlike the host's **Delete Selected**, this button stays clickable with an empty selection
— that is deliberate, because an empty selection is the entry point to mode 2.

### Mode 2 — export a date range

Click the button with **nothing** ticked and a modal opens (styled with the host's own
`.mxch-modal-overlay` / `.mxch-modal-content` classes, so it looks like MxChat's other
dialogs). It asks for a **Start date** and an **End date**, pre-filled with **the last 30
days, ending today** — computed from the browser's *local* date, so it does not slip a day
for anyone far from Greenwich.

Both bounds are **inclusive**: the range runs from `00:00:00` on the start day to
`23:59:59` on the end day. Picking the same date twice therefore exports that whole day —
without the end-of-day stretch, a bare date would compare against midnight on a `TIMESTAMP`
column and return nothing.

The dialog closes on **Cancel**, on the **×**, on **Escape**, or on a click outside the
box. It refuses to submit when a date is missing (*"Please provide both a start and an end
date."*) or when the start is after the end (*"The start date must be before the end
date."*). Should reversed bounds reach the server anyway, it swaps them rather than
failing — the intent is unambiguous.

> There is no "export everything" mode, by design. With no selection *and* no valid date
> pair the request is rejected with a 400, so a stray click can never dump the whole table.

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

| Column | Content |
|---|---|
| `Session ID` | The conversation identifier, the same value the host's checkboxes carry. |
| `Email` | `user_email` as stored by MxChat, when the visitor gave one. |
| `User identifier` | `user_identifier` as stored by MxChat. |
| `Role` | Who wrote the message — MxChat's own `role` value (user / assistant). |
| `Message` | The message body, after formula neutralisation (see below). |
| `Timestamp` | The row's `timestamp`, verbatim from the database. |

Rows are ordered by **session, then timestamp ascending**, so each conversation reads top
to bottom as it happened.

The column set and the BOM deliberately mirror the host's own export, so both files open
identically in Excel.

---

## Security

| Guard | What it does |
|---|---|
| **Capability** | `manage_options`, checked both when enqueuing the script and again in the export handler. A user without it gets a 403. |
| **Nonce** | A dedicated `mxchat_plus_export_transcripts` nonce, verified with `check_admin_referer()`. It is this module's own — the host's nonces are never reused. |
| **Prepared SQL** | Session ids come from the browser, so every value is bound. The `IN (…)` placeholder list is built from the **count** of ids, never from their content. |
| **Date validation** | Strict `YYYY-MM-DD` plus `checkdate()`, so `2026-02-30` is rejected as firmly as `2026-01-01' OR '1'='1`. |
| **Export ceiling** | At most **2000** conversations per export (see below). |
| **CSV injection** | Neutralised — see below. |

### CSV injection

Chat messages are written by site visitors. A spreadsheet treats a cell starting with
`=`, `+`, `-` or `@` (and `\t` or `\r`) as a **formula**, so a visitor could type
`=HYPERLINK("http://evil.test","click")` or `=cmd|' /C calc'!A0` into the chat widget and
have it execute on an admin's machine when the export is opened.

The `Message` column is therefore prefixed with a tab character whenever it begins with one
of those. The text stays readable; it is simply no longer the first thing the spreadsheet
parses. Ordinary messages — including `a=b` and `1+1`, where the character is not in first
position — are written untouched.

---

## Limits

- **2000 conversations per export.** Beyond that the list is truncated to its first 2000
  entries rather than rejected, so a "select all" on a large install still produces a
  usable file. The truncation is silent: if you selected more, split the work into several
  exports (or use the date-range mode over narrower periods).
- **The cap counts conversations, not messages.** A range export is bounded by its dates,
  not by this ceiling — a wide range on a busy install can produce a very large file.
- **Timestamps are compared as stored.** The bounds are plain strings matched against
  MxChat's `timestamp` column; no timezone conversion happens on the way in.
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
   the host's transcripts screen, not the chat settings and not the leads screen.
3. **Insufficient rights.** Without `manage_options` (editors, shop managers, most custom
   roles) the script is never enqueued at all.
4. **The host's toolbar changed.** The button is injected next to the element with id
   `mxch-delete-selected`. If a future MxChat release renames it, there is nothing to
   anchor to — the console will be silent, because the injection simply does nothing.

### The button disappears when I change page or search

It should not: the host re-renders its whole list (and its toolbar) on paging, sorting and
searching, and a `MutationObserver` re-injects the button after every rebuild. If it really
is gone, reload the screen and report it — that observer is the fix for exactly this.

### "Select at least one conversation, or provide a valid start and an end date."

The request arrived with no session ids and no usable date pair — normally an invalid date
that the browser let through. Re-open the dialog and pick the dates with the date fields.

### "No messages found…" / "No conversations found in that date range."

The query ran and matched nothing. For a selection, the conversations have no rows left in
`wp_mxchat_chat_transcripts` (deleted since the list was rendered). For a range, no message
carries a timestamp between the two bounds.

### Accents look wrong in Excel

The file carries a UTF-8 BOM precisely to prevent this. If you see mojibake, the file has
most likely been through an intermediate tool that stripped the BOM — open the original
download, or import it explicitly as UTF-8.

### Nothing downloads and a blank tab stays open

The export opens in a new tab; a pop-up blocker or a 403/404 body will leave that tab
showing an error message rather than saving a file. Read the message in the tab — it names
the actual cause.
