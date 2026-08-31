# Contributing to Cairo Store

## Before anything else

```bash
composer install
pre-commit install --hook-type pre-commit --hook-type pre-push
```

⚠️ The second line is **required**. Without `--hook-type pre-push` the `.git/hooks/pre-push` file does not exist at all, so semgrep never runs — the configuration alone is not enough.

gitleaks and trivy are called by their bare names, so they must be on the `PATH` that git sees. After any fresh installation, close the terminal and open it again: the running process inherited an older `PATH`. semgrep is **not** on that list any more — `.pre-commit-config.yaml` pins it and pre-commit builds its own environment, so the version you run is the version CI runs.

### A coverage driver

The coverage gate needs one, and without it `phpunit --coverage` produces nothing at all — which is how the threshold in CI came to sit at a figure nobody could measure. Install **pcov**, the same driver CI uses:

1. Download the build matching your PHP from the official archive — check `php -i | findstr "Thread Compiler Architecture"` first. For XAMPP's PHP 8.2 on Windows that is `php_pcov-1.0.12-8.2-ts-vs16-x64.zip` from `downloads.php.net/~windows/pecl/releases/pcov/`.
2. Put `php_pcov.dll` in `php/ext/`.
3. Add to `php.ini`:

   ```ini
   extension=pcov
   pcov.enabled=0
   ```

`pcov.enabled=0` is deliberate: the extension loads but instruments nothing, so the web server and ordinary scripts pay no cost. `composer test:coverage:gate` switches it on for its own process with `-d pcov.enabled=1`.

Confirm with `php -m | findstr pcov`.

---

## Workflow

A branch per unit of work, then a merge with `--no-ff` so the unit's history stays readable:

```bash
git checkout -b cleanup/some-topic
# … the work …
composer check          # must be green
git checkout main
git merge --no-ff cleanup/some-topic
```

---

## The gates

`composer check` runs all nine together, and every one of them must pass:

| Gate | The bar |
|---|---|
| `composer validate --strict` | valid |
| PHPStan | **zero** errors at level 6 |
| PSR-12 | **zero** violations |
| Escaping gate | `NEEDS` at zero in the views |
| README numbers | they match what the code measures |
| OpenAPI specification | regenerating it changes nothing |
| PHPUnit | every test green |
| Coverage | not below the floor in `scripts/coverage-gate.php` |
| semgrep | zero findings, on CI's rules and CI's version |

### It has to be the same command CI runs

The last three arrived late, and their absence was not a detail. `composer check` is the thing that answers "is this safe to push", and those three gates lived only in the workflow — so they could not be run before a push, and each one was red in CI for a long time without anyone being able to see why:

- **semgrep** ran `--config auto` here and `--config p/php` there, on whatever version happened to be installed. Measured: 1.174.0 scanned 185 files and reported nothing; the 1.145.0 that CI pins scanned 343 and reported nine findings, two of them real bugs.
- **Coverage** could not be measured at all without a driver, and the threshold read 25% while the reality was 10%.
- **The OpenAPI check** compared bytes that a Windows machine cannot produce, because swagger-php joins description lines with `PHP_EOL`.

So when you add a gate to `.github/workflows/ci.yml`, add it to `composer check` in the same commit — and if the two need different commands to do the same job, the difference is a bug in one of them.

### The coverage floor is a ratchet

It holds the figure **actually measured**, so it fails on a regression rather than on failing to reach an aspiration — the second kind of gate is red forever and therefore ignored. Raise it when new tests raise the number; never lower it without writing the reason beside the change. Keep `scripts/coverage-gate.php` and the value in `ci.yml` in step.

### Zero means zero

**No `@phpstan-ignore`, no baseline, and no exclusion in `phpcs.xml`** without a comment explaining why the check itself is wrong here — not why the code is hard to fix.

The repository carries two exclusions today, and both have their reason written down:

- `Generic.Files.LineEndings` — the line endings belong to `.gitattributes`. With `text=auto`, git stores the file as LF and checks it out as CRLF on Windows, so this check cannot possibly pass on both systems: it passes in CI and fails locally for the same file with the same content. A check whose result follows the operating system is not a check.
- `Generic.Files.LineLength` — 153 lines pass 120 characters, and almost all of them are single `new OA\Property(...)` arguments inside an OpenAPI attribute block. Splitting those across lines makes the specification harder to read, not easier.
- `PSR1.Files.SideEffects` for the bootstrap files — `config.php` defines constants and configures the session together, and `tests/bootstrap.php` defines functions and builds the test database. That is the definition of a bootstrap file, not a violation in it.

### The hooks are not skipped

**Never `--no-verify`.** A failing hook is repaired, not bypassed.

If gitleaks raises a false alarm, add an exception **by literal value rather than by path** in `.gitleaks.toml`, with a comment explaining why it is not a secret. A path exclusion blinds the scan to any real secret written into that file later.

A standing example: the RFC 6238 test vector in `tests/Unit/TotpTest.php` — a high-entropy string that looks exactly like a key, but is published in the standard, and whose whole value lies in being known to everyone.

---

## Tests

A test for every bug fix, **failing before the fix and passing after it**. A test that does not fail against the broken code guards nothing.

- `tests/Unit/` — no database, no network, fractions of a second.
- `tests/Integration/` — a separate `<DB_DATABASE>_test` database, with its tables emptied between every test.
- `tests/js/` — the browser files loaded into jsdom on the global scope, exactly as a `<script>` tag loads them.

Test **the contract, not the implementation**. For example: `Totp` is tested against the reference vectors of RFC 6238 rather than against itself — because a broken TOTP implementation stays perfectly consistent with itself (it generates a code and accepts it), so a "generate then verify" test passes over code that does not work with Google Authenticator at all.

---

## Code style

**A comment explains "why", not "what".** The code says what it does; the comment says what cannot be read from it: the option that was rejected, the trap we fell into, and the number that changed our minds.

```php
// ✗ increments the variable by one
$i++;

// ✓ Emptying with DELETE rather than TRUNCATE. The difference is measured:
//     TRUNCATE (28 tables): 8.585 s
//     DELETE   (28 tables): 0.256 s   ← 33 times faster
// because TRUNCATE in InnoDB drops the table's space and recreates it.
```

**Measure, do not estimate.** Any claim about performance or duplication carries its number.

**When you fix a fault, name it.** A comment saying "X used to be here and this is what it broke" prevents its return more than correct code does on its own.

**English throughout** — comments, strings, commit bodies and documentation. The repository was written in Arabic and converted; a new Arabic comment reintroduces the split. The one exception is test *data* that exists to exercise UTF-8 handling: `MailQueueTest` round-trips an Arabic subject, `ValidatorTest` measures a four-character eight-byte name, and `MigratorTest` builds a migration with a multi-byte comment. In each of those the non-ASCII text is the subject under test, not prose.

---

## Adding a route

In `public/index.php`:

```php
$r->post('/admin/things/delete', [AdminThingsController::class, 'delete'])
    ->middleware('perm:can_manage_things');
```

**Declare the guard on the route.** It runs before the controller is constructed, and it makes the policy readable in one table rather than across 24 controllers.

The checks inside the action bodies are still in place, and the duplication is deliberate and temporary. And `tests/Integration/RouteGuardParityTest.php` compares the two sides — any divergence fails the build.

The available guards: `auth` · `admin` · `perm:<permission_name>` · `throttle:<bucket>,<max>,<windowMinutes>`.

---

## Adding an API endpoint

Document it with an `#[OA\Get]` or `#[OA\Post]` attribute above the action, then:

```bash
composer docs:generate
```

and commit `public/docs/openapi.yaml` along with your change. The coverage test fails on any route without documentation, and CI fails if the file has fallen behind the attributes.

Use the shared components rather than describing bodies inline:

```php
new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied')
```

The schemas live in `app/config/openapi/schemas.php`, and the responses in `responses.php`.

---

## Changing the schema

```bash
php scripts/migrate.php make add_something   # creates the file with the next number
# … write the @UP and @DOWN sections …
php scripts/migrate.php up --pretend         # review what will run
composer migrate                             # apply
composer test:schema                         # update the reference schema
```

Commit the migration file and `tests/fixtures/schema.sql` **together**. Without the second, the integration tests fail against an old structure — and that is deliberate: a plain failure is better than a test passing over a schema that no longer exists.

**Always write the `@DOWN` section.** And if rolling back is impossible, write the reason plainly rather than leaving the section empty — emptiness reads as an oversight, a reason reads as a decision. A standing example: `0006_categories_dynamic` converts a column from `ENUM` to `VARCHAR`, and going back means losing every category an admin added afterwards.

**Do not edit a migration that has been applied.** The migrator stores each file's checksum and refuses to go forward if it changes — because an edit means your database and production's are different and both say "applied".
