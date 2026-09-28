const fs = require("fs");
const path = require("path");

const STEP_DELAY_MS = Number(process.env.WDIO_STEP_DELAY_MS || 600);
const SCREENSHOT_ON_EACH_STEP = String(process.env.WDIO_SCREENSHOT_ON_EACH_STEP || "1") === "1";
const RUN_DIR = process.env.WDIO_RUN_DIR || path.resolve(__dirname, "../../../output/e2e_watch/dev_run");
const SHOT_DIR = path.join(RUN_DIR, "screenshots");

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function stepDelay() {
  if (STEP_DELAY_MS > 0) await sleep(STEP_DELAY_MS);
}

function safeName(input) {
  return String(input).replace(/[^a-zA-Z0-9_-]/g, "_");
}

async function shot(name) {
  if (!SCREENSHOT_ON_EACH_STEP) return;
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  const file = path.join(SHOT_DIR, `${Date.now()}_${safeName(name)}.png`);
  await browser.saveScreenshot(file);
}

async function waitAndTap(selector, timeout = 15000) {
  const el = await $(selector);
  await el.waitForDisplayed({ timeout });
  await el.click();
  await stepDelay();
}

async function scrollDown() {
  try {
    await browser.execute("mobile: scrollGesture", {
      left: 100,
      top: 240,
      width: 880,
      height: 1400,
      direction: "down",
      percent: 0.75
    });
    await stepDelay();
  } catch (_) {
    // no-op
  }
}

async function waitAndType(selector, value, timeout = 15000) {
  const el = await $(selector);
  await el.waitForDisplayed({ timeout });
  await el.click();
  try {
    await el.clearValue();
  } catch (_) {
    // Compose semantics wrappers may not support clearValue directly.
  }
  try {
    await el.setValue(value);
  } catch (_) {
    await browser.keys(value);
  }
  await stepDelay();
}

async function assertDisplayed(selector, msg = "Element not found", timeout = 15000) {
  const el = await $(selector);
  try {
    const ok = await el.waitForDisplayed({ timeout });
    if (ok) return;
  } catch (_) {
    // Fallback for Compose accessibility wrappers that are present but not "displayed".
  }
  const exists = await el.waitForExist({ timeout });
  if (!exists) throw new Error(msg);
}

module.exports = {
  shot,
  stepDelay,
  waitAndTap,
  waitAndType,
  assertDisplayed,
  scrollDown,
  exists: async (selector, timeout = 4000) => {
    const el = await $(selector);
    try {
      return await el.waitForExist({ timeout });
    } catch (_) {
      return false;
    }
  },
};
