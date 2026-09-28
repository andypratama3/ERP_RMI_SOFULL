<?php
/**
 * _migration_template_builder.php
 * Shared builder: Excel template migrasi 12 sheet + REF.
 * Dipanggil dari migration_download_template.php (web) dan generate_migration_excel.php (CLI).
 *
 * Dropdown & valid values sesuai form ERP_RMI_SOFULL:
 *   master_vendors.php    → vendor_type, category (dept), status
 *   master_customers.php  → category, segment, office_code, status
 *   master_manufactures.php → origin_type, status
 *   master_products.php   → category, status
 */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Comment;

/** ─── WARNA TEMA ─────────────────────────────── */
const MIG_CLR_HEADER_FILL   = 'D9E1F2'; // biru muda
const MIG_CLR_HEADER_FONT   = '1F3864'; // biru tua
const MIG_CLR_EXAMPLE_FILL  = 'FFF2CC'; // kuning muda
const MIG_CLR_REQUIRED_FILL = 'FCE4D6'; // oranye muda (wajib)
const MIG_CLR_REF_FILL      = 'E2EFDA'; // hijau muda
const MIG_CLR_REF_HEADER    = '375623'; // hijau tua

/** ─── VALID VALUES (sesuai form ERP) ────────── */
const MIG_OFFICE_CODES  = 'BGR,BDG,BKS,TGR,SLO,SMG,JGY,KAL';
const MIG_CURRENCY      = 'IDR,USD,CNY,EUR,SGD';
const MIG_MNF_ORIGIN    = 'Local,Import';
const MIG_MNF_STATUS    = 'active,inactive';
const MIG_VND_TYPE      = 'Forwarding,Logistic,Lainnya';
const MIG_VND_DEPT      = 'ACT,CRM,SCM,WQS,FIN,HRL,PQP,ITC,MPR';
const MIG_VND_STATUS    = 'active,inactive';
const MIG_CUST_CAT      = 'RS Swasta,RS Pemerintah,Internal';
const MIG_CUST_SEG      = 'Hermina,Non Hermina,RSUD,Kantor';
const MIG_CUST_STATUS   = 'active,inactive';
const MIG_PROD_CAT      = 'BMHP,UnitAcc';
const MIG_PROD_STATUS   = 'active,inactive,draft,pending_reg';
const MIG_PROD_UNIT     = 'PCS,BOX,SET,UNIT,PAIR,ROLL,PACK,TUBE,VIAL,AMPUL,BOTOL,STRIP,KAPSUL,TABLET,LITER,ML,GRAM,KG';

/** ─── DATA REAL DARI SISTEM (backup 09-Mar-2026) ─ */
function _mig_real_manufactures(): array
{
    // Hanya principal eksternal (bukan KANTOR-* internal)
    return [
        ['YAXIN',        'Suzhou Yaxin Medical Products Co., Ltd',         'RIZKIMED', 'Import', 'China', 'Suzhou',          'No.12, Zhongta Road, Mudu Town, Suzhou 215101, Jiangsu province, China', '+86 152 5017 8777', 'yaxin@yx-yiliao.com',                    'active'],
        ['NANCHANG',     'Nanchang Kanghua Health Materials Co., Ltd',      'RIZKIMED', 'Import', 'China', 'Jianxian Nanchang','Moonlight Road Medical Device Technology Park, Jinxian Nanchang 331700 Jiangxi China', '+86 153 9791 1825', 'sales6@jxkh.cn',                        'active'],
        ['CATHAY',       'CATHAY MANUFACTURING CORP',                       'CATHAY',   'Import', 'China', 'Shanghai',        'Room 1510, No. 20, Lane 699 Guang Fu Lin Road, Songjiang District, Shanghai, China', '+86 138 1688 9961', 'jenny.feng@cathaymanufacturing.com',    'active'],
        ['EXCELLENTCARE','EXCELLENTCARE MEDICAL (HUIZHOU) LTD',             'RIZKIMED', 'Import', 'China', 'Huizhou',         'Shatou Industrial Zone, Yuanzhou Town, Boluo Country, Huizhou, 516123, Guangdong, China', '0752-6358333-302', 'info@excellentcare.com.cn',         'active'],
        ['CELECARE',     'WENZHOU CELECARE MEDICAL INSTRUMENTS CO., LTD',   'RIZKIMED', 'Import', 'China', 'Wenzhou',         'NO.407 XIAJIN RD(1-4th Floor), JINZHU INDUSTRIAL ZONE, NANBAIXIANG STREET, OUHAI DISTRICT, WENZHOU, ZHEJIANG, CHINA', '+86-577-56708225', 'info@celecare.com', 'active'],
        ['MEDPLUS',      'MEDPLUS INC',                                     'MEDPLUS',  'Import', 'China', 'GUANGZHOU',       '4TH FLOOR, BUILDING6, NO. 586 ZHONGSHUN ROAD, ZHONGCUN, PANYU DISTRICT, GUANGZHOU, 511495, CHINA', '+86 20 3477 3301', 'info@gzmedplus.com',          'active'],
    ];
}

function _mig_real_vendors(): array
{
    return [
        ['FOR001','PT NOATUM LOGISTIC INDONESIA',        'Forwarding','SCM','JAKARTA PUSAT', '021-57941901',      'ricky.kurniawan@noatumlogistics.com','0018045336017000','active'],
        ['FOR003','PT MATS INTERNASIONAL INDONESIA',      'Forwarding','SCM','JAKARTA PUSAT', '021-39837188',      'heri@mii.id',                       '0317560167003000','active'],
        ['FOR004','PT ZEIST GLOBAL SERVICE',              'Forwarding','SCM','JAKARTA UTARA', '021-22455468',      'sales02@zeist.co.id',               '0536721616043000','active'],
        ['FOR005','PT GLOBAL LINK EXPRESS',               'Forwarding','SCM','JAKARTA BARAT', '021-21695069',      'pt.globallinkexpress@gmail.com',     '',               'active'],
        ['FOR009','PT GEN LOGISTIK INDONESIA',            'Forwarding','SCM','Jakarta Selatan','081289943020',     'fajar@genlog.co.id',                '0824179485017000','active'],
        ['VEN003','PT EMIRAD INTERNATIONAL LOGISTICS',   'Lainnya',   'SCM','Depok',         '081904092805',      'mktg.assoc@emiradlogistics.co.id',  '0025683335015000','active'],
        ['VEN004','PT AKBAR PUTRA MANDIRI LOGISTICS',    'Lainnya',   'SCM','Bogor',         '085718293144',      'rudianto@apmlogistics.id',          '0015216260201000','active'],
        ['VEN005','PT MERPATI ALAM SEMESTA KARGO',       'Lainnya',   'SCM','Bogor',         '081317147644',      'reza@mas-kargo.co.id',              '0019968734015000','active'],
        ['VEN006','BARAKA EXPRESS (Bogor)',               'Lainnya',   'SCM','Bogor',         '085770537397',      'customercare@baraka-express.com',   '',               'active'],
        ['VEN007','BARAKA EXPRESS (Semarang)',            'Lainnya',   'SCM','Semarang',      '0895383162130',     'customercare@baraka-express.com',   '',               'active'],
        ['VEN008','BARAKA EXPRESS (Solo)',                'Lainnya',   'SCM','Solo',          '089699114771',      'customercare@baraka-express.com',   '',               'active'],
        ['VEN009','J&T CARGO',                           'Lainnya',   'SCM','Bogor',         '081287044235',      'jntcargocibinong@gmail.com',        '',               'active'],
        ['VEN010','LION PARCEL',                         'Lainnya',   'SCM','Bogor',         '081287044235',      '',                                  '',               'active'],
    ];
}

function _mig_real_customers(): array
{
    // Data RS Hermina & internal dari backup 09-Mar-2026
    // Format: [code, name, category, segment, city, office_code, address, phone, email, npwp, status]
    return [
        // Internal kantor
        ['BGR-INT','Kantor Rizqullah Mediska Indonesia (Internal)',              'Internal','Kantor','','BGR','','','','','active'],
        ['BDG-INT','Kantor Rizqullah Mediska Indonesia Bandung (Internal)',      'Internal','Kantor','','BDG','','','','','active'],
        ['BKS-INT','Kantor Rizqullah Mediska Indonesia Bekasi (Internal)',       'Internal','Kantor','','BKS','','','','','active'],
        ['TGR-INT','Kantor Rizqullah Mediska Indonesia Tangerang (Internal)',    'Internal','Kantor','','TGR','','','','','active'],
        ['SLO-INT','Kantor Rizqullah Mediska Indonesia Jawa Tengah (Solo) (Internal)','Internal','Kantor','','SLO','','','','','active'],
        ['SMG-INT','Kantor Rizqullah Mediska Indonesia Semarang (Internal)',     'Internal','Kantor','','SMG','','','','','active'],
        ['JGY-INT','Kantor Depo Yogyakarta (Internal)',                         'Internal','Kantor','','JGY','','','','','active'],
        ['KAL-INT','Kantor Depo Kalimantan (Internal)',                         'Internal','Kantor','','KAL','','','','','active'],
        ['SYS-INT','Kantor SYS (Internal)',                                     'Internal','Kantor','','SYS','','','','','active'],
        // RS Hermina
        ['H001','RS HERMINA ACEH',            'RS Swasta','Hermina','Kab Aceh Besar',          'BGR','Jl.Soekarno Hatta RT 000 RW 000, Aje Cut Ingin Jaya Kab Aceh Besar Aceh',                    '06518072525','',                                    '42.455.114.1.108.000','active'],
        ['H002','RS HERMINA ARCAMANIK',       'RS Swasta','Hermina','Kota Bandung',            'BGR','Jl. A.H. Nasution KM7 No. 50, Kel. Antapani Wetan, Kec. Antapani, kota Bandung',             '02287242525','farmasi.herminaarca@gmail.com',          '02.789.562.2.429-000','active'],
        ['H003','RS HERMINA BALIKPAPAN',      'RS Swasta','Hermina','Kota Balikpapan',         'BGR','Jl. MT Haryono RT 45, Sepingan Baru Balikpapan, Kalimantan Timur, Indonesia',                '05428515230','farmasi.balikpapan@herminahospitals.com', '74.937.119.1.721.000','active'],
        ['H004','RS HERMINA BANYUMANIK',      'RS Swasta','Hermina','Kota Semarang',           'SMG','Jl. Jenderal Pol Anton Sujarwo No.195A, Srondol Wetan, Banyumanik, Kota Semarang, 50263',   '02476488989','farmasi.banyumanik@gmail.com',           '31.720.421.2-517.000','active'],
        ['H005','RS HERMINA BEKASI',          'RS Swasta','Hermina','Kota Bekasi',             'BKS','Jl. Kemakmuran No.39, RT.004/RW.003, Marga Jaya, Kec. Bekasi Sel., Kota Bks, 17141',         '0218842121', 'pengadaanfarmasi.rshbks@gmail.com',      '01.783.421.9-007.000','active'],
        ['H006','RS HERMINA BITUNG',          'RS Swasta','Hermina','Kabupaten Tangerang',     'TGR','Jl. Raya Serang No.10, Kadu, Kec. Curug, Kabupaten Tangerang, Banten 15810',                 '02159497525','',                                    '73.379.780.7-451.000','active'],
        ['H007','RS HERMINA BOGOR',           'RS Swasta','Hermina','Kota Bogor',              'BGR','Jalan Ring Road I Kav. 23, 25, 27, Curugmekar, Kec. Bogor Bar., Kota Bogor, 16113',           '02518382525','',                                    '02.073.142.8.404.001','active'],
        ['H008','RS HERMINA CIAWI',           'RS Swasta','Hermina','Kabupaten Bogor',         'BGR','Jl. Raya Puncak - Gadog No.23, Pandansari, Kec. Ciawi, Kabupaten Bogor, 16720',              '02518407575','ifrsciawi@gmail.com',                   '08.614.217.1-743.4.000','active'],
        ['H009','RS HERMINA CILEDUG',         'RS Swasta','Hermina','Kota Tangerang',          'TGR','Jl. Cipto Mangunkusumo Gg. H. Mencong No. 3, Ciledug, Tangerang, Banten 15151',              '02173454951','farmasiciledug@gmail.com',              '04.124.281.0.441.6000','active'],
        ['H010','RS HERMINA CILEGON',         'RS Swasta','Hermina','Kota Cilegon',            'TGR','Kawasan PT Bonauli Real Estate, Jl. Bonakarta, Masigit, Kec. Jombang, Kota Cilegon, 42414',  '02547812525','farmasi.herminacilegon@gmail.com',      '86.126.303.6-417.000','active'],
        ['H011','RS HERMINA CIPUTAT',         'RS Swasta','Hermina','Kota Tangerang Selatan',  'TGR','Jl. Ciputat Raya Jl. Kertamukti No.2, Ciputat, Tangerang Selatan, Banten 15419',             '02174702525','farmasi.ciputat@herminahospitals.com',   '31.190.968.3-411.000','active'],
        ['H012','RS HERMINA CIRUAS',          'RS Swasta','Hermina','Kabupaten Serang',        'TGR','Jl. Raya Serang - Jkt No.Km.9, Ranjeng, Kec. Ciruas, Kabupaten Serang, Banten 42182',        '0254281829', 'farmasi.ciruas@herminahospitals.com',    '70.970.383.9-401.000','active'],
        ['H013','RS HERMINA DAAN MOGOT',      'RS Swasta','Hermina','Jakarta Barat',           'TGR','Jl. Kintamani Raya No.2, Kalideres, Kec. Kalideres, Kota Jakarta Barat 11840',               '0215408989', 'farmasi.daanmogot@gmail.com',            '02.073.114.7-038.000','active'],
        ['H014','RS HERMINA DEPOK',           'RS Swasta','Hermina','Kota Depok',              'BGR','Jl. Siliwangi No.50, Depok, Kec. Pancoran Mas, Kota Depok, Jawa Barat 16431',                 '0211500488', '',                                    '01.973.467.2.007.000','active'],
        ['H015','RS HERMINA GALAXY',          'RS Swasta','Hermina','Kabupaten Bekasi',        'BKS','Ruko Grand Galaxy City, Jl. Boulevar Raya Bar., Jaka Setia, Kec. Bekasi Sel., 17147',         '0218222525', 'farmasi.galaxy@herminahospitals.com',    '31.173.812.4-432.000','active'],
        ['H016','RS HERMINA GRAND WISATA',    'RS Swasta','Hermina','Kabupaten Bekasi',        'BKS','Jl. West Gateway Blvd No.1 Blok JA 1, Lambangsari, Tambun Sel., Kabupaten Bekasi 17510',     '02182651212','',                                    '21.026.393.5-431.000','active'],
        ['H017','RS HERMINA NUSANTARA',       'RS Swasta','Hermina','Kabupaten Penajam Paser Utara','BGR','Kawasan Inti Pusat Pemerintahan, Bumi Harapan, Kec. Sepaku, Kab. Penajam Paser Utara, Kaltim','05428252520','farmasi.rsuherminanusantara@gmail.com','00.192.011.5.100.7.000','active'],
        ['H018','RS HERMINA JATINEGARA',      'RS Swasta','Hermina','Jakarta Timur',           'BGR','Jl. Jatinegara Barat 126 Jakarta Timur, DKI Jakarta 13320',                                   '0218513838', 'farmasi.jatinegara@herminahospitals.com','01.920.115.1.007.000','active'],
        ['H019','RS HERMINA KARAWANG',        'RS Swasta','Hermina','Kabupaten Karawang',      'BKS','Jalan Tuparev, Blok Gg. Sukasari No.386A, Karawang Wetan, Kec. Karawang Tim., 41314',        '02678412525','farmasi.karawang@herminahospitals.com', '83.675.630.4-408.000','active'],
        ['H020','RS HERMINA KEMAYORAN',       'RS Swasta','Hermina','Kota Jakarta Pusat',      'BGR','Jl. Selangit Kav. 4, Gn. Sahari Sel., Kec. Kemayoran, Kota Jakarta Pusat 10620',             '02122602525','farmasikemayoran.hermina@gmail.com',    '01.364.468.7-046.000','active'],
        ['H021','RS HERMINA KENDARI',         'RS Swasta','Hermina','Kota Kendari',            'BGR','Jl. DI Panjaitan, Wundudopi, Kec. Baruga, Kota Kendari, Sulawesi Tenggara 93117',             '04013192525','keuangan.kendari@herminahospitals.com', '84.671.659.5.811.000','active'],
        ['H022','RS HERMINA KUTABUMI / PERIUK TANGERANG','RS Swasta','Hermina','Kota Tangerang','TGR','Jl. Moh. Toha, Nagrak, Kec. Periuk, Kota Tangerang, Banten 15131',                           '02129432525','',                                    '85.048.181.3-402.000','active'],
        ['H023','RS HERMINA LAMPUNG',         'RS Swasta','Hermina','Kota Bandar Lampung',     'BGR','Jl. Tulang Bawang No.21-23, Enggal, Kota Bandar Lampung, Lampung 35213',                      '0721242525', 'jangmed.lampung@herminahospitals.com',   '08.648.072.7.632.2.00','active'],
        ['H024','RS HERMINA MADIUN',          'RS Swasta','Hermina','Kota Madiun',             'SLO','Jl. Sido Makmur Jl. Ring Road Barat, Manguharjo, Kec. Manguharjo, Kota Madiun 63127',         '03514108585','farmasi.hermina49@gmail.com',            '94.070.566.8-532.000','active'],
        ['H025','RS HERMINA MAKASSAR',        'RS Swasta','Hermina','Kota Makassar',           'BGR','Jl. Toddopuli Raya Timur No.7, Borong, Kec. Manggala, Kota Makassar, Sulawesi Selatan 90231', '04114091817','',                                    '73.472.002.2.805.000','active'],
        ['H026','RS HERMINA MANADO',          'RS Swasta','Hermina','Kota Manado',             'BGR','Jl. Ring Road Manado II, Paniki Bawah, Kec. Mapanget, Kota Manado, Sulawesi Utara',           '04317242525','farmasi.manado@herminahospitals.com',   '82.392.404.882.1.000','active'],
        ['H027','RS HERMINA MEDAN',           'RS Swasta','Hermina','Kota Medan',              'BGR','Jl. Asrama, Sei Sikambing C. II, Kec. Medan Helvetia, Kota Medan, Sumatera Utara 20123',      '06180862525','farmasi.medan@herminahospitals.com',    '75.709.650.8.124.00','active'],
        ['H028','RS HERMINA MEKARSARI',       'RS Swasta','Hermina','Kabupaten Bogor',         'BGR','Jl. Raya Cileungsi - Jonggol No.Km. 1, Cileungsi Kidul, Kec. Cileungsi, Kab. Bogor 16820',   '02129232525','farmasi.mekarsari@herminahospitals.com','31.417.132.3.436.000','active'],
        ['H029','RS HERMINA METLAND CIBITUNG','RS Swasta','Hermina','Kabupaten Bekasi',        'BKS','Perumahan Metland Cibitung, Jl. Metland Cibitung, Telagamurni, Kec. Cikarang Bar. 17530',     '02188362626','',                                    '91.400.950.1-413.000','active'],
        ['H030','RS HERMINA MUTIARA BUNDA SALATIGA','RS Swasta','Hermina','Kota Salatiga',     'SMG','Jl. Merak No.8, Mangunsari, Kec. Sidomukti, Kota Salatiga, Jawa Tengah 50721',                '0298329500', '',                                    '96.677.893.8-505.000','active'],
        ['H031','RS HERMINA OPI JAKABARING',  'RS Swasta','Hermina','Kabupaten Banyuasin',     'BGR','Jl. Gubernur H. A Bastari, Kel. Jakabaring Selatan, Kec. Rambutan, Kab. Banyuasin, Sumsel 30257','07113031520','farmasi.opijakabaring@herminahospitals.com','08.209.521.1.7314.000','active'],
        ['H032','RS HERMINA PALEMBANG',       'RS Swasta','Hermina','Kota Palembang',          'BGR','Jl. Jend. Basuki Rachmat No.897, Pahlawan, Kec. Kemuning, Kota Palembang, Sumsel 30127',      '1500488',    'keuangan.palembang@herminahospitals.com','',                  'active'],
        ['H033','RS HERMINA PANDANARAN',      'RS Swasta','Hermina','Kota Semarang',           'SMG','Jl. Pandanaran No.24, Pekunden, Kec. Semarang Tengah, Kota Semarang, Jawa Tengah 50134',      '0248442525', 'tukarfakturfarmasiherpanda@gmail.com',  '02.204.515.7-511.000','active'],
        ['H034','RS HERMINA PASTEUR',         'RS Swasta','Hermina','Kota Bandung',            'BDG','Jl. Dr. Djunjunan No.107, Pasteur, Kec. Cicendo, Kota Bandung, Jawa Barat 40173',             '0226072525', 'pengadaanherminapasteur17@gmail.com',   '02.244.242.2-441.000','active'],
        ['H035','RS HERMINA PASURUAN',        'RS Swasta','Hermina','Kabupaten Pasuruan',      'SLO','Jl Raya Pasuruan-Probolinggo Km 5 RT 01 RW 01, Desa Sambirejo Kec Rejoso Kab Pasuruan 67181', '03434742523','farmasi.pasuruan@hermina.com',           '53.126.379.6-446.000','active'],
        ['H036','RS HERMINA PEKALONGAN',      'RS Swasta','Hermina','Kota Pekalongan',         'SMG','Jl. Jenderal Sudirman No.16a, Podosugih, Kec. Pekalongan Bar., Kota Pekalongan, 51112',       '0285432525', 'farmasi.pekalongan@herminahospitals.com','916448483502000',    'active'],
        ['H037','RS HERMINA PEKANBARU',       'RS Swasta','Hermina','Kota Pekanbaru',          'BGR','Jl. Tuanku Tambusai, Delima, Kec. Tampan, Kota Pekanbaru, Riau 28292',                        '07618411919','keuangan.pekanbaru@herminahospitals.com','0836260851216000',   'active'],
        ['H038','RS HERMINA PIK DUA',         'RS Swasta','Hermina','Kabupaten Tangerang',     'TGR','Jl. Raya Boulevard Osaka, Salembaran, Kosambi, Tangerang Regency, Banten 15214',              '1500488',    '',                                    '04.218.818.2.2.418.000','active'],
        ['H039','RS HERMINA PODOMORO',        'RS Swasta','Hermina','Kota Jakarta Utara',      'BGR','Jl. Danau Agung 2 No.28-30, Sunter Agung, Kec. Tj. Priok, Jakarta Utara 14350',              '0216404910', '',                                    '81.666.093.0.048.000','active'],
        ['H040','RS HERMINA PURWOKERTO',      'RS Swasta','Hermina','Kabupaten Banyumas',      'SMG','Jl. Yos Sudarso, Karanglewas Lor, Kec. Purwokerto Bar., Kabupaten Banyumas, 53136',           '02817772525','farmasi.purwokerto@herminahospitals.com','75.830.148.5-521.000','active'],
        ['H041','RS HERMINA SAMARINDA',       'RS Swasta','Hermina','Kota Samarinda',          'BGR','Jl. Teuku Umar No.RT. 34, Karang Asam Ilir, Kec. Sungai Kunjang, Kota Samarinda, Kaltim 75126','05412090707','farmasi.samarinda@herminahospitals.com','80.876.505.1.722.000','active'],
        ['H042','RS HERMINA SERPONG',         'RS Swasta','Hermina','Kota Tangerang Selatan',  'TGR','Jl. Raya Puspitek No.km 1 No 99, Buaran, Kec. Serpong, Kota Tangerang Selatan, 15310',        '02175884999','farmasi.serpong@herminahospitals.com',  '31.823.871.4-411.000','active'],
        ['H043','RS HERMINA SOLO',            'RS Swasta','Hermina','Kota Surakarta',          'SLO','Jl. Kolonel Sutarto No.16, Jebres, Kec. Jebres, Kota Surakarta, Jawa Tengah 57126',           '0271638989', 'gudangfarmasi.solo@gmail.com',          '31.772.971.3-526.000','active'],
        ['H044','RS HERMINA SOREANG',         'RS Swasta','Hermina','Kabupaten Bandung',       'TGR','Jl. Terusan Al Fathu No.9A, Soreang, Kec. Soreang, Kabupaten Bandung, Jawa Barat 40911',      '0225892525', 'farmasisoreang@gmail.com',              '41.244.472.1-445.000','active'],
        ['H045','RS HERMINA SUKABUMI',        'RS Swasta','Hermina','Kabupaten Sukabumi',      'BGR','Jl. Raya Sukaraja, Sukaraja, Kec. Sukaraja, Kabupaten Sukabumi, Jawa Barat 43192',            '02666252525','kontrabonskb@gmail.com',               '02.522.444.5.405.000','active'],
        ['H046','RS HERMINA TANGERANG',       'RS Swasta','Hermina','Kota Tangerang',          'TGR','Jl. Ks. Tubun No.10, Ps. Baru, Kec. Karawaci, Kota Tangerang, Banten 15112',                  '02155772525','',                                    '02.673.095.2-415.000','active'],
        ['H047','RS HERMINA TANGKUBANPRAHU',  'RS Swasta','Hermina','Kota Malang',             'SLO','Jl. Tangkuban Perahu No.29-33, Kauman, Kec. Klojen, Kota Malang, Jawa Timur 65119',           '0341322525', 'hutang.tangkubanprahu@herminahospitals.com','0023481393651000','active'],
        ['H048','RS HERMINA TASIKMALAYA',     'RS Swasta','Hermina','Kabupaten Tasikmalaya',   'BDG','Jl. Ir. H. Juanda No.7A, Cipedes, Kec. Cipedes, Kab. Tasikmalaya, Jawa Barat 46133',          '02653172525','farmasi.tasikmalaya@herminahospitals.com','43.317.218.6-425.000','active'],
        ['H049','RS UBAYA',                   'RS Swasta','Non Hermina','Kota Surabaya',        'SLO','Jl. Raya Panjang Jiwo Permai No.87, Panjang Jiwo, Kec. Tenggilis Mejoyo, Surabaya 60299',    '03199211515','gu.farmasi@rs.ubaya.ac.id',             '91.076.254.1.606.000','active'],
        ['H050','RS HERMINA WONOGIRI',        'RS Swasta','Hermina','Kabupaten Wonogiri',      'SLO','Jl. Raya Wonogiri-Ponorogo No.KM. 5, Jatibedug, Purworejo, Kec. Wonogiri, Jateng 57612',      '02735327365','farmasi.wonogiri@herminahospitals.com', '94.070.566.8-532.000','active'],
        ['H051','RS HERMINA YOGYA',           'RS Swasta','Hermina','Kabupaten Sleman',        'BGR','Jl. Selokan Mataram, Meguwo, Maguwoharjo, Kec. Depok, Kabupaten Sleman, DIY 55282',            '02742800808','farmasi.yogya@herminahospitals.com',   '04.218.818.2.2.418.000','active'],
    ];
}

function _mig_real_products(): array
{
    // 3 produk yang ada di sistem — ini data sample awal, tim perlu lengkapi
    return [
        ['OBT-001', 'Obat A',   null,   'BMHP', 'unit', '8991234567890', 'active'],
        ['OBT-002', 'Obat B',   null,   'BMHP', 'unit', '',              'active'],
        ['ALK-001', 'Alkes A',  null,   'BMHP', 'unit', '',              'active'],
    ];
}

function _mig_real_office(): array
{
    return [
        ['BGR', 'Rizqullah Mediska Indonesia',                        'Kab. Bogor',     'Jalan Pondok Rajeg, Ruko Sentra Pondok Rajeg No. 7 & 8, Kel. Pondok Rajeg, Kec. Cibinong, Kabupaten Bogor, Provinsi Jawa Barat, 16914', '0852-8336-4900', -6.597147,  106.806039, 150, 'active'],
        ['BKS', 'Rizqullah Mediska Indonesia Bekasi',                 'Kota Bekasi',    'Perumahan The East View Residence Blok F 17, Jl. Raya Mustika Sari, Kel. Mustikasari, Kec. Mustikajaya, Kota Bekasi, Provinsi Jawa Barat, 17157', '0852-8336-4900', -6.238270,  106.975570, 150, 'active'],
        ['TGR', 'Rizqullah Mediska Indonesia Tangerang',              'Kota Tangerang', 'Jalan Muhamad Toha No. B26 Km. 0,6, Kel. Periuk, Kec. Periuk, Kota Tangerang, Provinsi Banten, 15131', '0813-8425-1574', -6.178306,  106.631889, 150, 'active'],
        ['BDG', 'Rizqullah Mediska Indonesia Bandung',                'Kota Bandung',   'Ruko Puri Dago Mas Unit 428, Jalan Terusan Jakarta, Kota Bandung, Provinsi Jawa Barat, 40293', '0812-1067-5863', -6.917464,  107.619123, 150, 'active'],
        ['SLO', 'Rizqullah Mediska Indonesia Jawa Tengah (Solo)',     'Kota Surakarta', 'Jalan Pakel No. 06, Kel. Banyuanyar, Kec. Banjarsari, Kota Surakarta, Provinsi Jawa Tengah, 57137', '0852-8336-4900', -7.566667,  110.816667, 150, 'active'],
        ['SMG', 'Rizqullah Mediska Indonesia Semarang',               'Kota Semarang',  'Ruko Tlogo Timun Mas No. 1A Kav. C, Kel. Tlogosari Kulon, Kec. Pedurungan, Kota Semarang, Provinsi Jawa Tengah, 50196', '0852-8336-4900', -6.966667,  110.416667, 150, 'active'],
        ['KAL', 'Depo Kalimantan',                                    'Kalimantan',     '', '',         null,       null,       120, 'active'],
        ['JGY', 'Depo Yogyakarta',                                    'Yogyakarta',     '', '',         null,       null,       120, 'active'],
        ['SYS', 'SYS',                                                'SYS',            '', '',         null,       null,       120, 'inactive'],
    ];
}

/** ─── ENTRY POINT ───────────────────────────── */
function mig_build_spreadsheet(): Spreadsheet
{
    $ss = new Spreadsheet();
    $ss->getProperties()->setTitle('RMI Migration Template')->setCreator('ERP RMI SOFULL');

    $sheets = [
        '1_Manufactures' => '_mig_sheet_manufactures',
        '2_Vendors'      => '_mig_sheet_vendors',
        '3_Customers'    => '_mig_sheet_customers',
        '4_Products'     => '_mig_sheet_products',
        '5_Stock'        => '_mig_sheet_stock',
        '6_AP'           => '_mig_sheet_ap',
        '7_AR'           => '_mig_sheet_ar',
        '8_Office'       => '_mig_sheet_office',
        '9_Bank'         => '_mig_sheet_bank',
        '10_GL_Opening'  => '_mig_sheet_gl',
        '11_FixedAssets' => '_mig_sheet_fa',
        '12_Cara_Pengisian' => '_mig_sheet_guide',
        'REF_ValidValues'   => '_mig_sheet_ref',
    ];

    $idx = 0;
    foreach ($sheets as $title => $fn) {
        $sheet = $idx === 0 ? $ss->getActiveSheet() : $ss->createSheet();
        $sheet->setTitle(substr($title, 0, 31));
        $fn($sheet);
        $idx++;
    }

    $ss->setActiveSheetIndex(0);
    return $ss;
}

/** ─── HELPER: STYLE HEADER ──────────────────── */
function _mig_header_style(array $rgb_fill, array $rgb_font = []): array
{
    $font = ['bold' => true];
    if ($rgb_font) $font['color'] = ['rgb' => implode('', $rgb_font)];

    return [
        'font' => array_merge(['bold' => true, 'color' => ['rgb' => MIG_CLR_HEADER_FONT]], $font),
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb_fill[0]]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B0B0B0']]],
    ];
}

/** ─── HELPER: WRITE HEADERS ─────────────────── */
function _mig_write_headers(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers): void
{
    // Row 1: nama kolom (bold, biru)
    // Row 2: keterangan singkat (abu)
    $col = 1;
    foreach ($headers as $field => $info) {
        $colLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($colLetter . '1', $field);
        $sheet->setCellValue($colLetter . '2', $info['desc'] ?? '');

        // Header style
        $sheet->getStyle($colLetter . '1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => ($info['required'] ?? false) ? 'FF0000' : MIG_CLR_HEADER_FONT],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => ($info['required'] ?? false) ? MIG_CLR_REQUIRED_FILL : MIG_CLR_HEADER_FILL],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => false],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B0B0B0']]],
        ]);
        // Desc row style
        $sheet->getStyle($colLetter . '2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => '666666']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);

        // Column width
        $sheet->getColumnDimension($colLetter)->setWidth($info['width'] ?? 18);
        $col++;
    }
    $sheet->getRowDimension(1)->setRowHeight(20);
    $sheet->getRowDimension(2)->setRowHeight(28);
    $sheet->freezePane('A3');
}

/** ─── HELPER: WRITE EXAMPLES ────────────────── */
function _mig_write_examples(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers, array $examples, bool $isRealData = false): void
{
    // Warna: hijau muda = data real dari sistem, kuning = contoh baru
    $fillRgb = $isRealData ? 'E8F5E9' : MIG_CLR_EXAMPLE_FILL;
    $fontRgb = $isRealData ? '1B5E20' : '595959';

    $rowNum = 3;
    foreach ($examples as $ex) {
        $col = 1;
        foreach (array_keys($headers) as $field) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($colLetter . $rowNum, $ex[$field] ?? '');
            $sheet->getStyle($colLetter . $rowNum)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillRgb]],
                'font' => ['color' => ['rgb' => $fontRgb], 'size' => 9],
            ]);
            $col++;
        }
        $rowNum++;
    }

    if ($isRealData) {
        // Baris pemisah "tambah data baru di bawah ini"
        $maxCol = count($headers);
        $lastColLetter = Coordinate::stringFromColumnIndex($maxCol);
        $sheet->mergeCells('A' . $rowNum . ':' . $lastColLetter . $rowNum);
        $sheet->setCellValue('A' . $rowNum, '⬇  Tambahkan data baru di baris berikutnya');
        $sheet->getStyle('A' . $rowNum)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => '0D47A1'], 'size' => 9],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension($rowNum)->setRowHeight(16);
    }
}

/** ─── HELPER: Aktifkan proteksi sheet ──────── */
function _mig_protect_sheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    // Unlock semua sel dulu (agar hanya yang di-lock eksplisit yang terkunci)
    $sheet->getStyle('A1:Z600')->getProtection()
        ->setLocked(Protection::PROTECTION_UNPROTECTED);
    // Aktifkan proteksi — tanpa password agar bisa dibuka manual di Excel jika perlu
    $sheet->getProtection()->setSheet(true);
}

/** ─── HELPER: Kunci range sel tertentu ──────── */
function _mig_lock_range(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->getProtection()
        ->setLocked(Protection::PROTECTION_PROTECTED);
}

/** ─── HELPER: RINGKASAN / SUMMARY SECTION ──── */
function _mig_add_summary(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $lastCol, int $startRow, array $rows): void
{
    // Separator header
    $sheet->mergeCells('A' . $startRow . ':' . $lastCol . $startRow);
    $sheet->setCellValue('A' . $startRow, '📊  RINGKASAN — hanya untuk verifikasi, tidak diupload');
    $sheet->getStyle('A' . $startRow)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '374151'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getRowDimension($startRow)->setRowHeight(16);

    $r = $startRow + 1;
    foreach ($rows as [$label, $formula]) {
        $sheet->setCellValue('A' . $r, $label);
        $sheet->setCellValue('B' . $r, $formula);
        $sheet->getStyle('A' . $r)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '374151'], 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
        ]);
        $sheet->getStyle('B' . $r)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '1D4ED8'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getColumnDimension('B')->setWidth(max((float)$sheet->getColumnDimension('B')->getWidth(), 22));
        $r++;
    }
}

/** ─── HELPER: DATA VALIDATION (static list) ─── */
function _mig_add_dropdown(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $colLetter, int $fromRow, int $toRow, string $list): void
{
    $range = $colLetter . $fromRow . ':' . $colLetter . $toRow;
    $v = $sheet->getCell($colLetter . $fromRow)->getDataValidation();
    $v->setType(DataValidation::TYPE_LIST);
    $v->setErrorStyle(DataValidation::STYLE_INFORMATION);
    $v->setAllowBlank(true);
    // setShowDropDown(true) = TAMPILKAN panah dropdown
    // (PhpSpreadsheet invertkan logika OOXML: true=show, false=hide)
    $v->setShowDropDown(true);
    $v->setShowErrorMessage(true);
    $v->setErrorTitle('Nilai tidak valid');
    $v->setError('Pilih dari dropdown atau ketik nilai yang sesuai.');
    $v->setFormula1('"' . $list . '"');
    $sheet->setDataValidation($range, $v);
}

/** ─── HELPER: DATA VALIDATION (dynamic range dari sheet lain) ─── */
function _mig_add_dropdown_range(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $colLetter, int $fromRow, int $toRow, string $rangeFormula): void
{
    // rangeFormula contoh: "'1_Manufactures'!$A$4:$A$503"
    $range = $colLetter . $fromRow . ':' . $colLetter . $toRow;
    $v = $sheet->getCell($colLetter . $fromRow)->getDataValidation();
    $v->setType(DataValidation::TYPE_LIST);
    $v->setErrorStyle(DataValidation::STYLE_INFORMATION);
    $v->setAllowBlank(true);
    // setShowDropDown(true) = TAMPILKAN panah dropdown
    $v->setShowDropDown(true);
    $v->setShowErrorMessage(true);
    $v->setErrorTitle('Kode tidak ditemukan');
    $v->setError('Pilih dari dropdown — kode harus ada di sheet master terkait.');
    $v->setFormula1($rangeFormula);
    $sheet->setDataValidation($range, $v);
}

/** ─── SHEET: 1_MANUFACTURES ─────────────────── */
function _mig_sheet_manufactures(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    $fields = ['manufacture_code','manufacture_name','brand_name','origin_type','country','city','address','phone','email','status'];
    $headers = [
        'manufacture_code' => ['desc' => 'Kode unik principal *WAJIB*', 'required' => true, 'width' => 16],
        'manufacture_name' => ['desc' => 'Nama lengkap principal *WAJIB*', 'required' => true, 'width' => 32],
        'brand_name'       => ['desc' => 'Nama brand/merek dagang', 'width' => 16],
        'origin_type'      => ['desc' => 'Local / Import', 'width' => 12],
        'country'          => ['desc' => 'Negara asal', 'width' => 14],
        'city'             => ['desc' => 'Kota', 'width' => 14],
        'address'          => ['desc' => 'Alamat lengkap', 'width' => 36],
        'phone'            => ['desc' => 'No. telepon', 'width' => 18],
        'email'            => ['desc' => 'Alamat email', 'width' => 28],
        'status'           => ['desc' => 'active / inactive', 'width' => 12],
    ];

    // Banner info
    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->setCellValue('A1', '✅  Data hijau = sudah ada di sistem (akan di-skip saat upload). Tambahkan principal baru di bawah baris biru.');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '1B5E20'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C8E6C9']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(16);

    // Header row 2, desc row 3
    $col = 1;
    foreach ($headers as $field => $info) {
        $cl = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($cl . '2', $field);
        $sheet->setCellValue($cl . '3', $info['desc']);
        $sheet->getStyle($cl . '2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ($info['required'] ?? false) ? 'FF0000' : MIG_CLR_HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ($info['required'] ?? false) ? MIG_CLR_REQUIRED_FILL : MIG_CLR_HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle($cl . '3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => '666666']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getColumnDimension($cl)->setWidth($info['width'] ?? 18);
        $col++;
    }
    $sheet->getRowDimension(2)->setRowHeight(20);
    $sheet->getRowDimension(3)->setRowHeight(24);
    $sheet->freezePane('A4');

    // Data real (hijau)
    $realData = _mig_real_manufactures();
    $rowNum = 4;
    foreach ($realData as $row) {
        $col = 1;
        foreach ($fields as $f) {
            $cl = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($cl . $rowNum, $row[$col - 1] ?? '');
            $sheet->getStyle($cl . $rowNum)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
                'font' => ['color' => ['rgb' => '1B5E20'], 'size' => 9],
            ]);
            $col++;
        }
        $rowNum++;
    }

    // Baris pemisah
    $sheet->mergeCells('A' . $rowNum . ':' . $lastCol . $rowNum);
    $sheet->setCellValue('A' . $rowNum, '⬇  Tambahkan principal baru di baris berikutnya');
    $sheet->getStyle('A' . $rowNum)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '0D47A1'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    _mig_add_dropdown($sheet, 'D', 4, 500, MIG_MNF_ORIGIN);
    _mig_add_dropdown($sheet, 'J', 4, 500, MIG_MNF_STATUS);

    // Proteksi: lock kode manufacture di baris hijau agar tidak bisa diubah
    _mig_protect_sheet($sheet);
    _mig_lock_range($sheet, 'A1:' . $lastCol . '3');                    // banner + headers
    _mig_lock_range($sheet, 'A4:A' . ($rowNum - 1));                    // manufacture_code di baris hijau
    _mig_lock_range($sheet, 'A' . $rowNum . ':' . $lastCol . $rowNum); // separator biru
}

/** ─── SHEET: 2_VENDORS ──────────────────────── */
function _mig_sheet_vendors(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    $fields  = ['vendors_code','vendors_name','vendor_type','category','city','phone','email','npwp','status'];
    $headers = [
        'vendors_code' => ['desc' => 'Kode unik vendor *WAJIB*', 'required' => true, 'width' => 14],
        'vendors_name' => ['desc' => 'Nama vendor *WAJIB*', 'required' => true, 'width' => 34],
        'vendor_type'  => ['desc' => 'Forwarding / Logistic / Lainnya', 'width' => 14],
        'category'     => ['desc' => 'Dept: SCM,ACT,FIN,dll', 'width' => 12],
        'city'         => ['desc' => 'Kota vendor', 'width' => 16],
        'phone'        => ['desc' => 'No. telepon', 'width' => 16],
        'email'        => ['desc' => 'Alamat email', 'width' => 28],
        'npwp'         => ['desc' => 'NPWP (format: 01.234.567.8-999.000)', 'width' => 22],
        'status'       => ['desc' => 'active / inactive', 'width' => 12],
    ];

    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->setCellValue('A1', '✅  Data hijau = sudah ada di sistem. Tambahkan vendor baru di bawah baris biru.');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '1B5E20'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C8E6C9']],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(16);

    $col = 1;
    foreach ($headers as $field => $info) {
        $cl = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($cl . '2', $field);
        $sheet->setCellValue($cl . '3', $info['desc']);
        $sheet->getStyle($cl . '2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ($info['required'] ?? false) ? 'FF0000' : MIG_CLR_HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ($info['required'] ?? false) ? MIG_CLR_REQUIRED_FILL : MIG_CLR_HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle($cl . '3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => '666666']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getColumnDimension($cl)->setWidth($info['width'] ?? 18);
        $col++;
    }
    $sheet->getRowDimension(2)->setRowHeight(20);
    $sheet->getRowDimension(3)->setRowHeight(24);
    $sheet->freezePane('A4');

    $realData = _mig_real_vendors();
    $rowNum   = 4;
    foreach ($realData as $row) {
        $col = 1;
        foreach ($fields as $f) {
            $cl = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($cl . $rowNum, $row[$col - 1] ?? '');
            $sheet->getStyle($cl . $rowNum)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
                'font' => ['color' => ['rgb' => '1B5E20'], 'size' => 9],
            ]);
            $col++;
        }
        $rowNum++;
    }

    $sheet->mergeCells('A' . $rowNum . ':' . $lastCol . $rowNum);
    $sheet->setCellValue('A' . $rowNum, '⬇  Tambahkan vendor baru di baris berikutnya');
    $sheet->getStyle('A' . $rowNum)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '0D47A1'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    _mig_add_dropdown($sheet, 'C', 4, 500, MIG_VND_TYPE);
    _mig_add_dropdown($sheet, 'D', 4, 500, MIG_VND_DEPT);
    _mig_add_dropdown($sheet, 'I', 4, 500, MIG_VND_STATUS);

    // Proteksi: lock kode vendor di baris hijau
    _mig_protect_sheet($sheet);
    _mig_lock_range($sheet, 'A1:' . $lastCol . '3');                    // banner + headers
    _mig_lock_range($sheet, 'A4:A' . ($rowNum - 1));                    // vendors_code di baris hijau
    _mig_lock_range($sheet, 'A' . $rowNum . ':' . $lastCol . $rowNum); // separator biru
}

/** ─── SHEET: 3_CUSTOMERS ────────────────────── */
function _mig_sheet_customers(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    // F=office_code (dropdown→8_Office), L=office_name (formula🔒)
    $fields  = ['customers_code','customers_name','category','segment','city','office_code','address','phone','email','npwp','status'];
    $headers = [
        'customers_code' => ['desc' => 'Kode unik customer *WAJIB*', 'required' => true, 'width' => 14],
        'customers_name' => ['desc' => 'Nama customer *WAJIB*', 'required' => true, 'width' => 36],
        'category'       => ['desc' => 'RS Swasta / RS Pemerintah / Internal', 'width' => 16],
        'segment'        => ['desc' => 'Hermina / Non Hermina / RSUD / Kantor', 'width' => 14],
        'city'           => ['desc' => 'Kota customer', 'width' => 20],
        'office_code'    => ['desc' => 'Kode office ← pilih dari dropdown', 'width' => 14],
        'address'        => ['desc' => 'Alamat lengkap', 'width' => 40],
        'phone'          => ['desc' => 'No. telepon / WA', 'width' => 16],
        'email'          => ['desc' => 'Alamat email', 'width' => 28],
        'npwp'           => ['desc' => 'NPWP (format: 01.234.567.8-999.000)', 'width' => 22],
        'status'         => ['desc' => 'active / inactive', 'width' => 12],
        'office_name'    => ['desc' => 'Nama kantor — otomatis dari office_code 🔒', 'formula' => true, 'width' => 22],
    ];

    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->setCellValue('A1', '✅  Data hijau = sudah ada di sistem (skip saat upload). Tambahkan customer baru di bawah baris biru. Total: ' . count(_mig_real_customers()) . ' customer.');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '1B5E20'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C8E6C9']],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(16);

    $col = 1;
    foreach ($headers as $field => $info) {
        $cl = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($cl . '2', $field);
        $sheet->setCellValue($cl . '3', $info['desc']);
        $sheet->getStyle($cl . '2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ($info['required'] ?? false) ? 'FF0000' : MIG_CLR_HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ($info['required'] ?? false) ? MIG_CLR_REQUIRED_FILL : MIG_CLR_HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle($cl . '3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => '666666']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getColumnDimension($cl)->setWidth($info['width'] ?? 18);
        $col++;
    }
    $sheet->getRowDimension(2)->setRowHeight(20);
    $sheet->getRowDimension(3)->setRowHeight(24);
    $sheet->freezePane('A4');

    $realData = _mig_real_customers();
    $rowNum   = 4;
    foreach ($realData as $row) {
        $col = 1;
        foreach ($fields as $i => $f) {
            $cl = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($cl . $rowNum, $row[$i] ?? '');
            $sheet->getStyle($cl . $rowNum)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
                'font' => ['color' => ['rgb' => '1B5E20'], 'size' => 9],
            ]);
            $col++;
        }
        $rowNum++;
    }

    $sheet->mergeCells('A' . $rowNum . ':' . $lastCol . $rowNum);
    $sheet->setCellValue('A' . $rowNum, '⬇  Tambahkan customer baru di baris berikutnya');
    $sheet->getStyle('A' . $rowNum)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '0D47A1'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    // Formula office_name (col L) untuk semua baris 4–503 — auto dari 8_Office
    for ($r = 4; $r <= 503; $r++) {
        $sheet->setCellValue('L' . $r,
            "=IF(F{$r}=\"\",\"\",IFERROR(INDEX('8_Office'!\$B\$3:\$B\$15,MATCH(F{$r},'8_Office'!\$A\$3:\$A\$15,0)),\"⚠ Kode tidak ditemukan\"))");
        $sheet->getStyle('L' . $r)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FF']],
            'font' => ['color' => ['rgb' => '1E40AF'], 'size' => 9, 'italic' => true],
        ]);
    }

    _mig_add_dropdown($sheet, 'C', 4, 503, MIG_CUST_CAT);
    _mig_add_dropdown($sheet, 'D', 4, 503, MIG_CUST_SEG);
    // office_code (F): dropdown dinamis dari 8_Office (bukan hardcode)
    _mig_add_dropdown_range($sheet, 'F', 4, 503, "'8_Office'!\$A\$3:\$A\$15");
    _mig_add_dropdown($sheet, 'K', 4, 503, MIG_CUST_STATUS);

    // Summary totals
    _mig_add_summary($sheet, $lastCol, 506, [
        ['Total customer diisi :', '=COUNTA(A4:A503)'],
    ]);

    // Proteksi: lock kode customer + formula office_name
    _mig_protect_sheet($sheet);
    _mig_lock_range($sheet, 'A1:' . $lastCol . '3');                    // banner + headers
    _mig_lock_range($sheet, 'A4:A' . ($rowNum - 1));                    // customers_code baris hijau
    _mig_lock_range($sheet, 'L4:L503');                                  // formula office_name
    _mig_lock_range($sheet, 'A' . $rowNum . ':' . $lastCol . $rowNum); // separator biru
}

/** ─── SHEET: 4_PRODUCTS ─────────────────────── */
function _mig_sheet_products(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    // A=sku, B=products_name, C=manufacture_code (dropdown→Manufactures),
    // D=manufacture_name (formula🔒), E=category, F=unit, G=barcode, H=status
    $headers = [
        'sku'              => ['desc' => 'Kode produk unik *WAJIB*', 'required' => true, 'width' => 16],
        'products_name'    => ['desc' => 'Nama produk *WAJIB*', 'required' => true, 'width' => 40],
        'manufacture_code' => ['desc' => 'Kode principal ← pilih dari dropdown', 'width' => 20],
        'manufacture_name' => ['desc' => 'Nama principal — otomatis dari kode 🔒', 'width' => 34],
        'category'         => ['desc' => 'BMHP = habis pakai / UnitAcc = alat', 'width' => 14],
        'unit'             => ['desc' => 'Satuan (PCS, BOX, SET, dll)', 'width' => 12],
        'barcode'          => ['desc' => 'Barcode / EAN produk (opsional)', 'width' => 18],
        'status'           => ['desc' => 'active / inactive / draft / pending_reg', 'width' => 16],
    ];

    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->setCellValue('A1',
        '⚠  Data kuning = produk awal/sample. ' .
        'manufacture_code: pilih dari dropdown → nama principal (col D) otomatis terisi 🔒. ' .
        'Tambah produk baru di bawah baris biru.');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '856404'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // Header rows 2-3
    $col = 1;
    foreach ($headers as $field => $info) {
        $cl        = Coordinate::stringFromColumnIndex($col);
        $isFormula = ($field === 'manufacture_name');
        $sheet->setCellValue($cl . '2', $field);
        $sheet->setCellValue($cl . '3', $info['desc']);
        $sheet->getStyle($cl . '2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ($info['required'] ?? false) ? 'FF0000' : MIG_CLR_HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => [
                'rgb' => $isFormula ? 'DBEAFE' : (($info['required'] ?? false) ? MIG_CLR_REQUIRED_FILL : MIG_CLR_HEADER_FILL),
            ]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle($cl . '3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => $isFormula ? '1E40AF' : '666666']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $isFormula ? 'EFF6FF' : 'F5F5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getColumnDimension($cl)->setWidth($info['width'] ?? 18);
        $col++;
    }
    $sheet->getRowDimension(2)->setRowHeight(20);
    $sheet->getRowDimension(3)->setRowHeight(28);
    $sheet->freezePane('A4');

    // Formula manufacture_name (col D) untuk semua baris 4–503 — auto dari 1_Manufactures
    for ($r = 4; $r <= 503; $r++) {
        $sheet->setCellValue('D' . $r,
            "=IF(C{$r}=\"\",\"\",IFERROR(INDEX('1_Manufactures'!\$B\$4:\$B\$503,MATCH(C{$r},'1_Manufactures'!\$A\$4:\$A\$503,0)),\"⚠ Kode tidak ditemukan\"))");
        $sheet->getStyle('D' . $r)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FF']],
            'font' => ['color' => ['rgb' => '1E40AF'], 'size' => 9, 'italic' => true],
        ]);
    }

    // Data produk awal/sample (kuning) — _mig_real_products(): [sku,name,mnf_code,cat,unit,barcode,status]
    $realData = _mig_real_products();
    $rowNum   = 4;
    $styleY   = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF9C4']],
                 'font' => ['color' => ['rgb' => '795548'], 'size' => 9, 'italic' => true]];
    foreach ($realData as $prod) {
        $sheet->setCellValue('A' . $rowNum, $prod[0] ?? '');       // sku
        $sheet->setCellValue('B' . $rowNum, $prod[1] ?? '');       // products_name
        $sheet->setCellValue('C' . $rowNum, $prod[2] ?? '');       // manufacture_code
        // D = formula (sudah di-set di loop atas)
        $sheet->setCellValue('E' . $rowNum, $prod[3] ?? '');       // category
        $sheet->setCellValue('F' . $rowNum, $prod[4] ?? 'PCS');    // unit
        $sheet->setCellValue('G' . $rowNum, $prod[5] ?? '');       // barcode
        $sheet->setCellValue('H' . $rowNum, $prod[6] ?? 'active'); // status
        foreach (['A','B','C','E','F','G','H'] as $cl) {
            $sheet->getStyle($cl . $rowNum)->applyFromArray($styleY);
        }
        $rowNum++;
    }

    // Catatan + separator
    $sheet->setCellValue('A' . $rowNum, '  ↑ Data di atas = produk awal/sample. Lengkapi atau tambah produk baru di bawah.');
    $sheet->mergeCells('A' . $rowNum . ':' . $lastCol . $rowNum);
    $sheet->getStyle('A' . $rowNum)->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '795548'], 'size' => 8],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF9C4']],
    ]);
    $rowNum++;
    $sheet->mergeCells('A' . $rowNum . ':' . $lastCol . $rowNum);
    $sheet->setCellValue('A' . $rowNum, '⬇  Tambahkan produk baru di baris berikutnya');
    $sheet->getStyle('A' . $rowNum)->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => '0D47A1'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getRowDimension($rowNum)->setRowHeight(16);

    // manufacture_code (C): dropdown dinamis dari 1_Manufactures
    _mig_add_dropdown_range($sheet, 'C', 4, 503, "'1_Manufactures'!\$A\$4:\$A\$503");
    // category (E), unit (F), status (H): dropdown statis
    _mig_add_dropdown($sheet, 'E', 4, 503, MIG_PROD_CAT);
    _mig_add_dropdown($sheet, 'F', 4, 503, MIG_PROD_UNIT);
    _mig_add_dropdown($sheet, 'H', 4, 503, MIG_PROD_STATUS);

    // Proteksi: hanya kolom D (formula manufacture_name) yang dikunci
    _mig_protect_sheet($sheet);
    _mig_lock_range($sheet, 'A1:' . $lastCol . '3'); // banner + headers
    _mig_lock_range($sheet, 'D4:D503');              // formula manufacture_name
    _mig_lock_range($sheet, 'A' . $rowNum . ':' . $lastCol . $rowNum); // separator
}

/** ─── SHEET: 5_STOCK ────────────────────────── */
function _mig_sheet_stock(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    // SKU (A) & Nama Produk (B) = formula otomatis dari sheet 4_Products (terkunci).
    // Tim hanya perlu isi qty_on_hand (C) dan office_code (D).
    $headers = [
        'sku'           => ['desc' => 'Otomatis dari sheet 4_Products — 🔒 jangan diubah', 'required' => true, 'width' => 20],
        'products_name' => ['desc' => 'Otomatis dari sheet 4_Products — 🔒 jangan diubah', 'width' => 40],
        'qty_on_hand'   => ['desc' => 'Stok fisik per tanggal cutover ← ISI DI SINI *WAJIB*', 'required' => true, 'width' => 20],
        'office_code'   => ['desc' => 'Kode office: BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL', 'width' => 14],
    ];

    $lastCol  = Coordinate::stringFromColumnIndex(count($headers));
    $prodSheet = '4_Products'; // nama sheet sumber SKU & nama produk

    // Banner
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->setCellValue('A1',
        '🔗  Kolom SKU & Nama Produk otomatis terhubung (formula) ke sheet 4_Products. ' .
        'Tim hanya perlu mengisi qty_on_hand (kolom C) dan office_code (kolom D). ' .
        'Tambah produk baru di sheet 4_Products → otomatis muncul di sini.');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '0D47A1'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // Header rows 2-3
    $col = 1;
    foreach ($headers as $field => $info) {
        $cl = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($cl . '2', $field);
        $sheet->setCellValue($cl . '3', $info['desc'] ?? '');
        $sheet->getStyle($cl . '2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ($info['required'] ?? false) ? 'FF0000' : MIG_CLR_HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ($info['required'] ?? false) ? MIG_CLR_REQUIRED_FILL : MIG_CLR_HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle($cl . '3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => '555555']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F4FF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getColumnDimension($cl)->setWidth($info['width'] ?? 16);
        $col++;
    }
    $sheet->getRowDimension(2)->setRowHeight(20);
    $sheet->getRowDimension(3)->setRowHeight(30);
    $sheet->freezePane('A4');

    // Formula rows 4–503 (500 produk):
    // Col A = SKU dari Products, Col B = Nama dari Products
    // Col C = qty kosong (tim isi), Col D = office default BGR
    $maxRows = 500;
    for ($i = 0; $i < $maxRows; $i++) {
        $prodRow  = 4 + $i; // baris data di sheet 4_Products (data mulai baris 4)
        $stockRow = 4 + $i; // baris yg sama di Stock

        // Formula: ambil SKU & nama dari Products, tampilkan kosong jika Products-nya kosong
        $sheet->setCellValue('A' . $stockRow,
            "=IF('{$prodSheet}'!A{$prodRow}=\"\",\"\",'{$prodSheet}'!A{$prodRow})");
        $sheet->setCellValue('B' . $stockRow,
            "=IF('{$prodSheet}'!B{$prodRow}=\"\",\"\",'{$prodSheet}'!B{$prodRow})");

        // Style col A-B: biru muda = formula/terkunci
        $sheet->getStyle('A' . $stockRow . ':B' . $stockRow)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FF']],
            'font' => ['color' => ['rgb' => '1E40AF'], 'size' => 9, 'italic' => true],
        ]);
        // Style col C-D: putih bersih = tim isi
        $sheet->getStyle('C' . $stockRow . ':D' . $stockRow)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
            'font' => ['color' => ['rgb' => '111827'], 'size' => 9],
        ]);
    }

    // office_code (D): dropdown dinamis dari 8_Office
    _mig_add_dropdown_range($sheet, 'D', 4, 503, "'8_Office'!\$A\$3:\$A\$15");

    // Summary totals untuk cross-check vs sistem lama
    _mig_add_summary($sheet, $lastCol, 506, [
        ['Produk dengan stok (qty > 0) :', '=COUNTIF(C4:C503,">"&0)'],
        ['Total qty keseluruhan         :', '=SUM(C4:C503)'],
    ]);

    // Proteksi: lock banner + headers + kolom A-B (formula) agar tim tidak override
    _mig_protect_sheet($sheet);
    _mig_lock_range($sheet, 'A1:' . $lastCol . '3'); // banner + headers
    _mig_lock_range($sheet, 'A4:B503');              // semua formula SKU + nama
}

/** ─── SHEET: 6_AP ───────────────────────────── */
function _mig_sheet_ap(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    // D=manufacture_code (dropdown→Manufactures), E=manufacture_name (formula🔒)
    // F=office_code, G=currency, H=balance_amount, I=note
    $headers = [
        'invoice_number'   => ['desc' => 'No. invoice hutang *WAJIB*', 'required' => true, 'width' => 22],
        'invoice_date'     => ['desc' => 'Tanggal invoice (YYYY-MM-DD) *WAJIB*', 'required' => true, 'width' => 18],
        'due_date'         => ['desc' => 'Tanggal jatuh tempo (YYYY-MM-DD)', 'width' => 18],
        'manufacture_code' => ['desc' => 'Kode principal ← pilih dari dropdown *WAJIB*', 'required' => true, 'width' => 20],
        'manufacture_name' => ['desc' => 'Nama principal — otomatis dari kode 🔒', 'formula' => true, 'width' => 32],
        'office_code'      => ['desc' => 'Kode office (BGR, BDG, dst)', 'width' => 14],
        'currency'         => ['desc' => 'IDR / USD / CNY / EUR / SGD', 'width' => 12],
        'balance_amount'   => ['desc' => 'Saldo hutang per cutover *WAJIB*', 'required' => true, 'width' => 18],
        'note'             => ['desc' => 'Catatan (opsional)', 'width' => 28],
    ];

    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->setCellValue('A1',
        '⚙  Hutang ke principal. manufacture_code: pilih dari dropdown → nama principal (col E) otomatis 🔒. ' .
        'Tanggal format: YYYY-MM-DD. Kolom migration_batch & row_no otomatis.');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '155724'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D4EDDA']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    _mig_write_ap_ar_sheet($sheet, $headers, [
        [
            'invoice_number'   => 'INV-AP-2026-001',
            'invoice_date'     => '2026-01-31',
            'due_date'         => '2026-02-28',
            'manufacture_code' => 'YAXIN',
            'manufacture_name' => '',
            'office_code'      => 'BGR',
            'currency'         => 'IDR',
            'balance_amount'   => 12500000,
            'note'             => 'Hutang opening cutover',
        ],
        [
            'invoice_number'   => 'INV-AP-2026-002',
            'invoice_date'     => '2026-01-15',
            'due_date'         => '2026-02-15',
            'manufacture_code' => 'CATHAY',
            'manufacture_name' => '',
            'office_code'      => 'BGR',
            'currency'         => 'USD',
            'balance_amount'   => 5000,
            'note'             => 'Hutang USD opening',
        ],
    ]);

    // Formula manufacture_name (col E) untuk semua baris 4–503
    for ($r = 4; $r <= 503; $r++) {
        $sheet->setCellValue('E' . $r,
            "=IF(D{$r}=\"\",\"\",IFERROR(INDEX('1_Manufactures'!\$B\$4:\$B\$503,MATCH(D{$r},'1_Manufactures'!\$A\$4:\$A\$503,0)),\"⚠ Kode tidak ditemukan\"))");
        $sheet->getStyle('E' . $r)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FF']],
            'font' => ['color' => ['rgb' => '1E40AF'], 'size' => 9, 'italic' => true],
        ]);
    }

    // manufacture_code (D): dropdown dari 1_Manufactures
    _mig_add_dropdown_range($sheet, 'D', 4, 503, "'1_Manufactures'!\$A\$4:\$A\$503");
    // office_code (F): dropdown dinamis dari 8_Office; currency (G): static
    _mig_add_dropdown_range($sheet, 'F', 4, 503, "'8_Office'!\$A\$3:\$A\$15");
    _mig_add_dropdown($sheet, 'G', 4, 503, MIG_CURRENCY);

    // Summary untuk cross-check saldo hutang
    _mig_add_summary($sheet, $lastCol, 506, [
        ['Total invoice hutang :', '=COUNTA(A4:A503)'],
        ['Total balance (semua currency) :', '=SUM(H4:H503)'],
    ]);

    // Proteksi: lock formula manufacture_name
    _mig_protect_sheet($sheet);
    _mig_lock_range($sheet, 'A1:' . $lastCol . '3'); // banner + headers
    _mig_lock_range($sheet, 'E4:E503');              // formula manufacture_name
}

/** ─── SHEET: 7_AR ───────────────────────────── */
function _mig_sheet_ar(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    // D=customers_code (dropdown→Customers), E=customers_name (formula🔒)
    // F=office_code, G=currency, H=balance_amount, I=note
    $headers = [
        'invoice_number'  => ['desc' => 'No. invoice piutang *WAJIB*', 'required' => true, 'width' => 22],
        'invoice_date'    => ['desc' => 'Tanggal invoice (YYYY-MM-DD) *WAJIB*', 'required' => true, 'width' => 18],
        'due_date'        => ['desc' => 'Tanggal jatuh tempo (YYYY-MM-DD)', 'width' => 18],
        'customers_code'  => ['desc' => 'Kode customer ← pilih dari dropdown *WAJIB*', 'required' => true, 'width' => 18],
        'customers_name'  => ['desc' => 'Nama customer — otomatis dari kode 🔒', 'formula' => true, 'width' => 36],
        'office_code'     => ['desc' => 'Kode office (BGR, BDG, dst)', 'width' => 14],
        'currency'        => ['desc' => 'IDR / USD / CNY / EUR / SGD', 'width' => 12],
        'balance_amount'  => ['desc' => 'Saldo piutang per cutover *WAJIB*', 'required' => true, 'width' => 18],
        'note'            => ['desc' => 'Catatan (opsional)', 'width' => 28],
    ];

    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->setCellValue('A1',
        '⚙  Piutang dari customer. customers_code: pilih dari dropdown → nama customer (col E) otomatis 🔒. ' .
        'Tanggal format: YYYY-MM-DD. Kolom migration_batch & row_no otomatis.');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '155724'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D4EDDA']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    _mig_write_ap_ar_sheet($sheet, $headers, [
        [
            'invoice_number'  => 'INV-AR-2026-001',
            'invoice_date'    => '2026-01-31',
            'due_date'        => '2026-02-28',
            'customers_code'  => 'H001',
            'customers_name'  => '',
            'office_code'     => 'BGR',
            'currency'        => 'IDR',
            'balance_amount'  => 9800000,
            'note'            => 'Piutang opening cutover',
        ],
        [
            'invoice_number'  => 'INV-AR-2026-002',
            'invoice_date'    => '2026-01-20',
            'due_date'        => '2026-02-20',
            'customers_code'  => 'H007',
            'customers_name'  => '',
            'office_code'     => 'BGR',
            'currency'        => 'IDR',
            'balance_amount'  => 5500000,
            'note'            => 'Piutang opening cutover',
        ],
    ]);

    // Formula customers_name (col E) untuk semua baris 4–503
    for ($r = 4; $r <= 503; $r++) {
        $sheet->setCellValue('E' . $r,
            "=IF(D{$r}=\"\",\"\",IFERROR(INDEX('3_Customers'!\$B\$4:\$B\$503,MATCH(D{$r},'3_Customers'!\$A\$4:\$A\$503,0)),\"⚠ Kode tidak ditemukan\"))");
        $sheet->getStyle('E' . $r)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EFF6FF']],
            'font' => ['color' => ['rgb' => '1E40AF'], 'size' => 9, 'italic' => true],
        ]);
    }

    // customers_code (D): dropdown dari 3_Customers
    _mig_add_dropdown_range($sheet, 'D', 4, 503, "'3_Customers'!\$A\$4:\$A\$503");
    // office_code (F): dropdown dinamis dari 8_Office; currency (G): static
    _mig_add_dropdown_range($sheet, 'F', 4, 503, "'8_Office'!\$A\$3:\$A\$15");
    _mig_add_dropdown($sheet, 'G', 4, 503, MIG_CURRENCY);

    // Summary untuk cross-check saldo piutang
    _mig_add_summary($sheet, $lastCol, 506, [
        ['Total invoice piutang :', '=COUNTA(A4:A503)'],
        ['Total balance (semua currency) :', '=SUM(H4:H503)'],
    ]);

    // Proteksi: lock formula customers_name
    _mig_protect_sheet($sheet);
    _mig_lock_range($sheet, 'A1:' . $lastCol . '3'); // banner + headers
    _mig_lock_range($sheet, 'E4:E503');              // formula customers_name
}

/** Helper untuk AP & AR (sama strukturnya, baris 1 = banner, 2 = header, 3 = desc, 4+ data) */
function _mig_write_ap_ar_sheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers, array $examples): void
{
    $sheet->getRowDimension(1)->setRowHeight(28);
    $col = 1;
    foreach ($headers as $field => $info) {
        $colLetter = Coordinate::stringFromColumnIndex($col);
        $isFormula = !empty($info['formula']);
        $sheet->setCellValue($colLetter . '2', $field);
        $sheet->setCellValue($colLetter . '3', $info['desc'] ?? '');
        $sheet->getStyle($colLetter . '2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ($info['required'] ?? false) ? 'FF0000' : MIG_CLR_HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => [
                'rgb' => $isFormula ? 'DBEAFE' : (($info['required'] ?? false) ? MIG_CLR_REQUIRED_FILL : MIG_CLR_HEADER_FILL),
            ]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle($colLetter . '3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => $isFormula ? '1E40AF' : '666666']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $isFormula ? 'EFF6FF' : 'F5F5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getColumnDimension($colLetter)->setWidth($info['width'] ?? 18);
        $col++;
    }
    $sheet->getRowDimension(2)->setRowHeight(20);
    $sheet->getRowDimension(3)->setRowHeight(28);
    $sheet->freezePane('A4');

    $rowNum = 4;
    foreach ($examples as $ex) {
        $col = 1;
        foreach (array_keys($headers) as $f) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $isFormula = !empty($headers[$f]['formula']);
            $sheet->setCellValue($colLetter . $rowNum, $ex[$f] ?? '');
            $sheet->getStyle($colLetter . $rowNum)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => [
                    'rgb' => $isFormula ? 'EFF6FF' : MIG_CLR_EXAMPLE_FILL,
                ]],
                'font' => $isFormula
                    ? ['color' => ['rgb' => '1E40AF'], 'size' => 9, 'italic' => true]
                    : [],
            ]);
            $col++;
        }
        $rowNum++;
    }
}

/** ─── SHEET: 8_OFFICE ───────────────────────── */
function _mig_sheet_office(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    $headers = [
        'office_code'      => ['desc' => 'Kode office (BGR, BDG, dll)', 'required' => true, 'width' => 14],
        'office_name'      => ['desc' => 'Nama kantor/depo', 'width' => 30],
        'office_lat'       => ['desc' => 'Latitude GPS (contoh: -6.597147)', 'width' => 18],
        'office_lng'       => ['desc' => 'Longitude GPS (contoh: 106.806039)', 'width' => 18],
        'office_radius_m'  => ['desc' => 'Radius absensi geofence (meter)', 'width' => 16],
        'status'           => ['desc' => 'active / inactive', 'width' => 12],
    ];

    _mig_write_headers($sheet, $headers);

    $realOffice = _mig_real_office();
    $rowNum     = 3;
    $oFields    = ['office_code','office_name','office_lat','office_lng','office_radius_m','status'];
    // mapping index dari _mig_real_office(): [code,name,city,address,phone,lat,lng,radius,status]
    $officeMap  = [0, 1, 5, 6, 7, 8];
    foreach ($realOffice as $row) {
        $col = 1;
        foreach ($officeMap as $idx) {
            $cl = Coordinate::stringFromColumnIndex($col);
            $v  = $row[$idx] ?? '';
            $sheet->setCellValue($cl . $rowNum, $v);
            $sheet->getStyle($cl . $rowNum)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
                'font' => ['color' => ['rgb' => '1B5E20'], 'size' => 9],
            ]);
            $col++;
        }
        $rowNum++;
    }

    $sheet->setCellValue('A' . $rowNum, '⚠  Sheet ini untuk UPDATE office yang sudah ada. Jika ingin tambah office baru, hubungi Administrator ITC.');
    $sheet->mergeCells('A' . $rowNum . ':F' . $rowNum);
    $sheet->getStyle('A' . $rowNum . ':F' . $rowNum)->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '856404'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'fff3cd']],
    ]);

    _mig_add_dropdown($sheet, 'A', 3, 20, MIG_OFFICE_CODES . ',SYS');
    _mig_add_dropdown($sheet, 'F', 3, 20, 'active,inactive');
}

/** ─── SHEET: 9_BANK ─────────────────────────── */
function _mig_sheet_bank(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    $headers = [
        'bank_name'       => ['desc' => 'Nama bank (BCA, Mandiri, BRI, dll)', 'width' => 16],
        'account_name'    => ['desc' => 'Nama pemilik rekening', 'width' => 24],
        'account_number'  => ['desc' => 'Nomor rekening', 'width' => 20],
        'currency'        => ['desc' => 'IDR / USD / CNY', 'width' => 10],
        'office_code'     => ['desc' => 'Kode office (BGR, BDG, dll)', 'width' => 14],
        'balance_date'    => ['desc' => 'Tanggal saldo (YYYY-MM-DD)', 'width' => 16],
        'balance_amount'  => ['desc' => 'Saldo rekening', 'width' => 18],
        'note'            => ['desc' => 'Catatan', 'width' => 24],
    ];

    _mig_write_headers($sheet, $headers);
    _mig_write_examples($sheet, $headers, [
        ['bank_name' => 'BCA',     'account_name' => 'PT Rizqullah Mediska Indonesia', 'account_number' => '1234567890', 'currency' => 'IDR', 'office_code' => 'BGR', 'balance_date' => '2026-01-31', 'balance_amount' => 500000000, 'note' => 'Rekening operasional'],
        ['bank_name' => 'Mandiri', 'account_name' => 'PT Rizqullah Mediska Indonesia', 'account_number' => '0987654321', 'currency' => 'IDR', 'office_code' => 'BGR', 'balance_date' => '2026-01-31', 'balance_amount' => 250000000, 'note' => 'Rekening tabungan'],
    ]);

    $sheet->setCellValue('A' . ($sheet->getHighestRow() + 1), '⚠  Sheet ini COMING SOON — belum diproses sistem saat ini.');
    $sheet->getStyle('A' . $sheet->getHighestRow() . ':H' . $sheet->getHighestRow())->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '856404']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'fff3cd']],
    ]);

    // bank_name (A): dropdown bank umum di Indonesia
    _mig_add_dropdown($sheet, 'A', 3, 500, 'BCA,Mandiri,BRI,BNI,BSI,CIMB Niaga,OCBC,Permata,BTN,DBS,Danamon,Mega,Panin,Lainnya');
    // currency (D): static; office_code (E): dinamis dari 8_Office
    _mig_add_dropdown($sheet, 'D', 3, 500, MIG_CURRENCY);
    _mig_add_dropdown_range($sheet, 'E', 3, 500, "'8_Office'!\$A\$3:\$A\$15");
}

/** ─── SHEET: 10_GL_OPENING ──────────────────── */
function _mig_sheet_gl(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    $headers = [
        'account_code' => ['desc' => 'Kode akun GL', 'required' => true, 'width' => 14],
        'account_name' => ['desc' => 'Nama akun', 'width' => 28],
        'debit'        => ['desc' => 'Jumlah debit', 'width' => 18],
        'credit'       => ['desc' => 'Jumlah kredit', 'width' => 18],
        'note'         => ['desc' => 'Catatan', 'width' => 24],
    ];

    _mig_write_headers($sheet, $headers);
    _mig_write_examples($sheet, $headers, [
        ['account_code' => '1100', 'account_name' => 'Kas',  'debit' => 100000000, 'credit' => 0, 'note' => 'Opening balance'],
        ['account_code' => '1200', 'account_name' => 'Bank', 'debit' => 500000000, 'credit' => 0, 'note' => 'Opening balance'],
        ['account_code' => '2100', 'account_name' => 'Hutang Usaha', 'debit' => 0, 'credit' => 50000000, 'note' => 'Opening balance'],
    ]);

    $sheet->setCellValue('A' . ($sheet->getHighestRow() + 1), '⚠  Sheet ini COMING SOON — belum diproses sistem saat ini.');
    $sheet->getStyle('A' . $sheet->getHighestRow() . ':E' . $sheet->getHighestRow())->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '856404']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'fff3cd']],
    ]);
}

/** ─── SHEET: 11_FIXED ASSETS ────────────────── */
function _mig_sheet_fa(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    $headers = [
        'asset_code'        => ['desc' => 'Kode aset unik', 'required' => true, 'width' => 14],
        'asset_name'        => ['desc' => 'Nama aset', 'width' => 28],
        'category'          => ['desc' => 'IT / Kendaraan / Bangunan / Peralatan', 'width' => 18],
        'acquisition_date'  => ['desc' => 'Tanggal perolehan (YYYY-MM-DD)', 'width' => 18],
        'acquisition_value' => ['desc' => 'Nilai perolehan (Rp)', 'width' => 18],
        'office_code'       => ['desc' => 'Kode office (BGR, BDG, dll)', 'width' => 14],
        'note'              => ['desc' => 'Catatan', 'width' => 24],
    ];

    _mig_write_headers($sheet, $headers);
    _mig_write_examples($sheet, $headers, [
        ['asset_code' => 'FA-BGR-001', 'asset_name' => 'Laptop Dell Latitude 5520', 'category' => 'IT',         'acquisition_date' => '2025-01-15', 'acquisition_value' => 15000000, 'office_code' => 'BGR', 'note' => 'Opening'],
        ['asset_code' => 'FA-BGR-002', 'asset_name' => 'Mobil Toyota Kijang Innova', 'category' => 'Kendaraan', 'acquisition_date' => '2024-06-01', 'acquisition_value' => 320000000, 'office_code' => 'BGR', 'note' => 'Opening'],
    ]);

    $sheet->setCellValue('A' . ($sheet->getHighestRow() + 1), '⚠  Sheet ini COMING SOON — belum diproses sistem saat ini.');
    $sheet->getStyle('A' . $sheet->getHighestRow() . ':G' . $sheet->getHighestRow())->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '856404']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'fff3cd']],
    ]);

    // category (C): static; office_code (F): dinamis dari 8_Office
    _mig_add_dropdown($sheet, 'C', 3, 100, 'IT,Kendaraan,Bangunan,Peralatan,Furniture,Lainnya');
    _mig_add_dropdown_range($sheet, 'F', 3, 100, "'8_Office'!\$A\$3:\$A\$15");
}

/** ─── SHEET: 12_CARA PENGISIAN ─────────────── */
function _mig_sheet_guide(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    $sheet->getColumnDimension('A')->setWidth(18);
    $sheet->getColumnDimension('B')->setWidth(8);
    $sheet->getColumnDimension('C')->setWidth(50);
    $sheet->getColumnDimension('D')->setWidth(45);
    $sheet->getColumnDimension('E')->setWidth(20);

    // Title
    $sheet->setCellValue('A1', 'PANDUAN PENGISIAN — RMI Migration Template');
    $sheet->getStyle('A1:E1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->mergeCells('A1:E1');
    $sheet->getRowDimension(1)->setRowHeight(26);

    // Legend
    $sheet->setCellValue('A2', '🔴 Kolom merah = WAJIB diisi');
    $sheet->setCellValue('C2', '🟡 Baris kuning = contoh isian (hapus sebelum upload jika sudah punya data sendiri)');
    $sheet->getStyle('A2:E2')->applyFromArray([
        'font' => ['italic' => true, 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFDE7']],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(16);

    // Header tabel
    $headers2 = ['Sheet', 'Urutan', 'Cara Pengisian', 'Valid Values / Dropdown', 'Status'];
    $col = 1;
    foreach ($headers2 as $h) {
        $cl = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($cl . '3', $h);
        $sheet->getStyle($cl . '3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => MIG_CLR_REF_HEADER]],
        ]);
        $col++;
    }

    $rows = [
        ['1_Manufactures', '1', "Isi principal dari sistem lama.\nmanufacture_code harus unik.\nWajib sebelum Products.", "origin_type: Local, Import\nstatus: active, inactive", 'Diproses'],
        ['2_Vendors',      '2', "Vendor logistik/forwarding.\nvendors_code harus unik.\nOpsional.", "vendor_type: Forwarding, Logistic, Lainnya\ncategory: ACT,CRM,SCM,WQS,FIN,HRL,PQP,ITC,MPR\nstatus: active, inactive", 'Diproses'],
        ['3_Customers',    '3', "Customer RS/Klinik/Internal.\ncustomers_code harus unik.\nWajib sebelum AR.", "category: RS Swasta, RS Pemerintah, Internal\nsegment: Hermina, Non Hermina, RSUD, Kantor\noffice_code: BGR,BDG,BKS,TGR,SLO,SMG,JGY,KAL\nstatus: active, inactive", 'Diproses'],
        ['4_Products',     '4', "Produk/item.\nSKU harus unik.\nmanufacture_code harus ada di sheet 1.\nWajib sebelum Stock.", "category: BMHP, UnitAcc\nunit: PCS,BOX,SET,UNIT,PAIR,ROLL,PACK,dll\nstatus: active, inactive, draft, pending_reg", 'Diproses'],
        ['5_Stock',        '5', "Stok fisik per SKU per office.\nSKU harus ada di master_products.\nKolom batch & row_no otomatis.", "office_code: BGR,BDG,BKS,TGR,SLO,SMG,JGY,KAL", 'Diproses'],
        ['6_AP',           '6', "Hutang ke principal (opening balance).\nmanufacture_code harus ada di sheet 1.\nTanggal format YYYY-MM-DD.\nKolom batch & row_no otomatis.", "office_code: BGR,BDG,...\ncurrency: IDR,USD,CNY,EUR,SGD", 'Diproses'],
        ['7_AR',           '7', "Piutang dari customer (opening balance).\ncustomers_code harus ada di sheet 3.\nTanggal format YYYY-MM-DD.\nKolom batch & row_no otomatis.", "office_code: BGR,BDG,...\ncurrency: IDR,USD,CNY,EUR,SGD", 'Diproses'],
        ['8_Office',       '8', "Update data office yang sudah ada.\nTidak bisa menambah office baru dari sini.", "office_code: BGR,BDG,BKS,TGR,SLO,SMG,JGY,KAL", 'Diproses'],
        ['9_Bank',         '9', "Saldo rekening bank opening.\nTidak wajib.", "currency: IDR,USD,CNY\noffice_code: BGR,BDG,...", 'Coming Soon'],
        ['10_GL_Opening',  '10', "Jurnal saldo awal akuntansi.\nTidak wajib.", '-', 'Coming Soon'],
        ['11_FixedAssets', '11', "Daftar aset tetap opening.\nTidak wajib.", "category: IT,Kendaraan,Bangunan,Peralatan,Furniture,Lainnya\noffice_code: BGR,BDG,...", 'Coming Soon'],
        ['12_Cara_Pengisian', '-', 'Sheet panduan ini. Jangan dihapus.', '-', '-'],
        ['REF_ValidValues', '-', 'Daftar referensi nilai valid semua kolom dropdown.', '-', '-'],
    ];

    $rowNum = 4;
    foreach ($rows as $r) {
        $col = 1;
        foreach ($r as $v) {
            $cl = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($cl . $rowNum, $v);
            $sheet->getStyle($cl . $rowNum)->applyFromArray([
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]],
            ]);
            if ($col === 5) { // Status
                $color = $v === 'Diproses' ? 'd4edda' : ($v === 'Coming Soon' ? 'fff3cd' : 'FFFFFF');
                $sheet->getStyle($cl . $rowNum)->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]]]);
            }
            $col++;
        }
        $sheet->getRowDimension($rowNum)->setRowHeight(60);
        $rowNum++;
    }

    // Tips
    $sheet->setCellValue('A' . ($rowNum + 1), 'TIPS:');
    $sheet->getStyle('A' . ($rowNum + 1))->applyFromArray(['font' => ['bold' => true]]);
    $tips = [
        "1. Isi sheet sesuai URUTAN (1 → 4 master dulu, baru 5 → 7 transaksi).",
        "2. Hapus baris contoh (kuning) sebelum upload jika data kamu sudah ada.",
        "3. Jangan ubah nama sheet dan header kolom.",
        "4. Tanggal di sheet 6_AP dan 7_AR HARUS format YYYY-MM-DD (contoh: 2026-01-31).",
        "5. Kolom dengan nama merah = WAJIB. Kosong = baris di-skip.",
        "6. Jika ada error saat upload, pesan error muncul di halaman upload.",
    ];
    $tRow = $rowNum + 2;
    foreach ($tips as $t) {
        $sheet->setCellValue('A' . $tRow, $t);
        $sheet->mergeCells('A' . $tRow . ':E' . $tRow);
        $tRow++;
    }
}

/** ─── SHEET: REF_VALID VALUES ───────────────── */
function _mig_sheet_ref(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    $sheet->getColumnDimension('A')->setWidth(22);
    $sheet->getColumnDimension('B')->setWidth(22);
    $sheet->getColumnDimension('C')->setWidth(28);
    $sheet->getColumnDimension('D')->setWidth(36);

    // Title
    $sheet->setCellValue('A1', 'REF — Valid Values Semua Dropdown');
    $sheet->getStyle('A1:D1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => MIG_CLR_REF_HEADER]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->mergeCells('A1:D1');

    $data = [
        ['Sheet', 'Kolom', 'Valid Values', 'Keterangan'],
        ['1_Manufactures', 'origin_type', 'Local, Import', 'Asal barang'],
        ['1_Manufactures', 'status',      'active, inactive', 'Status principal'],
        ['2_Vendors', 'vendor_type', 'Forwarding, Logistic, Lainnya', 'Tipe vendor'],
        ['2_Vendors', 'category',    'ACT, CRM, SCM, WQS, FIN, HRL, PQP, ITC, MPR', 'Departemen terkait'],
        ['2_Vendors', 'status',      'active, inactive', 'Status vendor'],
        ['3_Customers', 'category',   'RS Swasta, RS Pemerintah, Internal', 'Kategori customer'],
        ['3_Customers', 'segment',    'Hermina, Non Hermina, RSUD, Kantor', 'Segmen customer'],
        ['3_Customers', 'office_code','BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL', 'Kode kantor/depo'],
        ['3_Customers', 'status',     'active, inactive', 'Status customer'],
        ['4_Products', 'category', 'BMHP, UnitAcc', 'BMHP = habis pakai; UnitAcc = alat/peralatan'],
        ['4_Products', 'unit',     'PCS, BOX, SET, UNIT, PAIR, ROLL, PACK, TUBE, VIAL, AMPUL, BOTOL, STRIP, KAPSUL, TABLET, LITER, ML, GRAM, KG', 'Satuan produk'],
        ['4_Products', 'status',   'active, inactive, draft, pending_reg', 'Status produk'],
        ['5_Stock', 'office_code', 'BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL', 'Kode office untuk stok'],
        ['6_AP',    'office_code', 'BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL', 'Kode office'],
        ['6_AP',    'currency',    'IDR, USD, CNY, EUR, SGD', 'Mata uang'],
        ['7_AR',    'office_code', 'BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL', 'Kode office'],
        ['7_AR',    'currency',    'IDR, USD, CNY, EUR, SGD', 'Mata uang'],
        ['8_Office','office_code', 'BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL, SYS', 'Kode office yang valid'],
        ['8_Office','status',      'active, inactive', 'Status office'],
        ['', '', '', ''],
        ['Office Code', 'Kota', '', ''],
        ['BGR', 'Bogor', '', ''],
        ['BDG', 'Bandung', '', ''],
        ['BKS', 'Bekasi', '', ''],
        ['TGR', 'Tangerang', '', ''],
        ['SLO', 'Solo', '', ''],
        ['SMG', 'Semarang', '', ''],
        ['JGY', 'Yogyakarta', '', ''],
        ['KAL', 'Kalimantan', '', ''],
        ['SYS', 'System (admin)', '', ''],
    ];

    $rowNum = 2;
    foreach ($data as $row) {
        $col = 1;
        foreach ($row as $v) {
            $cl = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($cl . $rowNum, $v);
            $col++;
        }
        // Style header row
        if ($rowNum === 2) {
            $sheet->getStyle('A2:D2')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => MIG_CLR_REF_HEADER]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => MIG_CLR_REF_FILL]],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM]],
            ]);
        }
        // Style office code header
        if ($row[0] === 'Office Code') {
            $sheet->getStyle('A' . $rowNum . ':D' . $rowNum)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => MIG_CLR_REF_FILL]],
            ]);
        }
        $sheet->getStyle('A' . $rowNum . ':D' . $rowNum)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($rowNum)->setRowHeight(-1);
        $rowNum++;
    }
}
