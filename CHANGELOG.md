# GenPay Changelog

## October 27, 2026 — Backup & Restore

- **Backups:** Super-admins can create, download and delete backups at `admin/backup.php`. Each backup is a single zip in `backups/` with:
  - `database.sql`, a full `mysqldump` with triggers and with `DEFINER` clauses removed
  - `database_report.pdf`, every table and every row, with password, PIN, token, hash and session columns masked
  - `uploads/`, `assets/profile_photos/` and `assets/merchant_logos/`
  - `manifest.json`, holding a SHA-256 of the SQL, per-table row counts and the circulation state

  The zips themselves are the list of backups, so there is no database table that a restore could roll back. The core is in `connection/app/backup.php` and `connection/app/backup_pdf.php`. FPDF 1.86 is vendored in `connection/lib/fpdf/`.
- **Restore:** Restores are super-admin only and need the account password plus the word `RESTORE`.
  - Before any change, the SQL is checked against the manifest hash and a `pre-restore` safety backup is taken.
  - Every other request gets a 503 while the restore runs.
  - If the SQL import fails, the safety copy is imported again automatically. If swapping the files fails, the original folders are put back.
  - Afterwards the app checks row counts, circulation drift and the audit hash chain.
- **Automatic backups:** `database/backup_cron.php` (CLI only) creates a nightly backup and keeps the newest 14 `auto` backups. It needs to be added to Windows Task Scheduler; the command is in the file header. The admin page shows a warning when no automatic backup has run in 36 hours.
- **Audit:** New action types `BACKUP_CREATE`, `BACKUP_DOWNLOAD`, `BACKUP_RESTORE` and `BACKUP_DELETE`. Each action is also written to `backups/backup.log`, because a restore replaces the audit table.
- **Access:** `.htaccess` returns 403 for `/backups`, and `backups/.htaccess` (Deny from all) is tracked in git.

### Verification

A round trip on a scratch database (`ewallet_backup_test`) passed all 16 checks:
- After a restore, every table matched its pre-change `CHECKSUM TABLE` value, a table added after the backup was removed, an added upload was removed, the triggers came back and the post-checks were green.
- A backup with a tampered `database.sql` was refused without any change.
- A failed import was rolled back.
- The lock and path-traversal guards held.

The audit writer's table grants survive the drop and re-import. The PDF holds none of the 33 bcrypt hashes. Backup zips, the log, `database/` and `connection/lib/` all return 403 over HTTP.

## October 20, 2026 — Security Fixes

- **Mint PIN lockout:** Five wrong Mint PINs in 15 minutes lock minting for that admin. Wrong passwords when setting a new PIN count toward the same lock (`connection/MintingGuard.php`).
- **Sign-in limit per network:** 100 failed sign-ins from one network address in 15 minutes pause sign-in from it, on top of the existing 5-per-account lock (`record.php`). Wrong passwords typed by email and by student ID now count toward the same account, so an account gets 5 tries in total instead of 5 for each (`tests/login_account_lock.php`).
- **Spending-limit race:** Transfers, checkout and POS payments now lock the student's wallet before checking the daily limits, so two requests at the same moment can no longer both pass (`gjc_lock_student_wallet()` in `connection/app/wallets.php`).
- **Public form upload limit:** The stall application form accepts 60 uploads per network address per hour (`connection/app/stall_applications.php`).
- **Shared throttle:** All four limits reuse the `login_attempts` table. Keys are scoped HMAC hashes (`gjc_throttle_key()`), and the table check now runs once per request.
- **Admin product restriction:** `admin/api/get_stall_details.php` now checks the CSRF token, and `admin/merchant_details.php` sends it.

### Verification

New tests `tests/wallet_lock_order.php` and `tests/apply_upload_limit.php` pass, as do the updated `tests/login_throttle.php` and `tests/mint_pin_required.php`. A two-connection check confirmed the wallet lock blocks a second payment until the first ends. Existing PHP and Node tests touched by these areas still pass.

## October 20, 2026 — Cleanup

- **Dead schema helpers:** Removed 18 one-time `gjc_ensure_*` / backfill functions from the 3NF and normalization work (`connection/app/contracts.php`, `schema.php`, `orders.php`, `stall_applications.php`, `wallets.php`). Nothing called them; they remain in git history at `4757b27` and earlier.
- **POS tests:** `tests/pos_queue_qr.cjs` and `tests/pos_qr_requests.cjs` now model the fullscreen QR elements added to `merchant/pos.php`.

### Verification

All PHP files lint clean. All 17 PHP test scripts and 3 Node tests pass. Every page and endpoint answers without a server error.

## October 8, 2026 — Local Development

- **Integrity repairs:** Added the missing parent role and reconnected two fee-waiver logs to credit #131.
- **Temporary passwords:** Cleared 211 stored plaintext values and stopped future storage in the three password columns. Existing email delivery and first-login password changes remain.
- **Legacy tables:** Exported and removed the empty `wallet`, `transaction`, and `topup` tables, along with their old code references.
- **Order items:** Made item tables the official records and kept JSON as historical snapshots. Backfilled 78 cart items, 57 QR items, and 92 receipt items. Preserved deleted-product details.
- **Core database integrity:** Changed 25 user-reference columns to signed integers and added 51 foreign keys. The database now has 66 foreign keys across 59 tables.

### Verification

PHP syntax and migration checks passed. Existing money data, order snapshots, and triggers remained unchanged.

### Still Pending

Orphaned records, remaining non-core foreign keys and ID-type mismatches, and the tuition-reference collation mismatch were left unchanged.

### Rollback Exports

Saved in `storage/backups/`:

- `genpay-legacy-table-definitions-2026-10-08.sql`
- `genpay-order-items-before-2026-10-08.sql`
- `genpay-core-integrity-before-2026-10-08.sql`
