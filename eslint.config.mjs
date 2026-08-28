// ══════════════════════════════════════════════════════════════
// ESLint — Cairo Store
// ══════════════════════════════════════════════════════════════
//
// المشروع بلا خطوة بناء للـJS: 34 ملفاً تُحمَّل بوسوم <script> وتتشارك
// نطاقاً عاماً واحداً، و62 دالة معلّقة على window عمداً كي تراها ملفات
// أخرى. ولهذا `sourceType: 'script'` لا 'module'، و`no-undef` مضبوطة
// على globals المتصفح — لا على افتراض وحدات ES.
//
// القواعد هنا تصف الكود كما هو، ثم تشدّ عليه. القاعدة التي تُفشل مئة
// ملف في أول تشغيل لا تُصلح شيئاً — تُطفأ بعد يوم.

import globals from 'globals';

export default [
  // ⚠️ ناتج البناء يُستثنى. كان `npm run lint:js` يفشل دائماً بـ182
  // خطأً — كلها في public/js/dist/*.js، أي في الحزم **المصغَّرة**:
  // أسماء من حرف واحد، وإسناد داخل if، واستعمال قبل التعريف. هذا ما
  // يفعله المصغِّر عمداً، وليس شيئاً يُصلَح.
  //
  // والأثر أن البوابة لم تكن تحرس شيئاً: مخرجات حمراء دائماً تُقرأ
  // كضجيج، فلا يُلاحَظ فيها خطأ حقيقي في ملف مصدري. (dist متتبَّع عن
  // قصد — انظر .gitignore — فوجوده ليس مصادفة محلية.)
  {
    ignores: ['public/js/dist/**'],
  },
  {
    files: ['public/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'script',
      globals: {
        ...globals.browser,
        // دوال المشروع المشتركة عبر النطاق العام. تعدادها هنا يجعل
        // no-undef مفيدة: أي اسم غير مذكور خطأ مطبعي حقيقي.
        bootstrap: 'readonly',
        Swal: 'readonly',
        hcaptcha: 'readonly',
        URLROOT: 'readonly',

        // ── النطاق العام المشترك للمشروع ────────────────────
        //
        // 76 اسماً، مستخرجة **آلياً** من الكود نفسه: كل `window.X =`
        // وكل دالة أو ثابت في المستوى الأعلى. القائمة اليدوية كانت
        // ستتقادم عند أول ملف جديد.
        //
        // بلا هذه القائمة تنتج no-undef مئتين وسبعة وسبعين خطأً كلها
        // كاذبة — والقاعدة التي تُفشل كل شيء تُطفأ بعد يوم فتُفقد
        // فائدتها الحقيقية: الاسم الذي **ليس** هنا خطأ مطبعي فعلي.
        //
        // 'writable' لا 'readonly': الملفات تُعيد الإسناد عليها فعلاً.
        //
        // لتحديثها: راجع build/ أو أعد توليدها بالنمط نفسه.
        REGULAR_USER_FLAG: 'writable',
        _csrfToken: 'writable',
        addToCartDB: 'writable',
        adminAuth: 'writable',
        adminHcaptchaOnLoad: 'writable',
        adminNotifDeleteOne: 'writable',
        adminNotifOpen: 'writable',
        allCategoriesData: 'writable',
        applySavedTheme: 'writable',
        buildProductPicture: 'writable',
        canAddToCart: 'writable',
        categoryDeleteTargetId: 'writable',
        changeQtyDB: 'writable',
        changeWishlistQty: 'writable',
        checkSignupFormValidity: 'writable',
        currentNotifyTarget: 'writable',
        dismissNotif: 'writable',
        escAttr: 'writable',
        escHtml: 'writable',
        escapeHtml: 'writable',
        fetchWithCsrfRetry: 'writable',
        filterStatus: 'writable',
        fixBodyPadding: 'writable',
        fixImagePath: 'writable',
        formatRelativeTime: 'writable',
        getCartData: 'writable',
        goToOrderDetails: 'writable',
        handleReleaseOrder: 'writable',
        handleTakeIt: 'writable',
        headerValue: 'writable',
        highlightNavIcons: 'writable',
        imagePathOrEmpty: 'writable',
        initAddProductForm: 'writable',
        initBackToTop: 'writable',
        initEditProductForm: 'writable',
        initImageFallbacks: 'writable',
        initOrderDetails: 'writable',
        initPageTransitions: 'writable',
        initProductsListInteractions: 'writable',
        initScrollReveal: 'writable',
        initializeTheme: 'writable',
        liveStockData: 'writable',
        logoutAdmin: 'writable',
        logoutUser: 'writable',
        openDeleteConfirm: 'writable',
        openEditAddressModal: 'writable',
        openNotifDetail: 'writable',
        openNotifyModal: 'writable',
        openPermModal: 'writable',
        rebuildBodyWithToken: 'writable',
        refreshCartUI: 'writable',
        renderCart: 'writable',
        renderCategoryPickerList: 'writable',
        renderHomeSections: 'writable',
        renderSelectedChips: 'writable',
        renderSlider: 'writable',
        saveCart: 'writable',
        selectedCategoryIds: 'writable',
        setTheme: 'writable',
        showLoading: 'writable',
        showSkeletons: 'writable',
        showToast: 'writable',
        startRetryCountdown: 'writable',
        stockBadge: 'writable',
        submitReport: 'writable',
        switchAuthModal: 'writable',
        syncCartWithStock: 'writable',
        toggleBothPasswords: 'writable',
        togglePassword: 'writable',
        toggleWishlist: 'writable',
        updateButtonState: 'writable',
        updateCounters: 'writable',
        updateCsrfToken: 'writable',
        updateDelivery: 'writable',
        validateSignUp: 'writable',
        wishlist: 'writable',
      },
    },
    rules: {
      // ── أخطاء حقيقية ──────────────────────────────────────
      'no-undef': 'error',
      'no-unused-vars': ['error', { args: 'none', varsIgnorePattern: '^_' }],
      // builtinGlobals: false مقصود. الأسماء المشتركة مُعلَنة أعلاه
      // كـglobals **وهي أيضاً** معرَّفة فعلاً في ملف واحد — فبقاء
      // الفحص على الافتراضي يجعل كل تعريف أصلي «إعادة تعريف» للعام.
      // هذا وصف خاطئ للبنية: الملف الذي يُعرّفها هو مصدرها لا ناسخها.
      'no-redeclare': ['error', { builtinGlobals: false }],
      'no-dupe-keys': 'error',
      'no-dupe-args': 'error',
      'no-unreachable': 'error',
      'no-fallthrough': 'error',
      'valid-typeof': 'error',
      'use-isnan': 'error',

      // TDZ: عطل وقع فعلاً في account.js — متغيّر استُعمل قبل تعريفه
      // بـlet فانفجر وقت التشغيل لا وقت التحليل.
      'no-use-before-define': ['error', { functions: false, classes: true, variables: true }],

      // إسناد داخل شرط: يكاد لا يكون مقصوداً أبداً.
      'no-cond-assign': ['error', 'always'],

      // ── ما يمنع أعطالاً صامتة ─────────────────────────────
      // ⚠️ تحذير لا خطأ، **ولهذا سبب لا تهاون**.
      //
      // اثنتان وثلاثون حالة، وأغلبها من نمط واحد:
      //     allNotifs.find(n => n.id == id)
      // حيث `id` قادم من dataset في الـDOM — أي **نصّ دائماً** — و
      // `n.id` رقم من JSON. فالمقارنة الفضفاضة هنا هي ما يجعل الكود
      // يعمل، وتحويلها إلى === تحويلاً أعمى يكسر كل بحث بالمعرّف في
      // المشروع: الإشعارات والسلّة والمفضّلة والطلبات.
      //
      // الإصلاح الصحيح تحويل النوع صراحةً عند الحدّ (Number(id))، وهو
      // تغيير يحتاج مراجعة كل موضع على حدة لا استبدالاً جماعياً.
      // فتُترك تحذيراً مرئياً بدل خطأ يُجبر على إطفاء القاعدة كلها.
      eqeqeq: ['warn', 'smart'],
      'no-implicit-globals': 'off', // النطاق العام مقصود هنا
      'no-var': 'warn',
      'prefer-const': 'warn',

      // console.log منسيّ في الإنتاج ضجيج لا عطل — تحذير لا خطأ.
      'no-console': ['warn', { allow: ['warn', 'error'] }],
    },
  },
];
