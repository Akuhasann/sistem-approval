<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

// --- KONFIGURASI DATABASE ---
$host   = "localhost";
$user   = "root";
$pass   = "";
$dbName = "db_approval";

$conn = mysqli_connect($host, $user, $pass, $dbName);

if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

// --- LOGIKA LOGOUT ---
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- LOGIKA LOGIN (DATABASE BASED) ---
$error = "";
if (isset($_POST['login_action'])) {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $query = "SELECT * FROM users WHERE username = '$username' AND password = '$password'";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) > 0) {
        $userData = mysqli_fetch_assoc($result);
        $_SESSION['role'] = $userData['role'];
        $_SESSION['username'] = $userData['username'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $error = "Username atau Password salah!";
    }
}

$currentRole = $_SESSION['role'] ?? null;

// --- INISIALISASI FOLDER ---
$targetDir = 'uploads';
$masterDir = 'assets';
if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
if (!is_dir($masterDir)) mkdir($masterDir, 0777, true);

// --- LOGIKA USER: Upload Dokumen ---
if (isset($_POST['upload_doc']) && ($currentRole == 'user' || $currentRole == 'admin')) {
    $originalName = $_FILES['word_file']['name'];
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $onlyName = pathinfo($originalName, PATHINFO_FILENAME);

    // MODIFIKASI DISINI: Nama File + Waktu sampai detik
    // Kita gunakan preg_replace untuk membersihkan spasi/karakter aneh agar tidak error di URL
    $cleanName = preg_replace("/[^a-zA-Z0-9]/", "_", $onlyName);
    $waktu = date('d-m-Y_H-i-s'); // Format: Hari-Bulan-Tahun_Jam-Menit-Detik

    $serverFileName = $cleanName . '_' . $waktu . '.' . $ext;

    if (move_uploaded_file($_FILES['word_file']['tmp_name'], $targetDir . '/' . $serverFileName)) {
        // Simpan ke database
        $sql = "INSERT INTO documents (original_name, extension, server_file, status) 
                VALUES ('$onlyName', '$ext', '$serverFileName', 'Pending')";

        if (mysqli_query($conn, $sql)) {
            $_SESSION['msg_success'] = "Dokumen berhasil dikirim!";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// --- LOGIKA DR/ADMIN: Approve (DENGAN PLACEHOLDER) ---
// --- LOGIKA DR/ADMIN: Approve (TANPA PLACEHOLDER - ANTI RECOVER) ---
if (isset($_POST['approve_doc']) && ($currentRole == 'dr' || $currentRole == 'admin')) {
    $docID = $_POST['doc_id'];
    $res = mysqli_query($conn, "SELECT * FROM documents WHERE id = $docID");
    $data = mysqli_fetch_assoc($res);
    $ttdFile = glob("$masterDir/ttdDr.*");

    if (empty($ttdFile)) {
        $_SESSION['msg_error'] = "File TTD di folder assets tidak ada!";
    } else {
        $zip = new ZipArchive;
        $filePath = $targetDir . '/' . $data['server_file'];

        if ($zip->open($filePath) === TRUE) {
            // 1. Masukkan file gambar TTD
            $imgData = file_get_contents($ttdFile[0]);
            $zip->addFromString('word/media/ttd_approved.png', $imgData);

            // 2. Ambil isi dokumen
            $xml = $zip->getFromName('word/document.xml');
            $rId = "rId" . rand(10000, 99999);

            // --- PENGATURAN TATA LETAK (Satuan CM) ---
            $satuan_emu = 360000;

            $lebar_cm   = 4;
            $tinggi_cm  = 2;
            $jarakkiri  = 3;   // Jarak dari pinggir kiri kertas
            $jarakatas  = 18.5;   // Jarak dari pinggir atas kertas (Kurangi agar NAIK)

            // Konversi ke EMU
            $emu_lebar  = $lebar_cm * $satuan_emu;
            $emu_tinggi = $tinggi_cm * $satuan_emu;
            $pos_x      = $jarakkiri * $satuan_emu;
            $pos_y      = $jarakatas * $satuan_emu;

            $drawingXml = '
<w:p>
    <w:r>
        <w:drawing>
            <wp:anchor distT="0" distB="0" distL="114300" distR="114300" simplePos="0" relativeHeight="251658240" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1">
                <wp:simplePos x="0" y="0"/>
                
                <wp:positionH relativeFrom="page">
                    <wp:posOffset>' . $pos_x . '</wp:posOffset>
                </wp:positionH>
                
                <wp:positionV relativeFrom="page">
                    <wp:posOffset>' . $pos_y . '</wp:posOffset>
                </wp:positionV>

                <wp:extent cx="' . $emu_lebar . '" cy="' . $emu_tinggi . '"/>
                <wp:effectExtent l="0" t="0" r="0" b="0"/>
                <wp:wrapNone/> <wp:docPr id="' . rand(1, 9999) . '" name="Signature"/>
                <wp:cNvGraphicFramePr>
                    <a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/>
                </wp:cNvGraphicFramePr>
                <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
                    <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                        <pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
                            <pic:nvPicPr>
                                <pic:cNvPr id="0" name="TTD"/>
                                <pic:cNvPicPr/>
                            </pic:nvPicPr>
                            <pic:blipFill>
                                <a:blip r:embed="' . $rId . '"/>
                                <a:stretch><a:fillRect/></a:stretch>
                            </pic:blipFill>
                            <pic:spPr>
                                <a:xfrm>
                                    <a:off x="0" y="0"/>
                                    <a:ext cx="' . $emu_lebar . '" cy="' . $emu_tinggi . '"/>
                                </a:xfrm>
                                <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                            </pic:spPr>
                        </pic:pic>
                    </a:graphicData>
                </a:graphic>
            </wp:anchor>
        </w:drawing>
    </w:r>
</w:p>';

            // 4. Sisipkan ke dalam body dokumen
            $xml = str_replace('</w:body>', $drawingXml . '</w:body>', $xml);
            $zip->addFromString('word/document.xml', $xml);

            // 5. Daftarkan Relasi Gambar (Penting!)
            $relsPath = 'word/_rels/document.xml.rels';
            $rels = $zip->getFromName($relsPath);
            $newRel = '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/ttd_approved.png"/>';
            $rels = str_replace('</Relationships>', $newRel . '</Relationships>', $rels);
            $zip->addFromString($relsPath, $rels);

            // 6. Daftarkan Content Type
            $typesPath = '[Content_Types].xml';
            $types = $zip->getFromName($typesPath);
            if (strpos($types, 'Extension="png"') === false) {
                $types = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $types);
                $zip->addFromString($typesPath, $types);
            }

            $zip->close();

            // Ubah bagian ini di dalam logika approve_doc
            $dateTimeFull = date("d-m-Y H:i:s"); // Tambahkan jam, menit, detik
            mysqli_query($conn, "UPDATE documents SET status = 'Approved', date_approved = '$dateTimeFull' WHERE id = $docID");
            $_SESSION['msg_success'] = "Dokumen berhasil disetujui!";
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
// --- LOGIKA HAPUS DOKUMEN ---
if (isset($_POST['delete_doc'])) {
    $docID = intval($_POST['doc_id']);
    // Cari nama file di database agar bisa dihapus dari folder uploads
    $res = mysqli_query($conn, "SELECT server_file FROM documents WHERE id = $docID AND status = 'Pending'");

    if ($data = mysqli_fetch_assoc($res)) {
        $filePath = $targetDir . '/' . $data['server_file'];

        // 1. Hapus file fisik
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // 2. Hapus data di database
        mysqli_query($conn, "DELETE FROM documents WHERE id = $docID");
        $_SESSION['msg_success'] = "Dokumen berhasil dihapus!";
    } else {
        $_SESSION['msg_error'] = "Gagal menghapus! Dokumen mungkin sudah di-approve atau tidak ditemukan.";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
// Ambil pesan session
$success = $_SESSION['msg_success'] ?? "";
$error = $error ?: ($_SESSION['msg_error'] ?? "");
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

$result = mysqli_query($conn, "SELECT * FROM documents ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Sistem Approval TTD - Multi Role</title>
    <script src="https://unpkg.com/jszip/dist/jszip.min.js"></script>
    <script src="https://unpkg.com/docx-preview/dist/docx-preview.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f4f7f6;
            margin: 0;
        }

        .login-container {
            width: 100%;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #2c3e50;
        }

        .login-card {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 350px;
            text-align: center;
        }

        .nav {
            background: #2c3e50;
            color: white;
            padding: 15px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .container {
            width: 95%;
            max-width: 1200px;
            margin: 20px auto;
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 20px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }

        .btn-blue {
            background: #3498db;
            color: white;
        }

        .btn-green {
            background: #2ecc71;
            color: white;
        }

        .btn-red {
            background: #e74c3c;
            color: white;
        }

        input[type="text"],
        input[type="password"],
        select,
        input[type="file"] {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            text-transform: uppercase;
        }

        .badge-admin {
            background: #9b59b6;
            color: white;
        }

        .preview-box {
            display: none;
            position: fixed;
            top: 5%;
            left: 10%;
            width: 80%;
            height: 85%;
            background: white;
            z-index: 1000;
            box-shadow: 0 0 50px rgba(0, 0, 0, 0.5);
            padding: 20px;
            border-radius: 10px;
        }

        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 999;
        }

        #preview-container {
            height: 90%;
            overflow-y: auto;
            background: #525659;
            border-radius: 5px;
            display: flex;
            justify-content: center;
        }
    </style>
</head>

<body>

    <?php if (!$currentRole): ?>
        <div class="login-container">
            <div class="login-card">
                <h2>🔐 Login Sistem</h2>
                <form method="post">
                    <input type="text" name="username" placeholder="Username" required>
                    <input type="password" name="password" placeholder="Password" required>
                    <button type="submit" name="login_action" class="btn btn-blue" style="width: 100%;">Masuk</button>
                </form>
                <?php if ($error): ?><p style="color: red; font-size: 13px;"><?= $error ?></p><?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="nav">
            <span>
                <b>SISTEM APPROVAL</b> |
                User: <u><?= $_SESSION['username'] ?></u>
                <span class="badge badge-admin"><?= strtoupper($currentRole) ?></span>
            </span>
            <a href="?logout=true" class="btn btn-red" style="font-size: 12px;">LOGOUT</a>
        </div>

        <div class="container">
            <div class="sidebar">
                <div class="card">
                    <?php if ($currentRole == 'user' || $currentRole == 'admin'): ?>
                        <h3>📤 Upload Dokumen</h3>
                        <form method="post" enctype="multipart/form-data">
                            <input type="file" name="word_file" accept=".docx" required>
                            <button type="submit" name="upload_doc" class="btn btn-blue" style="width:100%">Kirim Sekarang</button>
                        </form>
                        <hr>
                    <?php endif; ?>

                    <?php if ($currentRole == 'dr' || $currentRole == 'admin'): ?>
                        <h3>👨‍💼 Menu Approval</h3>
                        <p style="font-size: 13px; color: #666;">Anda memiliki akses untuk menyetujui dokumen yang masuk.</p>
                    <?php endif; ?>

                    <?php if ($success): ?><p style="color: green; font-weight: bold;"><?= $success ?></p><?php endif; ?>
                    <?php if ($error): ?><p style="color: red; font-weight: bold;"><?= $error ?></p><?php endif; ?>
                </div>
            </div>

            <div class="main">
                <div class="card">
                    <h3>📋 Daftar Dokumen</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama File</th>
                                <th>Status</th>
                                <th>Waktu Approved</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1; // Inisialisasi nomor urut
                            while ($d = mysqli_fetch_assoc($result)):
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= $d['original_name'] ?></td>
                                    <td>
                                        <span class="badge" style="background: <?= $d['status'] == 'Approved' ? '#2ecc71' : '#f1c40f' ?>; color: white;">
                                            <?= $d['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= ($d['status'] == 'Approved' && !empty($d['date_approved'])) ? $d['date_approved'] : '-' ?>
                                    </td>
                                    <td>
                                        <button onclick="viewDoc('uploads/<?= $d['server_file'] ?>')" class="btn btn-blue" style="padding: 5px 10px; font-size: 12px;">👁️ Lihat</button>

                                        <?php if ($d['status'] == 'Pending'): ?>
                                            <?php if ($currentRole == 'dr' || $currentRole == 'admin'): ?>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Setujui dokumen ini?')">
                                                    <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                                    <button type="submit" name="approve_doc" class="btn btn-green" style="padding: 5px 10px; font-size: 12px;">Approve</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus dokumen ini?')">
                                                <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                                <button type="submit" name="delete_doc" class="btn btn-red" style="padding: 5px 10px; font-size: 12px;">🗑️ Hapus</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($d['status'] == 'Approved'): ?>
                                            <?php
                                            $cleanName = preg_replace("/[^a-zA-Z0-9]/", "_", $d['original_name']);
                                            $safeDate = str_replace([' ', ':'], ['_', '-'], $d['date_approved']);
                                            $namaFileDownload = $cleanName . "_signed_" . $safeDate . ".docx";
                                            ?>
                                            <a href="uploads/<?= $d['server_file'] ?>"
                                                download="<?= $namaFileDownload ?>"
                                                class="btn btn-green"
                                                style="padding: 5px 10px; font-size: 12px;">Download</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="overlay" class="overlay" onclick="closeDoc()"></div>
    <div id="previewBox" class="preview-box">
        <button onclick="closeDoc()" class="btn btn-red" style="float: right;">Tutup [X]</button>
        <h3>Pratinjau Dokumen</h3>
        <div id="preview-container"></div>
    </div>

    <script>
        function viewDoc(url) {
            document.getElementById('previewBox').style.display = 'block';
            document.getElementById('overlay').style.display = 'block';
            const container = document.getElementById('preview-container');
            container.innerHTML = "<p style='color:white; padding:20px;'>Sedang merender dokumen...</p>";

            // Tambahkan t=TIMESTAMP agar browser selalu mengambil file terbaru dari server
            const antiCacheUrl = url + "?t=" + new Date().getTime();

            fetch(antiCacheUrl)
                .then(res => {
                    if (!res.ok) throw new Error("Gagal mengambil file");
                    return res.blob();
                })
                .then(blob => {
                    container.innerHTML = "";
                    docx.renderAsync(blob, container);
                })
                .catch(err => {
                    container.innerHTML = "<p style='color:red;'>Error: " + err.message + "</p>";
                });
        }

        function closeDoc() {
            document.getElementById('previewBox').style.display = 'none';
            document.getElementById('overlay').style.display = 'none';
        }
    </script>

</body>

</html>