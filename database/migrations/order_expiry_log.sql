-- ════════════════════════════════════════════════════════════════════════
-- Migration: Order Expiry Log — سجل الطلبات التي رجعت تلقائياً لـ not_taken
-- بعد انتهاء مهلة الـ 3 ساعات دون تسليم (يُستخدم من OrderModel::releaseExpiredTakenOrders())
-- يعتمد على جدولين: orders + admins
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `order_expiry_log` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`          INT UNSIGNED NOT NULL,
    `previous_admin_id` INT UNSIGNED DEFAULT NULL COMMENT 'الأدمن الذي كان قد أخذ الطلب قبل انتهاء المهلة',
    `taken_at`          DATETIME NOT NULL COMMENT 'وقت أخذ الطلب الأصلي',
    `reverted_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'وقت الإرجاع التلقائي',
    PRIMARY KEY (`id`),
    INDEX `idx_order` (`order_id`),
    INDEX `idx_reverted` (`reverted_at`),
    CONSTRAINT `fk_expirylog_order`  FOREIGN KEY (`order_id`)          REFERENCES `orders`(`order_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_expirylog_admin`  FOREIGN KEY (`previous_admin_id`) REFERENCES `admins`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;