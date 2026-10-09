<?php
// Public page (no sign-in required). Reached only through the "Register" link on login.php.
// Submissions are stored as 'Pending' and stay out of the dashboard, reports and filters until an admin approves them.
session_start();
require_once 'db.php';

$open      = setting('registration_open') === '1';
$submitted = isset($_GET['submitted']);
$errors    = [];
$old       = [];

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!empty($_POST['website'])) { // honeypot: genuine users never complete this hidden field
        redirect('register.php?submitted=1');
    }

    $old = clean_intern_input($_POST);
    $old['status'] = 'Active'; // an admin may change the status during validation

    // Basic throttle: 5 submissions per browser session per hour
    $_SESSION['reg_log'] = array_values(array_filter($_SESSION['reg_log'] ?? [], fn($t) => $t > time() - 3600));

    if (count($_SESSION['reg_log']) >= 5) {
        $errors[] = 'Several registrations were submitted from this browser recently. Please try again later or contact the HR Department.';
    } else {
        $errors = validate_intern($old);
        if (!$errors && find_duplicate($pdo, $old)) {
            $errors[] = 'A registration with the same name and batch year has already been submitted. To correct it, please contact the HR Department.';
        }
        if (!$errors) {
            insert_intern($pdo, $old, 'Pending', 'register');
            audit_log($pdo, 'registration_submitted', 'Registration submitted for ' . intern_summary($old) . '.',
                ['id' => null, 'username' => 'Public registration', 'role' => null]);
            $_SESSION['reg_log'][] = time();
            redirect('register.php?submitted=1');
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
          <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-figmaYellow text-xs font-black text-figmaBlue">1</span><span class="text-blue-100"><b class="text-white">Submit your information.</b><br>Provide your school, course, department, and internship dates.</span></li>
          <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-figmaYellow text-xs font-black text-figmaBlue">2</span><span class="text-blue-100"><b class="text-white">HR Department review.</b><br>Your submission is verified and corrected where necessary.</span></li>
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

            <form method="POST" action="register.php" <?php echo form_attrs(); ?> class="space-y-4">
                <?php echo csrf_field(); ?>
                <div class="hidden" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                <?php intern_fields($pdo, 'reg_', $old, ['status' => false, 'errors' => $errors]); ?>

                <label class="flex items-start gap-3 rounded border border-gray-200 bg-white p-3 text-xs text-gray-600">
                    <input type="checkbox" required class="mt-0.5 h-4 w-4 accent-[#15458A]">
                    <span>I certify that the information provided is accurate and complete, and I understand that it will be reviewed by the HR Department before it is accepted.</span>
                </label>

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
</body>
</html>