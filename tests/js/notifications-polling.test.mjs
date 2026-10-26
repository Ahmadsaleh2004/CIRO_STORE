import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/features/notifications.js — when the bell asks the server, and when it does not.
 *
 * It used to poll every thirty seconds with a setInterval, in every tab: hidden or not, and
 * answered or not. A background tab kept the free Render instance awake, and during an
 * outage every open tab kept asking. These tests hold the replacement: once a minute, nothing
 * while the tab is hidden, a catch-up on return, and a growing wait after failures that
 * honours the server's Retry-After.
 */
describe('notifications.js — polling', () => {
    let hidden;
    let calls;

    const ok = () => ({
        ok: true,
        status: 200,
        headers: new Headers(),
        json: async () => ({ success: true, notifications: [], unread_count: 0 }),
    });
    const unavailable = (retryAfter) => ({
        ok: false,
        status: 503,
        headers: new Headers(retryAfter ? { 'Retry-After': String(retryAfter) } : {}),
        json: async () => { throw new SyntaxError('HTML'); },
    });

    /** Lets the awaited fetch and json() settle without moving the fake clock. */
    const flush = async () => { for (let i = 0; i < 5; i++) await Promise.resolve(); };

    // Each test loads the script again, and an earlier copy's visibilitychange listener on
    // `document` would still fetch — so every listener a test adds is removed after it.
    let added = [];
    const realAdd = document.addEventListener.bind(document);

    beforeEach(() => {
        vi.useFakeTimers();
        added = [];
        document.addEventListener = (type, fn, opts) => {
            added.push([type, fn, opts]);
            realAdd(type, fn, opts);
        };
        hidden = false;
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
        document.body.innerHTML = '<button id="notifBell"></button><span id="notifBadge"></span>';
        window.BASE_URL = '';
        calls = 0;
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        added.forEach(([type, fn, opts]) => document.removeEventListener(type, fn, opts));
        document.addEventListener = realAdd;
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    const start = (responses) => {
        globalThis.fetch = vi.fn(async () => {
            calls += 1;
            return (responses[calls - 1] || ok)();
        });
        loadScript('js/features/notifications.js');
    };

    it('asks once on load, then once a minute — not every thirty seconds', async () => {
        start([]);
        await flush();
        expect(calls).toBe(1);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(calls).toBe(1);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(calls).toBe(2);
    });

    it('asks nothing while the tab is hidden, and catches up when it is shown', async () => {
        start([]);
        await flush();

        hidden = true;
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(10 * 60_000);
        expect(calls).toBe(1);

        hidden = false;
        document.dispatchEvent(new Event('visibilitychange'));
        await flush();
        expect(calls).toBe(2);
    });

    it('waits longer after each failure', async () => {
        start([() => unavailable(), () => unavailable()]);
        await flush();
        expect(calls).toBe(1);

        // First failure: two minutes, not one
        await vi.advanceTimersByTimeAsync(60_000);
        expect(calls).toBe(1);
        await vi.advanceTimersByTimeAsync(60_000);
        expect(calls).toBe(2);

        // Second failure: four minutes
        await vi.advanceTimersByTimeAsync(3 * 60_000);
        expect(calls).toBe(2);
        await vi.advanceTimersByTimeAsync(60_000);
        expect(calls).toBe(3);
    });

    it("uses the server's Retry-After when it sends one", async () => {
        start([() => unavailable(300)]);
        await flush();

        await vi.advanceTimersByTimeAsync(299_000);
        expect(calls).toBe(1);
        await vi.advanceTimersByTimeAsync(1_000);
        expect(calls).toBe(2);
    });
});
