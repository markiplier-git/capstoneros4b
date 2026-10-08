# AGENTS.md — capstone_final (EcoAgri inventory/production)

Vanilla PHP + PDO MySQL + vanilla JS. No composer, no npm project, no build, no test runner.

## Run / setup

- Requires XAMPP (Apache + MySQL), Node/npm only for frontend libs. Docroot must be `htdocs/capstone_final` → `http://localhost/capstone_final/login.php`.
- `run.bat`: installs `assets/node_modules` (fontawesome, chart.js) and opens the site. Start Apache + MySQL in XAMPP first.
- `test.bat`: there are no automated tests — verify manually in browser per role (login, CRUD, charts, forecast).
- Check edited PHP with `php -l <file>`; there is no lint/typecheck suite.

## Database — `rural_urban` (root, no password, `php_backend/db.php`)

- Fresh install: run the full DDL in `README.txt`.
- Existing DB: run only the `MIGRATION` blocks in `database_query`. Ignore the stale top half of that file (INT quantities, `Recent` defaults, `role UNIQUE`) — canonical schema is the bottom `UPDATED TABLES` section + `README.txt`.
- Keep `date_default_timezone_set('Asia/Manila')` in `db.php`: PHP date filters must match MySQL TIMESTAMP clock (UTC+8) or same-day rows vanish.
- `php_backend/.private/account.php` + `.env` bootstrap the single admin row — never commit or print them.

## Auth / roles (`php_backend/session.php` → `requireRole`)

- `users.php`: admin only. `inventory/sales/reports.php` + `insertItem/import/setGoal/report_pdf`: admin, inventory_staff. `production.php` + `insertBatch/updateBatch`: admin, production_staff. `index/forecasting/history.php`: all roles. Stored values are `admin`, `inventory_staff`, `production_staff` — display labels come only from `roleLabel()` in `php_backend/session.php`. One active account per role (`$MAX_PER_ROLE` in `addUser.php`); deactivated accounts free their slot.
- Production-staff forecasting view is read-only by design (even a forged `generate` POST must not compute) — keep it that way. History is per-user for non-admins (`user = own username`, so admin rows never leak); admin sees all.
- Safety-stock goal: admin/inventory_staff only via `php_backend/setGoal.php`; it rewrites `php_backend/sales_goal.php` (generated — do not hand-edit).
- Password reset is self-service via `login.php` Forgot Password → `php_backend/password_reset.php` (JSON). Needs the admin-only email (Admin Email card on `users.php`), `password_resets` table, Gmail App Password in `MAIL_PASSWORD` (`php_backend/.private/.env` spaceless — the loader strips whitespace, never commit), and `vendor/` from `composer install`. Codes: hashed, single-use, 10-min expiry, ≤5 attempts, IP-bound, 60s cooldown + 5/hour cap. Login itself throttles too: 5 failures per username+IP inside 5 minutes lock further tries (`login_attempts` table), success clears the count. Last-resort link stays hidden until Send Code is attempted; reset fields have show/hide toggles and a Close row after any result; new password must differ from the current one. Email/password changes take double input (email+confirm, password+confirm) with server-side match checks. Passwords follow one rule (`password_policy.php`: ≥8 chars, 1+ letter + 1+ number, common-password denylist) on every creation/change path. Last-resort requests go to the hardcoded backup inbox in `.private/account.php`; the system never self-deletes accounts.
- Stock bands on `index.php` (`#dashboard-notif`), `inventory.php` (`#notif`), and `production.php` (`#prod-notif`) share one goal-relative logic — green at/above goal, orange "under the safety stock" warning below goal, red "nearly depleted" at ≤ 5 Sacks, danger text at zero — and markup (`h2.notif > i + span`, server-rendered). Dashboard and inventory append `Produce`/`Add` action links on low bands (Add jumps to `#transactions` on the Transactions card). Production renders its band only when stock is low/critical (no healthy banner). Never reintroduce hardcoded thresholds or JS `innerHTML`/`style.color` banner hacks on any page.

## Data rules that break things if changed

- Completing a batch (`updateBatch.php ... WHERE production_id`): inserts `inventory` with its own random 7-digit `prod_id` (never the batch id; link lives in description `Batch: <id>` only), and bumps the single-row `total` ledger. Guarded against double-`Completed` — keep the guard. Inventory rows are permanent: never add edit/delete for them.
- Live stock = `total` ledger; dashboard/inventory read it, completed batches add, inventory deducts. `insertItem.php` writes a `history` leg for both directions (`Stock Added` / `Stock Deducted`, ref `TOTAL`) — Recent Inventory Movements and the Inventory Analysis inflow count both legs, so keep them in sync. (Dashboard trend plots the ledger directly, so it needs no leg.)
- Forecasting lives only in `php_backend/forecast_lib.php::runForecast()` (Holt's DES, monthly, 12-point gate, 36-month cap, MTD pro-rated, predicts next full month, clamps ≥0). `forecasting.php`, `reports.php`, `report_pdf.php` share it — fix logic there, never per-caller. Thin-history refusals save nothing to `forecasting_history`. The Forecasting chart shows complete months + forecast only; the table adds one text-only "(Need end of month)" placeholder row for the current month instead of a number.
- Sales import (`php_backend/import.php`) accepts only month-paired JSON (`["YYYY-MM", qty, ...]`, ascending, closed months, max 60). Two-step: upload stages a session preview (oldest→latest coverage, missing months block, zeros warn, existing-range conflicts need overwrite checkbox), Confirm writes one Completed `inventory` row per month (mid-month stamp).
- Units: canonical `Sacks`, 1 Sack = 50 KG display-only conversion (hover on desktop, tap on mobile).
- All queries via PDO prepared statements. Frontend libs load local-first (`assets/node_modules/...` via `head_assets.php`) with CDN fallback — set `$NEED_CHART = true` before including it on chart pages; cache-bust CSS with `filemtime('style.css')`.
- CSRF: every POST carries `csrf_field()` and every POST endpoint calls `csrf_check()` first (`php_backend/csrf.php`; session cookie is httponly + SameSite=Lax, 12h absolute + 30min idle expiry). `appBase()` prefixes every shared redirect/link (`''` root, `'../'` backend) — use it, never hardcode. Never add a state-changing GET endpoint or skip the check on a new form/handler.
- Dashboard second row is `.info-cards.trend-row` (1:2 grid, stacks on mobile). Never put `grid-column: span N` / `1 / -1` inline on the trend card — it breaks the mobile single-column grid or strands Safety Stock on its own row.
- Dashboard Inventory Trend plots the `stock_daily` ledger (one row/day, upserted by `recordStockSnapshot()` in `php_backend/stock_snapshot.php` on every `total` write + dashboard visit). True recorded history — never reconstruct it. `$trendCurrent` (live total, fetched once at top of `index.php`) feeds the Vermicast card — do not remove it.
- Dialog JS: scope duplicate ids to their dialog (e.g. `#edit-diag .desc`), wrap in `DOMContentLoaded`, use `querySelectorAll` + `forEach` for repeated rows.
