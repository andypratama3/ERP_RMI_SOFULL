#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
RELEASE_DIR="$ROOT_DIR/output/internal_release"
REQUEST_ID="${RELEASE_REQUEST_ID:-$(uuidgen 2>/dev/null || openssl rand -hex 16)}"

if [ -f "$ROOT_DIR/.env" ]; then
  set -a
  . "$ROOT_DIR/.env"
  set +a
fi

APP_NAME="${APP_NAME:-InternalERP}"
APP_ID="${APP_ID:-com.company.internalerp}"
APP_ENV="${APP_ENV:-production}"
REQUIRE_MANUAL_CONFIRM="${REQUIRE_MANUAL_CONFIRM:-1}"

mkdir -p "$RELEASE_DIR/drop/download"

errors=""

if [ -z "${ANDROID_KEYSTORE_PATH:-}" ] && [ -z "${ANDROID_KEYSTORE_BASE64:-}" ]; then
  errors="${errors}\n- Missing signing material: ANDROID_KEYSTORE_PATH or ANDROID_KEYSTORE_BASE64"
fi

for v in ANDROID_KEYSTORE_PASSWORD ANDROID_KEY_ALIAS ANDROID_KEY_PASSWORD; do
  eval "vv=\${$v:-}"
  if [ -z "${vv}" ]; then
    errors="${errors}\n- Missing env: ${v}"
  fi
done

if [ "$APP_ENV" != "production" ] && [ "$APP_ENV" != "staging" ]; then
  errors="${errors}\n- APP_ENV must be production|staging"
fi

if ! command -v sh >/dev/null 2>&1; then
  errors="${errors}\n- shell not found"
fi

if [ ! -f "$ROOT_DIR/gradlew" ]; then
  errors="${errors}\n- gradlew not found at [WORKDIR]/gradlew"
fi

if [ -n "$errors" ]; then
  printf '%s\n' "FAIL request_id=$REQUEST_ID"
  printf '%b\n' "$errors"
  exit 1
fi

if [ "$REQUIRE_MANUAL_CONFIRM" = "1" ] && [ -z "${CI:-}" ] && [ -t 0 ]; then
  printf "request_id=%s\n" "$REQUEST_ID"
  printf "Release target app=%s (%s), env=%s\n" "$APP_NAME" "$APP_ID" "$APP_ENV"
  printf "Type YES to continue: "
  read -r ans
  if [ "$ans" != "YES" ]; then
    echo "Cancelled by user."
    exit 1
  fi
fi

echo "PASS request_id=$REQUEST_ID"
exit 0
