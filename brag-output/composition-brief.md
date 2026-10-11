# Hyperframes Composition Brief: GenPay

## Objective
A 25s launch-style brag video for GenPay, General de Jesus College's closed-loop GenCoin campus wallet and canteen stall system.

## Output
- Composition directory: `brag-output/composition/`
- Rendered video: `brag-output/brag.mp4`
- Format: landscape — 1920x1080
- Duration: 25s

## Source Material
- Project root: `C:\laragon\www\genpay`
- Primary files read: index.php, stalls.php, THEME.md, assets/css/theme.css tokens, CirculationEngine docs (.claude/skills/genpay-system/references/economy.md), admin/economy.php, merchant/pos.php, student/dashboard.php, parent/allowance.php, admin/leases.php, plus the UI captures in screenshots/ (visual reference only, never embedded, because they hold real data)
- Tagline: "The Future of Campus Cashless Payments"
- Verbatim copy: "₱10 = 1 GenCoin", "POS Terminal", "Generate Payment QR", "Total Money in System", "All money accounted for", "Now Leasing · Live Occupancy", "Stall Directory"

## Creative Direction
- Tone preset: app-store. Direction: confident campus-fintech launch, "a central bank for the canteen," played straight.
- Hook: "This canteen has its own currency." → ₱10 = 1 GC chip
- Outro: logo + "The Future of Campus Cashless Payments"
- Avoid: generic SaaS language, abstract filler, **gold lines/keylines/borders/focus rings** (the user's standing rule; gold is fills and text only)

## Visual Identity
- Dark `#0d2418`, forest `#123524`, green `#1e5c3a`, cream `#eef4ef`, card `#ffffff`, line `#e2eae3`
- Gold `#f0a500` (fills/text on dark); gold text on light surfaces `#8a6a10`
- Ink `#14251b`, muted `#6b7a70`; success `#15803d` / `#dcf3e4`
- Plus Jakarta Sans (display/body), IBM Plex Mono (amounts)
- Radius 4px for containers, pill for buttons only
- Assets: `assets/img/gp_logo.png`, `assets/img/GenSimeon-Bldg.jpg`

## Storyboard
Contract: `brag-output/brag-plan.md`, 7 scenes (Hook 3 / Wallet 3 / POS 4.5 / Closed loop 5 / Stalls 4 / Features 2.5 / Outro 3).

## Audio
- Music: `assets/music/happy-beats-business-moves-vol-1-by-ende-dot-app.mp3` @ 0.32, fade-in 0.4, fade-out 1.5
- Cue source: bundled preset (120.19 BPM). Beat-locks: 16.02 stall title, 20.02 first feature tile, 23.02 logo
- SFX (motion-matched, 0.55–0.8): mouseclick1 (POS taps), click_002 (Scan & Pay / Generate QR), chips-stack-2 (balance count), chips-collide-1 (Paid), drop_001/002 (reveals), card-slide-1 (stall deal), switch_002 (lease flip), impactSoft_medium_001 (hook), impactBell_heavy_000 (loop lock + logo)
- Audio-reactive: subtle. A precomputed per-frame RMS envelope is not available (the Hyperframes-creative extraction helper isn't installed), so it is skipped and documented.

## Hyperframes Instructions
One root composition with scenes as timed `.clip` elements, a single paused GSAP timeline registered on `window.__timelines`, deterministic only. Run `npx hyperframes check` before render.
