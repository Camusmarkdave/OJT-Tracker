<?php
session_start();
require_once 'db.php';

// Security check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Fetch stats dynamically
$stats = ['Total' => 0, 'Active' => 0, 'Completed' => 0, 'Upcoming' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM interns GROUP BY status");
while ($row = $stmt->fetch()) {
    if (array_key_exists($row['status'], $stats)) {
        $stats[$row['status']] = $row['count'];
    }
    $stats['Total'] += $row['count'];
}

// Fetch all intern records
$internsStmt = $pdo->query("SELECT * FROM interns ORDER BY created_at DESC");
$interns = $internsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InternTrack - Dashboard</title>
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
    <aside class="w-64 bg-figmaBlue text-white flex flex-col justify-between shadow-xl z-20 relative">
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
                    <a href="#" class="flex items-center justify-between bg-blue-800/60 text-white px-3 py-2.5 rounded-md text-sm font-medium border border-blue-700/50">
                        <div class="flex items-center gap-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                            Intern Records
                        </div>
                        <svg class="w-4 h-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </a>
                    <a href="#" class="flex items-center gap-3 text-blue-200 hover:bg-blue-800/40 px-3 py-2.5 rounded-md text-sm font-medium transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        Reports
                    </a>
                    <a href="#" class="flex items-center gap-3 text-blue-200 hover:bg-blue-800/40 px-3 py-2.5 rounded-md text-sm font-medium transition-colors">
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
                    <span>Active</span><span class="bg-green-500/20 text-green-400 px-2 py-0.5 rounded font-bold"><?php echo $stats['Active']; ?></span>
                </div>
                <div class="flex justify-between text-xs text-blue-200">
                    <span>Upcoming</span><span class="bg-figmaYellow/20 text-figmaYellow px-2 py-0.5 rounded font-bold"><?php echo $stats['Upcoming']; ?></span>
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
                <div class="text-xs text-gray-400 font-medium mb-1">Dashboard <span class="mx-1">></span> <span class="text-gray-800">Intern Records</span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">INTERN RECORDS</h2>
            </div>
            <div class="flex items-center gap-4">
                <button class="h-10 w-10 rounded border border-gray-200 flex items-center justify-center text-gray-500 hover:bg-gray-50">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                </button>
                <button class="bg-[#0fb871] text-white px-5 py-2.5 rounded font-bold text-sm flex items-center gap-2 hover:bg-green-600 transition shadow-sm">
                    <span>+</span> ADD INTERN
                </button>
            </div>
        </header>

        <!-- Scrollable Body -->
        <div class="p-8 overflow-y-auto flex-1">
            
            <!-- Stats Grid -->
            <div class="grid grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-5 rounded-lg shadow-sm border border-gray-100 border-l-4 border-l-figmaBlue flex items-center gap-4">
                    <div class="h-12 w-12 rounded bg-blue-50 text-figmaBlue flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-gray-800 leading-none"><?php echo $stats['Total']; ?></div>
                        <div class="text-[10px] font-bold text-gray-400 tracking-wider uppercase mt-1">Total Interns</div>
                    </div>
                </div>
                <div class="bg-white p-5 rounded-lg shadow-sm border border-gray-100 border-l-4 border-l-[#0fb871] flex items-center gap-4">
                    <div class="h-12 w-12 rounded bg-green-50 text-[#0fb871] flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"></path></svg>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-gray-800 leading-none"><?php echo $stats['Active']; ?></div>
                        <div class="text-[10px] font-bold text-gray-400 tracking-wider uppercase mt-1">Active</div>
                    </div>
                </div>
                <div class="bg-white p-5 rounded-lg shadow-sm border border-gray-100 border-l-4 border-l-red-400 flex items-center gap-4">
                    <div class="h-12 w-12 rounded bg-red-50 text-red-400 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-gray-800 leading-none"><?php echo $stats['Completed']; ?></div>
                        <div class="text-[10px] font-bold text-gray-400 tracking-wider uppercase mt-1">Completed</div>
                    </div>
                </div>
                <div class="bg-white p-5 rounded-lg shadow-sm border border-gray-100 border-l-4 border-l-figmaYellow flex items-center gap-4">
                    <div class="h-12 w-12 rounded bg-yellow-50 text-yellow-600 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-gray-800 leading-none"><?php echo $stats['Upcoming']; ?></div>
                        <div class="text-[10px] font-bold text-gray-400 tracking-wider uppercase mt-1">Upcoming</div>
                    </div>
                </div>
            </div>

            <!-- Toolbar / Filters -->
            <div class="bg-white p-4 rounded-t-lg border-b border-gray-100 flex items-center justify-between shadow-sm">
                <div class="flex gap-4 w-1/2">
                    <input type="text" placeholder="Search name, school, department..." class="bg-gray-50 border border-gray-200 text-sm rounded-md px-4 py-2 w-full focus:outline-none focus:ring-1 focus:ring-figmaBlue">
                    <input type="text" class="bg-gray-50 border border-gray-200 text-sm rounded-md px-4 py-2 w-32 focus:outline-none">
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex border border-gray-200 rounded-md overflow-hidden text-xs font-bold shadow-sm">
                        <button class="bg-figmaBlue text-white px-4 py-2">ALL</button>
                        <button class="bg-white text-gray-500 hover:bg-gray-50 px-4 py-2 border-l border-gray-200">ACTIVE</button>
                        <button class="bg-white text-gray-500 hover:bg-gray-50 px-4 py-2 border-l border-gray-200">COMPLETED</button>
                        <button class="bg-white text-gray-500 hover:bg-gray-50 px-4 py-2 border-l border-gray-200">UPCOMING</button>
                    </div>
                    <div class="text-xs text-gray-400 font-medium whitespace-nowrap"><?php echo $stats['Total']; ?> / <?php echo $stats['Total']; ?> records</div>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-b-lg shadow-sm border border-gray-100 border-t-0 overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-white border-b border-gray-100 text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                            <th class="p-5">Name</th>
                            <th class="p-5">School</th>
                            <th class="p-5">Department</th>
                            <th class="p-5">Start</th>
                            <th class="p-5">End</th>
                            <th class="p-5">Graduation</th>
                            <th class="p-5">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (count($interns) > 0): ?>
                            <?php foreach ($interns as $intern): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-5 flex items-center gap-4">
                                        <div class="h-10 w-10 rounded-md bg-figmaBlue text-white flex items-center justify-center font-bold text-sm">
                                            <?php echo strtoupper(substr($intern['first_name'], 0, 1) . substr($intern['last_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($intern['course'] ?? 'N/A'); ?></div>
                                        </div>
                                    </td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo htmlspecialchars($intern['school']); ?></td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo htmlspecialchars($intern['department']); ?></td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo empty($intern['start_date']) ? '-' : date('M j, Y', strtotime($intern['start_date'])); ?></td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo empty($intern['end_date']) ? '-' : date('M j, Y', strtotime($intern['end_date'])); ?></td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo empty($intern['graduation_date']) ? '-' : date('M j, Y', strtotime($intern['graduation_date'])); ?></td>
                                    <td class="p-5">
                                        <span class="bg-figmaBlue text-white text-[10px] font-bold px-3 py-1 rounded-full tracking-wider uppercase">
                                            <?php echo htmlspecialchars($intern['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="p-10 text-center text-sm text-gray-400">
                                    No interns found. Click "+ ADD INTERN" to get started.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <div class="p-5 border-t border-gray-100 flex items-center justify-between">
                    <div class="text-xs text-gray-400">Showing <span class="font-bold text-gray-700"><?php echo $stats['Total']; ?></span> interns</div>
                    <div class="flex gap-2">
                        <button class="border border-gray-200 text-gray-500 rounded px-3 py-1 text-xs font-bold hover:bg-gray-50">&larr; Prev</button>
                        <button class="bg-figmaBlue text-white rounded px-3 py-1 text-xs font-bold">1</button>
                        <button class="border border-gray-200 text-gray-500 rounded px-3 py-1 text-xs font-bold hover:bg-gray-50">Next &rarr;</button>
                    </div>
                </div>
            </div>
            
        </div>
    </main>
</body>
</html>