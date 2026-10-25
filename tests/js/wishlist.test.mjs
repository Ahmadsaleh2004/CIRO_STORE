import { beforeEach, describe, expect, it, vi } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/features/wishlist.js — what may delete a saved product, and what may not.
 *
 * The wishlist lives only in the visitor's browser (localStorage). Nothing on the server can
 * restore it, so the one operation that writes a shorter list back must be certain.
 *
 * It was not. renderWishlist kept an item only when live stock data existed for it, so a
 * failed stock request — a database outage, or one 503 while the free-tier container woke —
 * left no item with data, dropped them all, and saved the empty list. These tests replay
 * that response and the legitimate removals beside it.
 */
describe('wishlist.js — pruning', () => {
    const saved = [
        { id: 1, name: 'Airpods', price: 199, image_path: 'images/a.jpg' },
        { id: 2, name: 'iPad', price: 499, image_path: 'images/b.jpg' },
        { id: 3, name: 'Switch', price: 299, image_path: 'images/c.jpg' },
    ];

    const savedIds = () => JSON.parse(localStorage.getItem('wishlist')).map((p) => p.id);

    /** A fetch answering with the given status and body. */
    const respond = (status, body) => vi.fn(async () => ({
        ok: status >= 200 && status < 300,
        status,
        json: async () => {
            if (typeof body === 'string') throw new SyntaxError('Unexpected token < in JSON');
            return body;
        },
    }));

    const live = (id, overrides = {}) => ({
        id,
        stock_quantity: 5,
        price: 100,
        discount_percentage: 0,
        price_after_discount: 100,
        is_visible: 1,
        ...overrides,
    });

    beforeEach(() => {
        document.body.innerHTML = '<div id="wishlist-container"></div>';
        localStorage.setItem('wishlist', JSON.stringify(saved));
        window.BASE_URL = '';

        loadScript('js/core/utils.js');
        loadScript('js/features/wishlist.js');
    });

    it('keeps every item when the stock endpoint answers a 503 page', async () => {
        // The exact outage: an HTML error page, which cannot be parsed as JSON.
        globalThis.fetch = respond(503, '<!DOCTYPE html><title>Temporarily unavailable</title>');

        await window.renderWishlist();

        expect(savedIds()).toEqual([1, 2, 3]);
    });

    it('keeps every item when the request itself fails', async () => {
        globalThis.fetch = vi.fn(async () => { throw new TypeError('Failed to fetch'); });

        await window.renderWishlist();

        expect(savedIds()).toEqual([1, 2, 3]);
    });

    it('keeps every item when the server answers success:false', async () => {
        globalThis.fetch = respond(200, { success: false });

        await window.renderWishlist();

        expect(savedIds()).toEqual([1, 2, 3]);
    });

    it('still draws the cards during an outage, from what the browser saved', async () => {
        globalThis.fetch = respond(503, '<html>');

        await window.renderWishlist();

        expect(document.querySelectorAll('#wishlist-container .remove-fav')).toHaveLength(3);
    });

    it('draws the cards before the stock request returns, with nothing yet clickable', async () => {
        let answer;
        globalThis.fetch = vi.fn(() => new Promise((resolve) => { answer = resolve; }));

        const rendering = window.renderWishlist();

        // The request is still in flight: the cards are already there, from localStorage.
        const cards = document.querySelectorAll('#wishlist-container .product-card');
        expect(cards).toHaveLength(3);
        expect(document.querySelectorAll('#wishlist-container .add-to-cart-wl')).toHaveLength(0);
        expect(cards[0].querySelector('button.btn-success').disabled).toBe(true);

        // No claim the server has not made yet: no "Out of Stock" from a missing stock, and
        // not the price saved with the item, which may be stale.
        expect(cards[0].textContent).not.toContain('Out of Stock');
        expect(cards[0].textContent).not.toContain('$199.00');

        answer({
            ok: true,
            status: 200,
            json: async () => ({ success: true, products: { 1: live(1), 2: live(2), 3: live(3) } }),
        });
        await rendering;

        expect(document.querySelectorAll('#wishlist-container [data-wl-qty-input]')).toHaveLength(3);
    });

    it('removes an item the server marks hidden', async () => {
        globalThis.fetch = respond(200, {
            success: true,
            products: { 1: live(1), 2: live(2, { is_visible: 0 }), 3: live(3) },
        });

        await window.renderWishlist();

        expect(savedIds()).toEqual([1, 3]);
    });

    it('removes an item the server no longer returns at all — a deleted product', async () => {
        globalThis.fetch = respond(200, {
            success: true,
            products: { 1: live(1), 3: live(3) },
        });

        await window.renderWishlist();

        expect(savedIds()).toEqual([1, 3]);
    });
});
