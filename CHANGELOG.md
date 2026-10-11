# GenPay Changelog

## October 8, 2026 — Local Development

- **Integrity repairs:** Added the missing parent role and reconnected two fee-waiver logs to credit #131.
- **Temporary passwords:** Cleared 211 stored plaintext values and stopped future storage in the three password columns. Existing email delivery and first-login password changes remain.
- **Legacy tables:** Exported and removed the empty `wallet`, `transaction`, and `topup` tables, along with their old code references.
- **Order items:** Made item tables the official records and kept JSON as historical snapshots. Backfilled 78 cart items, 57 QR items, and 92 receipt items. Preserved deleted-product details.
- **Core database integrity:** Changed 25 user-reference columns to signed integers and added 51 foreign keys. The database now has 66 foreign keys across 59 tables.

### Verification

PHP syntax and migration checks passed. Existing money data, order snapshots, and triggers remained unchanged.

### Still Pending

Orphaned records, remaining non-core foreign keys and ID-type mismatches, and the tuition-reference collation mismatch were left unchanged.

### Rollback Exports

Saved in `storage/backups/`:

- `genpay-legacy-table-definitions-2026-10-08.sql`
- `genpay-order-items-before-2026-10-08.sql`
- `genpay-core-integrity-before-2026-10-08.sql`
