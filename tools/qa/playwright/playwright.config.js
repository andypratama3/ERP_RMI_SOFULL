// Playwright config untuk QA ERP RMI SOFULL.
// Credential TIDAK di-hardcode: wajib lewat env (lihat tools/qa/playwright/README.md).
const { defineConfig, devices } = require('@playwright/test');

const BASE_URL = process.env.PW_BASE_URL || 'http://127.0.0.1:8765';
const VIEWPORTS = {
  mobile: { width: 390, height: 844 },
  tablet: { width: 820, height: 1180 },
  desktop: { width: 1440, height: 900 },
};

module.exports = defineConfig({
  testDir: './tests',
  outputDir: './test-results',
  timeout: 30_000,
  expect: { timeout: 7_000 },
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { outputFolder: './playwright-report', open: 'never' }]],

  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    ignoreHTTPSErrors: true,
  },

  projects: [
    // Cross-browser penuh: jalankan suite yang sama di 3 engine.
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },

    // Responsive: hanya spec responsif, 3 viewport, engine default.
    {
      name: 'responsive',
      testMatch: /responsive\.spec\.js/,
      use: { ...devices['Desktop Chrome'], viewport: VIEWPORTS.desktop },
    },
  ],
});
