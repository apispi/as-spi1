# Frontend

Vue 3 SPA in `resources/js`, built by Vite into `public_html/build/`. Backend
API surface in [SPECS.md](SPECS.md) §6; catalog UI in [CATALOG.md](CATALOG.md).

> **Primary source files:** `resources/js/app.js`, `resources/js/router.js`,
> `resources/js/store/{auth,requests}.js`, `resources/js/views/`,
> `resources/js/components/`, `resources/css/app.css`, `vite.config.js`.

---

## 1. Stack & build

- **Vue 3** with `<script setup>` SFCs, **Vue Router** (history mode),
  **Pinia** stores, **Axios** for HTTP.
- **Vite** via `laravel-vite-plugin`, `publicDirectory: 'public_html'`, entries
  `resources/css/app.css` + `resources/js/app.js`, `@vitejs/plugin-vue`.
- `npm run build` → hashed assets in `public_html/build/` (committed).
- `app.js` mounts the app with Pinia + Router onto `#app` (blade `welcome`).

**Axios/CSRF:** same-origin requests automatically send `X-XSRF-TOKEN` from the
`XSRF-TOKEN` cookie Laravel sets. Do not add a manual CSRF workaround.

## 2. Router (`resources/js/router.js`)

Route meta drives guards in a global `beforeEach` (using the Pinia `auth`
store): `requiresAuth`, `guestOnly`, `requiresAdmin`.

| Path | View | Meta |
|---|---|---|
| `/` | Home | guestOnly |
| `/login` | Login | guestOnly |
| `/register` | Register | guestOnly |
| `/complete-registration` | CompleteRegistration | guestOnly |
| `/dashboard` | Dashboard | requiresAuth |
| `/profile` | Profile | requiresAuth |
| `/chat` | Chat | requiresAuth |
| `/admin` | Admin | requiresAuth + requiresAdmin |
| `/catalog` | CatalogSection (meta.section='catalog') | requiresAuth + requiresAdmin |
| `/active` | CatalogSection (meta.section='active') | requiresAuth + requiresAdmin |

Guard logic: unauthenticated + requiresAuth → `/login`; authenticated +
guestOnly → `/dashboard`; non-admin + requiresAdmin → `/dashboard`. On first
navigation it hydrates the user via the auth store.

## 3. Stores (`resources/js/store`)

- **auth** — current `user`, `isAuthenticated`, `isInitialized`; `fetchUser()`
  (`GET /api/user`), `login()`, `register()`, `logout()`.
- **requests** — `savedRequests`, `fetchSavedRequests()`, `saveRequest()`,
  `deleteRequest()`.

## 4. App shell (`App.vue`)

For authenticated users, a collapsible left sidebar:
- **Workspace:** Dashboard, Chat, Profile.
- **Admin** (only when `user.is_admin`): Admin Panel, Catalog, Active.
- Account popup (name/email, manage profile, admin link, sign out). Mobile
  topbar + overlay.

Guests get the plain routed view (Home/Login/Register).

## 5. Views

- **Home** — marketing sections + a live tester (posts `/api/proxy`) + a
  **Quick start** row of example cards that fill the tester and scroll to it.
  Protocols: REST, GraphQL, WebSocket, SOAP, Webhook, MCP, A2A. gRPC/MQTT/AMQP
  appear as **disabled "coming soon"** (never implemented — do not fake). No
  fabricated testimonials.
- **Login** — email/password + `GoogleButton`; surfaces `?error=` OAuth codes.
- **Register** — **email only** → `POST /api/register/start` → "check your
  inbox" state. (Email-first flow; see SPECS.md §7.)
- **CompleteRegistration** — reads `?email&token`, collects name + password →
  `POST /api/register/complete`, then routes to `/dashboard`.
- **Dashboard** — two-panel tester (RequestPanel + ResponsePanel) with a
  sidebar of **Saved / History** tabs (history is click-to-replay, 200 cap,
  clearable). On mount fetches `/api/user/preferences` (default protocol/method)
  and `/api/tools/active`, passing both to RequestPanel.
- **Profile** — tabs: **Account** (name, change password), **Personalisation**
  (default protocol/method, timezone, compact history →
  `PUT /api/user/preferences`), **API Keys** (generate/regenerate with a
  show-once banner; masked hint after; `curl` example for `/api/v1`), **Usage**
  (stats + recent activity), **Settings** (SCX AI integration), **Danger Zone**
  (delete account). Notification toggles were removed (no mail system).
- **Admin** — stat cards (users, new-this-week, admins, saved, request volume,
  per-protocol bars), searchable paginated users table (promote/demote/delete),
  and an audit-log table.
- **CatalogSection** — shared by `/catalog` and `/active`, parameterised by
  `route.meta.section`. See [CATALOG.md](CATALOG.md).
- **Chat** — SCX AI chat (uses the user's stored SCX key/model).

## 6. Components

- **RequestPanel** — protocol selector (REST/MCP/A2A) + method/URL/headers/body
  or params. MCP: **Discover Tools** (live `tools/list`) and an **Active tools**
  dropdown (from `/api/tools/active`) that fills the URL and a `tools/call`
  template from the stored `input_schema`. A2A: fetch agent card + `message/
  send` template. Applies saved default protocol/method from preferences.
- **ResponsePanel** — status/time/size, tabbed body/headers/request; JSON
  syntax highlighting. **HTML-escape content before highlighting** (XSS).
- **GoogleButton** — shared "Continue with Google" (links to
  `/auth/google/redirect`), used on Login and Register.

## 7. Theming

Dark theme via CSS custom properties (`--bg-color`, `--panel-bg`,
`--border-color`, `--text-primary`, `--text-secondary`, `--accent-color`, …)
defined in `resources/css/app.css`; components use scoped styles referencing
those vars. Protocol accent colours: REST `#58a6ff`, GraphQL `#e954b2`,
WebSocket `#3fb950`, MCP `#a371f7`, A2A `#f85149`.
