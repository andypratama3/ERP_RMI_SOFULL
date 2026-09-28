#!/bin/bash
# UAT script untuk H2H order_create
# Usage: ./h2h_order_test.sh <base_url> <api_key>
# Example: ./h2h_order_test.sh https://erp.example.com/ERP_RMI_SOFULL rpk_xxx

BASE="${1:-}"
KEY="${2:-}"

if [ -z "$BASE" ] || [ -z "$KEY" ]; then
  echo "Usage: $0 <base_url> <api_key>"
  echo "Example: $0 https://erp.example.com/ERP_RMI_SOFULL rpk_xxx"
  exit 1
fi

echo "=== 1. Health Check ==="
curl -s -w "\nHTTP %{http_code}\n" -H "X-API-Key: $KEY" "$BASE/api/v1/partner/health.php"
echo ""

echo "=== 2. Order Create (H2H) ==="
curl -s -w "\nHTTP %{http_code}\n" -X POST \
  -H "X-API-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{"idempotency_key":"UAT-'$(date +%Y%m%d%H%M%S)'","customers_code":"Hoo1","office_code":"BGR","items":[{"sku":"OBT-001","qty":1}]}' \
  "$BASE/api/v1/partner/order_create.php"
echo ""

echo "=== 3. Idempotency Test (kirim 2x sama) ==="
IDEM="UAT-IDEM-$(date +%s)"
echo "Request 1:"
curl -s -w "\nHTTP %{http_code}\n" -X POST -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d "{\"idempotency_key\":\"$IDEM\",\"customers_code\":\"Hoo1\",\"office_code\":\"BGR\",\"items\":[{\"sku\":\"OBT-001\",\"qty\":1}]}" \
  "$BASE/api/v1/partner/order_create.php"
echo ""
echo "Request 2 (harus return DO sama):"
curl -s -w "\nHTTP %{http_code}\n" -X POST -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d "{\"idempotency_key\":\"$IDEM\",\"customers_code\":\"Hoo1\",\"office_code\":\"BGR\",\"items\":[{\"sku\":\"OBT-001\",\"qty\":1}]}" \
  "$BASE/api/v1/partner/order_create.php"
echo ""
echo "Done."
