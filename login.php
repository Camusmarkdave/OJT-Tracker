<?php
session_start();
require_once 'db.php'; // Database connection and shared helpers

$error_message = '';
$notice = '';

if (($_GET['reason'] ?? '') === 'deactivated') {
    $notice = 'Your session has ended because your account is no longer active. Please contact a Super Admin.';
} elseif (isset($_GET['loggedout'])) {
    $notice = 'You have been signed out successfully.';
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        // Prepared statement to prevent SQL injection
        $stmt = $pdo->prepare("SELECT id, username, password_hash, `role`, is_active FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            audit_log($pdo, 'login_failed', 'Sign-in attempt using an unrecognized username.', ['id' => null, 'username' => mb_substr($username, 0, 50), 'role' => null]);
            $error_message = "The username or password you entered is incorrect.";

        } elseif (!password_verify($password, $user['password_hash'])) {
            audit_log($pdo, 'login_failed', 'Sign-in attempt with an incorrect password.', $user);
            $error_message = "The username or password you entered is incorrect.";

        } elseif (!(int)$user['is_active']) {
            audit_log($pdo, 'login_failed', 'Sign-in blocked: the account is deactivated.', $user);
            $error_message = "This account has been deactivated. Please contact a Super Admin.";

        } else {
            session_regenerate_id(true);
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];

            $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
            audit_log($pdo, 'login', 'Signed in successfully.', $user);

            header("Location: dashboard.php");
            exit;
        }
    } else {
        $error_message = "Please enter both your username and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Administrator Sign In</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { figmaBg: '#F5F6F8', figmaBlue: '#15458A', figmaYellow: '#FDDB31', figmaDark: '#1A1A2E' }
                }
            }
        }
    </script>
</head>
<body class="flex min-h-screen w-full items-center justify-center bg-gray-900 p-4 font-sans">
  <div class="flex w-full max-w-[1257px] min-h-[687px] flex-col overflow-hidden bg-white shadow-2xl md:flex-row">

    <!-- Left Side: Branding -->
    <div class="relative flex w-full flex-col bg-figmaBlue p-10 text-white md:w-1/2 md:p-14 overflow-hidden">
      <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-white opacity-5"></div>
      <div class="absolute -bottom-20 -right-20 h-80 w-80 rounded-full bg-teal-500 opacity-20"></div>

      <div class="z-10 mb-16 flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded bg-figmaYellow text-figmaBlue">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3zm6.82 6L12 12.72 5.18 9 12 5.28 18.82 9zM17 15.99l-5 2.73-5-2.73v-3.72L12 15l5-2.73v3.72z"/>
          </svg>
        </div>
        <span class="text-sm font-bold uppercase tracking-widest text-white"><?php echo e(APP_NAME); ?></span>
      </div>

      <div class="z-10 flex-grow">
        <div class="mb-6 h-1 w-12 bg-figmaYellow"></div>
        <h1 class="mb-4 text-5xl font-extrabold uppercase leading-none tracking-tight">
          Manage<br>Interns.<br><span class="text-figmaYellow">Efficiently.</span>
        </h1>
        <p class="mb-12 max-w-sm text-sm text-blue-100 leading-relaxed">
          A centralized OJT management system for maintaining intern records, schools, departments, and internship schedules.
        </p>
      </div>

      <div class="z-10 grid grid-cols-3 gap-6">
        <div><div class="text-lg font-extrabold text-figmaYellow">Records</div><div class="text-xs text-blue-200">Centralized intern profiles</div></div>
        <div><div class="text-lg font-extrabold text-figmaYellow">Validation</div><div class="text-xs text-blue-200">Reviewed before acceptance</div></div>
        <div><div class="text-lg font-extrabold text-figmaYellow">Reports</div><div class="text-xs text-blue-200">School and department analytics</div></div>
      </div>
    </div>

    <!-- Right Side: Form -->
    <div class="flex w-full flex-col justify-between bg-figmaBg p-10 md:w-1/2 md:p-14">
      <div>
        <div class="mb-2 text-xs font-bold tracking-widest text-green-600">ADMINISTRATOR PORTAL</div>
        <h2 class="mb-8 text-4xl font-extrabold uppercase leading-none text-figmaDark">Administrator<br>Sign In</h2>

        <?php if ($notice !== ''): ?>
            <div class="mb-4 rounded border-l-4 border-blue-500 bg-blue-50 p-4 text-sm text-blue-800">
                <?php echo e($notice); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="mb-4 rounded border-l-4 border-red-500 bg-red-100 p-4 text-sm text-red-700">
                <?php echo e($error_message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-6">
          <div>
            <label class="mb-2 block text-xs font-bold text-figmaDark" for="username">USERNAME</label>
            <input type="text" id="username" name="username" placeholder="Enter your username" required autocomplete="username" class="w-full rounded border border-gray-300 px-4 py-3 text-sm focus:border-figmaBlue focus:outline-none focus:ring-1 focus:ring-figmaBlue">
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold text-figmaDark" for="password">PASSWORD</label>
            <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password" class="w-full rounded border border-gray-300 px-4 py-3 text-sm focus:border-figmaBlue focus:outline-none focus:ring-1 focus:ring-figmaBlue">
          </div>
          <button type="submit" class="mt-2 flex w-full items-center justify-center gap-2 rounded bg-figmaBlue px-4 py-3 text-sm font-bold text-white transition hover:bg-blue-900 shadow-md">
            SIGN IN &rarr;
          </button>
        </form>

        <!-- New OJT interns: the only public entry point to the Registration page -->
        <div class="mt-8 flex items-center justify-between gap-4 rounded border border-gray-200 bg-white p-4">
          <div class="text-sm text-gray-600">
            <div class="font-bold text-figmaDark">New OJT intern?</div>
            Submit your information for review by the HR Department.
          </div>
          <a href="register.php" class="shrink-0 rounded border-2 border-figmaBlue px-4 py-2 text-xs font-bold text-figmaBlue transition hover:bg-figmaBlue hover:text-white">REGISTER</a>
        </div>

        <div class="mt-6 text-sm text-gray-500">
          Need assistance signing in? <a href="#" class="font-bold text-figmaBlue hover:underline">Contact IT Support</a>
        </div>
      </div>
      <div class="mt-16 text-xs text-gray-400">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?> OJT Management System. Confidential and for authorized use only.</div>
    </div>
  </div>
</body>
</html>