<?php
session_start();
require_once 'includes/functions.php';
require_once 'config/connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

$appType = $_SESSION['application_type'] ?? 'new';
$isNew = ($appType === 'new');
$isRenewal = ($appType === 'renewal');

$application = getOrCreateDraftApplication($user_id);
$app_id = $application['id'];

// --- Validation: ensure steps 2,3,4,5,6 are complete ---
$missingStep = getMissingStep($app_id, $conn);
$currentStep = 7;
if ($missingStep > 0 && $missingStep < $currentStep) {
    $stepPages = [
        2 => 'application_type.php',
        3 => 'business_info.php',
        4 => 'business_address.php',
        5 => 'owner_info.php',
        6 => 'additional_info.php',
        7 => 'documents.php'
    ];
    if (isset($stepPages[$missingStep])) {
        header('Location: ' . $stepPages[$missingStep]);
        exit;
    }
}

// Load already uploaded documents from database
$uploadedDocs = getUploadedDocsStatus($app_id);

// Count uploaded docs (only those in required list)
$requiredTypes = ['barangay','dti_sec','rpt','engineering','zoning','sanitary','fire_safety'];
$uploadedCount = 0;
foreach ($requiredTypes as $t) {
    if (isset($uploadedDocs[$t])) $uploadedCount++;
}

// Handle POST to advance step (only after JS validation)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    updateApplicationStep($app_id, 8);
    header('Location: review_submit.php');
    exit;
}

// Sidebar steps
$steps = [
    1 => 'Requirements',
    2 => 'Application type',
    3 => 'Business info',
    4 => 'Business address',
    5 => 'Owner info',
    6 => 'Additional info',
    7 => 'Documents',
    8 => 'Review and submit'
];

// Document list (key => [number, title, office])
$documents = [
    'barangay'     => ['n' => 1, 'title' => 'Barangay clearance', 'office' => 'Barangay'],
    'dti_sec'      => ['n' => 2, 'title' => 'DTI/SEC photocopy', 'office' => 'DTI / SEC'],
    'rpt'          => ['n' => 3, 'title' => 'RPT, current year 2026 payment', 'office' => 'Treasury Office'],
    'engineering'  => ['n' => 4, 'title' => 'Engineering', 'office' => 'Municipal Engineering Office'],
    'zoning'       => ['n' => 5, 'title' => 'Zoning certification (MPDC)', 'office' => 'Municipal Planning and Development Office'],
    'sanitary'     => ['n' => 6, 'title' => 'Sanitary health permit', 'office' => 'Health Center'],
    'fire_safety'  => ['n' => 7, 'title' => 'Fire safety certificate 2026', 'office' => 'Bureau of Fire Protection'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Documents | <?php echo $isRenewal ? 'Renew' : 'New'; ?> Business Permit | Buyawanong Placer</title>
    
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="apple-touch-icon" href="/img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

    <style>
        :root {
            --brand-dark: #1e293b;
            --brand-dark-hover: #0f172a;
            --brand-yellow: #fbbf24;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --green: #10b981;
        }

        * { font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-light); color: var(--brand-dark); }

        /* Back link */
        .back-link {
            color: var(--brand-dark);
            text-decoration: underline;
            text-decoration-color: #cbd5e1;
            text-underline-offset: 3px;
            text-decoration-thickness: 1px;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        .back-link:hover {
            color: var(--brand-dark);
            text-decoration-color: var(--brand-dark);
        }

        /* Page title */
        .page-title {
            font-weight: 800;
            font-size: clamp(1.9rem, 4vw, 2.6rem);
            letter-spacing: -0.02em;
            line-height: 1.1;
            color: var(--brand-dark);
            margin-bottom: 6px;
        }
        .page-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        /* Sidebar steps */
        .step-list { list-style: none; padding: 0; margin: 0; }
        .step-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 7px 0;
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        .step-list li .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #ffffff;
            border: 1.5px solid var(--border-color);
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .step-list li.completed .num {
            background: var(--green);
            border-color: var(--green);
            color: #ffffff;
        }
        .step-list li.active { color: var(--brand-dark); font-weight: 700; }
        .step-list li.active .num {
            background: var(--brand-yellow);
            border-color: var(--brand-yellow);
            color: var(--brand-dark);
        }

        /* Info banner (light green) */
        .info-banner {
            background: #ecfdf5;
            border-left: 4px solid var(--green);
            border-radius: 6px;
            padding: 14px 18px;
            font-size: 0.875rem;
            color: var(--brand-dark);
            margin-bottom: 28px;
            line-height: 1.55;
        }

        /* Section heading with count */
        .upload-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .upload-header h2 {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--brand-dark);
            margin: 0;
        }
        .upload-counter {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #ecfdf5;
            color: #065f46;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 2rem;
            border: 1px solid #a7f3d0;
        }

        /* Document rows */
        .document-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 0;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .document-row:last-of-type {
            border-bottom: none;
        }
        .doc-info { flex: 1; min-width: 200px; }
        .doc-title {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--brand-dark);
            margin-bottom: 3px;
        }
        .doc-office {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0;
        }
        .doc-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--green);
            margin-top: 4px;
        }
        .doc-status.hidden { display: none; }

        /* Right side: file input + button */
        .doc-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .file-input-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }
        .btn-choose-file {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 20px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--brand-dark);
            font-weight: 600;
            font-size: 0.82rem;
            transition: all 0.15s ease;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-choose-file:hover {
            background: #f1f5f9;
            border-color: var(--brand-dark);
        }
        .btn-choose-file:disabled {
            opacity: 0.6;
            cursor: wait;
        }
        .selected-file-name {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 4px;
            display: block;
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .selected-file-name.ok { color: var(--green); font-weight: 500; }
        .selected-file-name.err { color: #ef4444; font-weight: 500; }

        /* Back + Continue */
        .btn-back {
            background: #ffffff;
            color: var(--brand-dark);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 32px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-back:hover {
            background: #f1f5f9;
            border-color: var(--brand-dark);
            color: var(--brand-dark);
        }
        .btn-continue {
            background-color: var(--brand-dark);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 12px 32px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .btn-continue:hover {
            background-color: var(--brand-dark-hover);
            color: #ffffff;
            transform: translateY(-2px);
        }

        .alert { border-radius: 8px; font-size: 0.9rem; }

        /* Responsive */
        @media (max-width: 991px) {
            .step-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 28px; }
            .step-list li { padding: 4px 10px 4px 4px; background: #ffffff; border: 1px solid var(--border-color); border-radius: 2rem; }
            .step-list li.completed { background: #ecfdf5; border-color: var(--green); }
            .step-list li.active { background: #fffbeb; border-color: var(--brand-yellow); }
            .step-list li span:not(.num) { font-size: 0.78rem; }
        }
        @media (max-width: 576px) {
            .btn-back, .btn-continue { width: 100%; }
            .document-row { flex-direction: column; align-items: flex-start; }
            .doc-actions { width: 100%; }
            .btn-choose-file { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<?php include 'header/nav.php'; ?>

<div class="container py-4 py-md-5" style="max-width: 1080px;">

    <!-- Back link -->
    <div class="mb-3">
        <a href="dashboard.php" class="back-link">Back to dashboard</a>
    </div>

    <!-- Title -->
    <div class="mb-4">
        <h1 class="page-title">
            <?php echo $isRenewal ? 'Renew business permit application' : 'New business permit application'; ?>
        </h1>
        <p class="page-subtitle">Tax year 2026, Municipality of Placer</p>
    </div>

    <div class="row g-4 g-lg-5">

        <!-- LEFT: Sidebar -->
        <div class="col-lg-4 col-xl-3">
            <ul class="step-list">
                <?php foreach ($steps as $num => $label):
                    $cls = '';
                    if ($num < 7) $cls = 'completed';
                    elseif ($num === 7) $cls = 'active';
                ?>
                    <li class="<?php echo $cls; ?>">
                        <span class="num">
                            <?php if ($num < 7): ?>
                                <i class="bi bi-check-lg" style="font-size:0.7rem;"></i>
                            <?php else: ?>
                                <?php echo $num; ?>
                            <?php endif; ?>
                        </span>
                        <span><?php echo htmlspecialchars($label); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- RIGHT: Content -->
        <div class="col-lg-8 col-xl-9">

            <!-- Info banner -->
            <div class="info-banner">
                Upload scanned or clear photo copies. The LGU checks each one. You may need to bring the originals when you claim the permit.
            </div>

            <!-- Upload header -->
            <div class="upload-header">
                <h2>Upload documents</h2>
                <span class="upload-counter" id="uploadCounter"><?php echo $uploadedCount; ?> of <?php echo count($documents); ?></span>
            </div>

            <!-- Upload form -->
            <form id="documentsForm" enctype="multipart/form-data">

                <?php foreach ($documents as $key => $doc): ?>
                    <div class="document-row" id="row_<?php echo $key; ?>">
                        <div class="doc-info">
                            <div class="doc-title"><?php echo $doc['n']; ?>. <?php echo htmlspecialchars($doc['title']); ?></div>
                            <p class="doc-office"><?php echo htmlspecialchars($doc['office']); ?></p>
                            <span class="doc-status <?php echo isset($uploadedDocs[$key]) ? '' : 'hidden'; ?>" id="status_<?php echo $key; ?>">
                                <i class="bi bi-check-circle-fill"></i> Uploaded
                            </span>
                            <span class="selected-file-name" id="filename_<?php echo $key; ?>"></span>
                        </div>

                        <div class="doc-actions">
                            <!-- Hidden native input -->
                            <input type="file"
                                   class="file-input-hidden"
                                   id="file_<?php echo $key; ?>"
                                   accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                            <!-- Visible "Choose file" button -->
                            <button type="button"
                                    class="btn-choose-file"
                                    data-doc-type="<?php echo $key; ?>"
                                    id="btn_<?php echo $key; ?>">
                                <i class="bi bi-paperclip"></i> Choose file
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Back + Continue -->
                <div class="d-flex justify-content-between mt-5 flex-wrap gap-2">
                    <button type="button" class="btn-back" id="backBtn">Back</button>
                    <button type="submit" class="btn-continue" id="continueBtn">Continue</button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- Footer -->
<footer class="border-top py-4 mt-5">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <small class="text-muted">© <?php echo date('Y'); ?> Municipality of Placer, Surigao del Norte.</small>
        <div>
            <a href="#" class="text-decoration-none text-muted small me-3">Privacy Policy</a>
            <a href="#" class="text-decoration-none text-muted small">Contact Us</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
/* ============================================================
   FILE VALIDATION RULES
   ============================================================ */
const MAX_SIZE_MB    = 25;
const MAX_SIZE_BYTES = MAX_SIZE_MB * 1024 * 1024;
const ALLOWED_EXT    = ['pdf', 'jpg', 'jpeg', 'png'];
const ALLOWED_MIME   = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
const BLOCKED_MIME_PREFIXES = ['video/', 'audio/'];

const requiredDocs = ['barangay','dti_sec','rpt','engineering','zoning','sanitary','fire_safety'];

const docNames = {
    barangay: 'Barangay clearance',
    dti_sec: 'DTI/SEC copy',
    rpt: 'RPT payment',
    engineering: 'Engineering',
    zoning: 'Zoning certification',
    sanitary: 'Sanitary health permit',
    fire_safety: 'Fire safety certificate'
};

/* -------- Uploaded status from PHP -------- */
let uploaded = {};
<?php foreach ($uploadedDocs as $type => $name): ?>
    uploaded['<?php echo $type; ?>'] = true;
<?php endforeach; ?>

/* -------- Counter update -------- */
function updateCounter() {
    const count = requiredDocs.filter(d => uploaded[d]).length;
    const counterEl = document.getElementById('uploadCounter');
    if (counterEl) counterEl.textContent = count + ' of ' + requiredDocs.length;
}
updateCounter();

/* ============================================================
   VALIDATE FILE
   ============================================================ */
function validateFile(file) {
    if (!file) return { valid: false, error: 'No file selected.' };

    const ext  = (file.name.split('.').pop() || '').toLowerCase();
    let mime   = (file.type || '').toLowerCase();

    if (BLOCKED_MIME_PREFIXES.some(p => mime.startsWith(p))) {
        return { valid: false, error: `Video and audio files are not allowed. You uploaded a <strong>${mime || 'media'}</strong> file.` };
    }
    if (!ALLOWED_EXT.includes(ext)) {
        return { valid: false, error: `File type <strong>.${ext}</strong> is not allowed. Only PDF, JPG, JPEG, and PNG are accepted.` };
    }
    if (mime && !ALLOWED_MIME.includes(mime)) {
        return { valid: false, error: 'The file content does not match an allowed format. Please upload a genuine PDF or image file.' };
    }
    if (file.size > MAX_SIZE_BYTES) {
        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
        return { valid: false, error: `File size is <strong>${sizeMB} MB</strong>. Maximum is <strong>${MAX_SIZE_MB} MB</strong>.` };
    }
    if (file.size === 0) {
        return { valid: false, error: 'The file appears to be empty.' };
    }
    return { valid: true, error: '' };
}

/* ============================================================
   UPLOAD
   ============================================================ */
async function uploadDocument(docType) {
    const fileInput = document.getElementById(`file_${docType}`);
    const file = fileInput.files[0];

    if (!file) {
        await Swal.fire({
            icon: 'warning', title: 'No file selected',
            text: `Please choose a file for ${docNames[docType]}.`,
            confirmButtonColor: '#1e293b'
        });
        return false;
    }

    const check = validateFile(file);
    if (!check.valid) {
        await Swal.fire({
            icon: 'error', title: 'Invalid file',
            html: check.error, confirmButtonColor: '#1e293b'
        });
        fileInput.value = '';
        const nameEl = document.getElementById(`filename_${docType}`);
        if (nameEl) { nameEl.textContent = ''; nameEl.className = 'selected-file-name'; }
        return false;
    }

    const formData = new FormData();
    formData.append('document_type', docType);
    formData.append('file', file);

    const btn = document.getElementById(`btn_${docType}`);
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Uploading...';
    btn.disabled = true;

    try {
        const response = await fetch('upload_document.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            uploaded[docType] = true;
            updateCounter();

            const statusEl = document.getElementById(`status_${docType}`);
            if (statusEl) statusEl.classList.remove('hidden');

            await Swal.fire({
                icon: 'success', title: 'Uploaded!',
                html: `<strong>${file.name}</strong><br><small class="text-muted">${(file.size / 1024 / 1024).toFixed(2)} MB</small>`,
                timer: 1500, showConfirmButton: false
            });
            return true;
        } else {
            throw new Error(data.error || 'Upload failed');
        }
    } catch (err) {
        await Swal.fire({
            icon: 'error', title: 'Upload failed',
            text: err.message, confirmButtonColor: '#1e293b'
        });
        return false;
    } finally {
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
}

/* ============================================================
   ATTACH HANDLERS
   ============================================================ */

// "Choose file" button → triggers hidden input
document.querySelectorAll('.btn-choose-file').forEach(btn => {
    btn.addEventListener('click', function () {
        const docType = this.getAttribute('data-doc-type');
        const input = document.getElementById(`file_${docType}`);
        if (input) input.click();
    });
});

// File picked → validate → auto-upload
document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;

        const docType = this.id.replace('file_', '');
        const nameEl  = document.getElementById(`filename_${docType}`);

        // Validate first
        const check = validateFile(file);
        if (!check.valid) {
            await Swal.fire({
                icon: 'error', title: 'Invalid file',
                html: check.error, confirmButtonColor: '#1e293b'
            });
            this.value = '';
            if (nameEl) { nameEl.textContent = ''; nameEl.className = 'selected-file-name'; }
            return;
        }

        // Show chosen file name
        const ext = file.name.split('.').pop().toUpperCase();
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        if (nameEl) {
            nameEl.textContent = `${file.name} · ${sizeMB} MB`;
            nameEl.className = 'selected-file-name ok';
        }

        // Auto-upload immediately
        await uploadDocument(docType);
    });
});

/* ============================================================
   BACK
   ============================================================ */
document.getElementById('backBtn').addEventListener('click', function () {
    window.location.href = 'additional_info.php';
});

/* ============================================================
   FORM SUBMIT — verify all docs uploaded
   ============================================================ */
document.getElementById('documentsForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const missing = requiredDocs.filter(doc => !uploaded[doc]);
    if (missing.length > 0) {
        const missingNames = missing.map(d => docNames[d]).join(', ');
        await Swal.fire({
            icon: 'warning',
            title: 'Missing required documents',
            html: `Please upload: <strong>${missingNames}</strong>`,
            confirmButtonColor: '#1e293b'
        });
        return;
    }

    const confirm = await Swal.fire({
        icon: 'question',
        title: 'All documents uploaded',
        text: 'All required documents are uploaded. Proceed to review?',
        showCancelButton: true,
        confirmButtonColor: '#1e293b',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, continue'
    });

    if (confirm.isConfirmed) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        document.body.appendChild(form);
        form.submit();
    }
});
</script>

</body>
</html>