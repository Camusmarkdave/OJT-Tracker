<?php
/**
 * functions.php - shared helpers for InternTrack.
 * Loaded automatically by db.php, so every page that requires db.php can use these.
 */

const APP_NAME   = 'InternTrack';
const STATUSES   = ['Active', 'Completed'];
const INPUT_CLS  = 'w-full border border-gray-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:ring-1 focus:ring-figmaBlue focus:border-figmaBlue';
const LABEL_CLS  = 'block text-xs font-bold text-gray-700 mb-1';
const STATUS_CLS = [
    'Active'    => 'bg-green-100 text-green-700',
    'Completed' => 'bg-blue-100 text-figmaBlue',
];

/* ------------------------------------------------------------------ */
/* Basics                                                              */
/* ------------------------------------------------------------------ */

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function json_safe($v): string {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

function redirect(string $to) { header("Location: $to"); exit; }

function require_login(): void {
    if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { redirect('login.php'); }
}

function fmt_date(?string $d): string { return $d ? date('M j, Y', strtotime($d)) : '-'; }

function status_badge(string $s): string {
    return '<span class="' . (STATUS_CLS[$s] ?? 'bg-gray-100 text-gray-600') . ' text-[10px] font-bold px-3 py-1 rounded-full tracking-wider uppercase">' . e($s) . '</span>';
}

/* CSRF protection for every POST form */
function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_verify(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        die('Your session expired. Go back, refresh the page and try again.');
    }
}

/* One-time messages shown after a redirect */
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_html(): string {
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $cls = $type === 'error' ? 'border-red-500 bg-red-50 text-red-700' : 'border-green-500 bg-green-50 text-green-700';
        $out .= '<div class="mb-6 rounded border-l-4 p-4 text-sm font-medium ' . $cls . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function errors_box(array $errs): string {
    if (!$errs) { return ''; }
    $li = implode('', array_map(fn($m) => '<li>' . e($m) . '</li>', $errs));
    return '<div data-server-errors class="rounded border-l-4 border-red-500 bg-red-50 p-3 text-xs text-red-700">'
         . '<div class="mb-1 font-bold">Please fix the following:</div><ul class="list-disc space-y-0.5 pl-4">' . $li . '</ul></div>';
}

/* ------------------------------------------------------------------ */
/* Database upgrade + settings                                         */
/* ------------------------------------------------------------------ */

/**
 * Brings an existing database up to date. Safe to run more than once.
 * Existing intern records are kept; nothing is deleted.
 */
function ensure_schema(PDO $pdo): void {
    try {
        $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='schema_version'")->fetchColumn();
    } catch (PDOException $ex) {
        $v = false; // settings table doesn't exist yet
    }
    if ($v === '3') { return; }

    $has = fn($t, $c) => (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch();

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        UNIQUE KEY name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if (!$has('interns', 'validation_status')) {
        // Existing records stay fully accepted ('Approved'); only new self-registrations start as 'Pending'.
        $pdo->exec("ALTER TABLE interns
            ADD validation_status VARCHAR(20) NOT NULL DEFAULT 'Approved',
            ADD submitted_via VARCHAR(20) NOT NULL DEFAULT 'admin',
            ADD rejection_reason TEXT NULL,
            ADD validated_at DATETIME NULL");
    }
    if (!$has('users', 'display_name')) {
        $pdo->exec("ALTER TABLE users ADD display_name VARCHAR(100) NULL");
    }

    // "Upcoming" no longer exists. Old records become Completed if their end date has passed, otherwise Active.
    $pdo->exec("UPDATE interns
                SET status = IF(end_date IS NOT NULL AND end_date < CURDATE(), 'Completed', 'Active')
                WHERE status IS NULL OR status NOT IN ('Active','Completed')");
    $pdo->exec("ALTER TABLE interns MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Active'");
    // (The old graduation_date column is left in place, untouched and unused, so no data is lost.)

    if ((int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn() === 0) {
        $pdo->exec("INSERT IGNORE INTO departments (name) SELECT DISTINCT TRIM(department) FROM interns WHERE TRIM(department) <> ''");
        if ((int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO departments (name) VALUES ('Finance'),('HR'),('IT'),('Marketing'),('Operations'),('Sales')");
        }
    }
    save_setting($pdo, 'schema_version', '3');
}

function setting(string $key, ?string $default = null): ?string {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        try { $cache = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (PDOException $ex) { $cache = []; }
    }
    $defaults = ['registration_open' => '1'];
    return $cache[$key] ?? $default ?? ($defaults[$key] ?? null);
}

function save_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

/* ------------------------------------------------------------------ */
/* Lookups                                                             */
/* ------------------------------------------------------------------ */

function get_departments(PDO $pdo): array {
    return $pdo->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

/** Schools come from accepted intern records (entered through + Add Intern or approved registrations). */
function get_schools(PDO $pdo): array {
    return $pdo->query("SELECT DISTINCT school FROM interns
                        WHERE validation_status='Approved' AND school IS NOT NULL AND school <> ''
                        ORDER BY school")->fetchAll(PDO::FETCH_COLUMN);
}

function pending_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM interns WHERE validation_status='Pending'")->fetchColumn();
}

/* ------------------------------------------------------------------ */
/* Intern records: cleaning, validation, saving                        */
/* ------------------------------------------------------------------ */

function clean_intern_input(array $in): array {
    $t = fn($k) => trim((string)($in[$k] ?? ''));
    $d = fn($k) => $t($k) !== '' ? $t($k) : null;
    return [
        'first_name' => $t('first_name'),
        'last_name'  => $t('last_name'),
        'course'     => $t('course'),
        'school'     => $t('school'),
        'department' => $t('department'),
        'start_date' => $d('start_date'),
        'end_date'   => $d('end_date'),
        'batch_year' => $t('batch_year') !== '' ? $t('batch_year') : date('Y'),
        'status'     => $in['status'] ?? 'Active',
    ];
}

/**
 * Validates an intern record (array with the same keys as the interns table).
 * Returns a list of error messages; an empty list means the record is valid.
 */
function validate_intern(array $d): array {
    $err = [];

    foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'department' => 'Department'] as $k => $label) {
        if (trim((string)($d[$k] ?? '')) === '') { $err[] = "$label is required."; }
    }
    foreach (['first_name' => 50, 'last_name' => 50, 'course' => 100, 'school' => 100, 'department' => 50] as $k => $max) {
        if (mb_strlen((string)($d[$k] ?? '')) > $max) {
            $err[] = ucfirst(str_replace('_', ' ', $k)) . " must be $max characters or fewer.";
        }
    }
    if (!in_array($d['status'] ?? '', STATUSES, true)) { $err[] = 'Please choose a valid status.'; }

    $maxYear = (int)date('Y') + 5;
    $by = (string)($d['batch_year'] ?? '');
    if (!ctype_digit($by) || (int)$by < 2000 || (int)$by > $maxYear) {
        $err[] = "Batch year must be between 2000 and $maxYear.";
    }

    // Each date must be a real calendar date (rejects things like Feb 31 or a typo'd year)
    $dates = [];
    foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $k => $label) {
        $s = $d[$k] ?? null;
        if ($s === null || $s === '') { continue; }
        $o = DateTime::createFromFormat('!Y-m-d', (string)$s);
        if (!$o || $o->format('Y-m-d') !== $s) { $err[] = "$label is not a valid date."; continue; }
        if ((int)$o->format('Y') < 2000 || (int)$o->format('Y') > 2100) {
            $err[] = "$label must fall between the years 2000 and 2100."; continue;
        }
        $dates[$k] = $o;
    }

    // Sequence rule: the end date can't come before the start date
    if (isset($dates['start_date'], $dates['end_date']) && $dates['end_date'] < $dates['start_date']) {
        $err[] = 'Date conflict: the end date (' . $dates['end_date']->format('M j, Y')
               . ') is earlier than the start date (' . $dates['start_date']->format('M j, Y') . ').';
    }
    return $err;
}

function insert_intern(PDO $pdo, array $d, string $validation = 'Approved', string $via = 'admin'): void {
    // validated_at is stamped by the database clock so it matches created_at
    $validatedAt = $validation === 'Approved' ? 'NOW()' : 'NULL';
    $pdo->prepare("INSERT INTO interns
        (first_name, last_name, course, school, department, start_date, end_date, batch_year, status, validation_status, submitted_via, validated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,$validatedAt)")
        ->execute([
            $d['first_name'], $d['last_name'], $d['course'] ?: null, $d['school'] ?: null, $d['department'],
            $d['start_date'], $d['end_date'], (int)$d['batch_year'], $d['status'], $validation, $via,
        ]);
}

function update_intern(PDO $pdo, int $id, array $d): void {
    $pdo->prepare("UPDATE interns SET first_name=?, last_name=?, course=?, school=?, department=?, start_date=?, end_date=?, batch_year=?, status=? WHERE id=?")
        ->execute([
            $d['first_name'], $d['last_name'], $d['course'] ?: null, $d['school'] ?: null, $d['department'],
            $d['start_date'], $d['end_date'], (int)$d['batch_year'], $d['status'], $id,
        ]);
}

/** Finds another (non-rejected) record with the same name and batch year. */
function find_duplicate(PDO $pdo, array $d, int $excludeId = 0): ?array {
    $st = $pdo->prepare("SELECT id, validation_status FROM interns
        WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) AND batch_year=? AND id<>? AND validation_status<>'Rejected' LIMIT 1");
    $st->execute([$d['first_name'], $d['last_name'], (int)$d['batch_year'], $excludeId]);
    return $st->fetch() ?: null;
}

/* ------------------------------------------------------------------ */
/* Shared form fields (used by Add, Edit, Register and Validation)     */
/* ------------------------------------------------------------------ */

function form_attrs(): string { return 'data-intern-form'; }

/**
 * Prints the intern fields. $p is an id prefix (e.g. 'add_', 'edit_', 'reg_', 'rv_'),
 * $v pre-filled values, $o options: ['status' => bool, 'errors' => string[]].
 */
function intern_fields(PDO $pdo, string $p, array $v = [], array $o = []): void {
    $withStatus = $o['status'] ?? true;
    $depts   = get_departments($pdo);
    $schools = get_schools($pdo);
    $val     = fn($k) => e($v[$k] ?? '');
    $year    = ($v['batch_year'] ?? '') !== '' ? $v['batch_year'] : date('Y');
    $status  = $v['status'] ?? 'Active';
    $dept    = (string)($v['department'] ?? '');
    $L = LABEL_CLS; $I = INPUT_CLS;
    echo errors_box($o['errors'] ?? []);
    ?>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>first_name">FIRST NAME *</label>
            <input type="text" name="first_name" id="<?= $p ?>first_name" value="<?= $val('first_name') ?>" maxlength="50" required class="<?= $I ?>">
        </div>
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>last_name">LAST NAME *</label>
            <input type="text" name="last_name" id="<?= $p ?>last_name" value="<?= $val('last_name') ?>" maxlength="50" required class="<?= $I ?>">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>school">SCHOOL</label>
            <input type="text" name="school" id="<?= $p ?>school" value="<?= $val('school') ?>" maxlength="100" list="<?= $p ?>schools" placeholder="e.g. Stanford University" autocomplete="off" class="<?= $I ?>">
            <datalist id="<?= $p ?>schools"><?php foreach ($schools as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
        </div>
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>course">COURSE</label>
            <input type="text" name="course" id="<?= $p ?>course" value="<?= $val('course') ?>" maxlength="100" placeholder="e.g. BS Computer Science" class="<?= $I ?>">
        </div>
    </div>

    <div class="grid <?= $withStatus ? 'grid-cols-3' : 'grid-cols-2' ?> gap-4">
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>department">DEPARTMENT *</label>
            <?php if ($depts): ?>
                <?php if ($dept !== '' && !in_array($dept, $depts, true)) { $depts[] = $dept; } ?>
                <select name="department" id="<?= $p ?>department" required class="<?= $I ?>">
                    <option value="">Select department</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= e($d) ?>" <?= $d === $dept ? 'selected' : '' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" name="department" id="<?= $p ?>department" value="<?= e($dept) ?>" maxlength="50" required placeholder="e.g. IT, HR" class="<?= $I ?>">
            <?php endif; ?>
        </div>
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>batch_year">BATCH YEAR *</label>
            <input type="number" name="batch_year" id="<?= $p ?>batch_year" value="<?= e($year) ?>" min="2000" max="<?= (int)date('Y') + 5 ?>" required class="<?= $I ?>">
        </div>
        <?php if ($withStatus): ?>
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>status">STATUS</label>
            <select name="status" id="<?= $p ?>status" class="<?= $I ?>">
                <?php foreach (STATUSES as $s): ?>
                    <option value="<?= $s ?>" <?= $s === $status ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>start_date">START DATE</label>
            <input type="date" name="start_date" id="<?= $p ?>start_date" value="<?= $val('start_date') ?>" class="<?= $I ?>">
        </div>
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>end_date">END DATE</label>
            <input type="date" name="end_date" id="<?= $p ?>end_date" value="<?= $val('end_date') ?>" class="<?= $I ?>">
        </div>
    </div>
    <p class="text-[11px] text-gray-400">The end date can't be earlier than the start date.</p>
    <div data-date-error class="hidden rounded border-l-4 border-red-500 bg-red-50 p-3 text-xs font-medium text-red-700"></div>
    <?php
}

/* ------------------------------------------------------------------ */
/* Page layout: <head> + sidebar. Pages then print their own header.   */
/* ------------------------------------------------------------------ */

function page_start(PDO $pdo, string $title, string $active): void {
    $sys = e(APP_NAME);
    $stats = ['Active' => 0, 'Completed' => 0];
    foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM interns
                          WHERE validation_status='Approved' AND status IN ('Active','Completed') GROUP BY status") as $r) {
        $stats[$r['status']] = (int)$r['c'];
    }
    $pending = pending_count($pdo);

    $st = $pdo->prepare("SELECT username, display_name FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id'] ?? 0]);
    $u = $st->fetch() ?: ['username' => 'admin', 'display_name' => null];
    $name = trim($u['display_name'] ?: $u['username']);
    $parts = preg_split('/[\s._-]+/', $name) ?: [$name];
    $initials = strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : mb_substr($parts[0], 1, 1)));

    $nav = [
        'dashboard'  => ['dashboard.php',  'Intern Records', 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
        'reports'    => ['reports.php',    'Reports',        'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        'validation' => ['validation.php', 'Validation',     'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
        'settings'   => ['settings.php',   'Settings',       'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z|M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $sys ?> - <?= e($title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { figmaBg: '#F5F6F8', figmaBlue: '#15458A', figmaYellow: '#FDDB31' } } } }
    </script>
</head>
<body class="flex h-screen bg-figmaBg font-sans overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-figmaBlue text-white flex flex-col justify-between shadow-xl z-20 relative shrink-0 print:hidden">
        <div>
            <div class="p-6 flex items-center gap-3 border-b border-blue-800/50">
                <div class="flex h-10 w-10 items-center justify-center rounded bg-figmaYellow text-figmaBlue shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3z"/></svg>
                </div>
                <div>
                    <h1 class="text-sm font-bold tracking-widest leading-tight uppercase"><?= $sys ?></h1>
                    <span class="text-[10px] text-blue-200">Records System</span>
                </div>
            </div>

            <div class="p-4">
                <div class="text-[10px] font-bold tracking-widest text-blue-300 mb-3 px-3">NAVIGATION</div>
                <nav class="space-y-1">
                    <?php foreach ($nav as $key => [$href, $label, $paths]): $on = $key === $active; ?>
                    <a href="<?= $href ?>" class="flex items-center justify-between px-3 py-2.5 rounded-md text-sm font-medium transition-colors <?= $on ? 'bg-blue-800/60 text-white border border-blue-700/50' : 'text-blue-200 hover:bg-blue-800/40' ?>">
                        <div class="flex items-center gap-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?php foreach (explode('|', $paths) as $d): ?><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $d ?>"></path><?php endforeach; ?></svg>
                            <?= $label ?>
                        </div>
                        <?php if ($key === 'validation' && $pending > 0): ?>
                            <span class="bg-figmaYellow text-figmaBlue text-[10px] font-black px-2 py-0.5 rounded-full"><?= $pending ?></span>
                        <?php elseif ($on): ?>
                            <svg class="w-4 h-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>

        <div>
            <div class="px-6 py-4 space-y-3 border-t border-blue-800/50">
                <div class="flex justify-between text-xs text-blue-200">
                    <span>Active</span><span class="bg-green-500/20 text-green-400 px-2 py-0.5 rounded font-bold"><?= $stats['Active'] ?></span>
                </div>
                <div class="flex justify-between text-xs text-blue-200">
                    <span>Completed</span><span class="bg-blue-300/20 text-blue-200 px-2 py-0.5 rounded font-bold"><?= $stats['Completed'] ?></span>
                </div>
            </div>
            <div class="p-4 border-t border-blue-800/50 flex items-center justify-between bg-blue-900/30">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="h-9 w-9 rounded bg-figmaYellow flex items-center justify-center text-figmaBlue font-bold text-sm shrink-0"><?= e($initials) ?></div>
                    <div class="leading-tight min-w-0">
                        <div class="text-sm font-bold text-white truncate"><?= e($name) ?></div>
                        <div class="text-[10px] text-blue-200 truncate">HR Department</div>
                    </div>
                </div>
                <a href="logout.php" title="Log out" class="text-blue-300 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                </a>
            </div>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-hidden">
    <?php
}