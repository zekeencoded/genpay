# Group D2 stall applications and rejection history — read-only review

Reviewed 10 October 2026. No live application code, rows or schema have been changed. All 63 table fingerprints and database metadata remained unchanged, including both protected triggers. Workflow schema and expiry helpers were deliberately not executed because they can alter tables or application state.

## Current records and dependencies

stall_applications has 61 columns and four rows: two awarded, one rejected and one expired. All current_step values are 1, including awarded applications; current_step must not be inferred from status or changed without evidence. archived_rejections has 17 columns and zero rows.

All four applications have populated applicant/contact, address and five document-path fields. All four have meeting dates, locations and sent-email timestamps. Three have contract upload/payment facts; two have awarded merchant links. Existing actor and merchant IDs resolve to users, fit signed INT and retain their historical meaning. Assigned stall IDs resolve to stalls. Unused legacy down-payment, initial-approval and contract-reference/signature fields are NULL in the present four rows; absence of current data is not authorization to remove them.

Existing incoming FKs from merchant_accounts.application_id and payment_verifications.application_id reference stall_applications.id, which is INT UNSIGNED. The existing assigned-stall FK references stalls.stall_id. The core application identity must remain unchanged so these relationships, uploads under application-ID directories and historical audit target IDs stay valid.

Every proprietor_name exactly matches the trimmed first/middle/last/suffix combination. A preference question is pending: derive the combined name while retaining historical name parts, or retain a documented independent snapshot. No field removal will be based on an assumed answer.

## Rejection/reactivation findings

Current admin rejection changes the existing application's status, reason and reviewer/time, then records audit and queues the existing notification. It does not populate archived_rejections. Its current reason can be lost if overwritten during a later rejection/reactivation sequence unless a separate event history is retained.

The old archived-rejections API lists archive rows and creates a NEW application on reactivation using status review, which the current live ENUM does not accept. It also does not reconstruct the name/address/meeting/financial details of the retained application. This differs from the user's already selected behavior: reuse the same application and retain rejection history.

The live status vocabulary is pending_verification, awarded, rejected, cancelled and expired. Preserve all labels and current outputs. Reactivation should retain the application ID and submitted facts, enter pending_verification, and retain every previous rejection event. Do not treat an expired no-show as a rejected application or invent older rejection events not evidenced by current state/history. Backfill the one present rejection from its reason/reviewer/time; preserve expired reason/reviewer facts separately as expiry state.

Repository search found no current page or JS caller of the archived-rejections API, though external/direct API clients cannot be excluded by a repository search. Preserve endpoint/JSON compatibility while implementing the selected identity/history behavior; no unrelated new UI is required.

## Proposed logical direction, not an approved schema plan

Keep a stable core stall_applications header with application ID, business/application identity, status and control timestamps. Separate applicant/contact, scoped address storage, document paths, meeting facts, payment facts, contract facts and current award/cancel/review facts into application-owned records. Add a small rejection-event table keyed by an event ID and the unchanged application ID, retaining reason, actor and date plus reactivation provenance. Avoid copying the full application into rejection rows.

Use the existing scoped address_locations hierarchy for province/city/barangay while keeping street and free-text forms. Preserve exactly blank/NULL/address behavior of the maintenance-created applications, not only today's four populated public-form rows. Application addresses and applicant/contact/business facts remain historical submitted facts; do not replace them with current merchant/user profile values.

Represent the five submitted document kinds with unique application/type rows; compatibility projections expose the same path keys. Preserve required-document validation, upload directory identity, draft moves/rollback, file MIME/size checks and merchant document precedence. Contract upload facts remain distinct from the five identity/business documents.

Keep historical cash deposit/advance, rental start and payment schedule values. Do not recalculate them from current stall rates or lease settings. Preserve the unused historical down-payment fields initially in the payment details and the current nullable legacy contract/approval fields in their relevant detail records. Keep the cleared temp_password_plain outside this group for the separately scoped Group E cleanup. The split is an entity-model improvement; table width alone is not proof of a 3NF violation. Scoped location dependencies and avoiding whole-row rejection duplication are concrete normalization targets.

New application identifiers must match unsigned stall_applications.id. New user/actor/merchant-owner identifiers must be signed INT matching users.userID, with separately proposed FK policies. Preserve optional NULL actors for system expiry. Financial references use RESTRICT. The exact DDL and all type alignment will be reviewed before approval.

## Compatibility and risks to resolve in the exact plan

EXPAND adds idempotent app.php schema helpers and new tables, with lossless backfill and no removal or live routing switch. MIGRATE updates all affected reads/writes and retains dual writes during verification. CONTRACT removes only exactly reconstructable old fields and the obsolete empty archive after separate approval and a refreshed full backup. Aim for one complete phase per stage, avoiding arbitrary per-endpoint mini-batches; actual verification failures must be resolved before contraction.

The existing workflow schema helper performs enum-widening/narrowing ALTER statements on every invocation and contains older archive-and-delete migration logic. Those patterns can implicitly commit a caller transaction or conflict with retained application identities. The complete MIGRATE must make installed-schema setup genuinely idempotent and route legacy rejected states to retained history rather than deleting the application. Preserve the current final ENUM vocabulary and old-stage meaning where relevant.

Current rejection and some other transitions first fetch then write without locking the whole operation. The normalized migration needs one atomic application transition with caller transaction/savepoint support, preserving audit/notification effects after successful state changes. Simulate failures/concurrency without generating live awards, wallets, leases, payments or messages during verification. Wallet operations remain governed by CirculationEngine; historical transactions/systemic audit remain immutable.

## Affected sources

connection/app.php (schema, expiry, meeting assignment/capacity, status helpers and queue count); apply.php and apply_upload.php (submission/upload flow); admin/api/stall_applications.php and archived_rejections.php; admin/stall_applications.php; admin/maintenance.php; admin/merchant_details.php; merchant/dashboard.php, contract.php, doc.php and settings.php. connection/mailer.php consumes the compatibility applicant fields. connection/StallManager.php and public stalls.php participate in stall/meeting UI; inspect their relevant query dependencies during the exact migration proposal. Keep audit keys, merchant_accounts and payment_verifications application references intact.

Private record exports, aggregate field-presence counts, FK/index metadata and before/after fingerprint evidence are retained under storage/backups/stall-application-review-2026-10-10, protected from HTTP and locally ignored. No actual browser application/rejection/reactivation/award flow has been exercised.

## Next

Resolve the combined-name preference, then prepare the exact Group D2 EXPAND schema, helper diff, backfill, verification and rollback. No EXPAND or application repair is approved by the instruction to continue this read-only review.