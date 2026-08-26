// ══════════════════════════════════════════════════════════════
// js/core/utils.js — الدوال البحتة المشتركة
// ══════════════════════════════════════════════════════════════

/**
 * escHtml — تهريب النصوص للحماية من XSS
 */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
window.escHtml = escHtml;
window.escapeHtml = escHtml; // للتوافق العكسي التام

/**
 * imagePathOrEmpty — حارس ضد null/undefined لمسار صورة، لا أكثر.
 *
 * ⚠️ **لا تُصلح هذه الدالة أي مسار.** كان اسمها fixImagePath، وهو اسم
 * يَعِد بما يفعله مقابلها في PHP (app/helpers/functions.php): إضافة
 * بادئة images/ لأي مسار بلا شرطة مائلة، وتمرير الروابط المطلقة كما
 * هي، وإرجاع صورة بديلة عند الفراغ. هذه لا تفعل شيئاً من ذلك.
 *
 * الاسم القديم كان فخّاً: في محرّر السلايدر بنى أحدهم المسار بيده
 * ظنّاً أن الإصلاح متوفّر في المتصفح، فخرج /airpods.jpg بدل
 * /images/airpods.jpg — وكُسرت كل صور المنتجات هناك.
 *
 * **القاعدة:** إصلاح مسار الصورة مسؤولية الخادم دائماً. مرّر المسار
 * عبر fixImagePath() في PHP قبل إرساله للمتصفح، ولا تبنِه في JS.
 */
function imagePathOrEmpty(imgPath) {
    return imgPath || '';
}
window.imagePathOrEmpty = imagePathOrEmpty;

// الاسم القديم يبقى موجّهاً للاسم الجديد كي لا ينكسر أي مستدعٍ خارجي،
// ولأن حذفه دفعةً واحدة تغيير أوسع من التنظيف. المستدعون الثلاثة داخل
// المشروع حُوّلوا كلهم.
window.fixImagePath = imagePathOrEmpty;

/**
 * formatRelativeTime — تحويل التاريخ لزمن نسبي (Just now, 5m ago, etc)
 */
function formatRelativeTime(dateStr) {
    if (!dateStr) return '';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60)    return 'Just now';
    if (diff < 3600)  return `${Math.floor(diff/60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
    return `${Math.floor(diff/86400)}d ago`;
}
window.formatRelativeTime = formatRelativeTime;

/**
 * buildProductPicture — بناء <picture> مع WebP fallback
 */
window.buildProductPicture = function (imagePath, altText, cssClass = '') {
    const webp = imagePath.replace(/\.(jpe?g|png)$/i, '.webp');
    const cls  = cssClass ? ` class="${cssClass}"` : '';
    return `<picture>
        <source srcset="${webp}" type="image/webp">
        <img src="${imagePath}" alt="${altText}"${cls} loading="lazy">
    </picture>`;
};

/**
 * stockBadge — بادج حالة المخزون. **مرآة لـgetStockBadge() في
 * app/helpers/stock_badge_helper.php ويجب أن تبقى مطابقة لها.**
 *
 * كانت هذه القاعدة مكتوبة **ثلاث مرات** في لغتين:
 *   1. الهيلبر في PHP (يخدم الـviews المبنية على الخادم)
 *   2. getStockBadgeJs في js/features/wishlist.js
 *   3. كتلة if/else مضمّنة في js/features/product-details.js
 *
 * وكانت النسختان في JS تختلفان عن بعضهما أصلاً: الأولى بلا فرع
 * «متوفّر»، والثانية به — نفس الفرق الذي اكتُشف بين PHP والـview في
 * المرحلة 5. اتفاقها اليوم كان مصادفة صيانة لا ضماناً.
 *
 * ⚠️ العتبة 50 والنصوص والأصناف مكرّرة عمداً بين لغتين — لا سبيل لتفادي
 * ذلك في مشروع بلا خطوة بناء تشارك الثوابت. **إن غيّرت شيئاً هنا فغيّره
 * في stock_badge_helper.php أيضاً**، والعكس. الملفان يشيران لبعضهما.
 *
 * @param {number}  stock        كمية المخزون (العمود unsigned فلا قيم سالبة)
 * @param {boolean} showInStock  هل نُرجع بادجاً أخضر عند التوفّر الوفير؟
 *                               صفحة تفاصيل المنتج نعم، وقائمة المنتجات
 *                               والمفضّلة لا (بادج على كل بطاقة ضجيج بصري).
 * @returns {{label: string, class: string}|null}
 */
function stockBadge(stock, showInStock = false) {
    const n = Number(stock);

    if (n === 0) {
        return { label: 'Out of Stock', class: 'bg-danger' };
    }
    if (n > 0 && n <= 50) {
        return { label: `Limited (${n} left)`, class: 'bg-warning text-dark' };
    }
    if (showInStock) {
        return { label: `In Stock (${n})`, class: 'bg-success' };
    }
    return null;
}
window.stockBadge = stockBadge;
