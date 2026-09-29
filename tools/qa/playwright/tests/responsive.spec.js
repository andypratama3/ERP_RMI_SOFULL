// Responsive coverage: horizontal overflow, elemen keluar viewport, dan
// kontrol formulir yang unusable (zero-size / offscreen) pada 3 viewport.
const { test, expect } = require('@playwright/test');

const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 820, height: 1180 },
  { name: 'desktop', width: 1440, height: 900 },
];

const PAGES = ['/master/login.php'];

for (const vp of VIEWPORTS) {
  test.describe(`responsive ${vp.name} (${vp.width}x${vp.height})`, () => {
    for (const p of PAGES) {
      test(`${p} tidak scroll horizontal`, async ({ page }) => {
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await page.goto(p);
        const overflow = await page.evaluate(() => ({
          scrollW: document.documentElement.scrollWidth,
          clientW: document.documentElement.clientWidth,
        }));
        // toleransi 2px untuk rounding subpixel
        expect(
          overflow.scrollW,
          `scrollWidth=${overflow.scrollW} > clientWidth=${overflow.clientW}`
        ).toBeLessThanOrEqual(overflow.clientW + 2);
      });

      test(`${p} tidak ada elemen melewati kanan viewport`, async ({ page }) => {
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await page.goto(p);
        const bad = await page.evaluate((w) => {
          const out = [];
          document.querySelectorAll('body *').forEach((el) => {
            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) return;
            if (r.right > w + 2) {
              out.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''} right=${Math.round(r.right)}`);
            }
          });
          return out.slice(0, 10);
        }, vp.width);
        expect(bad, `elemen melewati viewport ${vp.width}px:\n${bad.join('\n')}`).toEqual([]);
      });

      test(`${p} kontrol form punya area interaksi yang bisa dipakai`, async ({ page }) => {
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await page.goto(p);
        const small = await page.evaluate(() => {
          const out = [];
          document.querySelectorAll('input:not([type=hidden]), select, textarea, button').forEach((el) => {
            const r = el.getBoundingClientRect();
            const st = getComputedStyle(el);
            if (st.display === 'none' || st.visibility === 'hidden') return;
            if (r.width === 0 || r.height === 0) {
              out.push(`${el.tagName.toLowerCase()}#${el.id || '(no-id)'} zero-size`);
            }
          });
          return out;
        });
        expect(small, `kontrol form tidak usable:\n${small.join('\n')}`).toEqual([]);
      });
    }
  });
}
