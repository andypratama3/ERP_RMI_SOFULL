# PATCH DIFF — Tools NAS Finalization

**Generated:** 2026-03-01  
**Scope:** tools/**, .env, docs/governance/**

---

## Files Touched

| File | Change |
|------|--------|
| `.env` | +TOOLS_BASE_URL, +APP_URL |
| `tools/_lib/tools_paths.php` | +rmi_env_load() early |
| `tools/qa/smoke_tools_guest.php` | +--base-url= support |
| `tools/qa/run_cutover_checks.php` | +--env= support |
| `tools/nas/setup_erp.sh` | +TOOLS_BASE_URL, +APP_URL in .env template |
| `tools/qa/http_response_analysis_web.php` | Default base URL fallback (prior) |

---

## Diff Summary (Conceptual)

### .env
```diff
+ TOOLS_BASE_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
+ APP_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
```

### tools/_lib/tools_paths.php
```diff
+ // Load .env early so TOOLS_BASE_URL etc available to CLI
+ $__env = dirname(__DIR__, 2) . '/_shared/env.php';
+ if (is_file($__env)) {
+     require_once $__env;
+     if (function_exists('rmi_env_load')) {
+         rmi_env_load();
+     }
+ }
```

### tools/qa/smoke_tools_guest.php
```diff
  $baseUrl = tools_get_base_url();
+ foreach ($_SERVER['argv'] ?? [] as $arg) {
+     if (is_string($arg) && str_starts_with($arg, '--base-url=')) {
+         $baseUrl = rtrim(trim(substr($arg, 11)), '/');
+         break;
+     }
+ }
  $writeLast = !in_array('--no-write-last', $_SERVER['argv'] ?? [], true);
```

### tools/qa/run_cutover_checks.php
```diff
  $appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
+ foreach ($args as $a) {
+     if (is_string($a) && str_starts_with($a, '--env=')) {
+         $v = trim(substr($a, 6));
+         if ($v !== '') {
+             @putenv('APP_ENV=' . $v);
+             $appEnv = strtolower($v);
+         }
+         break;
+     }
+ }
```

### tools/nas/setup_erp.sh
```diff
  APP_DEBUG=true
+
+ TOOLS_BASE_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
+ APP_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL
+
  ERP_DB_HOST=127.0.0.1
```

---

## Masking

All paths in logs/reports use `[APP_ROOT]` or `ts_mask()` / `tools_mask_sensitive()`. No raw absolute paths or secrets in outputs.
