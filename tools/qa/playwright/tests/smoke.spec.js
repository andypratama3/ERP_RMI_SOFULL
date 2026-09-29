// Smoke test: guardrail dasar sebelum suite yang lebih berat.
// Kriteria PASS: halaman merespons 200/302 (bukan 500) dan tidak melempar
// exception PHP ke browser.
const { test, expect } = require('@playwright/test');

const PUBLIC_PAGES = [
  '/master/login.php',
];

test.describe('smoke: halaman publik', () => {
  for (const p of PUBLIC_PAGES) {
    test(`halaman ${p} tidak 5xx`, async ({ page }) => {
      const res = await page.goto(p);
      expect(res, `tidak ada response untuk ${p}`).toBeTruthy();
      const status = res.status();
      expect(status, `${p} => HTTP ${status}`).toBeLessThan(500);
    });
  }
});

test.describe('smoke: tidak ada PHP error yang bocor', () => {
  test('login page tidak menampilkan error/fatal PHP', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto('/master/login.php');
    const body = (await page.locator('body').innerText()).toLowerCase();
    for (const marker of ['fatal error', 'parse error', 'warning:', 'uncaught', 'stack trace']) {
      expect(body, `php marker "${marker}" muncul di login page`).not.toContain(marker);
    }
    expect(errors, 'JS error di login page').toEqual([]);
  });
});
