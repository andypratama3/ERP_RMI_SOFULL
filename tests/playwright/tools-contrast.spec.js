const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('tools index high-contrast smoke', async ({ page }, testInfo) => {
  const user = process.env.PW_ADMIN_USER || '';
  const pass = process.env.PW_ADMIN_PASS || '';

  await page.goto('/tools/index.php', { waitUntil: 'domcontentloaded' });

  // If auth page appears, login using CI env credentials.
  const hasPasswordInput = await page.locator('input[type="password"]').first().isVisible().catch(() => false);
  if (hasPasswordInput) {
    test.skip(!user || !pass, 'PW_ADMIN_USER/PW_ADMIN_PASS not set');
    const u = page.locator('input[name="username"], input[name="user"], input[type="text"]').first();
    const p = page.locator('input[type="password"]').first();
    await u.fill(user);
    await p.fill(pass);
    const submit = page.locator('button[type="submit"], input[type="submit"]').first();
    await submit.click();
    await page.waitForLoadState('domcontentloaded');
  }

  await expect(page.locator('body')).toBeVisible();
  const normalPath = testInfo.outputPath('tools-index-normal.png');
  await page.screenshot({ path: normalPath, fullPage: true });

  const toggle = page.locator('#rmiContrastToggle, button[aria-label*="contrast" i]').first();
  await expect(toggle).toBeVisible();
  await toggle.click();
  await expect(page.locator('body')).toHaveClass(/theme-contrast/);

  const contrastPath = testInfo.outputPath('tools-index-contrast.png');
  await page.screenshot({ path: contrastPath, fullPage: true });

  const baselineDir = path.join(process.cwd(), 'tests', 'playwright', 'baseline');
  const baselinePath = path.join(baselineDir, 'tools-index-contrast.png');
  if (fs.existsSync(baselinePath)) {
    const baseline = fs.readFileSync(baselinePath);
    const current = fs.readFileSync(contrastPath);
    expect(current.equals(baseline)).toBeTruthy();
  }

  const normalBuf = fs.readFileSync(normalPath);
  const contrastBuf = fs.readFileSync(contrastPath);
  expect(contrastBuf.equals(normalBuf)).toBeFalsy();
});
