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
