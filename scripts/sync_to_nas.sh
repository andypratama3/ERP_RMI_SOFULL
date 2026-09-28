#!/bin/bash
# sync_to_nas.sh — Sinkronkan workspace ke NAS via rsync
# WAJIB dijalankan dari Mac (bukan dari SSH ke NAS)
# Pastikan SSH ke NAS sudah bisa: ssh RizqullahMediska@10.10.60.20

set -e

SRC="${1:-/Volumes/web/ERP_RMI_SOFULL}"
DEST_USER="${2:-RizqullahMediska}"
DEST_HOST="${3:-10.10.60.20}"
DEST_PATH="/volume4/web/ERP_RMI_SOFULL"

if [[ ! -d "$SRC" ]]; then
  echo "Error: Source tidak ditemukan: $SRC"
  echo ""
  echo "Script ini harus dijalankan dari MAC (bukan dari SSH ke NAS)."
  echo "Di Mac: buka Terminal baru, lalu:"
  echo "  cd /Volumes/web/ERP_RMI_SOFULL"
  echo "  ./scripts/sync_to_nas.sh"
  echo ""
  echo "Jika workspace Anda di path lain:"
  echo "  ./scripts/sync_to_nas.sh /path/ke/ERP_RMI_SOFULL"
  exit 1
fi

if [[ "$(pwd)" == *"/volume4/"* ]]; then
  echo "Error: Anda sedang di NAS. Script ini harus dijalankan dari Mac."
  echo "Buka Terminal baru di Mac (bukan SSH), lalu jalankan script dari sana."
  exit 1
fi

echo "Sync ke NAS..."
echo "  From: $SRC"
echo "  To:   $DEST_USER@$DEST_HOST:$DEST_PATH"
echo ""

rsync -avz --progress \
  --exclude='storage/logs/' \
  --exclude='storage/backups/' \
  --exclude='exports/' \
  --exclude='node_modules/' \
  --exclude='.git/' \
  --exclude='.env' \
  --exclude='*.log' \
  "$SRC/" \
  "$DEST_USER@$DEST_HOST:$DEST_PATH/"

echo ""
echo "Sync selesai."
