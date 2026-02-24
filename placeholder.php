<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

// --- KONFIGURASI DATABASE ---
$host   = "localhost";
$user   = "root";
$pass   = "";
$dbName = "db_approval";

$conn = mysqli_connect($host, $user, $pass, $dbName);
if (!$conn) { die("Koneksi gagal: " . mysqli_connect_error()); }

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- LOGIKA LOGIN ---
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
$targetDir = 'uploads';
$masterDir = 'assets';
if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
if (!is_dir($masterDir)) mkdir($masterDir, 0777, true);

// --- UPLOAD DOKUMEN ---
if (isset($_POST['upload_doc']) && ($currentRole == 'user' || $currentRole == 'admin')) {
    $originalName = $_FILES['word_file']['name'];
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $onlyName = pathinfo($originalName, PATHINFO_FILENAME);
    $cleanName = preg_replace("/[^a-zA-Z0-9]/", "_", $onlyName);
    $waktu = date('d-m-Y_H-i-s');
    $serverFileName = $cleanName . '_' . $waktu . '.' . $ext;

    if (move_uploaded_file($_FILES['word_file']['tmp_name'], $targetDir . '/' . $serverFileName)) {
        $sql = "INSERT INTO documents (original_name, extension, server_file, status) VALUES ('$onlyName', '$ext', '$serverFileName', 'Pending')";
        mysqli_query($conn, $sql);
        $_SESSION['msg_success'] = "Dokumen berhasil dikirim!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// --- LOGIKA APPROVE (INLINE REPLACEMENT) ---
if (isset($_POST['approve_doc']) && ($currentRole == 'dr' || $currentRole == 'admin')) {
    $docID = $_POST['doc_id'];
    $res = mysqli_query($conn, "SELECT * FROM documents WHERE id = $docID");
    $data = mysqli_fetch_assoc($res);
    $ttdFiles = glob("$masterDir/ttdDr.*");

    if (empty($ttdFiles)) {
        $_SESSION['msg_error'] = "File TTD tidak ditemukan!";
    } else {
        $zip = new ZipArchive;
        $filePath = $targetDir . '/' . $data['server_file'];
        
        if ($zip->open($filePath) === TRUE) {
            $ttdData = file_get_contents($ttdFiles[0]);
            $imageName = 'ttd_approved.png';
            $zip->addFromString('word/media/' . $imageName, $ttdData);

            $xml = $zip->getFromName('word/document.xml');
            $rId = "rId" . rand(10000, 99999);
            
            // Ukuran dalam EMU (3.5cm x 1.8cm agar pas di antara teks)
            $emu_w = 3.5 * 360000;
            $emu_h = 1.8 * 360000;

            // Struktur XML Drawing Inline (Mengisi aliran teks)
            $drawingXml = '<w:r><w:drawing>
                <wp:inline distT="0" distB="0" distL="0" distR="0">
                    <wp:extent cx="'.$emu_w.'" cy="'.$emu_h.'"/>
                    <wp:effectExtent l="0" t="0" r="0" b="0"/>
                    <wp:docPr id="'.rand(1,9999).'" name="Signature"/>
                    <wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>
                    <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
                        <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                            <pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
                                <pic:nvPicPr><pic:cNvPr id="0" name="TTD"/><pic:cNvPicPr/></pic:nvPicPr>
                                <pic:blipFill><a:blip r:embed="'.$rId.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>
                                <pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$emu_w.'" cy="'.$emu_h.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>
                            </pic:pic>
                        </a:graphicData>
                    </a:graphic>
                </wp:inline>
            </w:drawing></w:r>';

            /**
             * SEARCH & REPLACE (ttd)
             * Kita mencari teks "(ttd)" dan menggantinya dengan drawing.
             * Note: Microsoft Word terkadang memecah teks (t-t-d) jika diedit berkali-kali.
             * Disarankan mengetik (ttd) dalam satu tarikan ketikan di Word.
             */
            if (strpos($xml, '(ttd)') !== false) {
                // Ganti teks (ttd) dengan objek gambar inline
                $xml = str_replace('(ttd)', $drawingXml, $xml);
            } else {
                // Fallback: Jika tidak ada teks (ttd), taruh di akhir body
                $fallback = '<w:p><w:pPr><w:jc w:val="right"/></w:pPr>' . $drawingXml . '</w:p>';
                $xml = str_replace('</w:body>', $fallback . '</w:body>', $xml);
            }

            $zip->addFromString('word/document.xml', $xml);

            // Daftarkan Relasi Gambar
            $relsPath = 'word/_rels/document.xml.rels';
            $rels = $zip->getFromName($relsPath);
            $newRel = '<Relationship Id="'.$rId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$imageName.'"/>';
            $rels = str_replace('</Relationships>', $newRel . '</Relationships>', $rels);
            $zip->addFromString($relsPath, $rels);

            // Pastikan Content Type terdaftar
            $typesPath = '[Content_Types].xml';
            $types = $zip->getFromName($typesPath);
            if (strpos($types, 'Extension="png"') === false) {
                $types = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $types);
                $zip->addFromString($typesPath, $types);
            }

            $zip->close();
            
            $dateTimeFull = date("d-m-Y H:i:s");
            mysqli_query($conn, "UPDATE documents SET status = 'Approved', date_approved = '$dateTimeFull' WHERE id = $docID");
            $_SESSION['msg_success'] = "Dokumen disetujui pada posisi (ttd)!";
        }
    }
    header("Location: index.php");
    exit();
}

// --- HAPUS DOKUMEN ---
if (isset($_POST['delete_doc'])) {
    $docID = intval($_POST['doc_id']);
    $res = mysqli_query($conn, "SELECT server_file FROM documents WHERE id = $docID");
    if ($data = mysqli_fetch_assoc($res)) {
        unlink($targetDir . '/' . $data['server_file']);
        mysqli_query($conn, "DELETE FROM documents WHERE id = $docID");
    }
    header("Location: index.php");
    exit();
}

$success = $_SESSION['msg_success'] ?? "";
unset($_SESSION['msg_success']);
$result = mysqli_query($conn, "SELECT * FROM documents ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sistem Approval TTD</title>
    <script src="https://unpkg.com/jszip/dist/jszip.min.js"></script>
    <script src="https://unpkg.com/docx-preview/dist/docx-preview.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; }
        .nav { background: #2c3e50; color: white; padding: 15px 50px; display: flex; justify-content: space-between; align-items: center; }
        .container { width: 95%; max-width: 1200px; margin: 20px auto; display: grid; grid-template-columns: 350px 1fr; gap: 20px; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .btn { padding: 8px 15px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; text-decoration: none; font-size: 12px; }
        .btn-blue { background: #3498db; color: white; }
        .btn-green { background: #2ecc71; color: white; }
        .btn-red { background: #e74c3c; color: white; }
        .preview-box { display: none; position: fixed; top: 5%; left: 10%; width: 80%; height: 85%; background: white; z-index: 1000; box-shadow: 0 0 50px rgba(0,0,0,0.5); padding: 20px; border-radius: 10px; }
        .overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999; }
        #preview-container { height: 90%; overflow-y: auto; background: #525659; border-radius: 5px; display: flex; justify-content: center; }
        .info-tag { background: #e8f4fd; color: #2980b9; padding: 10px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; border-left: 5px solid #3498db; }
    </style>
</head>
<body>

<?php if (!$currentRole): ?>
    <div style="display:flex; justify-content:center; align-items:center; height:100vh; background:#2c3e50;">
        <div class="card" style="width:300px; text-align:center;">
            <h2>Login</h2>
            <form method="post">
                <input type="text" name="username" placeholder="Username" style="width:100%; padding:10px; margin-bottom:10px; box-sizing:border-box;" required>
                <input type="password" name="password" placeholder="Password" style="width:100%; padding:10px; margin-bottom:10px; box-sizing:border-box;" required>
                <button type="submit" name="login_action" class="btn btn-blue" style="width:100%">Masuk</button>
            </form>
            <p style="color:red"><?= $error ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="nav">
        <span><b>SISTEM APPROVAL</b> | User: <u><?= $_SESSION['username'] ?></u> (<?= strtoupper($currentRole) ?>)</span>
        <a href="?logout=true" class="btn btn-red">Logout</a>
    </div>
    <div class="container">
        <div class="sidebar">
            <div class="card">
                <div class="info-tag">
                    📝 <b>Petunjuk:</b><br>
                    Ketik teks <code>(ttd)</code> di file Word Anda sebagai penanda posisi tanda tangan.
                </div>
                <?php if ($currentRole != 'dr'): ?>
                <h3>Upload Dokumen</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="file" name="word_file" accept=".docx" required style="width:100%; margin-bottom:10px;">
                    <button type="submit" name="upload_doc" class="btn btn-blue" style="width:100%">Kirim Dokumen</button>
                </form>
                <?php endif; ?>
                <p style="color:green"><?= $success ?></p>
            </div>
        </div>
        <div class="main">
            <div class="card">
                <h3>Daftar Dokumen</h3>
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama File</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n=1; while ($d = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= $d['original_name'] ?></td>
                            <td><span style="color:<?= $d['status']=='Approved'?'#2ecc71':'#f1c40f'?>; font-weight:bold;"><?= $d['status'] ?></span></td>
                            <td>
                                <button onclick="viewDoc('uploads/<?= $d['server_file'] ?>')" class="btn btn-blue">👁️ Lihat</button>
                                <?php if ($d['status'] == 'Pending' && ($currentRole == 'dr' || $currentRole == 'admin')): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                        <button type="submit" name="approve_doc" class="btn btn-green">Approve</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($d['status'] == 'Approved'): ?>
                                    <a href="uploads/<?= $d['server_file'] ?>" download class="btn btn-green">Download</a>
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
    <button onclick="closeDoc()" class="btn btn-red" style="float:right">Tutup</button>
    <h3>Pratinjau Dokumen</h3>
    <div id="preview-container"></div>
</div>

<script>
    function viewDoc(url) {
        document.getElementById('previewBox').style.display = 'block';
        document.getElementById('overlay').style.display = 'block';
        const container = document.getElementById('preview-container');
        container.innerHTML = "<p style='color:white;'>Memproses...</p>";
        const fileUrl = url + "?t=" + new Date().getTime();
        fetch(fileUrl)
            .then(res => res.blob())
            .then(blob => {
                container.innerHTML = "";
                docx.renderAsync(blob, container);
            });
    }
    function closeDoc() {
        document.getElementById('previewBox').style.display = 'none';
        document.getElementById('overlay').style.display = 'none';
    }
</script>
</body>
</html>