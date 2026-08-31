# Changelog

Follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow [SemVer](https://semver.org/).

> This file started late. Everything before `[1.1.0]` is reconstructed from the git history, and the full detail of that period is archived outside the repository.

---

## [Unreleased]

### Added

- **297 automated tests** — unit, integration and browser. The project had not one test.
  - `Totp` against the reference vectors of RFC 6238 — that is, the 2FA really does agree with Google Authenticator.
  - The CSRF contract over HTTP: it sweeps every POST endpoint from the router itself, so an endpoint added tomorrow enters the check automatically.
  - Guard parity: it compares the route table with the action bodies in both directions.
  - OpenAPI coverage: no route without documentation, and no documented operation without a route.
- **CI on GitHub Actions** — tests against a real MySQL (PHP 8.2 and 8.3), static analysis, and a security scan. Every tool that runs in the hooks runs here too.
- **PHPStan** at level 6 and **PSR-12** — zero errors in both, without a single baseline.
- **A complete OpenAPI specification**: 16 schemas, 12 shared responses and 135 references (there were none), and 53 examples (there was one).
- **Guarding declared in the route table** — 51 guards, with groups and named routes.
- `PUT`, `PATCH` and `DELETE` support in the router.
- Security headers: `nosniff` · `X-Frame-Options` · `Referrer-Policy` · `Permissions-Policy` · conditional HSTS · a fully enforced CSP with neither `'unsafe-inline'` in `script-src` nor in `style-src`.
- `Database::setConnection()` — an injection port for the tests, confined to the CLI.
- `ErrorPage::serverError()` and `ErrorPage::methodNotAllowed()`.
- `envBool()` for reading boolean flags from `.env`.
- Repository files: README · CONTRIBUTING · LICENSE · CHANGELOG · `.editorconfig`.

### Changed

- **The whole repository was converted to English** — comments, printed strings, test names, documentation and configuration. It was written in Arabic throughout; the commit messages stay Arabic, because rewriting them means rewriting the history, and that is a real risk against a cosmetic gain.
- **`.env` became the single source of the database settings.** The values used to be written out in `config.php` and to **take precedence** over `.env` — so the file was read and then ignored entirely.
- **`display_errors` became conditional on the environment.** It was pinned to `1` unconditionally, so every exception in production printed its paths to the visitor. And the default when `APP_ENV` is absent is **production** — forgetting to set it should conceal rather than disclose.
- `config.php` now loads `.env` itself, so no entry point depends on remembering it.
- **Path matching became two-stage** — the path first and then the method, so a 405 with an `Allow` header became possible.
- `Middleware::requireLogin()` now distinguishes a full page from a JSON endpoint.
- One MIME map governs both acceptance and the extension in image uploads.
- The OpenAPI tags were unified on `Section - Name` with real descriptions.
- Emptying between tests uses `DELETE` rather than `TRUNCATE` — 33 times faster (8.585 s → 0.256 s).

### Fixed

- **A leak of the connection details when the database failed.** `die($e->getMessage())` printed the host, the database name and the user to the visitor, and ended the request with a **200**, so monitoring tools read the broken page as healthy. It is now a 503 with the detail going to the log.
- **Path matching treated a dot as a regex metacharacter.** A path such as `/handlers/notify_handler.php` was also reachable as `/handlers/notify_handlerXphp`.
- **`env()` did not work** — the precedence of `??` over `?:` makes an empty key bypass the default value. It never showed because zero callers used it.
- **`(bool)"false" === true` in PHP** — `APP_DEBUG=false` would have opened debug mode rather than closing it.
- **`Router::route()` threw on every name**, however correct — it searched a map nobody filled.
- The allowed MIME list was separate from the extension branches: adding a type without a branch saved the file with a `.jpg` extension **silently**.
- A duplicated `unset` on `$_SESSION` was hiding the fact that two textually identical lines operate on **two different sessions**.
- Two dead private functions — one of them with a **live** copy in another controller, so editing the notification rule looked finished while it was half finished.
- Two conditions that never held: a `=== null` after a `??`, and a `=== false` from `token_get_all`.
- `floor()` returning a `float` where an `int` is required in the TOTP code generation.
- `X-Powered-By` is now removed in the application — `Header always unset` in Apache is not enough, because PHP adds it after `mod_headers`.
- **The CSP's digest for the theme boot script differed per platform.** It is computed over the content including the line endings, and `.gitattributes` checks the file out as CRLF on Windows and LF elsewhere — so one source produced two digests and the script was blocked on whichever platform was not the one the digest was written on. The line endings are now normalised at the source, inside `themeBootScript()`.

### Removed

- Seventeen plan and report files from the project root (archived outside the repository), and a temporary CSS copy.
- `docs/` and `.claude/` from tracking.

### Security

- `gitleaks` over the entire history in CI — a secret deleted in the last commit remains in the history.
- The gitleaks exception for the RFC 6238 vector is **by literal value rather than by path**, so the file stays scanned.

---

## [1.1.0]

### Added

- OpenAPI documentation with complete route coverage.
- The security scanning tools: gitleaks, semgrep and trivy, with local semgrep rules encoding this project's own faults specifically.
- An SRI digest for the API documentation page.

### Changed

- **An explicit `error_code` instead of the message's text** for a CSRF failure. The client detected the failure by matching the beginning of the text, so any endpoint wording its message differently lost the retry **silently** — which happened three times.
- The JSON endpoints' preamble was unified: from 18 sites to 45 through `beginJsonPost()`.
- Session hardening and path containment, and the database password left the `mysqldump` command line.

### Fixed

- A CSRF hole in both sign-out endpoints.
- A CSRF hole in the notification endpoints.
- `AdminProductModel::update` — an explicit contract instead of an unconditional `true`.
- `AdminProductModel::delete` returned `true` for a product that never existed, so the controller wrote a **lying audit log**.
- The raw `die()` sites that leaked the server's paths.

---

## [1.0.0]

The first release, after six cleanup phases:

1. Removing the dead code and unifying the naming.
2. Zero SQL in the controllers — every query in the models.
3. Unifying `respond()` and extracting two services.
4. The Core: three layouts in `view()`, a real 404, and the helpers through composer autoload.
5. The views: zero embedded `<style>`, seven partials, and all of the logic outside the templates.
6. The JS: one shared rule for the stock badge, and a CSRF safety net through the JS layer.
