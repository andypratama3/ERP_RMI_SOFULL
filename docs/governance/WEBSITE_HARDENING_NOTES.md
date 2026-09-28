# Website Hardening Notes — RMI Company Site

## Docroot

```
/volume4/web/website/rmi_website
```

## File yang Dibuat/Diubah

| File | Fungsi |
|------|--------|
| `robots.txt` | User-agent *, Allow /, Sitemap |
| `sitemap.xml` | Home + aboutus, contact, manufacturers, products |
| `.htaccess` | Apache: www→non-www 301, Force HTTPS, Cache-Control assets |

---

## Opsi A: Apache (.htaccess)

Sudah ada di `/volume4/web/website/rmi_website/.htaccess`:

- **Redirect www → non-www:** 301 ke `https://rizqullahmediska.com`
- **Force HTTPS:** 301 jika request HTTP
- **Cache-Control assets:** css/js/png/jpg/svg/woff2/webp → `public, max-age=2592000, immutable`
- **HTML:** no-cache (no-store, must-revalidate)

---

## Opsi B: Nginx (DSM Reverse Proxy)

Jika Synology pakai Nginx (bukan Apache), atur di **DSM → Control Panel → Login Portal → Advanced → Reverse Proxy**:

### Rule: www → non-www

- **Source:** `https://www.rizqullahmediska.com`
- **Destination:** `https://rizqullahmediska.com`
- **Custom header:** `X-Forwarded-Proto: https`
- Atau di Nginx custom config (jika tersedia):

```nginx
# Snippet untuk server block
if ($host = 'www.rizqullahmediska.com') {
    return 301 https://rizqullahmediska.com$request_uri;
}
```

### Cache untuk /assets/

```nginx
location /assets/ {
    add_header Cache-Control "public, max-age=2592000, immutable";
}
```

**NEXT_ACTION:** Pastikan rule Reverse Proxy di DSM mengarah ke docroot `/volume4/web/website/rmi_website`.

---

## Curl Check (CLI)

Jalankan dari NAS atau mesin yang bisa akses:

```bash
curl -I https://rizqullahmediska.com/robots.txt    # Expected: 200
curl -I https://rizqullahmediska.com/sitemap.xml   # Expected: 200
curl -I https://www.rizqullahmediska.com           # Expected: 301/308 → non-www
curl -I https://rizqullahmediska.com/assets/css/style.css  # Expected: 200 + Cache-Control
```

### Hasil (dari lingkungan eksekusi)

- `robots.txt`: 403 (Cloudflare challenge/bot protection)
- `sitemap.xml`: 403
- `www`: 403
- `assets`: 403

**Catatan:** 403 dari Cloudflare saat curl tanpa browser. Untuk validasi lengkap, jalankan curl dari NAS atau bypass Cloudflare (dev mode).

---

## DONE Criteria

- [x] robots.txt & sitemap.xml ada
- [x] Redirect www→non-www di .htaccess (Apache)
- [x] Cache header siap di .htaccess (Apache)
- [x] Instruksi Nginx/DSM untuk opsi B
- [ ] Curl 200: verifikasi manual dari NAS setelah deploy
