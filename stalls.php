<?php
require_once __DIR__ . "/connection/config.php";
require_once __DIR__ . "/connection/pdo.php";
require_once __DIR__ . "/connection/StallManager.php";

$stallMgr = new StallManager($db);
$stallMgr->flushExpiredPending(); // App-level expiry flush - runs before render
$stalls = $stallMgr->allStalls();

// Build a 5×2 grid: [rowLabel][colNumber] => stall
$grid = [];
foreach ($stalls as $s) {
    $grid[$s["row_label"]][$s["col_number"]] = $s;
}
ksort($grid);

// Status helpers
function statusClass(string $status): string
{
    return match ($status) {
        "occupied" => "stall--occupied",
        "pending_application" => "stall--pending",
        default => "stall--vacant",
    };
}
function statusLabel(string $status): string
{
    return match ($status) {
        "occupied" => "Occupied",
        "pending_application" => "Pending",
        default => "Vacant",
    };
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
    <title>Available Stalls | GenPay</title>
    <meta name="description" content="Browse available stalls at General de Jesus College. View real-time occupancy and apply online for a stall slot.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
    <link rel="stylesheet" href="<?= CSS_URL ?>/public_shell.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/stalls.css?v=17">
</head>
<body>

<?php $publicNavCurrent = 'stalls'; include __DIR__ . '/includes/partials/public_navbar.php'; ?>

<!-- HERO -->
<div class="hero">
    <div class="hero-content">
        <div class="hero-tag">Now Leasing &middot; Live Occupancy</div>
        <h1>General De Jesus College <span>Stall Directory</span></h1>
        <p>See what's open below. Apply once and we'll assign your stall during review &mdash; no need to pick one here.</p>
        <a href="<?= BASE_URL ?>/apply" class="btn-primary-custom">Apply Now</a>
    </div>
</div>

<!-- LEGEND -->
<div class="legend">
    <div class="legend-item"><div class="legend-dot legend-dot--vacant"></div> Vacant</div>
    <div class="legend-item"><div class="legend-dot legend-dot--occupied"></div> Occupied</div>
    <div class="legend-item"><div class="legend-dot legend-dot--pending"></div> Pending Application</div>
</div>

<!-- STALL GRID -->
<div class="content">
    <?php foreach ($grid as $rowLabel => $cols): ?>
        <p class="section-title">Row <?= htmlspecialchars($rowLabel) ?></p>
        <div class="stall-row" role="list">
            <?php for ($col = 1; $col <= 5; $col++):

                $stall = $cols[$col] ?? null;
                if (!$stall) {
                    continue;
                }
                $cls = statusClass($stall["status"]);
                $lbl = statusLabel($stall["status"]);
                ?>
            <?php
            // Rental rate stays out of the public payload entirely - not just
            // unrendered - so it never appears in view-source/devtools either.
            $publicStall = $stall;
            unset($publicStall["monthly_rate"]);
            ?>
            <div class="stall <?= $cls,
                $stall["status"] === "occupied" && $stall["merchant_logo"]
                    ? " stall--has-logo"
                    : "" ?>"
                 id="stall-<?= htmlspecialchars($stall["stall_id"]) ?>"
                 role="listitem"
                 data-stall='<?= htmlspecialchars(
                     json_encode($publicStall),
                     ENT_QUOTES,
                 ) ?>'
                 <?php if (
                     $stall["status"] === "occupied" &&
                     $stall["merchant_logo"]
                 ): ?>
                 style="background-image:url('<?= htmlspecialchars(
                     BASE_URL . "/" . $stall["merchant_logo"],
                 ) ?>')"
                 <?php endif; ?>
                 <?php if ($stall["status"] === "vacant"): ?>
                 onclick="openModal(this)"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter')openModal(this)"
                 aria-label="<?= htmlspecialchars(
                     $stall["label"],
                 ) ?> - Vacant, view details"
                 <?php elseif ($stall["status"] === "occupied"): ?>
                 onclick="openModal(this)"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter')openModal(this)"
                 aria-label="<?= htmlspecialchars(
                     $stall["label"],
                 ) ?> - Operated by <?= htmlspecialchars(
     $stall["merchant_stall_name"],
 ) ?>"
                 <?php else: ?>
                 onclick="openModal(this)"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter')openModal(this)"
                 aria-label="<?= htmlspecialchars(
                     $stall["label"],
                 ) ?> - Pending application"
                 <?php endif; ?>
            >
                <?php if ($stall["status"] === "occupied"): ?>
                    <!-- A tenant logo + company name implies occupancy; no "Occupied" text needed.
                         The logo is the card's background image, and the stall ID sits on
                         top inside a circular badge, like a sticker. -->
                    <div class="stall-id-badge stall-id-badge--circle"><?= htmlspecialchars(
                        $stall["stall_id"],
                    ) ?></div>
                    <div class="stall-name"><?= htmlspecialchars(
                        $stall["merchant_stall_name"],
                    ) ?></div>
                <?php else: ?>
                    <div class="stall-id-badge"><?= htmlspecialchars(
                        $stall["stall_id"],
                    ) ?></div>
                    <div class="stall-status-badge"><?= $lbl ?></div>
                    <?php if ($stall["status"] === "pending_application"): ?>
                    <div class="stall-timer"
                         data-expires="<?= htmlspecialchars(
                             $stall["pending_expires_at"] ?? "",
                         ) ?>">
                         --:--
                    </div>
                    <?php else: ?>
                    <div class="stall-apply-hint">View details</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php
            endfor; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- PEEK MODAL -->
<div class="modal-overlay" id="stallModal" role="dialog" aria-modal="true" aria-labelledby="modal-stall-id">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <div class="modal-stall-id" id="modal-stall-id">--</div>
                <div class="modal-stall-label" id="modal-stall-label">--</div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="modal-status-chip" id="modal-status-chip">--</span>
                <button type="button" class="btn-close" onclick="closeModal()" aria-label="Close"></button>
            </div>
        </div>

        <div class="modal-body">

            <!-- Specs grid (rental rate stays out of public view - finance-only) -->
            <div class="modal-specs">
                <div class="spec-item">
                    <div class="spec-label">Area</div>
                    <div class="spec-value" id="modal-area">--</div>
                </div>
                <div class="spec-item">
                    <div class="spec-label">Location</div>
                    <div class="spec-value" id="modal-location">--</div>
                </div>
                <div class="spec-item">
                    <div class="spec-label">Stall ID</div>
                    <div class="spec-value" id="modal-id-detail">--</div>
                </div>
            </div>

            <!-- Occupied block - logo + name implies occupancy, no "Occupied" text -->
            <div class="modal-merchant-block" id="modal-merchant-block" style="display:none">
                <div class="modal-merchant-avatar" id="modal-merchant-avatar">?</div>
                <div>
                    <div class="modal-merchant-name" id="modal-merchant-name">--</div>
                    <div class="modal-merchant-sub">Currently operating</div>
                </div>
            </div>

            <!-- Pending block -->
            <div class="modal-pending-block" id="modal-pending-block" style="display:none">
                <div class="modal-pending-text">⏳ An application is being processed</div>
                <div class="modal-pending-timer" id="modal-pending-timer">--:--</div>
                <div style="font-size:11px;color:var(--gp-warning);margin-top:4px;">Stall will reopen if not submitted in time</div>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/partials/public_footer.php'; ?>

<script>
const BASE_URL = '<?= BASE_URL ?>';
let modalTimer = null;

function openModal(el) {
    const data = JSON.parse(el.dataset.stall);
    const modal = document.getElementById('stallModal');

    // Header
    document.getElementById('modal-stall-id').textContent   = data.stall_id;
    document.getElementById('modal-stall-label').textContent = data.label;
    document.getElementById('modal-id-detail').textContent   = data.stall_id;

    // Status chip - occupied has no chip; the logo + name below implies it
    const chip = document.getElementById('modal-status-chip');
    const chipMeta = { vacant: ['Vacant', 'chip--vacant'], pending_application: ['Pending', 'chip--pending'] }[data.status];
    chip.style.display = chipMeta ? 'inline-block' : 'none';
    if (chipMeta) { chip.textContent = chipMeta[0]; chip.className = 'modal-status-chip ' + chipMeta[1]; }

    // Specs (rental rate is finance-only, not shown publicly)
    document.getElementById('modal-area').textContent     = data.area_sqm ? data.area_sqm + ' m²' : 'N/A';
    document.getElementById('modal-location').textContent = 'Row ' + data.row_label + ', Slot ' + data.col_number;

    // Conditional blocks - this modal is informational only. Applying
    // happens exactly one way on this page: the Apply Now button in the hero.
    const merchantBlock = document.getElementById('modal-merchant-block');
    const pendingBlock  = document.getElementById('modal-pending-block');

    merchantBlock.style.display = 'none';
    pendingBlock.style.display  = 'none';
    if (modalTimer) clearInterval(modalTimer);

    if (data.status === 'occupied') {
        merchantBlock.style.display = 'flex';
        const name = data.merchant_stall_name || 'This Stall';
        const avatar = document.getElementById('modal-merchant-avatar');
        document.getElementById('modal-merchant-name').textContent = name;
        if (data.merchant_logo) {
            avatar.innerHTML = `<img src="${BASE_URL}/${data.merchant_logo}" alt="${name} logo" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
        } else {
            avatar.textContent = name.charAt(0).toUpperCase();
        }

    } else if (data.status === 'pending_application') {
        pendingBlock.style.display = 'block';
        startTimer(data.pending_expires_at, 'modal-pending-timer');
    }

    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('stallModal').classList.remove('is-open');
    document.body.style.overflow = '';
    if (modalTimer) clearInterval(modalTimer);
}

function startTimer(expiresAt, targetId) {
    const el  = document.getElementById(targetId);
    const end = expiresAt ? new Date(expiresAt.replace(' ', 'T')).getTime() : 0;

    function tick() {
        const diff = Math.max(0, Math.round((end - Date.now()) / 1000));
        const m    = String(Math.floor(diff / 60)).padStart(2, '0');
        const s    = String(diff % 60).padStart(2, '0');
        if (el) el.textContent = m + ':' + s;
        if (diff <= 0 && modalTimer) clearInterval(modalTimer);
    }
    tick();
    modalTimer = setInterval(tick, 1000);
}

// Countdown timers on stall cards
document.querySelectorAll('.stall-timer[data-expires]').forEach(el => {
    const end = new Date(el.dataset.expires.replace(' ', 'T')).getTime();
    function tick() {
        const diff = Math.max(0, Math.round((end - Date.now()) / 1000));
        const m = String(Math.floor(diff / 60)).padStart(2, '0');
        const s = String(diff % 60).padStart(2, '0');
        el.textContent = m + ':' + s;
        if (diff <= 0) { clearInterval(t); location.reload(); }
    }
    tick();
    const t = setInterval(tick, 1000);
});

// Close modal on overlay click
document.getElementById('stallModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Close on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>

</body>
</html>
