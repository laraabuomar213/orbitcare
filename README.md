# OrbitCare

Space-health monitoring and decision-support prototype for astronauts (hackathon build).

**There is no custom wearable in this version.** The dashboard replays **real recorded
physiological data** from the public [PhysioNet BIDMC PPG and Respiration Dataset](https://physionet.org/content/bidmc/1.0.0/).
Values are never generated, randomised or modified, and the UI labels them as
*Recorded data replay*. Those recordings are from ICU patients, not astronauts — see
[docs/DATA_PROVENANCE.md](docs/DATA_PROVENANCE.md).

Stack: PHP 8.2 · MySQL/MariaDB · HTML/CSS/vanilla JavaScript (no frameworks) · Apache (XAMPP).

---

## 1. Install (local, XAMPP)

1. Install XAMPP (PHP ≥ 8.1 with `pdo_mysql`, `curl`, `openssl`; MariaDB ≥ 10.4). Start **Apache** and **MySQL**.
2. Put the project at `C:\xampp\htdocs\orbitcare` (so it opens at http://localhost/orbitcare/).
3. Configure: copy `.env.example` to `.env` and fill it in (see §2).
4. Create the database and demo missions:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root < backend\database\schema.sql
   C:\xampp\mysql\bin\mysql.exe -u root orbitcare < backend\database\seed.sql
   ```
5. Load the PhysioNet data (§4), then optionally create the demo crew (§6).

**Upgrading an existing database:** apply new files in `backend/database/migrations/` in date order, e.g.
```
C:\xampp\mysql\bin\mysql.exe -u root orbitcare < backend\database\migrations\2026-11-09-ai-consultations.sql
```

## 2. Configure

All settings come from environment variables or the private `.env` file (blocked from the web):

| Variable | Meaning |
|---|---|
| `DATABASE_URL` | `mysql://user:password@host:port/database` |
| `AUTH_SECRET` | Long random string (pseudonymises client addresses in audit/rate-limit records) |
| `SESSION_IDLE_MINUTES` | Idle time before a session expires (default 120) |
| `REPLAY_SPEED` | 1 = real time |
| `CORS_ALLOWED_ORIGINS` | Extra origins allowed to call the API (empty = same origin only) |
| `AI_PROVIDER`, `AI_API_KEY`, `AI_API_BASE_URL`, `AI_MODEL` | Future AI provider (server-side only) |
| `MEDICAL_API_KEY`, `MEDICAL_API_BASE_URL` | Future medical API (server-side only) |

In production use the hosting platform's secret manager instead of a `.env` file.
The browser never receives any of these values.

## 3. Start frontend and backend

There is no separate build step: Apache serves both.

- Frontend: the PHP pages in `pages/` + `index.php`, with `assets/` (CSS/JS).
- Backend API: `api/index.php` — Apache rewrites `/orbitcare/api/<path>` to it (`.htaccess`, needs `mod_rewrite`).

Open http://localhost/orbitcare/.

## 4. Load the PhysioNet data

```
C:\xampp\php\php.exe cli\download-bidmc.php     # 53 records → data\raw\
C:\xampp\php\php.exe cli\ingest-bidmc.php       # validate + load into raw_measurements
```
Ingestion keeps every value exactly as recorded, skips missing (`NaN`) values instead of
filling them, rejects impossible values, and writes a per-record report to `data\processed\`.
Running it again is safe.

## 5. How replay works

Each astronaut account is assigned one dataset record. A *replay session* maps the
recording's own clock onto real time: after *t* seconds, every measurement with
`original_timestamp ≤ t` has "arrived". Each API request catches the replay up, analyses the
newly reached samples (in order, exactly once), and feeds the alert engine. When the
recording ends (8 minutes), the replay ends — it does not loop. Astronauts can restart it.

## 6. Authentication and roles

Real accounts in MySQL, passwords hashed with bcrypt, PHP sessions (HttpOnly, SameSite=Lax
cookie, new ID on login), CSRF tokens on every write, idle timeout, rate-limited login/sign-up.

- **Astronaut** — own dashboard, health data, alerts, AI assistant, mission group, profile, settings.
- **Mission admin** — mission dashboard, crew health of the missions they manage, mission alerts.
  Choosing "Admin" at sign-up grants nothing until approved:
  ```
  C:\xampp\php\php.exe cli\approve-admin.php person@example.com [MISSION-CODE]
  ```

Demo crew (3 astronauts + 1 approved admin in *Mars Expedition*, random passwords printed once):
```
C:\xampp\php\php.exe cli\create-demo-accounts.php
```

## 7. How alerts work

Deterministic rules in `config/health-thresholds.php` (documented ranges, configurable,
versioned). An alert is created when a value stays outside the green range for 10 consecutive
seconds; it records the rule, threshold version, observed value, unit and the exact source
measurement. It resolves after 10 s back in range. No AI decides whether a value is dangerous.

## 8. AI Assistant

General Doctor AI (entry point) recommends Respiratory, Cardiologist or Neurology AI using
backend rules on your real data and words; specialists receive a handoff so nothing is repeated;
consultations persist in the database. Without an AI provider it still works and says the AI
interpretation is unavailable. See [docs/AI_ARCHITECTURE.md](docs/AI_ARCHITECTURE.md).

## 9. Tests

```
C:\xampp\php\php.exe tests\run.php          # unit + integration (separate orbitcare_test database)
C:\xampp\php\php.exe tests\api-tests.php    # end-to-end over HTTP (Apache must be running)
```

## 10. Deploy

Any host with PHP 8.1+, MySQL 8 / MariaDB 10.4+ and Apache with `mod_rewrite` (or an
equivalent rewrite of `/api/*` to `api/index.php` on nginx). Set the environment variables
in the platform's secret manager, serve over **HTTPS** (session cookies become `Secure`
automatically), load the schema, run the two data CLI scripts, and keep `backend/`, `config/`,
`data/`, `cli/`, `tests/`, `logs/` and `docs/` out of the public web root or blocked as in `.htaccess`.

## Documentation

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — layers, folders, request flow
- [docs/DATA_PROVENANCE.md](docs/DATA_PROVENANCE.md) — where every metric comes from
- [docs/API.md](docs/API.md) — endpoints
- [docs/AI_ARCHITECTURE.md](docs/AI_ARCHITECTURE.md) — agents and provider interface
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) — finding which layer is failing
