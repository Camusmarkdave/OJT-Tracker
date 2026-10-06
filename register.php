<?php
// Public page (no login). Reached only through the "Register" link on login.php.
// Submissions are stored as 'Pending' and stay out of the dashboard, reports and filters until an admin approves them.
session_start();
require_once 'db.php';

$open      = setting('registration_open') === '1';
$submitted = isset($_GET['submitted']);
$errors    = [];
$old       = [];

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!empty($_POST['website'])) { // honeypot: real people never fill this hidden field
        redirect('register.php?submitted=1');
    }

    $old = clean_intern_input($_POST);
    $old['status'] = 'Active'; // the admin can change the status while validating

    // Basic throttle: 5 submissions per browser session per hour
    $_SESSION['reg_log'] = array_values(array_filter($_SESSION['reg_log'] ?? [], fn($t) => $t > time() - 3600));

    if (count($_SESSION['reg_log']) >= 5) {
        $errors[] = 'You have submitted several records recently. Please try again later or contact HR.';
    } else {
        $errors = validate_intern($old);
        $depts = get_departments($pdo);
        if ($depts && $old['department'] !== '' && !in_array($old['department'], $depts, true)) {
            $errors[] = 'Please choose a department from the list.';
        }
        if (!$errors && find_duplicate($pdo, $old)) {
            $errors[] = 'A record with this name and batch year has already been submitted. If you need to correct it, please contact HR.';
        }
        if (!$errors) {
            insert_intern($pdo, $old, 'Pending', 'register');
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
    <title><?php echo e(APP_NAME); ?> - Register</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { figmaBg: '#F5F6F8', figmaBlue: '#15458A', figmaYellow: '#FDDB31', figmaDark: '#1A1A2E' } } }
        }
    </script>
</head>
<body class="flex min-h-screen w-full items-center justify-center bg-gray-900 p-4 font-sans">
  <div class="flex w-full max-w-[1100px] flex-col overflow-hidden bg-white shadow-2xl md:flex-row">

    <!-- Left Side: Branding + what happens next -->
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
        <h1 class="mb-4 text-4xl font-extrabold uppercase leading-none tracking-tight">Register<br>as an <span class="text-figmaYellow">intern</span></h1>
        <p class="mb-10 max-w-xs text-sm leading-relaxed text-blue-100">Enter your own details so HR can add you to the OJT records.</p>

        <ol class="space-y-5 text-sm">
          <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-figmaYellow text-xs font-black text-figmaBlue">1</span><span class="text-blue-100"><b class="text-white">Fill in your details.</b><br>School, course, department and dates.</span></li>
          <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-figmaYellow text-xs font-black text-figmaBlue">2</span><span class="text-blue-100"><b class="text-white">HR checks your record.</b><br>They may correct or reject entries.</span></li>
          <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-figmaYellow text-xs font-black text-figmaBlue">3</span><span class="text-blue-100"><b class="text-white">You're on the roster.</b><br>Once approved, your record is official.</span></li>
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
                <h2 class="mb-2 text-2xl font-extrabold uppercase text-figmaDark">Record submitted</h2>
                <p class="max-w-sm text-sm text-gray-600">Thank you! HR will review your details. Your record becomes official once it has been approved. You don't need to submit it again.</p>
                <div class="mt-8 flex gap-3">
                    <a href="register.php" class="rounded border border-gray-300 bg-white px-4 py-2 text-xs font-bold text-gray-600 hover:bg-gray-50">REGISTER ANOTHER INTERN</a>
                    <a href="login.php" class="rounded bg-figmaBlue px-4 py-2 text-xs font-bold text-white hover:bg-blue-900">BACK TO LOGIN</a>
                </div>
            </div>

        <?php elseif (!$open): ?>
            <div class="flex min-h-[420px] flex-col items-center justify-center text-center">
                <div class="mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-yellow-100 text-yellow-600">
                    <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                </div>
                <h2 class="mb-2 text-2xl font-extrabold uppercase text-figmaDark">Registration is closed</h2>
                <p class="max-w-sm text-sm text-gray-600">HR isn't accepting new registrations right now. Please contact the HR department for help.</p>
                <a href="login.php" class="mt-8 rounded bg-figmaBlue px-4 py-2 text-xs font-bold text-white hover:bg-blue-900">BACK TO LOGIN</a>
            </div>

        <?php else: ?>
            <div class="mb-2 text-xs font-bold tracking-widest text-green-600">NEW INTERN</div>
            <h2 class="mb-6 text-3xl font-extrabold uppercase leading-none text-figmaDark">Your details</h2>

            <form method="POST" action="register.php" <?php echo form_attrs(); ?> class="space-y-4">
                <?php echo csrf_field(); ?>
                <div class="hidden" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                <?php intern_fields($pdo, 'reg_', $old, ['status' => false, 'errors' => $errors]); ?>

                <label class="flex items-start gap-3 rounded border border-gray-200 bg-white p-3 text-xs text-gray-600">
                    <input type="checkbox" required class="mt-0.5 h-4 w-4 accent-[#15458A]">
                    <span>I confirm that the information above is correct. I understand HR will review it before it is accepted.</span>
                </label>

                <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                    <a href="login.php" class="text-xs font-bold text-gray-500 hover:text-figmaBlue">&larr; Back to login</a>
                    <button type="submit" class="rounded bg-figmaBlue px-6 py-3 text-sm font-bold text-white shadow-md transition hover:bg-blue-900">SUBMIT FOR VALIDATION</button>
                </div>
            </form>
        <?php endif; ?>
      </div>
      <div class="mt-10 text-xs text-gray-400">&copy; 2025 <?php echo e(APP_NAME); ?> System — Confidential</div>
    </div>
  </div>
  <script src="app.js"></script>
</body>
</html>