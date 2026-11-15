# Architecture

```
PhysioNet BIDMC CSV (data/raw)
   │  cli/download-bidmc.php
   ▼
DATA INGESTION + VALIDATION  (DataIngestion, DataValidator)
   │  only validated values; missing = skipped & counted; impossible = rejected
   ▼
DATABASE  raw_measurements (immutable, DB triggers block UPDATE/DELETE)
   │
   ▼
DATA REPLAY  (DataReplay) — replay position = (now − start) × speed
   │  each newly reached sample, in order, exactly once
   ▼
HEALTH ANALYSIS (HealthAnalysis)  → processed_measurements (→ raw id)
   ▼
ALERT ENGINE (AlertEngine, deterministic)  → alerts (→ source measurement + rule)
   ▼
BACKEND API  api/index.php → backend/routes/*  (auth, roles, CSRF, rate limits)
   ▼
AI ANALYSIS LAYER (AIService → agents → provider; "not configured" by default)
   ▼
FRONTEND  pages/*.php + assets/js/app/*.js (polling, incremental updates)
   ▼
MISSION GROUP / ALERT FEED  (MissionService, /api/missions/{id}/alerts)
```

## Folders

| Folder | Role | Public? |
|---|---|---|
| `index.php`, `pages/` | Pages (server-rendered shell; data loaded from the API) | yes |
| `assets/css`, `assets/js` | Styles; `assets/js/app/` = signed-in app modules, `api.js` = central API client | yes |
| `api/index.php` | Single API entry point | yes (only entry to the backend) |
| `backend/core` | env, config, database, router, HTTP responses, logging, audit | no |
| `backend/middleware` | sessions & role guards, CSRF/CORS/rate limits, data-access rules | no |
| `backend/routes` | API endpoints grouped by area | no |
| `backend/services` | Business logic (ingestion, replay, analysis, alerts, missions, AI, medical API) | no |
| `backend/database` | `schema.sql`, `seed.sql` | no |
| `frontend/components` | PHP partials: layout/sidebar/header, dashboard cards, login modal | no |
| `config` | `config.php` (reads env), `health-thresholds.php` | no |
| `data/raw`, `data/processed` | Downloaded CSVs, ingestion reports | no |
| `cli` | Command-line tools | no |
| `tests` | Test suites and synthetic fixtures | no |
| `logs` | `app.log` (JSON lines) | no |

Private folders are blocked by the root `.htaccess` (404/403).

## Frontend ↔ backend

The frontend never touches the database or any secret. Every call goes through
`assets/js/app/api.js`, which adds the CSRF header, times out after 15 s, maps errors to
messages, and on `401` saves unsaved drafts and sends the user to log in again.

The dashboard polls one endpoint (`/api/health/current`) every 2 s and fetches only *new*
chart points (`after_id`), so nothing is reloaded wholesale. Polling pauses in hidden tabs.

## Arabic / English

Every text a user can see exists in both languages, in `backend/lang/{en,ar}/<area>.php`
(one file per area: common, dashboard, alerts, analytics, checkin, ai, mission, profile,
settings, auth, landing, alertness, emergency, chat). Both folders hold the same files and keys.

- **PHP:** `t('checkin.save')`, with placeholders `t('time.minutes_ago', ['n' => 5])`.
  `translate_in('en', $key)` gets a specific language.
- **Browser:** the page prints the current language's texts into `<script id="i18n-data">`;
  `assets/js/i18n.js` exposes them as `window.OrbitI18n`, and app modules import `t` from `api.js`.
  Nothing is fetched separately, so it works offline.
- **Choosing the language:** Settings → Language saves it to the account (`display.language`) and
  sets the `orbitcare_lang` cookie for the device. Public pages have a small switch (`?lang=ar`).
  At login the account's language applies unless this device already chose one.
- **Right-to-left:** `<html lang="ar" dir="rtl">`. CSS uses logical properties (`inset-inline-start`,
  `margin-inline-end` …) so layouts mirror by themselves; the few exceptions are in the
  "right-to-left" section of each stylesheet. Arrows use `.flip-rtl`. Charts keep time running
  left → right. Digits stay Western (`ar-u-nu-latn`).
- **Stored records stay language-neutral:** alerts are saved in English (a fixed record) and shown
  in the reader's language by rebuilding the text from the stored values (`AlertService::localize`).
  Status codes (`RED`, `GREEN` …) never change with the language.
- **Validated questionnaires:** PHQ-4 and the Samn-Perelli scale are shown in Arabic as an
  *unofficial translation*, with a button to show the original English wording.
- **AI:** symptom detection understands Arabic (Modern Standard and common Levantine phrases, with
  spelling normalisation and negation, `config/ai-specialists.php` → `phrases_ar`); the AI prompt
  asks for replies in the astronaut's language.
- Fonts are self-hosted (`assets/css/fonts.css`: Lexend + IBM Plex Sans Arabic) — no Google Fonts.
- `tests/run.php` checks that both languages have the same keys and placeholders, that every key
  used in the code exists, and that validated questionnaires keep their exact English wording.

## Missions and crew chat

- **Missions belong to admins:** an approved admin creates missions (`POST /api/missions`) and picks the
  crew from registered astronauts not yet in a mission (`GET /api/admin/astronauts`,
  `POST /api/missions/{id}/members`). An astronaut is in one mission at a time; sign-up no longer
  asks for a mission. `cli/approve-admin.php <email> [CODE]` approves an admin.
- **Crew chat** (`CrewChatService`, `GET|POST /api/missions/{id}/chat`, `POST /api/missions/{id}/read`):
  text messages plus cards — *emergency* (posted from the astronaut's own account, with the crew report
  and live "who is coming"), *recovery* (back in range) and *system* (crew changes).
  "Seen by" comes from each member's `last_read_message_id`; the status bar shows only online/away and
  whether someone has an active emergency — no medical details.
- **My Missions** (admins, `pages/missions.php` + `assets/js/app/missions-admin.js`, `MissionBoardService`,
  `GET /api/admin/missions[/{id}]`): create a mission, set its status, add / remove astronauts, and a crew
  table of who needs attention — health status now, today's check-in, latest Alertness Check vs. their own
  baseline, last seen, open emergency. "Need attention" = an open emergency or a critical (RED) status.
  Only recorded data is shown; an astronaut without a data source shows "No data".
- **Page** (`pages/mission.php` + `assets/js/app/mission.js`): crew status bar, the thread, open emergency
  cards in a side column (above the thread on small screens) with *On my way* / *Seen*, and the composer.
  Refreshed every 3 s; it re-draws only when something changed and keeps your scroll position.

## Vest device (Wokwi simulation for now)

**Off by default (`DEVICE_INPUT=off`):** OrbitCare's live data is the PhysioNet BIDMC recordings (real patient
measurements); with device input off the endpoint below is not registered at all (404). The code stays
ready for real vest hardware: set `DEVICE_INPUT=on` in `.env`.

`POST /api/device/readings` (`DeviceService`, `backend/routes/devices.php`) — the only route without CSRF:
the device has no browser session and proves itself with `X-Device-Token` (only its SHA-256 is stored,
`cli/register-device.php`). Every reading is kept as sent (`device_readings`); heart rate, SpO2 and
respiratory rate go into a live stream (a `source_records` row `device-sim:<id>`, played at speed 1), so
they reach the same analysis, alert engine and crew alarm as recorded data. Each source has a sample
interval (BIDMC 1 s, vest 5 s); "10 seconds out of range" means the same for both. The interface labels
the stream "Simulated device — simulated values". `cli/device-gateway.php` is the only door ngrok may
open. Details and firmware settings: `docs/DEVICE_SIMULATION.md`.

## Crew emergency alarm

`EmergencyService` + `assets/js/app/emergency.js` (loaded on every signed-in page by `shell.js`).
- **Raised by:** a critical (RED) alert from the measurements (`AlertEngine`), or a dangerous symptom in
  the astronaut's *latest* AI message (chest pain, can't breathe, fainting, confusion, difficulty
  speaking — the `red_flag` symptoms, English or Arabic). The same symptom does not ring again within 30 min.
- **Who hears it:** every member of the astronaut's mission (astronauts and admins), not the astronaut
  themself — they see "your crew has been alerted" and who is coming.
- **What it does:** every page polls `GET /api/emergencies` every 5 s (also in background tabs; it also
  brings the crew's replays up to date). A red window opens, a siren plays (Web Audio) and the browser
  says "Emergency. <name>: <problem>" in the listener's language — repeated every ~9 s until that member
  presses *On my way* or *Seen* (`POST /api/emergencies/{id}/respond`). One card per person.
  It stops when the alert resolves; unanswered alarms stop ringing after 2 h.
- **Voice offline:** a voice installed on the device is preferred; if only online voices exist and there
  is no link, the English sentence is spoken with a local voice. Browsers need one tap on the page before
  sound — the window shows "Tap to turn on the alarm sound" until then.
- **Privacy:** the crew gets only the name, the problem and the time; every answer is audited.

## Roles and access

- Page guards (`require_page_user`, `require_page_role`) return a real **403 page** for the wrong role.
- API guards (`'roles' => [...]` on each route) return JSON 401/403.
- Data access (`target_astronaut_id`, `MissionService::canViewAstronaut`): astronauts only
  their own data; approved mission admins only astronauts in missions they manage.
- Signed-in pages are sent with `Cache-Control: no-store` so "Back" after logout can't show them.

## Logging and audit

- `logs/app.log`: one JSON object per line — `ts`, `level`, `event`, IDs, `status`.
  Events: `auth.*`, `data.ingestion`, `data.download`, `replay.*`, `api.request`,
  `alert.created`, `alert.resolved`, `mission.notification`, `ai.request`, `medical_api.request`,
  `database.connect`. Keys that look like secrets are redacted automatically.
- `audit_logs` table: login/logout/failed login, sign-up, profile changes, mission joins,
  alert creation/acknowledgment, admin views of crew health, AI and medical API requests.
