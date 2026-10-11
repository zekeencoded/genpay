# Brag Plan: GenPay

## What is this app?
GenPay is General de Jesus College's campus wallet: students pay canteen stalls in GenCoin (₱10 = 1 GC) by QR. Every coin lives inside a closed loop that the database itself checks, and the same app runs the canteen's stall leasing.

## The angle
**"A school canteen that runs its own central bank."** Most school projects stop at "a QR payment app." GenPay has a money supply cap, a cashier vault, and MySQL triggers that refuse any write that would unbalance the books. It also rents out the canteen stalls. The video plays that scale straight: fintech-launch confidence applied to kwek-kwek and tapsilog. The joke tells itself, because it's all true.

## Hook (first 2-3 seconds)
Over the dark forest-green hero with the GJC building photo: **"This canteen has its own currency."** It lands and holds. Then a gold GenCoin chip slams in: **₱10 = 1 GC**.

## Key moments (the middle)
- **Student wallet:** phone card, balance counts up to **62.58 GC ≈ ₱625.80**, and a thumb taps **Scan & Pay**.
- **Merchant POS:** a cursor taps **Tapsilog 5.5 GC**, **Kwek-Kwek 3.5 GC** and **Fishball 2 GC**, one at a time. Current Order fills, the total ticks to **11 GC**, "Generate Payment QR" is clicked, and a green **Paid** stamp lands.
- **Closed loop (hero beat):** "Total Money in System **25,500.0 GC**". The stacked bar fills segment by segment (Cashier Vault / Student / Merchant / Parent wallets), then the equation locks: `Cap = Vault + Students + Merchants + Parents`, followed by the badge **"All money accounted for"**.
- **Stall management:** "Stall Directory · Live Occupancy". A grid of 10 stalls (Row A / Row B) deals in. Two are occupied, and one flips from **Pending Application** to **Leased** with a rent-roll chip.
- **Everything else:** four feature tiles hold together: Parent allowance controls, Encashments, Top-ups, Audit log.

## Outro / punchline
GenPay logo plus the site's own line: **"The Future of Campus Cashless Payments"**. Small print underneath: *General de Jesus College*.

## User flow worth showing
Student opens wallet → merchant rings up items on the POS → payment QR → Paid → the economy view proves the coin never left the loop. Stall application → lease is the merchant-onboarding flow.

## Tone
- Preset: `app-store`
- Creative direction: confident campus-fintech launch, "a central bank for the canteen," played straight
- Interpretation: clean feature-card reveals and smooth slides. The humour comes from bank-grade seriousness applied to street food, never from gags.

## Format: landscape — 1920x1080
## Duration: 26s (Scan & Pay scene added; the standalone wallet scene was folded into it)

## Visual identity (from the project — assets/css/theme.css)
- Background: `#0d2418` (gp-green-950) dark scenes; `#eef4ef` (gp-cream) light scenes
- Accent: `#f0a500` (gp-gold), fills and text only. **No gold hairlines, keylines or borders** (the user's standing rule).
- Text: `#14251b` ink / `#ffffff` on dark; muted `#6b7a70`
- Brand greens: `#123524`, `#1e5c3a`; success `#15803d` on `#dcf3e4`
- Display + body font: Plus Jakarta Sans; numbers in IBM Plex Mono
- Strongest visual element: the Economy "Total money in system" stacked bar + "All money accounted for" badge; the stall grid cards

## Privacy substitution
The screenshots contain real students and merchants. The video uses **fictional** stand-ins only: no student names, and stall names "Tita Nena's Silog" and "Kuya Jun's Street Food". Economy figures are illustrative.

## Share copy (draft)
Our school canteen now has its own currency. GenPay: GenCoin wallets, a POS for every stall, a closed-loop economy the database refuses to let drift, and stall leasing, all in one campus app.

## Audio direction
- Role: warm upbeat bed + a consistent light UI layer
- Music: `happy-beats-business-moves-vol-1-by-ende-dot-app.mp3` (120 BPM)
- Music treatment: 0.32 volume, 0.4s fade-in, 1.5s fade-out under the logo
- Music cue guidance: bundled preset `assets/music/cues/happy-beats-business-moves-vol-1-by-ende-dot-app.music-cues.json`. Strong cues: **16.02s** (stall grid title), **20.02s** (feature tiles), **23.02s** (logo). Beat grid every ~0.5s from 3.02s. POS taps land on every other beat (~1.0s apart) so each item reads.
- Audio-reactive treatment: subtle. The outro logo glow and the economy bar's sheen breathe with the bed. No visualizers.
- SFX posture: moderate and motion-matched. Clicks for taps, a chip clink on Paid, soft slides for stall cards, one bell on the loop lock and one on the logo.
- Restraint rule: never score every stall card; accent the first and the flip only.

## Storyboard

### Scene 1 — Hook — 0.0–3.0s
Building photo under a forest-green wash. "This canteen has its own currency." rises in (white, gold on "its own currency"). At 1.8s the gold ₱10 = 1 GC chip pops.
Sequential/interaction: headline then chip
Audio intent: confident open
Audio-coupled idea: soft impact on the headline, a drop on the chip
Transition mood: slide → Scene 2

### Scene 2 — Merchant POS — 3.0–7.0s
POS Terminal recreation. A cursor taps Tapsilog, Kwek-Kwek and Fishball on beats 3.52 / 4.53 / 5.53, and the total ticks to 11 GC. Clicking "Generate Payment QR" (6.25) swaps the order panel for **Scan to pay**: the QR, 11 GC ≈ ₱110.00, and "Waiting for student to scan…". Caption: **"Every stall gets a POS."**
Transition mood: push → Scene 3

### Scene 3 — Scan & Pay — 7.0–11.8s  *(added on request)*
Student phone, Scan & Pay camera. The **same QR** drifts into the viewfinder while a scan line sweeps, then locks at 8.3 (the brackets snap green). The **Confirm Payment** sheet slides up (copy from student/cart.php: Total to pay 11 GC · Merchant · Items 3 · Peso value ₱110.00). Confirm Payment is tapped at 9.6, then **Payment successful**, −11.00 GC, and the new balance counts 62.58 → 51.58 GC. Caption: **"Scan the stall's QR. One tap. Paid."** with a 1 Scan / 2 Confirm / 3 Paid step row.
Audio: select beep on lock, slide on sheet, click on confirm, chip clink on paid.
Transition mood: push → Scene 4

### Scene 4 — Closed loop — 11.8–16.4s
Cream background, System Economy card. "TOTAL MONEY IN SYSTEM" / 25,500.0 GC. The stacked bar fills in 4 segments (Vault 90.7% / Students 8.5% / Merchants 0.5% / Parents 0.4%), with a legend under it. Then an equation strip lands: `Cap = Vault + Students + Merchants + Parents`, followed by the badge **All money accounted for** at 13.5 and a hold. Headline: **"A closed loop. Every coin accounted for."**
Audio-coupled idea: soft drops per segment, bell on the badge
Transition mood: slide → Scene 5

### Scene 5 — Stall management — 16.4–20.4s
Dark hero strip: "Now Leasing · Live Occupancy" / **Stall Directory** (title at the 16.02 cue). Ten stall cards deal in (A1–A5, B1–B5). A2 "Tita Nena's Silog" and B1 "Kuya Jun's Street Food" show as occupied. B3 shows Pending, then flips to **LEASED** at 18.0, and a chip "Rent roll · ₱3,500/mo" appears.
Caption: **"And it runs the canteen's stalls."**
Audio-coupled idea: one slide on the deal, switch on the flip
Transition mood: slide → Scene 6

### Scene 6 — Everything else — 20.4–23.0s
Four tiles land on the beat grid (20.02, 20.52, 21.02, 21.52 — a quick reveal, then the full set holds): Parent allowance & spending controls · Top-ups & encashments · Immutable audit log · QR vouchers. Header: **"Built for the whole campus."**
Audio-coupled idea: a drop on the first and last tile
Transition mood: soft crossfade → Scene 7

### Scene 7 — Outro — 23.0–26.0s
Dark field, GenPay logo scales in (lock near 23.02), "GenPay", then "The Future of Campus **Cashless Payments**" (gold on the second line, as on the landing page) and a small "General de Jesus College".
Audio-coupled idea: bell on the logo, music fades out

**Music mood for this video:** upbeat
**Audio summary:** a warm upbeat bed under crisp UI clicks, with two bells marking the loop lock and the logo.
