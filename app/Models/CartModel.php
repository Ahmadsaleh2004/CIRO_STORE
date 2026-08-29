<?php

namespace App\Models;

use Exception;
use App\Core\Model;

/**
 * CartModel — سلّة المستخدم على الخادم.
 *
 * ══════════════════════════════════════════════════════════════
 * ما تخزّنه وما لا تخزّنه
 * ══════════════════════════════════════════════════════════════
 *
 * تخزّن **«ماذا وكم»** فقط: منتج، لون، كمية. ولا تخزّن سعراً ولا
 * اسماً ولا صورة — كلّها تُقرأ من `product_variants` عند العرض.
 *
 * ⚠️ وغياب عمود السعر قرارٌ لا إغفال. المرحلة الأولى أغلقت باب «السعر
 * يأتي من العميل»، وعمود سعر هنا يفتحه من جهة أخرى: قيمة مالية
 * مخزَّنة خارج مصدرها تصير مع الوقت مصدرَ حقيقة ثانياً، ثم يقرأها
 * أحدهم يوماً بدل الأصل.
 *
 * ولذلك `getForUser` تضمّ `product_variants` في كل قراءة: السعر الذي
 * يراه الزبون في سلّته هو سعر القاعدة **الآن**، لا سعر لحظة الإضافة.
 * وهذا يجعل حالة «تغيّر السعر» ظاهرةً في السلّة قبل الدفع لا عنده.
 *
 * ══════════════════════════════════════════════════════════════
 * لا سلّة زائر
 * ══════════════════════════════════════════════════════════════
 *
 * زرّ السلّة وزرّ «أضف للسلّة» محروسان بتسجيل الدخول في القوالب
 * الثلاثة، وغير المسجَّل يُدفع إلى نافذة الدخول. فلكل صفّ هنا مالكٌ
 * معروف منذ إنشائه — ولا منطق دمج ولا معرّف جلسة.
 */
class CartModel extends Model
{
    /**
     * أقصى كمية للسطر الواحد.
     *
     * يطابق CheckoutController::MAX_ITEM_QTY عمداً: سلّةٌ تقبل ما
     * يرفضه الدفع تعني زبوناً يكتشف الرفض في آخر خطوة.
     */
    public const MAX_QTY = 100;

    /**
     * سلّة المستخدم، مع بيانات العرض الحيّة من القاعدة.
     *
     * الشكل يطابق ما تنتظره `js/features/cart.js` حرفياً — نفس مفاتيح
     * السلّة المحلية (`id`, `variant_id`, `quantity`, `price` …) — كي
     * لا يحتاج العميل ترجمةً ثانية. الترجمة الوحيدة تبقى عند الإرسال
     * إلى `/checkout` كما أرسته المرحلة الأولى.
     *
     * `is_visible = 1` في الضمّ: منتجٌ أُخفي بعد إضافته يختفي من
     * السلّة بدل أن يصل الدفع فيُرفض هناك.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForUser(int $userId): array
    {
        try {
            $stmt = self::db()->prepare("
                SELECT
                    c.product_id                AS id,
                    c.variant_id,
                    c.quantity,
                    p.name,
                    v.color_name,
                    v.image_path,
                    v.price_after_discount      AS price,
                    v.stock_quantity            AS stock
                FROM cart_items c
                JOIN product_variants v ON v.id = c.variant_id
                JOIN products p         ON p.id = c.product_id
                WHERE c.user_id = ?
                  AND p.is_visible = 1
                ORDER BY c.created_at ASC, c.id ASC
            ");
            $stmt->execute([$userId]);

            return array_map(static function (array $row): array {
                $row['id']         = (int) $row['id'];
                $row['variant_id'] = (int) $row['variant_id'];
                $row['quantity']   = (int) $row['quantity'];
                $row['price']      = (float) $row['price'];
                $row['stock']      = (int) $row['stock'];
                return $row;
            }, $stmt->fetchAll());
        } catch (Exception $e) {
            error_log('CartModel::getForUser Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * يضيف كمية إلى سطر، أو يُنشئه.
     *
     * ── لماذا ON DUPLICATE KEY لا SELECT ثم INSERT/UPDATE ────────
     *
     * لأن الثانية سباقٌ صريح: تبويبان يضيفان نفس اللون في اللحظة نفسها
     * يقرآن «غير موجود» معاً فيُدرجان صفّين — ويمنعهما المفتاح الفريد
     * بخطأ بدل أن ينتج الصواب. والعبارة الواحدة تجعل القاعدة تحسم
     * الأمر بنفسها في رحلة واحدة.
     *
     * والحدّ الأعلى داخل SQL بـLEAST: حسابه في PHP يعني قراءةً قبل
     * الكتابة، وهو السباق نفسه من باب آخر.
     *
     * ⚠️ لا يتحقّق من المخزون. السلّة نيّةٌ لا حجز — والحجز يقع في
     * placeOrder داخل معاملة تقفل الصفّ. فحصُ المخزون هنا يعطي وعداً
     * لا تملك السلّة الوفاء به، ويجعل الزبون يظنّ القطعة محجوزة له.
     */
    public static function add(int $userId, int $productId, int $variantId, int $qty): bool
    {
        if ($qty < 1 || $qty > self::MAX_QTY) {
            return false;
        }

        try {
            $stmt = self::db()->prepare("
                INSERT INTO cart_items (user_id, product_id, variant_id, quantity)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), " . self::MAX_QTY . ")
            ");

            return $stmt->execute([$userId, $productId, $variantId, $qty]);
        } catch (Exception $e) {
            // أشيع سبب: variant محذوف بين عرض الصفحة والنقر — المفتاح
            // الأجنبي يرفض، وهو الرفض الصحيح.
            error_log('CartModel::add Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * يضبط كمية سطر ضبطاً مطلقاً — أو يحذفه عند الصفر.
     *
     * الصفر حذفٌ لا كمية: سطرٌ بكمية صفر يظهر في السلّة ولا يُطلَب،
     * وهو حالة لا معنى لها تُربك العرض والعدّاد معاً.
     */
    public static function setQuantity(int $userId, int $variantId, int $qty): bool
    {
        if ($qty <= 0) {
            return self::remove($userId, $variantId);
        }

        if ($qty > self::MAX_QTY) {
            return false;
        }

        try {
            $stmt = self::db()->prepare(
                'UPDATE cart_items SET quantity = ? WHERE user_id = ? AND variant_id = ?'
            );
            $stmt->execute([$qty, $userId, $variantId]);

            // rowCount = 0 يعني «لا سطر بهذا المعرّف لهذا المستخدم» —
            // إمّا لأنه غير موجود، أو لأنه يخصّ غيره. الحالتان رفضٌ
            // واحد من زاوية المستدعي، وشرط user_id في نفس العبارة هو
            // ما يمنع تعديل سلّة الآخرين (IDOR).
            //
            // ⚠️ ويُقبل التحديث إلى القيمة نفسها: MySQL يُرجع 0 حينها،
            // فنفحص الوجود صراحةً بدل أن نُرجع «فشل» عن نجاح.
            return $stmt->rowCount() > 0 || self::hasVariant($userId, $variantId);
        } catch (Exception $e) {
            error_log('CartModel::setQuantity Error: ' . $e->getMessage());
            return false;
        }
    }

    /** يحذف سطراً. شرط الملكية في نفس العبارة — لا فحص منفصل يُنسى. */
    public static function remove(int $userId, int $variantId): bool
    {
        try {
            $stmt = self::db()->prepare(
                'DELETE FROM cart_items WHERE user_id = ? AND variant_id = ?'
            );
            $stmt->execute([$userId, $variantId]);

            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('CartModel::remove Error: ' . $e->getMessage());
            return false;
        }
    }

    /** يُفرّغ سلّة المستخدم — تُستدعى بعد نجاح الطلب. */
    public static function clear(int $userId): bool
    {
        try {
            return self::db()
                ->prepare('DELETE FROM cart_items WHERE user_id = ?')
                ->execute([$userId]);
        } catch (Exception $e) {
            error_log('CartModel::clear Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * مجموع القطع في سلّة المستخدم — لشارة العدّاد.
     *
     * مجموع الكميات لا عدد السطور: الشارة تقول «كم قطعة» لا «كم لوناً».
     */
    public static function countItems(int $userId): int
    {
        try {
            $stmt = self::db()->prepare(
                'SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = ?'
            );
            $stmt->execute([$userId]);

            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('CartModel::countItems Error: ' . $e->getMessage());
            return 0;
        }
    }

    /** هل يملك المستخدم سطراً بهذا الـvariant؟ */
    private static function hasVariant(int $userId, int $variantId): bool
    {
        $stmt = self::db()->prepare(
            'SELECT 1 FROM cart_items WHERE user_id = ? AND variant_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $variantId]);

        return (bool) $stmt->fetchColumn();
    }
}
