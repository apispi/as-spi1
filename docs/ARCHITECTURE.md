# Architecture

High-level shape of apispi.com (Spi). See [SPECS.md](SPECS.md) for the full
specification and the other companion docs for detail.

---

## 1. Shape

Spi is a **Laravel 11 backend** serving a **Vue 3 single-page app**, plus a
public marketing homepage. One Laravel app serves both:

- **Public homepage** (`/`) — marketing + a live API tester that posts to
  `/api/proxy` (works for guests, rate-limited).
- **Authenticated SPA** — dashboard tester, saved requests, history, profile,
  and an admin back-office (users, audit log, catalog).

```
Browser ── HTTPS ──> SiteGround (Apache, public_html/) ──> index.php
                                                            │
                              Vite-built SPA (Vue) <────────┤ view('welcome')
                                                            │
                              /api/*  (session auth) ───────┤ routes/web.php
                              /auth/google/* (OAuth) ───────┤
                              /api/v1/* (API-key auth) ─────┘ routes/api.php
```

## 2. Tech stack

- Backend: **Laravel 11**, PHP **8.2+** (platform pinned to 8.2.0 in composer).
- Frontend: **Vue 3** (`<script setup>`), **Vue Router**, **Pinia**, **Axios**,
  built by **Vite** (`laravel-vite-plugin`, `publicDirectory: 'public_html'`).
- Auth extras: **laravel/socialite** (Google).
- DB: **MySQL** in prod, **SQLite** in-memory for tests.
- Web root: `public_html/` (set in `bootstrap/app.php`). `npm run build` emits
  to `public_html/build/`, committed to git.

## 3. Request lifecycle & routing

`bootstrap/app.php` wires:
- `web: routes/web.php`, `api: routes/api.php` with `apiPrefix: 'api/v1'`,
  `health: '/up'`, public path `public_html`.
- Middleware aliases: `admin` → `IsAdmin`, `auth.apitoken` →
  `AuthenticateApiToken`.
- **No CSRF exemption** for `api/*`.

`routes/web.php`:
- OAuth GET routes declared first.
- SPA catch-all last: `Route::get('/{any}', fn() => view('welcome'))
  ->where('any', '^(?!api\/|auth\/).*$')` — everything that isn't `api/` or
  `auth/` returns the SPA shell, and Vue Router takes over client-side.

## 4. Authentication model (two distinct paths)

1. **Session-cookie auth (the SPA).** Login/register/Google establish a Laravel
   session. All `/api/*` routes use it. CSRF is enforced; Axios automatically
   sends `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie. **Never disable CSRF to
   make an API client work.**
2. **Personal API key (programmatic).** A separate **stateless** `/api/v1`
   group authenticated by `Authorization: Bearer spi_…`. `AuthenticateApiToken`
   resolves the user by SHA-256 hash; no session, no CSRF (nothing to protect —
   there's no ambient cookie credential). This is why programmatic access lives
   apart from the SPA routes.

Third auth surface: **Google OAuth** (`/auth/google/redirect|callback`) via
Socialite, which links to an existing account by `google_id` or email, or
creates a passwordless one.

## 5. Middleware & rate limiting

- `IsAdmin` (alias `admin`): 403 unless `user()->isAdmin()`.
- `AuthenticateApiToken` (alias `auth.apitoken`): Bearer → `findByApiKey` →
  `Auth::setUser`, else 401.
- Rate limiters (`AppServiceProvider::boot`):
  - `proxy`: 20/min per IP (guest), 120/min per user.
  - `outbound-test`: 60/min per user (MCP/A2A + `/api/v1`).
  - `auth-attempts`: 10/min per IP (login/register/OAuth).

## 6. Outbound requests (proxy & protocol clients)

User-driven outbound HTTP goes through `ProxyController` (REST) or the
`McpClient` / `A2aClient` services (MCP/A2A). All three:
- validate the target URL with `App\Rules\PubliclyRoutableUrl` (SSRF guard),
- disable redirect-following (`allow_redirects=false`) so a validated URL can't
  bounce to an internal address,
- return a uniform `{ status, headers, body, time_ms }` shape so one
  `ResponsePanel` renders every protocol.

Authenticated outbound calls are recorded in `request_histories` (never
headers). See [MODELS.md](MODELS.md) and [CATALOG.md](CATALOG.md).

## 7. Deployment topology

- Host: **SiteGround** shared hosting, app at `~/www/spi.apispi.com`,
  web root `public_html/`.
- `deploy.sh` runs in two modes (local build+push, server release). Server mode:
  `reset --hard origin/main` → `composer install --no-dev` →
  `migrate --force` → optional seed → `optimize:clear` → verify HTTP 200.
- **Do not `route:cache`** — the closure catch-all can't be serialized.
- `.htaccess` pins `DirectoryIndex index.php`; there must be no
  `public_html/index.html`.

## 8. Security invariants

Summarised here; enforced across the code (see SPECS.md §11):
1. CSRF on `/api/*`; programmatic access via `/api/v1` token group only.
2. Every outbound URL passes the SSRF rule; no redirect following.
3. Secrets never serialize (password hash, `scx_api_key`, `api_token`,
   `registration_token`; connector `auth_header` never exposed/copied).
4. API keys & verification tokens stored **hashed**, plaintext shown once.
5. Rate-limit proxy and all auth endpoints.
6. Enumeration-safe registration.
7. Admins can't delete/demote themselves.
