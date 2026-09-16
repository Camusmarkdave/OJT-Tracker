<?php
session_start();
require_once 'db.php';

// Security check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Handle sorting order
$orderParam = $_GET['order'] ?? 'count_desc';

$deptOrderSql = "ORDER BY count DESC";
$schoolOrderSql = "ORDER BY count DESC";

if ($orderParam === 'count_asc') {
    $deptOrderSql = "ORDER BY count ASC";
    $schoolOrderSql = "ORDER BY count ASC";
} elseif ($orderParam === 'alpha_asc') {
    $deptOrderSql = "ORDER BY department ASC";
    $schoolOrderSql = "ORDER BY school ASC";
} elseif ($orderParam === 'alpha_desc') {
    $deptOrderSql = "ORDER BY department DESC";
    $schoolOrderSql = "ORDER BY school DESC";
}

// Fetch Interns by Department
$deptStmt = $pdo->query("SELECT department, COUNT(*) as count FROM interns GROUP BY department $deptOrderSql");
$deptStats = $deptStmt->fetchAll();

// Fetch Interns by School
$schoolStmt = $pdo->query("SELECT school, COUNT(*) as count FROM interns GROUP BY school $schoolOrderSql");
$schoolStats = $schoolStmt->fetchAll();

// Fetch Active/Upcoming for Sidebar stats
$stats = ['Active' => 0, 'Upcoming' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM interns WHERE status IN ('Active', 'Upcoming') GROUP BY status");
while ($row = $stmt->fetch()) { 
    $stats[$row['status']] = $row['count'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InternTrack - Reports</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { figmaBg: '#F5F6F8', figmaBlue: '#15458A', figmaYellow: '#FDDB31' }
                }
            }
        }
    </script>
</head>
<body class="flex h-screen bg-figmaBg font-sans overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-figmaBlue text-white flex flex-col justify-between shadow-xl z-20 relative shrink-0">
        <div>
            <!-- Sidebar Header -->
            <div class="p-6 flex items-center gap-3 border-b border-blue-800/50">
                <div class="flex h-10 w-10 items-center justify-center rounded bg-figmaYellow text-figmaBlue shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3z"/></svg>
                </div>
                <div>
                    <h1 class="text-sm font-bold tracking-widest leading-tight">INTERNTRACK</h1>
                    <span class="text-[10px] text-blue-200">Records System</span>
                </div>
            </div>

            <!-- Navigation -->
            <div class="p-4">
                <div class="text-[10px] font-bold tracking-widest text-blue-300 mb-3 px-3">NAVIGATION</div>
                <nav class="space-y-1">
                    <a href="dashboard.php" class="flex items-center gap-3 text-blue-200 hover:bg-blue-800/40 px-3 py-2.5 rounded-md text-sm font-medium transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        Intern Records
                    </a>
                    <a href="reports.php" class="flex items-center justify-between bg-blue-800/60 text-white px-3 py-2.5 rounded-md text-sm font-medium border border-blue-700/50">
                        <div class="flex items-center gap-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Reports
                        </div>
                        <svg class="w-4 h-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </a>
                    <a href="settings.php" class="flex items-center gap-3 text-blue-200 hover:bg-blue-800/40 px-3 py-2.5 rounded-md text-sm font-medium transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        Settings
                    </a>
                </nav>
            </div>
        </div>

        <!-- Sidebar Footer -->
        <div>
            <div class="px-6 py-4 space-y-3 border-t border-blue-800/50">
                <div class="flex justify-between text-xs text-blue-200">
                    <span>Active</span><span class="bg-green-500/20 text-green-400 px-2 py-0.5 rounded font-bold"><?php echo $stats['Active'] ?? 0; ?></span>
                </div>
                <div class="flex justify-between text-xs text-blue-200">
                    <span>Upcoming</span><span class="bg-figmaYellow/20 text-figmaYellow px-2 py-0.5 rounded font-bold"><?php echo $stats['Upcoming'] ?? 0; ?></span>
                </div>
            </div>
            
            <div class="p-4 border-t border-blue-800/50 flex items-center justify-between bg-blue-900/30">
                <div class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded bg-figmaYellow flex items-center justify-center text-figmaBlue font-bold text-sm">AD</div>
                    <div class="leading-tight">
                        <div class="text-sm font-bold text-white">Admin User</div>
                        <div class="text-[10px] text-blue-200">HR Department</div>
                    </div>
                </div>
                <a href="logout.php" class="text-blue-300 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-screen overflow-hidden">
        
        <!-- Header -->
        <header class="h-20 bg-white px-8 flex justify-between items-center border-b-2 border-blue-400 shadow-sm shrink-0">
            <div>
                <div class="text-xs text-gray-400 font-medium mb-1">Reports <span class="mx-1">></span> <span class="text-gray-800">Summary</span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">ANALYTICS & REPORTS</h2>
            </div>
            <div>
                <form method="GET" class="flex items-center gap-3 bg-gray-50 px-4 py-2 rounded-lg border border-gray-200">
                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wider">Sort By</label>
                    <select name="order" onchange="this.form.submit()" class="bg-transparent border-none text-sm font-bold text-gray-800 focus:outline-none cursor-pointer">
                        <option value="count_desc" <?php echo $orderParam === 'count_desc' ? 'selected' : ''; ?>>Descending Order</option>
                        <option value="count_asc" <?php echo $orderParam === 'count_asc' ? 'selected' : ''; ?>>Ascending Order</option>
                        <option value="alpha_asc" <?php echo $orderParam === 'alpha_asc' ? 'selected' : ''; ?>>Alphabetical (A-Z)</option>
                        <option value="alpha_desc" <?php echo $orderParam === 'alpha_desc' ? 'selected' : ''; ?>>Alphabetical (Z-A)</option>
                    </select>
                </form>
            </div>
        </header>

        <!-- Scrollable Body -->
        <div class="p-8 overflow-y-auto flex-1 flex flex-col gap-8">
            
            <!-- Tables Section -->
            <div class="grid grid-cols-2 gap-8">
                <!-- Department Stats -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-100 flex flex-col h-full">
                    <div class="p-5 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-800">Interns by Department</h3>
                    </div>
                    <div class="p-0 flex-1 overflow-auto max-h-[500px]">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th class="p-4">Department</th>
                                    <th class="p-4 text-right">Count</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($deptStats) > 0): ?>
                                    <?php foreach ($deptStats as $dept): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-4 text-sm font-semibold text-gray-700"><?php echo htmlspecialchars($dept['department'] ?: 'N/A'); ?></td>
                                            <td class="p-4 text-sm font-bold text-figmaBlue text-right"><?php echo $dept['count']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" class="p-8 text-center text-sm text-gray-400">No data available</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- School Stats -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-100 flex flex-col h-full">
                    <div class="p-5 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-800">Interns by School</h3>
                    </div>
                    <div class="p-0 flex-1 overflow-auto max-h-[500px]">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th class="p-4">School</th>
                                    <th class="p-4 text-right">Count</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($schoolStats) > 0): ?>
                                    <?php foreach ($schoolStats as $school): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-4 text-sm font-semibold text-gray-700"><?php echo htmlspecialchars($school['school'] ?: 'N/A'); ?></td>
                                            <td class="p-4 text-sm font-bold text-figmaBlue text-right"><?php echo $school['count']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" class="p-8 text-center text-sm text-gray-400">No data available</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </main>
</body>
</html>
