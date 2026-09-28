# Website Redirect (www → non-www)

## Status

Jika `.htaccess` sudah aktif (Apache): DONE.

Jika DSM pakai Nginx, paste snippet berikut ke vhost aktif untuk `rizqullahmediska.com`:

```nginx
# Redirect www to non-www
if ($host ~* ^www\.(.*)$) {
    return 301 https://$1$request_uri;
}
```

Atau server block terpisah:

```nginx
server {
    listen 80;
    listen 443 ssl;
    server_name www.rizqullahmediska.com;
    return 301 https://rizqullahmediska.com$request_uri;
}
```

## Verifikasi

```bash
curl -sI https://www.rizqullahmediska.com/ | head -5
# Harus: Location: https://rizqullahmediska.com/
```
