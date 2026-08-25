# Cairo Store — Four-Bug Fix: Verified Plan

> Every claim below was checked against the real code and the live `ciro_db`
> database on 2026-08-24. Sections marked **⚠** are places where the original
> task document's assumptions do not survive contact with the code.

---

## Executive summary

| # | Bug | Status after investigation | Effort |
|---|-----|---------------------------|--------|
| 4 | Admin captcha always fails | ✅ **FIXED & VERIFIED** — see "Bug 4 outcome" below | done |
| 1 | Wishlist "Notify Me" not green | ✅ **FIXED** — see "Bug 1 outcome" below | done |
| 3 | Forgot Password not working | ✅ **FIXED & VERIFIED** — was CSRF, not email | done |
| 5 | Manage Orders toolbar inconsistent | ✅ **FIXED & VERIFIED** — measured identical to Users | done |
| 2 | Manage Admins Edit modal | ✅ **FIXED** — unclosed `<div>` in `broadcast-form.php` was swallowing `#permModal`. Proven with a negative control | done |

**Recommended order: 4 → 1 → 3 → 2** (not the document's 1→2→3→4). Reasons in
§ "Why this order" at the end.

---

## ⚠ Bug 4 — Root cause found: placeholder keys defeat the dev-mode bypass

This one is fully solved on paper. The task document proposes a five-step
diagnostic ladder (internet connectivity, `allow_url_fopen`, key pairing,
domain allow-list, raw-response logging). **None of that is necessary.**

`.env` currently contains literal, never-replaced placeholders:

```
HCAPTCHA_SITE_KEY=YOUR_HCAPTCHA_SITE_KEY_HERE
HCAPTCHA_SECRET_KEY=YOUR_HCAPTCHA_SECRET_KEY_HERE
```

And `AdminAuthController::verifyCaptcha()` (line ~624) opens with:

```php
$secretKey = $_ENV['HCAPTCHA_SECRET_KEY'] ?? '';
if (empty($secretKey)) {
    // إذا لم يُضبط الـ secret key، نتجاوز التحقق في بيئة التطوير
    return true;   // ← dev-mode bypass
}
```

The code **already has a dev-mode escape hatch** — but it only fires when the
key is *empty*. `"YOUR_HCAPTCHA_SECRET_KEY_HERE"` is a non-empty string, so
`empty()` returns false, the bypass is skipped, and the placeholder gets sent
to `hcaptcha.com/siteverify`, which rejects it. Result: **permanent failure by
design-accident.**

The front end is broken by the same cause: `admin/login.php` line ~100 emits
`data-sitekey="YOUR_HCAPTCHA_SITE_KEY_HERE"`, so the hCaptcha widget cannot
render and never produces an `h-captcha-response` field at all. Both halves
fail for one reason.

### ✅ DECIDED — Option B (real keys)

The project owner registered a real hCaptcha site on 2026-08-24 and chose real
protection over the dev bypass.

| Value | Status |
|-------|--------|
| `HCAPTCHA_SITE_KEY` | **Obtained** — `08fa8392-2e6c-48d2-bbf4-83c874b2406c` (public; ships in page HTML by design) |
| `HCAPTCHA_SECRET_KEY` | **Pending** — owner pastes it into `.env` directly; it is never to be sent through chat |

**Domain allowlisting is intentionally left OFF** on the hCaptcha site. In the
current dashboard UI that toggle defaults to disabled, which means the sitekey
is accepted from any origin — including `localhost`. This removes the
domain-rejection failure mode entirely for local work.

> ⚠️ **Before deploying:** enable Domain allowlisting and add the production
> domain. Leaving it off in production lets anyone reuse this sitekey on their
> own site.

Once the secret is in `.env`, no code change is strictly required for the bug
to clear — but apply the hardening below anyway.

### Options considered and rejected (kept for reference)

**Option A — blank the values** so the existing dev bypass activates:

```
HCAPTCHA_SITE_KEY=
HCAPTCHA_SECRET_KEY=
```

Rejected: gives no bot protection and skips the captcha code path entirely, so
a latent fault in that path would stay hidden.

**Option A2 — hCaptcha's official test keys.** *(Superseded — real keys were
obtained instead. Kept in case a throwaway environment ever needs it.)*
hCaptcha publishes a documented key pair that always passes verification. This
is better than Option A because the widget actually renders and the whole
captcha code path runs end-to-end — you test the real flow instead of skipping
it, while never being blocked by a challenge:

```
HCAPTCHA_SITE_KEY=10000000-ffff-ffff-ffff-000000000001
HCAPTCHA_SECRET_KEY=0x0000000000000000000000000000000000000000
```

These are public, published by hCaptcha for exactly this purpose — they are not
secrets and provide **no protection whatsoever**. They must be replaced with a
real pair before deployment. (Verify them against hCaptcha's current docs when
you use them; published test credentials can change over time.)

**Option B — real protection.** Get a real key pair from hCaptcha. Steps, since
this was asked:

1. Go to **hcaptcha.com** → *Sign up* (the free tier is enough).
2. In the dashboard open **Sites** → *New Site* (or *Add Site*).
3. Under **Hostnames**, add both `localhost` and `127.0.0.1`. This matters —
   hCaptcha rejects verification from a hostname that is not on the list, which
   is one of the failure modes the task document worried about.
4. Save. The site's **Site Key** is shown on that page → this is the *public*
   key → `HCAPTCHA_SITE_KEY`.
5. Open **Settings** (account settings, not site settings) → **Secret Key**.
   Copy it → `HCAPTCHA_SECRET_KEY`.
6. Paste both into `.env`, replacing the placeholders, and restart Apache.

The Site Key is public (it ships in the page HTML). The Secret Key must stay
server-side only — do not paste it anywhere public, and note that `.env` should
never be committed to git.

I cannot perform these steps: they require creating an account under your
identity. Send me the two values when you have them and I will wire them in —
or start with Option A now and switch later.

### Hardening (do this regardless of A or B)

Make `verifyCaptcha()` treat a placeholder as unset, so this exact trap cannot
recur when the file is copied to a new machine:

```php
$secretKey = trim($_ENV['HCAPTCHA_SECRET_KEY'] ?? '');
if ($secretKey === '' || str_starts_with($secretKey, 'YOUR_')) {
    error_log('hCaptcha secret not configured — bypassing (dev mode)');
    return true;
}
```

Apply the same guard on the view side so the widget is not rendered with a
placeholder site key.

**Security note:** Option A means admin login has *no* bot protection. That is
acceptable on a local XAMPP box and is what the code already intends, but it
must be switched to Option B before this ever faces the internet. I will flag
this again if the project is deployed.

---

## ✅ Bug 4 outcome (completed 2026-08-24)

**Real cause (two compounding faults):**

1. The `.env` placeholder `YOUR_HCAPTCHA_SECRET_KEY_HERE` was non-empty, so
   `empty()` in `verifyCaptcha()` returned false and the intended dev-mode
   bypass never fired. The placeholder was sent to hCaptcha and rejected on
   every attempt.
2. **A second, hidden fault found during the fix:** the real secret had been
   *appended* as a new line while the placeholder line remained above it.
   `env_loader.php:19` uses `if (!array_key_exists($key, $_ENV))` — **first
   occurrence wins** — so the placeholder kept overriding the real secret. The
   bug would have persisted with no visible reason.

**Changes made:**

| File | Change |
|------|--------|
| `.env` | Real sitekey set; duplicate `HCAPTCHA_SECRET_KEY` line removed so only the real secret remains |
| `AdminAuthController::verifyCaptcha()` | Treats `YOUR_*` placeholders as unset; logs hCaptcha `error-codes` on rejection; logs empty-token and network-failure cases |
| `admin-auth.js` `getSiteKey()` | Same placeholder guard, keeping front end and back end consistent |

**Verification performed:**

- Sitekey reaches the DOM correctly (`08fa8392-…`, not a placeholder).
- A deliberately failed login makes the widget appear and **actually render** —
  confirmed a live `iframe` from `newassets.hcaptcha.com`.
- Secret validated against the live API by probing with a dummy token:
  hCaptcha returned `invalid-input-response`, **not** `invalid-input-secret` —
  proving the secret is accepted. (`allow_url_fopen` confirmed `On`.)

No captcha was solved or bypassed to verify this; the error-code probe
distinguishes a bad secret from a bad token without doing so.

---

## Bug 1 — Wishlist notify state: confirmed exactly as described

The task document's diagnosis is **correct in full**. Verified:

- `WishlistController::stock()` returns only `Product_dit::findStockByIds($ids)`
  — stock, price, discount, visibility. No notify state.
- `wishlist.js` line ~157 hardcodes the un-requested look on every render:
  ```js
  <button type="submit" class="btn btn-outline-warning w-100 js-notify-btn">🔔 Notify Me When Available</button>
  ```
- The two working pages compute it server-side from `stock_notifications`:
  `ProductController` line ~30 builds `$notifiedProductIds` for the listing,
  line ~154 builds `$alreadyRequested` for the details page.

### Fix

1. **Extend `WishlistController::stock()`** to include a per-product
   `already_notified` boolean, using the same query shape as
   `ProductController` line 30:
   ```sql
   SELECT product_id FROM stock_notifications WHERE user_id = ? AND product_id IN (...)
   ```
   Guard it with `isUser()` / `getCurrentUserId()` — this endpoint is a plain
   `GET` with no login requirement, so for a logged-out visitor every product
   must return `already_notified: false` rather than erroring or leaking
   another user's state.

2. **Update `wishlist.js`** to branch on the flag, mirroring
   `product.php` byte-for-byte so the three pages are visually identical:
   ```js
   ${alreadyNotified
     ? `<button class="btn btn-success w-100" disabled>✅ We'll notify you!</button>`
     : `<form class="js-notify-form" ...>…btn-outline-warning…</form>`}
   ```

3. **Verify the live-click path too.** `notify-stock.js` already flips the
   button after a successful submit; confirm the post-reload server-rendered
   state now matches what the click produced, so the button does not "revert"
   on refresh — that revert is the actual user-visible symptom.

### Test

Same user, same out-of-stock product, all three pages: product listing,
product details, wishlist. Click Notify on any one → reload all three → all
must show the green disabled state.

---

## ✅ Bug 1 outcome (completed 2026-08-24)

**Real cause:** exactly as the task document described. The wishlist is rendered
client-side from `localStorage`, and its data source
(`WishlistController::stock()`) returned only stock/price/visibility — never the
per-user notify state. `wishlist.js` therefore hardcoded the un-requested look
on every render, so the green state produced by a click vanished on reload.

**Changes made:**

| File | Change |
|------|--------|
| `WishlistController::stock()` | Adds `already_notified` per product, from `stock_notifications` for the current user. Computed in the controller, not `findStockByIds()`, because that model method is generic and shared; the flag is session-dependent. Wrapped in try/catch so a failure here degrades to "not requested" instead of dropping stock data. |
| `wishlist.js` | New `alreadyNotified` const from the live data; the notify button now switches class/`disabled`/label, mirroring `product.php` exactly. |

**Security note:** the endpoint is a public `GET` with no login requirement, so
the flag is gated behind `isUser() && getCurrentUserId()`. Anonymous visitors
get `false` for everything rather than another user's state.

**Verification performed:**

- Endpoint now returns the field — anonymous request for products 4 and 18
  returned `already_notified: false` for both (correct: no session).
- The underlying query isolates per user correctly, checked against real rows:
  user 100 → product 18; user 7 → product 4.
- Both render branches produce markup identical to `product.php`:
  `btn w-100 js-notify-btn btn-success` + `disabled` + "✅ We'll notify you!",
  versus `btn-outline-warning` + enabled + "🔔 Notify Me When Available".
- `notify-stock.js` compatibility confirmed: it toggles those same two classes
  and short-circuits on `btn.disabled`, so the click path and the
  server-rendered path now agree.

**Left for you to eyeball:** the logged-in render. I have no user password, so
the final visual check needs a real login — open the wishlist as a user with an
out-of-stock item already requested (user 100 / product 18 is live test data).

---

## Bug 3 — Forgot Password: needs runtime diagnosis, but the task's lead suspect is already ruled out

The task document's main hypothesis is that `MAIL_PASSWORD` might be a regular
Google password rather than an App Password. **That appears already satisfied**
— the configured value is in Google's 16-character App Password format (four
groups of four lowercase letters), not an account password.

So the cause lies elsewhere. Confirmed context:

- `Mailer::send()` (`app/core/Mailer.php` line ~42) swallows every failure into
  `error_log` and returns `false`.
- Both `AuthController::forgot()` and `AdminAuthController::forgotPassword()`
  return `success: true` unconditionally, by deliberate anti-enumeration
  design. So a total SMTP failure is invisible from the UI. That design is
  correct and should be kept — the fix is observability, not changing the
  response.
- `app/config/env_loader.php` parses with `explode('=', $line, 2)` then
  `trim`, so the spaces inside the password survive intact into
  `$_ENV['MAIL_PASSWORD']`. The loader is not corrupting the value.

### Two suspects, in priority order

**Suspect 1 — missing CA bundle on XAMPP (most likely; not mentioned in the
task document).** This is the single most common cause of silent SMTP failure
on XAMPP/Windows. PHP ships without a usable `curl.cainfo` /
`openssl.cafile`, so the STARTTLS handshake to `smtp.gmail.com` fails
certificate verification and PHPMailer throws before sending. Diagnosis: the
log line will contain `SMTP connect() failed` or a certificate-verify error.
Fix: point `openssl.cafile` in `php.ini` at a current `cacert.pem`.

**Suspect 2 — literal spaces in the App Password.** Google displays the
password grouped for readability; the spaces are presentation only. PHPMailer
forwards the string verbatim. Worth normalising defensively:
```php
$mail->Password = str_replace(' ', '', $_ENV['MAIL_PASSWORD'] ?? '');
```

### Plan

1. Enable `error_log` / `display_errors`, trigger one forgot-password request,
   and read the actual `Mailer::send Error:` line. **This single step decides
   between the two suspects — do not guess.**
2. Confirm `UserModel::createPasswordReset()` returns a non-null token, so we
   know the failure is in delivery and not token creation.
3. Apply whichever fix the log points to.
4. **Add permanent observability** so this cannot fail silently again: keep the
   generic `success: true` for the client, but log a distinguishable marker on
   send failure. Do **not** leak the failure reason into the HTTP response —
   that would undo the anti-enumeration protection the current design provides.
5. Test end-to-end: request → email arrives → link → password changed → login
   with the new password.

---

## 🔴 Bug 3 — ROOT CAUSE FOUND (round 2): it is CSRF, not email

The owner reported the actual on-screen error:

> **"Invalid CSRF token, please refresh and try again."**

That changes everything. My first pass tested the endpoint by **manually
supplying a CSRF token borrowed from another form**, which is exactly why it
passed. The real UI never gets that far — it fails CSRF validation *before*
`Mailer::send()` is ever reached. All the mail-pipeline evidence below stays
valid and useful (the mail path is proven healthy), but it was never the fault.

### Two compounding faults

**Fault 1 — the forgot form has no CSRF field at all.**

`app/views/inc/modals/forgot-password.php` contains **zero** occurrences of
`csrf_token`, while `login.php` and `register.php` each have one. `auth.js`
submits it with `body: new FormData(forgotForm)`, so nothing is sent, and
`AuthController::forgot()` rejects it at the first check.

**Fault 2 — the CSRF retry safety net cannot rescue it.**

`csrf.js` → `fetchWithCsrfRetry()` is supposed to recover from exactly this
error. It fetches a fresh token, then rebuilds the body:

```js
for (const [key, val] of options.body.entries()) {
    newBody.append(key, key === 'csrf_token' ? newToken : val);
}
```

This only **replaces a key that already exists**. When `csrf_token` is absent
the loop never adds it, so the retry re-sends an identical token-less request
and fails again. Any form missing the field is silently unrecoverable — this
will bite again elsewhere.

### ✅ Fix applied (2026-08-24)

| File | Change |
|------|--------|
| `inc/modals/forgot-password.php` | Added the missing `csrf_token` hidden input |
| `js/core/csrf.js` | Retry now **appends** the token when the form lacks the field, instead of only replacing an existing one |

**Audit result:** every other `<form>` in `app/views/` that lacked a
`csrf_token` was checked — all five (`dashboard`, `orders/index`,
`product/index` ×2, `support`, `users/index`) are `method="GET"` search or
filter forms, which correctly need no CSRF. **`forgot-password.php` was the
only real gap.**

**Verified through the real UI** (not a hand-built request this time):

- The rendered form now carries a 64-character token in its fields
  (`action`, `csrf_token`, `email`).
- Submitting via the genuine `auth.js` handler returned
  **`alert-success`** — "If this email is registered, you will receive a reset
  link shortly." **The "Invalid CSRF token" error is gone.**
- Round trip took 7.5 s (real SMTP), a fresh row (id 8) appeared in
  `password_resets`, and **zero** new lines were written to the PHP error log.

**Still yours to confirm:** that the email actually lands in the inbox, and
that clicking its link completes a password change. Everything up to hand-off
to Gmail is now proven working.

### Original fix plan (for reference)

1. **`forgot-password.php`** — add the hidden field, matching `login.php`
   exactly (the `$csrfToken` variable is already in scope; `footer.php` defines
   it before including the modals):
   ```php
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
   ```
2. **`csrf.js`** — make the retry *set* rather than only replace, so a missing
   field is added instead of silently dropped. Build the new body, then ensure
   the token key exists before resending.
3. **Audit every other form** in `app/views/` that POSTs to a CSRF-protected
   endpoint, and confirm each carries the field. Fault 1 is unlikely to be the
   only instance.
4. **Retest end-to-end** through the real UI (not a hand-made request this
   time): open the Forgot Password modal → submit → confirm success → confirm
   the email arrives → follow the link → change the password.

### Why the mail evidence still matters

Because the SMTP path is already proven healthy, fixing CSRF should be
sufficient — no mail configuration changes are needed. Evidence gathered
before the rediagnosis:

## Bug 3 — mail-pipeline evidence (still valid, and now known not to be the fault)

I ran the whole flow live and **every stage passed**. I did not change any code,
because there is no demonstrated fault to fix.

**Evidence gathered:**

| Check | Result |
|-------|--------|
| Real `POST /auth/forgot` with a valid CSRF token and a registered email | HTTP 200, took **6.5 s** — consistent with a genuine SMTP round trip, not an instant failure |
| `Mailer::send` error logging | **Nothing written.** The log did not grow by a single byte. Since `send()` logs on every exception path, it did not throw |
| Reset-token creation | ✅ Fresh row written to `password_resets` (id 7) at the exact request time — so token generation is fine |
| Reset link in the email body | ✅ Uses `URLROOT . '/auth/reset?...'`, which I confirmed returns **200** (note: `/auth/reset-password` is *not* a route and 404s — but the code does not use it) |
| SMTP connect + authenticate (no mail sent) | ✅ STARTTLS negotiated, then **`235 2.7.0 Accepted`** — Gmail accepted the credentials |

**Both hypotheses in the plan are now disproven:**

- *CA bundle missing on XAMPP* — ruled out; STARTTLS completed successfully.
- *Spaces in the app password* — ruled out; Gmail returned `235 Accepted`
  with the value exactly as stored, spaces included. No normalisation needed.

And the task document's own hypothesis (a regular password instead of an App
Password) was already wrong, as noted before.

**Conclusion:** an email *was* sent to `ahmadsaleh99887766@gmail.com` at
**03:43** during this test. The most likely explanation for the reported
symptom is **delivery, not code** — mail sent through Gmail SMTP from a local
XAMPP box very often lands in **Spam/Junk**.

**What I need you to check:** open that inbox — including Spam/Junk and the
"All Mail" view — for a message titled *"إعادة تعيين كلمة المرور"* around
03:43. Then:

- **If it is there** → there is no bug; the flow works. The only worthwhile
  follow-up is deliverability (SPF/DKIM on a real domain when you deploy).
- **If it is genuinely absent** → tell me, and I will instrument
  `Mailer::send()` to log its boolean return plus `$mail->ErrorInfo` on the
  success path too, so we capture the case where SMTP accepts the message but
  Gmail silently drops it afterwards.

### Separate issue spotted in the log (not part of these four bugs)

Entries from 23-Aug 21:08–21:16 show:

```
Table 'ciro_db.categories' doesn't exist in engine
Table 'ciro_db.notifications' doesn't exist in engine
```

*"doesn't exist in engine"* indicates InnoDB tablespace corruption, not a
missing table. Both tables respond normally now (the storefront renders
categories fine), so it appears to have been transient — most likely an
unclean MySQL shutdown. Worth knowing if odd data errors reappear; I have not
acted on it.

---

## ⚠ Bug 2 — REDIAGNOSED: the reported symptom is a different bug entirely

**The task document describes the wrong problem.** It says the Edit button does
not appear, and attributes it to `canManageTarget`. The project owner has since
confirmed the actual symptom:

> "الزر موجود لكن عند الكبس عليه … الصفحة تصبح باهتة قليلا لكن المفروض يطلع
> ديلوغ بوبب بس ما بطلع"
> *(The button is there, but clicking it dims the page slightly — the popup
> dialog that should appear never does.)*

So the permission logic in `canManageTarget` is **not involved at all**. The
button renders, the click handler runs. Everything in the section below about
the rank rule is still true and still worth knowing (see "Do not 'fix' the rank
rule"), but it is not this bug.

### What the symptom tells us

The page dimming is the Bootstrap backdrop (`--bs-backdrop-opacity: 0.12` in
`style.css` — a deliberately *slight* dim, matching the description exactly).
A backdrop only appears if `.show()` actually ran. So the modal is being
opened; its dialog is just not visible.

### What has been ruled out

- **CSS is not the cause, and neither is my earlier `modal-dialog-scrollable`
  change.** I rendered the exact `_perm-modal.php` structure against the real
  `bootstrap` + `style.css` + `admin.css` stack and measured it: the dialog
  comes out **312 × 335 px, `opacity: 1`, `visibility: visible`, `z-index:
  1055`, backdrop present.** It renders correctly in isolation.
- **No duplicate handler.** `openPermModal` is defined only in
  `manage-admins.js`; `admins.js` does not define it.
- **The modal is included correctly** — `index.php:163`, outside the `<table>`,
  so no HTML-parser relocation.
- **`openPermModal` itself is sound** — it null-checks `#permTargetId` and
  `#permModal`, then calls `bootstrap.Modal.getOrCreateInstance(el).show()`.

### Leading hypothesis (needs one console read to confirm)

Each row is `<tr class="clickable-row" data-href=".../admin/admins/details?id=N">`
and `manage-admins.js` binds `window.location.href = row.dataset.href` to it.
The actions `<td>` carries `onclick="event.stopPropagation()"` specifically to
suppress that.

If that suppression fails for any reason, clicking Edit would **both** open the
modal **and** start navigating to the details page. The page-transition overlay
(`#page-overlay`, `z-index: 1040`) dims the page, the in-flight navigation tears
down the modal before it paints — producing exactly "page dims, no dialog."

A JavaScript exception thrown inside `openPermModal` *after* `.show()` is the
other candidate, though its `catch` block raises an `alert()`, which was not
reported.

## ✅ Bug 2 outcome — SOLVED: an unclosed `<div>` three files away

**Real cause:** `app/views/admin/broadcast-form.php` opened
`<div class="perm-grid mb-4">` for the *Ranks Filter* group and **never closed
it** — 10 `<div>` against 9 `</div>`.

`manage-admins/index.php` includes the modals in this order:

```php
include '../notify-modal.php';
include '../broadcast-form.php';   // ← left a <div> open
include '_perm-modal.php';         // ← therefore swallowed by #broadcastModal
```

So `#permModal` was parsed as a **child of `#broadcastModal`**. A hidden
Bootstrap modal carries `display: none`, and that inherits down the whole
subtree. The result: Bootstrap opened `#permModal` perfectly — `.show` added,
`display:block`, `z-index:1055`, backdrop created — but every element inside it
measured **0 × 0**, so nothing was painted. The page dimmed from the backdrop
and no dialog ever appeared.

**Why it only broke on this page:** the unclosed div sits in the `else`
(admin-broadcast) branch. `users/index.php` passes
`$broadcastTargetType = 'user'`, which takes the `if` branch — properly closed
— so Manage Users was unaffected.

**Not caused by the earlier responsive work.** The missing `</div>` predates it;
that pass only renamed the wrapper's classes and dropped inline styles,
preserving the (already broken) structure.

**Fix:** added the missing `</div>` with a comment explaining the blast radius.

**Verification — negative control on the real files.** Rendered the three
actual PHP includes and walked the parsed DOM:

| State | `<div>` diff | `#permModal` parent chain |
|-------|--------------|---------------------------|
| Bug reintroduced | 1 | `div#broadcastModal.modal` ← `div#root` ❌ |
| Fixed | 0 | `div#root` ✅ |

This matches the on-page diagnostic exactly, which reported the offending
parent as `DIV.modal : 0x0 disp=none`.

**Project-wide audit:** every `.php` file under `app/views/` was checked for
`<div>`/`</div>` balance — `broadcast-form.php` was the **only** unbalanced
file.

**Diagnostic artifacts removed:** `public/js/_diag-permmodal.js` and its
`<script>` include are deleted; no debug code remains.

### How it was found (method note)

Three rounds of narrowing, because the first two hypotheses were both wrong:

1. Full-fidelity offline repro (6 stylesheets, both themes) — **rendered fine**,
   which correctly ruled out all CSS and my `modal-dialog-scrollable` change.
2. On-page diagnostic round 1 — reported `.modal-content` at **0×0** while
   `opacity:1 / visibility:visible`, with exactly **1** `#permModal` and **1**
   backdrop. That eliminated duplicate-ID and stacking theories and pointed at
   a collapsed subtree rather than an overlay.
3. On-page diagnostic round 2 walked the **ancestor chain** and immediately
   exposed `DIV.modal : disp=none` sitting above the modal — the answer.

The lesson worth keeping: when an element is `visible` yet measures 0×0, stop
inspecting the element and inspect its **ancestors**.

### Round 2 — deep investigation, still not reproducible

The owner confirmed **no console errors at all**, and supplied before/after
screenshots showing the page dims but no dialog appears.

I built a **full-fidelity reproduction**: all six stylesheets loaded in the
exact order `admin/inc/head.php` uses (bootstrap → `style.css` → `admin.css` →
`admin-head.css` → `admin-navbar.css` → `admin-footer.css`), the real
`body class="page-transitioning admin-layout"`, the navbar, the
`<main class="container-fluid py-4 px-4">` wrapper, the `.clickable-row` table
with its `stopPropagation` cell, a verbatim copy of `_perm-modal.php`, the
`#adminNotifSidebar`, and a byte-identical copy of `openPermModal`.

**Result — it works, in both themes:**

| Measurement | Light mode | Dark mode |
|---|---|---|
| Modal `.show` | true | true |
| `.modal-content` box | 800 × 599 @ top 151 | 800 × 599 @ top 151 |
| opacity / visibility | 1 / visible | 1 / visible |
| Topmost element at screen centre | `LABEL.perm-item` (the modal) | `LABEL.perm-item` |
| Row navigation fired? | **no** — `stopPropagation` worked | — |

**Now ruled out (do not re-investigate these):**

- CSS from any of the six admin stylesheets, in either theme.
- My earlier `modal-dialog-scrollable` addition.
- `.clickable-row` navigation stealing the click — `stopPropagation` works.
- `ui.js` `showLoading()` overlay (`z-index: 9999`) — **it has no callers
  anywhere in the codebase**, so it never fires.
- `#page-overlay` (`z-index: 1040`) — sits *below* the modal's 1055, so it
  could not hide it even if it fired.
- Duplicate/conflicting `openPermModal` — defined only in `manage-admins.js`.
- Modal markup trapped inside the `<table>` — it is included at
  `index.php:163`, outside it.

The fault therefore depends on something present only in the live session —
most likely runtime state or a script interaction I cannot replicate offline.

### What I need: one diagnostic run on your real page

Open Manage Admins while logged in, press F12 → Console, paste this, press
Enter, then click ✏️ and send me the single line it prints:

```js
(()=>{const m=document.getElementById('permModal');
if(!m)return console.log('DIAG: #permModal MISSING from page');
m.addEventListener('shown.bs.modal',()=>{const c=m.querySelector('.modal-content'),r=c.getBoundingClientRect(),s=getComputedStyle(c);
const mid=document.elementFromPoint(innerWidth/2,innerHeight/2);
console.log('DIAG',JSON.stringify({box:[Math.round(r.width),Math.round(r.height),Math.round(r.top)],op:s.opacity,vis:s.visibility,mz:getComputedStyle(m).zIndex,
top:mid?mid.tagName+'.'+(mid.className||'').toString().split(' ')[0]:null,
backdrops:document.querySelectorAll('.modal-backdrop').length,
modals:document.querySelectorAll('#permModal').length}));});
console.log('DIAG armed — now click the ✏️ button');})()
```

It reports the dialog's real size/position, what is actually on top at screen
centre, how many backdrops exist, and whether the page accidentally contains
**two** `#permModal` elements — the one remaining hypothesis I cannot test from
here. Alternatively, give me a local admin login and I will find it directly.

### ⚠ Do not "fix" the rank rule — it is load-bearing security

While tracing this I confirmed something important that must not be changed
casually, in case the rank rule is revisited later.

### What the rule is

`AdminModel::canManageTarget()` (line ~209) requires the actor's rank to be
*strictly* greater than the target's:

```php
return self::getRankValue($actorRole) > self::getRankValue($targetRole);
// A=4, B=3, C=2, D=1
```

### Why it is clearly deliberate

1. **It is enforced in four server-side authorization checks**, not just the
   view — `AdminManageAdminsController` lines 116, 218, 221, 329. The view
   condition at `index.php:125` merely mirrors the guard at line 218.
2. **The error message states the rule in words**: *"You cannot edit an admin
   with an equal or higher rank than your own."*

### Why relaxing it is dangerous

`storeEdit()` has **no self-guard** — there is no `$targetId === $adminId`
check anywhere in it. The strict `>` comparison is the *only* thing preventing
an admin from editing their own row. Change it to `>=` and any admin can open
the permission modal on themselves and grant themselves every permission,
including `can_manage_admins`. That is full privilege escalation, reachable in
two clicks.

If the rule is ever intentionally relaxed, an explicit
`if ($targetId === $adminId) reject;` guard must be added to `storeEdit()`
**first**.

### Current account layout (for reference)

The live `admins` table holds exactly two accounts:

| id | name | role |
|----|------|------|
| 1 | Ahmad Saleh | A |
| 2 | ALI | B |

Button visibility under the current (correct) rule:

- **Logged in as Ahmad (A):** own row → hidden (blocks self-edit); ALI's row →
  Edit shows. ✅ consistent with "the button is there".
- **Logged in as ALI (B):** no Edit anywhere — no C/D admin exists to manage.

This confirms the owner was logged in as **Ahmad (role A)** when reporting, and
that the rank logic is behaving exactly as designed.

---

## Bug 5 (new) — Manage Orders toolbar does not match the other admin pages

**Requested:** make Manage Orders look like Users / Manage Admins / Products.

**Diagnosed cause.** Three of the four pages follow one convention; Orders
breaks it. The convention is:

> `.admin-page-header` holds **only the title and page-level action buttons**
> (Export CSV, Add, Broadcast). Search and filters live on **their own row
> below**, full width.

| Page | Header right side | Search / filter location |
|------|------------------|--------------------------|
| Users | Export CSV, Broadcast | own row below (`form.d-flex.gap-2.flex-wrap.mb-3`) |
| Manage Admins | Export CSV, Add Admin, Broadcast | none |
| Products | Export CSV, Add Product | own rows below |
| **Orders** | Export CSV **+ search form + status `<select>`** ❌ | **crammed into the header** |

Because Orders stuffs the search and the status dropdown into the header's
right-hand cluster, that cluster becomes crowded and wraps awkwardly — which
is what you are seeing.

### ✅ Fix applied (2026-08-24)

`app/views/admin/orders/index.php` restructured to the shared convention:

- Header now holds **only** the title and the Export CSV button.
- Search + status filter moved to their own full-width row below the flash
  messages, as a **single flat form** matching `users/index.php`.
- Dropped the redundant `d-flex justify-content-between align-items-center
  flex-wrap gap-2 mb-4` classes from `.admin-page-header` — `admin.css:33`
  already defines all of that, and the duplicates were overriding its gap and
  margin.
- Removed the now-unneeded `.fluid-input` / `.search-form` overrides; sizing
  now matches Users (`max-width:280px` / `180px`).

**One thing measurement caught:** my first attempt nested the form inside an
outer flex `<div>`. That made the form a narrow flex item and the row wrapped
to two lines — **77 px tall instead of 38 px**. Flattening it to one form fixed
it. Worth knowing if this layout is ever edited again.

**Behaviour preserved:** the `<select>` deliberately has **no `name`**. It
navigates immediately via `filterStatus()` in `orders.js`, while the hidden
`status` input carries the filter through a text search. Giving it a name would
duplicate and conflict with that input. All four status values, the hidden
input, `.delete-order-btn` and the `.table-responsive` wrapper are unchanged.

**Verified by measurement against the Users page:**

| | 1280 px | 375 px |
|---|---|---|
| Toolbar height (Orders / Users) | 38 / 38 ✅ | 86 / 86 ✅ |
| Search input width | 280 / 280 ✅ | 280 / 280 ✅ |
| Status select width | 180 / 180 ✅ | 180 / 180 ✅ |
| Page overflow | none ✅ | none ✅ |

(Header heights differ by 1 px only because Users carries an extra "Broadcast"
button — expected, not a defect.)

### Original fix plan (for reference)

1. In `app/views/admin/orders/index.php`, leave **only** the Export CSV button
   inside `.admin-page-header`.
2. Move the search `<form>` and the status `<select>` into a new row placed
   directly after the flash messages, styled exactly like `users/index.php`:
   `class="d-flex gap-2 flex-wrap mb-3"`.
3. Keep the two controls in **one** GET form so filtering and searching submit
   together, and add a "✕ Clear" link matching Users.
4. Align control sizing with Users (`max-width:280px` search, `max-width:180px`
   select) and drop the now-redundant `.fluid-input` overrides added earlier.

### Must not break

- `onchange="filterStatus(this.value)"` on the status select — bound to
  `orders.js`. Keep the attribute and its exact option values
  (`not_taken` / `taken` / `cancelled` / `completed`).
- The hidden `status` input that preserves the filter while searching.
- `.delete-order-btn` and the table's `.table-responsive` wrapper.

### Verification

Confirm at 1280 px and 375 px that the header no longer wraps, that searching
preserves the active status filter, and that changing the status dropdown still
navigates correctly.

---

## Why this order

The document says 1 → 2 → 3 → 4. I recommend **4 → 1 → 3 → 2**:

- **4 first** because it is a one-line change with a fully known cause, and it
  unblocks admin login — which is a prerequisite for testing Bug 2 at all.
- **1 second** because it is self-contained, fully diagnosed, and needs no
  external services.
- **3 third** because it needs a real runtime log read and possibly a
  `php.ini` change plus mail-delivery round trips.
- **2 last** because it is blocked on your answer and may turn out to require
  no code change whatsoever.

---

## Revised order (round 2)

**3 → 5 → 2.** Bugs 1 and 4 are done.

- **Bug 3 first** — root cause is known and it is the highest-value fix
  (password reset is currently broken for every user).
- **Bug 5 second** — self-contained layout work, no unknowns.
- **Bug 2 last** — blocked until the diagnostic comes back.

## What I need from you before implementing

1. **Bug 4** — paste the **Secret Key** into `.env` yourself:
   ```
   HCAPTCHA_SECRET_KEY=ES_xxxxxxxxxxxxxxxx
   ```
   No quotes, no spaces around `=`. Do not send it through chat. Tell me when
   it is in and I will wire in the sitekey plus the hardening.
2. **Bug 2** — either the browser console output when clicking ✏️ (F12 →
   Console, plus whether the URL changes), or local admin credentials so I can
   reproduce it myself. This is the only genuine unknown left.

Bugs 1 and 3 need nothing from you; 1 is fully specified, and 3 starts with me
reading the mail error log.

## Security follow-ups noted during investigation

- `.env` holds the DB credentials, a Gmail app password, and now the hCaptcha
  secret. Confirm it is git-ignored and never committed. (To be verified as
  part of Bug 3.)
- Re-enable hCaptcha Domain allowlisting before any public deployment.
