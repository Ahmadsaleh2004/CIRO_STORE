import { beforeEach, describe, expect, it, vi } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/features/cart.js — طبقة تخزين السلّة.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا هذا الملف
 * ══════════════════════════════════════════════════════════════
 *
 * السلّة انتقلت من localStorage إلى الخادم، وصارت الذاكرة **مرآة**
 * لجدول `cart_items`. والمرآة تخلق صنفاً جديداً من الأعطال لم يكن
 * ممكناً قبلها: أن تختلف الشاشة عن القاعدة.
 *
 * وقد وقع ذلك فعلاً في أوّل نسخة من syncCartWithStock: كانت تُعدّل
 * الكمية داخل المرآة (وgetCartData تُرجعها بالمرجع)، ثم تقارن القيمة
 * المعدَّلة بنفسها لتقرّر ما تُرسله إلى الخادم — فالشرط `x < x` كاذب
 * دائماً، ولم يُرسَل شيء قطّ.
 *
 * الأثر: الشاشة تعرض الكمية المخفَّضة والخادم يحتفظ بالقديمة، فيصل
 * الزبون إلى الدفع فيُرفض بـout_of_stock عن سلّة تبدو سليمة أمامه.
 *
 * ولم يكن لهذا العطل ما يمسكه: اختبارات PHP تختبر الخادم، وهذا خطأ
 * في المتصفّح وحده. هذا الملف هو ما يمسكه.
 */
describe('cart.js — المرآة والخادم', () => {
    /** @type {Array<{url: string, body: any}>} */
    let calls;

    /** يبني استجابة سلّة كما يردّها الخادم. */
    const cartResponse = (items) => ({
        success: true,
        items,
        count: items.reduce((n, i) => n + i.quantity, 0),
    });

    beforeEach(() => {
        calls = [];

        // الصفحة تحمل عناصر السلّة — cartEnabled تفحص #cartSidebar.
        document.body.innerHTML = `
            <div id="cartSidebar"></div>
            <ul id="cart-items-list"></ul>
            <span id="cart-total"></span>`;

        window.BASE_URL   = '';
        window._csrfToken = 'test-token';
        window.escHtml    = (s) => String(s);
        window.showToast  = vi.fn();

        // fetchWithCsrfRetry هو ما تستعمله كل عمليات الكتابة.
        window.fetchWithCsrfRetry = vi.fn(async (url, opts) => {
            const body = JSON.parse(opts.body);
            calls.push({ url, body });
            return cartResponse([]);
        });

        globalThis.fetch = vi.fn(async () => ({
            json: async () => cartResponse([]),
        }));

        loadScript('js/features/cart.js');
    });

    // ════════════════════════════════════════════════════════
    // العقد مع بقيّة الملفات
    // ════════════════════════════════════════════════════════

    it('getCartData تبقى متزامنة — ستّة ملفات تفترض ذلك', () => {
        // تحويلها إلى async يعني await في كل موضع، وكل موضع نسيَه
        // يقرأ Promise كأنه مصفوفة — عطلٌ صامت.
        const result = window.getCartData();

        expect(Array.isArray(result)).toBe(true);
        expect(result).not.toBeInstanceOf(Promise);
    });

    it('كل كتابة تحمل توكن CSRF', async () => {
        await window.cartAdd(7, 12, 2);

        expect(calls).toHaveLength(1);
        expect(calls[0].body.csrf_token).toBe('test-token');
    });

    it('المرآة تُحدَّث من ردّ الخادم لا من تخمين محلي', async () => {
        window.fetchWithCsrfRetry = vi.fn(async () =>
            cartResponse([{ id: 7, variant_id: 12, quantity: 9, price: 5, stock: 20, name: 'X' }])
        );

        await window.cartAdd(7, 12, 1);

        // أُرسلت 1 وردّ الخادم 9 — والمرآة تتبع الردّ. التحديث المتفائل
        // كان سيعرض 1 ويكذب.
        expect(window.getCartData()[0].quantity).toBe(9);
    });

    // ════════════════════════════════════════════════════════
    // العطل الذي أوجد هذا الملف
    // ════════════════════════════════════════════════════════

    it('خفضُ الكمية إلى المتاح يصل الخادم فعلاً', async () => {
        // سلّة فيها 5 قطع، والمخزون الحيّ 2.
        window.fetchWithCsrfRetry = vi.fn(async (url, opts) => {
            calls.push({ url, body: JSON.parse(opts.body) });
            return cartResponse([]);
        });
        globalThis.fetch = vi.fn(async () => ({ json: async () => cartResponse([]) }));

        window.setCartMirror([
            { id: 1, variant_id: 9, quantity: 5, stock: 2, name: 'Widget' },
        ]);

        await window.syncCartWithStock();

        const update = calls.find(c => c.url.includes('/cart/update'));

        expect(update, 'لم يُرسَل تصحيح الكمية إلى الخادم').toBeDefined();
        expect(update.body.variant_id).toBe(9);
        expect(update.body.qty).toBe(2);
    });

    it('النافد يُحذف من الخادم لا من الشاشة وحدها', async () => {
        window.setCartMirror([
            { id: 1, variant_id: 9, quantity: 1, stock: 0, name: 'Sold Out' },
        ]);

        await window.syncCartWithStock();

        const remove = calls.find(c => c.url.includes('/cart/remove'));

        expect(remove, 'لم يُرسَل حذف النافد إلى الخادم').toBeDefined();
        expect(remove.body.variant_id).toBe(9);
    });

    it('السلّة السليمة لا تُطلق أي كتابة', async () => {
        window.setCartMirror([
            { id: 1, variant_id: 9, quantity: 2, stock: 10, name: 'Fine' },
        ]);

        await window.syncCartWithStock();

        // رحلة شبكة بلا سبب على كل فتح للسلّة.
        expect(calls).toHaveLength(0);
    });

    it('السلّة الفارغة لا تُطلق شيئاً', async () => {
        await window.syncCartWithStock();

        expect(calls).toHaveLength(0);
    });

    // ════════════════════════════════════════════════════════
    // الزائر
    // ════════════════════════════════════════════════════════

    it('بلا عناصر سلّة في الصفحة لا تُستدعى أي نقطة', async () => {
        // الزائر: القوالب لا تطبع #cartSidebar أصلاً. وبلا هذا الحارس
        // كان كل تحميل صفحة عامّة يدفع طلباً يردّ 401.
        document.body.innerHTML = '';

        await window.loadCart();
        const added = await window.cartAdd(1, 1, 1);

        expect(added).toBe(false);
        expect(calls).toHaveLength(0);
    });
    it('النقرات السريعة على نفس اللون تُسلسَل لا تتراكم', async () => {
        // بلاغ من الاستعمال: «كبست بسرعة على الإضافة… الرقم يضلّ يزيد».
        //
        // كل نقرة كانت تقرأ المرآة قبل وصول ردّ سابقتها، فترى الكمية
        // القديمة وتمرّ من فحص المخزون في المتصفّح. عشر نقرات = عشرة
        // طلبات متزامنة، ورقمٌ يقفز فوق المتاح ثم يرتدّ.
        let concurrent = 0;
        let peak = 0;

        window.fetchWithCsrfRetry = vi.fn(async (url, opts) => {
            concurrent++;
            peak = Math.max(peak, concurrent);
            calls.push({ url, body: JSON.parse(opts.body) });
            await new Promise(r => setTimeout(r, 20));
            concurrent--;
            return cartResponse([]);
        });

        await Promise.all(Array.from({ length: 6 }, () => window.cartAdd(1, 9, 1)));

        expect(calls).toHaveLength(6);
        expect(peak, 'طلبان متزامنان على نفس الـvariant').toBe(1);
    });

    it('الألوان المختلفة تبقى متوازية', async () => {
        let concurrent = 0;
        let peak = 0;

        window.fetchWithCsrfRetry = vi.fn(async (url, opts) => {
            concurrent++;
            peak = Math.max(peak, concurrent);
            calls.push({ url, body: JSON.parse(opts.body) });
            await new Promise(r => setTimeout(r, 20));
            concurrent--;
            return cartResponse([]);
        });

        // السلسلة لكل variant لا عامّة: تعديل سطر لا يمنع تعديل غيره.
        await Promise.all([window.cartAdd(1, 9, 1), window.cartAdd(2, 10, 1), window.cartAdd(3, 11, 1)]);

        expect(peak).toBeGreaterThan(1);
    });
});
/**
 * شارة العدّاد في الـnavbar.
 *
 * ══════════════════════════════════════════════════════════════
 * العطل الذي أوجد هذه المجموعة — بلاغ من الاستعمال الحقيقي
 * ══════════════════════════════════════════════════════════════
 *
 * «الكارت ما بتتجاوب مع الكمية — إذا في خمس حواسيب بكون على الكارت
 * رقم واحد، حتى لو اختلفت المنتجات.»
 *
 * السبب: updateCounters في js/core/ui.js كانت تقرأ السلّة من
 * `localStorage.getItem("cart")`. وهذا صحيحٌ يوم كانت السلّة محلية،
 * وصار عطلاً صامتاً لحظة انتقالها إلى الخادم: المفتاح لم يعد يُكتب
 * إطلاقاً، فتجمّدت الشارة على آخر قيمة كُتبت قبل الترحيل.
 *
 * ولم يمسكه شيء: الشارة ليست في أي اختبار، وcomposer check كلّه على
 * الخادم. ظهر في الاستعمال وحده.
 */
describe('ui.js — شارة السلّة', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div id="cartSidebar"></div>
            <ul id="cart-items-list"></ul>
            <span id="cart-total"></span>
            <span id="cart-count">0</span>
            <span id="wishlist-count">0</span>`;

        window.BASE_URL = '';
        window.escHtml  = (s) => String(s);
        localStorage.clear();

        loadScript('js/features/cart.js');
        loadScript('js/core/ui.js');
    });

    it('تعرض مجموع الكميات لا عدد السطور', () => {
        window.setCartMirror([
            { id: 1, variant_id: 1, quantity: 5, price: 10, stock: 9, name: 'Laptop' },
        ]);

        // خمس قطع → الشارة 5. كانت تعرض 1.
        expect(document.getElementById('cart-count').textContent).toBe('5');
    });

    it('تجمع الكميات عبر منتجات مختلفة', () => {
        window.setCartMirror([
            { id: 1, variant_id: 1, quantity: 5, price: 10, stock: 9, name: 'Laptop' },
            { id: 2, variant_id: 2, quantity: 3, price: 20, stock: 9, name: 'Phone' },
        ]);

        expect(document.getElementById('cart-count').textContent).toBe('8');
    });

    it('تتجاهل localStorage تماماً', () => {
        // بقايا ما قبل الترحيل: كانت هذه هي القيمة التي تتجمّد عليها
        // الشارة إلى الأبد.
        localStorage.setItem('cart', JSON.stringify([{ id: 99, quantity: 1 }]));

        window.setCartMirror([
            { id: 1, variant_id: 1, quantity: 4, price: 10, stock: 9, name: 'X' },
        ]);

        expect(document.getElementById('cart-count').textContent).toBe('4');
    });

    it('السلّة الفارغة تعطي صفراً', () => {
        window.setCartMirror([]);

        expect(document.getElementById('cart-count').textContent).toBe('0');
    });

    it('قائمة الأمنيات تبقى على localStorage — لم تنتقل', () => {
        localStorage.setItem('wishlist', JSON.stringify([1, 2, 3]));

        window.updateCounters();

        expect(document.getElementById('wishlist-count').textContent).toBe('3');
    });
});
