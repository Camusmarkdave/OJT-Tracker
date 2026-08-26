<?php
session_start();
require_once 'db.php'; // Include database connection

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        // Prepare statement to prevent SQL injection
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        // Verify password (assuming you used password_hash() when creating users)
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $username;
            
            header("Location: dashboard.php");
            exit;
        } else {
            $error_message = "Invalid username or password.";
        }
    } else {
        $error_message = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InternTrack - Login</title>
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
        <span class="text-sm font-bold tracking-widest text-white">INTERNTRACK</span>
      </div>

      <div class="z-10 flex-grow">
        <div class="mb-6 h-1 w-12 bg-figmaYellow"></div>
        <h1 class="mb-4 text-5xl font-extrabold uppercase leading-none tracking-tight">
          Manage<br>Interns.<br><span class="text-figmaYellow">Simply.</span>
        </h1>
        <p class="mb-12 max-w-sm text-sm text-blue-100 leading-relaxed">
          A centralized records system for tracking intern schools, departments, and timelines.
        </p>
      </div>

      <div class="z-10 flex gap-8">
        <div><div class="text-3xl font-extrabold text-figmaYellow">8</div><div class="text-xs text-blue-200">Interns Enrolled</div></div>
        <div><div class="text-3xl font-extrabold text-figmaYellow">6</div><div class="text-xs text-blue-200">Departments</div></div>
        <div><div class="text-3xl font-extrabold text-figmaYellow">2025</div><div class="text-xs text-blue-200">Batch Year</div></div>
      </div>
    </div>

    <!-- Right Side: Form -->
    <div class="flex w-full flex-col justify-between bg-figmaBg p-10 md:w-1/2 md:p-14">
      <div>
        <div class="mb-2 text-xs font-bold tracking-widest text-green-600">HR PORTAL</div>
        <h2 class="mb-8 text-4xl font-extrabold uppercase leading-none text-figmaDark">Welcome<br>Back</h2>

        <?php if (!empty($error_message)): ?>
            <div class="mb-4 rounded border-l-4 border-red-500 bg-red-100 p-4 text-sm text-red-700">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-6">
          <div>
            <label class="mb-2 block text-xs font-bold text-figmaDark">USERNAME</label>
            <input type="text" name="username" placeholder="e.g. hr.admin" required class="w-full rounded border border-gray-300 px-4 py-3 text-sm focus:border-figmaBlue focus:outline-none focus:ring-1 focus:ring-figmaBlue">
          </div>
          <div>
            <label class="mb-2 block text-xs font-bold text-figmaDark">PASSWORD</label>
            <input type="password" name="password" placeholder="••••••••" required class="w-full rounded border border-gray-300 px-4 py-3 text-sm focus:border-figmaBlue focus:outline-none focus:ring-1 focus:ring-figmaBlue">
          </div>
          <button type="submit" class="mt-2 flex w-full items-center justify-center gap-2 rounded bg-figmaBlue px-4 py-3 text-sm font-bold text-white transition hover:bg-blue-900 shadow-md">
            SIGN IN &rarr;
          </button>
        </form>

        <div class="mt-6 text-sm text-gray-500">
          Access issues? <a href="#" class="font-bold text-figmaBlue hover:underline">Contact IT Support</a>
        </div>
      </div>
      <div class="mt-16 text-xs text-gray-400">&copy; 2025 InternTrack System — Confidential</div>
    </div>
  </div>
</body>
</html>