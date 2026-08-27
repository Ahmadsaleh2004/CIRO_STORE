import { beforeAll, describe, expect, it } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/core/csrf.js — شبكة أمان CSRF في المتصفح.
 *
 * هذا الملف له **تاريخ أعطال حقيقي**، وكلّها من صنف واحد: الفشل
 * الصامت. الشبكة تبدو عاملة لأن الطلب الأول ينجح عادةً، ولا يظهر
 * العطل إلا حين تُطلب إعادة المحاولة فعلاً — أي عند انتهاء التوكن،
 * وهو ما لا يحدث في أي اختبار يدوي قصير.
 *
 * موثّق في الملف نفسه: «النسخة السابقة لم تكن **تتجاهله** بل
 * **تفسده**: كل جسم نصّي كان يمرّ على URLSearchParams، فيتحوّل
 * {"csrf_token":"…"} إلى مفتاح واحد مُرمَّز لا يستطيع json_decode
 * قراءته. أي أن إعادة المحاولة كانت تفشل حتماً لكل نقطة ترسل JSON —
 * وهي صفحة الدفع و admin/my-info وغيرها».
 *
 * rebuildBodyWithToken دالة داخلية لا تُصدَّر إلى window، فتُستخرج
 * بتنفيذ الملف ثم قراءتها من النطاق العام — وهو ما يجعل هذا الاختبار
 * ممكناً أصلاً في بنية بلا وحدات.
 */
describe('csrf.js — rebuildBodyWithToken', () => {
    let rebuild;

    beforeAll(() => {
        loadScript('js/core/csrf.js');
        // الدالة معرَّفة في المستوى الأعلى، فتصير عامّة بعد التنفيذ.
        rebuild = globalThis.rebuildBodyWithToken;
    });

    it('الدالة متاحة للاختبار', () => {
        expect(typeof rebuild).toBe('function');
    });

    describe('FormData — الشكل الأكثر شيوعاً في نماذج المشروع', () => {
        it('يستبدل التوكن ويُبقي بقية الحقول', () => {
            const body = new FormData();
            body.append('csrf_token', 'قديم');
            body.append('name', 'أحمد');
            body.append('qty', '3');

            const out = rebuild({ body }, 'جديد');

            expect(out.ok).toBe(true);
            expect(out.body.get('csrf_token')).toBe('جديد');
            expect(out.body.get('name')).toBe('أحمد');
            expect(out.body.get('qty')).toBe('3');
        });

        it('يضيف التوكن إن لم يكن موجوداً', () => {
            const body = new FormData();
            body.append('name', 'x');

            const out = rebuild({ body }, 'جديد');

            expect(out.ok).toBe(true);
            expect(out.body.get('csrf_token')).toBe('جديد');
        });
    });

    describe('URLSearchParams', () => {
        it('يستبدل التوكن ويُبقي الباقي', () => {
            const body = new URLSearchParams({ csrf_token: 'قديم', id: '9' });

            const out = rebuild({ body }, 'جديد');

            expect(out.ok).toBe(true);
            expect(new URLSearchParams(out.body.toString()).get('csrf_token')).toBe('جديد');
            expect(new URLSearchParams(out.body.toString()).get('id')).toBe('9');
        });
    });

    describe('جسم JSON — الشكل الذي كان يُفسَد', () => {
        /**
         * الاختبار الذي يحرس العطل الأصلي.
         *
         * لو عاد الجسم النصّي يمرّ على URLSearchParams، لصار الناتج
         * مفتاحاً واحداً مُرمَّزاً بـ%7B%22csrf_token%22… ولفشل
         * json_decode على الخادم — فتفشل إعادة المحاولة حتماً لكل نقطة
         * ترسل JSON.
         */
        it('يُبقي الجسم JSON صالحاً ويستبدل التوكن داخله', () => {
            const body = JSON.stringify({ csrf_token: 'قديم', items: [1, 2, 3] });

            const out = rebuild({ body, headers: { 'Content-Type': 'application/json' } }, 'جديد');

            expect(out.ok).toBe(true);

            // الناتج يجب أن يبقى JSON قابلاً للتحليل — لا نصّاً مُرمَّزاً.
            const parsed = JSON.parse(out.body);
            expect(parsed.csrf_token).toBe('جديد');
            expect(parsed.items).toEqual([1, 2, 3]);
        });

        it('يضيف التوكن إلى جسم JSON لا يحمله', () => {
            const body = JSON.stringify({ items: [] });

            const out = rebuild({ body, headers: { 'Content-Type': 'application/json' } }, 'جديد');

            expect(out.ok).toBe(true);
            expect(JSON.parse(out.body).csrf_token).toBe('جديد');
        });

        it('يقرأ ترويسة النوع مهما كانت حالة أحرفها', () => {
            const body = JSON.stringify({ a: 1 });

            const out = rebuild({ body, headers: { 'content-type': 'application/json; charset=utf-8' } }, 'ج');

            expect(out.ok).toBe(true);
            expect(JSON.parse(out.body).csrf_token).toBe('ج');
        });

        it('يقبل الترويسات ككائن Headers', () => {
            const headers = new Headers({ 'Content-Type': 'application/json' });
            const out = rebuild({ body: JSON.stringify({ a: 1 }), headers }, 'ج');

            expect(out.ok).toBe(true);
            expect(JSON.parse(out.body).csrf_token).toBe('ج');
        });

        it('يقبل الترويسات كمصفوفة أزواج', () => {
            const headers = [['Content-Type', 'application/json']];
            const out = rebuild({ body: JSON.stringify({ a: 1 }), headers }, 'ج');

            expect(out.ok).toBe(true);
            expect(JSON.parse(out.body).csrf_token).toBe('ج');
        });
    });

    describe('ما لا يُعرف كيف يُعاد بناؤه', () => {
        /**
         * ok=false تعني «لا تُعِد المحاولة». وهذا أصحّ من محاولة عمياء:
         * إعادة إرسال جسم أُفسد في الطريق تُنتج طلباً خاطئاً بصمت بدل
         * خطأ واضح.
         */
        it('يرفض إعادة بناء ما لا يفهمه بدل تخريبه', () => {
            const out = rebuild({ body: new Blob(['x']) }, 'ج');
            expect(out.ok).toBe(false);
        });

        it('يرفض الجسم الغائب', () => {
            const out = rebuild({}, 'ج');
            expect(out.ok).toBe(false);
        });
    });
});
