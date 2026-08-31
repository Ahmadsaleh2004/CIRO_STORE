import { beforeEach, describe, expect, it, vi } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/features/cart.js — the cart's storage layer.
 *
 * ══════════════════════════════════════════════════════════════
 * Why this file exists
 * ══════════════════════════════════════════════════════════════
 *
 * The cart moved from localStorage to the server, and the in-memory copy became a **mirror**
 * of the `cart_items` table. And a mirror creates a new class of fault that was not possible
 * before it: the screen disagreeing with the database.
 *
 * And that actually happened in the first version of syncCartWithStock: it modified the
 * quantity inside the mirror (and getCartData returns it by reference), then compared the
 * modified value with itself to decide what to send to the server — so the condition
 * `x < x` was always false, and nothing was ever sent.
 *
 * The effect: the screen shows the reduced quantity while the server keeps the old one, so
 * the customer reaches checkout and is refused with out_of_stock over a cart that looks
 * sound in front of them.
 *
 * And nothing could have caught this fault: the PHP tests test the server, and this is an
 * error in the browser alone. This file is what catches it.
 */
describe('cart.js — the mirror and the server', () => {
    /** @type {Array<{url: string, body: any}>} */
    let calls;

    /** Builds a cart response in the shape the server returns. */
    const cartResponse = (items) => ({
        success: true,
        items,
        count: items.reduce((n, i) => n + i.quantity, 0),
    });

    beforeEach(() => {
        calls = [];

        // The page carries the cart's elements — cartEnabled checks for #cartSidebar.
        document.body.innerHTML = `
            <div id="cartSidebar"></div>
            <ul id="cart-items-list"></ul>
            <span id="cart-total"></span>`;

        window.BASE_URL   = '';
        window._csrfToken = 'test-token';
        window.escHtml    = (s) => String(s);
        window.showToast  = vi.fn();

        // fetchWithCsrfRetry is what every write operation uses.
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
    // The contract with the other files
    // ════════════════════════════════════════════════════════

    it('getCartData stays synchronous — six files assume it', () => {
        // Making it async means an await at every site, and every site that forgets one
        // reads a Promise as if it were an array — a silent fault.
        const result = window.getCartData();

        expect(Array.isArray(result)).toBe(true);
        expect(result).not.toBeInstanceOf(Promise);
    });

    it('every write carries a CSRF token', async () => {
        await window.cartAdd(7, 12, 2);

        expect(calls).toHaveLength(1);
        expect(calls[0].body.csrf_token).toBe('test-token');
    });

    it('the mirror is updated from the server’s reply, not from a local guess', async () => {
        window.fetchWithCsrfRetry = vi.fn(async () =>
            cartResponse([{ id: 7, variant_id: 12, quantity: 9, price: 5, stock: 20, name: 'X' }])
        );

        await window.cartAdd(7, 12, 1);

        // 1 was sent and the server replied 9 — and the mirror follows the reply. An
        // optimistic update would have shown 1 and lied.
        expect(window.getCartData()[0].quantity).toBe(9);
    });

    // ════════════════════════════════════════════════════════
    // The fault that brought this file into being
    // ════════════════════════════════════════════════════════

    it('reducing the quantity to what is available actually reaches the server', async () => {
        // A cart holding 5 units, with live stock of 2.
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

        expect(update, 'the quantity correction was never sent to the server').toBeDefined();
        expect(update.body.variant_id).toBe(9);
        expect(update.body.qty).toBe(2);
    });

    it('a sold-out line is removed from the server, not from the screen alone', async () => {
        window.setCartMirror([
            { id: 1, variant_id: 9, quantity: 1, stock: 0, name: 'Sold Out' },
        ]);

        await window.syncCartWithStock();

        const remove = calls.find(c => c.url.includes('/cart/remove'));

        expect(remove, 'the sold-out removal was never sent to the server').toBeDefined();
        expect(remove.body.variant_id).toBe(9);
    });

    it('a sound cart triggers no write at all', async () => {
        window.setCartMirror([
            { id: 1, variant_id: 9, quantity: 2, stock: 10, name: 'Fine' },
        ]);

        await window.syncCartWithStock();

        // A network round trip for no reason on every opening of the cart.
        expect(calls).toHaveLength(0);
    });

    it('an empty cart triggers nothing', async () => {
        await window.syncCartWithStock();

        expect(calls).toHaveLength(0);
    });

    // ════════════════════════════════════════════════════════
    // The signed-out visitor
    // ════════════════════════════════════════════════════════

    it('with no cart elements on the page, no endpoint is called', async () => {
        // The signed-out visitor: the templates do not print #cartSidebar at all. And
        // without this guard, every public page load pushed a request that answered 401.
        document.body.innerHTML = '';

        await window.loadCart();
        const added = await window.cartAdd(1, 1, 1);

        expect(added).toBe(false);
        expect(calls).toHaveLength(0);
    });
    it('rapid clicks on the same colour are serialised rather than piling up', async () => {
        // A report from real use: "I clicked add quickly… the number keeps going up."
        //
        // Every click read the mirror before the previous one's reply arrived, so it saw the
        // old quantity and passed the browser's stock check. Ten clicks = ten concurrent
        // requests, and a number that jumps past what is available and then springs back.
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
        expect(peak, 'two concurrent requests on the same variant').toBe(1);
    });

    it('different colours stay parallel', async () => {
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

        // The chain is per variant rather than global: editing one line does not block
        // editing another.
        await Promise.all([window.cartAdd(1, 9, 1), window.cartAdd(2, 10, 1), window.cartAdd(3, 11, 1)]);

        expect(peak).toBeGreaterThan(1);
    });
});
/**
 * The counter badge in the navbar.
 *
 * ══════════════════════════════════════════════════════════════
 * The fault that brought this group into being — a report from real use
 * ══════════════════════════════════════════════════════════════
 *
 * "The cart does not respond to the quantity — if there are five laptops in it, the cart
 * shows one, even when the products differ."
 *
 * The cause: updateCounters in js/core/ui.js read the cart from
 * `localStorage.getItem("cart")`. Which was right on the day the cart was local, and became
 * a silent fault the moment it moved to the server: the key stopped being written at all, so
 * the badge froze on the last value written before the migration.
 *
 * And nothing caught it: the badge is in no test, and the whole of composer check is on the
 * server. It surfaced in use alone.
 */
describe('ui.js — the cart badge', () => {
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

    it('shows the sum of the quantities rather than the number of lines', () => {
        window.setCartMirror([
            { id: 1, variant_id: 1, quantity: 5, price: 10, stock: 9, name: 'Laptop' },
        ]);

        // Five units → the badge reads 5. It used to read 1.
        expect(document.getElementById('cart-count').textContent).toBe('5');
    });

    it('sums the quantities across different products', () => {
        window.setCartMirror([
            { id: 1, variant_id: 1, quantity: 5, price: 10, stock: 9, name: 'Laptop' },
            { id: 2, variant_id: 2, quantity: 3, price: 20, stock: 9, name: 'Phone' },
        ]);

        expect(document.getElementById('cart-count').textContent).toBe('8');
    });

    it('ignores localStorage entirely', () => {
        // Leftovers from before the migration: this was the value the badge would freeze on
        // forever.
        localStorage.setItem('cart', JSON.stringify([{ id: 99, quantity: 1 }]));

        window.setCartMirror([
            { id: 1, variant_id: 1, quantity: 4, price: 10, stock: 9, name: 'X' },
        ]);

        expect(document.getElementById('cart-count').textContent).toBe('4');
    });

    it('an empty cart gives zero', () => {
        window.setCartMirror([]);

        expect(document.getElementById('cart-count').textContent).toBe('0');
    });

    it('the wishlist stays in localStorage — it did not move', () => {
        localStorage.setItem('wishlist', JSON.stringify([1, 2, 3]));

        window.updateCounters();

        expect(document.getElementById('wishlist-count').textContent).toBe('3');
    });
});
