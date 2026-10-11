# Group D1 staff-field review — read-only

Reviewed 10 October 2026 after violations CONTRACT. No live PHP, database rows, or schema were modified during this review. All 63 table fingerprints and database metadata remained unchanged; both protected triggers are unchanged.

## Current data

224 users, including four merchant staff (roleID 6). Two staff are Active and two Inactive. Three staff retain merchant-owner links; all three owners exist, are roleID 2 / merchant_admin, and have no owner chain. One Inactive staff account retains the previously approved detached NULL owner. There are no dangling owners, self links, zero owner values or values outside signed INT range.

Two staff have the free-text position Cashier, and one staff has a removal timestamp. All position, removal-date and owner values occur on roleID 6 accounts. Other roles currently have none of these three facts. There is no existing staff-profile table. merchant_accounts is a separate table and must not be repurposed merely because its name contains accounts.

users.merchant_owner_id is nullable INT UNSIGNED; users.userID is signed INT. No index or foreign key currently enforces the owner field.

## Functional dependencies and recommendation

Keep sub_role in users as account authorization state. It is not derivable solely from roleID: the three finance accounts all use roleID 4, but two have sub_role cashier and one has super_admin. MintingGuard checks the stored sub_role directly. Login-session fallback mappings, NULL sub_role values and historical audit sub_role snapshots must remain unchanged.

position, staff_removed_at and merchant_owner_id currently describe an individual user or that user's one owner relationship. They depend directly on the user key; no observed non-key field determines another one. Optional fields or table width alone do not make users violate 3NF. A free-text position is descriptive and does not control permissions. Removing a staff account sets users.status Inactive and records the removal date; this is retained account lifecycle/history, not an active-roster flag to derive from status (other inactive accounts were not removed).

Recommended: keep the current staff fields and prepare a focused integrity change aligning merchant_owner_id to nullable signed INT and adding a named indexed owner FK to users.userID. Proposed owner actions should be RESTRICT on delete/update to preserve links and require explicit detachment when needed. This is referential integrity, not proof that the current columns violate 3NF. No data cleanup is needed on the present three owner values, and the detached NULL remains allowed.

Alternative: move position, staff_removed_at and merchant_owner_id into merchant_staff_profiles keyed by signed users.userID, with nullable signed owner_user_id. Keep sub_role/status in users. Backfill all four roleID 6 accounts, including detached/removed states; retain dual writes until a separately approved CONTRACT. This provides an entity split but requires migration of staff management, profile/roster/backup queries, login owner projection, owner resolution, suspension inheritance and tenant activity queries. It offers no additional demonstrated functional-dependency correction over retaining the fields.

A preference question was sent to the user before preparing an exact schema/code plan. No proposed option has been applied. After selecting an option, prepare its exact idempotent app.php helper, SQL, diff, verification and rollback before requesting phase approval and a refreshed backup.

## Affected behavior and sources

- connection/app.php: login session setup, owner resolution, manual/restricted suspension inheritance and position/removal schema setup.
- merchant/api/staff.php: creation, scoped lookup, profile edits, password resets, status changes and soft removal.
- merchant/staff.php, settings.php, view_staff.php, profile.php, profile_edit.php and export_backup.php: roster filtering, job-title display/edit and exports. Existing export queries do not all filter removed rows identically; preserve each query's behavior during any migration.
- admin/dashboard.php and merchant_details.php; connection/MerchantTenantDirectory.php: owner/staff activity and tenant scope.
- connection/MintingGuard.php, audit_logger.php, admin/api/users.php and role-gated admin endpoints: stored authorization sub_role and immutable audit snapshots.
- record.php and persistent-login code share session setup; any split must project owner facts consistently for both password and remember-me login.

Repository search found no hard DELETE FROM users in the scanned live PHP. Staff removal is a soft deletion, preserving user identity and history. An owner FK would constrain physical owner deletion by direct SQL or external code; that intentional integrity effect must be included in its exact proposal.

Private aggregate results, table/schema fingerprints and the read-only script are in storage/backups/staff-review-2026-10-10, which is blocked from HTTP and locally ignored. Browser behavior was inspected in source but not exercised.