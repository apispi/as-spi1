# Documentation

Specification and reference for **apispi.com (Spi)** — a multi-protocol API
testing tool (Laravel 11 + Vue 3). These docs are written to let an engineer or
AI understand, extend, or rebuild the project. Project overview and setup live
in the [root README](../README.md).

## Documents

| Doc | Scope |
|---|---|
| [SPECS.md](SPECS.md) | **Canonical top-level specification.** Full rebuild reference: intent, data model, API surface, behaviours, security invariants. |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Request lifecycle, the two-path auth model, routing/bootstrap, middleware, rate limiting, deployment topology. |
| [MODELS.md](MODELS.md) | Eloquent models — fillable/hidden, casts, relations, methods, business rules. |
| [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) | Every table, column, type, index, and the migration order. |
| [FRONTEND.md](FRONTEND.md) | Vue SPA — router, Pinia stores, views, components, theming, build. |
| [CATALOG.md](CATALOG.md) | Catalog/Active admin sections and the connector sync that populates them. |

`SPECS.md` is authoritative; the others are focused deep-dives. If a companion
disagrees with SPECS.md, fix the companion.

## Suggested reading order

1. **ARCHITECTURE.md** — the shape of the system and how a request flows.
2. **DATABASE-SCHEMA.md** + **MODELS.md** — the data and the rules over it.
3. **FRONTEND.md** — how the SPA consumes the API.
4. **CATALOG.md** — the most involved feature, end to end.
5. **SPECS.md** — the complete contract; read fully before a rebuild.

## Repository map

| Path | Contents | Documented in |
|---|---|---|
| `app/Http/Controllers/` | HTTP endpoints | SPECS §6–7, CATALOG |
| `app/Http/Middleware/` | `IsAdmin`, `AuthenticateApiToken` | ARCHITECTURE §5 |
| `app/Models/` | Eloquent models | MODELS |
| `app/Services/Mcp/`, `app/Services/A2a/` | Protocol clients | SPECS §4, ARCHITECTURE §6 |
| `app/Rules/PubliclyRoutableUrl.php` | SSRF guard | SPECS §4 |
| `app/Mail/` | `RegistrationVerificationMail` | SPECS §4, §7 |
| `app/Providers/AppServiceProvider.php` | Rate limiters | ARCHITECTURE §5 |
| `database/migrations/` | Schema | DATABASE-SCHEMA |
| `routes/web.php`, `routes/api.php` | Routing | ARCHITECTURE §3, SPECS §5–6 |
| `bootstrap/app.php` | App wiring, middleware aliases, public path | ARCHITECTURE §3 |
| `config/security.php`, `config/services.php` | SSRF/admin + OAuth config | SPECS §9 |
| `resources/js/` | Vue SPA (`views/`, `components/`, `store/`, `router.js`) | FRONTEND |
| `resources/css/app.css` | Theme variables | FRONTEND §7 |
| `resources/views/emails/` | Mail templates | SPECS §7 |
| `public_html/` | Web root, built assets, `.htaccess` | ARCHITECTURE §7 |
| `deploy.sh` | Two-mode deploy script | ARCHITECTURE §7 |
| `tests/` | PHPUnit feature/unit tests | SPECS §12 |

## Conventions

- File paths are repo-relative.
- "**must** / **never**" mark invariants that protect security or correctness —
  do not regress them (consolidated in [SPECS.md §11](SPECS.md) and
  [ARCHITECTURE.md §8](ARCHITECTURE.md)).
