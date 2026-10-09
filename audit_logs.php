<?php
session_start();
require_once 'db.php';
require_login(); // Security check
require_permission('audit.view'); // Super Admin only

/* ---------- Search (the only filter) ---------- */
$q = trim($_GET['q'] ?? '');

$where = '';
$params = [];
if ($q !== '') {
    // Matches the user name, the details, and the activity (for example "approved" or "sign-in")
    $likes = ['username LIKE ?', 'details LIKE ?', 'action LIKE ?'];
    $params = ["%$q%", "%$q%", "%$q%"];
    $keys = array_keys(array_filter(AUDIT_ACTIONS, fn($a) => stripos($a[1], $q) !== false));
    if ($keys) {
        $likes[] = 'action IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
        array_push($params, ...$keys);
    }
    $where = 'WHERE ' . implode(' OR ', $likes);
}

/* ---------- Pagination ---------- */
$perPage = 25;
$cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT * FROM audit_logs $where ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset");
$st->execute($params);
$logs = $st->fetchAll();

$pageLink = fn($p) => 'audit_logs.php?' . http_build_query(array_filter(['q' => $q, 'page' => $p > 1 ? $p : ''], fn($v) => $v !== ''));
$accent = ['green' => 'border-l-green-500', 'red' => 'border-l-red-500', 'blue' => 'border-l-blue-500', 'yellow' => 'border-l-yellow-400', 'gray' => 'border-l-gray-300'];

page_start($pdo, 'Audit Logs', 'audit');
?>

        <!-- Header -->
        <header class="h-20 bg-white px-8 flex justify-between items-center border-b-2 border-blue-400 shadow-sm shrink-0">
            <div>
                <div class="text-xs text-gray-400 font-medium mb-1">Administration <span class="mx-1">></span> <span class="text-gray-800">Audit Logs</span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">AUDIT LOGS</h2>
            </div>
            <span class="rounded-full bg-blue-50 px-4 py-1.5 text-xs font-bold text-figmaBlue"><?php echo number_format($total); ?> <?php echo $total === 1 ? 'entry' : 'entries'; ?><?php echo $q !== '' ? ' found' : ' recorded'; ?></span>
        </header>

        <div class="p-8 overflow-y-auto flex-1">
            <div class="mx-auto max-w-6xl">

                <?php echo flash_html(); ?>

                <p class="mb-5 text-sm text-gray-500">A permanent record of sign-ins, record changes, validation decisions, and settings changes. Visible to Super Admins only. Entries cannot be edited or deleted.</p>

                <!-- Search -->
                <form method="GET" action="audit_logs.php" class="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-gray-100 bg-white p-4 shadow-sm">
                    <div class="relative flex-1 min-w-[260px]">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <input type="text" name="q" value="<?php echo e($q); ?>" aria-label="Search the audit log" placeholder="Search by user, activity, or details (for example: approved, hr.admin, Holy Angel University)"
                               class="w-full rounded-md border border-gray-200 bg-gray-50 py-2.5 pl-11 pr-4 text-sm focus:border-figmaBlue focus:bg-white focus:outline-none focus:ring-1 focus:ring-figmaBlue">
                    </div>
                    <button type="submit" class="rounded-md bg-figmaBlue px-6 py-2.5 text-xs font-bold text-white transition hover:bg-blue-900">SEARCH</button>
                    <?php if ($q !== ''): ?>
                    <!-- Visible whenever a search is applied; returns to the complete log -->
                    <a href="audit_logs.php" title="Remove the search and show every log entry"
                       class="inline-flex items-center gap-2 whitespace-nowrap rounded-md border border-red-300 bg-red-50 px-4 py-2.5 text-xs font-bold text-red-700 shadow-sm transition hover:bg-red-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-1">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        CLEAR FILTERS
                    </a>
                    <?php endif; ?>
                </form>

                <!-- Log -->
                <div class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm">
                    <?php if (!$logs): ?>
                        <div class="p-16 text-center">
                            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                            </div>
                            <div class="text-sm font-bold text-gray-800"><?php echo $q !== '' ? 'No Matching Entries' : 'No Activity Recorded Yet'; ?></div>
                            <div class="mt-1 text-sm text-gray-400"><?php echo $q !== '' ? 'No log entries match your search. Select Clear Filters to view the complete log.' : 'Activity will appear here as the system is used.'; ?></div>
                        </div>
                    <?php else: ?>
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="border-b border-gray-100 bg-white text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                <th class="px-5 py-3 w-28">Time</th>
                                <th class="px-3 py-3">Activity</th>
                                <th class="px-3 py-3">User</th>
                                <th class="px-3 py-3">Details</th>
                                <th class="px-5 py-3 text-right">IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $lastDay = '';
                            foreach ($logs as $log):
                                $ts  = strtotime($log['created_at']);
                                $day = date('Y-m-d', $ts);
                                $def = AUDIT_ACTIONS[$log['action']] ?? ['Other', ucwords(str_replace('_', ' ', $log['action'])), 'gray'];
                                if ($day !== $lastDay): $lastDay = $day; ?>
                            <tr>
                                <td colspan="5" class="border-y border-gray-100 bg-gray-50 px-5 py-2 text-[11px] font-bold uppercase tracking-wider text-gray-500"><?php echo e(date('l, F j, Y', $ts)); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="border-b border-gray-50 align-top transition hover:bg-blue-50/40">
                                <td class="border-l-4 <?php echo $accent[$def[2]]; ?> px-5 py-4 text-xs font-semibold whitespace-nowrap text-gray-700"><?php echo e(date('g:i:s A', $ts)); ?></td>
                                <td class="px-3 py-4 whitespace-nowrap">
                                    <span class="<?php echo BADGE_CLS[$def[2]]; ?> rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider"><?php echo e($def[1]); ?></span>
                                </td>
                                <td class="px-3 py-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-figmaBlue/10 text-[11px] font-bold text-figmaBlue"><?php echo e(strtoupper(mb_substr($log['username'], 0, 2))); ?></div>
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-gray-800"><?php echo e($log['username']); ?></div>
                                            <?php if ($log['role']): ?><div class="mt-0.5"><?php echo role_badge($log['role']); ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-md break-words px-3 py-4 text-sm leading-relaxed text-gray-600"><?php echo e($log['details'] ?: '-'); ?></td>
                                <td class="px-5 py-4 text-right font-mono text-xs whitespace-nowrap text-gray-400"><?php echo e($log['ip_address'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <div class="flex items-center justify-between border-t border-gray-100 bg-white px-5 py-4">
                        <div class="text-xs text-gray-400">Showing <span class="font-bold text-gray-700"><?php echo number_format($offset + 1); ?>&ndash;<?php echo number_format($offset + count($logs)); ?></span> of <span class="font-bold text-gray-700"><?php echo number_format($total); ?></span></div>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo e($pageLink($page - 1)); ?>" class="rounded border border-gray-200 px-3 py-1 text-xs font-bold text-gray-500 hover:bg-gray-50">&larr; Previous</a>
                            <?php else: ?>
                                <span class="rounded border border-gray-100 px-3 py-1 text-xs font-bold text-gray-300">&larr; Previous</span>
                            <?php endif; ?>
                            <span class="rounded bg-figmaBlue px-3 py-1 text-xs font-bold text-white">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                            <?php if ($page < $pages): ?>
                                <a href="<?php echo e($pageLink($page + 1)); ?>" class="rounded border border-gray-200 px-3 py-1 text-xs font-bold text-gray-500 hover:bg-gray-50">Next &rarr;</a>
                            <?php else: ?>
                                <span class="rounded border border-gray-100 px-3 py-1 text-xs font-bold text-gray-300">Next &rarr;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>

</body>
</html>