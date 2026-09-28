#!/bin/sh
# Find PHP binary with pdo_mysql on Synology NAS
for p in /usr/local/bin/php84 /usr/local/bin/php82 /usr/local/bin/php81 /usr/local/bin/php80 /usr/local/bin/php74 \
         /usr/bin/php84 /usr/bin/php82 /usr/bin/php81 /usr/bin/php80 /usr/bin/php; do
    [ -x "$p" ] || continue
    if "$p" -m 2>/dev/null | grep -q pdo_mysql; then
        echo "OK: $p"
        echo "$p"
        exit 0
    fi
done
echo "FAIL: no PHP with pdo_mysql found"
exit 1
