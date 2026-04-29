# Merlin app — design guide

This document is the source of truth for visual decisions in the Merlin app
(Laravel + Filament). It exists so the app feels like the same product as the
marketing site at `merlinapp.io`, and so future contributors (human or AI)
make consistent choices without having to re-derive them.

> **Status — direction not yet locked.** The marketing site currently runs an
> A/B preview (`/` = Workshop, `/a` = Ledger). Once a winner is picked, fill in
> the **Tokens** section below with that direction's values, swap the Filament
> primary color, and delete this notice.

## North-star principles

These are non-negotiable and apply regardless of which direction wins:

1. **Warm + human, not enterprise-cold.** Merlin replaces a bookkeeper. It should feel like a thoughtful colleague, not a control panel.
2. **Show the data, don't decorate it.** The product is a ledger. Tabular numerics, real supplier names, real account codes — never lorem ipsum or stock illustrations.
3. **No emoji** as UI elements. Use icons (Heroicons, already bundled with Filament) or numbered markers.
4. **No gradient backgrounds.** Flat surfaces, optionally one very subtle linear fade in the marketing hero. App surfaces are solid.
5. **Tone in copy.** Direct, second person, present tense. "Post to ledger" not "Submit posting request". "Needs review · 4" not "There are 4 items requiring your attention".
6. **Confidence is a first-class UI concept.** Every AI suggestion shows a confidence pill. Users learn to read these like a fuel gauge.
7. **Audit trail visible by default.** "Posted by Merlin · 92% confidence · matched supplier history (11 prior)" should appear next to the entry, not buried in a drawer.

## Tokens

> Replace the placeholder values below with the winning direction's tokens.
> Both directions' values are listed for reference — uncomment one block.

### Colors

```css
/* DIRECTION B — Workshop (white + warm amber). Currently default. */
--bg:           #FFFFFF;   /* page background */
--bg-alt:       #FAF8F4;   /* subtle warm off-white for cards/sections */
--surface:      #FFFFFF;   /* cards, modals, table rows */
--ink:          #1A1A1A;   /* primary text */
--ink-soft:     #4D4D4D;   /* secondary text */
--muted:        #8A8580;   /* tertiary / placeholder */
--border:       #EAE6DF;   /* dividers, card borders */
--accent:       #C8772E;   /* primary action, links, focus ring */
--accent-on:    #FFFFFF;   /* text on accent bg */
--accent-ink:   #9A4F12;   /* accent-tinted text on light */
--accent-soft:  #FCEFDF;   /* accent-tinted bg (badges, hover) */
--accent-border:#F2D9B7;   /* accent-tinted border */

/* DIRECTION A — Ledger (cream + ink blue). Uncomment if A wins.
--bg:           #F5F0E6;
--bg-alt:       #EDE6D6;
--surface:      #FFFFFF;
--ink:          #0F1B3D;
--ink-soft:     #3B4566;
--muted:        #7A7263;
--border:       #E5DCC7;
--accent:       #0F1B3D;
--accent-on:    #F5F0E6;
--accent-ink:   #0F1B3D;
--accent-soft:  #EFE6D2;
--accent-border:#D9CDB3;
*/

/* Semantic — same in both directions */
--success:      #3B7A4E;
--warning:      #B45309;
--danger:       #B91C1C;
--info:         #1E40AF;
```

### Typography

Both directions share Inter for UI; Direction A adds Fraunces for display headings only.

```css
/* Always Inter for UI surfaces (tables, forms, nav, body copy) */
--font-ui: 'Inter', system-ui, -apple-system, sans-serif;

/* Direction B: Inter for everything */
--font-display: 'Inter', system-ui, sans-serif;

/* Direction A: Fraunces for h1/h2 only, weights 300–500 */
/* --font-display: 'Fraunces', 'Source Serif Pro', Georgia, serif; */
```

**Type scale** (px, line-height, letter-spacing):

| Role | Size | LH | LS | Weight |
|---|---|---|---|---|
| Display L | 56–68 | 1.02 | -0.035em | 400 (A) / 700 (B) |
| Display M | 36–42 | 1.1 | -0.02em | 400 (A) / 700 (B) |
| Title | 22 | 1.25 | -0.01em | 500 (A) / 600 (B) |
| Heading | 17 | 1.3 | -0.01em | 600 |
| Body | 15 | 1.6 | 0 | 400 |
| Body small | 14 | 1.55 | 0 | 400 |
| Label | 12.5 | 1.4 | 0.12em UPPER | 600 |
| Number (tabular) | inherit | inherit | 0 | 500, `font-variant-numeric: tabular-nums` |

**Always use tabular figures** for: amounts, dates, invoice numbers, account codes, confidence percentages, anything in a table column.

### Spacing & radius

- Spacing scale follows Tailwind's default (4 / 8 / 12 / 16 / 24 / 32 / 48 / 64 / 96 px).
- Radii: `--radius-sm: 6px` (buttons, pills), `--radius-md: 8px` (inputs, small cards), `--radius-lg: 12px` (cards), `--radius-xl: 14px` (modals, the review-queue card).
- Border default: `1px solid var(--border)`. No 2px borders anywhere.

### Shadows

```css
--shadow-card:  0 1px 0 rgba(0,0,0,0.02), 0 18px 40px -16px rgba(26,26,26,0.14);
--shadow-modal: 0 20px 60px -10px rgba(0,0,0,0.25);
```

Use shadows sparingly. Tables don't get shadows. Cards in a grid don't get shadows. Floating things (modals, the elevated review panel, dropdowns) get them.

## Component patterns

### Confidence pill

The most distinctive Merlin UI element. Use everywhere an AI decision is shown.

```html
<span class="confidence-pill confidence-pill--high">92% confidence</span>
```

```css
.confidence-pill {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; font-weight: 600;
  padding: 4px 10px; border-radius: 999px;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.confidence-pill--high {
  color: var(--accent-ink);
  background: var(--accent-soft);
  border: 1px solid var(--accent-border);
}
.confidence-pill--med {
  color: var(--warning);
  background: #FEF3C7;
  border: 1px solid #FDE68A;
}
.confidence-pill--low {
  color: var(--danger);
  background: #FEE2E2;
  border: 1px solid #FECACA;
}
```

Thresholds: ≥85 high, 60–84 med, <60 low. These match the autonomous-posting rule defaults.

### Buttons

- **Primary** — `bg: ink, color: accent-on`. The dark button. For "Post to ledger", "Send", "Save".
- **Accent** — `bg: accent, color: accent-on`. Sparingly. Reserve for the highest-stakes action on a screen.
- **Secondary** — `bg: surface, color: ink, border: 1px solid border`. "Edit coding", "Cancel".
- **Ghost** — text only, `color: ink-soft, hover: ink`. For tertiary actions in tight rows.

Heights: 32 (sm, in tables), 40 (md, default), 48 (lg, primary CTAs).

### Tables

- Header: `bg-alt`, label-style label (uppercase, 10.5px, 0.06em letter-spacing).
- Rows: 1px border-top in `--border`. No zebra striping.
- Hover: row gets `bg-alt`.
- Numeric columns right-aligned, tabular figures.
- Active/selected row: 2px left-border in `--accent` + `bg: accent-soft`.

### Forms (Filament default behaviours to keep)

Filament's defaults already align with this guide. Just override:
- Focus ring color → `--accent`
- Input border-radius → `--radius-md`
- Input border default → `--border`
- Input bg → `--bg` (not `--surface` — feels recessed)

### Empty states

Always two lines:
- Bold short statement of what's not here ("No invoices in review.")
- One thin sentence in `--muted` explaining what would put something here ("Drop a PDF into the watched folder, or upload one above.")

No empty-state illustrations. They get tired fast.

### Confidence-driven row treatments in the review queue

| State | Visual |
|---|---|
| Auto-posted (≥ threshold) | Listed in "Posted today", green dot, no review needed |
| Needs review (< threshold) | In the queue, accent left-border on hover/select |
| Coding edited by user | Small "edited" indicator, the AI suggestion preserved underneath |
| Coding never seen before | "First time" microcopy under the suggested account |

## Filament integration

### Setting up the theme

```bash
php artisan make:filament-theme admin
```

This scaffolds:
- `resources/css/filament/admin/theme.css`
- `tailwind.config.preset.js` (or similar)

Edit the generated `theme.css`:

```css
@import '/vendor/filament/filament/resources/css/theme.css';

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
/* If Direction A wins, also: */
/* @import url('https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..700&display=swap'); */

@layer base {
  :root {
    /* Paste the token block from above */
  }
  body {
    font-family: var(--font-ui);
    background: var(--bg);
    color: var(--ink);
  }
  h1, h2 { font-family: var(--font-display); letter-spacing: -0.025em; }
}
```

### Setting Filament's primary color

In `app/Providers/Filament/AdminPanelProvider.php`:

```php
use Filament\Support\Colors\Color;

->colors([
    // For Direction B — amber:
    'primary' => Color::hex('#C8772E'),
    // For Direction A — ink blue:
    // 'primary' => Color::hex('#0F1B3D'),
])
```

Filament generates the 50–950 shade scale automatically via OKLCH.

### Custom Blade components for confidence pills, etc.

Make these as actual Blade components (`resources/views/components/confidence-pill.blade.php`) so they're reusable across Filament resources, infolists, custom pages.

```blade
@props(['value'])
@php
  $bucket = $value >= 85 ? 'high' : ($value >= 60 ? 'med' : 'low');
@endphp
<span class="confidence-pill confidence-pill--{{ $bucket }}">
  {{ $value }}% confidence
</span>
```

Use it in a Filament `TextColumn` via `formatStateUsing(fn ($state) => view('components.confidence-pill', ['value' => $state]))`.

## What NOT to do

- **Don't use Filament's purple primary by default.** It's the marketing site's old palette and we've moved on.
- **Don't add hero-style gradient banners** to admin pages. Marketing-site flair doesn't belong here.
- **Don't show currency without tabular figures.** Misaligned columns of money are a credibility problem in a bookkeeping product.
- **Don't use the wizard metaphor in microcopy.** No "Let Merlin's magic…", no "✨", no "spell". The product name is a vibe, not a theme.
- **Don't add icons next to every label.** Icons should mark *types* (supplier, invoice, account) not decorate every row.
- **Don't introduce new accent colors.** If you need to differentiate states, use the semantic colors (success/warning/danger/info) — not new hues.

## When in doubt

Mirror the marketing site at `merlinapp.io`. If a pattern exists there, the app
should use the same vocabulary. The whole point is that a customer who saw the
landing page and then logs in feels like they walked through the same door.
