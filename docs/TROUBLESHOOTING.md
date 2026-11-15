# Troubleshooting

Start with **two checks** — they tell you which layer is failing:

1. Open http://localhost/orbitcare/api/auth/session
   - JSON `{"ok":true,…}` → Apache, PHP and routing work.
   - 404 page (not JSON) → rewrite not working (see *API*).
   - `503 database_unavailable` → see *Database*.
2. Look at the last lines of `logs/app.log` (JSON, one event per line). Every API request
   logs `api.request` with path and status; errors log `api.exception` with file:line.

| Symptom | Layer | How to confirm | Fix |
|---|---|---|---|
| Page loads but cards say "Could not reach the server" | **Frontend ↔ network** | Browser DevTools → Network: `/api/...` failing | Apache running? Same origin? |
| Page is blank / unstyled | **Frontend** | DevTools Console errors; 404 on `assets/...` | Check `APP_BASE_URL` matches the folder name |
| `/api/...` returns an HTML 404 | **API routing** | `.htaccess` present? `mod_rewrite` loaded in `httpd.conf`? `AllowOverride All` for htdocs? | Enable them, restart Apache |
| `500 server_error` | **Backend** | `logs/app.log` → `api.exception` | Fix the reported file/line |
| `503 database_unavailable` | **Database** | `logs/app.log` → `database.connect` code; MySQL running in XAMPP? | Check `DATABASE_URL`; start MySQL; load `schema.sql` |
| "Table … doesn't exist" | **Database** | `mysql -u root orbitcare -e "SHOW TABLES"` | Load `backend/database/schema.sql` + `seed.sql` |
| Dashboard: "No recorded data source is assigned" | **Dataset / account** | `SELECT data_source_record_id FROM astronauts` | Profile not completed, or created before data was loaded |
| `409 record_not_ingested` | **Dataset** | `SELECT COUNT(*) FROM source_records` | Run `cli/download-bidmc.php` then `cli/ingest-bidmc.php` |
| Ingestion: "Invalid dataset…" | **Dataset** | Message names the file and problem; `data/processed/*_ingestion.json` | Re-download the file |
| Values stop changing, "replay complete" | **Replay service** (expected) | Recordings are 8 minutes | Press *Restart replay* |
| A metric shows "Data unavailable" | **Replay / source data** (expected) | Analytics → Data quality shows missing counts | The recording has a gap; nothing is filled in by design |
| No alerts appear | **Alert engine** | `SELECT * FROM processed_measurements ORDER BY id DESC LIMIT 5` — classifications present? Values outside `config/health-thresholds.php` for ≥ 10 s? | Expected for in-range records like `bidmc_03` |
| Redirected to login with "session expired" | **Authentication** | Idle longer than `SESSION_IDLE_MINUTES` | Log in again — drafts are restored |
| `403 csrf_failed` | **Authentication** | Page opened before a login/logout in another tab | Refresh the page |
| `403 forbidden` on admin pages | **Authorization** (expected) | `SELECT role, admin_approved_at FROM users` | Approve with `cli/approve-admin.php` |
| `429 rate_limited` | **Security** | Too many login/sign-up attempts | Wait a few minutes |
| Chat says "AI provider not configured" | **AI provider** (expected) | `GET /api/ai/status` | See docs/AI_ARCHITECTURE.md |
| Medical API errors | **Medical API** | `logs/app.log` → `medical_api.request` status | Check key/URL/timeout |

## Run the tests

```
C:\xampp\php\php.exe tests\run.php          # needs MySQL; uses orbitcare_test
C:\xampp\php\php.exe tests\api-tests.php    # needs Apache + MySQL
```

## Local MariaDB note

On this machine MariaDB reported `mysql.db` as crashed, so a restricted database user could
not be granted permissions and the app uses XAMPP's local `root`. After a backup:
`mysql -u root -e "REPAIR TABLE mysql.db"`, then create a user with only
`SELECT, INSERT, UPDATE, DELETE` on `orbitcare.*` and put it in `DATABASE_URL`.
