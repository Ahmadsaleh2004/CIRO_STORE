<?php

namespace App\Models;

use App\Core\Database;
use Exception;
use PDO;

/**
 * AdminProductModel — استعلامات لوحة تحكم الأدمن الخاصة بالمنتجات فقط
 * (منفصل عن ProductModel المستخدم بواجهة المتجر العامة).
 */
class AdminProductModel
{
    /** خيارات ترتيب السعر */
    public const PRICE_SORT_OPTIONS = [
        'price_desc' => 'Price: High to Low',
        'price_asc'  => 'Price: Low to High',
    ];

    /** خيارات ترتيب الكمية */
    public const STOCK_SORT_OPTIONS = [
        'stock_desc' => 'Stock: High to Low',
        'stock_asc'  => 'Stock: Low to High',
    ];

    /** خيارات ترتيب التاريخ */
    public const DATE_SORT_OPTIONS = [
        'date_desc' => 'Newest First',
        'date_asc'  => 'Oldest First',
    ];

    /**
     * قائمة منتجات صفحة Manage Products — بحث + فلترة متعددة الكاتوجريز + ترتيب مركّب.
     * الأولوية: سعر → كمية → تاريخ.
     */
    public static function getPaginated(
        string  $search,
        array   $categoryIds,  // array بدل int — يقبل عدة كاتوجريز (OR)
        ?string $priceSort,
        ?string $stockSort,
        ?string $dateSort,
        int     $limit,
        int     $offset
    ): array {
        try {
            $db     = Database::connect();
            $where  = [];
            $params = [];

            // فلتر البحث بالاسم
            if ($search !== '') {
                $where[]  = 'p.name LIKE ?';
                $params[] = "%{$search}%";
            }

            // JOIN فلترة بكاتوجريز متعددة (OR) — DISTINCT يمنع تكرار المنتج
            $joinCat   = '';
            $catParams = [];
            if (!empty($categoryIds)) {
                $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
                $joinCat      = "JOIN product_category_pivot pcpf
                                  ON pcpf.product_id = p.id
                                  AND pcpf.category_id IN ({$placeholders})";
                $catParams    = array_map('intval', $categoryIds);
            }

            // compound ORDER BY بالأولوية: سعر → كمية → تاريخ
            $orderParts = [];
            if ($priceSort === 'price_desc')     $orderParts[] = 'p.price DESC';
            elseif ($priceSort === 'price_asc')  $orderParts[] = 'p.price ASC';

            if ($stockSort === 'stock_desc')     $orderParts[] = 'total_stock DESC';
            elseif ($stockSort === 'stock_asc')  $orderParts[] = 'total_stock ASC';

            if ($dateSort === 'date_asc')        $orderParts[] = 'p.date_added ASC';
            elseif ($dateSort === 'date_desc')   $orderParts[] = 'p.date_added DESC';

            // افتراضي: تاريخ تنازلي إذا لم يُحدد ترتيب
            if (empty($orderParts)) {
                $orderParts[] = 'p.date_added DESC';
            }

            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $orderSql = implode(', ', $orderParts);

            // ترتيب params: catParams أولاً (JOIN)، ثم WHERE، ثم LIMIT/OFFSET
            $allParams   = array_merge($catParams, $params);
            $allParams[] = $limit;
            $allParams[] = $offset;

            $sql = "
                SELECT p.*,
                       GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS categories,
                       COALESCE(
                           (SELECT SUM(pv.stock_quantity)
                            FROM product_variants pv WHERE pv.product_id = p.id),
                           p.stock_quantity, 0
                       ) AS total_stock,
                       (SELECT MIN(pv2.stock_quantity)
                        FROM product_variants pv2 WHERE pv2.product_id = p.id) AS min_variant_stock,
                       lm.full_name AS last_modified_by_name
                FROM products p
                {$joinCat}
                LEFT JOIN product_category_pivot pcp ON pcp.product_id = p.id
                LEFT JOIN categories c ON c.id = pcp.category_id
                LEFT JOIN admins lm ON lm.id = p.updated_by_admin_id
                {$whereSql}
                GROUP BY p.id, lm.full_name
                ORDER BY {$orderSql}
                LIMIT ? OFFSET ?
            ";

            $stmt       = $db->prepare($sql);
            $totalCount = count($allParams);

            foreach ($allParams as $i => $val) {
                // آخر عنصرين دائماً LIMIT/OFFSET = INT
                $isInt = ($i >= $totalCount - 2) || is_int($val);
                $stmt->bindValue($i + 1, $val, $isInt ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("AdminProductModel::getPaginated Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * عدد المنتجات بعد تطبيق الفلاتر — لحساب عدد صفحات الـ Pagination
     */
    public static function countFiltered(string $search, array $categoryIds = []): int
    {
        try {
            $db        = Database::connect();
            $where     = [];
            $catParams = [];
            $params    = [];

            if ($search !== '') {
                $where[]  = 'p.name LIKE ?';
                $params[] = "%{$search}%";
            }

            $joinCat = '';
            if (!empty($categoryIds)) {
                $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
                $joinCat      = "JOIN product_category_pivot pcpf
                                  ON pcpf.product_id = p.id
                                  AND pcpf.category_id IN ({$placeholders})";
                $catParams    = array_map('intval', $categoryIds);
            }

            $whereSql  = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $allParams = array_merge($catParams, $params);

            $stmt = $db->prepare(
                "SELECT COUNT(DISTINCT p.id) FROM products p {$joinCat} {$whereSql}"
            );
            $stmt->execute($allParams);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminProductModel::countFiltered Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * العدد الكلي للمنتجات في النظام — غير مفلتر إطلاقًا (عداد عنوان صفحة Manage Products)
     */
    public static function countAll(): int
    {
        try {
            return (int)Database::connect()
                ->query("SELECT COUNT(*) FROM products")
                ->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminProductModel::countAll Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * جلب منتج واحد + كاتوجريزه (IDs) + متغيراته — لصفحة Edit
     */
    public static function findByIdWithCategories(int $id): ?array
    {
        try {
            $db = Database::connect();

            $stmt = $db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                return null;
            }

            // كاتوجريز المنتج كـ array من IDs
            $catStmt = $db->prepare(
                "SELECT category_id FROM product_category_pivot WHERE product_id = ?"
            );
            $catStmt->execute([$id]);
            $product['category_ids'] = array_map('intval', $catStmt->fetchAll(PDO::FETCH_COLUMN));

            // variants مرتبة بالـ sort_order
            $varStmt = $db->prepare(
                "SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order ASC, id ASC"
            );
            $varStmt->execute([$id]);
            $product['variants'] = $varStmt->fetchAll(PDO::FETCH_ASSOC);

            return $product;
        } catch (Exception $e) {
            error_log("AdminProductModel::findByIdWithCategories Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * مزامنة كاتوجريز منتج ضمن transaction مفتوحة — لا تُستدعى مستقلة.
     * تحذف القديم وتكتب الجديد. $categoryIds يجب أن يحتوي عنصراً واحداً على الأقل.
     */
    public static function syncCategories(\PDO $db, int $productId, array $categoryIds): void
    {
        $db->prepare("DELETE FROM product_category_pivot WHERE product_id = ?")
           ->execute([$productId]);

        $ins = $db->prepare(
            "INSERT IGNORE INTO product_category_pivot (product_id, category_id) VALUES (?, ?)"
        );
        foreach (array_unique(array_map('intval', $categoryIds)) as $catId) {
            if ($catId > 0) {
                $ins->execute([$productId, $catId]);
            }
        }
    }

    /**
     * إنشاء منتج جديد مع variants وكاتوجريزه في transaction واحدة.
     *
     * ⚠️ التحقق من وجود صورة يجب أن يتم قبل استدعاء هذه الدالة —
     * لا تُفتح transaction لعملية رح تفشل أكيد.
     *
     * @param array $data        حقول products (name, description, country_of_origin, manufacturer...)
     * @param array $variants    كل variant: [color_name, color_hex, price, discount, stock, gender, image_path, is_default, sort_order]
     * @param array $categoryIds مصفوفة IDs (واحد على الأقل)
     * @param int   $adminId     ID الأدمن المُنشئ
     * @return int|null          ID المنتج الجديد أو null عند الفشل
     */
    public static function create(array $data, array $variants, array $categoryIds, int $adminId): ?int
    {
        if (empty($variants)) {
            error_log("AdminProductModel::create — variants array is empty.");
            return null;
        }
        if (empty($categoryIds)) {
            error_log("AdminProductModel::create — categoryIds array is empty.");
            return null;
        }

        $db = Database::connect();
        try {
            $db->beginTransaction();

            $db->prepare("
                INSERT INTO products
                    (name, description, country_of_origin, manufacturer,
                     price, discount_percentage, gender_category,
                     image_path, date_added, is_visible,
                     created_by_admin_id, updated_by_admin_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, ?, ?)
            ")->execute([
                trim($data['name']),
                trim($data['description']   ?? ''),
                trim($data['country']        ?? ''),
                trim($data['manufacturer']   ?? ''),
                (float)($data['price']       ?? 0),
                (float)($data['discount']    ?? 0),
                in_array($data['gender'] ?? 'both', ['male', 'female', 'both']) ? $data['gender'] : 'both',
                $data['image_path'] ?? null,
                $adminId,
                $adminId,
            ]);

            $productId = (int)$db->lastInsertId();

            self::insertVariants($db, $productId, $variants);
            self::syncCategories($db, $productId, $categoryIds);

            $db->commit();
            return $productId;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("AdminProductModel::create Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * تحديث منتج موجود مع variants وكاتوجريزه في transaction واحدة.
     *
     * @param int   $productId   ID المنتج
     * @param array $data        حقول products للتحديث
     * @param array $variants    كل variant مع بياناتها
     * @param array $categoryIds مصفوفة IDs
     * @param int   $adminId     ID الأدمن المعدِّل
     *
     * القيمة المُرجَعة ثلاثية كما في delete():
     *   true  — حُدِّث فعلاً
     *   false — المعرّف غير موجود
     *   null  — فشل تقني أو مدخل غير صالح
     *
     * @return bool|null
     */
    public static function update(int $productId, array $data, array $variants, array $categoryIds, int $adminId): ?bool
    {
        if (empty($variants)) {
            error_log("AdminProductModel::update — variants array is empty.");
            return null;
        }
        if (empty($categoryIds)) {
            error_log("AdminProductModel::update — categoryIds array is empty.");
            return null;
        }

        $db = Database::connect();
        try {
            $db->beginTransaction();

            // ⚠️ الوجود يُفحص صراحةً لا بـrowCount الخاص بجملة UPDATE.
            // في MySQL تُرجع UPDATE بقيم مطابقة **صفر صفوف متأثرة** رغم
            // وجود الصفّ، فالاعتماد عليها يخلط «غير موجود» بـ«لم يتغيّر
            // شيء» — ويجعل حفظ منتج بلا تعديل يُجيب «Product not found».
            // (delete() تستطيع الاعتماد على rowCount لأن الحذف لا يملك
            // هذا الالتباس.)
            $exists = $db->prepare("SELECT 1 FROM products WHERE id = ? LIMIT 1");
            $exists->execute([$productId]);
            if ($exists->fetchColumn() === false) {
                $db->rollBack();
                return false;
            }

            $fields = [
                'name'                => trim($data['name']),
                'description'         => trim($data['description']   ?? ''),
                'country_of_origin'   => trim($data['country']       ?? ''),
                'manufacturer'        => trim($data['manufacturer']   ?? ''),
                'price'               => (float)($data['price']       ?? 0),
                'discount_percentage' => (float)($data['discount']    ?? 0),
                'gender_category'     => in_array($data['gender'] ?? 'both', ['male', 'female', 'both'])
                                            ? $data['gender'] : 'both',
                'updated_at'          => date('Y-m-d H:i:s'),
                'updated_by_admin_id' => $adminId,
            ];

            if (!empty($data['image_path'])) {
                $fields['image_path'] = $data['image_path'];
            }

            $setSql = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
            $params = array_values($fields);
            $params[] = $productId;

            // القاعدة تُطلق على «UPDATE متسلسل ثم return true» لأنها لا
            // ترى ما يثبت وجود الصفّ. والتبرير هنا: الوجود مفحوص صراحةً
            // قبل هذا السطر بـSELECT، ولم يُستعمل rowCount عمداً — راجع
            // التعليق أعلى الـtransaction. وإطلاق القاعدة هنا هو غرضها:
            // أن تُجبر على كتابة هذا التبرير لا أن تمرّ صامتة.
            // nosemgrep: cairo-execute-then-return-true
            $db->prepare("UPDATE products SET {$setSql} WHERE id = ?")->execute($params);

            $db->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$productId]);
            self::insertVariants($db, $productId, $variants);
            self::syncCategories($db, $productId, $categoryIds);

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("AdminProductModel::update Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * حذف منتج + variants + pivot في transaction واحدة.
     * صور الـ variants تُحذف من الكنترولر قبل استدعاء هذه الدالة.
     *
     * القيمة المُرجَعة ثلاثية عمداً:
     *   true  — حُذف فعلاً (صفّ واحد على الأقل تأثّر)
     *   false — المعرّف غير موجود، فلم يُحذف شيء (والـtransaction رُوجعت)
     *   null  — فشل تقني (استثناء)
     * الفصل بين false وnull مقصود: المستدعي يحتاج رسالة مختلفة لكلٍّ منهما،
     * ولا يجوز أن يكتب سجل تدقيق أو يطلق إشعاراً في حالة false.
     */
    public static function delete(int $productId): ?bool
    {
        $db = Database::connect();
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$productId]);
            $db->prepare("DELETE FROM product_category_pivot WHERE product_id = ?")->execute([$productId]);

            $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$productId]);

            // DELETE على معرّف غير موجود ينجح بلا خطأ ويحذف صفر صفوف. بلا
            // هذا الفحص كانت الدالة تُرجع true لمنتج لم يوجد قط، فيكتب
            // الكنترولر صفّ تدقيق وإشعاراً عن حذف لم يحدث.
            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                return false;
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("AdminProductModel::delete Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * إجمالي مخزون منتج عبر كل الـ variants (الألوان) — يُستخدم للتحقق من نفاذ
     * المخزون بعد إضافة/تعديل منتج. يرجع 1 (وليس 0) عند فشل الاستعلام تحديداً
     * لتفادي إطلاق إشعار "نفاذ مخزون" كاذب بسبب خطأ تقني، وليس نفاذ حقيقي.
     */
    public static function getTotalStock(int $productId): int
    {
        try {
            $stmt = Database::connect()->prepare("
                SELECT COALESCE(SUM(stock_quantity), 0)
                FROM product_variants
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminProductModel::getTotalStock Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * اسم منتج بمعرّفه فقط — يُستخدم قبل الحذف النهائي (Hard Delete) لأن السطر
     * سيختفي من الجدول ولن يمكن معرفة اسمه بعدها لأغراض الإشعار/السجل.
     */
    /**
     * اسم منتج بمعرّفه.
     *
     * تفويض إلى ProductModel: الاستعلام كان مكتوباً هنا وفي موديل المتجر
     * بنفس النص. اسم المنتج ليس مفهوماً خاصاً بلوحة التحكم، فمصدر الحقيقة
     * صار ProductModel. أُبقيت هذه الدالة لأن كنترولرز الأدمن تستدعيها.
     */
    public static function getNameById(int $productId): ?string
    {
        return ProductModel::getNameById($productId);
    }

    /**
     * إخفاء/إظهار منتج (toggle is_visible).
     * يرجع قيمة is_visible الجديدة أو null عند الفشل.
     */
    public static function toggleVisibility(int $productId): ?int
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare("SELECT is_visible FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$productId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                return null;
            }
            $newVal = $current ? 0 : 1;
            $db->prepare("UPDATE products SET is_visible = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$newVal, $productId]);
            return $newVal;
        } catch (Exception $e) {
            error_log("AdminProductModel::toggleVisibility Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * جلب مسارات صور variants لمنتج معيّن — لحذفها من القرص قبل حذف المنتج.
     */
    public static function getVariantImagePaths(int $productId): array
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT image_path FROM product_variants WHERE product_id = ? AND image_path IS NOT NULL"
            );
            $stmt->execute([$productId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log("AdminProductModel::getVariantImagePaths Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * إدخال batch من variants ضمن transaction مفتوحة.
     */
    private static function insertVariants(\PDO $db, int $productId, array $variants): void
    {
        $ins = $db->prepare("
            INSERT INTO product_variants
                (product_id, color_name, color_hex, price, discount_percentage,
                 stock_quantity, gender_category, image_path, is_default, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($variants as $idx => $v) {
            $ins->execute([
                $productId,
                trim($v['color_name']  ?? ''),
                trim($v['color_hex']   ?? '') ?: null,
                (float)($v['price']    ?? 0),
                (float)($v['discount'] ?? 0),
                (int)($v['stock']      ?? 0),
                in_array($v['gender'] ?? 'both', ['male', 'female', 'both']) ? $v['gender'] : 'both',
                $v['image_path'] ?? null,
                (int)(!empty($v['is_default'])),
                (int)($v['sort_order'] ?? $idx),
            ]);
        }
    }

    /**
     * رفع صورة variant واحدة على القرص وإرجاع المسار النسبي.
     * يُستدعى من الكنترولر لكل variant قبل استدعاء create()/update().
     *
     * @param array  $fileEntry  مصفوفة ملف واحدة من $_FILES
     * @param string $uploadDir  المجلد المطلق (مع trailing slash)
     * @return string|null       المسار النسبي (images/xxx.jpg) أو null
     */
    public static function uploadVariantImage(array $fileEntry, string $uploadDir): ?string
    {
        if (empty($fileEntry['tmp_name']) || $fileEntry['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $mime    = mime_content_type($fileEntry['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            return null;
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };

        $filename = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest     = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($fileEntry['tmp_name'], $dest)) {
            return null;
        }

        return 'images/' . $filename;
    }
}
