<?php
session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);
$currentUser = gjc_current_user($db);
$currentPage = 'restricted_products';

// Retroactively enforce the current rules over already-listed inventory. Catches
// items that slipped in before a rule existed or before matching was tightened
// (e.g. an obfuscated "C0br4"). Idempotent: only newly-matching items are touched.
$autoDisabled = gjc_enforce_restrictions_on_inventory($db);
gjc_ensure_product_violation_schema($db);

$violators = [];
if (in_array('restricted_violation_count', gjc_table_columns($db, 'users'), true)) {
    // Suspensions that already served their days clear lazily here, so the
    // table below only ever badges live ones.
    try {
        $db->exec("UPDATE users SET restricted_suspended_until = NULL WHERE restricted_suspended_until IS NOT NULL AND restricted_suspended_until <= NOW()");
    } catch (\Throwable $ignored) {}
    $violators = $db->query(
        "SELECT u.userID, u.first_name, u.last_name, u.restricted_violation_count, u.restricted_violation_last_at,
                u.restricted_suspended_until,
                m.stall_name, m.stall_id
           FROM users u
      LEFT JOIN merchant m ON m.userID = u.userID
          WHERE u.restricted_violation_count > 0 OR u.restricted_suspended_until IS NOT NULL
          ORDER BY (u.restricted_suspended_until IS NOT NULL) DESC, u.restricted_violation_count DESC, u.restricted_violation_last_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

$products = [];
if (gjc_table_exists($db, 'restricted_products')) {
    $products = $db->query(
        "SELECT rp.*, u.first_name, u.last_name
           FROM restricted_products rp
           LEFT JOIN users u ON u.userID = rp.flagged_by
          ORDER BY rp.is_active DESC, rp.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restricted Products | GenPay</title>
    <meta name="description" content="Nutritional compliance product blacklist management for GenPay.">
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=37">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="gp-theme">
<div class="admin-layout">
    <?php require_once __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

    <main class="admin-main">
        <header class="topbar">
            <button class="menu-btn" aria-label="Toggle navigation" onclick="document.getElementById('sidebar').classList.toggle('collapsed')"><i class="fa-solid fa-bars"></i></button>
            <div>
                <h1>Restricted Products</h1>
                <p>Nutritional compliance blacklist â€” prevents merchants from encoding prohibited items.</p>
            </div>
            <div class="admin-user">
                <span><?= gjc_e($currentUser['name']) ?></span>
                <div class="avatar"><i class="fa-solid fa-user-tie"></i></div>
            </div>
        </header>

        <!-- Info Banner -->
        <div style="background:linear-gradient(135deg,var(--gp-green-850) 0%,#0d7a3e 100%);color:#fff;padding:20px 28px;border-radius:16px;margin-bottom:24px;">
            <h4 style="margin:0 0 6px;font-weight:800;"><i class="fa-solid fa-utensils"></i> Nutritional Compliance Registry</h4>
            <p style="margin:0;opacity:.85;">Items listed here are cross-checked when merchants add products to their inventory. Matching items are automatically blocked and flagged with the reason on file &mdash; including disguised spellings (e.g. <code style="color:#fff">C0br4</code>), spacing/punctuation tricks, look-alike characters, and close misspellings.</p>
        </div>

        <?php if ($autoDisabled > 0): ?>
        <div class="alert alert-warning" style="border-radius:12px;margin-bottom:24px;">
            <i class="fa-solid fa-shield-halved"></i>
            Re-scan disabled <strong><?= (int) $autoDisabled ?></strong> already-listed merchant item<?= $autoDisabled === 1 ? '' : 's' ?> that matched an active restriction under the updated rules.
        </div>
        <?php endif; ?>

        <!-- Summary Row -->
        <section class="row g-4 mb-4">
            <?php
            $activeCount   = count(array_filter($products, function ($p) {
                return $p['is_active'];
            }));
            $inactiveCount = count($products) - $activeCount;
            ?>
            <div class="col-12 col-md-4">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                    <span>Total Restrictions</span>
                    <h2><?= count($products) ?></h2>
                    <p>Items on file</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="metric-card" style="border-left:4px solid var(--gp-red)">
                    <div class="metric-icon"><i class="fa-solid fa-ban"></i></div>
                    <span>Active Blocks</span>
                    <h2 style="color:var(--gp-red)"><?= $activeCount ?></h2>
                    <p>Currently enforced</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fa-solid fa-circle-pause"></i></div>
                    <span>Inactive</span>
                    <h2><?= $inactiveCount ?></h2>
                    <p>Deactivated rules</p>
                </div>
            </div>
        </section>

        <!-- Merchants at Risk -->
        <?php if (!empty($violators)): ?>
        <section class="premium-panel mb-4">
            <div class="panel-header d-flex justify-content-between align-items-center">
                <div>
                    <h3>Merchants at Risk</h3>
                    <p>Blocked attempts to add or rename a product into a restricted one, per merchant admin. Hitting <?= gjc_setting_int($db, 'violation_risk_at') ?> strikes auto-suspends the whole stall for <?= gjc_setting_int($db, 'violation_suspend_days') ?> days and restarts the count; the full history stays in the audit log.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table premium-table align-middle">
                    <thead>
                        <tr>
                            <th>Merchant / Stall</th>
                            <th>Violations</th>
                            <th>Last Attempt</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($violators as $v): ?>
                        <tr>
                            <td>
                                <strong><?= gjc_e($v['stall_name'] ?: trim($v['first_name'] . ' ' . $v['last_name'])) ?></strong>
                                <?php if ($v['stall_id']): ?><div class="text-muted" style="font-size:12px">Stall <?= gjc_e($v['stall_id']) ?></div><?php endif; ?>
                            </td>
                            <td><strong><?= (int) $v['restricted_violation_count'] ?></strong></td>
                            <td><small><?= $v['restricted_violation_last_at'] ? date('M d, Y g:i A', strtotime($v['restricted_violation_last_at'])) : '—' ?></small></td>
                            <td>
                                <?php if (!empty($v['restricted_suspended_until'])): ?>
                                    <span class="badge-danger">Suspended</span>
                                    <div class="text-muted" style="font-size:12px">until <?= date('M d, g:i A', strtotime($v['restricted_suspended_until'])) ?></div>
                                <?php elseif ((int) $v['restricted_violation_count'] >= gjc_setting_int($db, 'violation_warn_at')): ?>
                                    <span class="badge-danger">At Risk</span>
                                <?php else: ?>
                                    <span class="badge-warning">Watch</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($v['restricted_suspended_until'])): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="liftSuspension(<?= (int) $v['userID'] ?>, this)">
                                        <i class="fa-solid fa-unlock me-1"></i>Lift Suspension
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- Products Table -->
        <section class="premium-panel">
            <div class="panel-header d-flex justify-content-between align-items-center">
                <div>
                    <h3>Blacklisted Items</h3>
                    <p><?= count($products) ?> restriction(s) on file.</p>
                </div>
                <button class="view-btn" data-bs-toggle="modal" data-bs-target="#restrictModal" id="btn-flag-product">
                    <i class="fa-solid fa-flag"></i> Flag Product
                </button>
            </div>
            <div class="table-responsive">
                <table class="table premium-table align-middle js-datatable" id="restrictedProductsTable" data-page-length="10" data-empty-message="No restricted products flagged yet.">
                    <thead>
                        <tr>
                            <th>Product / Keyword</th>
                            <th>Category</th>
                            <th>Match Type</th>
                            <th>Reason</th>
                            <th>Flagged By</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($products)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-5">No restricted products flagged yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><strong><?= gjc_e($p['product_name']) ?></strong></td>
                            <td><span class="badge-warning"><?= gjc_e(ucwords(str_replace('_', ' ', $p['category']))) ?></span></td>
                            <td><code><?= gjc_e($p['match_type']) ?></code></td>
                            <td style="max-width:280px;font-size:13px"><?= gjc_e($p['reason']) ?></td>
                            <td><?= gjc_e(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))) ?></td>
                            <td><small><?= date('M d, Y', strtotime($p['created_at'])) ?></small></td>
                            <td>
                                <?php if ($p['is_active']): ?>
                                    <span class="badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm <?= $p['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                    onclick="toggleRestriction(<?= (int)$p['id'] ?>, <?= $p['is_active'] ? 0 : 1 ?>)">
                                    <?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- Flag Product Modal -->
<div class="modal fade" id="restrictModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content custom-modal">
            <div class="modal-header"><h5 class="modal-title">Flag Restricted Product</h5></div>
            <div class="modal-body">
                <form id="restrictForm">
                    <input type="hidden" name="action" value="flag_product">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Product Name / Keyword *</label>
                            <input type="text" class="form-control" name="product_name" required
                                placeholder="e.g. Coca-Cola, Soda, Energy Drink">
                            <div class="form-text">Use a keyword that appears in the product name. Match type controls how it's compared.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category</label>
                            <select class="form-select" name="category">
                                <option value="beverage">Beverage</option>
                                <option value="snack">Snack</option>
                                <option value="junk_food">Junk Food</option>
                                <option value="candy">Candy</option>
                                <option value="general">General</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Match Type</label>
                            <select class="form-select" name="match_type">
                                <option value="contains">Contains (substring match)</option>
                                <option value="exact">Exact (full name only)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Reason / Policy Note *</label>
                            <textarea class="form-control" name="reason" rows="2" required
                                placeholder="e.g. High sugar content â€” DepEd nutritional guidelines prohibit this item."></textarea>
                        </div>
                    </div>
                    <div id="restrictMsg" class="mt-3"></div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="login-btn" style="flex:1" id="restrictSubmitBtn">Flag Product</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
<script>
document.getElementById('restrictForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('restrictSubmitBtn');
    btn.disabled = true; btn.textContent = 'Flagging...';
    const resp = await fetch('<?= ADMIN_URL ?>/api/restricted_products.php', {
        method: 'POST', body: new FormData(this)
    });
    const data = await resp.json();
    const msg = document.getElementById('restrictMsg');
    if (data.success) {
        msg.innerHTML = '<div class="alert alert-success">Product flagged successfully. Reloading...</div>';
        setTimeout(() => location.reload(), 1300);
    } else {
        msg.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
        btn.disabled = false; btn.textContent = 'Flag Product';
    }
});

async function toggleRestriction(id, newStatus) {
    const f = new FormData();
    f.append('action', 'toggle_restriction');
    f.append('id', id);
    f.append('is_active', newStatus);
    const resp = await fetch('<?= ADMIN_URL ?>/api/restricted_products.php', { method: 'POST', body: f });
    const data = await resp.json();
    if (data.success) location.reload();
    else alert('Error: ' + data.message);
}

async function liftSuspension(userId, btn) {
    if (!confirm('Lift this merchant\'s suspension early? They and their staff will be able to log in and sell again immediately.')) return;
    btn.disabled = true;
    const f = new FormData();
    f.append('action', 'lift_suspension');
    f.append('user_id', userId);
    const resp = await fetch('<?= ADMIN_URL ?>/api/restricted_products.php', { method: 'POST', body: f });
    const data = await resp.json();
    if (data.success) location.reload();
    else { alert('Error: ' + data.message); btn.disabled = false; }
}
</script>
</body>
</html>
