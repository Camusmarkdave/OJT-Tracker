<?php
session_start();
require_once 'db.php';
require_login(); // Security check

$tabs = [
    'account'      => ['Account',       'Your name, username and password'],
    'departments'  => ['Departments',   'The list interns choose from'],
    'registration' => ['Registration',  'Open or close the Register page'],
    'data'         => ['Data & export', 'Download your records'],
];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'account';
$uid = (int)($_SESSION['user_id'] ?? 0);

/* ---------- CSV export of accepted records (must run before any output) ---------- */
if (($_GET['export'] ?? '') === 'interns') {
    $safe = fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; // stops spreadsheet formula injection
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="interns-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
    fputcsv($out, ['ID', 'First name', 'Last name', 'School', 'Course', 'Department', 'Batch year', 'Status', 'Start date', 'End date'], ',', '"', '\\');
    foreach ($pdo->query("SELECT * FROM interns WHERE validation_status='Approved' ORDER BY last_name, first_name") as $r) {
        fputcsv($out, array_map($safe, [$r['id'], $r['first_name'], $r['last_name'], $r['school'], $r['course'], $r['department'],
            $r['batch_year'], $r['status'], $r['start_date'], $r['end_date']]), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

/* ---------- Saving ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['action'] ?? '';

    if ($act === 'save_profile') {
        $display = mb_substr(trim($_POST['display_name'] ?? ''), 0, 100);
        $user    = trim($_POST['username'] ?? '');
        $taken   = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?");
        $taken->execute([$user, $uid]);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $user)) {
            flash('error', 'Username must be 3-50 characters: letters, numbers, dots, dashes or underscores.');
        } elseif ($taken->fetchColumn()) {
            flash('error', 'That username is already taken.');
        } else {
            $pdo->prepare("UPDATE users SET username = ?, display_name = ? WHERE id = ?")->execute([$user, $display !== '' ? $display : null, $uid]);
            $_SESSION['username'] = $user;
            flash('success', 'Profile updated.');
        }

    } elseif ($act === 'change_password') {
        $cur  = trim($_POST['current_password'] ?? '');
        $new  = trim($_POST['new_password'] ?? '');
        $conf = trim($_POST['confirm_password'] ?? '');
        $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([$uid]);
        if (!password_verify($cur, (string)$st->fetchColumn())) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== $conf) {
            flash('error', 'The new password and its confirmation do not match.');
        } elseif ($new === $cur) {
            flash('error', 'Choose a new password that is different from the current one.');
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
        }

    } elseif ($act === 'add_department') {
        $n = trim($_POST['name'] ?? '');
        if ($n === '' || mb_strlen($n) > 50) {
            flash('error', 'Enter a department name of up to 50 characters.');
        } else {
            try {
                $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$n]);
                flash('success', "$n was added to the department list.");
            } catch (PDOException $ex) {
                flash('error', "$n is already in the list.");
            }
        }

    } elseif ($act === 'rename_department') {
        $st = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $st->execute([(int)($_POST['id'] ?? 0)]);
        $old = $st->fetchColumn();
        $n = trim($_POST['name'] ?? '');
        if ($old === false || $n === '' || mb_strlen($n) > 50) {
            flash('error', 'Enter a department name of up to 50 characters.');
        } elseif ($n !== $old) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE departments SET name = ? WHERE id = ?")->execute([$n, (int)$_POST['id']]);
                $pdo->prepare("UPDATE interns SET department = ? WHERE department = ?")->execute([$n, $old]); // keep existing records in sync
                $pdo->commit();
                flash('success', "$old was renamed to $n. Existing intern records were updated too.");
            } catch (PDOException $ex) {
                $pdo->rollBack();
                flash('error', "$n is already in the list.");
            }
        }

    } elseif ($act === 'delete_department') {
        $st = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $st->execute([(int)($_POST['id'] ?? 0)]);
        $name = $st->fetchColumn();
        $use = $pdo->prepare("SELECT COUNT(*) FROM interns WHERE department = ?");
        $use->execute([(string)$name]);
        if ($name === false) {
            flash('error', 'That department no longer exists.');
        } elseif ($use->fetchColumn() > 0) {
            flash('error', "$name is still assigned to intern records. Rename it or move those interns first.");
        } else {
            $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([(int)$_POST['id']]);
            flash('success', "$name was removed.");
        }

    } elseif ($act === 'save_registration') {
        $open = isset($_POST['registration_open']);
        save_setting($pdo, 'registration_open', $open ? '1' : '0');
        flash('success', $open ? 'Registration is now open.' : 'Registration is now closed. The Register page shows a closed message.');

    } elseif ($act === 'purge_rejected') {
        $del = $pdo->exec("DELETE FROM interns WHERE validation_status = 'Rejected'");
        flash('success', $del . ' rejected record' . ($del === 1 ? '' : 's') . ' deleted.');
    }
    redirect('settings.php?tab=' . $tab);
}

/* ---------- Data for display ---------- */
$st = $pdo->prepare("SELECT username, display_name FROM users WHERE id = ?");
$st->execute([$uid]);
$me = $st->fetch() ?: ['username' => '', 'display_name' => ''];

$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
$usage = [];
foreach ($pdo->query("SELECT department, COUNT(*) AS c FROM interns GROUP BY department") as $r) { $usage[mb_strtolower((string)$r['department'])] = (int)$r['c']; }

$counts = ['Approved' => 0, 'Pending' => 0, 'Rejected' => 0];
foreach ($pdo->query("SELECT validation_status, COUNT(*) AS c FROM interns GROUP BY validation_status") as $r) { $counts[$r['validation_status']] = (int)$r['c']; }

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$regUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') . '/register.php';
$regOpen = setting('registration_open') === '1';

function toggle(string $name, string $title, string $desc, bool $on): void { ?>
    <label class="flex cursor-pointer items-start justify-between gap-6 py-4">
        <span>
            <span class="block text-sm font-bold text-gray-800"><?= e($title) ?></span>
            <span class="block max-w-lg text-xs leading-relaxed text-gray-500"><?= e($desc) ?></span>
        </span>
        <input type="checkbox" name="<?= e($name) ?>" value="1" class="peer sr-only" <?= $on ? 'checked' : '' ?>>
        <span class="relative mt-1 h-6 w-11 shrink-0 rounded-full bg-gray-300 transition peer-checked:bg-figmaBlue peer-focus-visible:ring-2 peer-focus-visible:ring-figmaBlue after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition after:content-[''] peer-checked:after:translate-x-5"></span>
    </label>
<?php }

$I = INPUT_CLS; $L = LABEL_CLS;
$saveBtn = 'rounded bg-figmaBlue px-5 py-2.5 text-xs font-bold text-white transition hover:bg-blue-900';
$card = 'rounded-lg border border-gray-100 bg-white p-6 shadow-sm';

page_start($pdo, 'Settings', 'settings');
?>

        <!-- Header -->
        <header class="h-20 bg-white px-8 flex justify-between items-center border-b-2 border-blue-400 shadow-sm shrink-0">
            <div>
                <div class="text-xs text-gray-400 font-medium mb-1">Settings <span class="mx-1">></span> <span class="text-gray-800"><?= e($tabs[$tab][0]) ?></span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">SETTINGS</h2>
            </div>
        </header>

        <div class="p-8 overflow-y-auto flex-1">
            <?= flash_html() ?>

            <div class="grid grid-cols-12 items-start gap-8">

                <!-- Section list -->
                <nav class="col-span-3 space-y-1 rounded-lg border border-gray-100 bg-white p-2 shadow-sm">
                    <?php foreach ($tabs as $key => [$label, $hint]): $on = $key === $tab; ?>
                        <a href="settings.php?tab=<?= $key ?>" class="block rounded-md border-l-4 px-4 py-3 transition <?= $on ? 'border-figmaBlue bg-blue-50' : 'border-transparent hover:bg-gray-50' ?>">
                            <div class="text-sm font-bold <?= $on ? 'text-figmaBlue' : 'text-gray-800' ?>"><?= $label ?></div>
                            <div class="text-xs text-gray-400"><?= $hint ?></div>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="col-span-9 space-y-6">

                <?php if ($tab === 'account'): ?>
                    <form method="POST" action="settings.php?tab=account" class="<?= $card ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_profile">
                        <h3 class="text-lg font-bold text-gray-800">Profile</h3>
                        <p class="mb-5 text-sm text-gray-500">Shown in the sidebar. Your username is what you type to sign in.</p>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="<?= $L ?>" for="display_name">DISPLAY NAME</label><input id="display_name" name="display_name" value="<?= e($me['display_name']) ?>" maxlength="100" placeholder="e.g. Maria Santos" class="<?= $I ?>"></div>
                            <div><label class="<?= $L ?>" for="username">USERNAME *</label><input id="username" name="username" value="<?= e($me['username']) ?>" maxlength="50" required class="<?= $I ?>"></div>
                        </div>
                        <div class="mt-5 flex justify-end border-t pt-4"><button class="<?= $saveBtn ?>">SAVE PROFILE</button></div>
                    </form>

                    <form method="POST" action="settings.php?tab=account" class="<?= $card ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_password">
                        <h3 class="text-lg font-bold text-gray-800">Change password</h3>
                        <p class="mb-5 text-sm text-gray-500">Use at least 8 characters.</p>
                        <div class="grid grid-cols-3 gap-4">
                            <div><label class="<?= $L ?>" for="cp1">CURRENT PASSWORD</label><input id="cp1" type="password" name="current_password" autocomplete="current-password" required class="<?= $I ?>"></div>
                            <div><label class="<?= $L ?>" for="cp2">NEW PASSWORD</label><input id="cp2" type="password" name="new_password" minlength="8" autocomplete="new-password" required class="<?= $I ?>"></div>
                            <div><label class="<?= $L ?>" for="cp3">CONFIRM NEW PASSWORD</label><input id="cp3" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required class="<?= $I ?>"></div>
                        </div>
                        <div class="mt-5 flex justify-end border-t pt-4"><button class="<?= $saveBtn ?>">CHANGE PASSWORD</button></div>
                    </form>

                <?php elseif ($tab === 'departments'): ?>
                    <div class="<?= $card ?>">
                        <h3 class="text-lg font-bold text-gray-800">Departments</h3>
                        <p class="mb-5 text-sm text-gray-500">These appear in the department list on the Add Intern and Register forms.</p>

                        <form method="POST" action="settings.php?tab=departments" class="mb-6 flex gap-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_department">
                            <input name="name" maxlength="50" required placeholder="New department name" aria-label="New department name" class="<?= $I ?>">
                            <button class="<?= $saveBtn ?> whitespace-nowrap">+ ADD</button>
                        </form>

                        <div class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                            <?php foreach ($departments as $d): $n = $usage[mb_strtolower($d['name'])] ?? 0; ?>
                            <div class="flex items-center gap-3 p-3">
                                <form method="POST" action="settings.php?tab=departments" class="flex flex-1 items-center gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rename_department">
                                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                    <input name="name" value="<?= e($d['name']) ?>" maxlength="50" required aria-label="Department name" class="<?= $I ?> max-w-xs">
                                    <button class="rounded border border-gray-200 px-3 py-2 text-xs font-bold text-figmaBlue hover:bg-gray-50">RENAME</button>
                                </form>
                                <span class="w-24 text-right text-xs text-gray-400"><?= $n ?> intern<?= $n === 1 ? '' : 's' ?></span>
                                <form method="POST" action="settings.php?tab=departments" data-confirm="<?= e($d['name']) ?> will be removed from the department list." data-confirm-title="Remove department?" data-confirm-ok="Remove">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_department">
                                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                    <button <?= $n > 0 ? 'disabled title="Still assigned to interns"' : '' ?> class="rounded border border-red-200 px-3 py-2 text-xs font-bold text-red-500 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-30">REMOVE</button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                            <?php if (!$departments): ?><div class="p-6 text-center text-sm text-gray-400">No departments yet. Until you add some, forms use a free-text department field.</div><?php endif; ?>
                        </div>
                        <p class="mt-3 text-xs text-gray-400">Renaming a department also updates the interns assigned to it. A department that is still in use can't be removed.</p>
                    </div>

                <?php elseif ($tab === 'registration'): ?>
                    <form method="POST" action="settings.php?tab=registration" class="<?= $card ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_registration">
                        <h3 class="text-lg font-bold text-gray-800">Intern registration</h3>
                        <p class="mb-2 text-sm text-gray-500">New OJTs register themselves from the link on the login page. Their records stay pending until you approve them on the Validation page.</p>

                        <div class="border-y border-gray-100">
                            <?php toggle('registration_open', 'Accept new registrations', 'When off, the Register page tells visitors that registration is closed.', $regOpen); ?>
                        </div>

                        <div class="mt-5">
                            <label class="<?= $L ?>" for="regUrl">REGISTER PAGE LINK (SHARE WITH NEW INTERNS)</label>
                            <div class="flex gap-2">
                                <input id="regUrl" readonly value="<?= e($regUrl) ?>" class="<?= $I ?> bg-gray-50" onclick="this.select()">
                                <button type="button" id="copyBtn" onclick="copyLink()" class="rounded border border-gray-200 px-4 text-xs font-bold text-figmaBlue hover:bg-gray-50">COPY</button>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center justify-between rounded-lg bg-gray-50 p-4 text-sm text-gray-600">
                            <span><b><?= $counts['Pending'] ?></b> submission<?= $counts['Pending'] === 1 ? '' : 's' ?> waiting for validation</span>
                            <a href="validation.php" class="text-xs font-bold text-figmaBlue hover:underline">Open Validation &rarr;</a>
                        </div>
                        <div class="mt-5 flex justify-end border-t pt-4"><button class="<?= $saveBtn ?>">SAVE</button></div>
                    </form>

                <?php elseif ($tab === 'data'): ?>
                    <div class="<?= $card ?>">
                        <h3 class="text-lg font-bold text-gray-800">Your records</h3>
                        <div class="mt-4 grid grid-cols-3 gap-4">
                            <?php foreach (['Approved' => 'Accepted', 'Pending' => 'Waiting for validation', 'Rejected' => 'Rejected'] as $k => $label): ?>
                                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4"><div class="text-2xl font-black text-gray-800"><?= $counts[$k] ?></div><div class="text-xs text-gray-500"><?= $label ?></div></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="<?= $card ?> flex items-center justify-between gap-6">
                        <div>
                            <h3 class="text-base font-bold text-gray-800">Export accepted records</h3>
                            <p class="text-sm text-gray-500">Downloads a CSV file (opens in Excel) with every accepted intern. Pending and rejected submissions are not included.</p>
                        </div>
                        <a href="settings.php?tab=data&amp;export=interns" class="<?= $saveBtn ?> shrink-0">DOWNLOAD CSV</a>
                    </div>

                    <form method="POST" action="settings.php?tab=data" data-confirm="This permanently deletes all rejected submissions. Accepted records are not touched." data-confirm-title="Delete all rejected records?" data-confirm-ok="Delete all" class="<?= $card ?> flex items-center justify-between gap-6 border-red-100">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="purge_rejected">
                        <div>
                            <h3 class="text-base font-bold text-gray-800">Delete rejected records</h3>
                            <p class="text-sm text-gray-500">Permanently removes the <?= $counts['Rejected'] ?> rejected submission<?= $counts['Rejected'] === 1 ? '' : 's' ?>. Accepted records are not touched.</p>
                        </div>
                        <button <?= $counts['Rejected'] === 0 ? 'disabled' : '' ?> class="shrink-0 rounded border border-red-300 px-5 py-2.5 text-xs font-bold text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40">DELETE ALL</button>
                    </form>
                <?php endif; ?>

                </div>
            </div>
        </div>
    </main>

<script src="app.js"></script>
<script>
    function copyLink() {
        const input = document.getElementById('regUrl'), btn = document.getElementById('copyBtn');
        const done = () => { btn.textContent = 'COPIED'; setTimeout(() => btn.textContent = 'COPY', 1500); };
        if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done); else { input.select(); document.execCommand('copy'); done(); }
    }
</script>
</body>
</html>