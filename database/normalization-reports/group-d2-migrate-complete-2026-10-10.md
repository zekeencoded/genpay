# Group D2 MIGRATE completed — 10 October 2026

Applied after the user's explicit Group D2 MIGRATE approval and updated-backup confirmation.

## Result

Installed the approved candidates across eleven files: connection/app.php, apply.php, admin/maintenance.php, admin/api/stall_applications.php, admin/api/archived_rejections.php, admin/stall_applications.php, admin/merchant_details.php, merchant/dashboard.php, merchant/contract.php, merchant/doc.php, and merchant/settings.php.

Application readers now use the normalized projection. Application creation and updates synchronize the normalized detail tables and existing columns atomically. Submitted name parts determine proprietor_name. All 61 existing application columns remain during verification; no CONTRACT was applied.

Rejections append history within the application transaction. Reactivation reuses the same application, retains its documents and payment/contract facts, assigns a fresh meeting using existing scheduling rules, and retains the original rejection facts. Scheduling locks remain held through commit. The approved change preserves existing financial constructor statements and bindings.

The locked reconciliation transaction affected zero rows in all eleven statements: the expanded copies already matched the four original applications. No historical rejection or existing shared location was changed.

## Verification

- Eleven installed candidates match the approved files and pass PHP lint.
- All four complete 61-field application profiles exactly match the original records through reconstruction, dual reads, normalized-only reads, and individual fetches.
- 178 simulation cases against the installed helper source passed: 36 updates, 8 creates, 114 rollback injections, 8 reactivations, 8 state guards, and 4 name cases. These use a model database and perform no live mutations.
- 44 distinct SQL statements prepared with native MySQL without execution, including 10 consumer SELECT templates and the locked current-detail read.
- Six financial SQL constructor blocks and their bound-value source remain exact copies.
- All 71 base tables remain present. The 62 unrelated tables, schema metadata, and both protected triggers are unchanged.
- Existing rejection history and location nodes are unchanged. Installed schema helpers pass idempotence checks without changing data or metadata.

## Limits and next phase

Browser flows, uploads, email delivery, actual payment/award execution, and real concurrent requests were not exercised. Browser testing and the POS scanner issue remain deferred at the user's request. Simulation success does not establish browser behavior.

The next phase is Group D2 CONTRACT, requiring its own concrete proposal and separate approval before removing legacy application fields. Dual writes remain enabled now.

## Evidence and recovery

Private evidence and immediate source originals are stored under storage/backups/d2-migrate-2026-10-10, denied to HTTP requests. Files include private-immediate-baseline.json, applied-migrate.json, migrate-verification.json, simulation-results.json, native-sql-results.json, and original/.

Restore the eleven immediate source originals together to revert application routing if necessary. Keep normalized tables and history for review; do not automatically delete any records. No wallet or system operating balance was directly updated. The user's full backup remains the recovery boundary for broader database restoration.
