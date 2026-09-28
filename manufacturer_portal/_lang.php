<?php
/**
 * manufacturer_portal/_lang.php
 * Multi-language: English, 中文, Indonesia.
 */
declare(strict_types=1);

if (!function_exists('mportal_lang')) {
    function mportal_lang(): string {
        $lang = $_SESSION['mportal_lang'] ?? $_COOKIE['mportal_lang'] ?? '';
        if (in_array($lang, ['en', 'zh', 'id'], true)) {
            return $lang;
        }
        return 'zh'; // default: most manufacturers are from China
    }
}

if (!function_exists('mportal_set_lang')) {
    function mportal_set_lang(string $lang): void {
        if (in_array($lang, ['en', 'zh', 'id'], true)) {
            $_SESSION['mportal_lang'] = $lang;
            setcookie('mportal_lang', $lang, time() + 86400 * 365, '/');
        }
    }
}

if (!function_exists('mportal_t')) {
    function mportal_t(string $key): string {
        static $langs = null;
        if ($langs === null) {
            $langs = mportal_translations();
        }
        $lang = mportal_lang();
        $row = $langs[$key] ?? null;
        if ($row === null) {
            return $key;
        }
        return (string)($row[$lang] ?? $row['en'] ?? $key);
    }
}

function mportal_translations(): array {
    return [
        'portal_title' => [
            'en' => 'RMI Manufacturer Portal',
            'zh' => 'RMI 制造商门户',
            'id' => 'Portal Manufacturer RMI',
        ],
        'reg_alkes' => [
            'en' => 'Medical Device Registration & Manufacturer Cooperation',
            'zh' => '医疗器械注册与制造商合作',
            'id' => 'Reg Alkes & Kerjasama Pabrikan',
        ],
        'dashboard' => [
            'en' => 'Dashboard',
            'zh' => '仪表板',
            'id' => 'Dashboard',
        ],
        'partnership_proposal' => [
            'en' => 'Partnership Proposal',
            'zh' => '合作提案',
            'id' => 'Penawaran Kerjasama',
        ],
        'rfq' => [
            'en' => 'Request Quotation (RFQ)',
            'zh' => '询价 (RFQ)',
            'id' => 'Request Quotation (RFQ)',
        ],
        'reg_alkes_cases' => [
            'en' => 'Reg Alkes Cases',
            'zh' => '注册案例',
            'id' => 'Case Reg Alkes',
        ],
        'logout' => [
            'en' => 'Logout',
            'zh' => '退出',
            'id' => 'Logout',
        ],
        'welcome' => [
            'en' => 'Welcome,',
            'zh' => '欢迎，',
            'id' => 'Selamat datang,',
        ],
        'active_cases' => [
            'en' => 'Active Cases',
            'zh' => '进行中案例',
            'id' => 'Case Aktif',
        ],
        'pending_upload' => [
            'en' => 'Pending Upload',
            'zh' => '待上传',
            'id' => 'Menunggu Upload',
        ],
        'btn_partnership' => [
            'en' => 'Partnership Proposal — Upload Catalog, Quotation, Proposal',
            'zh' => '合作提案 — 上传目录、报价、提案',
            'id' => 'Penawaran Kerjasama — Upload Katalog, Quotation, Proposal',
        ],
        'btn_view_cases' => [
            'en' => 'View Reg Alkes Cases',
            'zh' => '查看注册案例',
            'id' => 'Lihat Case Reg Alkes',
        ],
        'upload_per_case' => [
            'en' => 'Upload Documents per Case',
            'zh' => '按案例上传文件',
            'id' => 'Upload Dokumen per Case',
        ],
        'select_case_upload' => [
            'en' => 'Select a case below to upload documents (REG_DOSSIER, IFU_ID, AKSESORIS_LIST, etc.):',
            'zh' => '选择下方案例上传文件（注册档案、IFU、配件清单等）：',
            'id' => 'Pilih case di bawah untuk upload dokumen case (REG_DOSSIER, IFU_ID, AKSESORIS_LIST, dll):',
        ],
        'upload_catalog_quotation' => [
            'en' => 'Upload catalog, quotation, proposal, and company profile. PQP RMI will review and contact you after agreement.',
            'zh' => '上传目录、报价、提案和公司简介。PQP RMI 审核后将与您联系。',
            'id' => 'Upload katalog, quotation, proposal penawaran, dan company profile. PQP RMI akan review dan menghubungi Anda setelah kesepakatan.',
        ],
        'btn_upload_now' => [
            'en' => 'Upload Proposal Now',
            'zh' => '立即上传提案',
            'id' => 'Upload Penawaran Sekarang',
        ],
        'no_case_msg' => [
            'en' => 'After partnership agreement, PQP will create Reg Alkes Case. Case documents will appear here.',
            'zh' => '合作达成后，PQP 将创建注册案例。案例文件将在此显示。',
            'id' => 'Setelah kesepakatan kerjasama, PQP akan membuat case Reg Alkes. Untuk dokumen per case (REG_DOSSIER, IFU_ID, dll), case akan muncul di sini.',
        ],
        'info_flow' => [
            'en' => 'Info Flow',
            'zh' => '流程说明',
            'id' => 'Info Flow',
        ],
        'flow_step1' => [
            'en' => '1. Partnership Proposal — Upload catalog, quotation, proposal. PQP reviews.',
            'zh' => '1. 合作提案 — 上传目录、报价、提案。PQP 审核。',
            'id' => '1. Penawaran Kerjasama — Upload katalog, quotation, proposal. PQP review.',
        ],
        'flow_step2' => [
            'en' => '2. Agreement — PQP agrees → creates Reg Alkes Case.',
            'zh' => '2. 达成 — PQP 同意 → 创建注册案例。',
            'id' => '2. Kesepakatan — PQP setuju kerjasama → buat case Reg Alkes.',
        ],
        'flow_step3' => [
            'en' => '3. Case Documents — Upload REG_DOSSIER, IFU_ID, PKS, LOA per case.',
            'zh' => '3. 案例文件 — 按案例上传 REG_DOSSIER、IFU_ID、PKS、LOA。',
            'id' => '3. Dokumen Case — Upload REG_DOSSIER, IFU_ID, PKS, LOA sesuai case.',
        ],
        'need_help' => [
            'en' => 'Need help? Contact PQP RMI: +62 812 1389 1595',
            'zh' => '需要帮助？请联系 PQP RMI：+62 812 1389 1595',
            'id' => 'Butuh bantuan? Hubungi PQP RMI: +62 812 1389 1595',
        ],
        'login_title' => [
            'en' => 'Login — Manufacturer Portal RMI',
            'zh' => '登录 — RMI 制造商门户',
            'id' => 'Login — Manufacturer Portal RMI',
        ],
        'login_desc' => [
            'en' => 'Sign in to upload registration documents.',
            'zh' => '登录以上传注册文件。',
            'id' => 'Masuk untuk upload dokumen registrasi.',
        ],
        'username' => [
            'en' => 'Username',
            'zh' => '用户名',
            'id' => 'Username',
        ],
        'password' => [
            'en' => 'Password',
            'zh' => '密码',
            'id' => 'Password',
        ],
        'btn_signin' => [
            'en' => 'Sign In',
            'zh' => '登录',
            'id' => 'Masuk',
        ],
        'forgot_password' => [
            'en' => 'Forgot password? Contact PQP RMI to reset.',
            'zh' => '忘记密码？请联系 PQP RMI 重置。',
            'id' => 'Lupa password? Hubungi PQP RMI untuk reset.',
        ],
        'err_required' => [
            'en' => 'Username and password are required.',
            'zh' => '请输入用户名和密码。',
            'id' => 'Username dan password wajib diisi.',
        ],
        'err_invalid' => [
            'en' => 'Invalid username or password.',
            'zh' => '用户名或密码错误。',
            'id' => 'Username atau password salah.',
        ],
        'err_inactive' => [
            'en' => 'Account inactive. Contact PQP RMI.',
            'zh' => '账户未激活。请联系 PQP RMI。',
            'id' => 'Akun tidak aktif. Hubungi PQP RMI.',
        ],
        'err_manufacture' => [
            'en' => 'Manufacturer not found or already deleted. Contact RMI.',
            'zh' => '制造商未找到或已删除。请联系 RMI。',
            'id' => 'Manufacture tidak ditemukan atau sudah dihapus. Hubungi RMI.',
        ],
        'err_throttle' => [
            'en' => 'Too many failed attempts. Try again in %d minutes.',
            'zh' => '尝试次数过多。请 %d 分钟后重试。',
            'id' => 'Terlalu banyak percobaan gagal. Coba lagi dalam %d menit.',
        ],
        'back_dashboard' => [
            'en' => '← Dashboard',
            'zh' => '← 仪表板',
            'id' => '← Dashboard',
        ],
        'partnership_proposal_title' => [
            'en' => 'Partnership Proposal',
            'zh' => '合作提案',
            'id' => 'Penawaran Kerjasama',
        ],
        'upload_catalog_desc' => [
            'en' => 'Upload latest catalog, quotation, proposal, and company profile for PQP RMI review. After agreement, upload PKS, LOA, LOA_KBRI.',
            'zh' => '上传最新目录、报价、提案和公司简介供 PQP RMI 审核。达成后上传 PKS、LOA、LOA_KBRI。',
            'id' => 'Upload katalog terbaru, quotation, proposal penawaran, dan company profile untuk diajukan ke PQP RMI. Setelah kesepakatan, lanjutkan upload PKS, LOA, LOA_KBRI.',
        ],
        'doc_type' => [
            'en' => 'Document Type',
            'zh' => '文件类型',
            'id' => 'Tipe Dokumen',
        ],
        'file' => [
            'en' => 'File',
            'zh' => '文件',
            'id' => 'File',
        ],
        'note' => [
            'en' => 'Note',
            'zh' => '备注',
            'id' => 'Catatan',
        ],
        'optional' => [
            'en' => 'Optional',
            'zh' => '可选',
            'id' => 'Opsional',
        ],
        'btn_upload' => [
            'en' => 'Upload',
            'zh' => '上传',
            'id' => 'Upload',
        ],
        'status' => [
            'en' => 'Status',
            'zh' => '状态',
            'id' => 'Status',
        ],
        'has' => [
            'en' => 'Has',
            'zh' => '已有',
            'id' => 'Ada',
        ],
        'pending' => [
            'en' => 'Pending',
            'zh' => '待上传',
            'id' => 'Belum',
        ],
        'format_max' => [
            'en' => 'Format: PDF, DOC, DOCX, XLS, XLSX, PNG, JPG, ZIP. Max 10MB.',
            'zh' => '格式：PDF、DOC、DOCX、XLS、XLSX、PNG、JPG、ZIP。最大 10MB。',
            'id' => 'Format: PDF, DOC, DOCX, XLS, XLSX, PNG, JPG, ZIP. Maks 10MB.',
        ],
        'doc_catalog' => [
            'en' => 'Product Catalog',
            'zh' => '产品目录',
            'id' => 'Katalog Produk',
        ],
        'doc_quotation' => [
            'en' => 'Quotation / Price Proposal',
            'zh' => '报价单',
            'id' => 'Quotation / Penawaran Harga',
        ],
        'doc_penawaran' => [
            'en' => 'Partnership Proposal',
            'zh' => '合作提案',
            'id' => 'Proposal Penawaran Kerjasama',
        ],
        'doc_company_profile' => [
            'en' => 'Company Profile',
            'zh' => '公司简介',
            'id' => 'Company Profile',
        ],
        'doc_pks' => [
            'en' => 'PKS (Partnership Agreement)',
            'zh' => 'PKS（合作协议）',
            'id' => 'PKS (Perjanjian Kerjasama)',
        ],
        'doc_loa' => [
            'en' => 'LOA (Letter of Authorization)',
            'zh' => 'LOA（授权书）',
            'id' => 'LOA (Letter of Authorization)',
        ],
        'doc_loa_kbri' => [
            'en' => 'LOA KBRI',
            'zh' => 'LOA KBRI',
            'id' => 'LOA KBRI',
        ],
        'cases_title' => [
            'en' => 'Reg Alkes Cases',
            'zh' => '注册案例',
            'id' => 'Case Reg Alkes',
        ],
        'cases_for' => [
            'en' => 'Cases for manufacture:',
            'zh' => '制造商案例：',
            'id' => 'Case dengan manufacture code:',
        ],
        'upload_hint' => [
            'en' => 'Upload documents: Click Detail & Upload on a case row to open the upload form.',
            'zh' => '上传文件：点击案例行的「详情与上传」打开上传表单。',
            'id' => 'Upload dokumen: Klik tombol Detail & Upload pada baris case untuk membuka form upload.',
        ],
        'case_code' => [
            'en' => 'Case Code',
            'zh' => '案例编号',
            'id' => 'Case Code',
        ],
        'product' => [
            'en' => 'Product',
            'zh' => '产品',
            'id' => 'Produk',
        ],
        'stage' => [
            'en' => 'Stage',
            'zh' => '阶段',
            'id' => 'Stage',
        ],
        'deadline' => [
            'en' => 'Revision Deadline',
            'zh' => '修订截止',
            'id' => 'Deadline Revisi',
        ],
        'action' => [
            'en' => 'Action',
            'zh' => '操作',
            'id' => 'Aksi',
        ],
        'detail_upload' => [
            'en' => 'Detail & Upload',
            'zh' => '详情与上传',
            'id' => 'Detail & Upload',
        ],
        'no_cases' => [
            'en' => 'No cases for your manufacturer. Contact PQP RMI if cases should appear.',
            'zh' => '暂无您的制造商案例。如有案例应显示，请联系 PQP RMI。',
            'id' => 'Belum ada case untuk manufacture Anda. Hubungi PQP RMI jika case seharusnya tampil.',
        ],
        'back_cases' => [
            'en' => '← Back to Cases',
            'zh' => '← 返回案例列表',
            'id' => '← Kembali ke Daftar Case',
        ],
        'upload_form_hint' => [
            'en' => 'Upload form is below. Select document type, choose file, then click Upload.',
            'zh' => '上传表单在下方。选择文件类型、选择文件，然后点击上传。',
            'id' => 'Form upload ada di bawah. Pilih tipe dokumen, pilih file, lalu klik Upload.',
        ],
        'upload_documents' => [
            'en' => 'Upload Documents',
            'zh' => '上传文件',
            'id' => 'Upload Dokumen',
        ],
        'document' => [
            'en' => 'Document',
            'zh' => '文件',
            'id' => 'Dokumen',
        ],
        'manufacture_not_found' => [
            'en' => 'Manufacturer not found. Contact PQP RMI: +62 812 1389 1595',
            'zh' => '未找到制造商。请联系 PQP RMI：+62 812 1389 1595',
            'id' => 'Manufacture tidak ditemukan. Hubungi PQP RMI: +62 812 1389 1595',
        ],
        'note_optional' => [
            'en' => 'Note (optional)',
            'zh' => '备注（可选）',
            'id' => 'Catatan (opsional)',
        ],
        'uploaded_docs' => [
            'en' => 'Uploaded Documents',
            'id' => 'Dokumen Terupload',
            'zh' => '已上传文件',
        ],
        'manu_docs_pks_loa' => [
            'en' => 'Manufacturer Documents (PKS, LOA, LOA_KBRI)',
            'zh' => '制造商文件（PKS、LOA、LOA_KBRI）',
            'id' => 'Dokumen Manufacture (PKS, LOA, LOA_KBRI)',
        ],
        'manu_docs_desc' => [
            'en' => 'These documents are used for Stage 4+. One upload applies to all cases for this manufacturer.',
            'zh' => '这些文件用于第 4 阶段及以上。一次上传适用于该制造商的所有案例。',
            'id' => 'Dokumen ini dipakai untuk Stage 4+. Satu upload berlaku untuk semua case manufacture ini.',
        ],
        'type' => [
            'en' => 'Type',
            'zh' => '类型',
            'id' => 'Tipe',
        ],
        'product_label' => [
            'en' => 'Product:',
            'zh' => '产品：',
            'id' => 'Produk:',
        ],
        'stage_label' => [
            'en' => 'Stage:',
            'zh' => '阶段：',
            'id' => 'Stage:',
        ],
        'status_label' => [
            'en' => 'Status:',
            'zh' => '状态：',
            'id' => 'Status:',
        ],
        'revision_deadline' => [
            'en' => 'Revision Deadline:',
            'zh' => '修订截止：',
            'id' => 'Deadline Revisi:',
        ],
        'no_docs' => [
            'en' => 'No documents yet.',
            'zh' => '暂无文件。',
            'id' => 'Belum ada dokumen.',
        ],
        'date' => [
            'en' => 'Date',
            'zh' => '日期',
            'id' => 'Tanggal',
        ],
        'lang_en' => ['en' => 'EN', 'zh' => 'EN', 'id' => 'EN'],
        'lang_zh' => ['en' => '中文', 'zh' => '中文', 'id' => '中文'],
        'lang_id' => ['en' => 'ID', 'zh' => 'ID', 'id' => 'ID'],
        'pqp_contact' => [
            'en' => 'PQP RMI: +62 812 1389 1595',
            'zh' => 'PQP RMI：+62 812 1389 1595',
            'id' => 'PQP RMI: +62 812 1389 1595',
        ],
    ];
}
