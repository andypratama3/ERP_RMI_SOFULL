// Helper login ENV-based untuk suite Playwright.
// Aturan: TIDAK ada username/password di repo. Script runner exporting env
// (PW_ADMIN_USER / PW_ADMIN_PASS) sebelum memanggil playwright.
const ADMIN_USER = process.env.PW_ADMIN_USER;
const ADMIN_PASS = process.env.PW_ADMIN_PASS;

/**
 * Returns true when credentials were provided by the environment.
 * Spec wajib skip (bukan fail) supaya CI tanpa secret tetap hijau & jujur.
 */
function hasCreds() {
  return Boolean(ADMIN_USER && ADMIN_PASS);
}

/**
 * Login via form master/login.php lalu return true bila sudah masuk ke halaman
 * beranda (marker nav user), false bila tetap di halaman login.
 */
async function login(page, { basePath = '/master/login.php' } = {}) {
  if (!hasCreds()) throw new Error('PW_ADMIN_USER/PW_ADMIN_PASS belum di-set');

  await page.goto(basePath);
  // Deteksi Already logged-in.
  if (!(await page.locator('input[name="username"], input[name="email"], form[action*="login"]').first().isVisible().catch(() => false))) {
    return true;
  }

  const user = page.locator('input[name="username"], input[name="email"]').first();
  const pass = page.locator('input[name="password"]').first();
  await user.fill(ADMIN_USER);
  await pass.fill(ADMIN_PASS);
  await Promise.all([
    page.waitForLoadState('networkidle').catch(() => {}),
    page.locator('form button[type="submit"], input[type="submit"]').first().click(),
  ]);

  return !(page.url().includes('login.php'));
}

module.exports = { hasCreds, login, ADMIN_USER };
