# GenPay — User Manual

*A role-by-role guide to using the GenPay Cashless Payment System of General de Jesus College.*

This manual covers the four login portals — **Finance (Admin)**, **Merchant**, **Student**, and **Parent** — plus the two public experiences: **applying for a stall** and **paying as a campus visitor**.

**How to read this manual.** Each module starts with a short overview of what it is for, followed by numbered steps for each task. Screenshot slots look like this:

> 📷 **[SCREENSHOT 1]** *Suggested caption: Login page.*
> **Capture:** What to show and in what state.

Work through them in order — they are numbered 1 to 53 across the whole document. Before capturing: use a clean browser window at 100% zoom, log in with demo/test accounts (never real student data), and keep the sidebar visible in full-page shots so readers can orient themselves.

---

## 1. Getting Started (all users)

### 1.1 Logging in

GenPay has a single login page for every role — the system reads your account type and sends you to the right portal automatically.

1. Open the GenPay site in your browser and click **Login** (or go directly to `/login.php`).
2. Enter your **registered email address** and **password**.
3. Click **SIGN IN**. You land on the dashboard for your role.

> 📷 **[SCREENSHOT 1]** *Suggested caption: The GenPay login page.*
> **Capture:** The full login card — GJC seal, GenPay title, email and password fields, and the "Register as Student / Register as Guest" links at the bottom. Empty fields are fine.

### 1.2 First login — changing your temporary password

Merchant, staff, and parent accounts are created by the school and emailed a **temporary password**. On your first login, GenPay will not let you go anywhere until you replace it.

1. Log in with the emailed temporary password.
2. You are taken straight to the **Change Password** screen (you cannot skip it — every page redirects here until it's done).
3. Enter a new password (at least 6 characters) twice and submit.
4. You are redirected to your dashboard. The temporary password stops working permanently.

> 📷 **[SCREENSHOT 2]** *Suggested caption: Forced password change on first login.*
> **Capture:** The Change Password screen with both fields visible (empty). Log in with a freshly created merchant or parent account to reach it.

### 1.3 Logging out

Click your name/avatar area and choose **Logout** (or the logout item at the bottom of the sidebar). Always log out on shared computers — the finance office especially.

---

## 2. Finance (Admin) Portal

The Finance portal is the control room of the campus economy. From here the school approves money movements (top-ups, encashments, withdrawals), manages stall rentals and merchant accounts, supervises what can be sold, issues visitor vouchers, and monitors that every peso in the system is accounted for.

### 2.1 Dashboard

The dashboard summarizes the whole system: circulating balance, today's transaction volume, pending top-ups and encashments waiting for you, user counts, a 7-day volume chart, and the most recent transactions.

> 📷 **[SCREENSHOT 3]** *Suggested caption: Finance dashboard.*
> **Capture:** The full dashboard after some demo transactions exist, so the KPI cards and the 7-day chart show real numbers instead of zeros.

### 2.2 Approving student top-ups

Students request top-ups from their portal; money only enters their wallet when the cashier receives the cash and approves the request here. A **2% service fee** is deducted automatically (a ₱100 top-up credits ₱98).

1. Open **Top-Ups** from the sidebar. Pending requests are listed with the student's name, amount, method, and reference number.
2. Collect the cash from the student and verify the amount matches the request.
3. Click **Approve**. GenPay moves the money from the cashier vault into the student's wallet and shows the fee breakdown in the confirmation.
4. To refuse a request (wrong amount, student never showed up), click **Reject** instead — nothing moves, and the student sees the rejected status.

> 📷 **[SCREENSHOT 4]** *Suggested caption: Pending top-up requests.*
> **Capture:** The Top-Ups page with at least one pending request row visible, before clicking anything.

> 📷 **[SCREENSHOT 5]** *Suggested caption: Top-up approved with the service-fee breakdown.*
> **Capture:** The success message right after clicking Approve — it shows the credited amount and the 2% fee.

### 2.3 Releasing merchant encashments and student withdrawals

Merchants and students turn wallet balance back into physical cash at the finance office. Both work the same way: they file a request from their portal, and you release it when you hand over the cash.

1. Open **Encashments** (merchant payouts) — or the withdrawals list for students.
2. Find the pending request and confirm the person and the amount.
3. Hand over the physical cash, then click **Release**. The wallet is debited and the money returns to the cashier vault.
4. GenPay refuses to release the same request twice, and will warn you if the request data changed since the page loaded (refresh and retry).

> 📷 **[SCREENSHOT 6]** *Suggested caption: Pending encashment requests.*
> **Capture:** The Encashments page with a pending merchant request visible.

### 2.4 Processing stall applications (the one-stop meeting)

Everything about renting a stall — document verification, contract signing, and payment — happens in **one in-person meeting**, which GenPay schedules automatically the moment an application is submitted.

**Daily routine:**

1. Open **Stall Applications**. The **Today's Schedule** card lists today's meetings in time order — this is your appointment log for the day.
2. Applications you have never opened show a gold **New** badge. Opening one clears the badge for all admins.
3. Click a row (or **Open** on a schedule entry) to expand the application's workspace inline.

> 📷 **[SCREENSHOT 7]** *Suggested caption: Stall Applications page with Today's Schedule and the New badge.*
> **Capture:** The full page with at least one meeting in Today's Schedule and one unopened application showing the gold **New** badge next to its business name.

**During the meeting, inside the expanded application:**

4. **Verify documents:** the applicant's uploaded permits appear as side-by-side previews. Click any document to view it full-size and compare it against the originals the applicant brought.

> 📷 **[SCREENSHOT 8]** *Suggested caption: Reviewing an applicant's submitted documents.*
> **Capture:** An expanded application with the Submitted Documents grid visible; ideally with one document opened in the full-size viewer overlay.

5. **Upload the signed contract:** after signing, scan the contract and upload it (PDF/JPG/PNG, max 5 MB) in the *Signed Contract* section.
6. **Record the payment:** enter the 2-month deposit, 1-month advance, the rental start date, and choose the recurring schedule (every 15th or every 30th). Selecting a stall pre-fills suggested amounts from its monthly rate. Click **Save Payment**.

> 📷 **[SCREENSHOT 9]** *Suggested caption: Contract upload and payment recording sections.*
> **Capture:** The workspace with the contract showing its "Uploaded" pill and the payment fields filled in, before awarding.

7. **Award the stall:** choose a vacant stall in *Assign Stall & Finalize* and click **Award**. GenPay refuses to award until both the contract and the payment are recorded. Confirm in the dialog — **this cannot be undone**. In one step, GenPay: assigns the stall, creates the merchant's login account, creates their wallet, records the 1-year lease with the next rent due date, and emails the merchant their temporary credentials.

> 📷 **[SCREENSHOT 10]** *Suggested caption: Award confirmation dialog.*
> **Capture:** The Award Stall confirmation modal, showing the stall and proprietor name.

8. **Rejecting:** if documents don't check out, click **Reject** and enter the reason. The applicant is emailed that they may submit a brand-new application anytime (a new meeting gets auto-scheduled on resubmission).

### 2.5 Transactions ledger

Every peso movement in the system is a row here — payments, top-ups, encashments, transfers, refunds, vouchers — merged with pending requests so nothing is invisible.

1. Open **Transactions**. Use the search box and the type/status filters to narrow the list.
2. Click a transaction to open its full detail: reference number, sender → receiver, amounts, and the vault balance before and after.

> 📷 **[SCREENSHOT 11]** *Suggested caption: The transactions ledger with filters.*
> **Capture:** The Transactions page with a mixed list (payments, top-ups) and the type filter dropdown open.

### 2.6 Economy — circulation health and minting

GenPay is a closed economy: the money supply (**circulation cap**) always equals the cashier vault plus all wallets plus active vouchers. This page proves it live.

1. Open **Economy**. The circulation widget shows the cap split across vault / student wallets / merchant wallets / vouchers, and a **Balanced** indicator (any drift over one centavo would abort transactions automatically).

> 📷 **[SCREENSHOT 12]** *Suggested caption: Circulation health widget showing a balanced economy.*
> **Capture:** The circulation section with the pool bars and the "balanced" state visible.

2. **Minting (increasing the money supply)** is done here by a super-admin. Enter the amount and a **mandatory written justification**. Over ₱50,000 minted in one month requires the super-admin PIN; ₱500,000/month is an absolute ceiling that cannot be exceeded.

> 📷 **[SCREENSHOT 13]** *Suggested caption: Minting form with justification field.*
> **Capture:** The mint/cap-increase form with an amount and reason typed in, before submitting.

### 2.7 Visitor vouchers

Visitors (event guests, parents on campus day) can spend at stalls without an account using prepaid QR vouchers.

1. Open **Visitors** and click **Create Voucher**.
2. Enter the visitor's name, contact, the amount they paid you in cash, and an expiry (1–168 hours; default 24).
3. Print the voucher (**Print Voucher**) and hand it over. Explain that it is **non-refundable** — unspent balance stays on the voucher and cannot be converted back to cash.
4. Expired vouchers recycle any unspent balance back to the vault automatically.

> 📷 **[SCREENSHOT 14]** *Suggested caption: Active visitor vouchers.*
> **Capture:** The Visitors page with stat cards (active visitors, visitor funds) and at least one active voucher in the list.

> 📷 **[SCREENSHOT 15]** *Suggested caption: A printed voucher with its QR code.*
> **Capture:** The print-voucher view showing the QR, amount, and expiry.

### 2.8 Users and Maintenance

The Maintenance page is where accounts are created in bulk or by hand.

- **Bulk student import:** upload the enrollment CSV; GenPay creates the user accounts, assigns Student IDs (format `GJC2026-0001`), and opens a wallet for each student automatically.
- **Parent accounts:** create a guardian, link them to their child by Student ID, and GenPay emails them a temporary password.
- **Direct merchant creation:** for merchants onboarded outside the application pipeline.

1. Open **Maintenance**, choose the task tab, fill in or upload the data, and submit. Results (created, skipped, errors) are reported per row.

> 📷 **[SCREENSHOT 16]** *Suggested caption: Bulk student import in Maintenance.*
> **Capture:** The import section with a file chosen and, ideally, the results summary after a small test import.

The **Users** page lists every account with its role and status; deactivating an account logs it out on its next click.

> 📷 **[SCREENSHOT 17]** *Suggested caption: User management list.*
> **Capture:** The Users page showing accounts of several roles.

### 2.9 Restricted products (campus health compliance)

The school bans certain products (energy drinks, etc.). Merchants physically cannot list them.

1. Open **Restricted Products** and click **Add**.
2. Enter the product/brand name, the match type — **Exact** (only that name) or **Contains** (any product containing the brand word) — and the reason shown to merchants.
3. On save, GenPay immediately re-scans every merchant's existing inventory and disables anything that now matches. The matching is evasion-proof: spacing tricks, punctuation, doubled letters, look-alike characters, and leetspeak ("C0br4") are all caught.

> 📷 **[SCREENSHOT 18]** *Suggested caption: The restricted products list.*
> **Capture:** The page with several bans listed showing their match type and reason.

### 2.10 Leases, Settings, and Audit Log

- **Leases:** every awarded stall's lease with monthly rent, next due date, and recorded rent payments per month.
- **Settings:** the default meeting location and the **holiday calendar** — dates added here are automatically skipped by the meeting auto-scheduler.
- **Audit Log:** *(super-admin only)* the record of every significant action — logins and refused logins, transactions, minting attempts, product-ban hits, account changes, document views and data exports — with who did it, when, from which IP and endpoint, the outcome (success, failure, denied, unconfirmed), the record affected, and the before/after values.

  **What the log actually guarantees.** Being precise about this matters, because an audit trail is only worth what its weakest guarantee is worth:

  - **Append-only.** Database triggers reject any `UPDATE` or `DELETE` on the audit table, and the application writes through a dedicated database account (`gjc_audit_writer`) that holds only `INSERT` and `SELECT` on that one table. The application cannot rewrite its own history even if it is compromised.
  - **Tamper-evident, not tamper-proof.** Each entry is hashed together with the hash of the entry before it. Editing an entry, or removing one from the middle, breaks the chain, and **Verify chain** on the Audit Log page finds the first entry that no longer adds up. This *detects* interference after the fact; it does not *prevent* someone with direct database-administrator access from making it. A determined DBA can still drop the triggers — they cannot do so without leaving the chain broken.
  - **Entries written before this protection existed are marked `legacy (unchained)`.** They are unverifiable rather than untrustworthy: the chain simply did not exist when they were written.
  - **Best-effort, never blocking.** A failure to write an audit entry is logged to the PHP error log and never interrupts the action being audited. If audit logging is misconfigured, a red banner appears at the top of the Audit Log page.
  - **Credentials are never recorded.** Passwords, hashes, PINs, OTPs, session and remember-me tokens and API keys are stripped from every payload before it is stored.

  **Retention.** Audit entries are **kept indefinitely — there is no purge, archive or cleanup job.** The table grows without bound and nothing trims it. If a retention policy is ever adopted, deleting entries will require dropping the append-only triggers, which will itself break the hash chain from that point backwards — so decide the policy deliberately rather than by running a delete.

> 📷 **[SCREENSHOT 19]** *Suggested caption: Lease and rent-payment tracking.*
> **Capture:** The Leases page with an active lease row expanded or its payments visible.

> 📷 **[SCREENSHOT 20]** *Suggested caption: The system audit log.*
> **Capture:** The Audit Log with mixed action types visible (LOGIN, TRANSACTION, etc.).

---

## 3. Merchant Portal

The Merchant portal is the stall's business toolkit: a product catalog, a point-of-sale, live incoming orders, refunds, staff accounts, and payouts. A **Merchant Admin** (the stall owner) can do everything below; **Merchant Staff** accounts can sell but cannot manage products, staff, or money settings. Everything staff sell credits the owner's wallet.

### 3.1 Your account and dashboard

Your account is created when the school awards you a stall — credentials arrive by email, and your first login forces a password change (§1.2). The dashboard shows your wallet balance, today's sales, and recent activity.

> 📷 **[SCREENSHOT 21]** *Suggested caption: Merchant dashboard.*
> **Capture:** The merchant dashboard with a non-zero wallet balance and some sales visible.

### 3.2 Managing your inventory

Every product you sell must be in the catalog — the POS and student Shop Cart both sell only what is listed here, at the prices listed here.

1. Open **Inventory** and click **Add Product**.
2. Fill in the product name, price, starting stock, unit, category, and optionally a **SKU** (each SKU must be unique in your catalog — it doubles as the scan code).
3. Save. If the product name matches a school ban, the save is **refused with the reason** — renaming it with tricks (spacing, numbers-for-letters) will not get past the filter, and attempts are logged for the school to see.
4. Toggle **Available** to temporarily hide an item without deleting it; stock counts down automatically with every sale, and a low-stock alert threshold warns you when it's time to restock.

> 📷 **[SCREENSHOT 22]** *Suggested caption: Merchant inventory list.*
> **Capture:** The Inventory page with several products, showing stock levels and availability toggles.

> 📷 **[SCREENSHOT 23]** *Suggested caption: A banned product being blocked at save.*
> **Capture:** The Add Product form after attempting to save a banned item — with the red "banned product" message showing the reason.

### 3.3 Selling at the counter (POS)

The POS handles both ways a student can pay: you charge their wallet directly, or you show them a QR to scan.

**Direct charge by Student ID:**

1. Open **POS** and tap products to build the order (quantities and total update live).
2. Click **Charge**, enter the student's ID (e.g., `GJC2026-0001`), and confirm the student's name that appears.
3. Confirm the charge. The student's wallet is debited, your wallet is credited, and stock is deducted — all in one step. If the student's balance is short, their wallet is frozen by a parent, or a parental daily limit would be exceeded, the sale is refused with the reason.

> 📷 **[SCREENSHOT 24]** *Suggested caption: Building an order in the POS.*
> **Capture:** The POS with 2–3 items added to the order and the running total visible.

> 📷 **[SCREENSHOT 25]** *Suggested caption: Charging a student by Student ID.*
> **Capture:** The charge dialog after the ID lookup, showing the student's name confirmation.

**QR order (student scans):**

4. Alternatively click **Generate QR**. GenPay creates a one-time payment QR for exactly this order — valid for **15 minutes**, single-use, and priced server-side so it cannot be altered.
5. The student scans it with their portal's Scan & Pay. You'll see the order flip to *paid* in the queue.

> 📷 **[SCREENSHOT 26]** *Suggested caption: A one-time POS payment QR.*
> **Capture:** The QR modal showing the code, amount, and the 15-minute expiry.

### 3.4 The Live Order Queue

The queue shows everything happening at your stall in real time: QR orders you generated, **cart orders students submitted from their phones** (visible the instant they submit, before any payment), and completed payments.

1. Watch the queue for incoming cart orders — prepare the items while the student walks over.
2. When the student arrives and pays (by scanning your Wallet QR — §3.7), the order flips to *paid*.
3. Click any order to view its full item breakdown; **Void** a pending order that will never be picked up.

> 📷 **[SCREENSHOT 27]** *Suggested caption: The Live Order Queue with a pending cart order.*
> **Capture:** The queue with at least one pending student cart order and one paid order, showing the status badges.

### 3.5 Refunds and returns

Refunds never delete the original sale — they create a visible reversal, keeping your books honest.

1. Open **History**, find the completed sale, and choose **Return/Refund**.
2. Enter the reason (required). The original transaction is marked *reversed*, and a new *refund* entry moves the money back to the student.
3. Visitor voucher payments are non-refundable by school policy — GenPay will refuse.

> 📷 **[SCREENSHOT 28]** *Suggested caption: Issuing a return on a completed sale.*
> **Capture:** The return dialog with the reason field filled in, on a completed transaction.

### 3.6 Loading a student's wallet (over-the-counter top-up)

Students can hand you cash and get wallet credit on the spot — you earn a 1% commission.

1. In the top-up section, look the student up by Student ID and confirm their name.
2. Enter the cash amount received. The screen shows the split: the student is credited ~97%, **1% comes back to you as commission**, and 2% is the system service fee.
3. Confirm. The credit comes out of *your* merchant wallet balance, so keep the cash — that's your reimbursement.

> 📷 **[SCREENSHOT 29]** *Suggested caption: Loading a student wallet with the fee breakdown.*
> **Capture:** The load-wallet form after entering an amount, showing the credited/fee split before confirming.

### 3.7 Getting paid: your Wallet QR, and cashing out

- **Settings → Wallet QR:** your permanent QR that identifies your stall. Print it and post it at the counter — students paying for submitted cart orders scan this.

> 📷 **[SCREENSHOT 30]** *Suggested caption: The stall's permanent Wallet QR in Settings.*
> **Capture:** The Settings page section showing the static Wallet QR.

- **Encash:** when you want physical cash, open **Encash**, enter the amount, and submit. Bring your ID to the finance office; the cashier hands over the cash and releases the request — only then is your wallet debited.

> 📷 **[SCREENSHOT 31]** *Suggested caption: Requesting an encashment.*
> **Capture:** The Encash page with an amount entered and your pending/past requests listed below.

### 3.8 Staff accounts

1. Open **Staff** and click **Add Staff** — enter their name and email; they receive a temporary password and must change it on first login.
2. Staff can run the POS and scanner but cannot edit inventory, manage staff, or change settings.
3. **Deactivate** a staff account any time — it takes effect on their very next click, even mid-session.

> 📷 **[SCREENSHOT 32]** *Suggested caption: Managing staff accounts.*
> **Capture:** The Staff page with one active staff member listed and the Add Staff form/button visible.

### 3.9 Accepting visitor vouchers

1. Open **QR Scanner** and scan the visitor's voucher QR.
2. Enter the purchase amount and confirm. The amount moves from the voucher to your wallet; any remainder stays on the voucher (change is never given in cash).

> 📷 **[SCREENSHOT 33]** *Suggested caption: Scanning a visitor voucher.*
> **Capture:** The merchant QR scanner with the camera view open (a voucher QR in frame if possible).

### 3.10 Menu and contract

- **Print Menu** renders your catalog as a printable price list for the counter.
- **Contract** shows the signed lease contract the school has on file for your stall.

> 📷 **[SCREENSHOT 34]** *Suggested caption: The printable stall menu.*
> **Capture:** The print-menu view with products and prices laid out.

---

## 4. Student Portal

The Student portal is a digital wallet: load money at the cashier or a stall, pay by QR, order ahead with the Shop Cart, send money to classmates, and cash out — with every transaction recorded in your history. Balances are shown in pesos and playfully in **GenCoins** (₱10 = 1 GC).

### 4.1 Dashboard

Your balance, quick actions, and recent transactions at a glance.

> 📷 **[SCREENSHOT 35]** *Suggested caption: Student dashboard.*
> **Capture:** The student dashboard with a positive balance and a few recent transactions.

### 4.2 Topping up your wallet

**At the cashier (finance office):**

1. Open **Top-Up** in the sidebar, enter the amount, choose the payment method, and submit. You get a reference number — your request is now *pending*.
2. Go to the cashier, present the reference (or your ID), and pay the cash.
3. The cashier approves it and your wallet is credited minus the **2% service fee** (₱100 cash → ₱98 credited). The status in your list flips to *approved*.

> 📷 **[SCREENSHOT 36]** *Suggested caption: Submitting a top-up request.*
> **Capture:** The Top-Up page with an amount entered and the recent-requests list below showing a pending and an approved request.

**At any stall:** hand your cash to a merchant and give them your Student ID — they load your wallet on the spot (≈97% credited after fees).

### 4.3 Paying with Scan & Pay

1. At the counter, the merchant rings up your order and shows you a **payment QR**.
2. Open **Scan & Pay**, allow camera access, and point your camera at the QR.
3. Review the order — the items and total shown come from the merchant's system and cannot be altered — and confirm.
4. Payment is instant; both you and the merchant see the confirmation with a reference number. The QR is single-use and expires after 15 minutes, so a stale code simply asks the merchant to generate a new one.

> 📷 **[SCREENSHOT 37]** *Suggested caption: Scanning a merchant payment QR.*
> **Capture:** The Scan & Pay page with the camera view open, ideally with a QR in frame.

> 📷 **[SCREENSHOT 38]** *Suggested caption: Payment confirmation.*
> **Capture:** The success state after paying, showing the amount and reference number.

### 4.4 Ordering ahead with the Shop Cart

Order from your phone, then pay when you pick it up — the stall starts seeing your order the moment you submit it.

1. Open **Shop Cart**, pick a stall, and browse its catalog (one stall per cart).
2. Add items and quantities. If something sells out or gets restricted while it's in your cart, GenPay removes it automatically and tells you why.
3. Click **Submit Order**. *No money moves yet* — the stall now sees your order in their queue and can start preparing.
4. Walk to the stall and scan the **Wallet QR posted at the counter** with Scan & Pay. GenPay matches the QR to your pending order, charges the locked-in total, and hands the merchant the paid confirmation.

> 📷 **[SCREENSHOT 39]** *Suggested caption: Building a cart from a stall's catalog.*
> **Capture:** The Shop Cart with a stall selected and 2–3 items added, total visible.

> 📷 **[SCREENSHOT 40]** *Suggested caption: A submitted order awaiting payment at the counter.*
> **Capture:** The cart page after submitting — the pending order summary with its reference number.

### 4.5 Sending GenCoins to another student

1. Open **Send GenCoin** and type the recipient's **Student ID** — their name appears so you can confirm it's the right person.
2. Enter the amount (minimum ₱1) and an optional message, then send.
3. Transfers are instant. You can send at most **₱5,000 per day** in total, and your own parental controls (frozen wallet, daily limit) apply.

> 📷 **[SCREENSHOT 41]** *Suggested caption: Sending money to a classmate.*
> **Capture:** The transfer form after the ID lookup, showing the recipient's confirmed name and an amount entered.

### 4.6 Withdrawing cash

1. Open **Withdraw**, enter the amount, and submit the request.
2. Go to the finance office with your ID. When the cashier hands you the cash, they release the request — only then is your wallet debited.

> 📷 **[SCREENSHOT 42]** *Suggested caption: Requesting a cash withdrawal.*
> **Capture:** The Withdraw page with an amount entered and past requests listed.

### 4.7 History and profile

- **History** lists every transaction with its type, amount, status, and reference — your receipts live here.
- **Profile** shows your Student ID and personal QR.

> 📷 **[SCREENSHOT 43]** *Suggested caption: Student transaction history.*
> **Capture:** The History page with a mix of payments, top-ups, and a transfer.

> 📷 **[SCREENSHOT 44]** *Suggested caption: Student profile with Student ID.*
> **Capture:** The Profile page (use a demo account; avoid real personal data).

**If a payment is refused,** the message tells you why: insufficient balance, a wallet frozen by your parent, or a parental daily spending limit reached — see §5 for what your parent controls.

---

## 5. Parent Portal

The Parent portal gives guardians live oversight of their children's wallets: balances, spending activity, protective controls, and low-balance alerts. Parent accounts are created by the school (you receive a temporary password by email — see §1.2).

### 5.1 Linking your child

1. Log in and open the linking section on your dashboard.
2. Enter your child's **Student ID** (printed on their school ID, format `GJC2026-0001`).
3. Confirm the student's name. You can link more than one child, and each appears as their own card.

> 📷 **[SCREENSHOT 45]** *Suggested caption: Linking a student to a parent account.*
> **Capture:** The link form with a Student ID entered and the confirmed student name shown.

### 5.2 The dashboard — balances and alerts

Your dashboard shows each linked child's current balance and recent activity, plus your **alerts**: GenPay notifies you when a child's balance falls below your threshold (default ₱50, adjustable) — at most one alert per child per day, so you're informed but not spammed.

> 📷 **[SCREENSHOT 46]** *Suggested caption: Parent dashboard with a linked student and a low-balance alert.*
> **Capture:** The dashboard with a student card showing a balance, and an unread low-balance alert visible.

### 5.3 Spending controls

Controls take effect **immediately** — they are checked at the moment of every purchase, transfer, and cart payment.

1. Open **Controls** and select the child.
2. **Daily spending limit:** set a peso cap; once the child's completed spending today reaches it, further payments are refused with a clear message (set 0 for no limit).
3. **Freeze wallet:** one switch stops all spending instantly — for a lost phone or a grounding. Unfreeze the same way. Money can still be *received* while frozen.
4. **Low-balance threshold:** the balance at which you want to be alerted.

> 📷 **[SCREENSHOT 47]** *Suggested caption: Parent spending controls.*
> **Capture:** The Controls page showing the daily-limit field, the freeze toggle, and the alert threshold for a linked student.

### 5.4 Viewing your child's activity

Open the child's detail page to see their transaction list — what they bought, where, when, and for how much. This is read-only: parents supervise, students transact.

> 📷 **[SCREENSHOT 48]** *Suggested caption: A linked student's activity as seen by the parent.*
> **Capture:** The student detail page from the parent portal with several transactions listed.

---

## 6. Applying for a Stall (public — no account needed)

Anyone can apply to rent a canteen stall online. The process is: pick a stall → submit the form with your documents → attend **one meeting** where everything (verification, contract, payment) is completed → receive your merchant account.

### 6.1 Browsing the stall map

1. Open the public **Stalls** page. The market's 10 stalls are shown as a live map — green = vacant, occupied stalls show the current business, and *pending* means someone is mid-application.
2. Click a vacant stall to see its size and monthly rate.

> 📷 **[SCREENSHOT 49]** *Suggested caption: The public stall availability map.*
> **Capture:** The Stalls page showing the 2×5 grid with a mix of vacant and occupied stalls.

### 6.2 Submitting the application

1. Choose a stall and proceed to **Apply**. The stall is now **held for you for 15 minutes** — finish the form within that window or it returns to the market.
2. Fill in your personal details, address, business name, contact number (`09XXXXXXXXX`), and email.
3. Upload the five required documents (JPG/PNG/PDF, max 5 MB each): your photo, business permit, sanitary permit, GJC requirements, and clearance.
4. Scroll through and accept the Terms & Conditions, then submit.
5. The confirmation page — and an email — give you your **verification meeting schedule**, booked automatically on the next available business day (meetings run hourly 8–11 AM and 1–4 PM, skipping weekends and school holidays).

> 📷 **[SCREENSHOT 50]** *Suggested caption: The stall application form.*
> **Capture:** The application form partially filled, showing the document upload fields.

> 📷 **[SCREENSHOT 51]** *Suggested caption: Submission confirmation with the auto-scheduled meeting.*
> **Capture:** The success page showing the meeting date, time, and location.

> 📷 **[SCREENSHOT 52]** *Suggested caption: The confirmation email.*
> **Capture:** The received email showing the meeting details and the reminder to bring original documents.

### 6.3 The verification meeting

Bring the **original copies** of every document you uploaded, a valid ID, and payment (2 months' deposit + 1 month advance). In that single meeting the school verifies your documents, you sign the contract, pay, and — if approved — the stall is awarded on the spot. Your merchant login credentials arrive by email; see §3.1 for your first login. If the application is not approved, you'll receive the reason by email and may submit a fresh application anytime.

---

## 7. Paying as a Campus Visitor

Visitors don't need an account — they use a prepaid QR voucher.

1. Go to the **finance office** and pay cash for a voucher; you'll receive a printed QR with your balance and expiry (typically 24 hours).
2. At any stall, present the QR — the merchant scans it and enters your purchase; the amount is deducted from the voucher.
3. Your remaining balance stays on the voucher for the next purchase. **Vouchers are non-refundable:** unspent balance cannot be converted back to cash, and it is recycled by the school when the voucher expires — so size your voucher to what you plan to spend.

> 📷 **[SCREENSHOT 53]** *Suggested caption: A visitor paying by voucher at a stall.*
> **Capture:** The merchant's voucher-scan screen mid-payment showing the deduction and remaining balance.

---

## 8. Quick answers (all roles)

| "Why was I stopped?" | What it means |
|---|---|
| *Insufficient wallet balance* | Top up first (§4.2) — the balance check happens at the instant of payment. |
| *Wallet is frozen by a parent or guardian* | A parent enabled the freeze (§5.3); spending resumes when they lift it. |
| *Daily spending limit reached* | A parent's daily cap (§5.3) — resets at midnight. |
| *Daily transfer limit exceeded* | Students can send at most ₱5,000/day total (§4.5). |
| *This QR has expired / already been used* | POS QRs are single-use and last 15 minutes — ask the merchant for a fresh one. |
| *Item is a banned product* | The school's restricted list blocks it (§2.9) — renaming won't help. |
| *This voucher is non-refundable* | Voucher change stays on the voucher (§7). |
| *Please change your password* | First-login rule (§1.2) — no page or payment works until you do. |

---

*GenPay User Manual — General de Jesus College. Pair this manual with `SYSTEM_MANUSCRIPT.md` (how the system works internally) for the complete documentation set.*
