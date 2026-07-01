<?php
// ======================== BACKEND (POST handling) ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Configuration
    define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50 MB
    define('TEMP_DIR', __DIR__ . '/temp');

    // Create temp directory if missing
    if (!is_dir(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0755, true);
    }

    // Helper to send JSON error (for AJAX)
    function sendError($message, $code = 400) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    // Validate file upload
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

    // File size check
    if ($file['size'] > MAX_FILE_SIZE) {
        sendError('File size exceeds 50 MB limit.');
    }

    // Validate file type (MIME and extension)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['application/pdf'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($mimeType, $allowedMimes) || $extension !== 'pdf') {
        sendError('Only PDF files are allowed.');
    }

    // Password
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    if (empty($password)) {
        sendError('Password is required.');
    }

    // Generate random secure filenames
    $inputName = bin2hex(random_bytes(16)) . '.pdf';
    $outputName = bin2hex(random_bytes(16)) . '.pdf';
    $inputPath = TEMP_DIR . '/' . $inputName;
    $outputPath = TEMP_DIR . '/' . $outputName;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $inputPath)) {
        sendError('Failed to save uploaded file.');
    }

    // Build qpdf command (escape all arguments)
    $passwordEscaped = escapeshellarg($password);
    $inputEscaped = escapeshellarg($inputPath);
    $outputEscaped = escapeshellarg($outputPath);
    $command = "qpdf --password={$passwordEscaped} --decrypt {$inputEscaped} {$outputEscaped} 2>&1";

    // Execute qpdf
    exec($command, $output, $returnCode);

    // Clean up input file (always)
    if (file_exists($inputPath)) {
        unlink($inputPath);
    }

    // Check result
    if ($returnCode === 0) {
        // Success – serve file for download
        if (!file_exists($outputPath)) {
            sendError('Decrypted file not found.', 500);
        }

        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="unlocked.pdf"');
        header('Content-Length: ' . filesize($outputPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Stream the file and delete it after sending
        $handle = fopen($outputPath, 'rb');
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);

        // Clean up output file
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }
        exit;
    } else {
        // Error – parse qpdf output to provide meaningful message
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

        // Remove any leftover output file
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        sendError($errorMessage);
    }
    exit; // never reached
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Unlock Tool</title>
    <style>
        /* ===== Reset & Global ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: background-color 0.3s, color 0.3s, border-color 0.3s;
        }

        :root {
            --bg-primary: #1e1e2f;
            --bg-secondary: #2a2a40;
            --bg-card: #2d2d44;
            --text-primary: #f0f0f5;
            --text-secondary: #b0b0c8;
            --accent: #6c63ff;
            --accent-hover: #5a52d5;
            --border: #3d3d5c;
            --shadow: rgba(0, 0, 0, 0.5);
            --drop-bg: #2a2a40;
            --drop-border: #6c63ff;
            --error: #ff6b6b;
            --success: #51cf66;
            --info: #4dabf7;
        }

        [data-theme="light"] {
            --bg-primary: #f8f9fa;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #212529;
            --text-secondary: #495057;
            --border: #dee2e6;
            --shadow: rgba(0, 0, 0, 0.1);
            --drop-bg: #f1f3f5;
            --drop-border: #6c63ff;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            margin: 0;
        }

        .container {
            background-color: var(--bg-secondary);
            border-radius: 24px;
            padding: 40px 30px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 10px 30px var(--shadow);
            border: 1px solid var(--border);
        }

        /* ===== Header ===== */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 10px;
        }

        header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, var(--accent), #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 26px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--accent);
        }

        input:checked + .slider:before {
            transform: translateX(22px);
        }

        /* ===== Drop Zone ===== */
        .drop-zone {
            border: 2px dashed var(--border);
            border-radius: 20px;
            padding: 40px 20px;
            text-align: center;
            background-color: var(--drop-bg);
            cursor: pointer;
            transition: border-color 0.3s, background 0.3s;
            margin-bottom: 20px;
        }

        .drop-zone:hover {
            border-color: var(--accent);
        }

        .drop-zone.dragover {
            border-color: var(--accent);
            background-color: var(--accent);
            background-opacity: 0.05;
        }

        .drop-zone p {
            margin: 10px 0;
            color: var(--text-secondary);
            font-size: 1rem;
        }

        .drop-zone .file-label {
            display: inline-block;
            background-color: var(--accent);
            color: #fff;
            padding: 10px 24px;
            border-radius: 30px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
            margin: 10px 0;
        }

        .drop-zone .file-label:hover {
            background-color: var(--accent-hover);
        }

        .file-info {
            margin-top: 15px;
            font-weight: 500;
            color: var(--text-primary);
            word-break: break-all;
        }

        /* ===== Password Field ===== */
        .password-field {
            margin: 20px 0;
        }

        .password-field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .password-field input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-size: 1rem;
            outline: none;
            transition: border-color 0.3s;
        }

        .password-field input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.2);
        }

        /* ===== Button ===== */
        #unlockBtn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), #a855f7);
            color: #fff;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.3s;
            margin: 10px 0 15px;
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

        /* ===== Status ===== */
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
            background-color: transparent;
            border: 1px solid transparent;
        }

        .status-message.error {
            background-color: rgba(255, 107, 107, 0.15);
            border-color: var(--error);
            color: var(--error);
        }

        .status-message.success {
            background-color: rgba(81, 207, 102, 0.15);
            border-color: var(--success);
            color: var(--success);
        }

        .status-message.info {
            background-color: rgba(77, 171, 247, 0.15);
            border-color: var(--info);
            color: var(--info);
        }

        /* ===== Footer ===== */
        footer {
            margin-top: 25px;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-secondary);
            border-top: 1px solid var(--border);
            padding-top: 20px;
        }

        /* ===== Responsive ===== */
        @media (max-width: 480px) {
            .container {
                padding: 24px 16px;
            }
            header h1 {
                font-size: 1.4rem;
            }
            .drop-zone {
                padding: 24px 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔓 PDF Unlock Tool</h1>
            <div class="theme-toggle">
                <label class="switch">
                    <input type="checkbox" id="themeSwitch">
                    <span class="slider round"></span>
                </label>
                <span id="themeLabel">Dark</span>
            </div>
        </header>

        <main>
            <div class="drop-zone" id="dropZone">
                <p>📂 Drag &amp; drop your PDF here</p>
                <p>or</p>
                <label for="fileInput" class="file-label">Browse Files</label>
                <input type="file" id="fileInput" accept=".pdf" hidden>
                <p class="file-info" id="fileInfo">No file selected</p>
            </div>

            <div class="password-field">
                <label for="passwordInput">Password</label>
                <input type="password" id="passwordInput" placeholder="Enter PDF password" required>
            </div>

            <button id="unlockBtn" disabled>Unlock PDF</button>

            <div id="status" class="status-message"></div>
        </main>

        <footer>
            <p>Max file size: 50 MB · Only .pdf files</p>
        </footer>
    </div>

    <script>
        (function() {
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('fileInput');
            const fileInfo = document.getElementById('fileInfo');
            const passwordInput = document.getElementById('passwordInput');
            const unlockBtn = document.getElementById('unlockBtn');
            const status = document.getElementById('status');
            const themeSwitch = document.getElementById('themeSwitch');

            let selectedFile = null;

            // ---------- Theme Toggle ----------
            function setTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
                document.getElementById('themeLabel').textContent = theme === 'dark' ? 'Dark' : 'Light';
            }

            const savedTheme = localStorage.getItem('theme') || 'dark';
            setTheme(savedTheme);
            themeSwitch.checked = savedTheme === 'dark';

            themeSwitch.addEventListener('change', function() {
                setTheme(this.checked ? 'dark' : 'light');
            });

            // ---------- Drag & Drop ----------
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
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    handleFile(files[0]);
                }
            });

            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length > 0) {
                    handleFile(e.target.files[0]);
                }
            });

            // ---------- File Validation ----------
            function handleFile(file) {
                const maxSize = 50 * 1024 * 1024; // 50 MB
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
                fileInfo.textContent = `📄 ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                unlockBtn.disabled = false;
                showStatus('', ''); // clear
            }

            function resetFile() {
                selectedFile = null;
                fileInput.value = '';
                fileInfo.textContent = 'No file selected';
                unlockBtn.disabled = true;
            }

            // ---------- Status Messages ----------
            function showStatus(message, type) {
                status.textContent = message;
                status.className = 'status-message' + (type ? ' ' + type : '');
            }

            // ---------- Unlock Action ----------
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

                // Disable button during processing
                unlockBtn.disabled = true;
                unlockBtn.textContent = '⏳ Unlocking...';
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
                        // Error response
                        const errorData = await response.json();
                        showStatus(errorData.error || 'An unknown error occurred.', 'error');
                        unlockBtn.disabled = false;
                        unlockBtn.textContent = 'Unlock PDF';
                        return;
                    }

                    // Success – PDF blob
                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }

                    const blob = await response.blob();
                    const url = URL.createObjectURL(blob);

                    // Extract filename from Content-Disposition header if available
                    let filename = 'unlocked.pdf';
                    const disposition = response.headers.get('content-disposition');
                    if (disposition) {
                        const match = disposition.match(/filename="(.+)"/);
                        if (match) filename = match[1];
                    }

                    // Trigger download
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);

                    showStatus('✅ PDF unlocked and downloaded successfully!', 'success');
                    resetFile();
                    passwordInput.value = '';
                } catch (error) {
                    console.error(error);
                    showStatus('Something went wrong. Please try again.', 'error');
                } finally {
                    unlockBtn.disabled = false;
                    unlockBtn.textContent = 'Unlock PDF';
                }
            });
        })();
    </script>
</body>
</html>
