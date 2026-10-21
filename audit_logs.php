<?php
session_start();
require_once 'db.php';
require_login(); // Security check
require_permission('audit.view'); // Super Admin only

const PERIODS = [
    'all'   => ['All time',     ''],
    'today' => ['Today',        'l.created_at >= CURDATE()'],
    '7d'    => ['Last 7 days',  'l.created_at >= NOW() - INTERVAL 7 DAY'],
    '30d'   => ['Last 30 days', 'l.created_at >= NOW() - INTERVAL 30 DAY'],
];
const CATEGORY_ICONS = [
    'signin'     => 'M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1',
    'security'   => 'M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016zM12 9v2m0 4h.01',
    'records'    => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    'validation' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    'accounts'   => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
    'other'      => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
];
// Details that only repeat the headline, so they are not shown a second time
const QUIET_DETAILS = ['Signed in successfully.', 'Changed own password.'];
const PAGE_NAMES = [
    'dashboard.php' => 'the Intern Records page', 'reports.php' => 'the Reports page', 'validation.php' => 'the Validation page',
    'settings.php' => 'the Settings page', 'accounts.php' => 'the Admin Accounts page', 'audit_logs.php' => 'the Audit Logs page',
    'resume.php' => 'a resume',
];

/** Splits an entry's details into the sentence, a list of [field, before, after] changes, and a rejection reason. */
function log_details(array $log): array {
    static $names = null;
    $names ??= array_merge(...array_values(PERMISSION_GROUPS)) + PAGE_NAMES;
    $text = trim((string)$log['details']);
    $changes = [];
    $reason = null;

    if (in_array($log['action'], ['intern_edited', 'validation_edited', 'profile_updated', 'user_updated'], true)
        && ($pos = strpos($text, ' Changes: ')) !== false) {
        $list = preg_replace('/\.$/', '', substr($text, $pos + 10));
        $text = substr($text, 0, $pos);
        foreach (explode('; ', $list) as $item) {
            if (preg_match('/^(.+?): (.*) → (.*)$/u', $item, $m)) { $changes[] = [$m[1], $m[2], $m[3]]; }
            else { $text .= ' ' . $item . '.'; } // e.g. "No fields were changed"
        }
    } elseif ($log['action'] === 'validation_rejected' && preg_match('/^(.*?) Reason: (.*?)\.?$/us', $text, $m)) {
        [$text, $reason] = [$m[1], $m[2]];
    } elseif ($log['action'] === 'access_denied') {
        $text = strtr($text, $names); // "(audit.view) on audit_logs.php" becomes "(View system audit logs) on the Audit Logs page"
    }
    return [$text, $changes, $reason];
}

/* ---------- Filters: activity type, person, time period, and search ---------- */
$q    = trim($_GET['q'] ?? '');
$cat  = array_key_exists($_GET['cat'] ?? '', AUDIT_CATEGORIES) ? $_GET['cat'] : '';
$when = array_key_exists($_GET['when'] ?? '', PERIODS) ? $_GET['when'] : 'all';
$who  = (string)($_GET['who'] ?? '');

// People who can be picked: every account, accounts deleted since, and the public registration form
$people = [];
foreach ($pdo->query("SELECT id, username, display_name FROM users ORDER BY COALESCE(display_name, username)") as $u) {
    $people[$u['id']] = $u['display_name'] ?: $u['username'];
}
foreach ($pdo->query("SELECT l.user_id, MAX(l.username) AS username FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
                      WHERE l.user_id IS NOT NULL AND u.id IS NULL GROUP BY l.user_id") as $u) {
    $people[$u['user_id']] = $u['username'] . ' (deleted account)';
}
$people['public'] = 'Public registration form';
if (!isset($people[$who])) { $who = ''; }

$where = [];
$params = [];
if ($q !== '') {
    // Matches the person, the details, and the activity itself (for example "approved" or "failed")
    $like = "%$q%";
    $or = ['l.username LIKE ?', 'u.display_name LIKE ?', 'l.details LIKE ?'];
    array_push($params, $like, $like, $like);
    $keys = array_keys(array_filter(AUDIT_ACTIONS, fn($a) => stripos($a[1], $q) !== false || stripos($a[3], $q) !== false));
    if ($keys) {
        $or[] = 'l.action IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
        array_push($params, ...$keys);
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
}
if ($who === 'public') {
    $where[] = "l.user_id IS NULL AND l.username = 'Public registration'";
} elseif ($who !== '') {
    $where[] = 'l.user_id = ?';
    $params[] = (int)$who;
}
if (PERIODS[$when][1] !== '') { $where[] = PERIODS[$when][1]; }

$from = 'FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id';

// Entries per activity type (for the counts on the category buttons)
$st = $pdo->prepare("SELECT l.action, COUNT(*) $from " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY l.action');
$st->execute($params);
$catCounts = array_fill_keys(array_keys(AUDIT_CATEGORIES), 0);
$allCount = 0;
foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $action => $n) {
    $allCount += $n;
    if (isset(AUDIT_ACTIONS[$action])) { $catCounts[AUDIT_ACTIONS[$action][0]] += $n; }
}
if ($cat !== '') {
    $keys = array_keys(array_filter(AUDIT_ACTIONS, fn($a) => $a[0] === $cat));
    $where[] = 'l.action IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
    array_push($params, ...$keys);
}

/* ---------- Pagination ---------- */
$perPage = 25;
$total  = $cat !== '' ? $catCounts[$cat] : $allCount;
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT l.*, u.display_name $from " . ($where ? 'WHERE ' . implode(' AND ', $where) : '')
                  . " ORDER BY l.created_at DESC, l.id DESC LIMIT $perPage OFFSET $offset");
$st->execute($params);
$logs = $st->fetchAll();

// The database clock decides what "today" and "5 min ago" mean (PHP may use a different time zone)
$now = strtotime($pdo->query("SELECT NOW()")->fetchColumn());
$today = date('Y-m-d', $now);
$yesterday = date('Y-m-d', strtotime('-1 day', $now));
$days = [];
foreach ($logs as $log) { $days[date('Y-m-d', strtotime($log['created_at']))][] = $log; }

$filtered = $q !== '' || $cat !== '' || $who !== '' || $when !== 'all';
$state = ['q' => $q, 'cat' => $cat, 'who' => $who, 'when' => $when === 'all' ? '' : $when];
$link = function (array $change = []) use ($state): string {
    $qs = http_build_query(array_filter(array_merge($state, $change), fn($v) => $v !== '' && $v !== null));
    return 'audit_logs.php' . ($qs !== '' ? '?' . $qs : '');
};
$ago = function (int $ts) use ($now): string {
    $s = max(0, $now - $ts);
    if ($s < 60) { return 'Just now'; }
    if ($s < 3600) { return floor($s / 60) . ' min ago'; }
    $h = (int)floor($s / 3600);
    return $h . ($h === 1 ? ' hour' : ' hours') . ' ago';
};
$icon = fn(string $d, string $cls) => '<svg class="' . $cls . '" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $d . '"></path></svg>';
$selectCls = 'rounded-md border border-gray-200 bg-gray-50 py-2.5 pl-3 pr-8 text-sm text-gray-700 focus:border-figmaBlue focus:bg-white focus:outline-none focus:ring-1 focus:ring-figmaBlue';

page_start($pdo, 'Audit Logs', 'audit');
?>

        <!-- Header -->
        <header class="h-20 bg-white px-8 flex justify-between items-center border-b-2 border-blue-400 shadow-sm shrink-0">
            <div>
                <div class="text-xs text-gray-400 font-medium mb-1">Administration <span class="mx-1">></span> <span class="text-gray-800">Audit Logs</span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">AUDIT LOGS</h2>
            </div>
            <span class="rounded-full bg-blue-50 px-4 py-1.5 text-xs font-bold text-figmaBlue"><?php echo number_format($total); ?> <?php echo $total === 1 ? 'entry' : 'entries'; ?><?php echo $filtered ? ' found' : ' recorded'; ?></span>
        </header>

        <div class="p-8 overflow-y-auto flex-1">
            <div class="mx-auto max-w-5xl">

                <?php echo flash_html(); ?>

                <p class="mb-5 flex items-center gap-2 text-sm text-gray-500">
                    <?php echo $icon('M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z', 'h-4 w-4 shrink-0 text-gray-400'); ?>
                    Every sign-in and change made in <?php echo e(APP_NAME); ?>, newest first. Only Super Admins can see this page, and entries can't be edited or deleted.
                </p>

                <!-- Filters -->
                <div class="mb-6 rounded-lg border border-gray-100 bg-white shadow-sm">
                    <nav aria-label="Type of activity" class="flex flex-wrap gap-2 border-b border-gray-100 p-4">
                        <?php foreach (['' => 'All activity'] + AUDIT_CATEGORIES as $key => $label):
                            $on = $key === $cat;
                            $n = $key === '' ? $allCount : $catCounts[$key];
                            $alert = $key === 'security' && $n > 0; ?>
                        <a href="<?php echo e($link(['cat' => $key])); ?>" <?php echo $on ? 'aria-current="true"' : ''; ?>
                           class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-bold transition <?php echo $on ? 'border-figmaBlue bg-figmaBlue text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-figmaBlue hover:text-figmaBlue'; ?>">
                            <?php if ($key !== ''): echo $icon(CATEGORY_ICONS[$key], 'h-3.5 w-3.5'); endif; ?>
                            <?php echo e($label); ?>
                            <span class="rounded-full px-1.5 py-0.5 text-[10px] leading-none <?php echo $on ? 'bg-white/20 text-white' : ($alert ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-500'); ?>"><?php echo number_format($n); ?></span>
                        </a>
                        <?php endforeach; ?>
                    </nav>

                    <form method="GET" action="audit_logs.php" class="flex flex-wrap items-center gap-3 p-4">
                        <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?php echo e($cat); ?>"><?php endif; ?>
                        <div class="relative min-w-[240px] flex-1">
                            <?php echo $icon('M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z', 'pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400'); ?>
                            <input type="search" name="q" value="<?php echo e($q); ?>" aria-label="Search the audit log" placeholder="Search by person, intern, school, or activity"
                                   class="w-full rounded-md border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-3 text-sm focus:border-figmaBlue focus:bg-white focus:outline-none focus:ring-1 focus:ring-figmaBlue">
                        </div>
                        <select name="who" onchange="this.form.submit()" aria-label="Show activity by" class="<?php echo $selectCls; ?> max-w-[220px]">
                            <option value="">Everyone</option>
                            <?php foreach ($people as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $who === (string)$value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="when" onchange="this.form.submit()" aria-label="Show activity from" class="<?php echo $selectCls; ?>">
                            <?php foreach (PERIODS as $value => [$label]): ?>
                                <option value="<?php echo $value; ?>" <?php echo $when === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="rounded-md bg-figmaBlue px-5 py-2.5 text-xs font-bold text-white transition hover:bg-blue-900">SEARCH</button>
                        <?php if ($filtered): ?>
                        <!-- Visible whenever a filter is applied; returns to the complete log -->
                        <a href="audit_logs.php" title="Remove every filter and show the complete log"
                           class="inline-flex items-center gap-2 whitespace-nowrap rounded-md border border-red-300 bg-red-50 px-4 py-2.5 text-xs font-bold text-red-700 shadow-sm transition hover:bg-red-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-1">
                            <?php echo $icon('M6 18L18 6M6 6l12 12', 'h-4 w-4'); ?>
                            CLEAR FILTERS
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Activity -->
                <div class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm">
                    <?php if (!$logs): ?>
                        <div class="p-16 text-center">
                            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                <?php echo $icon(CATEGORY_ICONS['validation'], 'h-6 w-6'); ?>
                            </div>
                            <div class="text-sm font-bold text-gray-800"><?php echo $filtered ? 'No Matching Activity' : 'No Activity Recorded Yet'; ?></div>
                            <div class="mt-1 text-sm text-gray-400"><?php echo $filtered ? 'Nothing matches these filters. Try another search, or select Clear Filters to see the complete log.' : 'Activity will appear here as the system is used.'; ?></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($days as $day => $entries): $dayTs = strtotime($day); ?>
                        <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-6 py-2.5">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-600">
                                <?php echo $day === $today ? 'Today' : ($day === $yesterday ? 'Yesterday' : e(date('l', $dayTs))); ?>
                            </h3>
                            <span class="text-xs text-gray-400"><?php echo e(date('F j, Y', $dayTs)); ?></span>
                        </div>
                        <ol class="divide-y divide-gray-100 border-b border-gray-100">
                            <?php foreach ($entries as $log):
                                $ts  = strtotime($log['created_at']);
                                $def = AUDIT_ACTIONS[$log['action']] ?? ['other', ucwords(str_replace('_', ' ', $log['action'])), 'gray', 'performed an action'];
                                [$text, $changes, $reason] = log_details($log);
                                $isPublic = $log['user_id'] === null && $log['username'] === 'Public registration';
                                $actor = $isPublic ? 'A new intern' : ($log['display_name'] ?: $log['username']);
                                $meta = array_filter([
                                    $isPublic ? 'Public registration form' : ($actor !== $log['username'] ? '@' . $log['username'] : ''),
                                    $log['role'] ? role_label($log['role']) : '',
                                    $log['ip_address'] ? 'IP ' . $log['ip_address'] : '',
                                ]); ?>
                            <li class="flex gap-4 px-6 py-4 <?php echo $def[0] === 'security' ? 'bg-red-50/40' : ''; ?>">
                                <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full <?php echo BADGE_CLS[$def[2]]; ?>" title="<?php echo e($def[1]); ?>">
                                    <?php echo $icon($log['action'] === 'logout' ? 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1' : CATEGORY_ICONS[$def[0]], 'h-[18px] w-[18px]'); ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-gray-700"><span class="font-bold text-gray-900"><?php echo e($actor); ?></span> <?php echo e($def[3]); ?></p>
                                    <?php if ($text !== '' && !in_array($text, QUIET_DETAILS, true)): ?>
                                        <p class="mt-0.5 break-words text-sm text-gray-500"><?php echo e($text); ?></p>
                                    <?php endif; ?>
                                    <?php if ($changes): ?>
                                        <dl class="mt-2 grid max-w-2xl grid-cols-[minmax(6rem,auto)_1fr] gap-x-4 gap-y-1 rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-xs">
                                            <?php foreach ($changes as [$field, $before, $after]): ?>
                                                <dt class="font-semibold text-gray-500"><?php echo e($field); ?></dt>
                                                <dd class="min-w-0 break-words"><span class="text-gray-400 line-through"><?php echo e($before); ?></span> <span class="text-gray-400">&rarr;</span> <span class="font-semibold text-gray-900"><?php echo e($after); ?></span></dd>
                                            <?php endforeach; ?>
                                        </dl>
                                    <?php endif; ?>
                                    <?php if ($reason !== null): ?>
                                        <p class="mt-2 border-l-2 border-red-200 pl-3 text-xs text-gray-500">Reason given: <span class="italic text-gray-700"><?php echo e($reason); ?></span></p>
                                    <?php endif; ?>
                                    <?php if ($meta): ?>
                                        <p class="mt-1.5 text-[11px] text-gray-400"><?php echo e(implode(' · ', $meta)); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="shrink-0 text-right" title="<?php echo e(date('l, F j, Y \a\t g:i:s A', $ts)); ?>">
                                    <div class="text-sm font-semibold text-gray-700"><?php echo e(date('g:i A', $ts)); ?></div>
                                    <?php if ($day === $today): ?><div class="text-[11px] text-gray-400"><?php echo e($ago($ts)); ?></div><?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ol>
                        <?php endforeach; ?>

                        <!-- Pagination: newest entries come first -->
                        <div class="flex items-center justify-between px-6 py-4">
                            <div class="text-xs text-gray-400">Showing <span class="font-bold text-gray-700"><?php echo number_format($offset + 1); ?>&ndash;<?php echo number_format($offset + count($logs)); ?></span> of <span class="font-bold text-gray-700"><?php echo number_format($total); ?></span></div>
                            <div class="flex items-center gap-2">
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo e($link(['page' => $page > 2 ? $page - 1 : ''])); ?>" class="rounded border border-gray-200 px-3 py-1 text-xs font-bold text-gray-500 hover:bg-gray-50">&larr; Newer</a>
                                <?php else: ?>
                                    <span class="rounded border border-gray-100 px-3 py-1 text-xs font-bold text-gray-300">&larr; Newer</span>
                                <?php endif; ?>
                                <span class="px-1 text-xs text-gray-500">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                                <?php if ($page < $pages): ?>
                                    <a href="<?php echo e($link(['page' => $page + 1])); ?>" class="rounded border border-gray-200 px-3 py-1 text-xs font-bold text-gray-500 hover:bg-gray-50">Older &rarr;</a>
                                <?php else: ?>
                                    <span class="rounded border border-gray-100 px-3 py-1 text-xs font-bold text-gray-300">Older &rarr;</span>
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
