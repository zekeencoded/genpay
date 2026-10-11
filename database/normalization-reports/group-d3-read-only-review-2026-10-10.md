# Group D3 read-only review — 10 October 2026

Reviewed merchant_applications, merchant_leases, and merchant_inventory using current live SELECT/SHOW/information_schema queries and repository PHP/JS/SQL searches. No application code, schema, or live data was modified. MySQL was initially stopped; the user started Laragon and the live review then completed.

## Current tables

| Table | Rows | Physical columns | Assessment |
| --- | ---: | ---: | --- |
| merchant_applications | 1 | 21 | Submitted application facts and workflow facts. Product types already normalized by Group A. |
| merchant_leases | 4 | 16 | Contract facts, stall link, and operational values. next_due_date is a stored calculated copy. |
| merchant_inventory | 11 | 18 | Per-item attributes and explicit availability/restriction decisions. No further transitive dependency established. |

Width alone does not establish a normal-form violation. The code and current data do not justify splitting these tables simply to reduce column counts. This review does not prove all possible functional dependencies from the small dataset.

## Merchant applications

The single row is submitted. All compliance, executive, approval, rejection, and generated-account IDs are NULL. One normalized product-type row exists; the former product_types column is absent.

The current runtime search found application references in the retained Group A normalization helpers, but no active onboarding page/endpoint directly writing this legacy pipeline. Historical dump/migration SQL still references it. This is not authorization to delete the table or its application.

Owner/business/contact fields represent facts submitted with an application. A future generated user account is a separate entity; current profile changes must not rewrite submitted application facts. No duplicate current-account dependency was established. Preserve the application and existing workflow facts.

## Leases

All four leases resolve to existing users and merchant profiles. All four stall links resolve to the registry. All four contract-era stall_name values differ from the current physical stall label, consistent with the earlier decision to preserve contract names and free-text location input. Do not replace those historical contract facts with current stall labels.

Monthly rent and deposit are contract terms. Neither should be recomputed from current stall prices. Lease status can be manually pending, active, expired, or terminated; it is not solely a calculation from the end date. rent_reminder_period records notification progress, not a copy of a tenant or stall attribute.

next_due_date is calculated by MerchantTenantDirectory::leaseAccount from contract billing dates and merchant_rent_payments. Stored values match the derived result in all four leases as of 10 October 2026, Asia/Manila.

The cache still has direct consumers and writers:
- connection/MerchantTenantDirectory.php reads directory cards, exposes the derived date, and synchronizes the physical column after payment/contract changes.
- admin/leases.php derives the schedule and repairs the stored column on page load.
- admin/api/leases.php writes an initial value on lease creation and synchronizes on updates.
- admin/api/stall_applications.php supplies the initial value when awarding an application.
- merchant/dashboard.php displays the stored date.
- merchant/export_backup.php exports the stored date.

Removing the copy requires migrating all of those consumers/writers and preserving the existing lease-end fallback once no billing period remains. Do not drop the column alone. The decision is whether to derive everywhere or retain/document the operating cache. Neither requires splitting the rest of the lease contract.

## Inventory

All eleven inventory owner IDs resolve to users and merchant profiles. Optional approved_by and restricted_by fields are NULL in every current row. There are no duplicate (merchant_user_id, sku) pairs. Current SKUs are all populated and also happen to be distinct across merchants; the actual unique key remains scoped to the merchant. Current global uniqueness is not evidence for changing SKU scope.

Category does not determine price or unit: street food has six items, six price values, and two unit values; food has two items, two prices, and two units. Extracting category into a lookup would not remove an established transitive dependency among these attributes.

All eleven items are available; one has zero stock. is_available is an independent menu choice. Keep it separate from stock_qty rather than deriving it as stock_qty > 0.

All items are currently unrestricted and no current active name-based restriction matches them. Nonetheless, code supports manual administrative restriction/clearance and records notes, actors, and timestamps. is_restricted and restriction_note cannot safely be replaced by a fresh match against today's restricted-products policy without changing manual decisions and historical context.

Inventory is used by merchant menu/stock management, finance restrictions, POS, student/parent cart and checkout, CirculationEngine stock handling, exports, and print/view pages. Product names and prices copied into order-item records remain historical sale facts and were handled in Group A; they must not be replaced with current inventory values.

## Referential integrity observations

Ten intended user/actor reference columns across these tables are unsigned INT while users.userID is signed INT:
- applications: compliance_by, exec_by, approved_by, rejected_by, generated_user_id;
- leases: merchant_user_id, created_by;
- inventory: merchant_user_id, approved_by, restricted_by.

All populated references resolve and fit signed INT. These columns currently have no actual user foreign keys; comments alone do not enforce them. This is an integrity observation, not proof of a 3NF violation. Type alignment/FK additions would require a separate exact proposal, delete-workflow compatibility review, and approval. No cleanup, actor reassignment, or FK addition is included in this review.

Existing incoming application-product and order-item FKs and the lease-to-stall FK remain unchanged.

## Evidence and limitations

Live fingerprints confirmed all 70 base tables and both protected triggers remained unchanged during the investigation. Private evidence is in storage/backups/d3-review-2026-10-10, protected from HTTP and locally ignored: private-review-baseline.json, read-only-findings.json, private-lease-due-date-comparison.json, and reference-scan.txt.

Only read-only schedule methods were called. No endpoint, restriction rescan, expiry, due-date synchronization, payment, email, or financial writer was executed. Browser tests remain deferred. Application schema and row evidence reflect the live database, not the earlier dump.

## Recommended next decision

Derive next_due_date everywhere and remove its stored copy through a separately proposed and approved MIGRATE/CONTRACT sequence. Existing calculations already produce the current four dates, so this targets a known duplicate without decomposing unrelated item or contract facts.

Alternatively, keep next_due_date explicitly documented as an operating cache. Preserve the three tables otherwise; no further entity split is currently justified.
