<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/db.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.PRODUCT.MANAGE', 'MASTER.PRODUCT.VIEW', 'SYSTEM.MASTER_MANAGE']);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';

$importDir  = __DIR__ . '/../uploads/product_media_import/';
$extractDir = __DIR__ . '/../uploads/product_media_extract/';

$imageDir = __DIR__ . '/../uploads/products/images/';
$videoDir = __DIR__ . '/../uploads/products/videos/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!empty($_FILES['zip_file']['name'])) {

        $zipTmp  = $_FILES['zip_file']['tmp_name'];
        $zipName = time() . '_' . basename($_FILES['zip_file']['name']);

        $zipPath = $importDir . $zipName;

        move_uploaded_file($zipTmp, $zipPath);

        $zip = new ZipArchive();

        if ($zip->open($zipPath) === TRUE) {

            $extractFolder = $extractDir . time() . '/';

            if (!is_dir($extractFolder)) {
                mkdir($extractFolder, 0777, true);
            }

            $zip->extractTo($extractFolder);
            $zip->close();

            $files = scandir($extractFolder);

            $success = 0;
            $failed  = 0;
            $duplicate = 0;

            foreach ($files as $file) {

                if ($file == '.' || $file == '..') {
                    continue;
                }

                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                $allowed = [
                    'jpg',
                    'jpeg',
                    'png',
                    'webp',
                    'mp4',
                    'mov'
                ];

                if (!in_array($ext, $allowed)) {
                    continue;
                }

                $filename = pathinfo($file, PATHINFO_FILENAME);

                /*
                contoh:
                ALK001_1.jpg
                ALK001_VIDEO.mp4
                */

                $parts = explode('_', $filename);

                $sku = trim($parts[0]);

                if ($sku == '') {
                    $failed++;
                    continue;
                }

                /*
                cek sku ada
                */

                $stmt = $pdo->prepare("
                    SELECT id, sku, products_name
                    FROM master_products
                    WHERE sku = ?
                    LIMIT 1
                ");

                $stmt->execute([$sku]);

                $product = $stmt->fetch();

                if (!$product) {
                    $failed++;
                    continue;
                }

                /*
                duplicate check
                */

                $dup = $pdo->prepare("
                    SELECT id
                    FROM master_product_media
                    WHERE sku = ?
                    AND file_name = ?
                    LIMIT 1
                ");

                $dup->execute([$sku, $file]);

                if ($dup->fetch()) {
                    $duplicate++;
                    continue;
                }

                /*
                tentukan folder
                */

                $sourcePath = $extractFolder . $file;

                if (in_array($ext, ['mp4', 'mov'])) {

                    $targetFolder = $videoDir;

                    $fileType = 'video';

                } else {

                    $targetFolder = $imageDir;

                    $fileType = 'image';
                }

                if (!is_dir($targetFolder)) {
                    mkdir($targetFolder, 0777, true);
                }

                $newFileName = time() . '_' . $file;

                $targetPath = $targetFolder . $newFileName;

                if (!rename($sourcePath, $targetPath)) {
                    $failed++;
                    continue;
                }

                /*
                simpan database
                */

                $insert = $pdo->prepare("
                    INSERT INTO master_product_media
                    (
                        sku,
                        file_name,
                        file_type,
                        file_path,
                        uploaded_by
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?
                    )
                ");

                $insert->execute([
                    $sku,
                    $newFileName,
                    $fileType,
                    $targetPath,
                    $_SESSION['user_id'] ?? 0
                ]);

                $success++;
            }

            $message = "
                SUCCESS : {$success}<br>
                FAILED : {$failed}<br>
                DUPLICATE : {$duplicate}
            ";

        } else {

            $message = 'ZIP gagal dibuka';
        }
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Bulk Product Media Upload</title>

    <style>

        body{
            font-family:Arial;
            padding:20px;
        }

        .box{
            border:1px solid #ccc;
            padding:20px;
            border-radius:10px;
            max-width:700px;
        }

    </style>

</head>
<body>

<h2>Bulk Product Media Upload</h2>

<div class="box">

    <?php if($message): ?>
        <div style="margin-bottom:20px;">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">

        <label>Upload ZIP Media</label>

        <br><br>

        <input
            type="file"
            name="zip_file"
            accept=".zip"
            required
        >

        <br><br>

        <button type="submit">
            Upload & Import
        </button>

    </form>

</div>

</body>
</html>