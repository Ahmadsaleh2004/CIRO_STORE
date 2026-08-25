<?php

namespace App\Models;

use App\Core\Database;
use App\Models\AdminModel;
use Exception;

/**
 * OrderModel — يغطي جداول: orders, order_items, user_addresses
 */
class OrderModel
{
    // ════════════════════════════════════════════════════════
    // عناوين المستخدم
    // ════════════════════════════════════════════════════════

    /** جلب عناوين مستخدم */
    public static function getUserAddresses(int $userId): array
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare(
                "SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id ASC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getUserAddresses Error: " . $e->getMessage());
            return [];
        }
    }

    /** إضافة عنوان جديد */
    public static function addAddress(int $userId, array $data): ?int
    {
        try {
            $db = Database::connect();

            // إذا العنوان الجديد is_default → أزل الـ default من الباقي
            if (!empty($data['is_default'])) {
                $db->prepare("UPDATE user_addresses SET is_default=0 WHERE user_id=?")
                   ->execute([$userId]);
            }

            $stmt = $db->prepare(
                "INSERT INTO user_addresses (user_id, label, country, city, full_address, phone_number, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $data['label']        ?? 'Home',
                $data['country']      ?? null,
                $data['city']         ?? null,
                $data['full_address'],
                $data['phone_number'] ?? null,
                !empty($data['is_default']) ? 1 : 0,
            ]);
            return (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log("OrderModel::addAddress Error: " . $e->getMessage());
            return null;
        }
    }

    /** حذف عنوان */
    public static function deleteAddress(int $addressId, int $userId): bool
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare("DELETE FROM user_addresses WHERE id=? AND user_id=?");
            return $stmt->execute([$addressId, $userId]);
        } catch (Exception $e) {
            error_log("OrderModel::deleteAddress Error: " . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════
    // الطلبات
    // ════════════════════════════════════════════════════════

    /**
     * إنشاء طلب جديد مع تخفيض المخزون (Transaction)
     *
     * @param int    $userId
     * @param int    $addressId
     * @param array  $items       [['variant_id'=>x,'product_id'=>y,'qty'=>z,'price'=>w], ...]
     * @param string $paymentMethod
     * @param string $idempotencyKey
     * @return int|null  order_id في حال النجاح
     */
    public static function placeOrder(
        int    $userId,
        int    $addressId,
        array  $items,
        string $paymentMethod,
        string $idempotencyKey
    ): ?int {
        if (empty($items)) return null;

        $db = Database::connect();

        try {
            // فحص Idempotency — منع الطلب المكرر
            $dup = $db->prepare("SELECT order_id FROM orders WHERE idempotency_key=? LIMIT 1");
            $dup->execute([$idempotencyKey]);
            $existing = $dup->fetchColumn();
            if ($existing) return (int)$existing;

            $db->beginTransaction();

            // حساب الإجمالي
            $total = 0.0;
            foreach ($items as $item) {
                $total += (float)$item['price'] * (int)$item['qty'];
            }

            // إدراج الطلب
            $stmt = $db->prepare(
                "INSERT INTO orders (user_id, address_id, total_amount, payment_method,
                                    status, idempotency_key, created_at)
                 VALUES (?, ?, ?, ?, 'not_taken', ?, NOW())"
            );
            $stmt->execute([$userId, $addressId, $total, $paymentMethod, $idempotencyKey]);
            $orderId = (int)$db->lastInsertId();

            // فرز الـ Variants لمنع Deadlock
            usort($items, fn($a, $b) => ($a['variant_id'] ?? 0) <=> ($b['variant_id'] ?? 0));

            // إدراج عناصر الطلب + تخفيض المخزون
            $stmtItem = $db->prepare(
                "INSERT INTO order_items
                    (order_id, product_id, variant_id, color_name_snapshot, quantity, price_at_purchase)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmtStock = $db->prepare(
                "UPDATE product_variants SET stock_quantity = stock_quantity - ?
                 WHERE id = ? AND stock_quantity >= ?"
            );

            foreach ($items as $item) {
                $variantId     = (int)($item['variant_id']     ?? 0);
                $productId     = (int)($item['product_id']     ?? 0);
                $qty           = (int)($item['qty']             ?? 1);
                $price         = (float)$item['price'];
                $colorSnapshot = $item['color_name'] ?? null;

                $stmtItem->execute([$orderId, $productId, $variantId ?: null, $colorSnapshot, $qty, $price]);

                if ($variantId) {
                    $affected = $stmtStock->execute([$qty, $variantId, $qty]);
                    if (!$stmtStock->rowCount()) {
                        // مخزون غير كافٍ — تراجع
                        $db->rollBack();
                        return null;
                    }
                }
            }

            $db->commit();
            return $orderId;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::placeOrder Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * إلغاء طلب (من المستخدم) — حذف نهائي (Hard Delete).
     * فقط الطلبات بحالة not_taken: يُرجَع المخزون أولًا ثم يُحذف
     * صف الطلب مع عناصره وسجل انتهاء المهلة نهائيًا (لا يبقى بالـ DB).
     *
     * ملاحظات النطاق المتعمد:
     * - cancelAllPendingForUser() (كاسكيد الحظر) و adminCancelDelivery()
     *   يبقيان Soft Cancel (status='cancelled') — سجل التدقيق محفوظ.
     */
    public static function cancelOrder(int $orderId, int $userId): bool
    {
        $db = Database::connect();

        try {
            $db->beginTransaction();

            // تحقق من الطلب وملكيته
            $stmt = $db->prepare(
                "SELECT order_id, status, stock_restored
                 FROM orders WHERE order_id=? AND user_id=? LIMIT 1"
            );
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch();

            if (!$order || $order['status'] !== 'not_taken') {
                $db->rollBack();
                return false;
            }

            // إرجاع المخزون أولًا (قبل الحذف، بنفس منطق stock_restored الموجود أصلاً)
            if (!$order['stock_restored']) {
                $items = $db->prepare(
                    "SELECT variant_id, quantity FROM order_items WHERE order_id=? AND variant_id IS NOT NULL"
                );
                $items->execute([$orderId]);
                $restore = $db->prepare(
                    "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id=?"
                );
                foreach ($items->fetchAll() as $item) {
                    $restore->execute([$item['quantity'], $item['variant_id']]);
                }
            }

            // حذف نهائي — order_items و order_expiry_log يملكان FK يحيل لـ orders.order_id
            // (بإعداد ON DELETE CASCADE بالشيمات) لكن الحذف اليدوي الصريح ههنا
            // يضمن ترتيبًا واضحًا مستقلاً عن سلوك الـ DB.
            $db->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$orderId]);
            $db->prepare("DELETE FROM order_expiry_log WHERE order_id=?")->execute([$orderId]);
            $db->prepare("DELETE FROM orders WHERE order_id=?")->execute([$orderId]);

            $db->commit();
            return true;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::cancelOrder Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * جلب طلبات المستخدم مع العناصر
     */
    public static function getUserOrders(int $userId): array
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare(
                "SELECT o.*,
                        ua.label AS address_label, ua.city, ua.country, ua.full_address
                 FROM orders o
                 LEFT JOIN user_addresses ua ON ua.id = o.address_id
                 WHERE o.user_id = ?
                 ORDER BY o.created_at DESC"
            );
            $stmt->execute([$userId]);
            $orders = $stmt->fetchAll();

            // جلب العناصر لكل طلب
            $stmtItems = $db->prepare(
                "SELECT oi.*, p.name AS product_name, pv.color_name, pv.image_path
                 FROM order_items oi
                 JOIN products p ON p.id = oi.product_id
                 LEFT JOIN product_variants pv ON pv.id = oi.variant_id
                 WHERE oi.order_id = ?"
            );
            foreach ($orders as &$order) {
                $stmtItems->execute([$order['order_id']]);
                $order['items'] = $stmtItems->fetchAll();
            }

            return $orders;
        } catch (Exception $e) {
            error_log("OrderModel::getUserOrders Error: " . $e->getMessage());
            return [];
        }
    }

    // ════════════════════════════════════════════════════════
    // إدارة اليوزرز — لوحة الأدمن (Users 02/03/04)
    // ════════════════════════════════════════════════════════

    /**
     * كل طلبات يوزر مع عدد عناصر كل طلب، الأحدث أولًا.
     * يستخدمها UserModel::addStrike() وصفحة user-details لعرض السجل.
     */
    public static function getOrdersForUser(int $userId): array
    {
        try {
            $stmt = Database::connect()->prepare("
                SELECT o.*,
                       (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count
                FROM orders o
                WHERE o.user_id = ?
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getOrdersForUser Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * إلغاء كل طلبات يوزر المعلّقة (not_taken/taken) وإرجاع مخزونها بمعاملة واحدة.
     * يُستدعى تلقائيًا من UserModel::addStrike() عند الوصول لـ 3 إنذارات (حظر تلقائي).
     * يحترم عمود stock_restored لمنع الإرجاع المضاعف — نفس منطق cancelOrder().
     */
    public static function cancelAllPendingForUser(int $userId): void
    {
        $db = Database::connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                "SELECT order_id, status, stock_restored
                 FROM orders
                 WHERE user_id = ? AND status IN ('not_taken', 'taken')"
            );
            $stmt->execute([$userId]);
            $orders = $stmt->fetchAll();

            $updStatus    = $db->prepare("UPDATE orders SET status='cancelled' WHERE order_id=?");
            $items        = $db->prepare(
                "SELECT variant_id, quantity FROM order_items WHERE order_id=? AND variant_id IS NOT NULL"
            );
            $restore      = $db->prepare(
                "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id=?"
            );
            $markRestored = $db->prepare("UPDATE orders SET stock_restored=1 WHERE order_id=?");

            foreach ($orders as $order) {
                $updStatus->execute([$order['order_id']]);

                if (!$order['stock_restored']) {
                    $items->execute([$order['order_id']]);
                    foreach ($items->fetchAll() as $item) {
                        $restore->execute([$item['quantity'], $item['variant_id']]);
                    }
                    $markRestored->execute([$order['order_id']]);
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::cancelAllPendingForUser Error: " . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // إدارة الطلبات — لوحة الأدمن (Orders 02/03/04/05)
    // ════════════════════════════════════════════════════════

    /** تعليم كل الطلبات كمقروءة — يُستدعى من AdminOrdersController::index() بعد عرض القائمة */
    public static function markAllOrdersNotified(): void
    {
        try {
            Database::connect()->prepare("UPDATE orders SET is_notified=1")->execute();
        } catch (Exception $e) {
            error_log("OrderModel::markAllOrdersNotified Error: " . $e->getMessage());
        }
    }

    /**
     * إرجاع تلقائي (Lazy Check) للطلبات المأخوذة التي انتهت مهلة الـ 4 ساعات:
     * status='taken' ومرّ على taken_at أكثر من 4 ساعات → يُسجَّل بـ order_expiry_log
     * ثم يُعاد الطلب لـ not_taken مع تصفير taken_by_admin_id و taken_at.
     * يُستدعى في بداية كل طلب لصفحة Orders (القائمة أو التفاصيل) — بدون Cron Job.
     *
     * @return array<int, array{order_id:int, previous_admin_id:?int}> The orders that were
     *         just reverted by this call, so the caller can log/notify. Empty array if none.
     */
    public static function releaseExpiredTakenOrders(): array
    {
        $db = Database::connect();
        $reverted = [];
        try {
            $db->beginTransaction();

            // الحد يُحسب داخل MySQL (NOW()) — لا مقارنة بتوقيت PHP
            // (مناطق PHP/MySQL مختلفة أحيانًا، فتجنُّب الحساب بأنفسنا يضمن الدقة)
            $stmt = $db->prepare(
                "SELECT order_id, taken_by_admin_id, taken_at FROM orders
                 WHERE status='taken' AND taken_at < DATE_SUB(NOW(), INTERVAL 4 HOUR)"
            );
            $stmt->execute();
            $expired = $stmt->fetchAll();

            if ($expired) {
                $logStmt = $db->prepare(
                    "INSERT INTO order_expiry_log (order_id, previous_admin_id, taken_at)
                     VALUES (?, ?, ?)"
                );
                $updStmt = $db->prepare(
                    "UPDATE orders SET status='not_taken', taken_at=NULL, taken_by_admin_id=NULL
                     WHERE order_id=?"
                );
                foreach ($expired as $row) {
                    $logStmt->execute([$row['order_id'], $row['taken_by_admin_id'], $row['taken_at']]);
                    $updStmt->execute([$row['order_id']]);
                    $reverted[] = [
                        'order_id'          => (int)$row['order_id'],
                        'previous_admin_id' => $row['taken_by_admin_id'] !== null ? (int)$row['taken_by_admin_id'] : null,
                    ];
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::releaseExpiredTakenOrders Error: " . $e->getMessage());
            return [];
        }
        return $reverted;
    }

    /**
     * قائمة الطلبات لصفحة admin/orders مع فلترة + بحث + ترقيم صفحات.
     * ترتيب العرض: not_taken أولاً، ثم taken، ثم cancelled، ثم completed، والأحدث أولاً داخل كل مجموعة.
     *
     * @param array $filters ['status' => string, 'search' => string] (اختياريان)
     */
    public static function getAdminOrdersList(array $filters, int $page, int $perPage = 20): array
    {
        try {
            $db = Database::connect();

            $search = trim((string)($filters['search'] ?? ''));
            $status = $filters['status'] ?? '';

            $where  = [];
            $params = [];

            if ($status !== '' && in_array($status, ['not_taken', 'taken', 'cancelled', 'completed'], true)) {
                $where[]  = 'o.status = ?';
                $params[] = $status;
            }

            if ($search !== '') {
                if (is_numeric($search)) {
                    $where[]  = '(o.order_id = ? OR u.full_name LIKE ? OR u.email LIKE ?)';
                    $params[] = (int)$search;
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                } else {
                    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                }
            }

            $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM orders o
                 JOIN users u ON u.id = o.user_id
                 {$whereClause}"
            );
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();

            $page      = max(1, $page);
            $offset    = ($page - 1) * $perPage;
            $totalPages = max(1, (int)ceil($total / $perPage));

            $stmt = $db->prepare(
                "SELECT o.*, u.full_name, u.email, ta.full_name AS handled_by_name
                 FROM orders o
                 JOIN users u ON u.id = o.user_id
                 LEFT JOIN admins ta ON ta.id = o.taken_by_admin_id
                 {$whereClause}
                 ORDER BY
                   CASE o.status
                     WHEN 'not_taken' THEN 1
                     WHEN 'taken'     THEN 2
                     WHEN 'cancelled' THEN 3
                     WHEN 'completed' THEN 4
                     ELSE 5
                   END ASC,
                   o.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute(array_merge($params, [(int)$perPage, (int)$offset]));

            return [
                'orders'     => $stmt->fetchAll(),
                'total'      => $total,
                'totalPages' => $totalPages,
            ];
        } catch (Exception $e) {
            error_log("OrderModel::getAdminOrdersList Error: " . $e->getMessage());
            return ['orders' => [], 'total' => 0, 'totalPages' => 1];
        }
    }

    /**
     * كل بيانات طلب واحد لصفحة تفاصيل الطلب (admin/orders/details)
     */
    public static function getAdminOrderDetails(int $orderId): ?array
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT o.*,
                        u.full_name AS user_name, u.email AS user_email, u.phone_number AS user_phone,
                        ua.full_address, ua.country, ua.city,
                        ua.phone_number AS shipping_phone, ua.label AS address_label,
                        ta.full_name AS handler_admin_name
                 FROM orders o
                 JOIN users u ON u.id = o.user_id
                 LEFT JOIN user_addresses ua ON ua.id = o.address_id
                 LEFT JOIN admins ta ON ta.id = o.taken_by_admin_id
                 WHERE o.order_id = ? LIMIT 1"
            );
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            return $order ?: null;
        } catch (Exception $e) {
            error_log("OrderModel::getAdminOrderDetails Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * عناصر طلب واحد مع المنتج واللون (variant) — نفس JOIN المستخدم بـ getUserOrders()
     * لضمان عرض صورة/اسم اللون الصحيح بدل صورة المنتج العامة فقط.
     * name اللون من color_name_snapshot (وقت الشراء) مع fallback للحالي pv.color_name.
     */
    public static function getOrderItemsWithProduct(int $orderId): array
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT oi.*, p.name AS product_name,
                        COALESCE(pv.image_path, p.image_path) AS image_path,
                        COALESCE(oi.color_name_snapshot, pv.color_name) AS color_name
                 FROM order_items oi
                 JOIN products p ON p.id = oi.product_id
                 LEFT JOIN product_variants pv ON pv.id = oi.variant_id
                 WHERE oi.order_id = ?"
            );
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getOrderItemsWithProduct Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * أخذ طلب من قائمة not_taken (تعيين الحالة taken للأدمن الحالي).
     *
     * @return array ['success'=>bool, 'message'=>string, 'targetUserId'=>int|null]
     */
    public static function adminTakeOrder(int $orderId, int $adminId): array
    {
        $db = Database::connect();
        try {
            $stmt = $db->prepare("SELECT status, user_id FROM orders WHERE order_id=? LIMIT 1");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Order not found.', 'targetUserId' => null];
            }

            if ($order['status'] !== 'not_taken') {
                return ['success' => false, 'message' => 'Cannot take this order — invalid status.', 'targetUserId' => null];
            }

            $db->prepare(
                "UPDATE orders SET status='taken', taken_at=NOW(), taken_by_admin_id=? WHERE order_id=?"
            )->execute([$adminId, $orderId]);

            return ['success' => true, 'message' => 'Order taken successfully.', 'targetUserId' => (int)$order['user_id']];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::adminTakeOrder Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Something went wrong.', 'targetUserId' => null];
        }
    }

    /**
     * إنهاء تسليم طلب (completed) مع تسجيل الأدمن كمنفّذ العملية لو كان الحقل فارغًا.
     *
     * @return array ['success'=>bool, 'message'=>string, 'targetUserId'=>int|null]
     */
    public static function adminMarkDelivered(int $orderId, int $adminId): array
    {
        $db = Database::connect();
        try {
            $stmt = $db->prepare("SELECT user_id FROM orders WHERE order_id=? LIMIT 1");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Order not found.', 'targetUserId' => null];
            }

            $db->prepare(
                "UPDATE orders SET status='completed', taken_by_admin_id=COALESCE(taken_by_admin_id, ?)
                 WHERE order_id=?"
            )->execute([$adminId, $orderId]);

            return ['success' => true, 'message' => 'Order marked as delivered.', 'targetUserId' => (int)$order['user_id']];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::adminMarkDelivered Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Something went wrong.', 'targetUserId' => null];
        }
    }

    /**
     * إلغاء تسليم طلب (cancelled) مع إرجاع مخزون الألوان (variants) بشكل صحيح،
     * يحترم عمود stock_restored لمنع الإرجاع المضاعف — نفس منطق cancelOrder().
     *
     * @return array ['success'=>bool, 'message'=>string, 'targetUserId'=>int|null]
     */
    public static function adminCancelDelivery(int $orderId, int $adminId): array
    {
        $db = Database::connect();
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT user_id, stock_restored FROM orders WHERE order_id=? LIMIT 1");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Order not found.', 'targetUserId' => null];
            }

            if (!$order['stock_restored']) {
                $items = $db->prepare(
                    "SELECT variant_id, quantity FROM order_items WHERE order_id=? AND variant_id IS NOT NULL"
                );
                $items->execute([$orderId]);
                $restore = $db->prepare(
                    "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id=?"
                );
                foreach ($items->fetchAll() as $item) {
                    $restore->execute([$item['quantity'], $item['variant_id']]);
                }
            }

            $db->prepare(
                "UPDATE orders SET status='cancelled', stock_restored=1,
                 taken_by_admin_id=COALESCE(taken_by_admin_id, ?) WHERE order_id=?"
            )->execute([$adminId, $orderId]);

            $db->commit();
            return ['success' => true, 'message' => 'Delivery cancelled.', 'targetUserId' => (int)$order['user_id']];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::adminCancelDelivery Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Something went wrong while cancelling.', 'targetUserId' => null];
        }
    }

    /**
     * حذف نهائي (Hard Delete) لطلب مكتمل أو مُلغى (status='completed' أو 'cancelled')
     * من لوحة الأدمن. يرفض أي طلب بغير هاتين الحالتين — يحفظ سجل التدقيق
     * للطلبات المُلْغاة عبر cancelAllPendingForUser() / adminCancelDelivery() (Soft Cancel).
     *
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function adminDeleteOrder(int $orderId): array
    {
        $db = Database::connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT status FROM orders WHERE order_id=? LIMIT 1");
            $stmt->execute([$orderId]);
            $status = $stmt->fetchColumn();

            if ($status === false) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Order not found.'];
            }
            if (!in_array($status, ['completed', 'cancelled'], true)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Only completed or cancelled orders can be deleted permanently.'];
            }

            // حذف نهائي — نفس ترتيب الحذف اليدوي الصريح المستخدم بـ cancelOrder()
            $db->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$orderId]);
            $db->prepare("DELETE FROM order_expiry_log WHERE order_id=?")->execute([$orderId]);
            $db->prepare("DELETE FROM orders WHERE order_id=?")->execute([$orderId]);

            $db->commit();
            return ['success' => true, 'message' => "Order #{$orderId} has been permanently deleted."];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::adminDeleteOrder Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Something went wrong.'];
        }
    }

    /**
     * Voluntary release: an admin who currently holds a 'taken' order gives it back.
     * Order returns to 'not_taken', taken_at and taken_by_admin_id are cleared.
     * Only succeeds if $adminId is the CURRENT holder of the order (enforced here,
     * not just at the controller/UI level, to prevent any admin from releasing
     * an order someone else is holding by crafting the request directly).
     *
     * @return array ['success'=>bool, 'message'=>string, 'targetUserId'=>?int]
     */
    public static function adminReleaseOrder(int $orderId, int $adminId): array
    {
        $db = Database::connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT status, taken_by_admin_id, user_id FROM orders WHERE order_id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$orderId]);
            $row = $stmt->fetch();

            if (!$row) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Order not found.', 'targetUserId' => null];
            }
            if ($row['status'] !== 'taken') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Only a taken order can be released.', 'targetUserId' => null];
            }
            if ((int)$row['taken_by_admin_id'] !== $adminId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You can only release an order you currently hold.', 'targetUserId' => null];
            }

            $db->prepare(
                "UPDATE orders SET status='not_taken', taken_at=NULL, taken_by_admin_id=NULL WHERE order_id=?"
            )->execute([$orderId]);

            $db->commit();
            return ['success' => true, 'message' => "Order #{$orderId} has been released back to Not Taken.", 'targetUserId' => (int)$row['user_id']];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("OrderModel::adminReleaseOrder Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Something went wrong.', 'targetUserId' => null];
        }
    }

    /**
     * مساعدة: user_id لطلب معيّن أو null — تُستخدم بعملية report_issue
     * (لا تُعدّل حالة الطلب، فقط إشعار للمستخدم + audit log).
     */
    public static function getOrderUserId(int $orderId): ?int
    {
        try {
            $stmt = Database::connect()->prepare("SELECT user_id FROM orders WHERE order_id=? LIMIT 1");
            $stmt->execute([$orderId]);
            $userId = $stmt->fetchColumn();
            return $userId !== false ? (int)$userId : null;
        } catch (Exception $e) {
            error_log("OrderModel::getOrderUserId Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * كل الطلبات المطابقة للفلترة (بدون pagination) لتصدير CSV — نفس منطق getAdminOrdersList().
     */
    public static function getAllForCsvExport(array $filters): array
    {
        try {
            $db = Database::connect();

            $search = trim((string)($filters['search'] ?? ''));
            $status = $filters['status'] ?? '';

            $where  = [];
            $params = [];

            if ($status !== '' && in_array($status, ['not_taken', 'taken', 'cancelled', 'completed'], true)) {
                $where[]  = 'o.status = ?';
                $params[] = $status;
            }

            if ($search !== '') {
                if (is_numeric($search)) {
                    $where[]  = '(o.order_id = ? OR u.full_name LIKE ? OR u.email LIKE ?)';
                    $params[] = (int)$search;
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                } else {
                    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                }
            }

            $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt = $db->prepare(
                "SELECT o.order_id, u.full_name, u.email, o.total_amount, o.payment_method,
                        o.status, ta.full_name AS handled_by_name, o.created_at
                 FROM orders o
                 JOIN users u ON u.id = o.user_id
                 LEFT JOIN admins ta ON ta.id = o.taken_by_admin_id
                 {$whereClause}
                 ORDER BY
                   CASE o.status
                     WHEN 'not_taken' THEN 1
                     WHEN 'taken'     THEN 2
                     WHEN 'cancelled' THEN 3
                     WHEN 'completed' THEN 4
                     ELSE 5
                   END ASC,
                   o.created_at DESC"
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getAllForCsvExport Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * طلبات تولّاها أدمن معيّن — الأحدث أولًا. يشمل:
     *   (أ) الطلبات التي حالتها الحالية taken_by_admin_id = $adminId (كما كان سابقاً)
     *   (ب) طلبات سابقة انتهت مهلتها وأُرجعت تلقائياً بينما كان هذا الأدمن يحملها
     *       (عبر order_expiry_log.previous_admin_id) — تُعلَّم بـ was_auto_released=1
     *       لأن حالتها الحالية بالجدول orders قد تكون تغيّرت منذ ذلك الحين (أخذها أدمن آخر
     *       لاحقاً مثلاً)، فلا يصح عرض بادج الحالة العادي لها.
     * تُستخدم بصفحة تفاصيل الأدمن (manage-admins/details).
     */
    public static function getOrdersHandledByAdmin(int $adminId, int $limit = 50): array
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT order_id, status, total_amount, created_at, 0 AS was_auto_released
                 FROM orders
                 WHERE taken_by_admin_id = ?

                 UNION

                 SELECT o.order_id, o.status, o.total_amount, o.created_at, 1 AS was_auto_released
                 FROM order_expiry_log el
                 JOIN orders o ON o.order_id = el.order_id
                 WHERE el.previous_admin_id = ?
                   AND (o.taken_by_admin_id IS NULL OR o.taken_by_admin_id != ?)

                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$adminId, $adminId, $adminId, (int)$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("OrderModel::getOrdersHandledByAdmin Error: " . $e->getMessage());
            return [];
        }
    }
}
