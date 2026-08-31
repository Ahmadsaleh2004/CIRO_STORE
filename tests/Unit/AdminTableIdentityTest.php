<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The admin tables — the identity on display, and the column count's integrity.
 *
 * The project's rule: a primary key is an identity that stays with its row for its whole
 * life and is never renumbered. Its other face in the interface: a table showing a "number"
 * for an entity must show that entity's real id rather than its row's position.
 *
 * The users table printed `$startNum + $i` — a pagination counter that shifts on every
 * deletion, so a user's "number" changed without the user changing. And the products table
 * did not show the identity at all: it lived in id="product-row-N", so the JavaScript saw it
 * and the admin did not. The admins and orders tables, meanwhile, printed the real id
 * already — that is, four similar tables behaved in three different ways.
 */
final class AdminTableIdentityTest extends TestCase
{
    private static function viewsDir(): string
    {
        return dirname(__DIR__, 2) . '/app/views/admin';
    }

    /**
     * The entity tables display the real id.
     *
     * @return array<string, array{string, string}>
     */
    public static function entityTables(): array
    {
        return [
            'users'    => ['users/index.php',          "\$u['id']"],
            'products' => ['product/index.php',        "\$p['id']"],
            'admins'   => ['manage-admins/index.php',  "\$adm['id']"],
            'orders'   => ['orders/index.php',         "\$o['order_id']"],
        ];
    }

    /**
     * @param string $file    the view's path relative to app/views/admin
     * @param string $idExpr  the expected id expression inside a cell
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entityTables')]
    public function testTheTablePrintsTheRealIdInACell(string $file, string $idExpr): void
    {
        $src = (string) file_get_contents(self::viewsDir() . '/' . $file);

        // An explanatory comment may sit between the <td> and the id, or a short prefix
        // such as "#" (the orders table writes `#<?= …`) — both are formatting and neither
        // changes the fact that what is printed is the real id.
        $pattern = '/<td[^>]*>\s*(?:<\?php.*?\?>\s*)?[^<]{0,4}<\?=\s*\(int\)'
                 . preg_quote($idExpr, '/') . '/s';

        $this->assertMatchesRegularExpression(
            $pattern,
            $src,
            "{$file} does not print the real id in a cell — the identity on display must not be a row's position."
        );
    }

    /**
     * No row counter posing as an identity.
     *
     * `$startNum + $i` and its like produce a number that changes when any row before it is
     * deleted — so the admin believes they are pointing at an entity while pointing at a
     * position.
     */
    public function testNoAdminTableUsesARowCounterAsIdentity(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::viewsDir(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // A pagination counter printed as it is inside a cell.
            if (preg_match('/<td[^>]*>\s*<\?=\s*\$startNum\s*\+/', $src)) {
                $offenders[] = $file->getFilename() . ' — it prints $startNum + $i as an identity.';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A row counter posing as an identity:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * $emptyColspan really does match the table's column count.
     *
     * The "no results" row spans the table with a hand-written colspan. And adding a column
     * without updating it — which is what adding the id column to the products table did —
     * leaves the row shorter than the table, so its shape breaks silently in the one state
     * nobody opens during development: when there is no data at all.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entityTables')]
    public function testEmptyRowColspanMatchesTheHeaderCount(string $file): void
    {
        $src = (string) file_get_contents(self::viewsDir() . '/' . $file);

        if (!preg_match('/\$emptyColspan\s*=\s*(\d+)/', $src, $m)) {
            $this->markTestSkipped("{$file} does not use the shared empty-table row.");
        }

        $declared = (int) $m[1];
        $headers  = preg_match_all('/<th[\s>]/', $src);

        $this->assertSame(
            $headers,
            $declared,
            "{$file}: the table has {$headers} columns and \$emptyColspan = {$declared}."
        );
    }
}
