# Design System Master File

> **LOGIC:** When building a specific page, first check `design-system/pages/[page-name].md`.
> If that file exists, its rules **override** this Master file.
> If not, strictly follow the rules below.

---

**Project:** SlotLock
**Generated:** 2026-11-26 20:14:17
**Category:** Developer Tool / IDE

---

## Global Rules

### Color Palette

| Role | Hex | CSS Variable |
|------|-----|--------------|
| Primary | `#1E293B` | `--color-primary` |
| On Primary | `#FFFFFF` | `--color-on-primary` |
| Secondary | `#334155` | `--color-secondary` |
| On Secondary | `#FFFFFF` | `--color-on-secondary` |
| Accent/CTA | `#22C55E` | `--color-accent` |
| On Accent/CTA | `#0F172A` | `--color-on-accent` |
| Background | `#0F172A` | `--color-background` |
| Foreground | `#F8FAFC` | `--color-foreground` |
| Card | `#1B2336` | `--color-card` |
| Card Foreground | `#F8FAFC` | `--color-card-foreground` |
| Muted | `#272F42` | `--color-muted` |
| Muted Foreground | `#94A3B8` | `--color-muted-foreground` |
| Border | `#475569` | `--color-border` |
| Destructive | `#EF4444` | `--color-destructive` |
| On Destructive | `#000000` | `--color-on-destructive` |
| Ring | `#FFFFFF` | `--color-ring` |

**Color Notes:** Code dark + run green

### Typography

- **Heading Font:** JetBrains Mono
- **Body Font:** IBM Plex Sans
- **Mood:** code, developer, technical, precise, functional, hacker
- **Google Fonts:** [JetBrains Mono + IBM Plex Sans](https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap)

**CSS Import:**
```css
/* No external font request. Use local stacks with system fallbacks:
   --font-heading: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace;
   --font-sans: "IBM Plex Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; */
```

### Spacing Variables

| Token | Value | Usage |
|-------|-------|-------|
| `--space-xs` | `4px` / `0.25rem` | Tight gaps |
| `--space-sm` | `8px` / `0.5rem` | Icon gaps, inline spacing |
| `--space-md` | `16px` / `1rem` | Standard padding |
| `--space-lg` | `24px` / `1.5rem` | Section padding |
| `--space-xl` | `32px` / `2rem` | Large gaps |
| `--space-2xl` | `48px` / `3rem` | Section margins |
| `--space-3xl` | `64px` / `4rem` | Hero padding |

### Shadow Depths

| Level | Value | Usage |
|-------|-------|-------|
| `--shadow-sm` | `0 1px 2px rgba(0,0,0,0.05)` | Subtle lift |
| `--shadow-md` | `0 4px 6px rgba(0,0,0,0.1)` | Cards, buttons |
| `--shadow-lg` | `0 10px 15px rgba(0,0,0,0.1)` | Modals, dropdowns |
| `--shadow-xl` | `0 20px 25px rgba(0,0,0,0.15)` | Hero images, featured cards |

> **Dark theme:** use rgba(2,6,23,...) shadows (`0 1px 2px .4`, `0 8px 24px .35`, `0 20px 50px .45`). The light shadow values above are too weak on #0F172A.

---

## Component Specs

### Buttons

```css
/* Primary Button */
.btn-primary {
  background: #22C55E;
  color: #0F172A; /* --color-on-accent; white on green is only 2.2:1 */
  padding: 12px 24px;
  border-radius: 10px;
  font-weight: 600;
  transition: background-color 200ms ease, box-shadow 200ms ease;
  cursor: pointer;
}

.btn-primary:hover {
  background: #16A34A;
  box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.42), 0 8px 24px rgba(34, 197, 94, 0.18);
}

.btn-primary:focus-visible {
  outline: 2px solid #FFFFFF;
  outline-offset: 2px;
}

/* Secondary Button */
.btn-secondary {
  background: transparent;
  color: #F8FAFC;
  border: 1px solid #7D8EA8;
  padding: 12px 24px;
  border-radius: 10px;
  font-weight: 600;
  transition: border-color 200ms ease, color 200ms ease;
  cursor: pointer;
}

.btn-secondary:hover {
  border-color: #F8FAFC;
  color: #FFFFFF;
}
```

### Cards

```css
.card {
  background: #1B2336; /* --color-card, over #0F172A */
  border: 1px solid #334155;
  border-radius: 14px;
  padding: 24px;
  box-shadow: 0 1px 2px rgba(2, 6, 23, 0.4);
}

/* Non-interactive cards have no hover transform. If interactive,
   change border-color to #5EEAD4 and lift the shadow only. */
```

### Inputs

```css
.input {
  padding: 8px 12px;
  background: #272F42;
  border: 1px solid #7D8EA8; /* >= 3:1 against the input surface */
  border-radius: 10px;
  color: #F8FAFC;
  font-size: 14px;
  min-height: 42px;
  transition: border-color 200ms ease;
}

.input:focus-visible {
  border-color: #5EEAD4;
  outline: 2px solid #FFFFFF;
  outline-offset: 2px;
}
```

### Modals

```css
.modal-overlay {
  background: rgba(2, 6, 23, 0.7);
}

.modal {
  background: #1B2336;
  color: #F8FAFC;
  border: 1px solid #334155;
  border-radius: 16px;
  padding: 32px;
  box-shadow: 0 20px 50px rgba(2, 6, 23, 0.45);
  max-width: 500px;
  width: 90%;
}
```

---

## Style Guidelines

**Style:** Dark Mode (OLED)

**Keywords:** Dark theme, low light, high contrast, deep black, midnight blue, eye-friendly, OLED, night mode, power efficient

**Best For:** Night-mode apps, coding platforms, entertainment, eye-strain prevention, OLED devices, low-light

**Key Effects:** Minimal glow (text-shadow: 0 0 10px), dark-to-light transitions, low white emission, high readability, visible focus

### Page Pattern

**Pattern Name:** FAQ/Documentation Landing

- **Conversion Strategy:** Reduce support tickets. Track search analytics. Show related articles. Contact escalation path.
- **CTA Placement:** Search bar prominent + Contact CTA for unresolved questions
- **Section Order:** Hero with search bar > Popular categories > FAQ accordion > Contact/support CTA

> **SlotLock note:** the FAQ pattern is **not applied**. The existing IA is preserved: hero → SQL invariant → evidence → API.

---

## Anti-Patterns (Do NOT Use)

- ❌ Light mode default
- ❌ Slow performance

### Additional Forbidden Patterns

- ❌ **Emojis as icons** — Use SVG icons (Heroicons, Lucide, Simple Icons)
- ❌ **Missing cursor:pointer** — All clickable elements must have cursor:pointer
- ❌ **Layout-shifting hovers** — Avoid scale transforms that shift layout
- ❌ **Low contrast text** — Maintain 4.5:1 minimum contrast ratio
- ❌ **Instant state changes** — Always use transitions (150-300ms)
- ❌ **Invisible focus states** — Focus states must be visible for a11y

---

## Pre-Delivery Checklist

Before delivering any UI code, verify:

- [ ] No emojis used as icons (use SVG instead)
- [ ] All icons from consistent icon set (Heroicons/Lucide)
- [ ] `cursor-pointer` on all clickable elements
- [ ] Hover states with smooth transitions (150-300ms)
- [ ] Text contrast 4.5:1 minimum (dark theme; light mode not recommended)
- [ ] Focus states visible for keyboard navigation
- [ ] `prefers-reduced-motion` respected
- [ ] Responsive: 375px, 768px, 1024px, 1440px
- [ ] No content hidden behind fixed navbars
- [ ] No horizontal scroll on mobile


---

## SlotLock — application notes

- **Pattern:** FAQ not applied. Existing IA preserved (hero → SQL invariant → evidence → API).
- **Mode:** dark-only, per the style (`Light not-recommended`). No theme toggle.
- **Fonts:** JetBrains Mono (headings/mono) + IBM Plex Sans (body) as local stacks with system fallbacks. No Google Fonts request or new dependency.
- **Accent vs brand:** `--accent` #22C55E is the action color; `--brand` #5EEAD4 preserves the existing teal identity (logo, h1 emphasis, pills). `--accent-2` now aliases the brand; the old lime is no longer used.
- **Contrast (measured):** text/panel 14.98:1, muted/panel 6.11:1, accent/panel 6.88:1, on-accent/accent 7.83:1, danger-text/panel 8.26:1, code text 15.19:1. Input border #7D8EA8 is 4.01:1 against #272F42 (>=3:1 non-text).
- **States:** hover/active use color/elevation only (no transform); selected slots use a 2px white ring + brand glow; `focus-visible` uses a 2px white outline with 2px offset on every control.
- **Color is not the only indicator:** held/confirmed slots also get diagonal stripe patterns; aria-labels include the status; the legend labels colors.
- **Tokens:** `public/css/tokens.css` (single source), `public/css/base.css` (reset/typography/focus), `public/css/demo.css` (demo components), landing component CSS in `resources/views/welcome.blade.php`.
- **Responsive:** verified at 375 / 768 / 1024 / 1440 px; no horizontal overflow; reduced-motion respected.
