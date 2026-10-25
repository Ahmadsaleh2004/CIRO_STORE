import { beforeEach, describe, expect, it, vi } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/core/ui.js — SweetAlert2 loaded on the first dialog.
 *
 * The library is no longer a tag on every page. `window.Swal` starts as a stand-in that
 * fetches the script and its stylesheet on the first fire() and forwards the call. These
 * tests hold the three promises that change rests on: a caller awaiting fire() still gets
 * the real dialog's answer, the stylesheet lands where the static tag used to be in the
 * cascade, and a file that never arrives still leaves the visitor with a working question.
 *
 * jsdom fetches nothing, so each test plays the browser's part: it fires `load` (or
 * `error`) on the elements the loader inserted, and defines `window.Sweetalert2` the way the
 * real script does when it runs.
 */
describe('ui.js — SweetAlert2 on first use', () => {
    const assets = {
        js: { url: '/js/dist/swal.abc.js', sri: null },
        css: { url: '/css/dist/swal.abc.css', sri: null },
    };

    const inserted = () => ({
        link: document.querySelector(`link[href="${assets.css.url}"]`),
        script: document.querySelector(`script[src="${assets.js.url}"]`),
    });

    /** Lets the loader's promise chain run up to its next wait. */
    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    /** What the real script does when it executes: it defines the library on window. */
    const realSwal = () => {
        const swal = { fire: vi.fn(async () => ({ isConfirmed: true, value: 'real' })) };
        window.Sweetalert2 = swal;
        window.Swal = swal;
        return swal;
    };

    beforeEach(() => {
        document.head.innerHTML =
            '<link rel="stylesheet" href="/css/dist/bootstrap.abc.css" data-vendor="bootstrap-css">'
            + '<link rel="stylesheet" href="/css/dist/store.abc.css">';
        document.body.innerHTML = '';
        delete window.Swal;
        delete window.Sweetalert2;
        window.__swalAssets = assets;

        loadScript('js/core/ui.js');
    });

    it('downloads nothing until a dialog is asked for', () => {
        expect(typeof window.Swal.fire).toBe('function');
        expect(inserted().link).toBeNull();
        expect(inserted().script).toBeNull();
    });

    it('forwards the first fire to the real library once both files have loaded', async () => {
        const answer = window.Swal.fire({ title: 'Delete?', showCancelButton: true });
        await settle();

        const { link, script } = inserted();
        const swal = realSwal();
        script.dispatchEvent(new Event('load'));
        await settle();

        // The script alone is not enough: the bare build injects no styles.
        expect(swal.fire).not.toHaveBeenCalled();

        link.dispatchEvent(new Event('load'));

        await expect(answer).resolves.toEqual({ isConfirmed: true, value: 'real' });
        expect(swal.fire).toHaveBeenCalledWith({ title: 'Delete?', showCancelButton: true });
    });

    it('puts the stylesheet straight after Bootstrap, ahead of the store bundle', async () => {
        window.Swal.fire('Hi');
        await settle();

        const order = [...document.head.querySelectorAll('link')].map((l) => l.getAttribute('href'));
        expect(order).toEqual([
            '/css/dist/bootstrap.abc.css',
            assets.css.url,
            '/css/dist/store.abc.css',
        ]);
    });

    it('loads the files once for any number of early calls', async () => {
        window.Swal.fire('one');
        window.Swal.fire('two');
        await settle();

        expect(document.querySelectorAll(`script[src="${assets.js.url}"]`)).toHaveLength(1);
        expect(document.querySelectorAll(`link[href="${assets.css.url}"]`)).toHaveLength(1);
    });

    it('starts the download on the first press, before any dialog is asked for', async () => {
        document.dispatchEvent(new Event('pointerdown'));
        await settle();

        expect(inserted().script).not.toBeNull();
    });

    it('keeps the integrity check on the CDN copy', async () => {
        window.__swalAssets = {
            js: { url: 'https://cdn.example/swal.js', sri: 'sha384-js' },
            css: { url: 'https://cdn.example/swal.css', sri: 'sha384-css' },
        };
        window.Swal.fire('Hi');
        await settle();

        const script = document.querySelector('script[src="https://cdn.example/swal.js"]');
        expect(script.integrity).toBe('sha384-js');
        expect(script.crossOrigin).toBe('anonymous');
    });

    it('answers with the native fallback when a file fails to load', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const answer = window.Swal.fire({ title: 'Cancel order?', showCancelButton: true });
        await settle();
        inserted().link.dispatchEvent(new Event('error'));

        await expect(answer).resolves.toEqual({ isConfirmed: false, isDismissed: true, value: false });
        expect(confirm).toHaveBeenCalledWith('Cancel order?');
    });

    it('answers with the fallback when the page carries no asset island', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        vi.spyOn(window, 'alert').mockImplementation(() => {});
        delete window.__swalAssets;

        await expect(window.Swal.fire('Saved')).resolves.toMatchObject({ isConfirmed: true });
        expect(window.alert).toHaveBeenCalledWith('Saved');
    });

    it('leaves a library the page already loaded with its own tag', () => {
        const own = { fire: vi.fn() };
        window.Swal = own;

        loadScript('js/core/ui.js');

        expect(window.Swal).toBe(own);
    });
});
