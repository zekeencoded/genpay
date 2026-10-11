# Group D3 lease due-date MIGRATE completed — 10 October 2026

Applied after explicit Group D3 MIGRATE approval and refreshed full-backup confirmation.

## Changes

Installed the four exact approved candidates:
- connection/MerchantTenantDirectory.php: shared derived-date projection using existing rent calculations and lease-end fallback; removed unused cached date selection from directory cards.
- admin/leases.php: calculated due date included in the returned lease rows, preserving current cache repair writes during verification.
- merchant/dashboard.php: displayed due date calculated from the selected lease and current rent-payment records.
- merchant/export_backup.php: Next Due calculated for leases.csv; all eight CSV columns, ordering, and sanitization remain intact.

No database or schema changes were applied. merchant_leases.next_due_date and all existing creation/cache synchronization writers remain. CONTRACT has not been applied.

## Verification

Installed source exactly matches the approved candidates; all four files pass PHP lint.

| Read-only check | Result |
| --- | --- |
| Complete lease API profiles | 4 of 4 match |
| Rent accounts | 4 of 4 match |
| Merchant dashboard data | 4 of 4 current owners match |
| Lease CSV rows/column order/value types | 4 of 4 current owners match |
| Stale stored-date cases | 4 pass |
| Absent stored-date cases | 4 pass |
| Directory card outputs | Unchanged |
| Lease-end fallback | Passed |
| Missing active lease display | Passed |
| Empty export for missing owner | Passed |
| Existing billing calculation methods | Four unchanged |
| Legacy syncNextDueDate writer | Unchanged |
| Base tables and exact fingerprints | All 70 unchanged |
| Schema metadata | Unchanged |
| Protected triggers | Both unchanged |

A fresh private snapshot was captured immediately before installation and compared with the installed-code checks. Verification did not call live financial writers, browser endpoints, reminders, uploads, ZIP generation, payment processing, or audit logging.

Browser testing remains deferred. Read-only query/helper comparisons do not establish end-to-end browser, payment, award, notification, or concurrency behavior.

## Evidence and rollback

Private immediate source originals and baseline are under storage/backups/d3-migrate-2026-10-10, protected from HTTP and locally ignored. applied-migrate.json and migrate-verification.json record deployment and verification.

The approved exact candidates and diff remain under:
C:/Users/Ezekiel/.codex/visualizations/2026/10/09/01a11e42-5059-7012-a15b-ddc6ef52d71a/genpay-group-d3-migrate-2026-10-10

Rollback is source-only: restore these four immediate original files together. Keep legitimate subsequent rent payments, due-date cache updates, wallet activity, and audit history. No database restoration is required for this MIGRATE.

## Next phase

Prepare Group D3 CONTRACT: adapt the remaining constructors and cache writers, add the explicit idempotent schema helper, then separately approve removal of the stored due-date column/index. Its exact diff, DDL, verification, and recovery plan must be reviewed before applying it.

No changes to merchant_applications, inventory attributes, owner/actor types, foreign keys, or Group E plaintext-password fields were included.
