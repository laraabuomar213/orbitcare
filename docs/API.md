# API

Base: `/orbitcare/api/`. JSON in, JSON out.

```
Success  { "ok": true,  "data": … }
Error    { "ok": false, "error": { "code": "…", "message": "…", "fields": { "field": "message" } } }
```

Writes (POST/PUT) need the header `X-CSRF-Token` (value from `GET auth/session` or the
page's `<meta name="csrf-token">`). Authentication is the `orbitcare_session` cookie.

Common errors: `400 invalid_json` · `401 not_authenticated` / `session_expired` ·
`403 forbidden` / `csrf_failed` / `profile_incomplete` / `origin_not_allowed` ·
`404 not_found` · `405 method_not_allowed` · `409` conflicts · `422 validation_failed` ·
`429 rate_limited` · `500 server_error` · `503 database_unavailable`.

Roles: **A** = astronaut, **M** = approved mission admin, **any** = any signed-in user, **public** = no login.

## Auth

| Method | Path | Who | Body / notes |
|---|---|---|---|
| GET | `auth/session` | public | `{ authenticated, session_expired, user, csrf_token }` |
| POST | `auth/signup` | public, 10/10 min | `{ role: astronaut\|mission_admin, full_name, email, password, confirm_password }` |
| POST | `auth/profile` | any (profile incomplete) | Astronaut: `astronaut_code, age, height_cm, weight_kg, mission_id, has_chronic_conditions, chronic_conditions, takes_medications, medication_name[], medication_purpose[], has_allergies, allergies, medical_history_notes` · Admin: `job_title, organization, mission_id` → `{ redirect, mission, pending_approval }` |
| POST | `auth/login` | public, 10/5 min | `{ email, password }` → `{ redirect }` |
| POST | `auth/logout` | public | JSON → `{ redirect }`; plain form → 303 to the landing page |
| POST | `auth/password` | any, 5/10 min | `{ current_password, new_password }` |

## Astronaut & health

Mission admins add `?astronaut_id=` (must be in a mission they manage); astronauts may only use their own ID.

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `astronaut` | A, M | Profile (no medical details), missions, data source |
| PUT | `astronaut` | A | `{ full_name, age, height_cm, weight_kg }` |
| GET | `health/current` | A, M | Replay info, 4 metrics (value or "unavailable" + provenance), status, active alert counts, last update |
| GET | `health/trends` | A, M | `metric`, `range=1h\|6h\|24h`, `after_id` (only newer points) |
| GET | `health/history` | A, M | `metric`, `limit≤200`, `before_id` → rows with provenance + derived summary |
| POST | `health/replay/restart` | A | Starts the recording again |

## Alerts

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `alerts` | A, M | `filter=all\|active\|resolved\|critical\|warning`, `astronaut_id`, `mission_id`, `limit` |
| GET | `alerts/{id}` | A, M | Full detail: explanation, rule, threshold version, recommended action, source measurement, status |
| POST | `alerts/{id}/acknowledge` | A, M | |

## Missions & admin

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `missions/available` | any | Missions open for assignment (sign-up step 2) |
| GET | `missions` | A, M | Missions the user belongs to / manages |
| GET | `missions/{id}` | A, M (members) | `can_manage` flag |
| GET | `missions/{id}/members` | A, M | Health summary only for admins (and yourself) |
| GET | `missions/{id}/alerts` | A, M | Admins: crew; astronauts: own alerts only |
| GET / POST | `missions/{id}/messages` | A, M | `after_id` / `{ body }` (30/min) |
| GET | `admin/overview` | M | `mission_id` → crew with current health + active alerts |

## AI Assistant (consultations)

All consultation endpoints use the signed-in astronaut from the session. A consultation that
belongs to someone else returns **404** (its existence is not revealed).

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `ai/status` | A, M | `{ configured, provider, message, agents, medical_api }` |
| GET | `ai/specialists` | A | `[{ key, name, focus, description, metrics, icon, tone }]` from `config/ai-specialists.php` |
| GET | `ai/conversations` | A | Your consultations: `[{ id, specialist, specialist_name, status, referred_from, created_at, updated_at, message_count }]` |
| POST | `ai/conversations` | A, 30/min | `{ specialist: general\|respiratory\|cardiology\|neurology, referral_from? }` → opens or reopens; a specialist gets a structured handoff from the General Doctor consultation → `{ conversation, messages }` |
| GET | `ai/conversations/{id}` | A | `{ conversation, messages }` (`after_id` for newer only) |
| POST | `ai/conversations/{id}/messages` | A, 20/min | `{ message }` → `{ conversation, messages: [user, assistant] }`. 409 if closed, 422 if empty |
| POST | `ai/conversations/{id}/retry` | A | Re-runs the analysis for the latest message (after an AI error/timeout) |
| POST | `ai/conversations/{id}/close` | A | Ends the consultation (kept in history) |
| GET | `ai/conversation` · POST `ai/chat` | A | Dashboard AI panel → the active General Doctor consultation |
| POST | `ai/analyze` | A | Runs the agents on recent replayed data |
| POST | `ai/alerts/{id}/explain` | A, M | |

**Assistant message `payload`** (structured — the frontend never parses AI text):

```
kind                 consultation_reply | handoff | handoff_update
specialist, specialist_name, lead
data_status          available | none          data_last_updated (ISO)
data_scope_note      e.g. "OrbitCare records no neurological measurements…"
observations[]       { metric, label, value, unit, classification, normal_range, measured_at,
                       available, reason_unavailable, raw_measurement_id, source_record_id, recording_offset_seconds }
trends[]             { metric, label, text }        patterns[]  alerts[] { id, title, severity }
symptoms[]           { key, label, red_flag }        (consultation_reply)
interpretation       { status: success|not_configured|error, text? , message? }
recommendation       { specialist, name, confidence: low|moderate|high, reasons[], source: "rules" } | null
next_step            { level: urgent|consult|monitor, text, ai_text? }
follow_up_questions[], safety_notice, analysis_id, retryable
handoff              (handoff kinds) { patient_concern, symptoms[], relevant_measurements[], relevant_trends[],
                       relevant_alerts[], general_doctor_assessment[], reason_for_referral, data_last_updated }
```

## Daily check-in (self-reported indicators)

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `checkins/today` | A | `{ mission_day (UTC), checkin \| null, wellbeing: { due, next_due_on, last }, trends[7 days, null = no check-in], radiation: { readings, total_msv }, insights[], form }` |
| PUT | `checkins/today` | A, 30/min | `{ sleep_hours 0–24, sleep_quality 1–5, fatigue_level 1–7, resistance_minutes, aerobic_minutes, symptoms[] (or ["none"]), radiation_dose_msv?, notes?, wellbeing?: {1..4: 0–3} }` → same as GET. Saving again the same mission day updates it |

`insights[]`: `{ key, level: info|attention|urgent, area, title, text, rule, source: { label, url } | null }` —
deterministic rules from `config/checkin.php` (see docs/DATA_PROVENANCE.md).

## Settings

| Method | Path | Who | Notes |
|---|---|---|---|
| GET / PUT | `settings` | any | `{ notifications: { in_app_alerts, alert_sound }, display: { reduced_motion }, privacy: { share_checkins_with_mission } }` (check-ins private by default) |
