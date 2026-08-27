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

`resources/js/store`: `auth` (session user), `requests` (saved
requests + cross-view `openInTester` handoff), `environments` (selected
environment, persisted), `collections` (+ last run), `monitors`. Other views
call the API directly via axios.

---

## 4. App shell (`App.vue`)

For authenticated users, a collapsible left sidebar:
- **Workspace:** Dashboard, Chat, Profile.
- **Admin** (only when `user.is_admin`): Admin Panel, Catalog, Active.
- Account popup (name/email, manage profile, admin link, sign out). Mobile
  topbar + overlay.

Guests get the plain routed view (Home/Login/Register).

## 5. Views

The SPA has grown well beyond the original tester. Current views
(`resources/js/views`):

**Workspace:** `Home` (public), `Dashboard` (hub), `Tester` (two-pane
request/response with environment bar, assertions & contract panels),
`Collections` (saved requests + collections + history, parity),
`AiLab`, `Explore` (agent explorer), `Monitors` (+ alert channels, status
pages), `Webhooks` (capture), `Recorder` (flight recorder + synthesis),
`Reports`, `Chat` (Spi assistant), `Developers`, `Profile`.

**Admin area** (separate nav, entered from the profile menu): `Admin`
(overview), `AdminUsers`, `AdminUserDetail`, `AdminOrganisations`,
`AdminMonitoring`, `CatalogSection` (Catalog/Active). Shared admin styling in
`views/admin-shared.css`.

**Public/auth:** `Login`, `Register`, `CompleteRegistration`, `SharedReport`
(`/r/{token}`), `StatusPage` (`/status/{token}`), `Terms`, `Privacy`.

---

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
- **RequestPanel** now also supports gRPC/MQTT/AMQP connection fields and the
  active prompts/resources dropdowns.
- **AssertionsPanel / ContractPanel** — attach assertions and capture/verify a
  response contract on the loaded saved request, with live verdicts.
- **EnvironmentManager / CollectionManager** — modal editors (shared items show
  an owner badge when not the current user).
- **ImportDialog / ExportMenu** — cURL & OpenAPI import; cURL/fetch/Python/HTTP
  export.
- **RunResults** — per-step collection/parity run output (status, assertions,
  contract drift, extractions).
- **Icon** — dependency-free SVG icon set.

## 7. Theming

Dark theme via CSS custom properties (`--bg-color`, `--panel-bg`,
`--border-color`, `--text-primary`, `--text-secondary`, `--accent-color`, …)
defined in `resources/css/app.css`; components use scoped styles referencing
those vars. Protocol accent colours: REST `#58a6ff`, GraphQL `#e954b2`,
WebSocket `#3fb950`, MCP `#a371f7`, A2A `#f85149`.
