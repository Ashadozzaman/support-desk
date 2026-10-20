# REST API v1

A token-authenticated JSON API over the same tickets and replies the web app manages. It covers two
items from the assessment's Bonus list: **API endpoints** and **API documentation**.

* **Base URL:** `{APP_URL}/api/v1` (locally `http://localhost:8000/api/v1`)
* **Interactive docs (OpenAPI 3.1):** `{APP_URL}/docs/api` (sign in first; any active user). The raw document
  is at `/docs/api.json`. It's generated from the code by [Scramble](https://scramble.dedoc.co) (routes,
  FormRequest rules, API Resources, PHPDoc), so it can't drift from what the endpoints actually do. The
  "Try it" panel accepts a bearer token.
* **Auth:** Laravel Sanctum personal access tokens (`Authorization: Bearer <token>`).

---

## 1. Design: why it's small

The API adds **no business logic**. Each `Api\V1\*` controller is the web controller's twin:

| Layer | Web app | API | Shared? |
|---|---|---|---|
| Validation | `StoreTicketRequest`, `TicketFilterRequest`, `StoreReplyRequest`, … | same classes | ✅ |
| Authorization | `$this->authorize()` → `TicketPolicy`, `ReplyPolicy`, `AttachmentPolicy` | same calls | ✅ |
| Business rules / transactions | `TicketService`, `TicketAssignmentService`, `ReplyService` | same services | ✅ |
| List visibility | `TicketRepository::paginateFor()` (customer → own, agent → mine + queue, admin → all) | same | ✅ |
| Output | `Inertia::render` / redirect | `TicketResource`, `TicketListResource`, `ReplyResource` as JSON | Resources shared |

So a rule changed for the web app (a validation rule, a policy, a status transition) changes the API too.
The API is also proof that the Controller → Service → Repository layering works: a second delivery mechanism
needed zero service changes.

The only API-specific code is token handling: `TokenController` → `ApiTokenService` → `UserRepository`.

---

## 2. Getting a token

```bash
curl -s -X POST http://localhost:8000/api/v1/tokens \
  -H 'Accept: application/json' \
  -d email=agent1@support-desk.test -d password=password -d device_name=curl
# → 201 {"token":"3|Xq…","token_type":"Bearer"}
```

| Field | Required | Notes |
|---|---|---|
| `email`, `password` | ✅ | same credentials as the login form |
| `device_name` | ✅ | label for the token, e.g. `"CI bot"` |
| `code` | only if two-factor is on | current TOTP code from the authenticator app |

The rules match the login form, so asking for a token is never an easier way in:

* **Generic error** for an unknown email, a wrong password or a deactivated account (422 on `email`, "These
  credentials do not match our records."). The response doesn't reveal which emails exist.
* **Unverified email** → 422; verify through the web app first.
* **Two-factor** → without a valid `code`, 422 on `code`. Recovery codes aren't accepted here (sign in on the
  web to use one).
* **Rate limit:** 6 requests/minute per IP.
* **Audit:** failures fire Laravel's `Failed` event, the same one a failed web login fires (security log + audit
  log `auth.login_failed`). A successful issue writes `auth.api_token_created` and a revoke writes
  `auth.api_token_revoked`, both visible on `/admin/audit-log`.
* The plain token is returned **once**; only its SHA-256 hash is stored (`personal_access_tokens`).

**Expiry & revocation:**

* Tokens expire after `SANCTUM_TOKEN_EXPIRATION` minutes (default 43200 = 30 days). Expired rows are deleted daily
  by `sanctum:prune-expired`.
* `DELETE /api/v1/tokens/current` revokes the token you're calling with (204).
* **Deactivating a user** (`/admin/users`) makes all their tokens fail on the next request (401).
  `Sanctum::authenticateAccessTokensUsing()` in `AppServiceProvider` checks `is_active`, which is the API
  equivalent of `EnsureUserIsActive` ending web sessions.

---

## 3. Endpoints

All endpoints except `POST /tokens` need `auth:sanctum` + a verified email, and are throttled to 60
requests/minute per user (replies: 20/minute, the same as the web form). `{ticket}` is the **ticket number**
(`TKT-2026-000123`), never the numeric id.

| Method & path | Purpose | Permission / rule (policy) | Success |
|---|---|---|---|
| `POST /tokens` | Issue token | credentials | 201 |
| `DELETE /tokens/current` | Revoke current token | authenticated | 204 |
| `GET /me` | Current user, roles, permissions | authenticated | 200 |
| `GET /tickets` | List (paginated) | `viewAny`; rows scoped by `paginateFor` | 200 |
| `POST /tickets` | Create | `tickets.create` | 201 |
| `GET /tickets/{ticket}` | Show | `view` (own / assigned-or-queue / all) | 200 |
| `PUT /tickets/{ticket}` | Update subject, description, priority, category | `update` | 200 |
| `PATCH /tickets/{ticket}/status` | Change status | `changeStatus` + valid transition | 200 |
| `POST /tickets/{ticket}/claim` | Claim unassigned ticket | `tickets.claim`; atomic UPDATE | 200 |
| `POST /tickets/{ticket}/assign` | Assign/reassign (`agent_id`) | `tickets.assign` | 200 |
| `GET /tickets/{ticket}/replies` | Conversation | `view`; notes only with `notes.view` | 200 |
| `POST /tickets/{ticket}/replies` | Reply (`type=reply`) or note (`type=internal_note`) | `replies.create` / `notes.create`; files need `attachments.upload` | 201 |
| `GET /attachments/{id}/download` | Download file | `AttachmentPolicy@download` | 200 (binary) |

### List filters (`GET /tickets`)

Same query parameters as the web list: `tab` (`active`|`resolved`, default `active`), `ticket_number`,
`keyword` (FULLTEXT), `status`, `priority`, `category_id`, `assigned_agent_id`, `created_by`, `created_from`,
`created_to` (dates), `sla_state` (`within`|`approaching`|`breached`|`met`|`met_late`), `per_page` (1–50, default 15), `page`.
The response is Laravel's standard paginated resource: `data`, `links`, `meta`.

### Examples

```bash
TOKEN=3|Xq…
H=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json')

# Agent's queue: active, unassigned-or-mine, breached first page
curl -s "${H[@]}" 'http://localhost:8000/api/v1/tickets?sla_state=breached&per_page=20'

# Create
curl -s "${H[@]}" -X POST http://localhost:8000/api/v1/tickets \
  -H 'Content-Type: application/json' \
  -d '{"subject":"VPN down","description":"Since 9am","priority":"high","category_id":1}'

# Claim (409 if another agent won the race)
curl -s "${H[@]}" -X POST http://localhost:8000/api/v1/tickets/TKT-2026-000123/claim

# Status
curl -s "${H[@]}" -X PATCH http://localhost:8000/api/v1/tickets/TKT-2026-000123/status \
  -H 'Content-Type: application/json' -d '{"status":"resolved"}'

# Reply with an attachment (multipart)
curl -s "${H[@]}" -X POST http://localhost:8000/api/v1/tickets/TKT-2026-000123/replies \
  -F body='Log attached' -F 'attachments[]=@/path/to/log.txt'

# Internal note (staff only)
curl -s "${H[@]}" -X POST http://localhost:8000/api/v1/tickets/TKT-2026-000123/replies \
  -F body='Escalating to L2' -F type=internal_note
```

---

## 4. Errors

Every `/api/*` response is JSON, even when the client omits `Accept: application/json`. `ForceJsonResponse`
sets that header first; without it a failed FormRequest would answer with a redirect.

| Status | When | Body |
|---|---|---|
| 401 | missing/invalid/expired/revoked token, or user deactivated | `{"message":"Unauthenticated."}` |
| 403 | policy or permission denies (e.g. customer → another customer's ticket, customer → internal note) | `{"message":"This action is unauthorized."}` |
| 404 | unknown ticket number / attachment | `{"message":"…"}` |
| 409 | claim lost the race (`TicketAlreadyAssignedException`) | `{"message":"This ticket has already been claimed by another agent."}` |
| 422 | validation error, or invalid status transition (`InvalidStatusTransitionException`) | `{"message":"…","errors":{"field":["…"]}}` / `{"message":"…"}` |
| 429 | rate limit | `{"message":"Too Many Attempts."}` |

---

## 5. Security notes

* **Internal notes never reach customers through the API either.** `GET /replies` goes through
  `ReplyRepository::forTicket()`, which drops `internal_note` rows unless the caller has `notes.view`. Posting
  a note needs `ReplyPolicy@createNote`, and note attachments follow the note's visibility. Covered by
  `tests/Feature/Api/ApiReplySecrecyTest.php`.
* `attachments[].download_url` in API responses points at the API download route (bearer token). The web
  route only accepts a session.
* Bearer tokens only. Sanctum's cookie mode (`statefulApi()`) is **not** enabled, so `/api/*` has no session
  or CSRF surface.
* No new permissions: the API is governed by the same spatie permissions and policies as the web app, so the
  roles editor (`/admin/roles`) controls both.

---

## 6. Tests

`tests/Feature/Api/` (29 tests):

* `ApiTokenTest`: issue, generic error for unknown/wrong/inactive, unverified, 2FA required + valid code,
  JSON without an Accept header, 401 without a token, revoke, deactivated user's token rejected, expiry, rate
  limit, docs page needs login.
* `ApiTicketTest`: list scoped to visibility, filter validation, create 201, validation 422, another
  customer's ticket 403, update + status, invalid transition 422, claim, lost race 409, assign permission,
  unknown number 404.
* `ApiReplySecrecyTest`: customer never gets notes, staff see both, customer can't post a note, reply with
  attachment (API download link), note attachment download 403 for the customer.

`phpunit.xml` sets `memory_limit=512M`: the docs test makes Scramble analyse every controller, and its
analysis cache stays in the single test process.

---

## 7. Not included (on purpose)

* Token **abilities/scopes**: every token acts with its owner's full permissions. That's enough here, since
  permissions already gate each action; scopes would be the next step for third-party integrations.
* Admin endpoints (users, roles, SLA, imports), dashboard and reports. The web app covers them, and each new
  endpoint would just be another thin twin.
* A token management UI (list/revoke all tokens from the profile page).
* API versioning beyond the `/v1` prefix.
