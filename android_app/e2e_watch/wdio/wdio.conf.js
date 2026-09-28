const path = require("path");

const runDir = process.env.WDIO_RUN_DIR || path.resolve(__dirname, "../../output/e2e_watch/dev_run");
const screenshotPath = path.join(runDir, "screenshots");
const junitDir = path.join(runDir, "junit");

exports.config = {
  runner: "local",
  autoCompileOpts: {
    autoCompile: false
  },
  specs: ["./tests/specs/*.e2e.js"],
  maxInstances: 1,
  hostname: "127.0.0.1",
  port: Number(process.env.WDIO_APPIUM_PORT || 4723),
  path: "/",
  logLevel: "info",
  bail: 0,
  waitforTimeout: 20000,
  connectionRetryTimeout: 120000,
  connectionRetryCount: 1,
  framework: "mocha",
  reporters: [
    "spec",
    ["junit", {
      outputDir: junitDir,
      outputFileFormat: () => "results.xml"
    }]
  ],
  mochaOpts: {
    ui: "bdd",
    timeout: Number(process.env.RUN_TIMEOUT_SECONDS || 300) * 1000
  },
  services: [],
  capabilities: [{
    platformName: "Android",
    "appium:automationName": "UiAutomator2",
    "appium:deviceName": process.env.WDIO_AVD_NAME || "ERP_WATCH",
    "appium:app": process.env.WDIO_APK_PATH,
    "appium:appPackage": process.env.WDIO_APP_PACKAGE,
    "appium:appActivity": process.env.WDIO_APP_ACTIVITY,
    "appium:autoGrantPermissions": true,
    "appium:newCommandTimeout": 180,
    "appium:adbExecTimeout": 200000,
    "appium:noReset": false,
    "appium:fullReset": false
  }],
  before: function () {
    browser.setTimeout({ implicit: 5000 });
  },
  afterTest: async function (test, context, result) {
    if (!result.passed) {
      const name = `${Date.now()}_${test.title.replace(/[^a-zA-Z0-9_-]/g, "_")}.png`;
      await browser.saveScreenshot(path.join(screenshotPath, name));
    }
  },
  outputDir: path.join(runDir, "logs")
};
