# apispi.com

A multi-protocol API testing tool built with Laravel 11 and Vue 3. Send and
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

- **Backend:** Laravel 11 (PHP 8.2+), session-cookie auth
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

The authenticated dashboard tests three protocols; the public homepage offers a
demo of REST/GraphQL/WebSocket/SOAP/Webhook/MCP/A2A (gRPC, MQTT, and AMQP are
marked "coming soon" — not yet implemented server-side).

- **MCP** — `App\Services\Mcp\McpClient`, Streamable HTTP transport (JSON + SSE),
  session handling, and a "Discover Tools" flow that reads `tools/list` and
  auto-fills a `tools/call` template from each tool's `inputSchema`.
- **A2A** — `App\Services\A2a\A2aClient`, JSON-RPC with agent-card discovery
  (`.well-known/agent-card.json`) and a `message/send` template filler.

A CLI client is also available: `php artisan mcp:test <url>` (interactive REPL
or `--method=` for one-shot calls).

## Key API endpoints

All under `/api`, session-authenticated unless noted. State-changing routes
require the CSRF token (axios sends it automatically from the `XSRF-TOKEN`
cookie).

- `POST /proxy` — REST proxy (open to guests; rate-limited)
- `POST /mcp/test`, `POST /a2a/test` — protocol testers (auth)
- `GET/POST/DELETE /saved-requests` — saved requests (free plan capped at 10)
- `GET/DELETE /history` — request history (200/user retention)
- `PUT /user/profile`, `/user/password`, `GET /user/stats`, `/user/activity`,
  `DELETE /user/account` — profile management
- `GET /admin/{users,stats,actions}`, user promote/delete — admin only

## Security notes

- **SSRF:** `App\Rules\PubliclyRoutableUrl` blocks loopback/private/reserved
  hosts on all outbound endpoints, resolving DNS to catch hostnames pointing
  inward. Outbound clients do **not** follow redirects, to prevent a validated
  URL from bouncing to an internal address. Not yet covered: DNS rebinding
  (would need connection-time IP pinning).
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
