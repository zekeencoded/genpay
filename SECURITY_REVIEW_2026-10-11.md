# GenPay security review — 11 October 2026

**Overall assessment: 3/10.** This is an engineering assessment of the current working tree, not a standardized certification or CVSS score. The system has useful defensive controls, but reachable payment authorization defects prevent treating it as ready for real balances and personal data.

## Scope and verification

- Repository-wide security pattern scanning, with targeted manual tracing of authentication, authorization, money movement, uploads, document serving, output rendering, SQL construction, mail delivery, and audit logging.
- Application inventory: 160 runtime PHP files, 38 API entry points, approximately 54,569 PHP lines. Also inspected JavaScript sinks, Apache rules, SQL/migration structure, dependency metadata, and existing test structure. This was not a line-by-line formal verification of all files.
- Read-only HTTP HEAD checks against `http://localhost/genpay/`. No authenticated payment, account, database, or upload operations were performed.
- Checked local configuration without displaying passwords or signing keys. No application code was changed. Existing uncommitted changes were included in the assessment.
- Production infrastructure, database grants/triggers, proxy defenses, recovery procedures, and external exposure were not independently verified. Static findings below are established from source; financial exploitation was not attempted.

## Findings, ordered by urgency

### 1. Critical — arbitrary student wallet debit through the economy API

**Evidence:** `admin/api/economy.php:14`, `:31`, `:66`; `connection/CirculationEngine.php:483`.

The endpoint requires session identity, but its `pay` action has no finance/student role restriction or ownership check. It passes client-supplied student wallet ID, merchant wallet ID, and amount directly to `studentPay()`. That method checks balance and circulation, but never compares the wallet owner to the authenticated caller. It also omits frozen-wallet, enrollment, suspension, and daily-limit checks. `lookup_student` in the same endpoint exposes wallet IDs to authenticated callers without a finance role restriction.

**Impact:** An authenticated account can request a payment from another student's wallet to a chosen merchant wallet. Balanced bookkeeping and audit entries do not prevent unauthorized movement. The endpoint accepts query parameters and lacks CSRF protection, compounding the issue.

**Fix:** Remove this alternate payment action or route it through the guarded checkout service. Derive the payer from the session and enforce account state, ownership, allowed recipient, and limits inside the transaction. Restrict lookup and administrative actions explicitly.

### 2. Critical — legacy POS debit requires no payer approval and trusts the submitted total

**Evidence:** `merchant/api/pos.php:719`, `:721`, `:734`, `:800`.

The `process_pos` action allows a merchant session, including staff, to submit a student wallet ID and debit it. There is no student session, payment PIN, or single-use student authorization bound to that charge. Although inventory ownership and availability are checked, the database price is not used to recompute the submitted total. CSRF verification elsewhere in this file applies to selected actions, not this action.

**Impact:** A merchant or compromised merchant session can charge a selected student's wallet for an arbitrary submitted amount within its balance and configured limits. The newer student-confirmed QR path does not disable this older route.

**Fix:** Retire `process_pos` in favor of student-confirmed QR checkout. Compute prices and totals on the server, bind approval to the payer, merchant, order and amount, and consume it once.

### 3. High — stored HTML/script injection across trust boundaries

**Evidence:** `connection/app.php:16`; `merchant/api/inventory.php:29`; `merchant/inventory.php:119`; `merchant/pos.php:143`; `admin/dashboard.php:375`; `parent/profile.php:22`.

`gjc_e()` returns a string without escaping it. Its callers place user-controlled names, product fields, descriptions and other values directly in HTML, attributes, and some JavaScript contexts. Inventory inputs are stored without rejecting HTML. Finance dashboard transaction sender/receiver labels also use this helper, creating a path from editable account names to a privileged viewer.

**Impact:** Stored XSS can act with the permissions of a user who views the affected page. Some newer pages use a correct local escaper; those fixes do not protect the remaining callers.

**Fix:** Escape text/attributes with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`; use appropriate JSON encoding for JavaScript. Audit helper call sites before changing its behavior because some callers intentionally pass generated markup. Use `textContent` for untrusted client-side text. Add CSP as defense in depth.

### 4. High — guardian authority granted using only a school ID

**Evidence:** `parent/api/link.php:36`, `:68`; `parent/api/controls.php:53`, `:84`; `admin/doc.php:46`.

Any provisioned parent account can insert a link to a student identified only by school ID. No student consent, verified guardian record, approval, or expiring invitation is required. Subsequent controls and document access correctly check the link, but the link itself was established without proof of authority.

**Impact:** A parent account can obtain another student's wallet oversight, freeze/unfreeze access, change spending limits, and potentially access linked fee-waiver documents. A school ID is an identifier, not an authorization secret.

**Fix:** Validate the relationship against approved guardian records or require a verified, expiring invitation and approval before activating a link.

### 5. High — inconsistent CSRF and HTTP method enforcement

**Evidence:** `admin/api/economy.php:31`; `admin/api/users.php:30`; `admin/api/mint.php:44`; `merchant/api/topup.php:23`; `parent/api/link.php:28`; `parent/api/controls.php:28`; `merchant/api/staff.php:24`; `change_password.php:23`.

Many privileged or financial mutations do not call `gjc_csrf_verify()`. Examples include economy operations, account suspension/ban, minting, merchant top-ups, returns, guardian controls, staff creation/toggling, and the forced first-login password change. Some handlers merge GET and POST without enforcing POST, allowing mutations through navigation. The presence of a CSRF check in a file does not mean every action in it is protected: staff management and POS provide clear examples.

**Impact:** A browser with an authenticated session can be induced to perform unwanted changes. Browser SameSite defaults may reduce some cross-site POST attacks, but do not resolve state-changing GET actions or same-site attack paths.

**Fix:** Require POST and validate a session-bound CSRF token before every mutation. Include authentication/profile and first-login password-change flows. Do not treat CORS or role checks as substitutes.

### 6. High — Git metadata is served by the local web server

**Evidence:** `.htaccess:87`. Local HEAD requests returned **200 OK** for `.git/HEAD`, `.git/config`, and `.git/index`.

The dot-file `FilesMatch` rule does not deny ordinary filenames inside a dot directory. Disabling directory listing also does not prevent requesting known paths.

**Impact:** Repository metadata is exposed without login. Repository reconstruction and historical source exposure are possible if the corresponding objects/pack files are also served; object retrieval was not attempted. Historical credential exposure is a risk, not a finding established in this review.

**Fix:** Deny dot-directory paths at the server level and deploy a clean artifact without `.git`, local tooling, tests, backups or unrelated generated output. Verify direct nested-path requests return 403/404. Whether the same exposure exists publicly depends on production deployment.

### 7. High — ordinary login retains the session ID; cookie hardening is absent

**Evidence:** `record.php:21`; `connection/app.php:3218`, `:3343`; `.htaccess:95`.

Ordinary password login calls `gjc_establish_login_session()` without rotating the session ID. The only runtime `session_regenerate_id(true)` found is in remember-me restoration. Application code does not set strict session mode or secure session-cookie parameters.

The local web login returned a session cookie with only `path=/`, without `HttpOnly`, `Secure`, or explicit `SameSite`. HTTP login returned 200 rather than redirecting to HTTPS. The separate CLI runtime also has strict mode disabled, but its configuration must not be assumed to represent Apache: CLI reports PHP 8.2.12 while the web server reports PHP 8.3.30.

**Impact:** Session fixation is not explicitly prevented, script access to the cookie is not blocked by HttpOnly, and authenticated traffic depends on deployment TLS enforcement. Missing flags do not prove that a session was stolen.

**Fix:** Rotate IDs at login and privilege changes; enable strict mode and HttpOnly; set Secure for HTTPS deployment and explicit SameSite; enforce TLS and configure trusted proxy handling.

### 8. High — no application login throttling and distinguishable account errors

**Evidence:** `record.php:21`, `:208`, `:236`; `connection/MintingGuard.php:229`.

The login flow reports different errors for an unknown account and an incorrect password. No application rate limit or progressive delay was found. Failed logins and mint-PIN attempts are logged, but logging does not limit guessing. Several password-change/create flows accept six-character passwords. Upstream throttling was not verified.

**Impact:** Account enumeration and repeated password/PIN guessing remain feasible at the application layer.

**Fix:** Use a common login failure response, account/IP-aware throttling, and stronger password requirements. Protect finance access with MFA and sensitive minting with reauthentication and attempt limits.

### 9. Medium — password changes do not revoke other sessions or remember tokens

**Evidence:** `student/security.php:38`; `merchant/security.php:36`; `parent/profile.php:82`; `connection/app.php:3289`.

Password-change handlers update the hash but do not delete the user's `auth_remember_tokens` or invalidate other active sessions. Existing sessions are checked for account lockout, not password version. Remember-me restoration validates the stored token rather than whether it predates a password change.

**Impact:** A stolen persistent token or active session can continue working after a password change, until expiration or separate revocation.

**Fix:** Revoke all persistent tokens and invalidate other sessions on password reset/change or credential compromise; use a session version or credential-change timestamp.

### 10. Medium — limits are checked outside the transaction that enforces them

**Evidence:** `student/api/transfer.php:109`, `:176`; `connection/MintingGuard.php:75`, `:103`; `connection/CirculationEngine.php:939`.

The daily transfer aggregate is checked before the debit transaction, and minting reads the monthly aggregate before `increaseCirculationCap()` acquires its settings lock. The locked operation does not recheck the monthly ceiling. Payment and P2P routes also count different transaction subsets for the parent's daily spend limit.

**Impact:** Concurrent requests from separate sessions can pass against the same old aggregate and exceed limits without creating a negative balance. PHP's per-session serialization does not protect separate sessions for the same account. The race was identified statically, not load-tested against real funds.

**Fix:** Lock a shared account/limit record first, then compute and enforce the complete aggregate within the same transaction. Use a consistent definition of daily spending across routes.

### 11. Medium — excessive application database privilege and public upload resource pressure

**Evidence:** Local config selects the MySQL `root` account; `connection/app.php` contains request-time schema creation/alteration helpers. `apply_upload.php:35` invokes uploads without authentication; `connection/app.php:2821` caps each file at 5 MB but does not enforce a global quota or request rate limit.

**Impact:** Application compromise has a larger database blast radius if the configured root login has its usual grants; actual grants were not queried. Anonymous upload sessions can accumulate files and consume disk. Per-file type/size checks do not cap total storage.

**Fix:** Move schema changes to deployment migrations and use a least-privilege runtime database account. Add upload quotas, rate limits and scheduled cleanup, and keep private storage outside the public document root.

## Controls that are already useful

- PDO prepared statements are widely used; emulated prepares are disabled. No definite user-input SQL injection was established in the reviewed paths, which is not a guarantee of absence.
- Password hashing/verification uses PHP password APIs; removed plaintext fallbacks were not present in current login.
- Most role pages call a shared guard, with current deactivation/suspension checks.
- Newer QR checkout derives the payer from the session, locks pending orders, checks expiry and status, protects against double settlement, and updates balances transactionally.
- Several money paths use guarded balance updates and rollback. These protect accounting, but do not compensate for missing payer authorization in alternate routes.
- Upload handlers generally enforce MIME/extension allowlists and size limits; document proxies perform canonical path and ownership checks.
- Local HEAD checks confirmed 403 for `connection/local_config.php`, `storage/`, and `database/ewallet_0722.sql`.
- Remember-me validators are random and stored hashed. Audit code supports credential redaction, a separate writer, append-only triggers and a hash chain; live grants, triggers, completeness and monitoring were not verified.

## Additional observations and limits

PHPMailer 6.9.1 is vendored manually. Its presence alone was not treated as a confirmed exploitable vulnerability; a full dependency/advisory and external CDN integrity review remains necessary. The production server/PHP support status was not assessed. `SECURITY.md` is still an unfilled policy template. The application builds its base URL from request Host/forwarded-protocol headers without a configured trusted-host/proxy allowlist. Audit infrastructure failures log errors but can allow application work to continue without an audit entry. These warrant follow-up after the payment authorization defects are closed.

## Remediation order and acceptance checks

1. Disable or secure the two alternate debit paths immediately. Verify cross-account payments fail and merchants cannot debit without a payer-bound approval.
2. Correct output encoding and guardian link creation. Verify hostile names/products render as text and a school ID alone cannot activate guardian authority.
3. Require CSRF and POST centrally. Verify every mutation rejects missing/invalid tokens, including older actions in partially protected files.
4. Remove Git from deployment; harden sessions and TLS; add throttling and token/session revocation. Verify direct Git paths are denied and login changes the session ID.
5. Make limits atomic, reduce database privileges, and cap anonymous uploads. Verify simultaneous independent sessions cannot exceed the intended aggregate limits.

Re-rate after remediation and isolated authorization/concurrency tests. Passing happy-path financial tests alone would not justify raising the score.

Guidance used for interpreting findings: [OWASP authorization](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Authorization_Cheat_Sheet.md), [OWASP CSRF prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html), [OWASP session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), and [PHPMailer security policy](https://github.com/PHPMailer/PHPMailer/security).
