# GenPay normalization summary — Groups A–D

Completed scope summary, 10 October 2026, Asia/Manila. Database: ewallet. Environment: disposable local development copy.

The user chose to end changes after Group D. All approved Groups A–D phases are complete. Group E was reviewed and a candidate was prepared, but its MIGRATE and CONTRACT were not approved or applied. This summary introduces no further application or database changes.

## What can be claimed

The reviewed repeating groups, wallet-identity dependencies, scoped address dependencies, duplicated application/archive storage, and selected calculated copies were normalized or removed. The implementation targets 3NF for the reviewed entity structures while retaining explicitly chosen operating values and historical facts.

Do not describe the entire database as formally certified strict 3NF. Normal forms depend on business functional dependencies, not table width, number of tables, foreign keys, or successful tests alone. The whole seventy-table schema has not had every possible dependency proven, and some operating balances/caches are deliberate retained denormalization.

The appropriate defense statement is: “GenPay's reviewed entities were normalized toward Third Normal Form, with documented operating balances and historical snapshots retained to preserve accounting correctness and system behavior.”

## Before/after normal-form assessment

| Area | Before | Completed result | Normalization interpretation |
| --- | --- | --- | --- |
| Application product types | Comma-separated product list | merchant_application_product_types is sole storage | Logical repeating group converted to separate rows |
| Cart and QR items | JSON item arrays alongside item tables | cart_order_items and qr_order_items are sole item storage | Repeating order lines become related rows |
| Wallet-linked identities | Wallet ID plus copied user ID | Wallet links retained; user identity resolved through joins | Removes the wallet → user transitive copy |
| Historical vouchers | Conflicting value/refund duplicates | initial_value and is_refundable retained; two unused copies removed | One stored fact per selected attribute |
| Cap increases | amount_added copied from old/new cap | Calculate new_cap − old_cap | Removes a deterministic stored copy |
| Obsolete school revenue balance | Unused zero aggregate column | Removed; empty legacy ledger retained | Retires unused duplicated state |
| User locations | Repeated province/city/barangay strings | user_addresses and scoped address_locations | Location hierarchy stored through identifiers |
| Manual suspensions | Seven fields in users | user_suspensions, original user fields removed | Separates suspension facts from current account |
| Restricted-product violations | Three duplicated user fields | user_violations, original user fields removed | Removes duplicate violation storage |
| Staff fields | Proposed extraction | Retained meaningful role/position/link facts; owner integrity enforced | No unproven decomposition imposed |
| Stall applications | Sixty-one columns with repeated detail facts | Ten core columns, eight normalized detail/history tables | Applicant, location, document, meeting, payment and decision facts separated |
| Application proprietor name | Stored combined-name copy | Derived from submitted name parts | Removes redundant name assembly |
| Rejection archive | Separate whole-application archive structure | Empty legacy archive dropped; retained application plus rejection events | Avoids duplicate application entities and preserves rejection history |
| Lease due date | Stored calculated copy | Derived from existing rent schedule and payments; column/index removed | One calculation rather than synchronized copy |
| Other merchant application/lease/inventory fields | Wide tables under review | Meaningful entity and contract facts retained | Width alone is not a 3NF violation |

SQL JSON is a supported scalar type; the Group A decision addressed logical repeating item groups and duplicated authoritative representations. This is not a claim that using JSON anywhere automatically makes a database UNF.

## Current schema structure

- Product/application choices: merchant_applications → merchant_application_product_types.
- Orders: cart_orders → cart_order_items; merchant_qr_orders → qr_order_items.
- Buyer/payee identity: orders/transfers/alerts/tuition references resolve users through their retained wallet relationships.
- Accounts: users with user_addresses → address_locations, user_suspensions, and user_violations. Reviewed staff fields remain in users.
- Stall applications: stall_applications with stall_application_applicants, stall_application_addresses, stall_application_documents, stall_application_meetings, stall_application_payments, stall_application_contracts, stall_application_decisions, and stall_application_rejections.
- Leases: merchant_leases retains contract terms and links to stalls/rent payments. Due dates are calculated, not stored.
- Inventory: per-merchant item records, with the existing merchant-scoped SKU key and independent stock, availability, and restriction decisions.

The complete live table/column/type/default/key inventory is in [schema inventory](C:/laragon/www/genpay/database/normalization-reports/genpay-schema-inventory-2026-10-10.md) and [schema JSON](C:/laragon/www/genpay/database/normalization-reports/genpay-current-schema-2026-10-10.json). These include schema metadata and counts, not account records or password values.

## Retained facts and operating values

Historical snapshots:
- transactions.fee_amount, credited_amount, and total_in_circulation record facts at the event under the applicable policy/circulation state.
- topup_requests and parent_topup_requests fee/credited fields retain processing snapshots.
- Contract-era lease business/location names, rent, deposits, and terms remain distinct from current physical stall labels/rates.
- Historical voucher initial_value and is_refundable are retained as ledger-supported facts.
- Suspension classification is retained in normalized suspension storage according to the chosen snapshot semantics.
- Order-item names and prices describe the sale, not today's inventory listing.

Operating values and explicit decisions:
- Wallet balances remain maintained through CirculationEngine.
- system_settings.total_circulation_cap remains an operating control used and locked by the minting/integrity workflow.
- rent_reminder_period records notification progress.
- Inventory availability is an explicit menu decision, not stock_qty > 0.
- Inventory restriction state can reflect manual review, not only today's product-name policy.

Historical facts are not automatically denormalization: an event's fee or contract's price is an attribute of that event/contract. Operating balances and maintained aggregates require explicit documentation because they materialize state.

See the separate [defense note](C:/laragon/www/genpay/database/normalization-reports/genpay-intentional-denormalization-note-2026-10-10.md).

## Live final inventory verification

- Seventy base tables.
- Eighty retired columns verified absent across the completed scope.
- archived_rejections absent.
- Both trg_audit_no_update and trg_audit_no_delete intact.
- Group E remains unapplied: users.temp_password present for 224 users, merchant_accounts.temp_password_plain for two links, stall_applications.temp_password_plain for four applications; all values currently NULL.
- All four Group E candidate target files still match their unapplied original source snapshots.
- Summary inspection changed no data or schema.

The eighty-column total consists of Group A: 3; Group B: 10; Group C: 2; Group D1: 13; Group D2: 51; Group D3: 1. It is a scope accounting total, not a measure of normal form.

The earlier approved orphan cleanup archived and removed three zero-balance, unreferenced merchant wallets. A separately approved missing staff-owner link was archived and detached. These were integrity cleanup steps, not financial balance adjustments.

## Verification and limits

Phase checks used exact row reconstruction, fingerprint comparisons, PHP lint, native statement preparation, and focused model simulations. D2 verified all four sixty-one-field logical profiles and 178 model cases; D3 verified all four lease/account/date/dashboard/CSV outputs and both canonical constructor statements.

Historical financial and audit rows and protected triggers were preserved in the recorded phase verification. No verification payment, award, wallet balance adjustment, or audit rewrite was performed.

Browser testing was explicitly deferred, including CONTRACT approval before those tests. Therefore the completion reports do not certify end-to-end login, password change, submission/upload, payment/award, notifications, CSV download, or concurrent requests. The previously reported POS QR scanner problem remains deferred.

## Final scope status

| Scope | Status |
| --- | --- |
| Groups A–C approved normalization | Complete |
| Group D1 account details/integrity | Complete |
| Group D2 application detail/history normalization | Complete |
| Group D3 review and selected lease due-date normalization | Complete |
| Group E plaintext-column cleanup | Unapplied; excluded at user's chosen stopping point |
| Browser/system acceptance testing | Deferred |
| POS QR scanner repair follow-up | Deferred |
| Final schema inventory and defense documentation | Complete |

Schema helpers are in connection/app.php; exact diffs, supplemental SQL, verification and recovery plans remain in their phase directories. MySQL DDL is non-transactional. Any recovery must preserve subsequent legitimate activity and use its applicable approved plan rather than blindly restoring old financial or audit data.
