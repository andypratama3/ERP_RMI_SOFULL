# Cloudflare — Bypass Challenge untuk API H2H

Domain `erp.rizqullahmediska.com` di belakang Cloudflare. Request `curl`/API dari Hermina bisa kena challenge **"Just a moment..."** karena Cloudflare menganggap sebagai bot.

**Penting:** Challenge bisa dari **Bot Fight Mode**, **Security Level**, atau **Browser Integrity Check**. Bot Fight Mode **tidak bisa** di-skip lewat rule — harus dimatikan manual.

---

## Perbedaan Rule (PENTING)

| Tipe | Lokasi | Fungsi |
|------|--------|--------|
| **Configuration Rule** | Rules → Configuration Rules | Set setting (mis. BIC Off) — **bisa tidak efektif** untuk challenge |
| **WAF Custom Rule (Skip)** | Security → WAF → Custom rules | Skip produk (BIC, Security Level) — **ini yang diperlukan** |

Configuration Rule dengan "Browser Integrity Check: Off" **berbeda** dari WAF Skip rule. Jika challenge masih muncul, buat **WAF Custom Rule** dengan action **Skip**.

---

## Langkah 1: Matikan Bot Fight Mode (WAJIB jika aktif)

Bot Fight Mode (Free plan) **tidak bisa** di-bypass dengan Skip rule. Jika aktif, API akan tetap kena challenge.

1. **Security** → **Bots**
2. Cari **Bot Fight Mode** → set ke **Off**
3. Save

---

## Langkah 2: Uji cepat — Matikan BIC global (opsional)

Untuk memastikan BIC penyebabnya:

1. **Security** → **Settings** → **Browser integrity check** → **Off**
2. Test: `curl -H "X-API-Key: rpk_xxx" "https://erp.rizqullahmediska.com/ERP_RMI_SOFULL/api/v1/partner/health.php"`
3. Jika berhasil → BIC penyebabnya. Buat WAF Skip rule (Langkah 3), lalu nyalakan kembali BIC global.

---

## Langkah 3: WAF Custom Rule — Skip BIC & Security Level

**Lokasi:** **Security** → **WAF** → **Custom rules** (bukan Configuration Rules)

1. **Create rule** (atau **Deploy custom rules** → **Create rule**)
2. **Rule name:** `Bypass API Partner H2H`
3. **Expression:**
   ```
   (http.request.uri.path contains "/ERP_RMI_SOFULL/api/v1/partner/")
   ```
4. **Action:** `Skip`
5. Di opsi Skip, centang **Skip products**:
   - **Browser Integrity Check**
   - **Security Level**
6. **Deploy**

> Jika opsi "Security Level" tidak ada di UI (perubahan Cloudflare 2024), centang saja **Browser Integrity Check**.

---

## Langkah 4: Security Level global (jika Langkah 3 belum cukup)

**Lokasi:** **Security** → **Settings** → **Security Level**

- Turunkan ke **Low** atau **Essentially Off** untuk testing

---

## Opsi B: Cloudflare API (jika UI tidak berhasil)

Jika WAF Custom Rule via dashboard tidak efektif, buat via API.

**1. Dapatkan Zone ID & Ruleset ID:**
- Zone ID: Dashboard → domain → Overview (sidebar kanan)
- Ruleset ID: `GET https://api.cloudflare.com/client/v4/zones/{ZONE_ID}/rulesets` → cari ruleset dengan `phase: "http_request_firewall_custom"`

**2. Tambah Skip rule:**

```bash
ZONE_ID="your_zone_id"
RULESET_ID="ruleset_id_http_request_firewall_custom"
API_TOKEN="your_cloudflare_api_token"

curl -X POST "https://api.cloudflare.com/client/v4/zones/$ZONE_ID/rulesets/$RULESET_ID/rules" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "skip",
    "action_parameters": {
      "products": ["bic", "securityLevel"]
    },
    "expression": "http.request.uri.path contains \"/ERP_RMI_SOFULL/api/v1/partner/\"",
    "description": "Bypass challenge untuk H2H API"
  }'
```

> API Token: My Profile → API Tokens → Create Token (perlu izin Zone.WAF Edit).

---

## Opsi C: IP Access Rule (Whitelist Hermina)

Jika Hermina punya IP publik tetap:

1. **Security** → **WAF** → **Tools** → **IP Access Rules**
2. **Add rule:** IP = `[IP Hermina]`, Action = **Allow**
3. **Deploy**

---

## Verifikasi

Setelah diatur, test dari luar:

```bash
curl -H "X-API-Key: rpk_xxx" "https://erp.rizqullahmediska.com/ERP_RMI_SOFULL/api/v1/partner/health.php"
```

**Expected:** `{"ok":true,...}` — bukan halaman "Just a moment...".

---

## Catatan

- Internal (http://10.10.60.20) tidak lewat Cloudflare → tetap jalan
- Production (https://erp.rizqullahmediska.com) perlu atur Cloudflare agar Hermina bisa hit API

---

## Troubleshooting

| Masalah | Solusi |
|--------|--------|
| **Configuration Rule BIC Off sudah ada, masih challenge** | Configuration Rule ≠ WAF Skip rule. Buat **WAF Custom Rule** (Security → WAF → Custom rules) dengan action **Skip** → products: BIC, Security Level. |
| **Security Level tidak ada di UI** | Di Free plan bisa tidak muncul. Gunakan WAF Skip rule (skip products) atau API. |
| **Rule sudah dibuat, masih "Just a moment..."** | 1) Pastikan rule di **WAF Custom rules**, bukan Configuration Rules. 2) Cek Bot Fight Mode = Off. 3) Uji matikan BIC global (Langkah 2) untuk konfirmasi. |
| **Zone erp vs root** | Jika `erp.rizqullahmediska.com` zone terpisah, atur rule di zone tersebut. |
