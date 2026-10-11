# Group E read-only review — 10 October 2026

Reviewed the final plaintext-password storage group. No live code, schema or data changes were made.

| Field | Rows | Non-NULL | Nonempty |
| --- | ---: | ---: | ---: |
| users.temp_password | 224 | 0 | 0 |
| merchant_accounts.temp_password_plain | 2 | 0 | 0 |
| stall_applications.temp_password_plain | 4 | 0 | 0 |

All three fields are nullable VARCHAR(100), default NULL, with no column indexes. No plaintext values were displayed or exported during the review.

Current runtime references only write NULL to the database fields. Password changes, award account creation, maintenance provisioning, and merchant-account linking still explicitly reference them. The first-login schema helper could recreate users.temp_password after removal. Application projection/default/write helpers also include temp_password_plain.

The generated temporary credential is still used in memory for hashing and first-use display/notification. That workflow is separate from the unused database copies and is retained to preserve account creation behavior.

MIGRATE requires four code files: connection/app.php, change_password.php, admin/api/stall_applications.php, and admin/maintenance.php. It removes NULL-only database dependencies, stops recreation, preserves hashing/first-login behavior, and retains a NULL compatibility field in the application projection. No EXPAND or data backfill is needed.

After separate MIGRATE approval and verification, prepare CONTRACT with an explicit idempotent schema helper to drop the three empty fields. Do not combine approval boundaries.

All seventy table fingerprints, metadata and both protected triggers remained unchanged. The reviewed SQL dumps/old migration scripts are historical artifacts, not current runtime writers. Private counts and fingerprints are under storage/backups/group-e-review-2026-10-10, denied to HTTP and locally ignored.

Plaintext credential storage is a security cleanup, not by itself proof of a normal-form violation. Removing these unused copies completes the requested Group E scope while retaining hashed authentication.

Exact MIGRATE proposal:
C:/Users/Ezekiel/.codex/visualizations/2026/10/09/01a11e42-5059-7012-a15b-ddc6ef52d71a/genpay-group-e-migrate-2026-10-10/group-e-migrate-proposal.md
