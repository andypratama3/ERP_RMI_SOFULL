# Website Cache Headers Snippet

## Target

File statik: css, js, png, jpg, svg, webp, woff2, mp4, pdf di bawah `/assets/` atau `/public/`.

## Apache (.htaccess)

Sudah diterapkan di `/volume4/web/website/rmi_website/.htaccess`:

```apache
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType text/css "access plus 30 days"
  ExpiresByType application/javascript "access plus 30 days"
  ExpiresByType image/png "access plus 30 days"
  ExpiresByType image/jpeg "access plus 30 days"
  ExpiresByType image/svg+xml "access plus 30 days"
  ExpiresByType image/webp "access plus 30 days"
  ExpiresByType font/woff2 "access plus 30 days"
</IfModule>
<IfModule mod_headers.c>
  <FilesMatch "\.(css|js|png|jpg|jpeg|svg|webp|woff2|mp4|pdf)$">
    Header set Cache-Control "public, max-age=2592000, immutable"
  </FilesMatch>
  <FilesMatch "\.(html|htm)$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
```

## Nginx

Jika DSM pakai Nginx, tambahkan ke `location`:

```nginx
location ~* ^/(assets|static|images|public)/.*\.(css|js|png|jpg|jpeg|svg|webp|woff2|mp4|pdf)$ {
    add_header Cache-Control "public, max-age=2592000, immutable";
    expires 30d;
}
location ~* \.(html|htm)$ {
    add_header Cache-Control "no-cache";
}
```
