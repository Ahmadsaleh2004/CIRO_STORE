import { beforeAll, describe, expect, it } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/core/utils.js — الدوال المشتركة لكل صفحات المتجر.
 *
 * أهمّ ما هنا `stockBadge`: **مرآة لـgetStockBadge() في PHP** موثَّقة
 * صراحةً في الملفين. الاثنتان تخدمان الشاشة نفسها — الـPHP للبطاقات
 * المبنيّة على الخادم، والـJS للبطاقات التي يبنيها المتصفح (المفضّلة
 * وتفاصيل المنتج). واختلافهما يعني منتجاً واحداً بشارتين مختلفتين
 * حسب الطريق الذي وصل منه.
 *
 * الطرف الـPHP مختبَر في tests/Unit/StockBadgeHelperTest.php بالقيم
 * نفسها. الاختباران معاً هما ما يجعل «يجب أن تبقى مطابقة» جملةً
 * مفروضة لا أمنية مكتوبة في تعليق.
 */
describe('utils.js', () => {
    beforeAll(() => {
        loadScript('js/core/utils.js');
    });

    describe('stockBadge — مرآة getStockBadge في PHP', () => {
        it('صفر يعني نفاد المخزون', () => {
            expect(window.stockBadge(0)).toEqual({ label: 'Out of Stock', class: 'bg-danger' });
        });

        it('النفاد يسبق كل شيء ولا يقلبه وسيط العرض', () => {
            expect(window.stockBadge(0, true).label).toBe('Out of Stock');
        });

        it('المخزون المنخفض يعرض العدد المتبقّي', () => {
            expect(window.stockBadge(7)).toEqual({
                label: 'Limited (7 left)',
                class: 'bg-warning text-dark',
            });
        });

        // حدّا العتبة تحديداً — نفس ما يحرسه الاختبار في PHP.
        it('العتبة عند 50 بالضبط', () => {
            expect(window.stockBadge(50).label).toBe('Limited (50 left)');
            expect(window.stockBadge(51)).toBeNull();
            expect(window.stockBadge(51, true).label).toBe('In Stock (51)');
        });

        it('واحد ما زال محدوداً', () => {
            expect(window.stockBadge(1).label).toBe('Limited (1 left)');
        });

        it('المخزون الوفير بلا شارة افتراضياً', () => {
            expect(window.stockBadge(500)).toBeNull();
        });

        it('والمخزون الوفير بشارة خضراء عند الطلب', () => {
            expect(window.stockBadge(500, true)).toEqual({
                label: 'In Stock (500)',
                class: 'bg-success',
            });
        });
    });

    describe('encodeImagePath', () => {
        /**
         * العطل الذي وُجدت له: المسافة في srcset **فاصل بين مرشّحين**
         * لا محرفاً عادياً. أسماء صور المشروع تحوي مسافات، فكان
         * المتصفح يقرأ «…/images/apple watch.webp» مرشّحَين ويرفض
         * الاثنين — عشر مرّات في تحميل واحد.
         */
        it('يُرمّز المسافات في اسم الملف', () => {
            expect(window.encodeImagePath('images/apple watch.webp'))
                .toBe('images/apple%20watch.webp');
        });

        it('يُبقي الشرطات المائلة كما هي', () => {
            expect(window.encodeImagePath('a/b c/d.jpg')).toBe('a/b%20c/d.jpg');
        });

        /**
         * عطل وقع في أول نسخة من الدالة: ترميز المقاطع بلا فصل المخطّط
         * يحوّل «http:» إلى «http%3A» فيتحطّم الرابط المطلق. أمسكه فحص
         * مباشر قبل أن يصل المتصفح.
         */
        it('لا يُفسد المخطّط في الرابط المطلق', () => {
            expect(window.encodeImagePath('http://localhost/STORE/public/images/a b.webp'))
                .toBe('http://localhost/STORE/public/images/a%20b.webp');
            expect(window.encodeImagePath('https://cdn.example.com/x y/z.png'))
                .toBe('https://cdn.example.com/x%20y/z.png');
        });

        it('لا يُرمّز ما هو مرمَّز سلفاً', () => {
            expect(window.encodeImagePath('images/apple%20watch.webp'))
                .toBe('images/apple%20watch.webp');
        });

        it('يترك المسار السليم كما هو', () => {
            expect(window.encodeImagePath('images/macbook.jpg')).toBe('images/macbook.jpg');
        });

        it('يحفظ سلسلة الاستعلام', () => {
            expect(window.encodeImagePath('images/a b.jpg?v=1')).toBe('images/a%20b.jpg?v=1');
        });

        it('يمرّر الفارغ بلا انفجار', () => {
            expect(window.encodeImagePath('')).toBe('');
            expect(window.encodeImagePath(null)).toBeNull();
        });
    });

    describe('escHtml', () => {
        it('يهرّب المحارف التي تفتح باب الحقن', () => {
            const out = window.escHtml('<script>alert(1)</script>');
            expect(out).not.toContain('<script>');
            expect(out).toContain('&lt;');
        });

        it('يهرّب علامات الاقتباس', () => {
            const out = window.escHtml(`" '`);
            expect(out).not.toContain('"');
        });

        it('لا يفسد النصّ العربي', () => {
            expect(window.escHtml('أحمد صالح')).toBe('أحمد صالح');
        });
    });

    describe('buildProductPicture', () => {
        it('يُرمّز مساري srcset و src معاً', () => {
            const html = window.buildProductPicture('images/apple watch.jpg', 'ساعة');

            expect(html).toContain('srcset="images/apple%20watch.webp"');
            expect(html).toContain('src="images/apple%20watch.jpg"');
            // لا مسافة خام داخل قيمة srcset — وهي علّة العطل الأصلية.
            expect(/srcset="[^"]*\s[^"]*"/.test(html)).toBe(false);
        });

        it('يحوّل الامتداد إلى webp في المصدر البديل وحده', () => {
            const html = window.buildProductPicture('images/x.png', 'x');
            expect(html).toContain('srcset="images/x.webp"');
            expect(html).toContain('src="images/x.png"');
        });
    });
});
