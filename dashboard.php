<?php
session_start();

// Security check: Redirect to login if the session variable isn't set
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InternTrack - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden font-sans">
    
    <!-- Sidebar Placeholder -->
    <aside class="w-64 bg-[#15458A] text-white flex flex-col">
        <div class="p-6 text-lg font-bold tracking-widest border-b border-blue-800">
            INTERNTRACK
        </div>
        <nav class="flex-grow p-4 space-y-2">
            <a href="#" class="block p-3 rounded bg-blue-800 font-medium">Dashboard</a>
            <a href="#" class="block p-3 rounded hover:bg-blue-800 transition">Intern Records</a>
            <a href="#" class="block p-3 rounded hover:bg-blue-800 transition">Departments</a>
        </nav>
        <div class="p-4 border-t border-blue-800">
            <!-- Logout script ends the session and redirects to login -->
            <a href="logout.php" class="text-sm text-blue-200 hover:text-white transition">Log Out</a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 flex flex-col overflow-y-auto">
        <!-- Top Navbar -->
        <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800">Dashboard Overview</h1>
            <div class="text-sm font-medium text-gray-600">
                Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>
            </div>
        </header>

        <!-- Body Content -->
        <div class="p-8">
            <div class="grid grid-cols-3 gap-6 mb-8">
                <!-- Stat Cards -->
                <div class="bg-white p-6 rounded shadow-sm border border-gray-100">
                    <h3 class="text-gray-500 text-sm font-bold mb-2">TOTAL INTERNS</h3>
                    <p class="text-3xl font-extrabold text-[#15458A]">24</p>
                </div>
            </div>
            
            <!-- Replace this section with the actual Figma design once uploaded -->
            <div class="bg-white p-8 rounded shadow-sm border border-gray-100 text-center py-20 text-gray-500">
                Body page content will go here. Please upload the Figma design!
            </div>
        </div>
    </main>

</body>
</html>