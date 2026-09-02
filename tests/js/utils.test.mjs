import { beforeAll, describe, expect, it } from 'vitest';

import { loadScript } from './helpers/load.mjs';

/**
 * js/core/utils.js — the functions shared by every store page.
 *
 * The most important thing here is `stockBadge`: **a mirror of getStockBadge() in PHP**,
 * documented explicitly in both files. The two serve the same screen — the PHP for the cards
 * built on the server, and the JS for the cards the browser builds (the wishlist and the
 * product details). And a difference between them means one product with two different
 * badges depending on which route it arrived by.
 *
 * The PHP side is tested in tests/Unit/StockBadgeHelperTest.php with the same values. The
 * two tests together are what make "they must stay identical" an enforced statement rather
 * than a wish written in a comment.
 */
describe('utils.js', () => {
    beforeAll(() => {
        loadScript('js/core/utils.js');
    });

    describe('stockBadge — a mirror of getStockBadge in PHP', () => {
        it('zero means out of stock', () => {
            expect(window.stockBadge(0)).toEqual({ label: 'Out of Stock', class: 'bg-danger' });
        });

        it('being out of stock outranks everything, and the display flag does not override it', () => {
            expect(window.stockBadge(0, true).label).toBe('Out of Stock');
        });

        it('low stock shows the number remaining', () => {
            expect(window.stockBadge(7)).toEqual({
                label: 'Limited (7 left)',
                class: 'bg-warning text-dark',
            });
        });

        // The two sides of the threshold precisely — the same thing the PHP test guards.
        it('the threshold sits at exactly 50', () => {
            expect(window.stockBadge(50).label).toBe('Limited (50 left)');
            expect(window.stockBadge(51)).toBeNull();
            expect(window.stockBadge(51, true).label).toBe('In Stock (51)');
        });

        it('one is still limited', () => {
            expect(window.stockBadge(1).label).toBe('Limited (1 left)');
        });

        it('plentiful stock carries no badge by default', () => {
            expect(window.stockBadge(500)).toBeNull();
        });

        it('and plentiful stock carries a green badge when asked for', () => {
            expect(window.stockBadge(500, true)).toEqual({
                label: 'In Stock (500)',
                class: 'bg-success',
            });
        });
    });

    describe('encodeImagePath', () => {
        /**
         * The fault it exists for: a space in a srcset is **a separator between candidates**
         * rather than an ordinary character. The project's image names contain spaces, so the
         * browser read "…/images/apple watch.webp" as two candidates and rejected both — ten
         * times in one page load.
         */
        it('encodes the spaces in a file name', () => {
            expect(window.encodeImagePath('images/apple watch.webp'))
                .toBe('images/apple%20watch.webp');
        });

        it('leaves the slashes as they are', () => {
            expect(window.encodeImagePath('a/b c/d.jpg')).toBe('a/b%20c/d.jpg');
        });

        /**
         * A fault in the function's first version: encoding the segments without separating
         * the scheme turns "http:" into "http%3A" and destroys an absolute URL. A direct
         * check caught it before it reached the browser.
         */
        it('does not corrupt the scheme in an absolute URL', () => {
            expect(window.encodeImagePath('http://localhost/STORE/public/images/a b.webp'))
                .toBe('http://localhost/STORE/public/images/a%20b.webp');
            expect(window.encodeImagePath('https://cdn.example.com/x y/z.png'))
                .toBe('https://cdn.example.com/x%20y/z.png');
        });

        it('does not re-encode what is already encoded', () => {
            expect(window.encodeImagePath('images/apple%20watch.webp'))
                .toBe('images/apple%20watch.webp');
        });

        it('leaves a sound path untouched', () => {
            expect(window.encodeImagePath('images/macbook.jpg')).toBe('images/macbook.jpg');
        });

        it('preserves the query string', () => {
            expect(window.encodeImagePath('images/a b.jpg?v=1')).toBe('images/a%20b.jpg?v=1');
        });

        it('passes empty values through without blowing up', () => {
            expect(window.encodeImagePath('')).toBe('');
            expect(window.encodeImagePath(null)).toBeNull();
        });
    });

    describe('escHtml', () => {
        it('escapes the characters that open the door to injection', () => {
            const out = window.escHtml('<script>alert(1)</script>');
            expect(out).not.toContain('<script>');
            expect(out).toContain('&lt;');
        });

        it('escapes the quote marks', () => {
            const out = window.escHtml(`" '`);
            expect(out).not.toContain('"');
        });

        it('leaves non-ASCII text intact', () => {
            expect(window.escHtml('Ahmad Şaleh — café · أحمد')).toBe('Ahmad Şaleh — café · أحمد');
        });
    });

    describe('buildProductPicture', () => {
        it('encodes both the srcset and the src paths', () => {
            const html = window.buildProductPicture('images/apple watch.jpg', 'a watch');

            expect(html).toContain('srcset="images/apple%20watch.webp"');
            expect(html).toContain('src="images/apple%20watch.jpg"');
            // No raw space inside the srcset value — the cause of the original fault.
            expect(/srcset="[^"]*\s[^"]*"/.test(html)).toBe(false);
        });

        it('switches the extension to webp on the alternative source alone', () => {
            const html = window.buildProductPicture('images/x.png', 'x');
            expect(html).toContain('srcset="images/x.webp"');
            expect(html).toContain('src="images/x.png"');
        });
    });

    describe('sameId — an id from JSON against one from the DOM', () => {
        it('a number equals the string of the same number, which is the whole point', () => {
            // From JSON the id is a number; from dataset it is always a string.
            expect(window.sameId(3, '3')).toBe(true);
            expect(window.sameId('3', 3)).toBe(true);
        });

        it('two different ids stay different in either direction', () => {
            expect(window.sameId(3, '4')).toBe(false);
            expect(window.sameId('10', 1)).toBe(false);
        });

        it('an absent id is not an identifier, so it matches nothing — itself included', () => {
            expect(window.sameId(null, null)).toBe(false);
            expect(window.sameId(undefined, undefined)).toBe(false);
            expect(window.sameId(null, 3)).toBe(false);
            expect(window.sameId(3, undefined)).toBe(false);
        });

        it('an empty attribute does not match the id zero — the trap in a numeric cast', () => {
            expect(window.sameId('', 0)).toBe(false);
        });
    });

    describe('sameVariant — the absence of a variant carries meaning', () => {
        it('two absences are the same variant: the one that does not exist', () => {
            expect(window.sameVariant(null, null)).toBe(true);
            expect(window.sameVariant(undefined, null)).toBe(true);
            expect(window.sameVariant(undefined, undefined)).toBe(true);
        });

        it('a real variant against an absent one is a different line in the cart', () => {
            expect(window.sameVariant(null, 5)).toBe(false);
            expect(window.sameVariant('5', null)).toBe(false);
        });

        it('a variant numbered zero is not an absent variant — as null == 0 was false', () => {
            expect(window.sameVariant(null, 0)).toBe(false);
            expect(window.sameVariant(0, 0)).toBe(true);
        });

        it('the string and the number of one variant are one variant', () => {
            expect(window.sameVariant(7, '7')).toBe(true);
            expect(window.sameVariant('7', 8)).toBe(false);
        });
    });
});
