# Inbound channels — email-to-ticket (Step 25)

**Goal:** a customer emails the support mailbox → a ticket is created. When the customer replies to that
conversation, the reply is added to **the same ticket**, never a duplicate.

**Key decision:** inbound messages reuse the existing `TicketService::create()` and `ReplyService::addReply()`.
There is no separate "email ticket" system. SLA, ticket numbering, activity, auto-assign, notifications and Reverb
all work exactly as they do for a web ticket, because it _is_ a web ticket created through the same path.

The intake core is **channel-agnostic**: email is the first adapter. Web chat, WhatsApp and the AI agent hand-off
(Step 24) can later become adapters that produce the same DTO. Only the email adapter is built now (CLAUDE.md: no
future-proofing beyond what's needed). The core is the part that would be expensive to retrofit.

> Status 2026-10-20: 25a ✅, 25b ✅ (verified against the real Gmail mailbox), 25c (outbound thread headers + loop
> prevention) built, awaiting verification.

---

## 1. Flow

```
Email (IMAP poll) ─┐
Web chat (later) ──┤   adapter: parse → InboundMessageData (readonly DTO)
WhatsApp (later) ──┤
AI hand-off (later)┘
                   ▼
        InboundMessageService::receive()          ← one DB::transaction
          1. dedupe   insertOrIgnore inbound_messages (UNIQUE channel + external_message_id) → 0 rows = already done
          2. who      sender address → users.email → User (unknown sender → new customer, §6)
          3. thread   thread keys → ticket_channel_threads → existing ticket? (sender must own it)
          4. new   → TicketService::create()      (source = email)
             reply → ReplyService::addReply()     (public reply only — never an internal note)
          5. attachments → AttachmentService::storeContents() (same allow-list / size / MIME / sha256 rules)
          6. record this message's thread keys → ticket_channel_threads
```

Outbound: an agent's public reply already notifies the customer (`ReplyReceived`, mail channel). For email tickets
that mail carries the thread headers (§4), so the customer's "Reply" in their mail client lands back on the ticket.
Internal notes never leave the app on any channel (CLAUDE.md rule 6 — tested).

## 2. Layers

| Layer         | Class                                                                                                                                                                                                                                          | Notes                                                                     |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| Enum          | `InboundChannel` (`email`), `TicketSource` (`web, email, chat, api, import`), `MailboxSyncStatus`                                                                                                                                              |                                                                           |
| DTO           | `InboundMessageData`, `InboundAttachmentData`, `MailboxSettingsData`                                                                                                                                                                           | `MailboxSettingsData::password` is `?string` — null = keep the stored one |
| Contract      | `Contracts\Inbound\MailboxClient`: `testConnection()`, `fetch(connection, ?afterUid, limit)` → `MailboxFetchResult` (UIDVALIDITY, highest UID, raw messages by UID; read with PEEK, nothing marked read or deleted) | |
| Support       | `WebklexMailboxClient` (webklex/php-imap ^6.2), `FakeMailboxClient` (tests), `EmailMessageParser` (raw RFC 822 → DTO via `Message::fromString`: Message-ID, In-Reply-To + References newest first, `[TKT-…]` hint, plain text with "On … wrote:" history cut, attachments; missing Message-ID → `generated-` + sha256 of the headers) | tests never touch Gmail |
| Service (25a) | `AttachmentService::storeContents()` — bytes instead of an `UploadedFile`; extension **and** content-sniffed type must be on the allow-list (like the form's `mimes:` rule)                                                                    | shares the store/compensating-delete path with `store()`                  |
| Repository    | `InboundMessageRepository` (25a), `MailboxRepository` (settings row, sync rows, atomic counter `+1`) | no business rules; settings read once per sync, so not cached |
| Service       | `InboundMessageService` (channel-agnostic core), `MailboxSettingsService` (save / test connection), `MailboxSyncService` (fetch → dispatch jobs, sync history)                                                                                 | only services open transactions                                           |
| Job           | `SyncMailboxJob` (queue `mail`, unique, tries 1 — the next scheduled sync is the retry), `ProcessInboundEmailJob` (queue `mail`, tries 3, reads its spooled `.eml` from the private disk, deletes it after success; idempotent via the UNIQUE key) | `failed()` counts on the sync row, keeps the file for `queue:retry` |
| Command       | `mailbox:sync` — every minute, `withoutOverlapping()->onOneServer()`; skips unless enabled **and** `fetch_interval_minutes` have passed since the last sync                                                                                    |                                                                           |
| Controller    | `Admin\MailboxController` (edit, update, test, sync) | `permission:mailbox.manage` + `MailboxSettingPolicy`; test/sync `throttle:10,1` |
| React         | `pages/admin/mailbox/index.tsx` (settings `FormCard` + sync history table)                                                                                                                                                                     | `components/form/*` only                                                  |

## 3. Database

| Table                    | Columns (main)                                                                                                                                                                                                                                                      | Constraints / indexes                                                           |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `mailbox_settings`       | single row: `is_enabled`, `host`, `port`, `encryption` (`MailboxEncryption`: ssl/tls/none), `username`, `password` (**`encrypted` cast**, APP_KEY; null = `IMAP_PASSWORD`), `folder`, `fetch_interval_minutes`, `batch_size`, `last_uid` + `uid_validity` (sync cursor), `last_synced_at` | one row |
| `mailbox_syncs`          | `triggered_by` (FK users, null = scheduler), `status` (`MailboxSyncStatus`: running/completed/baseline/failed), `fetched/created/replied/skipped/duplicate/failed_count`, `error` (safe message only), `finished_at`, timestamps | `INDEX(created_at)`; pruned after 30 days (`model:prune`) |
| `inbound_messages`       | `channel`, `external_message_id`, `sender_address`, `subject`, `status` (`InboundMessageStatus`: ticket_created / reply_added / skipped), `skip_reason`, `ticket_id` (FK, nullable), `ticket_reply_id` (FK, nullable), `rejected_attachments` (json), `received_at` | **`UNIQUE(channel, external_message_id)`**, `INDEX(created_at)`                 |
| `ticket_channel_threads` | `channel`, `thread_key` (an email Message-ID we sent or received), `ticket_id` (FK cascade)                                                                                                                                                                         | **`UNIQUE(channel, thread_key)`**, `INDEX(ticket_id)`                           |
| `tickets.source`         | varchar(16), default `web` (`TicketSource`: web / email / chat / import)                                                                                                                                                                                            | one `ALTER` (tickets has FULLTEXT → table copy; DATABASE.md note); no index yet |

Not built (yet): a `customer_contacts` table mapping a phone number to a user. Email needs only `users.email`;
it's added with the first channel that identifies people by something other than an email address.

Message bodies are stored as the ticket description / reply body, as today. The raw `.eml` file is **not** kept.

### Sync cursor (25b)

* **The first sync imports nothing.** It records the folder's highest UID and UIDVALIDITY as the starting point
  (`MailboxSyncStatus::Baseline`), so enabling the integration never turns a mailbox's history into tickets. Same
  when UIDVALIDITY changes (the server renumbered the folder) or an admin points the settings at another
  host/port/username/folder (the cursor is reset on save).
* Each later sync: `UID {last_uid+1}:*`, at most `batch_size`, oldest first. Raw messages are spooled to
  `storage/app/private/inbound/{uuid}.eml`, then **one transaction** moves `last_uid` and dispatches one
  `ProcessInboundEmailJob` per file (`afterCommit`). If that transaction fails the files are deleted and the cursor
  is unchanged, so the same messages are fetched again.
* Messages are read with IMAP PEEK; nothing in the mailbox is marked read, moved or deleted.
* Failures (`MailboxConnectionException`) store a fixed, safe message on the sync row and still move
  `last_synced_at`, so a broken mailbox is retried at the configured interval, not every minute.

## 4. Threading rules (never subject alone)

Order of matching for an incoming email:

1. `In-Reply-To`, then each `References` id → `ticket_channel_threads`.
2. A `[TKT-YYYY-NNNNNN]` token in the subject — **only if** the ticket exists **and** the sender is that ticket's
   customer (a stranger quoting a ticket number gets a new ticket of their own, not access to someone else's).
3. Otherwise → new ticket.

**Outbound (25c, `TicketEmailThreadService`)** — every ticket mail **to the ticket's own customer** (`TicketCreated`
confirmation, `TicketAssigned`, `ReplyReceived`, `TicketResolved`; any ticket source, so a web customer can answer by
email too) gets:
`Message-ID: <ticket.{TKT-number}.{random}@{MAIL_FROM domain}>` (`TicketMessageId`, remembered in
`ticket_channel_threads` so the customer's reply threads by header), `In-Reply-To` = the conversation's latest key,
`References` = its latest 10 keys, `[TKT-…]` subject prefix, `Reply-To` = the mailbox address (when enabled),
`Auto-Submitted: auto-generated` + `X-Auto-Response-Suppress: All`. `ReplyReceived` carries the **whole** agent
reply for the customer (an email-created customer may have no password to read it in the app); staff keep the
200-character preview. Mail to staff is never threaded. Internal notes are never mailed to customers (no
`ReplyReceived` for notes — tested).

**Loop prevention** — `inbound_messages.status = skipped` with `skip_reason`:

| Reason | When | Decided by |
|---|---|---|
| `own_mail` | sender is `MAIL_FROM_ADDRESS` or the mailbox username, or the Message-ID is one of ours | `EmailMessageParser` |
| `bounce` | sender `mailer-daemon@` / `postmaster@` | parser |
| `auto_reply` | `Auto-Submitted` other than `no` (RFC 3834), `X-Autoreply`, `X-Autorespond` | parser |
| `bulk_mail` | `Precedence: bulk/junk/list/auto_reply`, or a `List-Id` header | parser |
| `rate_limited` | more than `support.inbound.max_per_sender_per_hour` (20) from one address in an hour — backstop for auto-responders that send no headers; checked before any account is created | `InboundMessageService` |
| `staff_sender` | the sender's account has `tickets.view-any` / `tickets.view-queue` — staff answer in the app | `InboundMessageService` |
| `invalid_sender`, `sender_inactive`, `sender_not_allowed` | 25a | `InboundMessageService` |

A reply to a **closed** ticket opens a new ticket (closed is final —
`TicketStatus::Closed->canTransitionTo()` is always false). A reply to a **resolved** ticket reopens it (existing
`ReplyService` rule).

## 5. Security

- `mailbox.manage` permission (admin by default, granted by migration `2026_10_19_180001`) on every route + `MailboxSettingPolicy`.
- The password is **write-only**: never in Inertia props, API responses, logs or the audit log (the audit row
  records `password: changed` only). Blank password field on save = keep the stored one.
- Connection errors are shown/stored as a safe message ("Authentication failed", "Could not connect to
  imap.gmail.com:993") — never the raw exception (it can contain the username/password).
- Attachments go through the same allow-list as web uploads; disallowed files are dropped and listed in the ticket
  activity, the email is still imported.
- Email bodies render as plain text (existing rule). HTML-only mails are converted to text; no HTML is stored.

## 6. Decisions

| Decision                                                                                                                                                                                                                                                                                                                                                   | Status                                        |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| Split into 25a (core, fake input) / 25b (IMAP adapter + admin page) / 25c (outbound threading + loop prevention)                                                                                                                                                                                                                                           | ✅ developer 2026-10-19                       |
| IMAP credentials editable by an admin in the app (not only `.env`)                                                                                                                                                                                                                                                                                         | ✅ developer 2026-10-19                       |
| First mailbox to test with: `shovoua1@gmail.com`. Password from `IMAP_PASSWORD` in `.env` (gitignored, developer 2026-10-19) or saved on the admin page (encrypted, wins over `.env`) — never committed or seeded                                                                                                                                                                                                                             | ✅ developer 2026-10-19                       |
| Unknown sender → a new customer account (built-in `customer` role, unverified, random password). **No email is sent to it**: the From address can be forged, so mailing a link would let anyone make us email a stranger; the person uses "Forgot password" to sign in. Recommended option (a) minus the email; developer said "go ahead" without choosing | ✅ approved with 25a, 2026-10-19 |
| A thread match (headers or `[TKT-…]`) counts only when the sender is the ticket's customer; otherwise a new ticket                                                                                                                                                                                                                                         | ✅ 25a                                        |
| Priority Medium, no category for email tickets (no triage — Step 23 skipped)                                                                                                                                                                                                                                                                               | ✅ 25a, same as the chat hand-off             |
| Refused attachments are dropped and listed in `inbound_messages.rejected_attachments`; the email is still imported                                                                                                                                                                                                                                         | ✅ 25a (customer isn't told yet — 25c)        |
| Package: `webklex/php-imap` ^6.2 (the library without the Laravel wrapper — settings come from the DB, not a config file) | ✅ developer 2026-10-19 |

## 7. Gmail setup — getting the credential (shovoua1@gmail.com)

Gmail won't accept the normal account password over IMAP. Use an **app password**:

1. Sign in to `shovoua1@gmail.com` → <https://myaccount.google.com/security>.
2. **2-Step Verification** must be **On** (app passwords don't exist without it). Turn it on if needed.
3. Open <https://myaccount.google.com/apppasswords> → app name `Support Desk` → **Create**.
4. Google shows a 16-character password (`abcd efgh ijkl mnop`). Copy it now — it is shown only once.
   Spaces don't matter; the app strips them.
5. In Gmail → ⚙ **See all settings** → **Forwarding and POP/IMAP**: check IMAP access is enabled (on current
   accounts it is always on and the toggle may be missing — that's fine).
6. In Support Desk → **Admin → Mailbox** enter:

    | Field      | Value                        |
    | ---------- | ---------------------------- |
    | Host       | `imap.gmail.com` (prefilled) |
    | Port       | `993` (prefilled)            |
    | Encryption | SSL (prefilled)              |
    | Username   | `shovoua1@gmail.com`         |
    | Password   | the app password — from `IMAP_PASSWORD` in `.env` or typed on the page, never written here |
    | Folder     | `INBOX`                      |

7. Click **Test connection** — it must say _Connected_. Only then tick **Enabled** and **Save**.
8. Click **Sync now** once (or wait for the scheduler). The history shows **Starting point set**: the first sync
   imports nothing, so mail already in the inbox never becomes tickets.
9. Send a test email from a _different_ address to `shovoua1@gmail.com`, click **Sync now** again → a ticket appears
   with source _Email_. Reply to that email from the same address → the reply lands on the same ticket.

Troubleshooting:

- _Authentication failed_ — wrong/revoked app password, or the normal password was used. Create a new app password.
- _App passwords page says "not available"_ — 2-Step Verification is off, the account uses Advanced Protection, or
  (Google Workspace) the admin has disabled app passwords. Use a personal Gmail for testing, or OAuth2 (not in v1).
- _Connects but nothing imports_ — the first sync only sets the starting point (step 8); check `schedule:work` and a
  worker on the `mail` queue are running; check **Sync history** (Skipped / Failed counts) and `inbound_messages.skip_reason`.
  Messages are fetched by UID, so ones already read in Gmail are still imported.
- For customers to receive agent replies by email, outgoing mail must be real SMTP (e.g. Gmail SMTP with the same
  app password: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`). With `MAIL_MAILER=log` the
  import still works; replies only appear in `storage/logs`.

Rotating the credential: create a new app password, paste it on the admin page, Test, Save, then revoke the old one
in Google.

## 8. Running it

```bash
php artisan queue:work --queue=sla,default,mail,notifications,broadcasts,exports,ai
php artisan schedule:work          # runs mailbox:sync every minute (honours fetch interval)
php artisan mailbox:sync --now     # one sync immediately, ignoring the interval
```

## 9. Tests (Pest, `FakeMailboxClient` — no real Gmail)

25a: new email → ticket via `TicketService` (number, SLA, activity, auto-assign job); reply by `In-Reply-To` /
`References` / `[TKT-…]` token → same ticket; stranger quoting a ticket number → new ticket; same message twice
(and job retried) → one ticket; resolved ticket reopened, closed ticket → new ticket; attachments allowed /
rejected; inbound mail never becomes an internal note.
25b: `mailbox.manage` authorization on every route; password encrypted at rest and absent from props; blank
password keeps the old one; connection failure → safe message; disabled integration → no fetch; interval honoured;
manual sync; overlapping sync prevented; sync history counts.
25c: outbound headers present and stored; own mail / auto-replies / bounces skipped; internal note never mailed.
