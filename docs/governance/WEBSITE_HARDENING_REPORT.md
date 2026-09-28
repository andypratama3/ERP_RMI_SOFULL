# Website Hardening Report

## Docroot

```
/volume4/web/website/rmi_website
```

## Status

| Item | Status |
|------|--------|
| robots.txt | DONE |
| sitemap.xml | DONE |
| Redirect www→non-www | DONE (.htaccess) |
| Cache headers assets | DONE (max-age=2592000) |
| Options -Indexes | DONE (.htaccess) |
| No .env in website | DONE (excluded) |

## Check Script

```bash
php tools/qa/check_company_sitemap.php
```

Output: `storage/logs/company_website_checks.last.json`

## Endpoints

- `/` (200)
- `/robots.txt` (200)
- `/sitemap.xml` (200)

File: `tools/qa/http_endpoints_company_website.json`

## Nginx Snippet (jika DSM pakai Nginx)

- Redirect: `docs/governance/WEBSITE_REDIRECT_TODO.md`
- Cache: `docs/governance/WEBSITE_CACHE_HEADERS_SNIPPET.md`
