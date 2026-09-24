# GenPay — System Manuscript

*How everything works, feature by feature — the coding logic behind the whole system.*

---

## 1. The big picture

GenPay is a **closed-loop campus e-wallet** for General de Jesus College. Students load pesos into a digital wallet and spend them at canteen stalls; merchants rent those stalls, sell through a POS, and cash their earnings back out at the finance office; parents supervise their children's wallets; visitors get temporary QR vouchers; and the Finance admin runs the whole economy.

There is **no framework**. The stack is plain PHP 8 + PDO + MySQL (database `ewallet`), Bootstrap 5 for layout, DataTables for tables, and vanilla JavaScript with `fetch()` for interactivity. Every page follows the same lifecycle:

1. `require` the foundation files (`connection/config.php`, `pdo.php`, `app.php`).
2. Call `gjc_require_role([...])` — if the logged-in user isn't the right role, they're bounced to login before anything renders.
3. Run any `gjc_ensure_*_schema()` calls the page needs (more on this self-migrating pattern below).
4. Query the data, then render HTML with PHP templating.
5. Interactive actions (pay, award, approve) don't reload the page — the page's JavaScript POSTs to a small **JSON API file** (e.g. `student/api/checkout.php`, `merchant/api/pos.php`) which does the work inside a database transaction and returns `{success, message, ...}`.

So the system is really two layers: **page files** that render screens, and **API files** that move money and change state. Both share the same helper library.

---

## 2. The foundation layer (`connection/`)

### 2.1 `config.php` — paths and URLs

Defines `BASE_PATH` (the folder on disk) and `BASE_URL` (protocol + host + project folder, detected from the request so the same code works on `localhost` and a real domain). Everything else — `CSS_URL`, `ICONS_URL`, `ADMIN_URL`, `STUDENT_URL`, etc. — is derived from those two, so no file ever hardcodes a path.

### 2.2 `pdo.php` — the database connection

A tiny `Database` class builds one PDO connection with three important options: exceptions on error (`ERRMODE_EXCEPTION`, so a failed query throws instead of silently returning false), associative-array fetches by default, and **real prepared statements** (`EMULATE_PREPARES => false`) — the primary SQL-injection defense used consistently across the entire codebase. The global `$db` created here is what every page and API uses.

### 2.3 `app.php` — the shared toolbox (~2,300 lines)

This is the heart of the shared logic. Its most important ideas:

**Roles and sessions.** A user's `roleID` maps to a role name via `gjc_role_name()`: 1 = student, 2/5/6 = merchant, 3/4 = finance, 7 = parent. On top of that sits a `sub_role` string (`merchant_admin`, `merchant_staff`, `super_admin`, `parent`) for finer distinctions. `gjc_require_role(['finance'])` is the gate at the top of every protected page — it also re-checks the database on **every request** to force-logout accounts that were deactivated mid-session, and redirects anyone flagged `force_change` to the change-password screen no matter what page they asked for.

**The self-migrating schema pattern.** Instead of a migrations folder you must remember to run, the schema evolves at runtime. Functions like `gjc_ensure_stall_application_workflow_schema()`, `gjc_ensure_operational_tables()`, and `gjc_ensure_parent_schema()` run at the top of the pages that need them. Each one:
- creates its tables with `CREATE TABLE IF NOT EXISTS`,
- diffs the live column list (via `information_schema`, cached per request) against a PHP array of required columns and `ALTER TABLE ADD COLUMN`s anything missing,
- widens/narrows ENUMs and remaps legacy status values in idempotent steps.

Because every step is a no-op when already applied, calling them on every page load is safe, and a fresh database bootstraps itself just by using the app. This is why features like the stall-application "New" badge only needed two lines added to an `$adds` array to get their columns.

**Wallet helpers.** `gjc_student_wallet()` and `gjc_merchant_wallet()` fetch a user's wallet row and **create it on first touch** (`INSERT IGNORE ... balance 0`), so no signup flow ever has to remember to make a wallet. `gjc_merchant_owner_id()` resolves a merchant *staff* login to their *owner's* user id, so staff always operate the owner's wallet and inventory, never their own.

**Reference numbers.** `gjc_reference('TOP')` produces IDs like `TOP-20260709-3FA2C1` (prefix + date + random hex). Every money movement gets one, and the reference is deliberately reused across related records (a cart order and the payment that settles it share one reference) so a human can trace a transaction end-to-end.

**The restricted-product matcher** (explained with the merchant inventory in §8.3) and the transaction display helpers (`gjc_build_transaction_row`, `gjc_transaction_sender_receiver`) that turn raw ledger rows into human-readable "sender → receiver" lines also live here.

### 2.4 `audit_logger.php` — the append-only audit trail

`logAudit()` writes every significant action (LOGIN, TRANSACTION, MINT_ATTEMPT, ACCESS_DENIED, DOCUMENT_VIEW, …) into `systemic_audit_trail` with the actor and their sub-role, the target record, the outcome, a reason, the before/after values as JSON, the originating endpoint and method, IP, user agent, and a per-request correlation ID that ties together the several entries one action can produce.

`AUDIT_ACTION_TYPES` in this file is the **single** list of valid action types — the column is a `VARCHAR`, and `admin/audit_log.php` builds its filter from the same constant. An unrecognised type is refused and named, with the calling `file:line`, in the PHP error log. It used to be three hand-maintained lists that drifted apart, and a type missing from the PHP one meant the entry was silently never written.

Four design decisions matter:
- It uses a **separate PDO connection** owned by `gjc_audit_writer`, a database account with `INSERT` and `SELECT` on this one table and nothing else. There is **no fallback to the app connection** — if the audit credentials are missing, logging stops and says so, rather than quietly downgrading to an account that could rewrite what it writes. Credentials come from the gitignored `connection/audit_config.php`.
- The table is **append-only in the database**, not by convention: `BEFORE UPDATE` and `BEFORE DELETE` triggers `SIGNAL` an error. Each entry also carries `prev_hash`/`entry_hash`, a chain that `gjc_audit_verify_chain()` walks to find the first entry that no longer matches its own contents or its predecessor. That makes interference **detectable**, not impossible — a DBA with root can still drop the triggers, but not without breaking the chain.
- Failures are **logged, never silent, never fatal**. The `try/catch` around every call now writes the action type, actor and error to the PHP error log. An audit failure still must never break the workflow it's auditing — but it must never be invisible either.
- Entries raised **inside an open transaction are queued**, not written. The audit connection autocommits, so an entry written mid-transaction survives a rollback — which is how you get an audit record of a mint that never happened. `gjc_audit_commit()` releases the queue as-is; `gjc_audit_rollback()` releases it marked `failure`; a shutdown handler flushes anything left as `unconfirmed`.

Payloads are redacted on write: any key matching `password|pass|pwd|pin|otp|token|secret|remember|api_key` is replaced with `[REDACTED]` unless the value is flag-shaped (a boolean, or 0/1 — `force_password_change: 1` carries no secret and redacting it would throw away the entry's only signal). Payloads over 16 KB are truncated with a marker rather than overflowing the column and losing the entry.

Schema changes are the exclusive job of `database/migrations/audit_hardening.php`, which runs as root from the CLI. Nothing in the request path issues DDL any more — `gjc_ensure_audit_table()` is a cached read-only check that shouts into the error log if the migration hasn't been run.

The admin's Audit Log page is a read-only viewer over this table, restricted to the **super-admin** sub-role: it records what every other administrator did, and its payloads carry student and guardian personal details.

### 2.5 `mailer.php` + `mail_worker.php` — asynchronous email

All email goes through `gjc_queue_email()`: the message is written as a JSON file into `storage/mail_spool/` (~1 ms) and a detached background PHP worker is spawned to actually send it via Gmail SMTP. The worker holds a lock file (single instance), reuses **one** SMTP connection for a whole batch, retries failures 3 times 15 seconds apart, parks permanent failures in `failed/`, and logs to `mail.log`. If the spool can't be written, the code falls back to a synchronous send so no email is lost. This is why Submit/Award/Reject respond instantly instead of hanging 2–5 seconds on the SMTP handshake.

The Gmail address and its App Password come from the gitignored `connection/mail_config.php` (template: `mail_config.example.php`), or from the `MAIL_USER` / `MAIL_PASS` environment variables. If neither is set, `gjc_mailer()` throws before the worker claims any spool file, so queued emails wait in `storage/mail_spool/` and go out once the credentials are added, rather than using up their retries on login failures.

---

## 3. The economy — `CirculationEngine.php`

This class is the financial core. It models the campus economy like a tiny central bank with one **invariant** that must hold after every operation:

```
total_circulation_cap  =  cashier_vault  +  Σ student wallets  +  Σ merchant wallets  +  Σ active voucher balances
```

Money is never created or destroyed by a transaction — it only *moves* between those four pools. The cap only grows through an explicit, logged **minting** action.

**How every engine method is built** (they all share the same skeleton):
1. `beginTransaction()`.
2. `lockSettings()` — `SELECT * FROM system_settings WHERE id = 1 FOR UPDATE`. Because every money operation locks this singleton row first, all money movements in the whole system are **serialized**; two simultaneous payments can't interleave.
3. Lock and validate the source wallet (`SELECT ... FOR UPDATE`, balance check).
4. Do the balance `UPDATE`s.
5. `validateCirculation()` — re-sum all four pools and compare to the cap. If the drift exceeds ₱0.01, **throw and roll back the whole transaction**. This is a self-auditing tripwire: a bug that would corrupt the economy aborts instead of persisting.
6. `logTransaction()` — insert an immutable ledger row into `transactions` with a reference number, the vault balance before/after, and the total circulation at that moment (so the ledger doubles as a historical audit of the invariant), then `logAudit()`.
7. `commit()`, or roll back on any exception.

**The operations:**

- `cashIn` / `cashInWithFee` — a top-up: vault → student wallet. The fee version implements the service-fee model: **2% system fee** (stays in the vault as revenue) and, when a *merchant* performs the load, an extra **1% merchant commission** credited to the merchant's wallet. Rounding remainders are deliberately assigned to the credited amount so the books always sum exactly. Fees are also written to `fee_revenue_log`.
- `studentPay` — student wallet → merchant wallet (the vault is untouched; the ledger row records it anyway).
- `merchantSendToStudent` — the "GCash-style" load: a merchant loads a student's wallet **from the merchant's own balance**, with the same 2%/1% fee split (the merchant effectively earns back 1%).
- `merchantSettle` / `studentSettle` — encashment and student withdrawal: wallet → vault, mirroring a cashier handing over physical money.
- `createVoucher` / `voucherPay` / `expireVoucher` — visitor money: vault → voucher on issue; voucher → merchant on spend; unspent balance recycles to the vault on expiry. Vouchers are explicitly non-refundable (change stays on the voucher).
- `increaseCirculationCap` — minting. Requires a written justification, raises cap and vault together, and logs to `cap_increase_log`.

**`MintingGuard.php`** wraps minting with policy: a **soft limit** of ₱50,000/month (beyond it, a super-admin PIN is required) and a **hard limit** of ₱500,000/month that cannot be exceeded at all.

**`VoucherEngine.php`** is the visitor-facing wrapper: creates vouchers with a hashed QR code (`qr_code_hash` peppered with a constant), lists/expires them, and feeds the admin Visitors page (`admin/visitors.php`), voucher printing, and the merchant's `scan_voucher` API.

The **circulation widget** (`includes/circulation_widget.php`), embedded in the admin economy page, renders this live: cap vs. vault vs. student/merchant/voucher pools as percentages, a drift indicator ("balanced" when < ₱0.01), and the month's minting against the soft limit.

---

## 4. Login, roles, and account security

**Login** (`login.php` → `record.php` `Record::loginUser()`): looks the user up by email, verifies the password with `password_verify()` (bcrypt) — with a `hash_equals()` plaintext fallback for legacy rows — then refuses deactivated accounts, and builds the session: `userID`, `roleID`, `sub_role` (from the DB column, or a soft remap from `roleID` for legacy users), `merchant_owner_id`, and a friendly `role` name. Every attempt is audited — successes as `LOGIN`, wrong passwords as `LOGIN_FAILED`.

**Forced password change**: merchant/parent accounts are created with a temporary password and `force_password_change = 1`. Login detects this and sets `$_SESSION['force_change']`; from then on, `gjc_require_role()` on *every* page redirects to `change_password.php` until a new bcrypt password is saved, which clears all the first-login flags and wipes the stored temp password. Payment APIs additionally refuse to move money while `force_change` is set.

**Recurring security idioms** you'll see everywhere:
- Prepared statements for every query (no string-built SQL with user input).
- Ownership scoping in every merchant/student query (`WHERE merchant_user_id = ?`, `WHERE user_id = ?`) so one account can never read or mutate another's rows.
- Debits written as **conditional updates** — `UPDATE ... SET balance = balance - ? WHERE id = ? AND balance >= ?` — then checking `rowCount()`. The balance check and the deduction are one atomic statement, so two simultaneous payments cannot overdraw a wallet even without an explicit lock.
- File uploads validated three ways: size cap (5 MB), extension whitelist, and a **server-side MIME sniff** via `finfo` (a renamed `.exe` fails the sniff).
- `admin/doc.php` — a document proxy so uploaded files are never linked directly: it requires a finance session, strips traversal (`..`), forces the path to resolve inside `uploads/` via `realpath`, and serves with safe headers (images get wrapped in a fit-to-screen HTML shell for the iframe viewers).

*Honest caveats a reviewer would flag:* `gjc_e()` is named like an HTML escaper but currently just casts to string (output escaping relies on `htmlspecialchars` where used explicitly); the legacy plaintext-password fallback in login should eventually be removed; and the database credentials are still hardcoded in `pdo.php` rather than read from a gitignored config file or environment variables, the way the audit and SMTP credentials now are.

---

## 5. The public site and the stall-rental pipeline

### 5.1 Stall map — `stalls.php` + `StallManager.php`

The market is a fixed 2×5 grid (rows A–B, columns 1–5). `StallManager::allStalls()` joins stalls to their merchant and logo for the visual map. The interesting logic is the **15-minute application lock**: when an applicant picks a stall, `lockStall()` runs a *conditional* UPDATE (`SET status='pending_application' WHERE status='vacant'`) — if two people click at once, exactly one UPDATE matches and the loser is told the stall was taken. `flushExpiredPending()` runs at the top of every page render and releases locks whose 15 minutes lapsed — an "application-level cron" that needs no scheduler.

### 5.2 Applying — `apply.php`

A long public form validated field-by-field server-side (name lengths, `09XXXXXXXXX` phone regex, email filter, required T&C scroll-through), with five document uploads validated as described in §4 and stored under `uploads/stall_applications/{id}/`. On success, inside a transaction, the application row is inserted with status `pending_verification`. Then — *after* commit — two things happen:

- **Auto-scheduling**: `gjc_assign_meeting_slot()` books the earliest free verification meeting. The slot-finding core (`gjc_compute_next_meeting_slot`) is a pure function — next business day onward, 8 one-hour slots per day (8–11 AM, 1–4 PM), skipping weekends and the admin-managed `meeting_holidays` table, one applicant per slot. The find-then-write is serialized under a MySQL **named advisory lock** (`GET_LOCK('gjc_stall_scheduler')`) so two simultaneous submissions can't be handed the same slot; it must run outside a transaction so the winner's booking is visible to the next lock holder immediately.
- **Confirmation email** (queued, async) with the meeting date/time/location and a reminder to bring original documents.

Finally the page uses **POST–Redirect–GET**: the success data goes into a one-time session flash and the browser is redirected, so refreshing the page can't double-submit the form.

### 5.3 Admin processing — `admin/stall_applications.php` + its API

The admin page is a one-stop workspace: KPI cards, a "Today's Schedule" list of meetings in time order, and a DataTables list of applications where clicking a row expands an inline accordion panel (rendered client-side from a JSON snapshot of all applications, `APPS`). Newly submitted applications carry a gold **New** badge driven by a `first_viewed_at` column — the first expansion calls the `mark_viewed` API action, which stamps it (`WHERE first_viewed_at IS NULL`, so only the first open counts) and the badge disappears for every admin thereafter.

Everything happens at one in-person meeting, and the API (`admin/api/stall_applications.php`) reflects that with four actions:

- `upload_contract` — validates the scanned signed contract (size/extension/MIME), saves it, and replaces any earlier upload.
- `record_payment` — stores the 2-month deposit, 1-month advance, rental start date, and the recurring schedule day (15th or 30th).
- `award` — the big one, gated on contract + payment being complete. In **one database transaction** it: locks the stall row `FOR UPDATE` and confirms it is still vacant (throwing typed sentinel errors like `STALL_TAKEN` that map to friendly messages); creates the merchant's **user** account with a random temp password (bcrypt + forced change); creates the **merchant** business row; creates the merchant **wallet**; flips the stall to `occupied`; inserts a 1-year **lease** with the next rent due date computed from the schedule day; and marks the application `awarded`. Only after commit does it audit-log and queue the credentials email. If anything fails, the whole account/stall/lease creation vanishes atomically.
- `reject` — records the reason and queues a "you may re-apply" email.

### 5.4 Leases and rent — `admin/leases.php` + `MerchantTenantDirectory.php`

Awarding creates a `merchant_leases` row; the Leases page manages them and records `merchant_rent_payments` per period. `MerchantTenantDirectory` builds the tenant cards by defensively joining whatever tables exist (it feature-detects columns like `operational_status` and the rent-payments table), computing this month's paid-vs-due per lease.

*(A second, older Kanban-style pipeline — `admin/merchant_onboarding.php` over `merchant_applications` with submitted → compliance → exec-review stages — coexists with the one-stop flow above.)*

---

## 6. The student wallet

### 6.1 Top-up (the GCash-style request flow)

`student/topup_request.php` doesn't move money. It inserts a `topup_requests` row (`pending`, with a `TOP-...` reference) — a *request*, in the harmless-to-fake category. The money moves only when a cashier approves it in the admin (§9.2), through `CirculationEngine::cashInWithFee()`. Students can also be loaded in person by a merchant (§8.5).

### 6.2 Paying by QR — `student/scan.php` → `student/pay_qr.php`

The scan page opens the camera and decodes QR codes in the browser with the **jsQR** library. Two kinds of QR exist, and `pay_qr.php` handles both:

- **POS order QR** (dynamic): the merchant's POS creates a `merchant_qr_orders` row with a random 32-hex-char token, server-validated items, a server-computed total, and a 15-minute expiry; the QR encodes that token. When the student scans it, the API locks the order row `FOR UPDATE`, rejects used/expired tokens, and charges **the amount stored server-side** — the phone can't tamper with the price because the price never comes from the phone.
- **Static wallet QR** (merchant settings page): identifies just the merchant, used for the Shop Cart flow below.

Every payment path applies the same core sequence as the engine: parent-control checks → conditional-update debit → merchant credit → stock deduction → ledger insert → commit → parent low-balance alert check.

### 6.3 The Shop Cart — `student/cart.php` + `api/cart.php` + `api/checkout.php`

Students can browse a merchant's catalog and build a cart **stored in the PHP session** (single merchant at a time, item id → qty). The clever part is `gjc_cart_snapshot()` in `app.php`: every time the cart is displayed *or* charged, it re-reads every line against live `merchant_inventory` and silently drops anything that became restricted, unavailable, or out of stock since it was added — reporting why — so the cart UI and the checkout can never disagree about what's being bought.

Submitting the cart creates a `cart_orders` row (`pending`, locked-in prices in `items_json`, a `CART-...` reference) — again, **no money moves yet**. The merchant sees it instantly in their Live Order Queue. At the counter the student scans the merchant's static wallet QR; `checkout.php` then finds the student's pending order `FOR UPDATE`, verifies the scanned merchant actually matches the order's merchant, re-validates every line's availability *and* re-checks stock atomically during deduction, applies parent controls, moves the money, and marks the order `paid` — reusing the order's own reference for the payment so both records visibly belong together.

### 6.4 Sending money — `student/transfer.php` + `api/transfer.php`

Peer-to-peer transfer by Student ID. The API has a `lookup` action (resolve `GJC2026-0001` → name, blocking self-lookup) and a `transfer` action enforcing: minimum ₱1, a **system-wide ₱5,000/day send limit** (summed from the `p2p_transfers` table), and the sender's parent controls. The transfer itself is the standard pattern — conditional debit, credit, ledger row (type `p2p_transfer`), plus a dedicated `p2p_transfers` record carrying the optional message. The UI displays "GenCoins" at a cosmetic ₱10 = 1 GC rate (`gjc_token_display`); all storage is pesos.

### 6.5 Withdrawing

The student-facing page (`student/withdraw.php`) was removed; graduated students now collect their balance as a cheque from finance, off-system. The admin release path is kept for legacy requests. It mirrored the top-up request: a `withdrawal_requests` row that only becomes money when the cashier physically hands over cash and clicks release, which calls `CirculationEngine::studentSettle()` (wallet → vault).

### 6.6 History and profile

`student/history.php` lists the student's ledger rows and request states; `profile.php` shows their identity, Student ID (from `student_info`, auto-generated as `GJC{year}-{seq}` and backfilled for legacy accounts by `gjc_backfill_student_ids`), and QR.

---

## 7. The parent portal

Parents are role 7, created by an admin (usually during bulk student import) with an emailed temp password. The schema (`gjc_ensure_parent_schema`) is three tables — `parents` (with a low-balance threshold, default ₱50), `parent_student_links`, `parent_alerts` — plus two columns grafted onto `student_wallets`: `daily_spend_limit` and `is_frozen`.

- **Linking** (`parent/api/link.php`): the parent types a Student ID; the API resolves it and inserts a link row (unique per pair).
- **Controls** (`parent/api/controls.php`): every action first runs `assertLinkedWallet()` — proving the parent is actually linked to that student — then sets `is_frozen` or `daily_spend_limit` on the wallet.
- **Enforcement is at the spend sites, not here**: the checkout, POS, QR-pay, and transfer APIs each check `is_frozen` and compare today's completed spending (summed from the ledger) plus the new amount against the limit *before* debiting. That means controls apply instantly, with no background job.
- **Low-balance alerts**: after any successful debit, the spend APIs call `gjc_check_parent_balance_alert()`, which inserts an alert row for every linked parent whose threshold is at or above the new balance — throttled to one unread alert per parent-student pair per day so a shopping spree doesn't spam ten alerts.

The dashboard/student pages read the linked students' balances, transactions, and alerts.

---

## 8. The merchant portal

### 8.1 Two merchant roles

A `merchant_admin` owns the stall; `merchant_staff` accounts (created on the Staff page, capped, deactivatable) can sell but not manage. The trick that makes this work everywhere is `gjc_merchant_owner_id()`: every merchant API resolves the logged-in user to the owner's id first, so staff sales credit the owner's wallet and read the owner's inventory. Deactivating a staff account takes effect immediately because `gjc_require_role()` re-checks `status` on every request.

### 8.2 POS — `merchant/pos.php` + `api/pos.php`

The cashier builds an order client-side, but the server **re-validates every line** (belongs to this merchant, available, unrestricted, enough stock) and recomputes prices from the database. Two ways to settle:

- `process_pos` — charge a student directly: look them up by Student ID, then the standard transaction (parent controls → conditional debit → credit → stock decrement → `POS-...` ledger row).
- `create_qr_order` — generate the token QR described in §6.2; the server refuses if its own computed total doesn't match what the UI showed (someone edited the DOM), stores the validated snapshot, and expires it in 15 minutes.

The **Live Order Queue** (`list_queue`) merges three sources into one list: pending/expired QR orders, unpaid cart orders (visible the moment a student submits, before any money), and completed cart payments read back from the ledger — with lazy expiry (`UPDATE ... SET expired WHERE expires_at < NOW()`) run on each poll. `view_order` and `void_order` complete the queue management, and `get_sales_summary` powers the live "Today's Sales / Total Earned" tiles from ledger sums.

### 8.3 Inventory — `merchant/inventory.php` + `api/inventory.php` and the restricted-products system

CRUD over `merchant_inventory` (SKU, price, stock, availability), admin-only for `merchant_admin`. SKUs double as scan/barcode keys, so they're enforced unique per merchant both in code and — where legacy data allows — with a real unique index (`gjc_ensure_inventory_sku_index` attempts the index once and backs off silently if old duplicates block it, leaving the app-layer check in charge).

Every add/edit passes the product name through the **banned-product gate**, the smartest piece of pure logic in the system (`app.php`):

1. `gjc_normalize_product_name()` — lowercase, fold Unicode look-alikes (Cyrillic "Соbra" → "cobra") and accents, translate leetspeak (`0→o, 3→e, @→a`…), strip all punctuation/spacing, and collapse repeated letters — so "Pi@tt0s", "P-i-a-t-t-o-s", and "Piattoss" all normalize to `piatos`.
2. `gjc_restriction_matches()` — an `exact` ban compares whole normalized names; a `contains` ban matches the normalized phrase as a substring **or** any distinctive token of the ban (stop-words like "energy"/"drink" stripped, so banning "Cobra Energy Drink" enforces on *cobra*, not on every drink), with a Levenshtein-window **fuzzy match** whose allowed edit distance scales with token length (catches "kobra" while keeping short tokens strict).
3. A blocked save is refused outright and audit-logged as `PRODUCT_RESTRICTION` so admins see evasion attempts.
4. `gjc_enforce_restrictions_on_inventory()` re-scans *existing* inventory whenever the admin page loads — so tightening a rule retroactively disables items that had slipped through, marking them restricted and unavailable.

Restricted items are excluded at **every** sale path (`is_restricted = 0` appears in the POS, cart, and checkout queries), and the cart snapshot drops them with the ban reason.

### 8.4 Returns — `api/returns.php`

Refunds follow an accounting-correct **reversal pattern**: the original ledger row is never edited or deleted. The API locks the original transaction, verifies it's a completed payment belonging to this merchant (voucher payments are non-refundable by policy), flips its status to `reversed`, and records the money movement back to the student as a **new** `refund` ledger row — with the reason logged to the audit trail. The `refund` ENUM value is itself added by an ensure-function (`gjc_ensure_transaction_refund_type`) the first time the feature runs.

### 8.5 Encashment and wallet loading

- **Encash** (`merchant/encash.php`): the merchant requests a payout; a pending `encashment_requests` row appears in the admin. Money moves only at release (§9.2).
- **Load a student's wallet** (`api/topup.php`): the counter-service flow — look the student up by ID, take their cash, and call `CirculationEngine::merchantSendToStudent()`, which debits the merchant's own wallet, credits the student ~97%, returns the 1% commission to the merchant, and sends the 2% system fee to the vault.

### 8.6 Settings, staff, menu

`merchant/settings.php` manages the stall logo (stored as `assets/merchant_logos/{userID}.ext`, referenced from `users.profile_img`) and displays the **static wallet QR** students scan for cart payments. `staff.php`/`api/staff.php` create staff logins (temp password, forced change, `merchant_owner_id` set). `print_menu.php` renders a printable menu; `qr_scanner.php` + `api/scan_voucher.php` let merchants accept visitor vouchers; `history.php` and `contract.php` show the ledger and the signed lease contract.

---

## 9. The admin (Finance) portal

### 9.1 Dashboard and economy

`admin/dashboard.php` calls `gjc_admin_dashboard_data()`: a circulation snapshot from the engine, KPI counts (pending top-ups/encashments, user demographics), the last transactions, and a 7-day volume series for the chart. `admin/economy.php` embeds the circulation widget (§3) — the live "is the economy balanced?" view — plus minting via `api/mint.php`, which routes through `MintingGuard`.

### 9.2 Approvals — where requests become money

Three tiny endpoints follow one careful pattern — *re-read the request row, verify it's still `pending` and that the POSTed wallet/amount still match the stored record (a stale-tab guard), call the engine, then mark the request processed*:
- `approve_topup.php` → `cashInWithFee(..., 'finance', ...)`, writing the fee breakdown back onto the request.
- `release_encashment.php` → `merchantSettle()`.
- `release_student_withdrawal.php` → `studentSettle()`.
- `reject_topup.php` just flips status with the rejecting admin recorded.

The Topups and Encashments pages are DataTables fronting these endpoints.

### 9.3 Transactions ledger — `admin/transactions.php` + `view_transaction.php`

`gjc_fetch_admin_transactions()` merges **three sources** into one uniform list: the real `transactions` ledger, plus pending/rejected `topup_requests` and `encashment_requests` (approved/released ones are excluded because their resulting ledger rows already represent them — no double counting). Each row is normalized by `gjc_build_transaction_row()`, which derives human sender/receiver labels per type ("Cashier Vault → Juan dela Cruz" for a cash-in). Filtering/search happen in PHP over the merged list; `view_transaction.php` shows one record's full detail including the vault before/after snapshots.

### 9.4 Visitors — vouchers

`admin/visitors.php` (over `VoucherEngine`) issues QR vouchers for campus visitors: vault money moves onto a code with a 1–168 hour expiry, printable via `print_voucher.php`. Merchants accept them through their scanner; expiry recycles unspent balances to the vault. Stats cards show the active pool and expired count.

### 9.5 Users and maintenance

`admin/users.php` lists and manages accounts. The heavyweight is `admin/maintenance.php` (~2,000 lines): **bulk student import** (CSV → users + student_info with generated `GJC{year}-{seq}` IDs + wallets), **parent/guardian creation and linking** (with temp-password credential emails, now queued async), and direct merchant creation that also records a matching approved application row so hand-created merchants look identical to pipeline ones. Every import/creation is audit-logged (`USER_IMPORT`, `MERCHANT_CREATE`, `USER_ACCOUNT`).

### 9.6 Restricted products, settings, audit log

`admin/restricted_products.php` + its API manage the ban list (name, `exact`/`contains` match type, reason, active flag) and trigger the retroactive inventory sweep on load. `admin/settings.php` manages the meeting default location and the `meeting_holidays` calendar that the auto-scheduler skips. `admin/audit_log.php` is the viewer over `systemic_audit_trail` with role/action filters.

---

## 10. The database in one view

Roughly six groups (all InnoDB, mostly utf8mb4):

| Group | Tables | Purpose |
|---|---|---|
| Identity | `users`, `role`, `student_info`, `course`, `parents`, `parent_student_links` | Accounts, roles/sub-roles, student IDs, guardianship |
| Money | `system_settings` (singleton: vault + cap), `student_wallets`, `merchant_wallets`, `transactions`, `p2p_transfers`, `fee_revenue_log`, `cap_increase_log`, `school_revenue_ledger` | The closed-loop economy and its immutable ledger |
| Requests | `topup_requests`, `encashment_requests`, `withdrawal_requests` | Pending intents that only become money on cashier action |
| Commerce | `merchant`, `merchant_inventory`, `merchant_qr_orders`, `cart_orders`, `restricted_products` | Stalls' catalogs and the two order flows |
| Rental | `stalls`, `stall_applications`, `merchant_leases`, `merchant_rent_payments`, `meeting_holidays`, `meeting_settings`, `merchant_accounts`, `archived_rejections`, `merchant_applications` | The stall-rental pipeline end to end |
| Oversight | `systemic_audit_trail`, `parent_alerts`, `vouchers`, `qr_tokens` | Audit, alerts, visitor money |

`database/migration_v2.sql` snapshots the v2 refactor (sub-roles, fees, leases, restricted products, inventory, P2P), but day-to-day the ensure-functions in `app.php` keep any database current automatically.

---

## 11. The front-end system

- **Design tokens** live in `assets/css/gjc-clear.css`: an emerald + gold brand palette (`--gjc-gold-500: #e0b83a`, deep green `#0e6332`), a three-tier card system (flat panels → white stat tiles → one dark hero card per page with a gold keyline), and **Plus Jakarta Sans** for all text with tabular numerals so peso amounts align.
- Stylesheets are versioned by query string (`admin.css?v=10`) — bump the number to bust browser caches after a change.
- Tables use a shared DataTables pattern: PHP renders the full `<tbody>`, then JS enhances it with search/sort/paging — with a deliberate **fallback**: every page checks `$.fn.DataTable` exists first, so if the CDN is blocked the plain HTML table still works.
- Client JS follows one shape everywhere: build `FormData` → `fetch()` the API → parse JSON defensively (non-JSON responses become a friendly error) → show a Bootstrap toast → refresh the affected UI fragment in place.

---

## 12. The recurring patterns (the "grammar" of this codebase)

If you understand these seven idioms, you can read any file in the project:

1. **Gate first** — `gjc_require_role()` before any output; APIs also re-check `force_change`.
2. **Ensure-schema at the top** — pages self-migrate the tables they need; everything is idempotent.
3. **Requests vs. money** — user-facing "submit" actions insert request rows; only cashier/engine actions move balances.
4. **Transaction + `FOR UPDATE` + conditional debit** — every money movement is atomic, serialized through the settings row, and overdraft-proof at the SQL level.
5. **Server recomputes everything** — prices, totals, availability, and restrictions are always re-derived from the database at the moment of charging; the client is never trusted.
6. **Immutable ledger + reversal rows** — nothing in `transactions` is ever edited; corrections are new rows, and every row snapshots the vault and total circulation for auditability.
7. **Fail-soft periphery** — audit logging, emails, and optional-table writes swallow their own errors; the core action never dies because a side effect hiccuped.
