import { beforeAll, describe, expect, it } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/core/csrf.js — the CSRF safety net in the browser.
 *
 * This file has **a real history of faults**, all of them of one kind: silent failure. The
 * net looks like it works because the first request usually succeeds, and the fault appears
 * only when a retry is actually needed — that is, when the token expires, which never
 * happens in a short manual test.
 *
 * Documented in the file itself: "the previous version did not **ignore** it, it
 * **corrupted** it: every text body went through URLSearchParams, turning
 * {"csrf_token":"…"} into a single encoded key that json_decode cannot read. Which means
 * the retry failed without exception for every endpoint that sends JSON — the checkout page
 * and admin/my-info among them."
 *
 * rebuildBodyWithToken is an internal function never exported to window, so it is obtained
 * by executing the file and reading it off the global scope — which is what makes this test
 * possible at all in a structure without modules.
 */
describe('csrf.js — rebuildBodyWithToken', () => {
    let rebuild;

    beforeAll(() => {
        loadScript('js/core/csrf.js');
        // The function is declared at the top level, so it becomes global after execution.
        rebuild = globalThis.rebuildBodyWithToken;
    });

    it('the function is reachable for testing', () => {
        expect(typeof rebuild).toBe('function');
    });

    describe('FormData — the commonest shape in the project’s forms', () => {
        it('replaces the token and keeps the other fields', () => {
            const body = new FormData();
            body.append('csrf_token', 'old');
            body.append('name', 'Ahmad');
            body.append('qty', '3');

            const out = rebuild({ body }, 'new');

            expect(out.ok).toBe(true);
            expect(out.body.get('csrf_token')).toBe('new');
            expect(out.body.get('name')).toBe('Ahmad');
            expect(out.body.get('qty')).toBe('3');
        });

        it('adds the token when it is absent', () => {
            const body = new FormData();
            body.append('name', 'x');

            const out = rebuild({ body }, 'new');

            expect(out.ok).toBe(true);
            expect(out.body.get('csrf_token')).toBe('new');
        });
    });

    describe('URLSearchParams', () => {
        it('replaces the token and keeps the rest', () => {
            const body = new URLSearchParams({ csrf_token: 'old', id: '9' });

            const out = rebuild({ body }, 'new');

            expect(out.ok).toBe(true);
            expect(new URLSearchParams(out.body.toString()).get('csrf_token')).toBe('new');
            expect(new URLSearchParams(out.body.toString()).get('id')).toBe('9');
        });
    });

    describe('A JSON body — the shape that used to be corrupted', () => {
        /**
         * The test that guards the original fault.
         *
         * Were the text body to go through URLSearchParams again, the result would be a
         * single key encoded as %7B%22csrf_token%22… and json_decode would fail on the
         * server — so the retry would fail without exception for every endpoint that sends
         * JSON.
         */
        it('keeps a JSON body valid and replaces the token inside it', () => {
            const body = JSON.stringify({ csrf_token: 'old', items: [1, 2, 3] });

            const out = rebuild({ body, headers: { 'Content-Type': 'application/json' } }, 'new');

            expect(out.ok).toBe(true);

            // The result must remain parseable JSON — not encoded text.
            const parsed = JSON.parse(out.body);
            expect(parsed.csrf_token).toBe('new');
            expect(parsed.items).toEqual([1, 2, 3]);
        });

        it('adds the token to a JSON body that lacks it', () => {
            const body = JSON.stringify({ items: [] });

            const out = rebuild({ body, headers: { 'Content-Type': 'application/json' } }, 'new');

            expect(out.ok).toBe(true);
            expect(JSON.parse(out.body).csrf_token).toBe('new');
        });

        it('reads the content-type header whatever its letter case', () => {
            const body = JSON.stringify({ a: 1 });

            const out = rebuild({ body, headers: { 'content-type': 'application/json; charset=utf-8' } }, 't');

            expect(out.ok).toBe(true);
            expect(JSON.parse(out.body).csrf_token).toBe('t');
        });

        it('accepts the headers as a Headers object', () => {
            const headers = new Headers({ 'Content-Type': 'application/json' });
            const out = rebuild({ body: JSON.stringify({ a: 1 }), headers }, 't');

            expect(out.ok).toBe(true);
            expect(JSON.parse(out.body).csrf_token).toBe('t');
        });

        it('accepts the headers as an array of pairs', () => {
            const headers = [['Content-Type', 'application/json']];
            const out = rebuild({ body: JSON.stringify({ a: 1 }), headers }, 't');

            expect(out.ok).toBe(true);
            expect(JSON.parse(out.body).csrf_token).toBe('t');
        });
    });

    describe('What cannot be rebuilt', () => {
        /**
         * ok=false means "do not retry". Which is more correct than a blind attempt:
         * resending a body corrupted along the way produces a wrong request silently instead
         * of a clear error.
         */
        it('refuses to rebuild what it does not understand, rather than mangling it', () => {
            const out = rebuild({ body: new Blob(['x']) }, 't');
            expect(out.ok).toBe(false);
        });

        it('refuses an absent body', () => {
            const out = rebuild({}, 't');
            expect(out.ok).toBe(false);
        });
    });
});
