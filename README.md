# apispi.com

A multi-protocol API testing tool built with Laravel 12 and Vue 3. Send and
inspect REST, MCP (Model Context Protocol), and A2A (Agent-to-Agent) requests
from the browser, save and replay them, and review request history — with an
admin panel for user management.

## Documentation

Detailed specs live in [`docs/`](docs/):

- [SPECS.md](docs/SPECS.md) — canonical top-level specification (rebuild from scratch)
- [ARCHITECTURE.md](docs/ARCHITECTURE.md) — request lifecycle, auth model, routing, deployment
- [MODELS.md](docs/MODELS.md) — Eloquent models, casts, relations, rules
- [DATABASE-SCHEMA.md](docs/DATABASE-SCHEMA.md) — tables, columns, indexes, migrations
- [FRONTEND.md](docs/FRONTEND.md) — Vue SPA: router, stores, views, components
- [CATALOG.md](docs/CATALOG.md) — Catalog/Active sections and connector sync

## Stack

- **Backend:** Laravel 12 (PHP 8.2+), session-cookie auth
- **Frontend:** Vue 3 + Vue Router + Pinia, built with Vite
- **Public web root:** `public_html/` (SiteGround layout, set in `bootstrap/app.php`)

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev        # or: npm run build
```

`composer dev` runs the server, queue, logs, and Vite together.

## Environment variables

Beyond the standard Laravel keys:

| Variable | Purpose |
| --- | --- |
| `ADMIN_PASSWORD` | Password for the seeded `admin@apispi.com` account. If unset, the seeder generates a random one and prints it **once**. |
| `SSRF_RESOLVE_DNS` | When `true` (default), the SSRF guard resolves hostnames and blocks any that map to private/reserved IPs. Set `false` only in test environments. |

## Protocols

The authenticated dashboard and the public homepage both test the full protocol
set: REST/GraphQL/WebSocket/SOAP/Webhook/MCP/A2A/gRPC/MQTT/AMQP. gRPC, MQTT, and
AMQP connect to real backends, so they run only through the authenticated test
endpoints (a logged-out homepage visitor is prompted to sign in).

- **MCP** — `App\Services\Mcp\McpClient`, Streamable HTTP transport (JSON + SSE),
  session handling, and a "Discover Tools" flow that reads `tools/list` and
  auto-fills a `tools/call` template from each tool's `inputSchema`.
- **A2A** — `App\Services\A2a\A2aClient`, JSON-RPC with agent-card discovery
  (`.well-known/agent-card.json`) and a `message/send` template filler.
- **gRPC** — `App\Services\Grpc\GrpcClient`, unary calls over HTTP/2 with a
  pure-PHP protobuf codec (`ProtobufCodec`). Request messages are described as
  explicit field lists; responses are decoded generically and the gRPC status
  trailer is surfaced. Streaming is out of scope.
- **MQTT** — `App\Services\Mqtt\MqttTester` (php-mqtt/client). Publish and/or
  subscribe with a bounded timeout and message cap.
- **AMQP** — `App\Services\Amqp\AmqpTester` (php-amqplib). Publish to an
  exchange/routing-key and/or pull messages from a queue via `basic_get`.

A CLI client is also available: `php artisan mcp:test <url>` (interactive REPL
or `--method=` for one-shot calls).

## Environments and variables

Environments hold reusable values so one saved request can run against staging
and production. Reference them as `{{name}}` anywhere in a request — URL,
headers, body, MQTT topics, gRPC metadata:

```json
{ "environment": "Staging", "url": "https://{{base_url}}/users" }
```

`App\Http\Middleware\ResolveEnvironmentVariables` substitutes them **before**
validation, so every URL rule — including the SSRF guard — sees the resolved
target and a variable cannot smuggle an internal host past those checks.
Unknown placeholders are left in place and reported back under `environment.
unresolved`. Variables marked **secret** are masked (`••••••`) in request
history and in the echoed request, and their values are never returned to the
browser.

## Assertions

A saved request can carry assertions that validate its response:

```json
{ "path": "data.items.0.id", "operator": "is_type", "expected": "number" }
```

`path` is `status`, `time_ms`, `header.<name>`, or a dot path into the JSON
body (`$.data.items[0].id` and `data.items.0.id` are equivalent). The operator
vocabulary is **closed** — see `App\Services\Assertions\Assertion` — so
anything stored is guaranteed evaluable, and the AI generator
(`POST /api/ai/assert`) is constrained to the same list. `AssertionsPanel.vue`
mirrors it, and `AssertionVocabularyTest` fails if the two drift apart.

`App\Services\Assertions\AssertionEvaluator` never throws on bad input: an
assertion that cannot be evaluated fails with a reason, so one malformed row
does not take down the suite.

- `POST /api/assertions/evaluate` — evaluate assertions against a response
- `PUT /api/saved-requests/{id}/assertions` — attach assertions to a request

## Navigation

The workspace sections are Home, Tester, Collections, AI Lab, Monitors,
Reports, and Spi. **Collections** (`/collections`) is where saved requests
live: a tab each for saved requests, collections, and request history. Opening
a saved request hands it to the tester through the requests store, so the
tester itself stays a single-purpose two-pane view with no sidebar.

The **admin area** (Admin panel, Catalog, Active) is separate: it is reached
from the profile menu, and its nav appears in the sidebar only while you are
inside it, so admin sections never sit alongside the workspace ones.

## Collections

A collection is an ordered group of saved requests, run start to finish
against one environment with each step's assertions checked:

```bash
curl -X POST https://spi.apispi.com/api/v1/collections/12/run \
  -H "Authorization: Bearer spi_your_key" \
  -d '{"environment":"Staging"}'
```

The status code is the verdict — **200** when every step passed, **422** when
any failed — so CI can gate on it without parsing the body.

`App\Services\Collections\CollectionRunner` threads variables between steps:
the pool starts as the environment's variables, and each step can `extract`
values from its response (`{"name":"token","path":"data.token"}`) that later
steps reference as `{{token}}`. Resolution happens per step, immediately
before sending, which is what makes that threading work.

`App\Services\Collections\RequestExecutor` applies the same SSRF validation
and IP pinning as the interactive testers, for every protocol — gRPC, MQTT,
and AMQP included. Their connection details (host, topic, credentials) live in
the saved request's `params` and are resolved from the environment before the
step runs, so a broker password can be a secret `{{variable}}` instead of
plaintext on the row.

Those protocols have no HTTP status, so the whole tester result becomes the
assertion body — exactly as the tester UI normalises it, so an assertion means
the same thing interactively and in a run. Assert on `grpc_status`,
`published`, `message_count`, or `messages.0.message` rather than `status`.

Runs stop at the first failure unless the collection sets
`continue_on_failure`; remaining steps are reported as skipped. Every run is
persisted as an `InspectionReport` of type `collection_run`, so it is
shareable and diffable like any other report.

## Import and export

Paste a `curl` command — including "Copy as cURL" from browser devtools — and
it fills the tester without saving anything. `App\Services\Import\CurlImporter`
tokenises the command by hand rather than per-flag regexes, because quoting is
the actual problem: JSON bodies arrive single-quoted and full of double quotes,
with line continuations throughout. It follows curl's own semantics (a body
without `-X` implies POST, `-u` becomes an `Authorization` header, repeated
`-d` flags join with `&`).

`App\Services\Import\OpenApiImporter` turns an OpenAPI 3 document (YAML or
JSON) into saved requests, optionally a collection and an environment. Server
URLs become `{{base_url}}` and path/required-query parameters become
`{{placeholders}}` rather than being baked in — so an imported collection can
be pointed at staging or production, which is the reason to import into Spi
rather than just read the spec. Assertions are derived only from what the spec
actually promises (documented status code, documented top-level type);
inventing field-level checks from a schema produces assertions that fail
against a healthy API.

Any saved request exports as a runnable snippet — cURL, JS `fetch`, Python
`requests`, or raw HTTP. `{{variables}}` are left in place: the snippet is for
a human to paste, and substituting a secret into copyable text is the opposite
of what the secret flag is for.

- `POST /api/import/curl` — parse a curl command (preview only)
- `POST /api/import/openapi` — create saved requests from a spec
- `GET /api/saved-requests/{id}/export?format=` — snippet for a saved request
- `POST /api/export` — snippet for an unsaved draft

## Monitors

A monitor runs a collection on a schedule and alerts on status changes:

```bash
php artisan monitors:run           # runs every monitor whose interval elapsed
php artisan monitors:run --id=3    # run one now, ignoring its schedule
```

`App\Services\Monitors\MonitorRunner` records each run as a compact history
point (for uptime and latency) plus a full `InspectionReport`, and emails the
owner **only on a transition** — passing to failing, or back. Alerting on
every failing run is what gets monitoring muted, and a muted monitor is not a
monitor. The first run establishes a baseline and never alerts.

A run that throws is still recorded as a failing result: a monitor that goes
silent is worse than one reporting an error. Intervals are restricted to
`Monitor::INTERVALS` so a monitor cannot hammer a target.

### Alert channels

Besides email, a monitor can post to Slack, Discord, or any endpoint. Channels
belong to a user and are shared across their monitors, so a webhook URL is
entered once:

- `GET/POST/PUT/DELETE /api/alert-channels`
- `POST /api/alert-channels/{id}/test` — send a sample alert

Webhook alerts need **no mail server**, so they work with SMTP unconfigured.
Slack and Discord get a sentence; a generic webhook gets a structured
`monitor.status_changed` event. Delivery follows the same transition rule as
email — on change only, never every failing run.

The URL is a credential (anyone holding a Slack webhook URL can post to that
channel), so it is stored whole but never returned to the browser; the API
returns a `url_preview` instead. Because Spi POSTs to it server-side, the URL
is SSRF-validated on write and the address is pinned again at delivery.
Delivery never throws: a broken channel records its error and the run stands.

### Server setup (required)

The scheduler is registered in [`routes/console.php`](routes/console.php), but
Laravel's scheduler needs one cron entry on the server. In SiteGround's cPanel
→ Cron Jobs, add:

```
* * * * * cd ~/www/spi.apispi.com && php artisan schedule:run >> /dev/null 2>&1
```

Alerts also need real mail credentials in the production `.env`
(`MAIL_MAILER=smtp` and the rest). With `MAIL_MAILER=log` they are written to
the log file instead of sent.

## Spi (AI assistant)

`Spi` is the in-app assistant at `/chat`, backed by
`App\Http\Controllers\ScxChatController`. It runs on the user's own SCX API key
(added in Profile) and carries a product-aware system prompt so it can point
users at real features and pages rather than inventing them.

## Key API endpoints

All under `/api`, session-authenticated unless noted. State-changing routes
require the CSRF token (axios sends it automatically from the `XSRF-TOKEN`
cookie).

- `POST /proxy` — REST proxy (open to guests; rate-limited)
- `POST /mcp/test`, `POST /a2a/test` — MCP/A2A testers (auth)
- `POST /grpc/test`, `POST /mqtt/test`, `POST /amqp/test` — gRPC/MQTT/AMQP
  testers (auth; also under `/api/v1` with a personal API key)
- `GET/POST/PUT/DELETE /environments` — environments and their variables (auth)
- `GET/POST/DELETE /saved-requests` — saved requests (free plan capped at 10)
- `POST /assertions/evaluate`, `PUT /saved-requests/{id}/assertions` — response
  assertions (auth)
- `GET/POST/PUT/DELETE /collections`, `POST /collections/{id}/run` — collections
  and runs (auth; run also under `/api/v1` with a personal API key)
- `GET/POST/PUT/DELETE /monitors`, `GET /monitors/{id}`, `POST /monitors/{id}/run`
  — scheduled monitors and their history (auth)
- `GET/POST/PUT/DELETE /alert-channels`, `POST /alert-channels/{id}/test` —
  Slack/Discord/webhook alert destinations (auth)
- `GET/DELETE /history` — request history (200/user retention)
- `PUT /user/profile`, `/user/password`, `GET /user/stats`, `/user/activity`,
  `DELETE /user/account` — profile management
- `GET /admin/{users,stats,actions}`, user promote/delete — admin only

## Security notes

- **SSRF:** `App\Rules\PubliclyRoutableUrl` blocks loopback/private/reserved
  hosts on all outbound endpoints, resolving DNS to catch hostnames pointing
  inward. Outbound clients do **not** follow redirects, to prevent a validated
  URL from bouncing to an internal address. DNS rebinding (a host that resolves
  public at validation and private at connection time) is closed for http(s)
  traffic by `App\Services\Security\SsrfGuard`, which resolves the host, checks
  every address, and pins the validated IP into the connection via
  `CURLOPT_RESOLVE` so cURL cannot re-resolve — applied by the proxy, MCP, A2A,
  and gRPC clients (gRPC rides on cURL too). The socket testers pin differently
  because they cannot use `CURLOPT_RESOLVE`: `SsrfGuard::validatedAddress()`
  returns a checked IP, MQTT and AMQP connect to **that address** while
  verifying TLS against the original **hostname** via `peer_name`, so the
  certificate is still checked against the name the user asked for. php-mqtt
  builds its own stream context with no `peer_name` hook, so
  `App\Services\Mqtt\PinnedMqttClient` overrides socket creation to supply
  one.
- **Noncanonical hosts:** host checks canonicalise before comparing
  (`ChecksHostRoutability::canonicalHost`) — lowercase, strip brackets, drop
  the trailing DNS root dot. Without that, `http://127.0.0.1./` matched
  neither the blocklist nor `FILTER_VALIDATE_IP` and was allowed whenever
  `SSRF_RESOLVE_DNS=false`. `SsrfGuard` still pins `CURLOPT_RESOLVE` using the
  host **as cURL reads it** from the URL, since a canonicalised key would
  silently fail to match and let cURL resolve the name again.
- **Email validation:** endpoints that store or mail an address validate with
  `email:filter`, not the default `email` rule, which accepts a quoted local
  part containing CRLF (`"a\r\nb"@example.com`) — GHSA-5vg9-5847-vvmq.
- **Rate limiting:** guest proxy 20/min per IP (120 authed), MCP/A2A 60/min per
  user, login/register 10/min per IP.
- **Data exposure:** the user's password hash and SCX API key are hidden from
  serialization; request history never stores request headers (credentials).

### Operational TODO

If the seeder ever ran in production with the old default, rotate the
`admin@apispi.com` password — either log in and change it, or re-run
`php artisan db:seed` with `ADMIN_PASSWORD` set in the production `.env`.

## Deployment

`./deploy.sh user@host` builds assets, commits them, pushes, and runs the
server-side steps (pull, conditional `composer install`, migrate, cache clear)
over SSH. See the header of [`deploy.sh`](deploy.sh) for details. `REMOTE_PATH`
defaults to `~/www/apispi.com`.

## Tests

```bash
php artisan test
```

Covers the MCP/A2A clients, all outbound endpoints (including SSRF and rate
limiting), auth flows, saved-request cap, request history, admin panel, and the
user profile endpoints.
