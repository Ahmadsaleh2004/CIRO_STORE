// ══════════════════════════════════════════════════════════════
// js/features/checkout.js — صفحة إتمام الطلب (ثلاث خطوات)
// ══════════════════════════════════════════════════════════════
//
// كان هذا الملف كتلة <script> مضمّنة بطول 157 سطراً داخل
// app/views/checkout/checkout.php. الكتلة كانت تحقن أربع قيم PHP
// (URLROOT · توكن CSRF · مفتاح idempotency · العناوين المحفوظة) ثم
// تكتب منطق الصفحة كاملاً بعدها.
//
// القيم الأربع تصل الآن عبر data-* على <main id="main-content">، وكل ما
// تحت هذا السطر منطق خالص لا يعرف شيئاً عن PHP.
//
// كل شيء داخل IIFE عن قصد: النسخة المضمّنة كانت تعرّف
// URLROOT و CSRF_TOKEN و postJson و goTo و buildReview
// **في النطاق العام**. مقبول في كتلة تخصّ صفحة واحدة، لكنه في ملف
// خارجي يُحمَّل مع بقية ملفات المتجر يعني تصادم أسماء محتملاً — و
// `const URLROOT` في نطاق عام يرمي SyntaxError لو أعلنه ملف آخر.

(function () {
    'use strict';

    // الاختيار بالسمة لا بالـid عن قصد: السمة تصف ما نبحث عنه بالضبط،
    // فلا تتأثر بأي معرّف مكرَّر في الصفحة ولا تفترض وسماً بعينه.
    const root = document.querySelector('[data-checkout-urlroot]');
    if (!root) return; // لسنا في صفحة الدفع

    // ── بيانات الصفحة (من data-* لا من PHP مضمّن) ───────────────
    const URLROOT         = root.dataset.checkoutUrlroot;
    const CSRF_TOKEN      = root.dataset.checkoutCsrf;
    const IDEMPOTENCY_KEY = root.dataset.checkoutIdempotency;

    let SAVED_ADDRESSES = [];
    try {
        SAVED_ADDRESSES = JSON.parse(root.dataset.checkoutAddresses || '[]');
    } catch (e) {
        console.error('checkout: تعذّر تحليل العناوين المحفوظة', e);
    }

    // ── عناصر DOM ───────────────────────────────────────────────
    const steps     = [1, 2, 3].map(n => document.getElementById(`step-${n}`));
    const stepItems = [1, 2, 3].map(n => document.getElementById(`si-${n}`));

    function goTo(n) {
        steps.forEach((s, i) => {
            s.classList.toggle('active', i + 1 === n);
            stepItems[i].classList.remove('active', 'done');
            if (i + 1 < n)  stepItems[i].classList.add('done');
            if (i + 1 === n) stepItems[i].classList.add('active');
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ── إرسال JSON عبر شبكة أمان CSRF المركزية ───────────────────
    // كان هنا غلاف محلي اسمه fetchWithCsrf يرسل الطلب بلا إعادة محاولة،
    // ويبرّر نفسه بأن fetchWithCsrfRetry «تدعم FormData وurlencoded فقط
    // وهذه الصفحة ترسل JSON». **كان صحيحاً حين كُتب، وسقط في المرحلة
    // 6ب-1** التي علّمت تلك الدالة إعادة بناء أجسام JSON بالضبط.
    //
    // ولماذا يهمّ هنا تحديداً: التوكن في هذا المشروع واحد لكل جلسة،
    // فيكفي أن يفشل فورم في تبويب آخر ليُدوَّر التوكن ويصير توكن هذه
    // الصفحة باطلاً — فيضيع الطلب. وضياع طلب هنا يعني **ضياع عملية
    // شراء مكتملة** بعد أن ملأ المستخدم ثلاث خطوات.
    //
    // وإعادة المحاولة آمنة على /checkout: الكنترولر يتحقق من التوكن
    // **قبل** فحص idempotency وقبل إنشاء أي طلب، فالرفض يعني أن شيئاً
    // لم يُنشأ بعد. ومفتاح idempotency يبقى في الجسم المُعاد بناؤه
    // فيحمي من ازدواج الطلب على أي حال.
    //
    // csrf.js محمَّل في فوتر المتجر قبل هذا الملف (كلاهما defer، والتنفيذ
    // بترتيب المستند)، فالدالة معرَّفة يقيناً حين يعمل ما تحت.
    function postJson(url, payload) {
        return fetchWithCsrfRetry(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
    }

    // ── الحصول على العنوان المختار ───────────────────────────────
    function getSelectedAddressId() {
        const checked = document.querySelector('input[name="addr_choice"]:checked');
        if (checked) return parseInt(checked.value);
        // إذا لم يكن هناك عنوان محفوظ فنبني عنواناً جديداً
        const full = document.getElementById('newAddrFull')?.value.trim();
        return full ? 'new' : null;
    }

    // ── بناء ملخص الطلب ─────────────────────────────────────────
    function buildReview() {
        const cart    = window.getCartData ? window.getCartData() : [];
        const list    = document.getElementById('reviewCartList');
        const totalEl = document.getElementById('reviewTotal');
        const addrEl  = document.getElementById('reviewAddress');
        const payEl   = document.getElementById('reviewPayment');

        // ⚠️ `quantity` لا `qty`. المفتاح في السلّة اسمه quantity في
        // المواضع الثلاثة التي تكتبها (products-catalog · product-details
        // · wishlist)، وكان هذا السطر يقرأ `item.qty` — أي undefined.
        // فكان `price * undefined` يساوي NaN، وشاشة المراجعة الأخيرة
        // قبل الدفع تعرض «$NaN» في كل سطر وفي الإجمالي.
        let total = 0;
        list.innerHTML = cart.map(item => {
            const qty  = Number(item.quantity) || 0;
            const line = Number(item.price) * qty;
            total += line;
            return `<li class="d-flex justify-content-between mb-2 small">
            <span>${item.name}${item.color_name ? ' — ' + item.color_name : ''} × ${qty}</span>
            <span>$${line.toFixed(2)}</span>
        </li>`;
        }).join('') || '<li class="text-muted">Cart is empty.</li>';

        totalEl.textContent = '$' + total.toFixed(2);

        // عرض العنوان المختار
        const addrId = getSelectedAddressId();

        // المقارنة صارمة بعد توحيد النوع. المعرّفات تصل من مصدرين
        // مختلفين — `parseInt` من قيمة الراديو، وJSON من data-* — فكان
        // `==` هو ما يجسر بينهما. و`getSelectedAddressId` قد تُرجع
        // السلسلة 'new' أو null، وكلتاهما تصير NaN فلا تطابق أي معرّف،
        // وهو السلوك المطلوب: لا عنوان محفوظاً مختاراً.
        const addr = SAVED_ADDRESSES.find(a => Number(a.id) === Number(addrId));
        addrEl.textContent = addr
            ? [addr.label, addr.full_address, addr.city, addr.country].filter(Boolean).join(', ')
            : (document.getElementById('newAddrFull')?.value.trim() || '—');

        // طريقة الدفع
        const pay = document.querySelector('input[name="payment_method"]:checked');
        payEl.textContent = pay?.value === 'cash_on_delivery' ? 'Cash on Delivery' : pay?.value || '—';
    }

    // ── ناقلات الخطوات ──────────────────────────────────────────
    document.getElementById('toStep2Btn')?.addEventListener('click', () => {
        if (!getSelectedAddressId()) {
            Swal.fire({ icon: 'warning', title: 'Address Required', text: 'Please select or add a delivery address.' });
            return;
        }
        goTo(2);
    });
    document.getElementById('backToStep1Btn')?.addEventListener('click', () => goTo(1));
    document.getElementById('toStep3Btn')?.addEventListener('click', () => { buildReview(); goTo(3); });
    document.getElementById('backToStep2Btn')?.addEventListener('click', () => goTo(2));

    // ── إضافة عنوان جديد ────────────────────────────────────────
    document.getElementById('saveNewAddrBtn')?.addEventListener('click', async () => {
        const label   = document.getElementById('newAddrLabel').value.trim()   || 'Home';
        const phone   = document.getElementById('newAddrPhone').value.trim()   || '';
        const country = document.getElementById('newAddrCountry').value.trim() || '';
        const city    = document.getElementById('newAddrCity').value.trim()    || '';
        const full    = document.getElementById('newAddrFull').value.trim()    || '';
        const isDefault = document.getElementById('newAddrDefault')?.checked ? 1 : 0;

        if (!full) {
            Swal.fire({ icon: 'warning', text: 'Please enter the full address.' });
            return;
        }

        const res = await postJson(URLROOT + '/user/addresses', {
            csrf_token: CSRF_TOKEN,
            label, phone_number: phone, country, city, full_address: full, is_default: isDefault,
        });

        if (res.success) {
            Swal.fire({ icon: 'success', text: 'Address saved!' }).then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', text: res.message || 'Could not save address.' });
        }
    });

    // ── ترجمة السلّة إلى شكل الـAPI ──────────────────────────────
    //
    // السلّة تُخزَّن في localStorage بشكلها الخاص {id, quantity, …}،
    // والـAPI موثَّق بشكل آخر {product_id, qty, …}. وكان الطرفان
    // يتكلّمان بلا ترجمة: الخادم يقرأ product_id فيجده غائباً، فيسقط كل
    // عنصر، فيتلقّى الزبون «Invalid items in cart» بعد ثلاث خطوات.
    // **لم يكن أحد يستطيع إتمام طلب.**
    //
    // الترجمة هنا لا في الخادم عن قصد: شكل التخزين المحلي شأن العميل
    // وحده وقد يتغيّر، وشكل الـAPI عقد منشور في OpenAPI. وتعليم الخادم
    // قبول الاسمين كان سيثبّت التسميتين معاً إلى الأبد.
    //
    // ⚠️ `shown_price` هو السعر المعروض لا السعر المعتمَد. الخادم
    // يقارنه بسعر القاعدة ويرفض الطلب عند الاختلاف — ولا يخزّنه.
    function toOrderItems(cart) {
        return cart.map(item => ({
            product_id:  Number(item.id),
            variant_id:  item.variant_id ?? null,
            qty:         Number(item.quantity) || 0,
            shown_price: Number(item.price),
        }));
    }

    // ملاحظة: كانت هنا variantKey و sameLine — تطابقان سطر السلّة
    // المحلية بسطر جاء من الخادم لتصحيح سعره في مكانه. سقطتا حين
    // انتقلت السلّة إلى القاعدة: لم يعد هناك ما يُطابَق، فالخادم يُرجع
    // السلّة كاملةً بأسعارها الحيّة وتُستبدل المرآة بها.

    /**
     * يُحدّث السلّة بعد رفض الطلب لتغيّر سعر.
     *
     * ⚠️ صارت تُعيد الجلب من الخادم بدل الكتابة محلياً. كانت تكتب
     * السعر في نسخة المتصفّح — وذلك كان صحيحاً يوم كانت السلّة في
     * localStorage، وصار خطأً حين انتقلت إلى القاعدة: كتابةٌ في المرآة
     * لا تصل الأصل، فتُظهر للزبون سعراً لا يعرفه الخادم.
     *
     * وإعادة الجلب أصدق من الترقيع: `/cart` تُرجع السعر الحيّ لكل سطر
     * أصلاً، فما بعدها هو ما سيُحاسَب عليه فعلاً.
     */
    async function applyServerPrices(serverItems) {
        if (!serverItems.length || !window.loadCart) return;

        await window.loadCart();
    }

    /** جدول «قبل ← بعد» داخل الحوار. الأسماء من القاعدة، فتُهرَّب. */
    function buildPriceChangeHtml(serverItems) {
        const esc = window.escHtml || (s => String(s));
        const rows = serverItems.map(i => `
            <li class="d-flex justify-content-between gap-3 small">
                <span>${esc(i.name)}</span>
                <span><s class="text-muted">$${Number(i.shown_price).toFixed(2)}</s>
                      &nbsp;<strong>$${Number(i.price).toFixed(2)}</strong></span>
            </li>`).join('');

        return `<p class="mb-2">These prices changed while your cart was open:</p>
                <ul class="list-unstyled text-start mb-0">${rows}</ul>`;
    }

    /** يعيد زرّ الطلب إلى حالته — كان مكرّراً حرفياً في ثلاثة مواضع. */
    function resetPlaceButton() {
        const btn = document.getElementById('placeOrderBtn');
        if (!btn) return;
        btn.disabled    = false;
        btn.textContent = '✅ Place Order';
    }

    // ── تنفيذ الطلب ──────────────────────────────────────────────
    document.getElementById('placeOrderBtn')?.addEventListener('click', async () => {
        // السلّة تصل من الخادم عند تحميل الصفحة. وإن لم تكن قد وصلت
        // بعد — اتصال بطيء، أو نقرة سريعة — نجلبها الآن بدل أن نقول
        // للزبون «سلّتك فارغة» وهي ليست كذلك.
        if (window.getCartData && !window.getCartData().length && window.loadCart) {
            await window.loadCart();
        }

        const cart = window.getCartData ? window.getCartData() : [];
        if (!cart.length) {
            Swal.fire({ icon: 'warning', text: 'Your cart is empty.' });
            return;
        }

        const addrId = getSelectedAddressId();
        if (!addrId || addrId === 'new') {
            Swal.fire({ icon: 'warning', text: 'Please select a saved delivery address.' });
            return;
        }

        const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value || 'cash_on_delivery';

        document.getElementById('placeOrderBtn').disabled = true;
        document.getElementById('placeOrderBtn').textContent = '⏳ Placing Order…';

        try {
            const res = await postJson(URLROOT + '/checkout', {
                csrf_token:       CSRF_TOKEN,
                address_id:       addrId,
                payment_method:   paymentMethod,
                idempotency_key:  IDEMPOTENCY_KEY,
                items:            toOrderItems(cart),
            });

            // ── تغيّر السعر: نحدّث السلّة ونُعيد الزبون ليقرّر ────────
            //
            // الخادم رفض الطلب كاملاً وأعاد الأسعار الصحيحة. الاكتشاف
            // بـerror_code لا بنصّ الرسالة — نفس عقد csrf_invalid.
            if (!res.success && res.error_code === 'price_changed') {
                await applyServerPrices(res.items || []);
                resetPlaceButton();
                goTo(3);
                buildReview();
                Swal.fire({
                    icon:  'warning',
                    title: 'Prices Updated',
                    html:  buildPriceChangeHtml(res.items || []),
                    confirmButtonText: 'Review Cart',
                });
                return;
            }

            if (res.success) {
                // ⚠️ window.clearCart غير معرَّفة في أي ملف — الحارس هنا
                // يجعل الاستدعاء بلا أثر. سلوك قائم نُقل كما هو.
                if (window.clearCart) window.clearCart();
                Swal.fire({
                    icon: 'success', title: '✅ Order Placed!',
                    text: res.message, timer: 2000, showConfirmButton: false
                }).then(() => {
                    window.location.href = res.redirect || URLROOT;
                });
            } else {
                resetPlaceButton();
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        } catch {
            resetPlaceButton();
            Swal.fire({ icon: 'error', text: 'Network error. Please try again.' });
        }
    });
})();
