# Group D1 staff-owner integrity — proposed single step

Prepared 10 October 2026 after the user chose to keep the staff fields and strengthen owner integrity. This is the exact proposal for separate schema-step approval and updated full-backup confirmation. The preference selection has not been treated as approval to apply this schema change. No live application code, schema or rows have changed during preparation.

## Change

Keep users.sub_role, position, staff_removed_at and merchant_owner_id in place. Change only merchant_owner_id from nullable INT UNSIGNED to nullable signed INT, matching users.userID; preserve its default NULL and exact existing column comment. Add index idx_users_merchant_owner and FK fk_users_merchant_owner referencing users(userID), ON DELETE RESTRICT ON UPDATE RESTRICT. The exact SQL including the preserved comment is staff-owner-integrity-2026-10-10.sql alongside this report. The exact one-file PHP diff is staff-owner-integrity-2026-10-10.diff.

The logical schema operation is:

    ALTER TABLE users
      MODIFY COLUMN merchant_owner_id INT NULL DEFAULT NULL COMMENT <unchanged current comment>,
      ADD INDEX idx_users_merchant_owner (merchant_owner_id),
      ADD CONSTRAINT fk_users_merchant_owner FOREIGN KEY (merchant_owner_id)
        REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT;

The companion SQL file substitutes the exact quoted comment and is executable; the logical outline above uses a placeholder for readability.

One focused phase is sufficient: there is no table split, data backfill, reader/writer migration, dual-copy state, or later column-removal CONTRACT for staff fields. Existing current behavior, role permissions, staff soft removal, historical audit records and detached NULL owner remain supported. This is integrity hardening; the review did not establish a 3NF violation in the retained staff fields.

## PHP implementation

Add gjc_ensure_staff_owner_integrity_schema(PDO $db, bool $apply=false, ?string $archivePath=null): array in connection/app.php. It is an explicit CLI helper, not invoked by normal page requests. It defaults to a read-only plan and requires ewallet with no caller transaction. It checks identifier types, nullable/default/extra attributes, index/FK definitions and naming/incoming-dependency conflicts. It rejects missing owners, out-of-range/nonpositive/self owners, nonstaff linked accounts, unexpected owner roles, or owner chains for further review rather than silently changing data.

When apply=true, an exact immediate private export of the users schema and all users rows is required. It executes one ALTER and refreshes the users-column cache. If the signed type and exact intended index/FK already exist, it returns an empty plan with no DDL. Sub_role and job-title/removal helpers are unchanged. The nullable owner FK alone enforces existence, not merchant role; the existing application authorization and owner selection remain responsible for role rules.

Complete planned/original source, helper, SQL, rollback and preview results are retained privately in storage/backups/staff-owner-plan-2026-10-10. No staff-management endpoint changes are proposed.

## Current verification

224 users, four staff accounts, three existing valid merchant-owner links, one inactive detached staff account. All owner values fit signed INT. No dangling/self/zero/chain owner links and no owner-field indexes or FKs exist. The candidate app.php passes PHP lint. The explicit helper's actual live read-only dry run passes, and the generated ALTER prepares natively without execution. All 63 table contents/schema fingerprints and database metadata, including both protected triggers, remain unchanged. No browser or staff-mutation test was run.

## Apply and verify after approval

1. Confirm the updated full mysqldump backup and the exact Group D1 staff-owner integrity approval. Keep activity quiet during export and DDL.
2. Recheck exact source and collect fresh private fingerprints, schema metadata, all users rows and staff/owner projections. Run the helper dry run; stop on unexpected schema or ownership data.
3. Install only the reviewed helper addition, lint app.php, and invoke it with the exact immediate export and apply=true. MySQL DDL implicitly commits and is not undone by transaction rollback.
4. Verify every users row is byte-for-byte equivalent in the PDO result, with all four staff rows/three links/detached NULL preserved. Verify all other 62 tables and protected triggers unchanged.
5. Verify metadata differences are precisely the owner signedness change, named one-column nonunique index and RESTRICT/RESTRICT FK. Preserve column position, NULL/default/comment and all other metadata. Compare staff rosters, removal filtering and owner-scoped activity query results with the immediate baseline.
6. Run the helper again as a dry run to confirm its idempotent empty plan and unchanged data. Record the result/report. Browser testing remains deferred; do not claim actual create/remove/payment testing.

## Intended integrity effect

A physical deletion or primary-key change of a merchant owner is rejected while any staff row, including a removed historical staff row, still references that owner. Detachment remains possible by setting the nullable owner field to NULL through an explicitly authorized workflow. The scanned application uses soft staff removal and contains no hard DELETE FROM users. Invalid direct-SQL owner assignments that were formerly possible will be rejected. These are the intended effects of adding an enforced relationship.

## Rollback

Before DDL, revert only the exact new helper addition. After DDL, a separately approved rollback can drop fk_users_merchant_owner and idx_users_merchant_owner, then restore the original nullable INT UNSIGNED definition with its exact comment. Check all CURRENT owner values fit unsigned range before reverting; preserve current rows and staff history. Proposed exact contingency SQL is retained in the private plan directory. No wallet/system_settings updates or historical audit edits are involved. Full database restore is a separate decision accounting for intervening activity.

## Approval requested

Approve Group D1 staff-owner integrity and confirm the full backup is updated. This specific schema-step approval is required by the user's original hard rules 1, 4(e), 5 and 8. After completion, the next area is Group D2 stall applications and rejection history, investigated read-only before any change.