# Group D2 CONTRACT completed — 10 October 2026

Applied after explicit approval: “Approved for Group D2 CONTRACT before browser testing; backup updated.”

## Result

Removed 51 legacy fields from stall_applications in the approved single ALTER TABLE. Removed the empty archived_rejections table. The eight normalized application detail/history tables now hold the corresponding facts; the logical projection preserves the existing 61-field application shape.

stall_applications retains ten physical columns: id, stall_id, business_name, terms_accepted, status, current_step, created_at, updated_at, temp_password_plain, preferred_stall_id. The cleared password field remains for the separately scoped Group E review. Four core indexes remain; the old email index disappeared with its column.

Installed only the approved connection/app.php candidate. The explicit CLI CONTRACT helper validates the normalized records and dependencies before applying DDL and returns no pending plan afterward. The former archive schema helper now validates normalized rejection history and cannot recreate the retired archive.

Application writers automatically use normalized-only storage after removal. Rejections retain history; reactivation retains the same application and its document/payment/contract facts and schedules a fresh meeting. The Group D2 EXPAND, MIGRATE, and CONTRACT phases are complete.

## Verification results

| Check | Result |
| --- | --- |
| Physical core columns | 10 |
| Removed copied/derived columns | 51 |
| Complete logical application profiles matched | 4 of 4 |
| Individual application fetches matched | 4 of 4 |
| Locked current-profile fetches matched | 4 of 4 |
| Preserved tables with exact data/schema fingerprints | 69 |
| Total base tables after empty archive removal | 70 |
| Incoming application foreign keys retained | 10 |
| Protected triggers unchanged | 2 |
| Retained core indexes | 4 |
| Installed-source simulation cases passed | 178 |
| Distinct normalized SQL statements prepared without execution | 33 |
| Consumer SELECT templates prepared | 10 |
| Original financial constructor/binding blocks preserved | 6 |

All normalized records, rejection history, shared locations, financial tables, and audit data are unchanged. Core values and timestamps are unchanged. Only the approved legacy columns/email index and empty archive metadata disappeared. Schema helper idempotence checks changed no data or metadata. The installed candidate matches the approved source and passes PHP lint; the other ten previously migrated files still match their approved candidates.

The 178 simulations cover 36 updates, 8 creates, 114 injected rollback failures, 8 reactivations, 8 state guards, and 4 name cases, using the installed helper source with a model database. Native preparation validates SQL against the actual contracted schema without executing financial writes.

## Remaining validation and next scope

Browser testing remains deferred by explicit approval. Actual browser submission/upload, email delivery, rejection/reactivation, payment/award, and concurrent requests have not been exercised; the read-only and model checks do not establish those behaviors. The previously deferred POS scanner issue remains outstanding.

Next normalization scope is Group D3: read-only review of merchant_applications, merchant_leases, and merchant_inventory. No Group D3 investigation or change was applied in this CONTRACT phase. Overall normalization work is not yet complete.

## Evidence and recovery

Private immediate source, application profiles, metadata/fingerprints, original core/archive DDL, applied-contract.json, contract-verification.json, simulation-results.json, canonical-write-sql.json, and native-sql-results.json are stored under storage/backups/d2-contract-2026-10-10. This directory is denied to HTTP requests and locally ignored.

The approved proposal and exact recovery artifacts remain at:
C:/Users/Ezekiel/.codex/visualizations/2026/10/09/01a11e42-5059-7012-a15b-ddc6ef52d71a/genpay-group-d2-contract-2026-10-10

DDL is non-transactional. Recovery requires a quiet window and a separately approved action. Preserve current normalized profiles, restore original compatibility-column definitions/positions and email index, populate them from current normalized facts while preserving updated_at, recreate the empty archive if necessary, and restore the immediate pre-CONTRACT helper source. Keep normalized records and rejection history. The dry-run-by-default rollback artifact requires an explicit recovery flag. Do not automatically replace subsequent financial or audit activity with an older full backup.
