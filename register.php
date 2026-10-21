<?php
// Public page (no sign-in required). Reached only through the "Register" link on login.php.
// Submissions are stored as 'Pending' and stay out of the dashboard, reports and filters until an admin approves them.
session_start();
require_once 'db.php';

$open      = setting('registration_open') === '1';
$submitted = isset($_GET['submitted']);
$errors    = [];
$old       = [];
$resumeHint = ''; // non-empty when the user must reselect the file after a validation error
$captchaError = false;
$regMode   = ($_POST['reg_mode'] ?? 'resume') === 'manual' ? 'manual' : 'resume';

// ---------------------------------------------------------------------------
// AJAX endpoint: automatically parses and extracts details from a selected resume
// ---------------------------------------------------------------------------
if ($open && ($_GET['action'] ?? '') === 'parse_resume' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    csrf_verify();

    if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Please select a valid PDF file.';
        if (isset($_FILES['resume']['error']) && ($_FILES['resume']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['resume']['error'] === UPLOAD_ERR_FORM_SIZE)) {
            $msg = 'The resume file exceeds the maximum allowed size of ' . (RESUME_MAX_BYTES / 1024 / 1024) . ' MB.';
        }
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    $f = $_FILES['resume'];
    if ($f['size'] > RESUME_MAX_BYTES) {
        echo json_encode(['success' => false, 'message' => 'The resume file exceeds the maximum allowed size of ' . (RESUME_MAX_BYTES / 1024 / 1024) . ' MB.']);
        exit;
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        echo json_encode(['success' => false, 'message' => 'Only PDF files are accepted for the resume.']);
        exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($f['tmp_name']) !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'The uploaded file does not appear to be a valid PDF document.']);
        exit;
    }
    $handle = fopen($f['tmp_name'], 'rb');
    $magic  = fread($handle, 4);
    fclose($handle);
    if ($magic !== '%PDF') {
        echo json_encode(['success' => false, 'message' => 'The uploaded file does not appear to be a valid PDF document.']);
        exit;
    }

    $text = extract_pdf_text($f['tmp_name']);
    if ($text === null || trim($text) === '') {
        echo json_encode([
            'success'  => false,
            'readable' => false,
            'message'  => 'No readable text was detected in the PDF (it may be a scanned document). Please fill in your details below.'
        ]);
        exit;
    }

    $parsed = parse_resume_data($text, $pdo);
    $foundCount = count(array_filter($parsed));
    echo json_encode([
        'success'  => true,
        'readable' => true,
        'data'     => $parsed,
        'message'  => $foundCount > 0
            ? 'Information extracted from your resume and pre-filled below. Please review and verify the details.'
            : 'Resume uploaded. Specific details could not be detected automatically; please fill in the fields below.'
    ]);
    exit;
}

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // Detect post_max_size overflow: PHP silently empties $_POST when the body
    // exceeds post_max_size, which would make csrf_verify() show a misleading
    // "session expired" error.  Detect this case first and show a clear message.
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'The uploaded file exceeds the maximum allowed request size. Please reduce the file size and try again.';
    } else {
        csrf_verify();

        if (!empty($_POST['website'])) { // honeypot: genuine users never complete this hidden field
            redirect('register.php?submitted=1');
        }

        if (!captcha_verify('register', (string)($_POST['captcha'] ?? ''))) {
            $captchaError = true;
            $errors[] = 'The security code did not match. Please type the new code shown in the Security Check box.';
        }

        $old = clean_intern_input($_POST);
        // Default values for HR-only fields on public registration
        $old['department'] = '';
        $old['batch_year'] = (int)date('Y');
        $old['status']     = 'Active';
        $old['start_date'] = null;
        $old['end_date']   = null;

        // --- Resume upload validation ---
        $resumeData = null; // will be populated on a clean upload
        $hasUpload  = isset($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($regMode === 'resume') {
            if (!$hasUpload) {
                $errors[] = 'Please select a PDF resume file to upload.';
            } else {
                $f = $_FILES['resume'];

                if ($f['error'] !== UPLOAD_ERR_OK) {
                    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                        $errors[] = 'The resume file exceeds the maximum allowed size of ' . (RESUME_MAX_BYTES / 1024 / 1024) . ' MB.';
                    } else {
                        $errors[] = 'The resume file could not be uploaded. Please try again.';
                    }
                } elseif ($f['size'] > RESUME_MAX_BYTES) {
                    $errors[] = 'The resume file exceeds the maximum allowed size of ' . (RESUME_MAX_BYTES / 1024 / 1024) . ' MB.';
                } else {
                    // Extension check
                    $origName = $f['name'];
                    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    if ($ext !== 'pdf') {
                        $errors[] = 'Only PDF files are accepted for the resume. Please select a file with a .pdf extension.';
                    } else {
                        // Real MIME type via finfo
                        $finfo    = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->file($f['tmp_name']);
                        if ($mimeType !== 'application/pdf') {
                            $errors[] = 'The uploaded file does not appear to be a valid PDF. Please upload a genuine PDF document.';
                        } else {
                            // Magic-byte check: a PDF must start with "%PDF"
                            $handle = fopen($f['tmp_name'], 'rb');
                            $magic  = fread($handle, 4);
                            fclose($handle);
                            if ($magic !== '%PDF') {
                                $errors[] = 'The uploaded file does not appear to be a valid PDF. Please upload a genuine PDF document.';
                            } else {
                                // Sanitise the original name for display only (never used for paths)
                                $safeName = mb_substr(preg_replace('/[^\w.\- ]/', '_', basename($origName)), 0, 255);
                                $resumeData = ['tmpName' => $f['tmp_name'], 'name' => $safeName];
                            }
                        }
                    }
                }

                // The file input cannot be re-filled by the browser after a round-trip;
                // tell the intern to reselect it if there were other errors too.
                if ($errors) {
                    $resumeHint = 'The resume file you selected was not retained. Please reselect your resume file before submitting again.';
                }
            }
        }

        // Auto-extract from resume text on submit if any student-facing field was left empty
        if ($resumeData !== null && ($old['first_name'] === '' || $old['last_name'] === '' || $old['school'] === '')) {
            $extractedText = extract_pdf_text($resumeData['tmpName']);
            if ($extractedText !== null && trim($extractedText) !== '') {
                $autoParsed = parse_resume_data($extractedText, $pdo);
                if ($old['first_name'] === '' && !empty($autoParsed['first_name'])) { $old['first_name'] = $autoParsed['first_name']; }
                if ($old['last_name'] === '' && !empty($autoParsed['last_name'])) { $old['last_name'] = $autoParsed['last_name']; }
                if ($old['school'] === '' && !empty($autoParsed['school'])) { $old['school'] = $autoParsed['school']; }
                if ($old['course'] === '' && !empty($autoParsed['course'])) { $old['course'] = $autoParsed['course']; }
            }
        }

        $regErrors = validate_intern_registration($old);
        $errors    = array_merge($errors, $regErrors);
        if (!$errors && find_duplicate($pdo, $old)) {
            $errors[] = 'A registration with the same name and batch year has already been submitted. To correct it, please contact the HR Department.';
        }

        if (!$errors) {
            // Move the file to permanent storage before inserting the DB row.
            $resume = null;
            if ($resumeData !== null) {
                $dir = RESUME_UPLOAD_DIR;
                if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
                $storedName = bin2hex(random_bytes(16)) . '.pdf';
                $destPath   = $dir . '/' . $storedName;
                if (!move_uploaded_file($resumeData['tmpName'], $destPath)) {
                    // Storage failure is treated as a non-fatal issue: the record is saved without a resume.
                    $errors[] = 'The resume file could not be saved due to a server error. Please try submitting again, or contact the HR Department for assistance.';
                } else {
                    $resumeText = extract_pdf_text($destPath);
                    $resume = [
                        'file'   => $storedName,
                        'name'   => $resumeData['name'],
                        'text'   => $resumeText,
                        'status' => ($resumeText !== null && trim($resumeText) !== '') ? 'done' : 'empty',
                    ];
                }
            }

            if (!$errors) {
                insert_intern($pdo, $old, 'Pending', 'register', $resume);
                $detail = 'Registration submitted for ' . intern_summary($old) . ($resume ? ' (Resume attached: ' . $resume['name'] . ')' : ' (Manual registration)') . '.';
                audit_log($pdo, 'registration_submitted', $detail,
                    ['id' => null, 'username' => 'Public registration', 'role' => null]);
                if ($resume) {
                    audit_log($pdo, 'resume_uploaded', 'Resume uploaded during registration for ' . intern_summary($old) . '.',
                        ['id' => null, 'username' => 'Public registration', 'role' => null]);
                }
                redirect('register.php?submitted=1');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Intern Registration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { figmaBg: '#F5F6F8', figmaBlue: '#15458A', figmaYellow: '#FDDB31', figmaDark: '#1A1A2E' } } }
        }
    </script>
</head>
<body class="flex min-h-screen w-full items-center justify-center bg-gray-900 p-4 font-sans">
  <div class="flex w-full max-w-[1100px] flex-col overflow-hidden bg-white shadow-2xl md:flex-row">

    <!-- Left Side: Branding + registration process -->
    <div class="relative flex w-full flex-col bg-figmaBlue p-10 text-white md:w-2/5 overflow-hidden">
      <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-white opacity-5"></div>
      <div class="absolute -bottom-20 -right-20 h-80 w-80 rounded-full bg-teal-500 opacity-20"></div>

      <div class="z-10 mb-12 flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded bg-figmaYellow text-figmaBlue">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3z"/></svg>
        </div>
        <span class="text-sm font-bold uppercase tracking-widest"><?php echo e(APP_NAME); ?></span>
      </div>

      <div class="z-10 flex-grow">
        <div class="mb-6 h-1 w-12 bg-figmaYellow"></div>
        <h1 class="mb-4 text-4xl font-extrabold uppercase leading-none tracking-tight">Intern<br><span class="text-figmaYellow">Registration</span></h1>
        <p class="mb-10 max-w-xs text-sm leading-relaxed text-blue-100">Submit your information to be included in the official OJT intern records.</p>

        <ol class="space-y-5 text-sm">
          <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-figmaYellow text-xs font-black text-figmaBlue">1</span><span class="text-blue-100"><b class="text-white">Submit your information.</b><br>Upload your resume to automatically extract your details, or fill them in manually.</span></li>
          <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-figmaYellow text-xs font-black text-figmaBlue">2</span><span class="text-blue-100"><b class="text-white">HR Department review.</b><br>Your submission is verified and assigned to a department.</span></li>
          <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-figmaYellow text-xs font-black text-figmaBlue">3</span><span class="text-blue-100"><b class="text-white">Record accepted.</b><br>Once approved, your information becomes part of the official records.</span></li>
        </ol>
      </div>
    </div>

    <!-- Right Side -->
    <div class="flex w-full flex-col justify-between bg-figmaBg p-10 md:w-3/5">
      <div>
        <?php if ($submitted): ?>
            <div class="flex min-h-[420px] flex-col items-center justify-center text-center">
                <div class="mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 text-green-600">
                    <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                </div>
                <h2 class="mb-2 text-2xl font-extrabold uppercase text-figmaDark">Registration Submitted</h2>
                <p class="max-w-sm text-sm text-gray-600">Thank you. Your registration has been received and is pending review by the HR Department. No further action is required, and you do not need to submit it again.</p>
                <div class="mt-8 flex gap-3">
                    <a href="register.php" class="rounded border border-gray-300 bg-white px-4 py-2 text-xs font-bold text-gray-600 hover:bg-gray-50">REGISTER ANOTHER INTERN</a>
                    <a href="login.php" class="rounded bg-figmaBlue px-4 py-2 text-xs font-bold text-white hover:bg-blue-900">RETURN TO SIGN IN</a>
                </div>
            </div>

        <?php elseif (!$open): ?>
            <div class="flex min-h-[420px] flex-col items-center justify-center text-center">
                <div class="mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-yellow-100 text-yellow-600">
                    <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                </div>
                <h2 class="mb-2 text-2xl font-extrabold uppercase text-figmaDark">Registration Is Closed</h2>
                <p class="max-w-sm text-sm text-gray-600">The HR Department is not accepting new registrations at this time. Please contact the HR Department for assistance.</p>
                <a href="login.php" class="mt-8 rounded bg-figmaBlue px-4 py-2 text-xs font-bold text-white hover:bg-blue-900">RETURN TO SIGN IN</a>
            </div>

        <?php else: ?>
            <div class="mb-2 text-xs font-bold tracking-widest text-green-600">NEW INTERN</div>
            <h2 class="mb-6 text-3xl font-extrabold uppercase leading-none text-figmaDark">Registration Form</h2>

            <form method="POST" action="register.php" enctype="multipart/form-data" <?php echo form_attrs(); ?> class="space-y-4">
                <?php echo csrf_field(); ?>
                <div class="hidden" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                <!-- Registration mode selector -->
                <div class="rounded-lg border border-gray-200 bg-gray-50/70 p-4">
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-2">Registration Mode</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label id="mode_resume_card" class="flex cursor-pointer items-center gap-3 rounded-md border-2 bg-white p-3 shadow-sm transition hover:border-figmaBlue <?php echo $regMode === 'resume' ? 'border-figmaBlue ring-1 ring-figmaBlue' : 'border-gray-200'; ?>">
                            <input type="radio" name="reg_mode" value="resume" id="mode_resume" class="h-4 w-4 accent-figmaBlue" <?php echo $regMode === 'resume' ? 'checked' : ''; ?> onchange="toggleRegMode()">
                            <div>
                                <div class="text-sm font-bold text-gray-900">Upload my resume</div>
                                <div class="text-[11px] text-gray-500">Auto-fill details from your PDF</div>
                            </div>
                        </label>
                        <label id="mode_manual_card" class="flex cursor-pointer items-center gap-3 rounded-md border-2 bg-white p-3 shadow-sm transition hover:border-figmaBlue <?php echo $regMode === 'manual' ? 'border-figmaBlue ring-1 ring-figmaBlue' : 'border-gray-200'; ?>">
                            <input type="radio" name="reg_mode" value="manual" id="mode_manual" class="h-4 w-4 accent-figmaBlue" <?php echo $regMode === 'manual' ? 'checked' : ''; ?> onchange="toggleRegMode()">
                            <div>
                                <div class="text-sm font-bold text-gray-900">Fill in manually</div>
                                <div class="text-[11px] text-gray-500">Register directly without file</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Resume upload section (visible only in resume mode, placed before details so it auto-fills them) -->
                <div id="resumeSection" class="<?php echo $regMode === 'manual' ? 'hidden' : ''; ?> rounded-lg border border-blue-100 bg-blue-50/40 p-4">
                    <label class="<?php echo LABEL_CLS; ?>" for="reg_resume">SELECT YOUR RESUME * (PDF ONLY, MAX <?php echo RESUME_MAX_BYTES / 1024 / 1024; ?> MB)</label>
                    <input type="file" name="resume" id="reg_resume" accept=".pdf,application/pdf" <?php echo $regMode === 'resume' ? 'required' : ''; ?>
                        class="w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-figmaBlue file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white hover:file:bg-blue-900 focus:outline-none focus:ring-1 focus:ring-figmaBlue">
                    <?php if ($resumeHint): ?>
                    <p class="mt-1 text-xs text-yellow-700 font-medium"><?php echo e($resumeHint); ?></p>
                    <?php endif; ?>
                    <div id="resumeParseStatus" class="hidden mt-2 text-xs font-medium"></div>
                    <p class="mt-1 text-[11px] text-gray-500">When you select your PDF resume, your name, school, and course will be extracted automatically into the fields below.</p>
                </div>

                <!-- Student-facing fields (first name, last name, school required, course) -->
                <?php intern_fields($pdo, 'reg_', $old, ['hr_only' => false, 'status' => false, 'errors' => $errors]); ?>

                <label class="flex items-start gap-3 rounded border border-gray-200 bg-white p-3 text-xs text-gray-600">
                    <input type="checkbox" required class="mt-0.5 h-4 w-4 accent-[#15458A]">
                    <span>I certify that the information provided is accurate and complete, and I understand that it will be reviewed by the HR Department before it is accepted. Any resume I attach will be stored securely and reviewed by HR.</span>
                </label>

                <?php captcha_box('register', $captchaError); ?>

                <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                    <a href="login.php" class="text-xs font-bold text-gray-500 hover:text-figmaBlue">&larr; Return to sign in</a>
                    <button type="submit" class="rounded bg-figmaBlue px-6 py-3 text-sm font-bold text-white shadow-md transition hover:bg-blue-900">SUBMIT REGISTRATION</button>
                </div>
            </form>
        <?php endif; ?>
      </div>
      <div class="mt-10 text-xs text-gray-400">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?> OJT Management System. Confidential and for authorized use only.</div>
    </div>
  </div>
  <script src="app.js"></script>
  <script>
    function toggleRegMode() {
        const isResume = document.getElementById('mode_resume').checked;
        const resumeSection = document.getElementById('resumeSection');
        const resumeInput = document.getElementById('reg_resume');
        const resumeCard = document.getElementById('mode_resume_card');
        const manualCard = document.getElementById('mode_manual_card');

        if (!resumeSection || !resumeInput) return;

        if (isResume) {
            resumeSection.classList.remove('hidden');
            resumeInput.required = true;
            resumeInput.disabled = false;
            if (resumeCard) {
                resumeCard.classList.add('border-figmaBlue', 'ring-1', 'ring-figmaBlue');
                resumeCard.classList.remove('border-gray-200');
            }
            if (manualCard) {
                manualCard.classList.remove('border-figmaBlue', 'ring-1', 'ring-figmaBlue');
                manualCard.classList.add('border-gray-200');
            }
        } else {
            resumeSection.classList.add('hidden');
            resumeInput.required = false;
            resumeInput.disabled = true;
            resumeInput.value = '';
            const parseStatus = document.getElementById('resumeParseStatus');
            if (parseStatus) { parseStatus.classList.add('hidden'); parseStatus.textContent = ''; }
            if (manualCard) {
                manualCard.classList.add('border-figmaBlue', 'ring-1', 'ring-figmaBlue');
                manualCard.classList.remove('border-gray-200');
            }
            if (resumeCard) {
                resumeCard.classList.remove('border-figmaBlue', 'ring-1', 'ring-figmaBlue');
                resumeCard.classList.add('border-gray-200');
            }
        }
    }

    // Auto-parse resume on file selection
    document.addEventListener('DOMContentLoaded', () => {
        toggleRegMode();

        const resumeInput = document.getElementById('reg_resume');
        const parseStatus = document.getElementById('resumeParseStatus');
        if (!resumeInput) return;

        resumeInput.addEventListener('change', async function() {
            if (!this.files || !this.files[0]) return;
            const file = this.files[0];
            if (!file.name.toLowerCase().endsWith('.pdf')) return;

            if (parseStatus) {
                parseStatus.textContent = 'Extracting information from resume...';
                parseStatus.className = 'mt-2 text-xs font-medium text-figmaBlue flex items-center gap-1.5';
                parseStatus.classList.remove('hidden');
            }

            const formData = new FormData();
            formData.append('resume', file);
            const csrfEl = document.querySelector('input[name="csrf"]');
            if (csrfEl) formData.append('csrf', csrfEl.value);

            try {
                const res = await fetch('register.php?action=parse_resume', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();
                if (json.success && json.data) {
                    if (json.data.first_name) {
                        const fn = document.getElementById('reg_first_name');
                        if (fn) fn.value = json.data.first_name;
                    }
                    if (json.data.last_name) {
                        const ln = document.getElementById('reg_last_name');
                        if (ln) ln.value = json.data.last_name;
                    }
                    if (json.data.school) {
                        const sc = document.getElementById('reg_school');
                        if (sc) sc.value = json.data.school;
                    }
                    if (json.data.course) {
                        const cs = document.getElementById('reg_course');
                        if (cs) cs.value = json.data.course;
                    }
                    if (parseStatus) {
                        parseStatus.textContent = json.message || 'Details extracted from resume. Please review below.';
                        parseStatus.className = 'mt-2 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded p-2';
                    }
                } else if (parseStatus) {
                    parseStatus.textContent = json.message || 'Could not auto-extract details. Please enter your details below.';
                    parseStatus.className = 'mt-2 text-xs font-medium text-yellow-800 bg-yellow-50 border border-yellow-200 rounded p-2';
                }
            } catch (err) {
                if (parseStatus) {
                    parseStatus.textContent = 'Could not extract details automatically. Please enter your details below.';
                    parseStatus.className = 'mt-2 text-xs font-medium text-gray-500';
                }
            }
        });
    });
  </script>
</body>
</html>