<?php
/**
 * functions.php - shared helpers for InternTrack (OJT Management System).
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
/* Roles and permissions                                               */
/* ------------------------------------------------------------------ */

const ROLE_SUPER = 'super_admin';
const ROLE_ADMIN = 'admin';
const ROLE_LABELS = [ROLE_SUPER => 'Super Admin', ROLE_ADMIN => 'Admin'];
const ROLE_CLS    = [ROLE_SUPER => 'bg-yellow-100 text-yellow-800', ROLE_ADMIN => 'bg-blue-100 text-figmaBlue'];

const ALL_ROLES  = [ROLE_SUPER, ROLE_ADMIN];
const SUPER_ONLY = [ROLE_SUPER];

// Sign-in details of the regular Admin account that is created automatically (see ensure_schema).
// The Admin can change this password under Settings > My Account.
const DEFAULT_ADMIN_USERNAME = 'admin';
const DEFAULT_ADMIN_PASSWORD = 'Admin@2026';
const DEFAULT_ADMIN_NAME     = 'Admin User';

/**
 * Single source of truth for what each role may do.
 * Admin: day-to-day OJT management. Super Admin: everything an Admin can do, plus delete records,
 * control registration, and view the audit logs.
 */
const PERMISSIONS = [
    'records.view'        => ALL_ROLES,
    'records.add'         => ALL_ROLES,
    'records.edit'        => ALL_ROLES,
    'records.delete'      => SUPER_ONLY,
    'reports.view'        => ALL_ROLES,
    'validation.view'     => ALL_ROLES,
    'validation.review'   => ALL_ROLES,   // approve, reject, correct, or return a submission to pending
    'validation.delete'   => SUPER_ONLY,
    'account.manage_own'  => ALL_ROLES,
    'registration.manage' => SUPER_ONLY,
    'audit.view'          => SUPER_ONLY,
];

/** Audit log activity types: key => [category, label, badge colour]. */
const AUDIT_ACTIONS = [
    'login'                  => ['Authentication', 'Sign-in',                      'green'],
    'login_failed'           => ['Authentication', 'Failed sign-in',               'red'],
    'logout'                 => ['Authentication', 'Sign-out',                     'gray'],
    'intern_added'           => ['Intern Records', 'Intern added',                 'blue'],
    'intern_edited'          => ['Intern Records', 'Intern record edited',         'blue'],
    'intern_deleted'         => ['Intern Records', 'Intern record deleted',        'red'],
    'registration_submitted' => ['Validation',     'Registration submitted',       'yellow'],
    'validation_approved'    => ['Validation',     'Submission approved',          'green'],
    'validation_rejected'    => ['Validation',     'Submission rejected',          'red'],
    'validation_edited'      => ['Validation',     'Submission edited',            'blue'],
    'validation_reopened'    => ['Validation',     'Returned to pending',          'yellow'],
    'validation_deleted'     => ['Validation',     'Submission deleted',           'red'],
    'profile_updated'        => ['Settings',       'Profile updated',              'blue'],
    'password_changed'       => ['Settings',       'Password changed',             'blue'],
    'registration_toggled'   => ['Settings',       'Registration setting changed', 'yellow'],
    'access_denied'          => ['Security',       'Access denied',                'red'],
];
const BADGE_CLS = [
    'green'  => 'bg-green-100 text-green-700',
    'red'    => 'bg-red-100 text-red-700',
    'blue'   => 'bg-blue-100 text-figmaBlue',
    'yellow' => 'bg-yellow-100 text-yellow-800',
    'gray'   => 'bg-gray-100 text-gray-600',
];

/* ------------------------------------------------------------------ */
/* Basics                                                              */
/* ------------------------------------------------------------------ */

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function json_safe($v): string {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

function redirect(string $to) { header("Location: $to"); exit; }

function client_ip(): ?string { return $_SERVER['REMOTE_ADDR'] ?? null; }

function fmt_date(?string $d): string { return $d ? date('M j, Y', strtotime($d)) : '-'; }

function status_badge(string $s): string {
    return '<span class="' . (STATUS_CLS[$s] ?? 'bg-gray-100 text-gray-600') . ' text-[10px] font-bold px-3 py-1 rounded-full tracking-wider uppercase">' . e($s) . '</span>';
}

function role_label(?string $role): string { return ROLE_LABELS[$role] ?? 'Unassigned'; }

function role_badge(?string $role): string {
    return '<span class="' . (ROLE_CLS[$role] ?? 'bg-gray-100 text-gray-600') . ' rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">' . e(role_label($role)) . '</span>';
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
        die('Your session has expired. Please return to the previous page, refresh it, and try again.');
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
         . '<div class="mb-1 font-bold">Please correct the following before continuing:</div><ul class="list-disc space-y-0.5 pl-4">' . $li . '</ul></div>';
}

/* ------------------------------------------------------------------ */
/* Current user, login guard and permission checks                     */
/* ------------------------------------------------------------------ */

/** The signed-in user's record (re-read on every request, so role or status changes apply immediately). */
function current_user(bool $refresh = false): ?array {
    global $pdo;
    static $user = false;
    if ($user === false || $refresh) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $st = $pdo->prepare("SELECT id, username, display_name, `role`, is_active, last_login_at FROM users WHERE id = ?");
            $st->execute([(int)$_SESSION['user_id']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

function require_login(): void {
    $u = !empty($_SESSION['loggedin']) ? current_user() : null;
    if (!$u) { redirect('login.php'); }
    if (!(int)$u['is_active']) {
        session_unset();
        session_destroy();
        redirect('login.php?reason=deactivated');
    }
}

function can(string $permission): bool {
    $u = current_user();
    return $u !== null && in_array($u['role'], PERMISSIONS[$permission] ?? [], true);
}

/** Stops the request (and records the attempt) when the signed-in user lacks the permission. */
function require_permission(string $permission): void {
    global $pdo;
    if (can($permission)) { return; }
    audit_log($pdo, 'access_denied', 'Attempted a restricted action (' . $permission . ') on ' . basename($_SERVER['PHP_SELF'] ?? '') . '.');
    flash('error', 'You do not have permission to perform this action. Please contact a Super Admin if you require access.');
    redirect('dashboard.php');
}

/* ------------------------------------------------------------------ */
/* Audit logging                                                       */
/* ------------------------------------------------------------------ */

/**
 * Records an important action. $actor defaults to the signed-in user; pass an array like
 * ['id' => null, 'username' => 'Public registration', 'role' => null] for actions without a signed-in user.
 * Logging never interrupts the action being performed.
 */
function audit_log(PDO $pdo, string $action, string $details = '', ?array $actor = null): void {
    $actor = $actor ?? current_user();
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, username, `role`, action, details, ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([
                $actor['id'] ?? null,
                $actor['username'] ?? 'System',
                $actor['role'] ?? null,
                $action,
                mb_substr($details, 0, 2000),
                client_ip(),
            ]);
    } catch (PDOException $ex) {
        // intentionally ignored
    }
}

/** "Maria Santos (Holy Angel University, IT, Batch 2026)" */
function intern_summary(array $d): string {
    $parts = array_filter([
        trim((string)($d['school'] ?? '')),
        trim((string)($d['department'] ?? '')),
        !empty($d['batch_year']) ? 'Batch ' . $d['batch_year'] : '',
    ]);
    return trim($d['first_name'] . ' ' . $d['last_name']) . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
}

/** Lists which fields changed between two versions of an intern record. */
function intern_diff(array $before, array $after): string {
    $labels = ['first_name' => 'First name', 'last_name' => 'Last name', 'course' => 'Course', 'school' => 'School',
               'department' => 'Department', 'batch_year' => 'Batch year', 'status' => 'Status',
               'start_date' => 'Start date', 'end_date' => 'End date'];
    $changes = [];
    foreach ($labels as $k => $label) {
        $b = trim((string)($before[$k] ?? ''));
        $a = trim((string)($after[$k] ?? ''));
        if ($b !== $a) { $changes[] = $label . ': ' . ($b !== '' ? $b : '(blank)') . ' → ' . ($a !== '' ? $a : '(blank)'); }
    }
    return $changes ? implode('; ', $changes) : 'No fields were changed';
}

/* ------------------------------------------------------------------ */
/* Database upgrade + settings                                         */
/* ------------------------------------------------------------------ */

/**
 * Brings an existing database up to date. Safe to run more than once.
 * Existing records and accounts are kept; nothing is deleted.
 */
function ensure_schema(PDO $pdo): void {
    try {
        $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='schema_version'")->fetchColumn();
    } catch (PDOException $ex) {
        $v = false; // settings table doesn't exist yet
    }
    if ($v === '5') { return; }

    $has = fn($t, $c) => (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch();

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL
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

    // Roles: every account that existed before roles were introduced (the HR Admin) becomes a Super Admin.
    if (!$has('users', 'role')) {
        $pdo->exec("ALTER TABLE users
            ADD `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
            ADD is_active TINYINT(1) NOT NULL DEFAULT 1,
            ADD last_login_at DATETIME NULL");
        $pdo->exec("UPDATE users SET `role` = 'super_admin'");
    }

    // Audit trail
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(50) NOT NULL,
        `role` VARCHAR(20) NULL,
        action VARCHAR(40) NOT NULL,
        details TEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at),
        KEY idx_action (action),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Status is Active or Completed. Older 'Upcoming' records become Completed once their end date has passed, otherwise Active.
    $pdo->exec("UPDATE interns
                SET status = IF(end_date IS NOT NULL AND end_date < CURDATE(), 'Completed', 'Active')
                WHERE status IS NULL OR status NOT IN ('Active','Completed')");
    $pdo->exec("ALTER TABLE interns MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Active'");
    // (An old graduation_date column or departments table, if present, is left untouched and unused so no data is lost.)

    // The regular Admin account. Created once, so the Admin can sign in straight away.
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $st->execute([DEFAULT_ADMIN_USERNAME]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO users (username, password_hash, display_name, `role`, is_active) VALUES (?,?,?,?,1)")
            ->execute([DEFAULT_ADMIN_USERNAME, password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT), DEFAULT_ADMIN_NAME, ROLE_ADMIN]);
    }

    save_setting($pdo, 'schema_version', '5');
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

/**
 * Department suggestions for the forms: a few common departments plus every department already used in
 * the intern records. The department field itself is free text, so any department can be entered.
 */
function get_departments(PDO $pdo): array {
    $names = ['Finance', 'HR', 'IT', 'Marketing', 'Operations', 'Sales'];
    foreach ($pdo->query("SELECT DISTINCT TRIM(department) FROM interns WHERE TRIM(department) <> ''")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $names[] = $d;
    }
    $unique = [];
    foreach ($names as $n) {
        $key = mb_strtolower($n);
        if (!isset($unique[$key])) { $unique[$key] = $n; }
    }
    $out = array_values($unique);
    usort($out, 'strcasecmp');
    return $out;
}

/** Schools come from accepted intern records (entered through Add Intern or approved registrations). */
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
            $err[] = ucfirst(str_replace('_', ' ', $k)) . " must not exceed $max characters.";
        }
    }
    if (!in_array($d['status'] ?? '', STATUSES, true)) { $err[] = 'Please select a valid status.'; }

    $maxYear = (int)date('Y') + 5;
    $by = (string)($d['batch_year'] ?? '');
    if (!ctype_digit($by) || (int)$by < 2000 || (int)$by > $maxYear) {
        $err[] = "Batch year must be between 2000 and $maxYear.";
    }

    // Each date must be a real calendar date (rejects values such as February 31 or a mistyped year)
    $dates = [];
    foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $k => $label) {
        $s = $d[$k] ?? null;
        if ($s === null || $s === '') { continue; }
        $o = DateTime::createFromFormat('!Y-m-d', (string)$s);
        if (!$o || $o->format('Y-m-d') !== $s) { $err[] = "$label is not a valid calendar date."; continue; }
        if ((int)$o->format('Y') < 2000 || (int)$o->format('Y') > 2100) {
            $err[] = "$label must fall between the years 2000 and 2100."; continue;
        }
        $dates[$k] = $o;
    }

    // Sequence rule: the end date cannot precede the start date
    if (isset($dates['start_date'], $dates['end_date']) && $dates['end_date'] < $dates['start_date']) {
        $err[] = 'Invalid date range: the end date (' . $dates['end_date']->format('M j, Y')
               . ') cannot be earlier than the start date (' . $dates['start_date']->format('M j, Y') . ').';
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
            <input type="text" name="school" id="<?= $p ?>school" value="<?= $val('school') ?>" maxlength="100" list="<?= $p ?>schools" placeholder="e.g. Holy Angel University" autocomplete="off" class="<?= $I ?>">
            <datalist id="<?= $p ?>schools"><?php foreach ($schools as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
        </div>
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>course">COURSE</label>
            <input type="text" name="course" id="<?= $p ?>course" value="<?= $val('course') ?>" maxlength="100" placeholder="e.g. BS Information Technology" class="<?= $I ?>">
        </div>
    </div>

    <div class="grid <?= $withStatus ? 'grid-cols-3' : 'grid-cols-2' ?> gap-4">
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>department">DEPARTMENT *</label>
            <input type="text" name="department" id="<?= $p ?>department" value="<?= $val('department') ?>" maxlength="50" required list="<?= $p ?>departments" placeholder="e.g. IT" autocomplete="off" class="<?= $I ?>">
            <datalist id="<?= $p ?>departments"><?php foreach ($depts as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
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
            <label class="<?= $L ?>" for="<?= $p ?>start_date">INTERNSHIP START DATE</label>
            <input type="date" name="start_date" id="<?= $p ?>start_date" value="<?= $val('start_date') ?>" class="<?= $I ?>">
        </div>
        <div>
            <label class="<?= $L ?>" for="<?= $p ?>end_date">INTERNSHIP END DATE</label>
            <input type="date" name="end_date" id="<?= $p ?>end_date" value="<?= $val('end_date') ?>" class="<?= $I ?>">
        </div>
    </div>
    <p class="text-[11px] text-gray-400">Fields marked with an asterisk (*) are required. The end date must not be earlier than the start date.</p>
    <div data-date-error class="hidden rounded border-l-4 border-red-500 bg-red-50 p-3 text-xs font-medium text-red-700"></div>
    <?php
}

/* ------------------------------------------------------------------ */
/* Page layout: <head> + role-aware sidebar. Pages print their own header. */
/* ------------------------------------------------------------------ */

function nav_link(string $href, string $label, string $paths, bool $on, int $badge = 0): string {
    $cls = $on ? 'bg-blue-800/60 text-white border border-blue-700/50' : 'text-blue-200 hover:bg-blue-800/40';
    $svg = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
    foreach (explode('|', $paths) as $d) {
        $svg .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $d . '"></path>';
    }
    $svg .= '</svg>';
    if ($badge > 0) {
        $right = '<span class="bg-figmaYellow text-figmaBlue text-[10px] font-black px-2 py-0.5 rounded-full">' . $badge . '</span>';
    } elseif ($on) {
        $right = '<svg class="w-4 h-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>';
    } else {
        $right = '';
    }
    return '<a href="' . $href . '" class="flex items-center justify-between px-3 py-2.5 rounded-md text-sm font-medium transition-colors ' . $cls . '">'
         . '<div class="flex items-center gap-3">' . $svg . e($label) . '</div>' . $right . '</a>';
}

function page_start(PDO $pdo, string $title, string $active): void {
    $sys = e(APP_NAME);
    $stats = ['Active' => 0, 'Completed' => 0];
    foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM interns
                          WHERE validation_status='Approved' AND status IN ('Active','Completed') GROUP BY status") as $r) {
        $stats[$r['status']] = (int)$r['c'];
    }
    $pending = pending_count($pdo);

    $u = current_user() ?: ['username' => 'user', 'display_name' => null, 'role' => null];
    $name = trim($u['display_name'] ?: $u['username']);
    $parts = preg_split('/[\s._-]+/', $name) ?: [$name];
    $initials = strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : mb_substr($parts[0], 1, 1)));

    // [key, href, label, icon path(s), required permission]
    $main = [
        ['dashboard',  'dashboard.php',  'Intern Records', 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z', 'records.view'],
        ['reports',    'reports.php',    'Reports',        'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'reports.view'],
        ['validation', 'validation.php', 'Validation',     'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'validation.view'],
        ['settings',   'settings.php',   'Settings',       'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z|M15 12a3 3 0 11-6 0 3 3 0 016 0z', 'account.manage_own'],
    ];
    // Visible to Super Admins only
    $admin = [
        ['audit', 'audit_logs.php', 'Audit Logs', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01', 'audit.view'],
    ];
    $admin = array_values(array_filter($admin, fn($i) => can($i[4])));
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
        <div class="overflow-y-auto">
            <div class="p-6 flex items-center gap-3 border-b border-blue-800/50">
                <div class="flex h-10 w-10 items-center justify-center rounded bg-figmaYellow text-figmaBlue shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3z"/></svg>
                </div>
                <div>
                    <h1 class="text-sm font-bold tracking-widest leading-tight uppercase"><?= $sys ?></h1>
                    <span class="text-[10px] text-blue-200">OJT Management System</span>
                </div>
            </div>

            <div class="p-4">
                <div class="text-[10px] font-bold tracking-widest text-blue-300 mb-3 px-3">NAVIGATION</div>
                <nav class="space-y-1">
                    <?php foreach ($main as [$key, $href, $label, $paths, $perm]): if (!can($perm)) { continue; } ?>
                        <?= nav_link($href, $label, $paths, $key === $active, $key === 'validation' ? $pending : 0) ?>
                    <?php endforeach; ?>
                </nav>

                <?php if ($admin): ?>
                <div class="text-[10px] font-bold tracking-widest text-blue-300 mt-6 mb-3 px-3">ADMINISTRATION</div>
                <nav class="space-y-1">
                    <?php foreach ($admin as [$key, $href, $label, $paths]): ?>
                        <?= nav_link($href, $label, $paths, $key === $active) ?>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="px-6 py-4 space-y-3 border-t border-blue-800/50">
                <div class="flex justify-between text-xs text-blue-200">
                    <span>Active Interns</span><span class="bg-green-500/20 text-green-400 px-2 py-0.5 rounded font-bold"><?= $stats['Active'] ?></span>
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
                        <div class="text-[10px] text-blue-200 truncate"><?= e(role_label($u['role'])) ?></div>
                    </div>
                </div>
                <a href="logout.php" title="Sign out" aria-label="Sign out" class="text-blue-300 hover:text-white transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                </a>
            </div>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-hidden">
    <?php
}