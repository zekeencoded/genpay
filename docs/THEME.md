# GenPay Theme — the Scan & Pay design language

One design system for every role shell. Source of truth: **`assets/css/theme.css`**.
Extracted from the student Scan & Pay pages; consumed by the student
(`student_dashboard.css`, `--sd-*` aliases), admin (`admin.css`, `--emerald-*`/`--gold*`
aliases), merchant (`merchant.css`, same `--emerald-*`/`--gold*` alias pattern), and
parent (`parent_shell.css`, `--emerald-*`/`--gp-*` alias pattern) stylesheets.
**Change a color once in theme.css and every shell follows.**

## Palette tokens (`:root`)

| Token | Value | Use for |
|---|---|---|
| `--gp-green-950` | `#0d2418` | Deepest forest — sidebar bottom, topbar, hero gradients |
| `--gp-green-900` | `#123524` | Forest — gradient partner, hover fills |
| `--gp-green-850` | `#17402c` | Elevated dark surfaces (avatar tiles, icon chips on dark) |
| `--gp-green-700` | `#1e5c3a` | Lightest brand green — accents, chart lines, gradient ends |
| `--gp-gold` | `#f0a500` | THE accent: active nav, key numbers, focus, primary CTAs |
| `--gp-gold-light` | `#f6b81f` | Gold gradient partner (`gold-light → gold`, 135deg) |
| `--gp-gold-soft` | `rgba(240,165,0,.14)` | Gold-tinted fills (active nav bg, icon chips) |
| `--gp-gold-line` | `rgba(240,165,0,.45)` | Gold hairline borders on chips/badges |
| `--gp-gold-deep` | `#8a6a10` | Gold as **text on light surfaces** (contrast-safe) |
| `--gp-cream` | `#eef4ef` | Page background; also soft fills (table headers) |
| `--gp-card` | `#ffffff` | Content cards |
| `--gp-line` | `#e2eae3` | Hairline borders |
| `--gp-ink` / `--gp-muted` | `#14251b` / `#6b7a70` | Body text / secondary text |
| `--gp-subtle` | `#98a79d` | Tertiary text: placeholders, input adornments, "(optional)", empty states, disabled |
| `--gp-success` on `--gp-success-bg` | `#15803d` / `#dcf3e4` | Completed, approved, active |
| `--gp-warning` on `--gp-warning-bg` | `#b45309` / `#fdf1d8` | Pending, processing |
| `--gp-danger` on `--gp-danger-bg` | `#b42318` / `#fde2e2` | Failed, rejected, frozen, blocked |
| `--gp-info` on `--gp-info-bg` | `#2563eb` / `#e3edfd` | Neutral-informational |
| `--gp-green` / `--gp-red` | `#16a34a` / `#dc2626` | Money-in amounts / money-out & destructive |
| `--gp-radius` | `4px` | Containers: cards, panels, inputs, tables |
| `--gp-radius-tag` | `2px` | Inline tags and badges — tighter than containers |
| `--gp-radius-pill` | `999px` | **Buttons only.** Shape alone says "pressable" |
| `--gp-mono` | IBM Plex Mono | References, amounts, IDs (all numbers) |
| `--gp-grad-hero` | flat `#0d2418` | Dark hero cards. Name kept, value is flat — no gradient and no keyline; the dark field alone makes a hero a hero |
| `--gp-grad-shell` | flat `#0d2418` | Sidebar |
| `--gp-grad-forest-btn` | flat `#1e5c3a` | Primary commit buttons |
| `--gp-keyline` | `1px solid` `--gp-line` | **Retired.** Was the 2px gold "system signature" on heroes; removed as decoration. Defined only so a stray reference still resolves — do not use |
| `--gp-shadow-card` | `0 1px 2px` | Resting surfaces |
| `--gp-shadow-raised` | `0 1px 3px` | Heroes, sticky bars (`--gp-shadow-hero` is an alias) |
| `--gp-shadow-overlay` | `0 10px 22px` | Modals, dropdowns, notification panel |
| `--gp-focus-ring` | `0 0 0 3px` ink @ 16% | **The** focus treatment, for every interactive element. Dark neutral, not gold |
| `--gp-focus-border` | `--gp-ink` | The border colour a focused field takes. Pair with `--gp-focus-ring` |

### The neutral ramp

Dark to light, by perceptual lightness (L\*):

`--gp-ink` 12.9 → `--gjc-slate` 30.7 → `--gp-muted` 49.8 → **`--gp-subtle` 67.1** →
`--gp-line` 91.9 → `--gp-line-soft` 95.4 → `--gp-cream` 95.6 → `--gp-row-hover` 97.9 →
`--gp-card` 100.

`--gp-subtle` was added on 2026-08-27 to close a 42-point hole between "secondary
text" and "border". That hole is why Tailwind greys (`#9ca3af`, `#94a3b8`) kept
appearing across seventeen sites — someone needed a tertiary tone the palette did
not offer, repeatedly. Its value is derived rather than picked: it lands mid-gap and
uses `--gp-muted`'s exact channel shape (`g = r+15`, `b = r+5`), so it reads as that
colour lightened, not as an import.

**These neutrals are warm and green-tinted** (channel spread 4–17). Cool blue-greys
— anything from a Tailwind/slate ramp — look visibly wrong beside them. If you need
a neutral, it is one of the nine above. There is no tenth.

## Space — 4pt scale

`--gp-space-1` `4px` · `-2` `8px` · `-3` `12px` · `-4` `16px` · `-5` `20px` ·
`-6` `24px` · `-7` `32px` · `-8` `40px` · `-9` `48px` · `-10` `64px`

Every margin, padding and gap comes from this scale. 8pt was rejected deliberately:
this is a data-dense wallet UI, and on 8pt the gap between a label and its input
collapses into the gap between two table rows.

`--gp-space-nudge` (`2px`) is the **only** exception and has exactly one job: the
optical lift on a subtitle sitting under a heading (`margin: 2px 0 0`). That is
typographic correction, not layout rhythm. Naming it is what stops `2px` reopening
as a general half-step — don't use it for anything else.

## Type ramp

| Token | Size | Use for |
|---|---|---|
| `--gp-text-2xs` | `11px` | Uppercase micro-labels, table headers, badges |
| `--gp-text-xs` | `12px` | Captions, helper text, timestamps, meta |
| `--gp-text-sm` | `13px` | Dense UI: table cells, nav items, form labels |
| `--gp-text-base` | `14px` | Body default, buttons, inputs |
| `--gp-text-md` | `16px` | Card titles, emphasised body |
| `--gp-text-lg` | `18px` | Page title (`h1`), panel headings |
| `--gp-text-xl` | `22px` | Section headings, secondary figures |
| `--gp-text-2xl` | `28px` | Hero sub-figures |
| `--gp-text-3xl` | `34px` | Hero balance |
| `--gp-text-4xl` | `40px` | Dashboard headline figures |
| `--gp-text-5xl` | `56px` | Public marketing only (`index.php`) |

The four steps between 11px and 14px are 1px apart **on purpose**: 76% of this app's
type lives in that band, and a table header, a table cell and a caption genuinely need
to differ. Each step has a distinct job. What is not allowed is a size with no job —
**there are no half-pixel sizes.** If a size is not in this table, it is not a size.

**Line height:** `--gp-leading-none` `1` (figures, single-line chrome) ·
`-tight` `1.2` (display 28px+, h1/h2) · `-snug` `1.35` (16–22px headings, card titles,
table cells) · `-normal` `1.5` (body paragraphs) · `-relaxed` `1.65` (long-form).

**Font weight — only `400` `500` `600` `700` `800`.** Plus Jakarta Sans and Manrope
ship nothing else. A weight outside that set silently snaps to a neighbour, so `850`
and `800` render *identically* while reading as a deliberate distinction in source.
71 such declarations were found and corrected; don't reintroduce them.

## Motion

`--gp-transition` `150ms ease` · `--gp-transition-slow` `250ms ease` (width/margin,
sidebar collapse only).

**Name the properties you animate — never `transition: all`.** It animates layout
properties you did not intend and costs a frame. theme.css carries one global
`prefers-reduced-motion` guard covering the whole app; do not add scoped copies of it.

No sparkles, gradients, novelty animation or decorative motion. Hover may change
colour, background, border-colour, shadow or `transform` — **never** padding, border-
width, font-size or dimensions, because those shift layout under the cursor.

## Layering

`--gp-z-raised` `1` · `-sticky` `20` · `-sidebar` `40` · `-dropdown` `60` ·
`-modal` `1055` · `-toast` `1090`

Aligned to Bootstrap 5 so the two systems stop colliding — Bootstrap owns 1000
(dropdown), 1045 (offcanvas), 1050 (backdrop), 1055 (modal), 1070/1080
(popover/tooltip), 1090 (toast). App chrome stays below all of it; only the two
values that must match Bootstrap reach into its range. Nothing needs `9999`.

## Viewport

**Breakpoints — these four, and no fifth:** `576px` phone · `768px` tablet / nav
switch · `992px` sidebar · `1200px` wide. (CSS cannot use custom properties in a media
query, so this is a convention, not a token.)

**Containers:** `--gp-container-narrow` `420px` (auth) · `--gp-container-form` `720px`
(long forms) · `--gp-container-detail` `960px` (detail views). Dashboards are
full-bleed inside `--main-pad`.

**The topbar subtitle is desktop-only.** All three shared topbars
(`topbar_parent` / `topbar_student` / `topbar_merchant`) hide their `<p>` below
`768px`: on nearly every page the sentence restates the title — "Activity Trail"
over "Your own purchases and every wallet transaction across your linked
students" — and it was spending one to two lines above the fold to do it. Set
`$topbarSubtitleMobile = true` before requiring the partial **only** where the
subtitle earns its lines. Two pages do today: `parent/controls.php`, because
nothing else on it names whose wallet is being edited, and `student/dashboard.php`,
where the bar is greeting the student rather than labelling itself. Before adding
a third, check the page body first — `merchant/settings.php` looked like a
candidate until its form turned out to repeat the warning, and `student/cart.php`
until `student_scan.css` turned out to hide the whole bar on `body.sp-page`.

## Reusable classes

**Cards** — `gp-card` white content card · `gp-card-head` (h3 + p, optional right-side
action) · `gp-hero` dark gradient hero with `gp-hero-label` / `gp-hero-value` /
`gp-hero-badge` (gold chip) · `gp-stat` white stat tile with
`gp-stat-icon is-success|is-warning|is-danger|is-info|is-gold`.

**Buttons** (pill) — `gp-btn` base + one modifier: `gp-btn--forest` (primary commit),
`gp-btn--gold` (key CTA / on dark), `gp-btn--outline` (cancel/neutral),
`gp-btn--ghost` (translucent, dark surfaces only), plus `gp-btn--block`.

**Badges** — `gp-badge` + `gp-badge--success|--warning|--danger|--info|--gold`;
`gp-count` for soft count chips ("12 Records").

**Tables** — `gp-table`: cream uppercase headers, hairline rows, hover tint. Always
wrap in `.table-responsive`. Use `gp-mono` on references/amounts.

**Tables on a phone** — a listing table wide enough to need `.table-responsive` is
a listing table nobody can read on a phone: the amount, the status and the View
button all sit past the right edge. `gjc-table-cards.css` + `gjc_table_cards.js`
turn each row into a **list row you tap to open** — what the row is on the left of
the first line, the figure on the right, the supporting detail muted underneath,
and the whole row opening its full details. Not a small table inside the page: no
label column, no cell grid.

```
Banana Cue                     ₱15.00  ›
Low · Available
```

Declare what each column does on its `<th>`:

| `data-card` | Where it lands |
|---|---|
| `title` | First line, left — the row's identity. Also what opts the table into this layout. |
| `amount` | First line, right — the figure. |
| `hide` | Detail-only: off the row, reachable on the detail view (or by expanding). |
| *(absent)* | The muted second line, dot-separated. |

Column order in the markup stops mattering — the row is a wrapping flex line and
each cell is ordered into place, so `title` reads first even when it is the second
column. **A table that declares no title keeps the labelled layout** (a `LABEL` /
value line per field). That is right when the values don't describe themselves —
the parent's school-year balances are three money columns, unreadable stripped of
their headings — and it is also how detail-only fields render once a row expands.
For the same reason, prefer `hide` over the muted run for a bare number: a stock
count reading `6` next to a price says nothing, while a `Low` pill says it itself.

A tap resolves in order: a link in the row (the View button's `href`), then a
"View" button (the merchant order queue opens a modal), then — for tables with no
detail page of their own, like top-ups and encashments — expanding the row in
place, so nothing is unreachable. Taps landing on a control inside the row belong
to that control, so the staff roster's Active switch and inventory's Edit/QR/Void
buttons still work. When View is a row's *only* control the tap replaces it and it
comes off the card; a row with other actions keeps all of them. A cell holding a
control never joins the muted run — it gets a labelled line, since a lone switch
in a run of dot-separated text means nothing.

It is progressive enhancement — with JS off the table keeps scrolling as before.
Tables of 7+ columns stack at the `992px` breakpoint, the rest at `768px`; opt one
out entirely with `data-cards="false"`. Live on the student, merchant and parent
listing pages; admin still scrolls.

**Misc** — `gp-empty` empty state (icon + caption).

## Merchant-specific patterns (POS / Scan & Pay generation)

`merchant/pos.php` generates the payment QR the student side scans — its panel
echoes the student scan screen's visual language, adapted for a **static** code
instead of a live camera feed (see `assets/css/pos.css`):

- **`.pos-qr-frame` + `.pos-qr-corner.tl/tr/bl/br`** — the gold corner-bracket
  reticle (student `.sp-frame`/`.sp-corner` pattern), sized down to a 220×220 QR
  instead of a full camera frame. No scanline (nothing is scanning); the corners
  are pure framing. Wraps `#posQrCanvas` (where `qrcodejs` renders) — the wrapper
  and corner `<span>`s are siblings/parent of that id, so JS that targets
  `#posQrCanvas` by id is untouched.
- **`.pos-qr-guide` + `.pos-qr-guide-step`** — a compact 3-step "Show this QR to
  the student" strip inside the QR box (numbered gold-circle steps, same visual
  as `.sp-step`/`.sp-steps` on the student guide panel, but condensed to fit POS
  density — no separate side panel, since the cart/product grid already fills
  the layout).
- **`.pos-qr-status.is-pending/.is-paid/.is-expired`** — maps 1:1 to
  `gp-warning`/`gp-success`/`gp-danger`, matching the same three states the
  student side shows for a payment token.

`merchant/qr_scanner.php` (visitor voucher scanner, a **different** feature from
Scan & Pay — validates `VISITOR_VOUCHER` hashes, not payment tokens) gets a
lighter touch: `.merchant-reader` — the html5-qrcode container — gets a
gold-tinted border (`--gp-gold-line`) instead of literal corner brackets, and
**keeps a light background** (`--gp-cream`), because html5-qrcode injects its
own camera-permission button / device dropdown with light-theme styling we
don't control; a dark container risked poor contrast on that third-party UI
before the video feed starts. `.merchant-voucher-card` (the result panel) uses
`--gp-grad-hero` like every other hero card.

Both merchant hero cards (`.encash-hero-card`, `.history-balance-card`,
`.merchant-metric-card`) and the dashboard's economy pool cards keep their
category data-viz colors (purple/blue/amber) — same rule as admin's economy
widget.

## Parent portal (fourth shell)

The parent portal (`parent/dashboard.php`, `controls.php`, `profile.php`,
`student.php`) was rebuilt from the ground up onto its own `.parent-*` class
namespace in `assets/css/parent_shell.css` — a full shell rebuild, not a color
swap, since it previously ran on the pre-redesign `.student-layout`/
`.student-sidebar` classes (defined in `assets/css/student.css`, since deleted —
nothing referenced it). `includes/partials/sidebar_parent.php`
and the new `includes/partials/topbar_parent.php` mirror the exact partial
pattern used by the other three sides — 250px dark sidebar, full-bleed dark
topbar, gold active-nav state, `gp-grad-hero` cards, mono numbers.

Two things worth knowing if you touch this again:
- **The shared `logout_modal.php`** has a hardcoded CSS selector list for its
  instant-active-tab-on-click feedback (`.sidebar-menu > a, .merchant-menu > a,
  .student-menu > a, .parent-menu > a`) — if a future role adds its own
  `*-menu` class, it must be added to that list too, or the click highlight
  silently does nothing on that role's sidebar.
- **The ledger's transaction-type colors** (`parent/student.php`'s
  `$typeLabels` array) are deliberately kept in sync with student
  `student_dashboard.css`'s `.sd-txn--*` colors (payment=amber,
  topup=green, transfer=blue, voucher=purple) — a parent should see the
  same type-per-color coding their child sees on their own dashboard.

## Bootstrap bridge — `body.gp-theme`

Add `class="gp-theme"` to `<body>` to theme Bootstrap controls without touching markup:
cream page bg, inputs/selects with the neutral focus ring, pill `.btn-primary/.btn-success`
(forest), `.btn-secondary/.btn-outline-secondary` (neutral), `.btn-danger`,
16px-radius modals, and `.badge.bg-*` → soft status tints. All admin and interactive
merchant pages have it (merchant's print_menu.php is excluded, like admin's print
pages); student pages use their own sd-* components and don't need it.

## Conventions

- **Status mapping:** paid/completed/approved/active/released → success ·
  pending/processing → warning · failed/rejected/frozen/blocked → danger ·
  informational → info. Never invent new status colors.
- **Gold is an accent, not a surface.** Large fills stay forest/white/cream; gold marks
  the active thing, the key number, the primary action. Gold text on white must use
  `--gp-gold-deep`.
- **Numbers are mono** (`--gp-mono` / `gp-mono` / tabular-nums) — amounts, refs, IDs.
- **Cache-busting:** every stylesheet link carries `?v=N`; bump it whenever the file
  changes. A file `@import`ed by others (theme.css, gjc-clear.css) needs *both* the
  `@import` URL bumped **and** every stylesheet that imports it bumped — otherwise the
  browser never re-requests the importer and never sees the new import URL.

  **The invariant, not a snapshot:** each stylesheet must resolve to exactly one
  version across every page that links it. A version list in this document goes stale
  within a week, so verify it instead — this prints any sheet linked at two versions,
  and should output nothing:

  ```bash
  grep -rhoE '[a-z_-]+\.css\?v=[0-9]+' --include=*.php . | sed -E 's|\.css\?v=| v|' \
    | sort -u | awk '{a[$1]=a[$1]" "$2} END{for(k in a) if(split(a[k],x," ")>1) print k":"a[k]}'
  ```

  Drift here is not cosmetic: it previously caused two roles to load different token
  values from the same file.
- **`gjc-clear.css` is now a pure alias layer**, not an independent palette: its
  `--gjc-green-*`/`--gjc-gold-*`/`--gjc-danger/-success/-warning/-info/-alert`/
  `--gjc-ink/-muted/-line/-page/-panel/-soft/-sidebar` tokens all resolve to the
  matching `--gp-*` token (it `@import`s theme.css itself, so this holds even on
  pages that link only `gjc-clear.css` directly). The `--gjc-*` names are kept
  because dozens of older rules across the app still reference them by name —
  only the *values* changed. Left as independent (no `--gp-*` equivalent exists):
  `--gjc-gold-100`, `--gjc-soft-2`, `--gjc-danger-border`, `--gjc-success-border`,
  `--gjc-slate` (the last is a deliberate distinct hue, not brand-related — see
  the economy-widget rule above). New code should prefer `--gp-*` directly;
  `--gjc-*` exists for backward compatibility, not as a second system to target.
- **Deliberately NOT themed:** `print_voucher.php`/`print_voucher.css` and
  `merchant/print_menu.php` (print layouts), `admin/doc.php` (standalone docs), the
  economy widget's category data-viz colors (vault/students/merchants/vouchers pools,
  on both admin and merchant), and email templates (stay Arial).
- **Shared partials** (`includes/partials/logout_modal.php`, `datatables_assets.php`)
  are used by all three sides — style them via theme.css tokens only, never
  per-role files.
- **Third-party UI you don't fully control** (html5-qrcode's injected camera
  controls, DataTables' generated markup): style the container around it, not
  its internals, and keep enough default contrast that the library's own light-
  or dark-themed elements stay legible either way.
- **Every `*-main` content wrapper needs `min-width: 0`.** The `*-layout` shells
  use `display: flex` with a `position: fixed` sidebar (out of flow), so `*-main`
  is the sole flex item — flex items default to `min-width: auto`, which refuses
  to shrink below the intrinsic width of their content. Without `min-width: 0`,
  a wide DataTable or grid inside pushes `*-main` past its `calc(100% - 250px)`
  width instead of scrolling internally in its own `.table-responsive`, and the
  whole page gets a horizontal scrollbar. `.sd-main` (the original pattern) had
  this from the start; `admin-main`/`merchant-main`/`parent-main` were missing
  it until it was added across the board.

## Adding a new page

1. Give it a `<title>` — `Page Name | GenPay`. One suffix, no role variants.
2. Link `theme.css` (directly, or via a role stylesheet that `@import`s it).
3. Add `gp-theme` to `<body>` if the page uses Bootstrap controls.
4. Build with `gp-*` classes; reach for tokens (`var(--gp-*)`) in any custom CSS.
5. Every size, space, radius, shadow, duration and z-index comes from a token.
   If the value you want isn't in a scale above, use the nearest step — don't add
   a new value. Adding a step is a change to the system, not to your page.
6. Any async action that moves money disables its trigger and swaps the label
   (`Sending…`) for the whole round-trip, restoring it on **both** the error and
   network-error paths. `student/transfer.php` is the reference implementation.
7. Bump `?v=` on anything you edit, and re-run the drift check above.
