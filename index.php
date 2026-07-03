<?php
// ======================== BACKEND (UNCHANGED) ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('MAX_FILE_SIZE', 50 * 1024 * 1024);
    define('TEMP_DIR', __DIR__ . '/temp');

    if (!is_dir(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0755, true);
    }

    function sendError($message, $code = 400) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'File upload failed.';
        if (isset($_FILES['file']['error'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMsg = 'File exceeds the maximum size (50 MB).';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMsg = 'File was only partially uploaded.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMsg = 'No file was uploaded.';
                    break;
                default:
                    $errorMsg = 'Unknown upload error.';
            }
        }
        sendError($errorMsg);
    }

    $file = $_FILES['file'];

    if ($file['size'] > MAX_FILE_SIZE) {
        sendError('File size exceeds 50 MB limit.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($mimeType, ['application/pdf']) || $extension !== 'pdf') {
        sendError('Only PDF files are allowed.');
    }

    $password = isset($_POST['password']) ? $_POST['password'] : '';
    if (empty($password)) {
        sendError('Password is required.');
    }

    $inputName = bin2hex(random_bytes(16)) . '.pdf';
    $outputName = bin2hex(random_bytes(16)) . '.pdf';
    $inputPath = TEMP_DIR . '/' . $inputName;
    $outputPath = TEMP_DIR . '/' . $outputName;

    if (!move_uploaded_file($file['tmp_name'], $inputPath)) {
        sendError('Failed to save uploaded file.');
    }

    $passwordEscaped = escapeshellarg($password);
    $inputEscaped = escapeshellarg($inputPath);
    $outputEscaped = escapeshellarg($outputPath);
    $command = "qpdf --password={$passwordEscaped} --decrypt {$inputEscaped} {$outputEscaped} 2>&1";

    exec($command, $output, $returnCode);

    if (file_exists($inputPath)) {
        unlink($inputPath);
    }

    if ($returnCode === 0) {
        if (!file_exists($outputPath)) {
            sendError('Decrypted file not found.', 500);
        }
        $downloadToken = urlencode($outputName);
        $downloadUrl = '?download=' . $downloadToken;
        header('Content-Type: application/json');
        echo json_encode(['download_url' => $downloadUrl]);
        exit;
    } else {
        $errorOutput = implode("\n", $output);
        $errorMessage = 'Failed to unlock PDF. ';

        if ($returnCode === 3 || strpos($errorOutput, 'invalid password') !== false) {
            $errorMessage = 'Incorrect password. Please try again.';
        } elseif (strpos($errorOutput, 'corrupt') !== false || strpos($errorOutput, 'damaged') !== false) {
            $errorMessage = 'The PDF appears to be corrupted.';
        } elseif (strpos($errorOutput, 'unsupported') !== false) {
            $errorMessage = 'Unsupported PDF format (maybe encrypted with unsupported algorithm).';
        } else {
            $errorMessage .= 'Error details: ' . $errorOutput;
        }

        if (file_exists($outputPath)) {
            unlink($outputPath);
        }
        sendError($errorMessage);
    }
    exit;
}

if (isset($_GET['download'])) {
    $token = $_GET['download'];
    $token = basename($token);
    $filePath = __DIR__ . '/temp/' . $token;

    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found or already deleted.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="proUnlock_unlocked.pdf"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($filePath);
    unlink($filePath);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>PDF Unlock · Pro Toolss</title>
    <link rel="icon" type="image/png" href="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts: Inter & Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,600;14..32,700;14..32,800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           RESET & BASE
        ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        html,
        body {
            height: 100%;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f4f7fb;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding-top: 80px;
            padding-bottom: 0;
            overflow-x: hidden;
        }

        /* ============================================================
           UNIFIED NAVBAR (fixed top)
        ============================================================ */
        nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999;
            padding: 14px 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 1px 20px rgba(0, 0, 0, 0.03);
            transition: all 0.4s cubic-bezier(0.2, 0.9, 0.3, 1.2);
            font-family: 'Poppins', sans-serif;
            height: 72px;
            display: flex;
            align-items: center;
        }
        nav.scrolled {
            padding: 10px 0;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 2px 30px rgba(0, 0, 0, 0.06);
        }
        .nav-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 20px;
            width: 100%;
            position: relative;
        }
        .nav-left {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            justify-content: space-between;
        }
        .hamburger {
            display: block !important;
            font-size: 1.6rem;
            color: #333;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 6px 8px;
            background: transparent;
            border: none;
            border-radius: 8px;
            line-height: 1;
            flex-shrink: 0;
        }
        .hamburger:hover {
            color: #cc0000;
            background: rgba(204, 0, 0, 0.06);
        }
        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .logo .pro {
            color: #cc0000;
        }
        .logo .toolss {
            color: #1a2a6c;
        }
        .logo-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
        }
        .nav-links-mobile {
            display: none;
            flex-direction: column;
            width: 100%;
            gap: 0.4rem;
            padding: 14px 20px 12px;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            margin-top: 8px;
            background: rgba(255, 255, 255, 0.98);
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            border-radius: 0 0 16px 16px;
        }
        .nav-links-mobile.show {
            display: flex;
        }
        .nav-links-mobile a {
            padding: 8px 0;
            font-size: 0.95rem;
            width: 100%;
            border-bottom: 1px solid rgba(0, 0, 0, 0.03);
            color: #444;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: 0.3s;
        }
        .nav-links-mobile a:last-child {
            border-bottom: none;
        }
        .nav-links-mobile a i {
            width: 24px;
            text-align: center;
            flex-shrink: 0;
            color: #cc0000;
        }
        .nav-links-mobile a.active {
            color: #cc0000;
        }
        .nav-links-mobile a:hover {
            color: #cc0000;
            padding-left: 8px;
        }

        /* ============================================================
           UNIFIED FOOTER (fixed bottom on mobile, normal on desktop)
        ============================================================ */
        footer {
            font-family: 'Poppins', sans-serif;
            flex-shrink: 0;
            width: 100%;
            background: linear-gradient(145deg, #1a1a2e, #16213e);
            color: #e0e0e0;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            z-index: 998;
            position: relative;
            padding: 18px 0;
            margin-top: 40px;
        }
        .footer-content {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .footer-powered {
            font-size: 1rem;
            color: #aaaaaa;
            font-weight: 300;
            letter-spacing: 0.5px;
        }
        .footer-powered a {
            color: #ff6b6b;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }
        .footer-powered a:hover {
            color: #ff4444;
            text-decoration: underline;
        }
        .footer-powered i {
            color: #ff6b6b;
            margin: 0 6px;
        }

        /* ============================================================
           PDF UNLOCK TOOL – UNIFIED STYLING
        ============================================================ */
        main {
            flex: 1 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px 16px 30px;
            width: 100%;
        }

        .container {
            background: #ffffff;
            border-radius: 28px;
            padding: 32px 28px 36px;
            max-width: 560px;
            width: 100%;
            box-shadow: 0 20px 48px -12px rgba(0, 0, 0, 0.10), 0 0 0 1px rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.7);
            transition: box-shadow 0.2s;
        }

        .container h1 {
            font-size: 1.8rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            text-align: center;
            background: linear-gradient(135deg, #2563eb, #ea580c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 28px;
        }
        .container h1 i {
            -webkit-text-fill-color: #2563eb;
            margin-right: 8px;
        }

        .drop-zone {
            border: 2px dashed #d1d9e6;
            border-radius: 20px;
            padding: 36px 20px;
            text-align: center;
            background: #f8fafd;
            cursor: pointer;
            transition: border-color 0.3s, background 0.3s, box-shadow 0.3s;
            margin-bottom: 20px;
            position: relative;
        }
        .drop-zone:hover {
            border-color: #2563eb;
            background: #f1f7ff;
        }
        .drop-zone.dragover {
            border-color: #2563eb;
            background: #e3f0ff;
            box-shadow: 0 0 0 6px rgba(37, 99, 235, 0.08);
        }
        .drop-zone .icon-big {
            font-size: 3rem;
            color: #2563eb;
            display: block;
            margin-bottom: 10px;
        }
        .drop-zone p {
            margin: 8px 0;
            color: #475569;
            font-size: 1rem;
            font-weight: 500;
        }
        .drop-zone .file-label {
            display: inline-block;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            padding: 10px 28px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s, transform 0.2s;
            margin: 10px 0;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.20);
        }
        .drop-zone .file-label:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.30);
        }
        .file-info {
            margin-top: 14px;
            font-weight: 500;
            word-break: break-all;
            color: #1e293b;
            font-size: 0.95rem;
        }
        .file-info i {
            margin-right: 8px;
            color: #2563eb;
        }

        .password-field {
            margin: 22px 0 18px;
        }
        .password-field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #1e293b;
            font-size: 0.95rem;
        }
        .password-field label i {
            margin-right: 8px;
            color: #2563eb;
        }
        .password-field input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 14px;
            border: 2px solid #e2e8f0;
            background: #ffffff;
            color: #1e293b;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .password-field input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.10);
        }

        #unlockBtn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 16px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.3s, box-shadow 0.3s;
            margin: 10px 0 12px;
            box-shadow: 0 6px 24px rgba(37, 99, 235, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        #unlockBtn i {
            font-size: 1.1rem;
        }
        #unlockBtn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(37, 99, 235, 0.30);
        }
        #unlockBtn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .status-message {
            padding: 12px 16px;
            border-radius: 14px;
            margin-top: 16px;
            font-weight: 500;
            text-align: center;
            min-height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 1px solid transparent;
            font-size: 0.95rem;
        }
        .status-message i {
            margin-right: 10px;
            font-size: 1.1rem;
        }
        .status-message.error {
            background: rgba(255, 107, 107, 0.10);
            border-color: #ff6b6b;
            color: #dc2626;
        }
        .status-message.success {
            background: rgba(81, 207, 102, 0.10);
            border-color: #51cf66;
            color: #16a34a;
        }
        .status-message.info {
            background: rgba(77, 171, 247, 0.10);
            border-color: #4dabf7;
            color: #2563eb;
        }

        #downloadBtnContainer {
            margin: 16px 0 4px;
            text-align: center;
        }
        #downloadBtn {
            display: inline-block;
            padding: 14px 36px;
            background: linear-gradient(135deg, #059669, #047857);
            color: #fff;
            font-weight: 700;
            border-radius: 40px;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.3s;
            box-shadow: 0 6px 24px rgba(5, 150, 105, 0.25);
        }
        #downloadBtn i {
            margin-right: 8px;
        }
        #downloadBtn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(5, 150, 105, 0.30);
        }

        /* ============================================================
           RESPONSIVE: MOBILE (≤ 768px) – fixed header & footer
        ============================================================ */
        @media (max-width: 768px) {
            body {
                padding-top: 64px;
                padding-bottom: 60px;
            }
            nav {
                height: 60px;
                padding: 8px 0;
            }
            .nav-container {
                padding: 0 14px;
            }
            .nav-left {
                gap: 8px;
            }
            .hamburger {
                font-size: 1.5rem;
                padding: 4px 6px;
            }
            .logo {
                font-size: 1.2rem;
                gap: 6px;
            }
            .logo-icon {
                width: 28px;
                height: 28px;
            }
            .nav-links-mobile {
                padding: 12px 16px 10px;
                gap: 0.4rem;
            }
            .nav-links-mobile a {
                font-size: 0.85rem;
                padding: 6px 0;
            }
            .nav-links-mobile a i {
                width: 20px;
                font-size: 0.8rem;
            }

            footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                padding: 12px 0;
                margin-top: 0;
                height: 56px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: linear-gradient(145deg, #1a1a2e, #16213e);
                box-shadow: 0 -4px 30px rgba(0, 0, 0, 0.2);
                border-top: 1px solid rgba(255, 255, 255, 0.06);
            }
            .footer-powered {
                font-size: 0.85rem;
            }

            main {
                padding: 12px 12px 20px;
            }
            .container {
                padding: 24px 18px 28px;
                border-radius: 22px;
            }
            .container h1 {
                font-size: 1.5rem;
                margin-bottom: 22px;
            }
            .drop-zone {
                padding: 28px 16px;
            }
            .drop-zone .icon-big {
                font-size: 2.4rem;
            }
            .drop-zone p {
                font-size: 0.9rem;
            }
            .drop-zone .file-label {
                padding: 8px 20px;
                font-size: 0.9rem;
            }
            .password-field input {
                padding: 10px 14px;
                font-size: 0.95rem;
            }
            #unlockBtn {
                font-size: 0.95rem;
                padding: 12px;
            }
            .status-message {
                font-size: 0.9rem;
                padding: 10px 14px;
                min-height: 48px;
            }
            #downloadBtn {
                padding: 12px 28px;
                font-size: 0.95rem;
            }
        }

        @media (max-width: 480px) {
            body {
                padding-top: 56px;
                padding-bottom: 54px;
            }
            nav {
                height: 52px;
                padding: 6px 0;
            }
            .nav-container {
                padding: 0 12px;
            }
            .nav-left {
                gap: 6px;
            }
            .hamburger {
                font-size: 1.3rem;
                padding: 3px 5px;
            }
            .logo {
                font-size: 1rem;
                gap: 4px;
            }
            .logo-icon {
                width: 24px;
                height: 24px;
            }
            .nav-links-mobile {
                padding: 10px 14px 8px;
                gap: 0.3rem;
            }
            .nav-links-mobile a {
                font-size: 0.8rem;
                padding: 5px 0;
            }
            .nav-links-mobile a i {
                width: 18px;
                font-size: 0.7rem;
            }
            footer {
                height: 50px;
                padding: 10px 0;
            }
            .footer-powered {
                font-size: 0.75rem;
            }
            main {
                padding: 8px 8px 16px;
            }
            .container {
                padding: 18px 14px 22px;
                border-radius: 18px;
            }
            .container h1 {
                font-size: 1.3rem;
                margin-bottom: 18px;
            }
            .drop-zone {
                padding: 20px 12px;
                border-radius: 16px;
            }
            .drop-zone .icon-big {
                font-size: 2rem;
            }
            .drop-zone p {
                font-size: 0.85rem;
            }
            .drop-zone .file-label {
                padding: 6px 16px;
                font-size: 0.8rem;
            }
            .file-info {
                font-size: 0.85rem;
            }
            .password-field {
                margin: 16px 0 14px;
            }
            .password-field label {
                font-size: 0.85rem;
            }
            .password-field input {
                padding: 8px 12px;
                font-size: 0.9rem;
                border-radius: 12px;
            }
            #unlockBtn {
                font-size: 0.9rem;
                padding: 10px;
                border-radius: 14px;
            }
            .status-message {
                font-size: 0.85rem;
                padding: 8px 12px;
                min-height: 42px;
            }
            #downloadBtn {
                padding: 10px 22px;
                font-size: 0.9rem;
            }
        }

        @media (min-width: 769px) {
            body {
                padding-top: 76px;
                padding-bottom: 0;
            }
            nav {
                height: 76px;
                padding: 14px 0;
            }
            .nav-container {
                padding: 0 20px;
            }
            .nav-left {
                gap: 12px;
            }
            .hamburger {
                font-size: 1.8rem;
                padding: 8px 12px;
            }
            .logo {
                font-size: 1.5rem;
                gap: 8px;
            }
            .logo-icon {
                width: 32px;
                height: 32px;
            }
            .nav-links-mobile {
                max-width: 300px;
                right: 0;
                left: auto;
                border-radius: 0 0 16px 16px;
            }
            footer {
                position: relative;
                bottom: auto;
                left: auto;
                right: auto;
                height: auto;
                padding: 30px 0;
                margin-top: 60px;
                box-shadow: none;
            }
            .footer-powered {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<!-- ============================================================
     UNIFIED NAVBAR (fixed top)
============================================================ -->
<nav id="mainNav">
    <div class="nav-container">
        <div class="nav-left">
            <a href="https://www.protoolss.online" class="logo">
                <img src="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png" alt="Pro Toolss Logo" class="logo-icon" />
                <span class="pro">Pro</span><span class="toolss">Toolss</span>
            </a>
            <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <div class="nav-links-mobile" id="navLinksMobile">
            <a href="https://www.protoolss.online" class="active"><i class="fas fa-home"></i> Home</a>
        </div>
    </div>
</nav>

<!-- ============================================================
     MAIN CONTENT – PDF UNLOCK TOOL
============================================================ -->
<main>
    <div class="container">
        <h1><i class="fas fa-lock"></i> PDF Unlock Tool</h1>

        <div class="drop-zone" id="dropZone">
            <i class="fas fa-cloud-upload-alt icon-big"></i>
            <p>Drag &amp; drop your PDF here</p>
            <p>or</p>
            <label for="fileInput" class="file-label"><i class="fas fa-folder-open"></i> Browse Files</label>
            <input type="file" id="fileInput" accept=".pdf" hidden>
            <div class="file-info" id="fileInfo"><i class="fas fa-file-pdf"></i> No file selected</div>
        </div>

        <div class="password-field">
            <label for="passwordInput"><i class="fas fa-key"></i> Password</label>
            <input type="password" id="passwordInput" placeholder="Enter PDF password" required>
        </div>

        <button id="unlockBtn" disabled><i class="fas fa-unlock"></i> Unlock PDF</button>

        <div id="status" class="status-message"></div>

        <div id="downloadBtnContainer" style="display:none;">
            <a id="downloadBtn" href="#" download><i class="fas fa-download"></i> Download Unlocked PDF</a>
        </div>
    </div>
</main>

<!-- ============================================================
     UNIFIED FOOTER (fixed bottom on mobile, normal on desktop)
============================================================ -->
<footer>
    <div class="footer-content">
        <div class="footer-powered">
            Powered by
            <a href="https://www.protoolss.online" target="_blank" rel="noopener noreferrer">www.protoolss.online</a>
        </div>
    </div>
</footer>

<!-- ============================================================
     SCRIPTS (UNIFIED NAVBAR + PDF UNLOCK LOGIC)
============================================================ -->
<script>
    // ============================================================
    // UNIFIED NAVBAR SCRIPTS (Hamburger, scroll)
    // ============================================================
    (function() {
        const nav = document.getElementById('mainNav');
        window.addEventListener('scroll', function() {
            if (window.scrollY > 50) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        });

        const hamburger = document.getElementById('hamburgerBtn');
        const navLinksMobile = document.getElementById('navLinksMobile');

        hamburger.addEventListener('click', function(e) {
            e.stopPropagation();
            navLinksMobile.classList.toggle('show');
            const icon = this.querySelector('i');
            icon.classList.toggle('fa-bars');
            icon.classList.toggle('fa-times');
        });

        document.querySelectorAll('#navLinksMobile a').forEach(link => {
            link.addEventListener('click', function() {
                navLinksMobile.classList.remove('show');
                const icon = hamburger.querySelector('i');
                icon.classList.add('fa-bars');
                icon.classList.remove('fa-times');
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('nav') && navLinksMobile.classList.contains('show')) {
                navLinksMobile.classList.remove('show');
                const icon = hamburger.querySelector('i');
                icon.classList.add('fa-bars');
                icon.classList.remove('fa-times');
            }
        });
    })();

    // ============================================================
    // PDF UNLOCK TOOL LOGIC (unchanged functionality)
    // ============================================================
    (function() {
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const passwordInput = document.getElementById('passwordInput');
        const unlockBtn = document.getElementById('unlockBtn');
        const status = document.getElementById('status');
        const downloadContainer = document.getElementById('downloadBtnContainer');
        const downloadBtn = document.getElementById('downloadBtn');

        let selectedFile = null;

        document.getElementById('homeBtn')?.addEventListener('click', function() {
            window.location.href = 'https://www.protoolss.online';
        });

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                handleFile(e.dataTransfer.files[0]);
            }
        });
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFile(e.target.files[0]);
            }
        });

        function handleFile(file) {
            const maxSize = 50 * 1024 * 1024;
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                showStatus('Please select a valid PDF file.', 'error');
                resetFile();
                return;
            }
            if (file.size > maxSize) {
                showStatus('File size exceeds 50 MB limit.', 'error');
                resetFile();
                return;
            }
            selectedFile = file;
            fileInfo.innerHTML = `<i class="fas fa-file-pdf"></i> ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
            unlockBtn.disabled = false;
            showStatus('', '');
            downloadContainer.style.display = 'none';
        }

        function resetFile() {
            selectedFile = null;
            fileInput.value = '';
            fileInfo.innerHTML = '<i class="fas fa-file-pdf"></i> No file selected';
            unlockBtn.disabled = true;
            downloadContainer.style.display = 'none';
        }

        function showStatus(message, type) {
            status.innerHTML = message ? `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : 'info-circle'}"></i> ${message}` : '';
            status.className = 'status-message' + (type ? ' ' + type : '');
        }

        unlockBtn.addEventListener('click', async function() {
            if (!selectedFile) {
                showStatus('Please select a PDF file.', 'error');
                return;
            }
            const password = passwordInput.value.trim();
            if (!password) {
                showStatus('Please enter the PDF password.', 'error');
                return;
            }

            unlockBtn.disabled = true;
            unlockBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Unlocking...';
            showStatus('Processing...', 'info');

            const formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('password', password);
            formData.append('ajax', '1');

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    body: formData
                });

                const contentType = response.headers.get('content-type') || '';

                if (contentType.includes('application/json')) {
                    const data = await response.json();
                    if (data.error) {
                        showStatus(data.error, 'error');
                    } else if (data.download_url) {
                        showStatus('✅ PDF unlocked successfully!', 'success');
                        downloadBtn.href = data.download_url;
                        downloadContainer.style.display = 'block';
                    } else {
                        showStatus('Unexpected response from server.', 'error');
                    }
                } else {
                    showStatus('Server error. Please try again.', 'error');
                }
            } catch (error) {
                console.error(error);
                showStatus('Something went wrong. Please try again.', 'error');
            } finally {
                unlockBtn.disabled = false;
                unlockBtn.innerHTML = '<i class="fas fa-unlock"></i> Unlock PDF';
            }
        });

        downloadBtn.addEventListener('click', function() {
            setTimeout(() => {
                downloadContainer.style.display = 'none';
                resetFile();
                passwordInput.value = '';
                showStatus('Download completed. You can unlock another file.', 'info');
            }, 500);
        });
    })();
</script>

</body>
</html>
