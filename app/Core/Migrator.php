<?php

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Migrator — applying schema changes in order, with tracking and rollback.
 *
 * What came before it: seven .sql files under database/migrations run **by hand**,
 * with their ordering written only in their comments ("depends on admin_auth.sql").
 * Nothing knew which had been applied, nothing stopped one being run twice, and
 * there was no way back.
 *
 * ── The model: a baseline plus changes ─────────────────────
 *
 * The seven existing migrations **do not build the database from nothing**: every
 * one of them depends on tables (users, products, orders, categories) that appear in
 * none of them. Which is to say the real schema was born before them and grew
 * through them.
 *
 * So rather than pretend otherwise, the model here is explicit:
 *
 *   tests/fixtures/schema.sql   → the baseline, today's complete schema
 *   database/migrations/*.sql   → changes, most of them **already in the baseline**
 *
 * Which is why `baseline` exists: it records the existing migrations as applied
 * without running them, because the baseline already contains them. Running them
 * against it fails with "table already exists".
 *
 * ── The checksum ───────────────────────────────────────────
 *
 * A sha256 is stored for every applied migration. Editing a file that has already
 * been applied is a silent fault of the worst kind: the developer's database holds
 * the old version and production holds the new one, and both report "applied". The
 * migrator surfaces that rather than waiting for it to detonate.
 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory
    ) {
    }

    /**
     * Creates the tracking table if it is missing.
     *
     * `IF NOT EXISTS` is deliberate: the migrator is called often and this is the
     * first thing it does, so it must be a no-op when repeated.
     */
    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . '`version` VARCHAR(20) NOT NULL,'
            . '`name` VARCHAR(190) NOT NULL,'
            . '`checksum` CHAR(64) NOT NULL,'
            . '`applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`version`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Every migration file on disk, ordered by version.
     *
     * The ordering comes from the file name (`0001_admin_auth.sql`), not from the
     * modification date and not from the file system's order — both of those differ
     * between machines, and migration order cannot tolerate that.
     *
     * @return array<string, array{version: string, name: string, path: string}>
     */
    public function available(): array
    {
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        $out = [];

        foreach ($files as $path) {
            $base = basename($path, '.sql');

            if (!preg_match('/^(\d{4})_(.+)$/', $base, $m)) {
                throw new RuntimeException(
                    "Migration file with no version number: {$base}.sql — the required form is NNNN_name.sql"
                );
            }

            if (isset($out[$m[1]])) {
                throw new RuntimeException("Duplicate version number: {$m[1]}");
            }

            $out[$m[1]] = ['version' => $m[1], 'name' => $m[2], 'path' => $path];
        }

        ksort($out);

        return $out;
    }

    /**
     * The migrations recorded as applied.
     *
     * @return array<string, array{version: string, name: string, checksum: string, applied_at: string}>
     */
    public function applied(): array
    {
        $this->ensureTable();

        $rows = $this->pdo
            ->query('SELECT `version`, `name`, `checksum`, `applied_at` FROM `' . self::TABLE . '` ORDER BY `version`')
            ->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $out[$row['version']] = $row;
        }

        return $out;
    }

    /** @return list<array{version: string, name: string, path: string}> */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->available(),
            static fn (array $m): bool => !isset($applied[$m['version']])
        ));
    }

    /**
     * Migrations that were applied and whose file changed afterwards.
     *
     * @return list<string>
     */
    public function drifted(): array
    {
        $available = $this->available();
        $out = [];

        foreach ($this->applied() as $version => $row) {
            if (!isset($available[$version])) {
                $out[] = "{$version} — recorded as applied, with no file on disk.";
                continue;
            }

            $current = $this->checksum($available[$version]['path']);
            if ($current !== $row['checksum']) {
                $out[] = "{$version}_{$row['name']} — the file changed after it was applied.";
            }
        }

        return $out;
    }

    /**
     * Records every existing migration as applied **without running it**.
     *
     * Used once, on a database built from the baseline: the baseline already carries
     * the effect of these migrations, and running them against it fails with "table
     * already exists".
     *
     * @return int How many were recorded
     */
    public function baseline(): int
    {
        $this->ensureTable();
        $applied = $this->applied();
        $count = 0;

        foreach ($this->available() as $migration) {
            if (isset($applied[$migration['version']])) {
                continue;
            }

            $this->record($migration);
            $count++;
        }

        return $count;
    }

    /**
     * Runs the pending migrations.
     *
     * Each migration inside its own transaction: a failure in the seventh does not
     * roll back the sixth.
     *
     * ⚠️ MySQL does not support DDL inside a transaction — `CREATE TABLE` implicitly
     * commits the open transaction and cannot be rolled back. The transaction here
     * protects the DML (moving data in categories_dynamic, for instance) and
     * guarantees the tracking row is written only after the whole script succeeds.
     * Undoing DDL is the responsibility of the @DOWN section alone.
     *
     * @param bool $pretend Prints what would run, without running it
     * @return list<string> The names of what was applied
     */
    public function up(bool $pretend = false): array
    {
        $drift = $this->drifted();
        if ($drift !== []) {
            throw new RuntimeException(
                "Applied migrations whose files have changed — stop before the databases drift apart:\n  "
                . implode("\n  ", $drift)
            );
        }

        $done = [];

        foreach ($this->pending() as $migration) {
            $sql = $this->section($migration['path'], 'UP');

            if ($sql === '') {
                throw new RuntimeException(
                    "Migration {$migration['version']}_{$migration['name']} has no @UP section."
                );
            }

            if ($pretend) {
                $done[] = $migration['version'] . '_' . $migration['name'];
                continue;
            }

            $this->pdo->exec($sql);
            $this->record($migration);

            $done[] = $migration['version'] . '_' . $migration['name'];
        }

        return $done;
    }

    /**
     * Rolls back the last migration, or more.
     *
     * @param int $steps How many migrations to roll back, newest first
     * @return list<string>
     */
    public function down(int $steps = 1, bool $pretend = false): array
    {
        $available = $this->available();
        $applied   = $this->applied();
        krsort($applied);

        $done = [];

        foreach ($applied as $version => $row) {
            if (count($done) >= $steps) {
                break;
            }

            if (!isset($available[$version])) {
                throw new RuntimeException("No file for migration {$version} — rolling back is impossible.");
            }

            $sql = $this->section($available[$version]['path'], 'DOWN');

            if ($sql === '') {
                throw new RuntimeException(
                    "Migration {$version}_{$row['name']} has no @DOWN section — it cannot be rolled back."
                );
            }

            if (!$pretend) {
                $this->pdo->exec($sql);
                $this->pdo
                    ->prepare('DELETE FROM `' . self::TABLE . '` WHERE `version` = ?')
                    ->execute([$version]);
            }

            $done[] = $version . '_' . $row['name'];
        }

        return $done;
    }

    /**
     * Extracts the @UP or @DOWN section from a migration file.
     *
     * The form is a single comment line: `-- @UP` and `-- @DOWN`. A comment was chosen
     * because it keeps the file valid SQL that can be pasted into any database client
     * as-is — and it does not need the migrator in order to be read.
     */
    public function section(string $path, string $name): string
    {
        $content = (string) file_get_contents($path);

        // ⚠️ `\R` is forbidden here — the reason is already written out in
        // scripts/audit.php (splitLines), but this site was missed. Without the /u
        // modifier, `\R` operates on bytes and matches `\x85`, which is a legitimate
        // continuation byte inside Arabic letters in UTF-8 — the commonest being the
        // letter meem (D9 85).
        //
        // The consequence here was graver than in the line counter: every Arabic
        // comment line was cut in the middle of a letter, so its remainder became lines
        // not starting with `--` — that is, raw Arabic text handed to PDO::exec as if it
        // were SQL. The result was that **every** migration in this project failed with
        // a syntax error, all eight of them (measured: 0001 swelled from 112 lines to
        // 149).
        //
        // The fault stayed hidden because the first seven migrations were recorded with
        // `baseline` — which records without running — so no file had ever passed
        // through up() until now.
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        $collecting = false;
        $out = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*--\s*@(UP|DOWN)\s*$/i', $line, $m)) {
                $collecting = strtoupper($m[1]) === strtoupper($name);
                continue;
            }

            if ($collecting) {
                $out[] = $line;
            }
        }

        return trim(implode("\n", $out));
    }

    public function checksum(string $path): string
    {
        // Line endings are normalised before hashing: .gitattributes checks out CRLF on
        // Windows and LF elsewhere, so a raw checksum would differ between two machines
        // for the same file with identical content — a false drift alarm every time.
        $content = (string) file_get_contents($path);

        return hash('sha256', str_replace("\r\n", "\n", $content));
    }

    /** @param array{version: string, name: string, path: string} $migration */
    private function record(array $migration): void
    {
        $this->pdo
            ->prepare(
                'INSERT INTO `' . self::TABLE . '` (`version`, `name`, `checksum`) VALUES (?, ?, ?)'
            )
            ->execute([
                $migration['version'],
                $migration['name'],
                $this->checksum($migration['path']),
            ]);
    }
}
