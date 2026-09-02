# apispi.com (Spi) — Recreation Specification

This document specifies the application precisely enough to rebuild it from
scratch. It describes intent, data model, API surface, behaviour, and the
non-obvious decisions. Where a rule matters (security, validation), it is
stated explicitly.

> **Companion documents** (deep-dives on each area):
> - [ARCHITECTURE.md](ARCHITECTURE.md) — request lifecycle, auth model, routing, deployment
> - [MODELS.md](MODELS.md) — Eloquent models, casts, relations, business rules
> - [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) — every table, column, index, migration
> - [FRONTEND.md](FRONTEND.md) — Vue SPA: router, store, views, components
> - [CATALOG.md](CATALOG.md) — Catalog/Active sections and connector sync
>
> This file (SPECS.md) is the canonical top-level spec; the companions expand
> specific areas. If they ever disagree, treat SPECS.md as authoritative and
> fix the companion.

---

## 1. Product overview

**Spi** is a browser-based, multi-protocol **API testing and monitoring
platform** with a distinctive focus on the agent/MCP ecosystem, plus an admin
back-office. It began as a request tester and grew into a testing platform.

Signed-in users, working in a **shared per-organisation workspace**:
- compose and send requests across **REST, GraphQL, WebSocket, SOAP, Webhook,
  MCP, A2A, gRPC, MQTT, AMQP**, inspect responses, save and replay them;
- define **environments** (`{{variable}}` substitution with secret masking),
  **assertions**, and **contracts** (response schemas inferred from traffic);
- group saved requests into **collections** run in order with value passing,
  from the UI or `/api/v1` for CI, and diff them across environments (**parity**);
- schedule **monitors** (collection runs or **MCP drift** watch), alert via
  Slack/Discord/webhook, and publish public **status pages**;
- capture inbound webhooks with **dead-man's-switch** silence detection;
- put Spi **in the path** of an agent as an MCP **flight recorder** (record,
  scan, synthesize the observed contract) and expose Spi itself as an **MCP
  gateway**;
- **explore** an MCP server autonomously (safe by default), and use **AI
  assist** to author/explain/assert/fix/heal requests on the user's SCX key.

Admins manage users, **organisations**, workspace-wide **monitoring**, and a
**Catalog** of agents/skills/connectors/tools/prompts synced from live MCP/A2A
servers. Public marketing homepage + an authenticated SPA. Feature specifics
with rationale live in the [root README](../README.md); §"Feature areas" below
maps each area to its endpoints, data, and invariants.

---

## 2. Tech stack

- **Backend:** Laravel 12 (PHP 8.2+). Session-cookie auth for the SPA; a
  separate token-authenticated `/api/v1` group for programmatic use.
- **Frontend:** Vue 3 (`<script setup>`) + Vue Router + Pinia, built with Vite.
  Axios for HTTP (its automatic `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie is
  relied upon — do not disable CSRF).
- **Auth extras:** `laravel/socialite` (Google OAuth).
- **DB:** MySQL in production; SQLite in-memory for tests.
- **PHP dep constraint:** `composer.json` **must pin** `config.platform.php` to
  `8.2.0`, otherwise a newer dev machine locks Symfony 8.x (requires PHP ≥8.4.1)
  and production installs break.
- **Public web root:** `public_html/` (SiteGround layout). Set in
  `bootstrap/app.php` via `$app->usePublicPath(__DIR__.'/../public_html')`.
  Vite is configured with `publicDirectory: 'public_html'`, so `npm run build`
  writes compiled assets straight to `public_html/build/` (committed to git).

JS deps: `vue`, `vue-router`, `pinia`, `axios`, `@vitejs/plugin-vue`.
Dev: `vite`, `laravel-vite-plugin`, `tailwindcss`, `@tailwindcss/vite`,
`concurrently`.

---

## 3. Data model

The core tables are described inline below. The full current set — including
`organisations`, `environments`, `collections`/`collection_steps`,
`monitors`/`monitor_results`, `alert_channels`, `status_pages`,
`webhook_endpoints`/`webhook_captures`, `mcp_proxies`/`mcp_proxy_exchanges`, and
`inspection_reports` — is enumerated in
[DATABASE-SCHEMA.md](DATABASE-SCHEMA.md). Types are the meaningful columns.

### `users`
Base Laravel columns plus:
- `is_admin` (bool, default false)
- `scx_api_key` (string, **encrypted** cast, nullable) — third-party "SCX AI" key
- `scx_model` (string, nullable)
- `preferences` (json/array, nullable)
- `api_token` (string 64, unique, nullable) — **SHA-256 hash** of the personal API key
- `api_token_last_four` (string 4, nullable), `api_token_created_at` (datetime, nullable)
- `google_id` (string, unique, nullable), `avatar` (string, nullable)
- `registration_token` (string, nullable) — **bcrypt hash** of the email-verification token
- `registration_token_expires_at` (datetime, nullable)
- `name` is **nullable** and `password` is **nullable** (OAuth-only and
  email-first accounts have neither initially).

**User model rules (critical):**
- Use plain `protected $fillable` / `protected $hidden` **arrays** — NOT PHP
  attributes like `#[Fillable]`/`#[Hidden]` (those silently do nothing in
  Laravel 12 and were a real bug).
- `$fillable`: name, email, password, is_admin, scx_api_key, google_id, avatar.
- `$hidden`: password, remember_token, **scx_api_key**, **api_token**,
  **registration_token** (all secrets must never serialize).
- Casts: password `hashed`, is_admin `bool`, scx_api_key `encrypted`,
  preferences `array`, api_token_created_at/registration_token_expires_at
  `datetime`.
- Helpers: `isAdmin()`, `generateApiKey()` (returns plaintext `spi_`+40 random,
  stores `hash('sha256', …)`, sets last_four/created_at), static
  `hashApiKey()`, static `findByApiKey()`. Relations: `savedRequests()`,
  `requestHistories()`.
- API key prefix constant `spi_`.

### `saved_requests`
`user_id`, `name`, `protocol` (default `rest`), `method`, `url` (text),
`headers` (json/array), `body` (longtext), `params` (json/array). For MCP/A2A,
`method` holds the JSON-RPC method name and `params` the arguments.

### `request_histories`
`user_id`, `protocol`, `method`, `url` (text), `params` (json/array), `body`
(longtext), `status` (smallint, nullable — null = failed), `time_ms` (uint).
Index `(user_id, created_at)`. **Never stores request headers** (they carry
credentials). Model constant `RETENTION_PER_USER = 200`; static
`record($userId, $attrs)` inserts then trims older rows beyond the cap.

### `admin_actions` (audit log)
`admin_id` (FK users), `action` (e.g. promote_admin/demote_admin/delete_user),
`target_user_id` (nullable, **not** a FK — survives target deletion),
`target_email` (snapshot), `details` (json/array, nullable).

### `catalog_items`
`type` (agent|skill|connector|tool|prompt), `name`, `slug`, `description`,
`version`, `provider`, `metadata` (json/array), `is_active` (bool, default
false). Unique `(type, slug)`, index `(type, is_active)`. Model constant
`TYPES`; scopes `ofType($t)`, `active()`. One table backs all five entity types
and both the "Catalog" (all) and "Active" (`is_active=true`) admin sections;
activation is **workspace-wide**, not per-user.

---

## 4. Backend services

### `App\Services\Mcp\McpClient`
Minimal **MCP (Model Context Protocol)** client over **Streamable HTTP**.
- Constructor `(endpoint, ?bearerToken=null, extraHeaders=[])`.
- `initialize()` (handshake `initialize` with protocolVersion `2025-06-18`,
  then sends `notifications/initialized`), `listTools()`, `callTool()`,
  `listResources()`, `readResource()`, `listPrompts()`, `getPrompt()`, `ping()`,
  generic `request(method, params)` / `notify()`.
- Handles both plain-JSON and `text/event-stream` (SSE) responses (parse
  `data:` lines, match by JSON-RPC id). Tracks `Mcp-Session-Id` header across
  calls. Throws on JSON-RPC `error`. **Sets `allow_redirects => false`** (SSRF).

### `App\Services\A2a\A2aClient`
Minimal **A2A (Agent-to-Agent)** JSON-RPC client.
- Constructor `(endpoint, ?bearerToken=null, extraHeaders=[])`.
- `getAgentCard()` (tries `/.well-known/agent-card.json`, falls back to
  `/.well-known/agent.json`), `sendMessage()`, `getTask()`, `cancelTask()`,
  generic `request(method, params)`. Throws on JSON-RPC error / non-JSON.
  **`allow_redirects => false`**.

### `App\Rules\PubliclyRoutableUrl` (SSRF guard)
Validation rule used on every user-supplied outbound URL. Rejects:
- non-http(s) schemes; missing host;
- literal loopback/private/reserved IPs (via `FILTER_FLAG_NO_PRIV_RANGE |
  FILTER_FLAG_NO_RES_RANGE`), incl. IPv6 `[::1]`;
- `localhost`, `metadata.google.internal`, and `*.local` hostnames;
- **hostnames that resolve** (DNS A/AAAA) to any non-public IP.
- DNS resolution is gated by `config('security.ssrf_resolve_dns')` (env
  `SSRF_RESOLVE_DNS`, default true; set **false in tests** via phpunit.xml so
  faked hosts don't need to resolve). Resolver is injectable for unit tests.
- Known gap (documented, not fixed): DNS-rebinding needs connection-time IP
  pinning.

### `App\Mail\RegistrationVerificationMail`
Markdown mailable (`emails.registration-verification`) with a `setupUrl`.

### Middleware
- `IsAdmin` (alias `admin`): 403 unless `user()->isAdmin()`.
- `AuthenticateApiToken` (alias `auth.apitoken`): reads Bearer token, looks up
  `User::findByApiKey()`, `Auth::setUser()`, else 401. Used only by `/api/v1`.

### Rate limiters (`AppServiceProvider::boot`)
- `proxy`: guests 20/min per IP, authenticated 120/min per user.
- `outbound-test`: 60/min per user (MCP/A2A test + `/api/v1`).
- `auth-attempts`: 10/min per IP (login/register/OAuth).

---

## 5. Routing & bootstrap

`bootstrap/app.php`:
- `web: routes/web.php`, `api: routes/api.php`, `apiPrefix: 'api/v1'`,
  `health: '/up'`, public path `public_html`.
- Middleware aliases `admin` and `auth.apitoken`. **No CSRF exemption** for
  `api/*` (removed deliberately; the SPA sends the XSRF token).

`routes/web.php` — SPA catch-all is
`Route::get('/{any}', fn() => view('welcome'))->where('any','^(?!api\/|auth\/).*$')`
(excludes both `api/` and `auth/`). OAuth routes are declared before it.

`routes/api.php` — `/api/v1` group, middleware `['auth.apitoken',
'throttle:outbound-test']`: `proxy`, `mcp/test`, `a2a/test`.

---

## 6. HTTP API (session-authenticated `/api`, unless noted)

> The endpoints below are the original core. The **full current route surface**
> is grouped by feature in §7a and is authoritative via `php artisan route:list`
> and `routes/web.php` / `routes/api.php`. Public token routes (`/hook/{token}`,
> `/mcp-proxy/{token}`, `/status/{token}`, `/r/{token}`) are unauthenticated by
> design — the token is the credential.


**Public / guest:**
- `POST /api/proxy` — REST proxy (guest-allowed, `throttle:proxy`).
- `POST /api/login`, `POST /api/register`, `POST /api/register/start`,
  `POST /api/register/complete` (all `throttle:auth-attempts`).
- `GET /auth/google/redirect`, `GET /auth/google/callback`.

**Auth required:**
- `POST /api/logout`, `GET /api/user`.
- `GET/POST /api/saved-requests`, `DELETE /api/saved-requests/{id}`.
- `GET/DELETE /api/history`.
- `GET /api/tools/active`.
- `POST /api/mcp/test`, `POST /api/a2a/test` (`throttle:outbound-test`).
- `POST /api/scx/chat`.
- User: `PUT /api/user/password`, `PUT /api/user/profile`,
  `GET /api/user/stats`, `GET /api/user/activity`, `DELETE /api/user/account`,
  `GET /api/user/preferences`, `PUT /api/user/preferences`,
  `GET /api/user/api-key`, `POST /api/user/api-key/regenerate`,
  `GET|PUT /api/user/scx-api-key`, `PUT /api/user/scx-model`.

**Admin required (`auth`+`admin`):**
- `GET /api/admin/users` (paginated 25, `?search=` name/email, returns
  `email_verified` + `saved_requests_count`), `GET /api/admin/stats`,
  `GET /api/admin/actions`.
- `POST /api/admin/users/{id}/toggle-admin`, `DELETE /api/admin/users/{id}`
  (both forbid self-target with 422; both write an `admin_actions` row).
- Catalog: `GET /api/admin/catalog` (`?type=&active=`),
  `GET /api/admin/catalog/counts`, `POST /api/admin/catalog`,
  `PUT/DELETE /api/admin/catalog/{item}`,
  `POST /api/admin/catalog/{item}/toggle-active`,
  `POST /api/admin/catalog/{item}/sync`.

**Programmatic (`/api/v1`, Bearer API key):** `POST /api/v1/proxy`,
`/api/v1/mcp/test`, `/api/v1/a2a/test`.

Response shape used by proxy/MCP/A2A testers (so one ResponsePanel renders all):
`{ status, headers, body, time_ms, request_payload?, request_headers? }`.

---

## 7. Key behaviours (must-replicate)

### ProxyController
Validates `url` (`+PubliclyRoutableUrl`), `method` in
GET/POST/PUT/PATCH/DELETE/OPTIONS/HEAD, `headers` array, `body` (**accepts
string OR array** — array is `json_encode`d before sending; this dual shape is
required because the homepage builds an object body and the dashboard sends a
string). Strips `host`/`content-length` headers. `withoutVerifying()` +
`allow_redirects=false`. Records history for authenticated callers (never
headers). Returns the target's status/headers/body verbatim; 500 wrapper with
`"Proxy Error: …"` on exception.

### McpTestController / A2aTestController
Validate `url(+SSRF)`, `method`, `params?`, `headers?`. Filter host/content-
length headers, pass remaining as extra headers to the client. MCP: always
`initialize()` first, then run the requested method (or return the init result
for `initialize`); exposes `Mcp-Session-Id`, protocol version, server name in
returned `headers`. Both record history. Errors → 500 `"MCP/A2A Error: …"`.

### Auth
- `login`: `Auth::attempt` then `session()->regenerate()`; 401 on failure.
- `register` (legacy name/email/password): creates user, logs in,
  **regenerates session**.
- `logout`: logout + invalidate + regenerate token.
- `changePassword`: requires correct `current_password` (else 422), sets new,
  regenerates session.

### Email-first registration (RegistrationController)
- `start({email})`: **enumeration-safe** — identical generic response whether
  or not the email exists. Only when no *verified* account exists: create/refresh
  an unverified user, store `Hash::make(token)` + 60-min expiry, email a link
  `{APP_URL}/complete-registration?email=&token=`. No resend for verified accounts.
- `complete({email, token, name, password+confirmation})`: valid iff user exists,
  is unverified, token not expired, `Hash::check` matches. Sets name/password,
  `email_verified_at=now`, clears token, logs in, 201. Invalid/expired → 422.

### Google OAuth (GoogleAuthController, Socialite `google`)
- `redirect`: if no `services.google.client_id`, redirect
  `/login?error=google_unavailable`; else Socialite redirect.
- `callback`: on Socialite throw, **log the exception class+message** and
  redirect `/login?error=google_failed`. No email → `?error=google_no_email`.
  Match by `google_id`, else by `email` (links Google to an existing account),
  else create passwordless user (name = Google name or email prefix, store
  avatar). `Auth::login(remember:true)` + session regenerate → `/dashboard`.
- Requires `config/services.php` `google` block (client_id/secret/redirect from
  `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI`).

### Saved requests
Free plan capped at **10** per non-admin (`FREE_PLAN_LIMIT`); admins exempt;
over cap → 422. `protocol` in rest/mcp/a2a; validates accordingly.

### Connector sync (ConnectorSyncController)
`sync(connector)`: 422 unless `type==connector`; require `metadata.endpoint`;
re-validate endpoint with `PubliclyRoutableUrl` (422 if bad); build
`Authorization` header from `metadata.auth_header` if present. MCP → import
`tools/list` as `tool` items and `prompts/list` (optional, catch) as `prompt`;
A2A → import agent-card `skills` as `skill`. Upsert by
**connector-namespaced slug** `"{connectorSlug}-{slug(name)}"`, preserving
`is_active` (idempotent re-sync). Store on each imported item's metadata:
`connector_slug`, `endpoint`, `protocol` — but **never** `auth_header`. Stamp
connector `metadata.last_synced_at`. Upstream failure → 502.

### Active tools (ToolController@active)
Any authenticated user (active items are workspace-wide). Returns active `tool`
items that have an endpoint, exposing only `{id,name,description,provider,
endpoint,protocol,input_schema}` — never the connector auth header.

### AdminController
`users` paginated + searchable; `stats` = totals + `new_users_this_week` +
`total_requests`/`requests_this_week` + `protocol_breakdown{rest,mcp,a2a}`.
`toggleAdmin`/`deleteUser` write audit rows; deleteUser records saved-request
count in details.

---

## 7a. Feature areas

Each area below lists its endpoints, storage, and the invariants that matter.
Detailed rationale is in the [root README](../README.md).

### Environments & variables
`GET/POST/PUT/DELETE /api/environments`. `ResolveEnvironmentVariables`
middleware expands `{{var}}` in tester/collection payloads **before**
validation, so the SSRF guard sees the resolved target. Secret variables are
masked (`••••••`) in history, echoed requests, and reports, and never returned
to the browser. Names unique per workspace; one default per workspace.

### Assertions
`POST /api/assertions/evaluate`, `PUT /api/saved-requests/{id}/assertions`.
`Assertion` defines a **closed operator vocabulary**; the AI generator and the
Vue panel are constrained to it (a test pins the two lists together).
`AssertionEvaluator` never throws — a bad row fails with a reason.

### Contracts (schema drift)
`POST /api/contract/infer`, `PUT/POST /api/saved-requests/{id}/contract[/check]`.
`SchemaInferrer` learns a JSON-Schema baseline from a good response;
`ContractChecker` fails a collection step on a removed-required-field or
type-change (breaking) even at HTTP 200, while additive fields pass.

### Fuzzing
`POST /api/saved-requests/{id}/fuzz` mutates a REST body into adversarial
variants (`FuzzGenerator`, targeted by field type) and classifies each response
via `FuzzRunner`: 5xx = server_error (finding), 2xx on a type/shape violation =
accepted_invalid (finding), 4xx = handled. Injection/oversized are crash-probes.
Same SSRF path as the testers; persists a `fuzz` report.

### Collections, runner, parity
`GET/POST/PUT/DELETE /api/collections`, `POST /api/collections/{id}/run`
(also `/api/v1/...` with an API key — 200 all-passed, 422 any-failed for CI),
`POST /api/collections/{id}/parity`. `CollectionRunner` threads variables
between steps (extract → later `{{name}}`); `RequestExecutor` sends each step
with the same SSRF pinning as the interactive testers, for **all** protocols.
`ParityChecker` diffs two-environment runs by shape (value diffs ignored).
Runs persist as `collection_run`/`parity` reports; secrets masked.

### Monitors & alerting
`GET/POST/PUT/DELETE /api/monitors`, `GET /api/monitors/{id}`,
`POST /api/monitors/{id}/run`. `type=collection` runs a collection;
`type=mcp_drift` snapshots `tools/list` and alerts on shape change
(alert-once, then rebaseline). Scheduled by `monitors:run` (every minute;
each monitor's own interval decides dueness, evaluated in PHP).
`AlertDispatcher` delivers to Slack/Discord/webhook channels
(`/api/alert-channels`, `.../test`) **on transition only**; the URL is a
credential (never returned in full, SSRF-checked, pinned on delivery).
Public **status pages**: `GET /api/status-pages` (owner),
`GET /api/status/{token}` (public, sparse — no URLs/steps/owner identity).

### MCP mock server (service virtualization)
`ANY /mcp-mock/{token}` serves a **fake** MCP server from a stored definition
(handshake, `tools/list`, `tools/call` → each tool's canned `response`), so an
agent can be built against a stand-in. `GET/POST/PUT/DELETE /api/mcp-mocks` +
`POST /api/mcp-mocks/from-recorder/{proxyId}` — the latter seeds a mock from a
flight recorder's observed tools (inferred input schema + a real sample
response). Token-gated; the canned `response` is never leaked in `tools/list`.

### MCP gateway (Spi as an MCP server)
`POST /api/gateway/tools` — Streamable HTTP, stateless, API-key auth. Tools are
the caller's own artefacts (`list_collections`, `run_collection`,
`get_monitor_status`, `evaluate_assertions`, `http_request`). Satisfies our own
client/conformance grader; SSRF guard applies to `http_request`.

### Flight recorder & synthesis
`ANY /mcp-proxy/{token}` relays an agent's MCP calls to `upstream_url` and
records every exchange (Authorization forwarded, never stored; upstream
re-pinned per relay; responses scanned for injection live → `flagged`).
`GET /api/mcp-proxies/{id}/exchanges|synthesize` — synthesis reverse-engineers
the observed contract (input from real arguments, output from real responses)
beside what `tools/list` declared, surfacing undeclared tools. `POST /api/mcp-proxies/{id}/replay` replays the recorded requests against a
target and diffs response shapes (`McpReplayer`, contract-engine diff, safe mode
skips destructive calls) — regression testing from real traffic. A proxy may also
carry a **firewall** `policy` (`McpPolicyEngine`): ordered block/redact rules the
relay enforces inline — block a tool, redact secret arguments before they leave,
or withhold an injection-flagged response. Enforcement is recorded per exchange;
redacted values replace secrets in storage too.

### Webhook capture (dead-man's switch)
`ANY /hook/{token}` captures inbound requests (Authorization/Cookie redacted,
64 KB cap). An endpoint may set `trigger_collection_id` to **fire a collection
run** per capture (queued via `RunTriggeredCollection`, off the request path);
top-level JSON payload fields become `{{webhook_<field>}}` variables for the run. With `expect_interval_minutes` set, `webhooks:check` marks an
endpoint **silent** when nothing arrives in time and alerts on the transition
(and on recovery). `GET/POST/PUT/DELETE /api/webhook-endpoints`, `.../captures`.

### Agent explorer
`POST /api/explore` — `ServerExplorer` (subclass of `AgentLoopRunner`) drives
an MCP server toward a goal on the caller's SCX key. **Safe mode default on**:
`DestructiveHeuristic` refuses side-effecting tools (a blocked call is a
finding, not an execution); discovered tools run through the injection scanner.
Persists an `exploration` report.

### Import / export & AI assist
`POST /api/import/curl` (preview), `/api/import/openapi` (→ saved requests +
optional collection/environment), `POST /api/export`,
`GET /api/saved-requests/{id}/export`. `POST /api/ai/{author,explain,assert,fix,
heal}` — SCX-powered, on the caller's key; `heal` proposes updated assertions
after a legitimate API change (applied for review, never auto-saved).

### Shared workspaces & admin
Every resource is scoped by **workspace** (organisation members) via
`SharedInWorkspace`; history and credentials stay personal. Admin area
(`/api/admin/*`): users (create, soft/hard delete, restore, org assignment),
`organisations`, workspace-wide `monitoring`, stats, audit `actions`, and the
Catalog/connector subsystem ([CATALOG.md](CATALOG.md)).

---

## 8. Frontend (SPA)

**Router** (`resources/js/router.js`), guards via Pinia `auth` store
(`requiresAuth`, `guestOnly`, `requiresAdmin`):
- `/` Home (guestOnly), `/login`, `/register`, `/complete-registration`
  (guestOnly), `/dashboard`, `/profile`, `/chat` (auth), `/admin`,
  `/catalog` (section=catalog), `/active` (section=active) (auth+admin).

**Views:** Home (marketing + live tester + Quick-start example cards), Login,
Register (email-only → "check your inbox"), CompleteRegistration (name+password
from `?token`), Dashboard, Profile (tabs: Account, Personalisation, API Keys,
Usage, Settings, Danger Zone), Admin (stats + users table + audit log),
CatalogSection (shared by /catalog and /active), Chat.

**Components:** RequestPanel (protocol REST/MCP/A2A selector; MCP "Discover
Tools" via live `tools/list`; "Active tools" dropdown that fills URL +
`tools/call` template from stored `input_schema`; A2A agent-card fetch),
ResponsePanel (status/time/size, JSON syntax highlight — **HTML-escape before
highlighting**), GoogleButton (shared "Continue with Google").

**App shell** (`App.vue`): left sidebar for authenticated users — Workspace
(Dashboard/Chat/Profile) and, for admins, an Admin group (Admin Panel /
Catalog / Active) + account popup.

**Dashboard:** sidebar Saved / History tabs (history click-to-replay, 200 cap,
clear). Fetches `/api/user/preferences` (default protocol/method) and
`/api/tools/active` on mount, passes both to RequestPanel.

**Profile > Personalisation:** default_protocol, default_method, timezone,
compact_history → `PUT /api/user/preferences` (validated; default protocol
applied on the dashboard tester).

**Profile > API Keys:** generate/regenerate key (show-once banner; only masked
hint afterward) + a `curl` example for `/api/v1`.

**Homepage tester** posts to `/api/proxy` for HTTP-based protocols. Protocols
offered: REST, GraphQL, WebSocket, SOAP, Webhook, MCP, A2A, gRPC, MQTT, AMQP.
The last three connect to real backends and post to the authenticated
`/api/{grpc,mqtt,amqp}/test` endpoints (logged-out visitors are prompted to
sign in). No fabricated testimonials.

---

## 9. Config & env

`config/security.php`: `ssrf_resolve_dns` (env `SSRF_RESOLVE_DNS`, default
true), `admin_password` (env `ADMIN_PASSWORD`) — read via **config**, not
`env()` directly, so a warm config cache still resolves it.

Env of note: `ADMIN_PASSWORD`, `SSRF_RESOLVE_DNS`,
`GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI` (redirect =
`{APP_URL}/auth/google/callback`), `MAIL_MAILER` (needs real SMTP for
verification emails to actually send; `log` only writes to the log).

**Seeder** (`DatabaseSeeder`): upserts `admin@apispi.com` as admin. Password =
`config('security.admin_password')`; if unset, generate a random one and print
it once. **Never ship a hardcoded default password.**

---

## 10. Deployment

`deploy.sh` — two modes in one script:
- **Local:** `./deploy.sh "msg"` — build assets, `git add -A`, commit, push,
  then SSH to the server and run the same script there. Guards: aborts with an
  actionable message if the build toolchain (`node_modules/.bin/vite`) is
  missing (means it's being run on the server with a stale copy).
- **Server** (auto-detected by path `*/www/*`): `git fetch` + `reset --hard
  origin/main`, `composer install --no-dev --optimize-autoloader`,
  `php artisan migrate --force`, optional `db:seed` (`DEPLOY_SEED=1`),
  `optimize:clear`, then verify the live URL returns HTTP 200.
- Env: `DEPLOY_SSH_HOST` (default `as`), `DEPLOY_SERVER_PATH`
  (`~/www/spi.apispi.com`), `DEPLOY_SITE_URL` (`https://spi.apispi.com/`).
- Do **not** `route:cache` — a closure catch-all route can't be serialized.

`public_html/.htaccess` sets `DirectoryIndex index.php` so a stray `index.html`
can't shadow the app (this actually happened once). There must be **no**
`public_html/index.html`.

---

## 11. Security invariants (do not regress)

Additions since v1 (full list continues below):
- **Workspace scoping** — shared resources are read/edited/deleted via
  `Model::inWorkspaceOf($user)`, **never** a bare `where('user_id', $me)` that
  would leak across or hide within an organisation. History and credentials
  stay per-user.
- **Variable resolution before validation** — `{{var}}` is expanded before the
  URL/SSRF rules run, so a variable can never smuggle an internal host past
  them.
- **Secret masking** — secret variable values are masked in history, echoed
  requests, run/parity reports, and never returned to the browser.
- **Credential-bearing URLs** (alert-channel webhooks) and **tokens**
  (webhook/proxy/status/share) are never returned in full; relayed/captured
  Authorization headers are forwarded where needed but **never stored**.
- **SSRF everywhere outbound** — proxy, MCP, A2A, gRPC (CURLOPT_RESOLVE) and the
  socket testers (MQTT/AMQP via `validatedAddress` + TLS `peer_name`), the
  flight-recorder relay, and alert delivery all validate and pin. Hosts are
  canonicalised first (trailing-dot / case bypasses closed).
- **Agent safety** — the explorer refuses destructive tools by default.
- **Email validation** uses `email:filter` on every store/mail path
  (CRLF-injection advisory).


1. CSRF is enforced on `/api/*` (session flow). Programmatic access uses the
   separate token-authed `/api/v1` — never weaken CSRF to enable API clients.
2. All outbound URLs pass `PubliclyRoutableUrl`; outbound clients don't follow
   redirects.
3. Secrets never serialize: password hash, `scx_api_key`, `api_token`,
   `registration_token` are `$hidden`; connector `auth_header` is never exposed
   to non-admins or copied onto imported items.
4. API keys and registration/verification tokens are stored **hashed**;
   plaintext shown once.
5. Rate-limit proxy (esp. guests) and all auth endpoints.
6. Enumeration-safe registration `start`.
7. Admins cannot delete/demote themselves; deleteAccount blocks admins.

---

## 12. Testing

PHPUnit, SQLite `:memory:`, `RefreshDatabase`. ~21 feature/unit test files
covering: MCP/A2A clients, proxy (incl. SSRF + redirect bypass + body shapes),
rate limiting, auth (hash/key never leak), saved-request cap, request history
(headers never stored, retention trim), admin (auth gating, self-protection,
audit), catalog CRUD, connector sync (MCP/A2A/idempotency/auth-not-leaked),
active tools, API keys (`/api/v1` stateless), Google OAuth (create/link/no-
email/unconfigured), email registration (start/complete/expiry/enumeration).
Set `SSRF_RESOLVE_DNS=false` in phpunit.xml.

Note: local dev may run PHP 8.5 and emit `PDO::MYSQL_ATTR_SSL_CA` deprecation
noise — harmless; suppress with `-d error_reporting=0` when scripting.
