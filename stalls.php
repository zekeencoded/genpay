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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800&family=Oswald:wght@600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/public_shell.css?v=6">
    <link rel="stylesheet" href="<?= CSS_URL ?>/public_theme.css?v=1">
    <link rel="stylesheet" href="<?= CSS_URL ?>/stalls.css?v=18">
</head>
<body class="landing">

<?php $publicNavCurrent = 'stalls'; include __DIR__ . '/includes/partials/public_navbar.php'; ?>

<header class="hero-simple">
    <div class="wrap stalls-hero">
        <div class="hero-text">
            <p class="hero-tag">Now Leasing &middot; Live Occupancy</p>
            <h1>General De Jesus College <span>Stall Directory</span></h1>
            <p>See what's open below. Apply once and we'll assign your stall during review &mdash; no need to pick one here.</p>
            <a href="<?= BASE_URL ?>/apply" class="cta">Apply Now</a>
        </div>

        <!-- A campus stall, drawn in code, with a GenCoin sign that bobs gently. -->
        <svg class="stall-art" viewBox="0 0 420 340" aria-hidden="true">
            <circle cx="210" cy="190" r="150" fill="#0e4a1c"/>
            <circle class="sa-ring" cx="210" cy="190" r="172"/>
            <rect x="80" y="128" width="260" height="176" rx="12" fill="#fff"/>
            <rect x="60" y="82" width="300" height="50" rx="10" fill="#f0a000"/>
            <g fill="#ffc94d">
                <rect x="90" y="82" width="30" height="50"/><rect x="150" y="82" width="30" height="50"/>
                <rect x="210" y="82" width="30" height="50"/><rect x="270" y="82" width="30" height="50"/>
            </g>
            <g fill="#f0a000">
                <?php for ($cx = 75; $cx <= 345; $cx += 30): ?><circle cx="<?= $cx ?>" cy="132" r="15"/><?php endfor; ?>
            </g>
            <rect x="105" y="160" width="210" height="78" rx="8" fill="#eef4ef"/>
            <rect x="124" y="196" width="34" height="42" rx="6" fill="#138a36"/>
            <circle cx="193" cy="220" r="18" fill="#f0a000"/>
            <rect x="228" y="186" width="26" height="52" rx="6" fill="#003000"/>
            <rect x="266" y="204" width="32" height="34" rx="6" fill="#138a36"/>
            <rect x="66" y="238" width="288" height="24" rx="6" fill="#138a36"/>
            <rect x="120" y="276" width="180" height="14" rx="7" fill="#dfe8dd"/>
            <g class="sa-sign">
                <line x1="210" y1="44" x2="210" y2="82" stroke="#fff" stroke-width="4"/>
                <circle cx="210" cy="38" r="30" fill="#f0a000" stroke="#fff" stroke-width="5"/>
                <text x="210" y="49" text-anchor="middle" font-family="Oswald, Arial, sans-serif" font-size="32" font-weight="700" fill="#003000">G</text>
            </g>
        </svg>
    </div>
</header>

<div class="legend wrap" aria-label="Legend">
    <div class="legend-item"><span class="legend-dot legend-dot--vacant"></span> Vacant</div>
    <div class="legend-item"><span class="legend-dot legend-dot--occupied"></span> Occupied</div>
</div>

<main class="content wrap">
    <?php foreach ($grid as $rowLabel => $cols): ?>
        <h2 class="section-title">Row <?= htmlspecialchars($rowLabel) ?></h2>
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
                    <!-- Logo as background, stall ID in a circular badge. -->
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
</main>

<div class="modal-overlay" id="stallModal" role="dialog" aria-modal="true" aria-labelledby="modal-stall-id">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <div class="modal-stall-id" id="modal-stall-id">--</div>
                <div class="modal-stall-label" id="modal-stall-label">--</div>
            </div>
            <div class="modal-actions">
                <span class="modal-status-chip" id="modal-status-chip">--</span>
                <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
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

            <div class="modal-pending-block" id="modal-pending-block" style="display:none">
                <div class="modal-pending-text">⏳ An application is being processed</div>
                <div class="modal-pending-timer" id="modal-pending-timer">--:--</div>
                <div class="modal-pending-note">Stall will reopen if not submitted in time</div>
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

document.getElementById('stallModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>

</body>
</html>
