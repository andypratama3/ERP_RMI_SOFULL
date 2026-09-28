# Rancangan — Customer Portal (Opsi 3)

**Tujuan:** Hermina Purchasing (dan customer lain) bisa login ke portal, lihat katalog, pilih produk, checkout → create DO otomatis. Staff CRM mendapat notifikasi.

---

## 1. Ringkasan Arsitektur

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         CUSTOMER PORTAL                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│  [Login] → [Dashboard] → [Katalog] → [Cart] → [Checkout] → [Riwayat Order]   │
│                                                                              │
│  Auth: customer_portal_users (tabel terpisah, bukan master_system_login)      │
│  Output: sales_do + sales_do_items (status: crm_to_wqs)                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         ERP RMI (Internal)                                   │
│  Staff CRM: sales_do.php → review DO dari portal → proses lanjut (WQS→SCM)   │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Prinsip:** Customer tidak pakai `master_system_login`. Role RBAC tetap: manager, staff, sys. Portal punya auth sendiri.

---

## 2. Database Schema

### 2.1 Tabel `customer_portal_users`

```sql
CREATE TABLE customer_portal_users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,

  -- Binding ke customer
  customers_code VARCHAR(50) NOT NULL,
  office_code VARCHAR(30) DEFAULT NULL,  -- office RMI yang cover customer ini

  status VARCHAR(20) NOT NULL DEFAULT 'active',
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_username (username),
  KEY idx_customers_code (customers_code),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Catatan:**
- `customers_code` → wajib ada di `master_customers`
- `office_code` → office RMI yang menangani (dari `master_office`), untuk generate DO code
- Satu customer bisa punya banyak user (mis. Purchasing Hermina Depok, Purchasing Hermina Serpong)

### 2.2 Tabel `sales_do` — Tambahan Kolom (Opsional)

Untuk tracking order dari portal:

```sql
ALTER TABLE sales_do
  ADD COLUMN source VARCHAR(30) DEFAULT 'crm' COMMENT 'crm|portal|api|marketplace',
  ADD COLUMN portal_user_id INT DEFAULT NULL COMMENT 'FK customer_portal_users.id';
```

**Alternatif:** Pakai `note` saja: `"Order dari Portal - Hermina Depok"` atau flag `source` enum.

---

## 3. Autentikasi Portal

### 3.1 Login Flow

| Langkah | Deskripsi |
|---------|-----------|
| 1 | User buka `/customer_portal/login.php` |
| 2 | Input username + password |
| 3 | Validasi `customer_portal_users` (password_verify) |
| 4 | Cek `status = 'active'` |
| 5 | Cek `customers_code` ada di `master_customers` dan status active |
| 6 | Set session: `$_SESSION['portal_user_id']`, `$_SESSION['portal_customers_code']`, dll |
| 7 | Redirect ke `/customer_portal/index.php` (dashboard) |

### 3.2 Session Keys

```php
$_SESSION['portal_user_id']       = (int)
$_SESSION['portal_username']      = (string)
$_SESSION['portal_full_name']   = (string)
$_SESSION['portal_customers_code'] = (string)
$_SESSION['portal_office_code']   = (string)  // office RMI
```

### 3.3 Guard

```php
// customer_portal/_auth.php
function require_portal_login(): void {
    if (empty($_SESSION['portal_user_id']) || empty($_SESSION['portal_customers_code'])) {
        header('Location: ' . BASE . '/customer_portal/login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}
```

---

## 4. Struktur Modul

```
customer_portal/
├── _auth.php              # require_portal_login(), session helpers
├── _bootstrap.php         # DB, config, include path
├── login.php              # Form login
├── logout.php             # Destroy session, redirect
├── index.php              # Dashboard (ringkasan order, quick actions)
├── catalog.php            # Katalog produk (filter by customer pricelist)
├── cart.php               # Keranjang belanja (session-based)
├── checkout.php           # Submit order → create sales_do
├── orders.php             # Riwayat order (read-only)
├── order_detail.php       # Detail order (read-only)
├── assets/                # CSS/JS khusus portal (opsional)
└── layout.php             # Layout portal (header, footer, sidebar)
```

---

## 5. Halaman & Fitur

### 5.1 Login (`login.php`)

- Form: username, password
- CSRF token
- Rate limit (opsional, pakai LoginThrottle jika ada)
- Redirect ke `index.php` atau `next` param

### 5.2 Dashboard (`index.php`)

- Selamat datang, nama user + customer
- Ringkasan: jumlah order bulan ini, status terakhir
- Quick action: "Buat Order Baru" → catalog
- Link: Katalog, Riwayat Order, Logout

### 5.3 Katalog (`catalog.php`)

- Ambil produk dari `master_products` (status = active)
- Filter: hanya produk yang punya pricelist untuk `customers_code` ATAU pakai default `master_products.price`
- Logic harga:
  ```sql
  -- Prioritas: master_pricelist (customers_code) > master_pricelist (office_code) > master_products.price
  SELECT p.id, p.sku, p.products_name, p.unit, p.price,
         COALESCE(pl.sell_price, p.price) AS effective_price
  FROM master_products p
  LEFT JOIN master_pricelist pl ON pl.sku = p.sku
    AND (pl.customers_code = :customers_code OR pl.customers_code IS NULL)
    AND pl.status = 1 AND (pl.deleted_at IS NULL)
  WHERE p.status = 'active'
  ORDER BY p.products_name
  ```
- Tambah ke cart: POST ke `cart.php?action=add` (product_id, qty)

### 5.4 Cart (`cart.php`)

- Session-based: `$_SESSION['portal_cart']` = array of [product_id, sku, name, qty, unit_price, unit]
- Tampilkan: daftar item, qty, subtotal, total
- Edit qty, hapus item
- Tombol: "Checkout"

### 5.5 Checkout (`checkout.php`)

- Form: office_code (multi-office, dropdown jika >1 kantor), shipping_address (default dari master_customers), customer_pic, customer_phone, note
- Validasi: cart tidak kosong
- Submit → create `sales_do` + `sales_do_items`
- Logic create DO (mirip `sales_do.php`):
  - `do_code`: generate_do_code (RMI-{office}-YYMMDD-XXX)
  - `customers_code`: dari session
  - `office_code`: dari session (portal user)
  - `status`: crm_to_wqs
  - `source`: portal (jika kolom ada)
  - `portal_user_id`: id user (jika kolom ada)
- Kosongkan cart
- Redirect ke `order_detail.php?id={do_id}` dengan pesan sukses

### 5.6 Riwayat Order (`orders.php`)

- List DO dari `sales_do` WHERE `customers_code` = session
- Kolom: do_code, do_date, status, grand_total
- Filter: status, tanggal ✅
- Link ke detail

### 5.7 Detail Order (`order_detail.php`)

- Read-only: tampilkan DO + items
- Tombol: print (jika ada sales_do_print_cf.php yang bisa diakses)

---

## 6. Kelola User Portal (Master Data)

### 6.1 Halaman Kelola

**Lokasi:** `master/master_customer_portal_users.php` (akses: SYS, CRM, atau permission khusus)

**Fitur:**
- List: username, full_name, customers_code, office_code, status, last_login
- Tambah: username, password, full_name, email, phone, customers_code, office_code
- Edit: ubah data, reset password
- Nonaktifkan: status = inactive

**Validasi:**
- `customers_code` harus ada di `master_customers` dan status active
- `office_code` harus ada di `master_office`

---

## 7. Integrasi dengan sales_do

### 7.1 Alur Create DO dari Portal

```
Portal Checkout
    → Validasi cart
    → Ambil customers_code, office_code dari session
    → Generate do_code
    → INSERT sales_do (status=crm_to_wqs)
    → INSERT sales_do_items
    → log_sales_do_audit (jika ada)
    → Kosongkan cart
```

### 7.2 Perbedaan dengan CRM Manual

| Aspek | CRM (sales_do.php) | Portal (checkout) |
|-------|-------------------|-------------------|
| Customer | Pilih dari dropdown | Dari session (fixed) |
| Office | Pilih dari dropdown | Dari session (fixed) |
| Items | Input manual | Dari cart |
| sales_emp_code | Bisa diisi | NULL |
| source | crm | portal |

---

## 8. Keamanan

| Aspek | Implementasi |
|-------|--------------|
| **CSRF** | csrf_token() di semua form |
| **XSS** | rmi_h() di output |
| **SQL Injection** | Prepared statements |
| **Password** | password_hash (bcrypt), password_verify |
| **Session** | session_regenerate_id setelah login |
| **Isolasi** | Portal hanya lihat DO milik customers_code sendiri |
| **Rate limit** | LoginThrottle (opsional) |

---

## 9. UI/UX

- **Layout:** Responsif, mobile-friendly (karena device di lokasi)
- **Theme:** Bisa beda dengan ERP internal (lebih bersih, fokus order)
- **Branding:** Logo RMI, nama customer di header

---

## 10. Fase Implementasi

### Fase 1 — MVP (2–3 minggu)
1. Migration: `customer_portal_users`
2. `customer_portal/login.php`, `logout.php`, `_auth.php`
3. `customer_portal/index.php` (dashboard sederhana)
4. `customer_portal/catalog.php` (list produk, harga default)
5. `customer_portal/cart.php` (session cart)
6. `customer_portal/checkout.php` (create DO)
7. `master/master_customer_portal_users.php` (kelola user)

### Fase 2 — Enhancement ✅
1. `customer_portal/orders.php`, `order_detail.php` ✅
2. Pricelist per customer di katalog ✅
3. Kolom `source`, `portal_user_id` di sales_do ✅ (migration 137)
4. Badge "Portal" di list DO di sales_do.php ✅
5. Seed demo user (hermina_demo / password) ✅ (migration 138)
6. Print DO dari portal ✅ (customer_portal/do_print.php)
7. Branding RMI di login & layout ✅

### Fase 3 — Opsional
1. ~~Print DO dari portal~~ ✅
2. Notifikasi ke CRM: ✅ Alert di Sales Dashboard (order portal hari ini)
3. Link Customer Portal di Sales Dashboard ✅
4. Upload dokumen pendukung — ✅ (order_detail.php, migration 150, sales_do_view tampilkan docs)
5. Multi-office — ✅ (dropdown pilih kantor RMI saat checkout jika >1 office)
6. Filter Riwayat Order — ✅ (status, tanggal)
7. Email notifikasi ke CRM — ✅ (saat order dibuat, ke ALERT_EMAIL_TO / CUSTOMER_PORTAL_CRM_EMAIL)

---

## 11. URL & Routing

| URL | Halaman | Auth |
|-----|---------|------|
| `/customer_portal/login.php` | Login | Public |
| `/customer_portal/logout.php` | Logout | Portal |
| `/customer_portal/` atau `index.php` | Dashboard | Portal |
| `/customer_portal/catalog.php` | Katalog | Portal |
| `/customer_portal/cart.php` | Cart | Portal |
| `/customer_portal/checkout.php` | Checkout | Portal |
| `/customer_portal/orders.php` | Riwayat | Portal |
| `/customer_portal/order_detail.php?id=X` | Detail order | Portal |

**Base path:** Sesuaikan dengan struktur project. Jika di subfolder: `/ERP_RMI_SOFULL/customer_portal/`.

---

## 12. Mapping PIC

| Pihak | PIC | Peran |
|-------|-----|-------|
| **Hermina** | Purchasing | Login portal, pilih produk, checkout |
| **RMI** | Staff CRM | Kelola user portal, review DO dari portal, proses lanjut |
| **RMI** | SYS/Admin | Setup user portal, pricelist per customer |

---

## 13. Checklist Sebelum Go-Live

- [ ] Migration `customer_portal_users` dijalankan: `sql/migrations/136_customer_portal_users.sql` (atau buka `master/master_customer_portal_users.php` — auto-create)
- [ ] Minimal 1 user portal dibuat untuk Hermina (customers_code, office_code benar)
- [ ] Produk di master_products status active
- [ ] Pricelist (jika pakai) sudah diisi untuk customer
- [ ] Staff CRM paham flow: DO dari portal muncul di sales_do.php
- [ ] URL portal di-bookmark di device Hermina

---

*Dokumen ini sebagai acuan implementasi. Sesuaikan dengan kebutuhan bisnis.*
