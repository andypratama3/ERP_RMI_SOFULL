const S = require("../selectors");
const H = require("../helpers");
const { execFileSync } = require("node:child_process");

describe("ERP Mobile E2E Watch", () => {
  it("login -> dashboard -> modules -> logout", async () => {
    const username = process.env.WDIO_TEST_USERNAME || "";
    const password = process.env.WDIO_TEST_PASSWORD || "";
    const requestId = process.env.WDIO_REQUEST_ID || `RID_${Date.now()}`;
    let hasListDetail = false;

    if (!username || !password) {
      throw new Error("Credential test kosong. Isi TEST_USER_USERNAME/TEST_USER_PASSWORD di .env");
    }

    // 1) Launch app + Login
    await H.assertDisplayed(S.loginUsername, "login_username tidak ditemukan");
    await H.shot("login_screen");
    try {
      await H.waitAndType(S.loginUsername, username);
    } catch (_) {
      await H.waitAndType(S.loginUsernameInput, username);
    }
    try {
      await H.waitAndType(S.loginPassword, password);
    } catch (_) {
      await H.waitAndType(S.loginPasswordInput, password);
    }
    await H.waitAndTap(S.loginSubmit);
    let hasNavSales = await H.exists(S.navSales, 12000);
    let hasDashboardRoot = await H.exists(S.dashboardRoot, 12000);
    if (!hasNavSales && !hasDashboardRoot) {
      try {
        await H.assertDisplayed(S.navSales, "Dashboard/nav tidak tampil setelah login", 15000);
        hasNavSales = true;
      } catch (_) {
        await H.assertDisplayed(S.dashboardRoot, "Dashboard/nav tidak tampil setelah login", 15000);
        hasDashboardRoot = true;
      }
    }
    await H.shot("dashboard_after_login");

    // 2) Sales list + detail (minimal via first row marker)
    if (!(await H.exists(S.navSales, 2500))) {
      await H.scrollDown();
    }
    await H.waitAndTap(S.navSales);
    await H.shot("sales_screen");
    await H.assertDisplayed(S.detailHeader, "Header detail/list sales tidak ditemukan");
    if (await H.exists(S.listFirstItem, 6000)) {
      await H.waitAndTap(S.listFirstItem);
      await H.shot("sales_detail");
      hasListDetail = true;
      await browser.back();
      await H.stepDelay();
    } else {
      await H.shot("sales_empty_or_no_first_item");
      await browser.back();
      await H.stepDelay();
    }

    // 3) Purchases list + detail
    await H.waitAndTap(S.navPurchases);
    await H.shot("purchases_screen");
    await H.assertDisplayed(S.detailHeader, "Header purchases tidak ditemukan");
    if (await H.exists(S.listFirstItem, 6000)) {
      await H.waitAndTap(S.listFirstItem);
      await H.shot("purchases_detail");
      hasListDetail = true;
      await browser.back();
      await H.stepDelay();
    } else {
      await H.shot("purchases_empty_or_no_first_item");
    }

    if (!hasListDetail) {
      throw new Error("Flow list->detail tidak terpenuhi: item list tidak ditemukan di Sales maupun Purchases.");
    }

    // 4) Stock
    await H.waitAndTap(S.navStock);
    await H.shot("stock_screen");
    await H.assertDisplayed("android=new UiSelector().textContains(\"Stock\")", "Screen Stock tidak ditemukan");
    await browser.back();
    await H.stepDelay();

    // 5) Chat + kirim pesan
    await H.waitAndTap(S.navChat);
    await H.shot("chat_screen");
    await H.assertDisplayed(S.chatMessageInput, "chat_message_input tidak ditemukan");
    await H.waitAndType(S.chatMessageInput, `E2E WATCH ${requestId}`);
    await H.waitAndTap(S.chatSendButton);
    await H.shot("chat_send");
    await browser.back();
    await H.stepDelay();

    // 6) Logout
    if (await H.exists(S.bottomSettingsByText, 3000)) {
      await H.waitAndTap(S.bottomSettingsByText);
      await H.stepDelay();
    }
    for (let i = 0; i < 5; i += 1) {
      if (await H.exists(S.logoutButton, 1500)) break;
      await browser.back();
      await H.stepDelay();
    }
    if (!(await H.exists(S.logoutButton, 4000))) {
      throw new Error("logout_button tidak ditemukan");
    }
    await $(S.logoutButton).click();
    await H.stepDelay();
    await H.assertDisplayed(S.loginUsername, "Tidak kembali ke login setelah logout");
    await H.shot("logout_to_login");
  });

  it("offline -> online recovery outbox", async () => {
    const username = process.env.WDIO_TEST_USERNAME || "";
    const password = process.env.WDIO_TEST_PASSWORD || "";
    const requestId = process.env.WDIO_REQUEST_ID || `RID_${Date.now()}`;
    if (!username || !password) {
      throw new Error("Credential test kosong. Isi TEST_USER_USERNAME/TEST_USER_PASSWORD di .env");
    }

    const runShell = async (command) => {
      try {
        const serial = process.env.WDIO_DEVICE_SERIAL || "";
        const args = [];
        if (serial) {
          args.push("-s", serial);
        }
        args.push("shell", ...command.trim().split(/\s+/));
        execFileSync("adb", args, { stdio: "pipe", encoding: "utf8" });
      } catch (e) {
        throw new Error(`Gagal jalankan adb shell ${command}: ${e.message}`);
      }
    };

    try {
      // Guard: ensure emulator starts online even if previous run aborted.
      await runShell("svc wifi enable");
      await runShell("svc data enable");

      await H.assertDisplayed(S.loginUsername, "login_username tidak ditemukan");
      await H.waitAndType(S.loginUsername, username);
      await H.waitAndType(S.loginPassword, password);
      await H.waitAndTap(S.loginSubmit);
      const hasNavAfterLogin = await H.exists(S.navSales, 12000);
      const hasRootAfterLogin = await H.exists(S.dashboardRoot, 12000);
      if (!hasNavAfterLogin && !hasRootAfterLogin) {
        try {
          await H.assertDisplayed(S.navSales, "Dashboard tidak tampil", 15000);
        } catch (_) {
          await H.assertDisplayed(S.dashboardRoot, "Dashboard tidak tampil", 15000);
        }
      }
      await H.shot("recovery_dashboard");

      await runShell("svc wifi disable");
      await runShell("svc data disable");
      await H.stepDelay();
      await H.shot("offline_mode");

      if (!(await H.exists(S.navSales, 3000))) {
        await H.scrollDown();
      }
      await H.waitAndTap(S.navSales);
      if (await H.exists(S.listFirstItem, 5000)) {
        await H.waitAndTap(S.listFirstItem);
        await H.shot("offline_sales_detail");
        await browser.back();
      }
      await browser.back();
      await H.stepDelay();

      if (!(await H.exists(S.navTasksByText, 3000))) {
        await H.scrollDown();
      }
      await H.waitAndTap(S.navTasksByText);
      await H.assertDisplayed(S.syncNowButton, "sync_now_button tidak ditemukan");
      await H.waitAndTap(S.syncNowButton);
      await H.shot("queued_offline");

      await runShell("svc wifi enable");
      await runShell("svc data enable");
      await H.stepDelay();
      await H.stepDelay();
      await H.waitAndTap(S.syncNowButton);
      await H.shot("sync_after_online");

      if (await H.exists(S.tasksFilterSent, 4000)) {
        await H.waitAndTap(S.tasksFilterSent);
        await H.shot("tasks_sent_after_recovery");
      } else {
        throw new Error("tasks_filter_sent tidak ditemukan untuk validasi recovery");
      }

      if (await H.exists(S.bottomSettingsByText, 3000)) {
        await H.waitAndTap(S.bottomSettingsByText);
        await H.stepDelay();
      }
      await H.assertDisplayed(S.logoutButton, "logout_button tidak ditemukan");
      await $(S.logoutButton).click();
      await H.assertDisplayed(S.loginUsername, "Tidak kembali ke login setelah logout recovery scenario");
      await H.shot(`recovery_done_${requestId}`);
    } finally {
      // Safety net to avoid leaving emulator in offline mode.
      await runShell("svc wifi enable");
      await runShell("svc data enable");
    }
  });
});
