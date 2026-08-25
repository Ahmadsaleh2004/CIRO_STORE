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
 * fixImagePath — إرجاع مسار الصورة المعالج من PHP
 */
function fixImagePath(imgPath) {
    return imgPath || '';
}
window.fixImagePath = fixImagePath;

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
