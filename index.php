<?php
// ======================== BACKEND ========================
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Unlock Tool</title>
    <link rel="icon" href="https://i.ibb.co/FkJPMZ8r/Chat-GPT-Image-Jun-18-2026-07-58-22-AM.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #212529;
            padding-top: 75px;
        }

        /* ===== NAVBAR ===== */
        .new-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(4px);
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
            padding: 12px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            font-size: 32px;
            font-weight: 800;
        }
        .logo-blue { color: #007BFF; }
        .logo-orange { color: #FF6F00; }

        .home-btn {
            background: none;
            border: none;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
        }
        .home-btn .lines {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .home-btn .lines span {
            width: 24px;
            height: 3px;
            background: #1e293b;
            border-radius: 10px;
        }
        .home-btn .label {
            font-size: 10px;
            font-weight: 700;
            color: #1e293b;
            margin-top: 3px;
            text-transform: uppercase;
        }

        /* ===== MAIN ===== */
        main {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: #ffffff;
            border-radius: 24px;
            padding: 40px 30px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #dee2e6;
        }

        .container h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 30px;
            text-align: center;
            background: linear-gradient(135deg, #007BFF, #FF6F00);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .container h1 i {
            margin-right: 10px;
        }

        .drop-zone {
            border: 2px dashed #dee2e6;
            border-radius: 20px;
            padding: 40px 20px;
            text-align: center;
            background: #f1f3f5;
            cursor: pointer;
            transition: border-color 0.3s, background 0.3s;
            margin-bottom: 20px;
        }
        .drop-zone:hover {
            border-color: #007BFF;
        }
        .drop-zone.dragover {
            border-color: #007BFF;
            background: rgba(0,123,255,0.05);
        }
        .drop-zone .icon-big {
            font-size: 3rem;
            color: #007BFF;
            display: block;
            margin-bottom: 10px;
        }
        .drop-zone p {
            margin: 10px 0;
            color: #495057;
            font-size: 1rem;
        }
        .drop-zone .file-label {
            display: inline-block;
            background: #007BFF;
            color: #fff;
            padding: 10px 24px;
            border-radius: 30px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
            margin: 10px 0;
        }
        .drop-zone .file-label:hover {
            background: #0056b3;
        }
        .file-info {
            margin-top: 15px;
            font-weight: 500;
            word-break: break-all;
            color: #212529;
        }
        .file-info i {
            margin-right: 6px;
            color: #dc3545;
        }

        .password-field {
            margin: 20px 0;
        }
        .password-field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #495057;
        }
        .password-field label i {
            margin-right: 6px;
            color: #6c757d;
        }
        .password-field input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #dee2e6;
            background: #fff;
            color: #212529;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.3s;
        }
        .password-field input:focus {
            border-color: #007BFF;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.2);
        }

        #unlockBtn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #007BFF, #FF6F00);
            color: #fff;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.3s;
            margin: 10px 0 15px;
        }
        #unlockBtn i {
            margin-right: 8px;
        }
        #unlockBtn:hover:not(:disabled) {
            transform: translateY(-2px);
            opacity: 0.9;
        }
        #unlockBtn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .status-message {
            padding: 12px 16px;
            border-radius: 12px;
            margin-top: 15px;
            font-weight: 500;
            text-align: center;
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 1px solid transparent;
        }
        .status-message i {
            margin-right: 8px;
        }
        .status-message.error {
            background: rgba(255, 107, 107, 0.15);
            border-color: #ff6b6b;
            color: #ff6b6b;
        }
        .status-message.success {
            background: rgba(81, 207, 102, 0.15);
            border-color: #51cf66;
            color: #51cf66;
        }
        .status-message.info {
            background: rgba(77, 171, 247, 0.15);
            border-color: #4dabf7;
            color: #4dabf7;
        }

        #downloadBtnContainer {
            margin: 15px 0 5px;
            text-align: center;
        }
        #downloadBtn {
            display: inline-block;
            padding: 12px 30px;
            background: #28a745;
            color: #fff;
            font-weight: 600;
            border-radius: 30px;
            text-decoration: none;
            transition: background 0.3s;
        }
        #downloadBtn i {
            margin-right: 8px;
        }
        #downloadBtn:hover {
            background: #218838;
        }

        /* ===== FOOTER ===== */
        footer {
            background: #0b1426;
            color: white;
            height: 70px;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
        }
        .design-footer {
            color: #94a3b8;
        }
        .highlight {
            color: #facc15;
            font-weight: 700;
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            footer {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
            }
            .container {
                padding: 24px 16px;
            }
        }
    </style>
</head>
<body>

<!-- ====== NAVBAR ====== -->
<nav class="new-nav">
    <div class="logo">
        <span class="logo-blue">Pro</span>
        <span class="logo-orange">Toolss</span>
    </div>
    <button id="homeBtn" class="home-btn">
        <div class="lines">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <span class="label">Home</span>
    </button>
</nav>

<!-- ====== MAIN ====== -->
<main>
    <div class="container">
        <h1><i class="fas fa-lock"></i> PDF Unlock Tool</h1>

        <div class="drop-zone" id="dropZone">
            <i class="fas fa-cloud-upload-alt icon-big"></i>
            <p>Drag &amp; drop your PDF here</p>
            <p>or</p>
            <label for="fileInput" class="file-label"><i class="fas fa-folder-open"></i> Browse Files</label>
            <input type="file" id="fileInput" accept=".pdf" hidden>
            <p class="file-info" id="fileInfo"><i class="fas fa-file-pdf"></i> No file selected</p>
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

<!-- ====== FOOTER ====== -->
<footer>
    <div class="design-footer">
        Design by
        <a href="https://www.protoolss.online" target="_blank" class="highlight">
            Pro Toolss
        </a>
    </div>
</footer>

<script>
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

        document.getElementById('homeBtn').addEventListener('click', function() {
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
