<?php

namespace Tests\Integration;

use App\Models\AdminModel;
use App\Models\CategoryModel;
use Tests\Support\DatabaseTestCase;

/**
 * "It deleted nothing and said it succeeded" — the same contract as
 * AdminProductModelDeleteTest, for the three places that still carried the fault.
 *
 * `DELETE ... WHERE id = ?` against an id that does not exist is not an error in SQL. The
 * statement succeeds and removes zero rows, so a method that returns true straight after
 * it reports a deletion that never took place. AdminProductModel::delete was fixed for
 * exactly this and given the test beside this one; the local semgrep rule
 * `cairo-execute-then-return-true` was written to find the rest.
 *
 * It found them once it was run with the version CI pins rather than whatever happened to
 * be on the machine: AdminModel::deleteAdmin, CategoryModel::deleteAndReassign and
 * OrderModel::cancelOrder. The first two are covered here.
 *
 * ⚠️ These tests fail against the version before the fix — they are not decoration. Each
 * one passed as `assertTrue` on the old code, which is precisely the bug: the old code
 * returned true.
 *
 * OrderModel::cancelOrder is not covered here because reproducing its case needs two
 * concurrent transactions racing on the same order — its non-locking SELECT is what makes
 * the row disappear underneath it — and the fixture harness runs a single connection. The
 * reasoning is recorded in the comment on the fix instead.
 */
final class DeleteRowCountContractTest extends DatabaseTestCase
{
    private function insertAdmin(string $email = 'someone@example.com'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admins (full_name, email, password, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['Test Admin', $email, 'not-a-real-hash', 'B']);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertCategory(string $name, int $isCore = 0): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categories (name, is_core) VALUES (?, ?)');
        $stmt->execute([$name, $isCore]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── AdminModel::deleteAdmin ──────────────────────────────

    public function testDeletingAnExistingAdminReturnsTrueAndRemovesTheRow(): void
    {
        $id = $this->insertAdmin();
        $this->assertSame(1, $this->countRows('admins'));

        $this->assertTrue(AdminModel::deleteAdmin($id));
        $this->assertSame(0, $this->countRows('admins'));
    }

    /**
     * The case the contract exists for. It is not hypothetical: the controller does not
     * merely return this value to the browser, it writes an audit row reading
     * "Deleted: <email>" and sends a notification on the strength of it.
     */
    public function testDeletingAMissingAdminReturnsFalseNotTrue(): void
    {
        $this->assertSame(0, $this->countRows('admins'));

        $this->assertFalse(AdminModel::deleteAdmin(999999));
    }

    public function testDeletingAMissingAdminLeavesTheOtherRowsAlone(): void
    {
        $keep = $this->insertAdmin('keep@example.com');

        $this->assertFalse(AdminModel::deleteAdmin($keep + 5000));
        $this->assertSame(1, $this->countRows('admins'));
    }

    // ── CategoryModel::deleteAndReassign ─────────────────────

    public function testReassigningAndDeletingAnExistingCategoryReturnsTrue(): void
    {
        $source      = $this->insertCategory('Source');
        $destination = $this->insertCategory('Destination');
        $this->assertSame(2, $this->countRows('categories'));

        $this->assertTrue(CategoryModel::deleteAndReassign($source, $destination));
        $this->assertSame(1, $this->countRows('categories'));
    }

    /**
     * The source category never existed. The old code moved nothing, deleted nothing and
     * returned true, so the admin was told the category had been removed while it went on
     * appearing in the list.
     */
    public function testDeletingAMissingCategoryReturnsFalseNotTrue(): void
    {
        $destination = $this->insertCategory('Destination');

        $this->assertFalse(CategoryModel::deleteAndReassign($destination + 5000, $destination));
        $this->assertSame(1, $this->countRows('categories'));
    }

    /**
     * A core category is refused before the transaction opens, by isCore(). This asserts
     * the refusal still holds after the rowCount check was added — the two guards are
     * independent, and the second must not be what the first relies on.
     */
    public function testDeletingACoreCategoryIsStillRefused(): void
    {
        $core        = $this->insertCategory('Core', 1);
        $destination = $this->insertCategory('Destination');

        $this->assertFalse(CategoryModel::deleteAndReassign($core, $destination));
        $this->assertSame(2, $this->countRows('categories'));
    }
}
