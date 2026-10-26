<?php

namespace Tests\Integration;

use App\Models\NotificationModel;
use Tests\Support\DatabaseTestCase;

/**
 * A dismissed notification stays dismissed.
 *
 * Dismissing used to mark the notification read, and the bell lists read notifications too —
 * so it came back on the next poll, a minute after the user had removed it. It is deleted
 * now, and only the owner's.
 */
final class NotificationDismissTest extends DatabaseTestCase
{
    private function user(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute(['Bell User', 'bell' . uniqid() . '@example.com']);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<int> */
    private function idsFor(int $userId): array
    {
        return array_map(static fn (array $n): int => (int) $n['id'], NotificationModel::getList($userId) ?? []);
    }

    public function testADismissedNotificationIsGoneFromTheNextList(): void
    {
        $userId = $this->user();
        NotificationModel::insert($userId, 'Keep', 'stays');
        NotificationModel::insert($userId, 'Remove', 'goes');

        // By title, not position: both are created within the same second, and the list is
        // ordered by created_at alone.
        $byTitle = array_column(NotificationModel::getList($userId) ?? [], 'id', 'title');

        $this->assertTrue(NotificationModel::dismiss((int) $byTitle['Remove'], $userId));

        $this->assertSame([(int) $byTitle['Keep']], $this->idsFor($userId));
    }

    public function testAnotherUsersNotificationCannotBeDismissed(): void
    {
        $owner    = $this->user();
        $stranger = $this->user();
        NotificationModel::insert($owner, 'Mine', 'private');
        [$id] = $this->idsFor($owner);

        NotificationModel::dismiss($id, $stranger);

        $this->assertSame([$id], $this->idsFor($owner));
    }
}
