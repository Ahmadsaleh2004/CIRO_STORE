-- ══════════════════════════════════════════════════════════════
-- Cairo Store — demo data for a public deployment
-- ══════════════════════════════════════════════════════════════
--
-- tests/fixtures/schema.sql builds the structure and nothing else: it carries zero INSERT
-- statements. That is correct for a test database and wrong for a demo — a fresh deploy
-- from it alone shows a store with no products, no categories and no way to reach the
-- admin panel, which is worse than showing nothing.
--
-- This file fills that gap, and its value is in what it leaves out. A plain dump of a
-- working database would publish real customer accounts, addresses, orders, contact
-- messages, login attempts and the owner's own phone number. So only two kinds of row are
-- here: the catalog (products, variants, categories, sliders) and the presentation
-- settings, with every contact field replaced by an obvious placeholder.
--
-- Excluded deliberately: users, user_addresses, user_strikes, orders, order_items,
-- cart_items, product_reviews, contact_messages, notifications, admin_notifications,
-- admin_audit_log, every *_attempts and *_log table, email_verifications,
-- password_resets, mail_queue, rate_limits, stock_notifications.
--
-- ── How to use it ────────────────────────────────────────────
--
--     mysql -u USER -p DBNAME < tests/fixtures/schema.sql
--     mysql -u USER -p DBNAME < database/demo-seed.sql
--     php scripts/migrate.php baseline
--
-- With Docker the schema loads by itself on the first run, so only the second and third
-- lines are needed:
--
--     docker compose exec -T db mysql -ucairo -pcairo ciro_db < database/demo-seed.sql
--     docker compose exec app php scripts/migrate.php baseline
--
-- ⚠️ The demo administrator below has **no usable password**. The placeholder is not a
-- valid bcrypt hash, so password_verify() rejects every attempt and the account is locked
-- until you set one yourself. That is deliberate: a working password committed to a public
-- repository is a working password for everyone who reads it. Generate your own and run
-- the UPDATE printed underneath.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── The demo administrator ───────────────────────────────────
--
-- Role 'A' is the top rank; admin_permissions grants it everything so the whole panel is
-- reachable in the demo. Set a real hash before deploying:
--
--     php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
--     UPDATE admins SET password = '<the printed hash>' WHERE id = 1;

INSERT INTO `admins` (`id`, `full_name`, `email`, `password`, `totp_enabled`, `role`, `created_at`)
VALUES (1, 'Demo Administrator', 'admin@example.com', '!SET-A-HASH-BEFORE-USING!', 0, 'A', NOW());

INSERT INTO `admin_permissions` (`id`, `admin_id`, `can_manage_admins`, `can_manage_products`, `can_manage_users`, `can_view_dashboard`, `can_manage_support`, `can_edit_site_content`, `can_manage_branding`, `can_manage_checkout_settings`, `can_manage_orders`, `can_manage_warnings`)
VALUES (1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1);

-- categories (4 rows)
INSERT INTO `categories` (`id`, `name`, `is_core`) VALUES ('1', 'accessories', '1');
INSERT INTO `categories` (`id`, `name`, `is_core`) VALUES ('2', 'phone', '1');
INSERT INTO `categories` (`id`, `name`, `is_core`) VALUES ('3', 'computer', '1');
INSERT INTO `categories` (`id`, `name`, `is_core`) VALUES ('4', 'gaming', '1');

-- age_groups (4 rows)
INSERT INTO `age_groups` (`id`, `name`) VALUES ('4', 'adults');
INSERT INTO `age_groups` (`id`, `name`) VALUES ('1', 'all_ages');
INSERT INTO `age_groups` (`id`, `name`) VALUES ('2', 'kids');
INSERT INTO `age_groups` (`id`, `name`) VALUES ('3', 'teens');

-- products (16 rows)
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('1', 'Airpods', 'Wireless Apple Airpods with premium sound quality.', 'Vietnam', 'Apple', '120.00', '50.00', '60.00', 'both', 'airpods.jpg', '2026-07-13', '2', '28', '0', '1', NULL, NULL, '2026-07-11 00:57:38', NULL, '1', '2026-08-22 01:48:18');
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('2', 'Airpods Pro', 'Airpods Pro with active noise cancellation.', 'China', 'Apple', '180.00', '0.00', '180.00', 'both', 'airpods pro.jpg', '2026-07-11', '5', '25', '0', '0', NULL, NULL, '2026-07-11 00:57:38', NULL, NULL, NULL);
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('3', 'Apple Watch', 'Modern smartwatch with fitness tracking.', 'China', 'Apple', '350.00', '0.00', '350.00', 'both', 'apple watch.jpg', '2026-07-08', '5', '10', '0', '1', NULL, NULL, '2026-07-11 00:57:38', NULL, '1', '2026-07-27 02:26:19');
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('4', 'Camera', 'Professional digital camera.', 'Japan', 'Canon', '700.00', '15.00', '595.00', 'both', 'camera.jpg', '2026-07-04', '5', '22', '0', '1', NULL, NULL, '2026-07-11 00:57:38', NULL, '1', '2026-07-31 17:01:55');
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('5', 'Headphones', 'Comfortable headphones with high quality sound.', 'Malaysia', 'Sony', '90.00', '0.00', '90.00', 'both', 'headphones.jpg', '2026-07-01', '0', '20', '0', '1', NULL, NULL, '2026-07-11 00:57:38', NULL, NULL, NULL);
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('6', 'iPad', 'Powerful tablet for work and entertainment.', 'China', 'Apple', '800.00', '0.00', '800.00', 'both', 'ipad.jpg', '2026-06-26', '7', '23', '0', '1', NULL, NULL, '2026-07-11 00:57:38', NULL, '1', '2026-08-30 01:25:20');
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('7', 'iPhone 10 Pro', 'Premium smartphone with great performance.', 'China', 'Apple', '900.00', '0.00', '900.00', 'both', 'iphon10 pro.jpg', '2026-06-21', '1', '19', '0', '1', NULL, NULL, '2026-07-11 00:57:38', NULL, NULL, NULL);
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('8', 'iPhone 11 Pro', 'Advanced smartphone with excellent camera.', 'China', 'Apple', '1100.00', '0.00', '1100.00', 'both', 'iphon11 pro.jpg', '2023-09-25', '1', '29', '0', '1', NULL, NULL, '2026-07-11 00:57:38', NULL, NULL, NULL);
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('9', 'MacBook', 'High-performance laptop for professionals.', 'USA', 'Apple', '1800.00', '0.00', '1800.00', 'both', 'macbook.jpg', '2025-01-15', '5', '3', '1', '1', NULL, NULL, '2026-07-11 00:57:39', NULL, '1', '2026-07-31 17:38:00');
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('10', 'Nintendo Switch Lite', 'Portable gaming console.', 'Japan', 'Nintendo', '300.00', '0.00', '300.00', 'both', 'nintendo switch lite.jpg', '2023-04-10', '0', '20', '0', '0', NULL, NULL, '2026-07-11 00:57:39', NULL, NULL, NULL);
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('11', 'PS4 Controller', 'Wireless PS4 controller.', 'China', 'Sony', '70.00', '0.00', '70.00', 'both', 'ps4 controller.jpg', '2022-08-05', '0', '20', '0', '1', NULL, NULL, '2026-07-11 00:57:39', NULL, NULL, NULL);
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('12', 'PS4', 'PlayStation 4 gaming console.', 'Japan', 'Sony', '500.00', '0.00', '500.00', 'both', 'ps4.jpg', '2021-11-20', '0', '5', '0', '1', NULL, NULL, '2026-07-11 00:57:39', NULL, NULL, NULL);
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('13', 'Smart Watch', 'Smart watch with health monitoring.', 'South Korea', 'Samsung', '150.00', '0.00', '150.00', 'both', 'smart watch.jpg', '2025-07-19', '4', '11', '0', '1', NULL, NULL, '2026-07-11 00:57:39', NULL, NULL, NULL);
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('18', 'UGREEN X512', '', 'jordan', 'Canon', '40.00', '0.00', '40.00', 'both', 'product_1785248052_6a68b9343dce0.png', '2026-07-28', '18', '0', '0', '1', NULL, NULL, '2026-07-28 17:14:12', '1', '1', '2026-08-30 03:36:18');
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('19', 'PlayStation 5 Slim', 'Gaming Console Jordan, playstation 5 console, playstation 5 jordan, playstation 5 slim 825gb, playstation console jordan, playstation gaming, ps5 4k gaming, ps5 825gb, ps5 dualsense, ps5 gaming console, ps5 jordan, ps5 slim 825gb, ps5 slim console, ps5 slim digital, ps5 slim disc edition, sony console jordan, sony playstation 5, sony playstation 5 slim, sony ps5 slim', 'Japan', 'Sony', '500.00', '0.00', '500.00', 'both', 'product_1785248243_6a68b9f34c32d.png', '2026-07-28', '35', '0', '0', '1', NULL, NULL, '2026-07-28 17:17:23', '1', '1', '2026-08-30 05:35:37');
INSERT INTO `products` (`id`, `name`, `description`, `country_of_origin`, `manufacturer`, `price`, `discount_percentage`, `price_after_discount`, `gender_category`, `image_path`, `date_added`, `sales_count`, `stock_quantity`, `out_of_stock_notified`, `is_visible`, `name_ar`, `description_ar`, `created_at`, `created_by_admin_id`, `updated_by_admin_id`, `updated_at`) VALUES ('20', 'iPhone 16', 'The iPhone 16 is a perfect blend of power, style, and innovation. With its advanced A18 Bionic chip, the iPhone 16 delivers lightning-fast performance for multitasking, gaming, and streaming. The stunning 6.1-inch Super Retina XDR OLED display brings', 'USA', 'Apple', '1014.00', '10.00', '912.60', 'both', 'product_1785506898_6a6cac52a9c8e.png', '2026-07-31', '35', '29', '0', '1', NULL, NULL, '2026-07-31 17:08:18', '1', '1', '2026-08-30 06:00:54');

-- product_variants (17 rows)
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('1', '1', 'Default', NULL, '120.00', '50.00', '60.00', '0', 'both', 'airpods.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-29 15:57:24');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('2', '2', 'Default', NULL, '180.00', '0.00', '180.00', '25', 'both', 'airpods pro.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-03 18:59:03');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('3', '3', 'Default', NULL, '350.00', '0.00', '350.00', '9', 'both', 'apple watch.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-30 01:55:18');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('4', '4', 'Default', '#ffffff', '700.00', '15.00', '595.00', '22', 'both', 'camera.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-03 18:59:03');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('5', '5', 'Default', NULL, '90.00', '0.00', '90.00', '20', 'both', 'headphones.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-03 18:59:03');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('7', '7', 'Default', NULL, '900.00', '0.00', '900.00', '19', 'both', 'iphon10 pro.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-03 18:59:03');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('8', '8', 'Default', NULL, '1100.00', '0.00', '1100.00', '29', 'both', 'iphon11 pro.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-03 18:59:03');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('9', '9', 'Default', '#000000', '1800.00', '0.00', '1800.00', '3', 'both', 'macbook.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-29 23:36:44');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('10', '10', 'Default', NULL, '300.00', '0.00', '300.00', '20', 'both', 'nintendo switch lite.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-03 18:59:03');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('11', '11', 'Default', NULL, '70.00', '0.00', '70.00', '20', 'both', 'ps4 controller.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-03 18:59:03');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('12', '12', 'Default', NULL, '500.00', '0.00', '500.00', '3', 'both', 'ps4.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-30 01:55:18');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('13', '13', 'Default', NULL, '150.00', '0.00', '150.00', '11', 'both', 'smart watch.jpg', '1', '0', '0', '2026-07-31 16:55:17', '2026-08-03 18:59:03');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('63', '19', 'Default', '#000000', '500.00', '0.00', '500.00', '3', 'both', 'product_1785248243_6a68b9f34c32d.png', '1', '0', '0', '2026-08-22 22:33:37', '2026-08-29 22:54:11');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('82', '18', 'Default', '#000000', '40.00', '0.00', '40.00', '5', 'both', 'product_1785248052_6a68b9343dce0.png', '1', '0', '0', '2026-08-30 02:23:31', '2026-08-30 02:23:31');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('83', '20', 'Black', '#000000', '1014.00', '10.00', '912.60', '0', 'both', 'product_1785506898_6a6cac52a9c8e.png', '1', '0', '0', '2026-08-30 02:24:40', '2026-08-30 02:24:40');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('84', '20', 'White', '#ffffff', '899.00', '15.00', '764.15', '19', 'both', 'product_1785506899_6a6cac539ad7c.png', '0', '1', '0', '2026-08-30 02:24:40', '2026-08-30 02:24:40');
INSERT INTO `product_variants` (`id`, `product_id`, `color_name`, `color_hex`, `price`, `discount_percentage`, `price_after_discount`, `stock_quantity`, `gender_category`, `image_path`, `is_default`, `sort_order`, `out_of_stock_notified`, `created_at`, `updated_at`) VALUES ('86', '6', 'Default', '#000000', '800.00', '0.00', '800.00', '24', 'both', 'ipad.jpg', '1', '0', '0', '2026-08-30 02:25:20', '2026-08-30 02:25:20');

-- product_category_pivot (17 rows)
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('1', '1');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('2', '1');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('3', '1');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('4', '1');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('5', '2');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('6', '3');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('7', '2');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('8', '2');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('9', '3');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('10', '4');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('11', '4');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('12', '4');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('13', '1');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('18', '1');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('18', '2');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('19', '4');
INSERT INTO `product_category_pivot` (`product_id`, `category_id`) VALUES ('20', '2');

-- product_age_group_pivot (17 rows)
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('1', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('2', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('3', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('3', '3');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('4', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('5', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('6', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('7', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('8', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('9', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('10', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('11', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('12', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('13', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('18', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('19', '1');
INSERT INTO `product_age_group_pivot` (`product_id`, `age_group_id`) VALUES ('20', '1');

-- home_sliders (2 rows)
INSERT INTO `home_sliders` (`id`, `sort_order`, `updated_by_admin_id`, `created_at`, `updated_at`) VALUES ('94', '0', '1', '2026-08-30 12:02:59', '2026-08-30 12:02:59');
INSERT INTO `home_sliders` (`id`, `sort_order`, `updated_by_admin_id`, `created_at`, `updated_at`) VALUES ('95', '1', '1', '2026-08-30 12:02:59', '2026-08-30 12:02:59');

-- home_slider_items (7 rows)
INSERT INTO `home_slider_items` (`id`, `slider_id`, `sort_order`, `active_mode`, `product_id`, `product_link_url`, `product_title`, `product_description`, `manual_image_path`, `manual_link_url`, `manual_title`, `manual_description`, `created_at`, `updated_at`) VALUES ('262', '94', '0', 'product', '1', 'http://localhost/STORE/public/product?id=1', 'Airpods', 'Wireless Apple Airpods with premium sound quality.', NULL, NULL, NULL, NULL, '2026-08-30 12:02:59', '2026-08-30 12:02:59');
INSERT INTO `home_slider_items` (`id`, `slider_id`, `sort_order`, `active_mode`, `product_id`, `product_link_url`, `product_title`, `product_description`, `manual_image_path`, `manual_link_url`, `manual_title`, `manual_description`, `created_at`, `updated_at`) VALUES ('263', '94', '1', 'product', '2', 'http://localhost/STORE/public/product?id=2', 'Airpods Pro', 'Airpods Pro with active noise cancellation.', NULL, NULL, NULL, NULL, '2026-08-30 12:02:59', '2026-08-30 12:02:59');
INSERT INTO `home_slider_items` (`id`, `slider_id`, `sort_order`, `active_mode`, `product_id`, `product_link_url`, `product_title`, `product_description`, `manual_image_path`, `manual_link_url`, `manual_title`, `manual_description`, `created_at`, `updated_at`) VALUES ('264', '94', '2', 'product', '6', 'http://localhost/STORE/public/product?id=6', 'iPad', 'Powerful tablet for work and entertainment.', NULL, NULL, NULL, NULL, '2026-08-30 12:02:59', '2026-08-30 12:02:59');
INSERT INTO `home_slider_items` (`id`, `slider_id`, `sort_order`, `active_mode`, `product_id`, `product_link_url`, `product_title`, `product_description`, `manual_image_path`, `manual_link_url`, `manual_title`, `manual_description`, `created_at`, `updated_at`) VALUES ('265', '94', '3', 'product', '7', 'http://localhost/STORE/public/product?id=7', 'iPhone 10 Pro', 'Premium smartphone with great performance.', NULL, NULL, NULL, NULL, '2026-08-30 12:02:59', '2026-08-30 12:02:59');
INSERT INTO `home_slider_items` (`id`, `slider_id`, `sort_order`, `active_mode`, `product_id`, `product_link_url`, `product_title`, `product_description`, `manual_image_path`, `manual_link_url`, `manual_title`, `manual_description`, `created_at`, `updated_at`) VALUES ('266', '94', '4', 'product', '8', 'http://localhost/STORE/public/product?id=8', 'iPhone 11 Pro', 'Advanced smartphone with excellent camera.', NULL, NULL, NULL, NULL, '2026-08-30 12:02:59', '2026-08-30 12:02:59');
INSERT INTO `home_slider_items` (`id`, `slider_id`, `sort_order`, `active_mode`, `product_id`, `product_link_url`, `product_title`, `product_description`, `manual_image_path`, `manual_link_url`, `manual_title`, `manual_description`, `created_at`, `updated_at`) VALUES ('267', '94', '5', 'product', '10', 'http://localhost/STORE/public/product?id=10', 'Nintendo Switch Lite', 'Portable gaming console.', NULL, NULL, NULL, NULL, '2026-08-30 12:02:59', '2026-08-30 12:02:59');
INSERT INTO `home_slider_items` (`id`, `slider_id`, `sort_order`, `active_mode`, `product_id`, `product_link_url`, `product_title`, `product_description`, `manual_image_path`, `manual_link_url`, `manual_title`, `manual_description`, `created_at`, `updated_at`) VALUES ('268', '95', '0', 'manual', NULL, NULL, NULL, NULL, 'images/slider_1787231033_14fc3559.png', 'https://www.samsung.com/levant_ar/smartphones/galaxy-s24-ultra/', 'Galaxy S24 Ultra', NULL, '2026-08-30 12:02:59', '2026-08-30 12:02:59');

-- website_settings (1 rows)
INSERT INTO `website_settings` (`id`, `created_at`, `employees_count`, `site_url`, `facebook_url`, `instagram_url`, `snapchat_url`, `whatsapp_number`, `tiktok_url`, `twitter_x_url`, `google_maps_url`, `copyright_text`, `phone_number`, `working_hours`, `logo_path`, `favicon_path`, `default_currency`, `default_language`, `return_policy`, `privacy_policy`, `terms_and_conditions`, `footer_text`) VALUES ('1', '2026-07-11 00:50:08', '50', '', 'https://facebook.com/example', 'https://instagram.com/example', '', '+20 100 000 0000', '', '', '', '© Cairo Store. All Rights Reserved.', '+20 100 000 0000', 'Sun - Thu: 9 AM - 6 PM', NULL, NULL, 'USD', 'en', 'You may return any product within 14 days of purchase in its original condition.', 'We respect your privacy. Your personal data is kept secure and never shared with third parties without your consent.', 'By using Cairo Store, you agree to our terms. All sales are subject to product availability.', 'Premium electronics store offering smartphones, laptops, gaming devices and smart accessories.');

SET FOREIGN_KEY_CHECKS = 1;
