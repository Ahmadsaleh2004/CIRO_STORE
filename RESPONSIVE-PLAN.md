# Cairo Store — Responsive Refactor: Verified Execution Plan

> This plan supersedes the original 24-task draft. Every claim below was
> verified against the actual codebase on 2026-08-24. Sections marked
> **⚠ CORRECTION** are places where following the original draft literally
> would have broken something.

---

## A. Audit verification result

The original audit was **accurate**. Confirmed by direct measurement:

| Claim | Verified |
|---|---|
| `checkout.css` has 0 media queries | ✅ 0 mq / 58 lines |
| `my-info.css` has 0 media queries | ✅ 0 mq / 33 lines |
| `admin-login.css` has 0 media queries | ✅ 0 mq / 200 lines |
| `branding.css` has 0 media queries | ✅ 0 mq / 8 lines |
| `products.css` has no size breakpoint | ✅ 1 mq, `prefers-reduced-motion` only |
| `product-details.css` has no size breakpoint | ✅ 1 mq, `hover: hover` only |
| `dashboard.php` table unwrapped | ✅ 1 table, 0 wrapped |
| `orders/details.php` 4 tables, 1 wrapped | ✅ exact |
| `users/details.php` 4 tables, 3 wrapped | ✅ exact |
| `manage-admins/details.php` 4 tables, 3 wrapped | ✅ exact |
| `orders/index.php` fixed `width:200px` / `160px` | ✅ exact |
| `_templates.php` `width:260px` slide card | ✅ exact |
| `broadcast-form.php` 7× `min-width:120px` | ✅ exact |

**Breakpoint chaos is real.** Live inventory across all CSS:
`320, 375, 400, 414, 575, 576, 767, 768, 991, 992, 1024, 1400, 1440` — 13
distinct thresholds where Bootstrap defines 5.

---

## B. ⚠ Three corrections to the original draft

### ⚠ CORRECTION 1 — Do NOT reorder the `@media` blocks in `style.css`

Original Task 01, instruction 2, says to "reorder all `@media` blocks so
they run largest→smallest." **This would break the site.**

`style.css` redefines `:root` custom properties *inside* five media queries
(lines 1296, 1312, 1327, 1396, plus the base at line 4 and a trailing block
at 2364). These set `--card-height` and `--img-height` per breakpoint:

```css
/* line ~1296 */  :root { --card-height: 360px; --img-height: 210px; }
/* line ~1312 */  :root { --card-height: 300px; --img-height: 160px; }
```

Because these are plain CSS custom properties with equal specificity,
**the last matching block wins**. Their correctness depends entirely on
source order. Reordering them silently changes every product card's height
at every breakpoint, across the whole site, with no error to catch it.

**Revised instruction:** leave the physical order of `@media` blocks
untouched. Instead, add a table-of-contents comment at the top of
`style.css` documenting which breakpoint lives at which line. Readability
is achieved through documentation, not through moving cascade-sensitive
code.

### ⚠ CORRECTION 2 — Do NOT swap the reset-card shadow for `--shadow-color`

Original Task 10, instruction 2, suggests replacing
`box-shadow: 0 10px 30px rgba(0,0,0,.12)` with `var(--shadow-color)`.

`--shadow-color` is defined as `rgba(15, 15, 15, 0.04)` — a very subtle
*card-resting* shadow, roughly a third the opacity of the reset card's
deliberate elevated-modal shadow. Swapping it would visually flatten the
reset card to near-invisible elevation.

**Revised instruction:** keep the hardcoded value, or introduce a *new*
variable (`--shadow-elevated`) if the same dramatic shadow is needed in
more than one place. Do not reuse `--shadow-color` for it.

### ⚠ CORRECTION 3 — Task 19's two-pane branch is dead code

Original Task 19 spends most of its length on a conditional: "if
`support.php` is a two-pane layout, implement single-pane mobile switching
with back-navigation."

It is **not** two-pane. `support.php` renders a single responsive card grid
already: `<div class="col-12 col-md-6 col-lg-4" id="msg-card-...">`.

**Revised instruction:** skip the two-pane work entirely. Task 19 reduces to
a ~15-minute pass: the `max-width:320px` search input (line 24) plus
verifying the two existing `d-flex ... flex-wrap` toolbars. This drops Task
19 from a large task to a trivial one.

---

## C. Two further findings the original audit missed

**`users/index.php` is already correct.** Task 16 instruction 1 asks to
check for hardcoded pixel widths on its toolbar. It uses
`style="max-width:280px;"` and `style="max-width:180px;"` — the `max-width`
pattern the Master Plan's own rule 5 endorses. No change needed; only
`orders/index.php` has genuinely broken fixed `width:`.

**The admin navbar is a top bar, not a sidebar.** Task 11 branches on
"if it's a sidebar / if it's a top bar." Resolved:
`app/views/admin/inc/navbar.php:11` is
`<nav class="navbar custom-navbar navbar-expand-xl">`. Take the top-bar
branch. Note it expands at `xl` (1200px) while the public navbar
(`inc/navbar.php:33`) expands at `lg` (992px) — that gap between 992–1199px
is worth a deliberate decision, not an accident.

---

## D. Revised execution order

The original 24 tasks are sound in content. The ordering is re-grouped into
five phases so that high-risk, high-value work lands first and shared
patterns are settled before they get copied to three more pages.

### Phase 0 — Foundation (must be first)

| # | Task | Files |
|---|---|---|
| 0.1 | Design system foundation | `public/css/style.css` |

Adds `--space-1..6`, `.responsive-toolbar`, `.fluid-input`,
`.phone-code-select`, `.perm-grid`, the touch-target safety net, and the
`#notifSidebar` tablet width. **Applies Correction 1** — documents the
media-query map instead of reordering it.

> Define **all** shared classes here, including `.phone-code-select` and
> `.perm-grid`. The original draft introduced these mid-plan (Tasks 03 and
> 17) and then re-derived them in Tasks 08, 22 and 23 — which is exactly how
> the three variants of the same fix drift apart. One definition, five
> consumers.

### Phase 1 — Zero-coverage pages (highest value)

These have literally no responsive CSS today. Biggest visible win per hour.

| # | Task | Files |
|---|---|---|
| 1.1 | Checkout (revenue-critical) | `checkout/checkout.php`, `confirmation.php`, `pages/checkout.css` |
| 1.2 | Products catalog | `product/product.php`, `pages/products.css` |
| 1.3 | Product details | `product/product_dit.php`, `pages/product-details.css` |
| 1.4 | My Info / account | `account/my-info.php`, `pages/my-info.css`, `shared/order-cancel-button.php` |
| 1.5 | Admin login & reauth | `admin/login.php`, `store-reauth.php`, `admin/admin-login.css` |

### Phase 2 — Shared layout & dialogs

Touched after Phase 1 because these files are low-risk but affect every
page; doing them second means Phase 1's bugs are already flushed out.

| # | Task | Files |
|---|---|---|
| 2.1 | Public layout | `inc/head.php`, `inc/navbar.php`, `inc/footer.php` |
| 2.2 | Public modals | `inc/modals/*.php` (6 files) |
| 2.3 | Admin shared layout | `admin/inc/*.php`, `admin/admin-layout/*.css` |

### Phase 3 — Mechanical fixes (fast, low-risk, batchable)

These are small and independent. They can be done in one sitting.

| # | Fix | Where |
|---|---|---|
| 3.1 | Wrap 5 missing tables in `.table-responsive` | `dashboard.php` (1), `orders/details.php` (3), `users/details.php` (1), `manage-admins/details.php` (1) |
| 3.2 | Fixed `width:` → `.fluid-input` | `orders/index.php:27`, `orders/index.php:35` |
| 3.3 | `min-width:120px` → `.perm-grid` | `broadcast-form.php` (7×), `_perm-modal.php` |
| 3.4 | Inline `max-width:110px` → `.phone-code-select` | `register.php:70`, `account/my-info.php:81`, `admin/my-info.php:77` |

> 3.1 alone fixes the worst remaining layout breakage in the admin panel and
> takes minutes. Consider pulling it forward if admin usability on phones is
> urgent.

### Phase 4 — Remaining pages

| # | Task | Files |
|---|---|---|
| 4.1 | Home page | `home.php`, `pages/home.css`, `pages/home-slider.css` |
| 4.2 | Static pages | `page/about.php`, `contact.php`, `wishlist.php`, `pages/wishlist.css` |
| 4.3 | Reset password | `auth/reset-password.php` — **applies Correction 2** |
| 4.4 | Admin dashboard | `admin/dashboard.php`, `home.php`, `admin/admin.css` |
| 4.5 | Admin products | `admin/product/*.php` (4 files) — **highest JS risk** |
| 4.6 | Admin orders | `admin/orders/*.php` |
| 4.7 | Admin users | `admin/users/*.php` |
| 4.8 | Admin manage-admins | `admin/manage-admins/*.php` (4 files) |
| 4.9 | Admin branding | `admin/branding/*.php`, `admin/branding.css` |
| 4.10 | Admin support | `admin/support.php` — **applies Correction 3, now trivial** |
| 4.11 | Admin settings | `admin/settings.php` |
| 4.12 | Admin backup | `admin/backup.php` |
| 4.13 | Admin my-info | `admin/my-info.php` |
| 4.14 | Broadcast & notify modals | `admin/broadcast-form.php`, `notify-modal.php` |

### Phase 5 — Regression audit

Full cross-page pass at 320 / 375 / 414 / 576 / 768 / 992 / 1200 / 1440 /
1920, both themes, plus a JS-hook regression check.

---

## E. Standing rules (unchanged from the original draft)

These remain correct and apply to every task:

1. Never rename an `id`, `name`, `data-*`, or JS-referenced class.
2. Never change PHP logic, form field names, or CSRF handling.
3. Bootstrap 5.3.3 grid is the primary layout tool.
4. Use existing CSS variables — no hardcoded colors (theming depends on it).
5. Fixed px is fine for small UI atoms (icons, checkboxes, avatars,
   thumbnails); never for containers, inputs, cards, or columns.
6. Every `<table>` wrapped in `.table-responsive`.
7. Modals never exceed viewport; `modal-dialog-scrollable` for long bodies.
8. Touch targets ≥40×40px at ≤767px.
9. No page-level horizontal scroll at any breakpoint.
10. Add rules to the existing per-page CSS file; don't create new ones.

**Unified breakpoints:** `576 / 768 / 992 / 1200 / 1400` (Bootstrap 5
defaults). Existing historical values (`400`, `414`, `1024`, `1440`) stay
where they are — only *new* rules must use the unified set.

---

## F. Highest-risk item

**Phase 4.5 (admin products)** is the single most dangerous task.
`public/js/admin/products.js` clones a template row for each color variant
and depends on exact `name="variants[i][...]"` attributes and specific
button classes. Read `products.js` in full *before* touching
`add.php`/`edit.php`, and re-test add-variant / remove-variant immediately
after.

Runner-up: **Phase 4.9 (branding)** — `_templates.php` contains a
`<template>` element cloned by `branding.js`. Prefer the low-risk
horizontal-scroll approach for the `width:260px` slide cards over any
structural rewrite.
