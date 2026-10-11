# Group D3 lease due-date CONTRACT completed — 10 October 2026

Applied after explicit approval: “Approved for Group D3 CONTRACT before browser testing; backup updated.”

## Result

Removed merchant_leases.next_due_date and its single-column idx_lease_due index using the approved ALTER TABLE. The lease table now has fifteen physical columns. Total base tables remain seventy.

Installed the exact five approved candidates:
- connection/app.php: explicit CLI, default-read-only, idempotent CONTRACT helper.
- connection/MerchantTenantDirectory.php: synchronization retains its calculated return value; no cache write occurs after removal.
- admin/leases.php: rent roll uses calculated dates and stops repairing the removed column.
- admin/api/leases.php: canonical lease creation omits the cache field/value; legacy-layout compatibility remains guarded. Missing lease schema is reported instead of recreating the retired cache.
- admin/api/stall_applications.php: canonical award lease creation omits the cache field/value while retaining the original contract bindings.

Lease names, contract terms, IDs, remaining dates, rates, deposits, statuses, notification markers, payments and foreign keys are retained. No wallet or operating balance was updated; historical transactions and audit records were untouched.

Group D3 review and its selected due-date MIGRATE/CONTRACT are complete. No further entity split was justified by that review. The observed unsigned actor/owner reference columns were documented as a separate integrity concern; no type/FK change was included.

## Verification

| Check | Result |
| --- | --- |
| Physical lease columns | 15 |
| Base tables | 70 |
| Other tables with exact schema/data fingerprints | 69 unchanged |
| Complete lease API profiles | 4 of 4 match |
| Rent accounts and calculated dates | 4 of 4 match |
| Merchant dashboard results | 4 of 4 match |
| Eight-cell CSV rows/order/types | 4 of 4 owner exports match |
| Rent-roll accounts/dates | 4 of 4 match |
| Compatibility date-method calls | 4 read-only calls match |
| Injected stale-date projections | 4 pass |
| Directory card output | Unchanged |
| Missing lease/owner and lease-end fallback | Passed |
| Canonical constructor statements | 2 prepared natively without execution |
| Schema helper after CONTRACT | Empty pending plan |
| Protected triggers | Both unchanged |

The only metadata changes are removal of the date column/index and adjustment of remaining lease-column ordinals. All remaining lease values and timestamps are unchanged. Rent-payment, financial, and audit table fingerprints remain exact. Verification itself changed no data or metadata.

All five installed files match approved candidate bytes and pass PHP lint. Installed-source model simulations passed four synchronization cases and four constructor cases across legacy/canonical layouts. Constructor bindings preserve all original values except the removed cache value; placeholder counts match.

## Limits

Browser testing remains deferred by explicit approval. Real lease creation, payment, award, notification, ZIP download, and concurrent-request flows were not exercised. Read-only database/output comparisons and model checks do not prove those end-to-end behaviors.

Legitimate lease writes continue to update timestamps. A lease timestamp no longer changes solely to synchronize a calculated cache.

## Evidence and recovery

Fresh private snapshots, source originals, original lease DDL, applied-contract.json, contract-verification.json, simulation-results.json, and constructor-sql.json are under storage/backups/d3-contract-2026-10-10, denied to HTTP and locally ignored.

The approved plan and recovery artifacts remain under:
C:/Users/Ezekiel/.codex/visualizations/2026/10/09/01a11e42-5059-7012-a15b-ddc6ef52d71a/genpay-group-d3-contract-2026-10-10

DDL is non-transactional. Recovery requires a separately approved action in a quiet window. Capture CURRENT calculated dates and updated_at, add a temporary nullable date column in its original position, populate it using parameterized updates preserving updated_at, restore DATE NOT NULL and idx_lease_due, then restore the five immediate source originals. The provided recovery script defaults to a dry run and requires an explicit recovery flag.

Retain all legitimate subsequent payments and lease activity. Do not automatically overwrite financial or audit history with an older full backup.

## Next scope

Group E: read-only review of users.temp_password, merchant_accounts.temp_password_plain, and stall_applications.temp_password_plain, including remaining readers and writers before any separately approved removal. No Group E investigation or changes were made in this phase.
