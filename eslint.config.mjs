// ══════════════════════════════════════════════════════════════
// ESLint — Cairo Store
// ══════════════════════════════════════════════════════════════
//
// The project has no build step for the JS: 34 files are loaded by <script> tags and share
// one global scope, with 62 functions hung on window deliberately so other files can see
// them. Which is why `sourceType: 'script'` rather than 'module', and why `no-undef` is set
// against the browser globals — not against an assumption of ES modules.
//
// The rules here describe the code as it is, and then tighten around it. A rule that fails
// a hundred files on its first run fixes nothing — it is switched off within a day.

import globals from 'globals';

export default [
  // ⚠️ The build output is excluded. `npm run lint:js` used to fail every time with 182
  // errors — all of them in public/js/dist/*.js, that is, in the **minified** bundles:
  // single-letter names, assignment inside an if, use before definition. That is what the
  // minifier does on purpose, and not something to be fixed.
  //
  // And the effect was that the gate guarded nothing: permanently red output reads as
  // noise, so a real error in a source file goes unnoticed inside it. (dist is tracked
  // deliberately — see .gitignore — so its presence is not a local accident.)
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
        // The project's functions shared through the global scope. Listing them here is
        // what makes no-undef useful: any name not among them is a real typo.
        bootstrap: 'readonly',
        Swal: 'readonly',
        hcaptcha: 'readonly',
        URLROOT: 'readonly',

        // ── The project's shared global scope ───────────────
        //
        // 76 names, extracted **mechanically** from the code itself: every `window.X =`
        // and every top-level function or constant. A hand-written list would have gone
        // stale at the first new file.
        //
        // Without this list, no-undef produces two hundred and seventy-seven errors, all
        // of them false — and a rule that fails everything is switched off within a day,
        // losing its real value: a name that is **not** here is an actual typo.
        //
        // 'writable' rather than 'readonly': the files really do reassign them.
        //
        // To update it: see build/, or regenerate it the same way.
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
        sameId: 'writable',
        sameVariant: 'writable',
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
      // ── Real errors ───────────────────────────────────────
      'no-undef': 'error',
      'no-unused-vars': ['error', { args: 'none', varsIgnorePattern: '^_' }],
      // builtinGlobals: false is deliberate. The shared names are declared above as
      // globals **and are also** genuinely defined in one file — so leaving the check at
      // its default makes every original definition a "redeclaration" of the global. That
      // is a wrong description of the structure: the file that defines a name is its
      // source, not a copy of it.
      'no-redeclare': ['error', { builtinGlobals: false }],
      'no-dupe-keys': 'error',
      'no-dupe-args': 'error',
      'no-unreachable': 'error',
      'no-fallthrough': 'error',
      'valid-typeof': 'error',
      'use-isnan': 'error',

      // TDZ: a fault that actually happened in account.js — a variable used before its
      // let declaration, so it blew up at run time rather than at analysis time.
      'no-use-before-define': ['error', { functions: false, classes: true, variables: true }],

      // Assignment inside a condition: it is almost never intended.
      'no-cond-assign': ['error', 'always'],

      // ── What prevents silent faults ───────────────────────
      // ⚠️ A warning rather than an error, **and that has a reason, not a shrug**.
      //
      // Thirty-two cases, most of them one pattern:
      //     allNotifs.find(n => n.id == id)
      // where `id` comes from a dataset in the DOM — that is, **always a string** — and
      // `n.id` is a number from JSON. So the loose comparison here is what makes the code
      // work, and converting it to === blindly breaks every lookup by id in the project:
      // the notifications, the cart, the wishlist and the orders.
      //
      // The right fix is an explicit conversion at the boundary (Number(id)), a change that
      // needs each site reviewed on its own rather than a bulk replacement. So it is left
      // as a visible warning instead of an error that forces the whole rule off.
      eqeqeq: ['warn', 'smart'],
      'no-implicit-globals': 'off', // The global scope is intended here
      'no-var': 'warn',
      'prefer-const': 'warn',

      // A console.log forgotten in production is noise rather than a fault — a warning,
      // not an error.
      'no-console': ['warn', { allow: ['warn', 'error'] }],
    },
  },
];
