<?php
session_start();
require_once 'db.php';
require_login(); // Security check
require_permission('account.manage_own');

$me  = current_user();
$uid = (int)$me['id'];

// Every user manages their own account. Super Admins can also control intern registration.
$tabs = ['account' => ['My Account', 'Profile and password']];
if (can('registration.manage')) { $tabs['registration'] = ['Registration', 'Control intern self-registration']; }
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'account';

/* ---------- Saving ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['action'] ?? '';

    if ($act === 'save_profile') {
        require_permission('account.manage_own');
        $display = mb_substr(trim($_POST['display_name'] ?? ''), 0, 100);
        $user    = trim($_POST['username'] ?? '');
        $taken   = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?");
        $taken->execute([$user, $uid]);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $user)) {
            flash('error', 'The username must contain 3 to 50 characters, using only letters, numbers, periods, hyphens, and underscores.');
        } elseif ($taken->fetchColumn()) {
            flash('error', 'That username is already in use. Please choose a different username.');
        } else {
            $changes = [];
            if ($user !== $me['username']) { $changes[] = "Username: {$me['username']} → $user"; }
            if ($display !== (string)$me['display_name']) { $changes[] = 'Display name: ' . ($me['display_name'] ?: '(blank)') . ' → ' . ($display !== '' ? $display : '(blank)'); }
            $pdo->prepare("UPDATE users SET username = ?, display_name = ? WHERE id = ?")->execute([$user, $display !== '' ? $display : null, $uid]);
            $_SESSION['username'] = $user;
            audit_log($pdo, 'profile_updated', 'Updated own profile. ' . ($changes ? 'Changes: ' . implode('; ', $changes) . '.' : 'No fields were changed.'));
            flash('success', 'Your profile was updated successfully.');
        }

    } elseif ($act === 'change_password') {
        require_permission('account.manage_own');
        $cur  = trim($_POST['current_password'] ?? '');
        $new  = trim($_POST['new_password'] ?? '');
        $conf = trim($_POST['confirm_password'] ?? '');
        $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([$uid]);
        if (!password_verify($cur, (string)$st->fetchColumn())) {
            flash('error', 'The current password you entered is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'The new password must contain at least 8 characters.');
        } elseif ($new !== $conf) {
            flash('error', 'The new password and its confirmation do not match.');
        } elseif ($new === $cur) {
            flash('error', 'The new password must be different from the current password.');
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            audit_log($pdo, 'password_changed', 'Changed own password.');
            flash('success', 'Your password was changed successfully.');
        }

    } elseif ($act === 'save_registration') {
        require_permission('registration.manage');
        $open = isset($_POST['registration_open']);
        save_setting($pdo, 'registration_open', $open ? '1' : '0');
        audit_log($pdo, 'registration_toggled', 'Intern registration was set to ' . ($open ? 'open' : 'closed') . '.');
        flash('success', $open ? 'Intern registration is now open.' : 'Intern registration is now closed. The Registration page will display a closed notice.');
    }
    redirect('settings.php?tab=' . $tab);
}

/* ---------- Data for display ---------- */
$pendingCount = pending_count($pdo);
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
            <div class="flex items-center gap-2 text-xs text-gray-500">Signed in as <?= role_badge($me['role']) ?></div>
        </header>

        <div class="p-8 overflow-y-auto flex-1">
            <?= flash_html() ?>

            <div class="grid grid-cols-12 items-start gap-8">

                <?php if (count($tabs) > 1): ?>
                <!-- Section list -->
                <nav class="col-span-3 space-y-1 rounded-lg border border-gray-100 bg-white p-2 shadow-sm">
                    <?php foreach ($tabs as $key => [$label, $hint]): $on = $key === $tab; ?>
                        <a href="settings.php?tab=<?= $key ?>" class="block rounded-md border-l-4 px-4 py-3 transition <?= $on ? 'border-figmaBlue bg-blue-50' : 'border-transparent hover:bg-gray-50' ?>">
                            <div class="text-sm font-bold <?= $on ? 'text-figmaBlue' : 'text-gray-800' ?>"><?= $label ?></div>
                            <div class="text-xs text-gray-400"><?= $hint ?></div>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>

                <div class="<?= count($tabs) > 1 ? 'col-span-9' : 'col-span-12 max-w-4xl' ?> space-y-6">

                <?php if ($tab === 'account'): ?>
                    <?php if (!can('registration.manage')): ?>
                    <div class="flex items-start gap-3 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-figmaBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <div>Your account has the <b>Admin</b> role. You can manage your own profile and password here. Additional system settings are available to Super Admins only.</div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="settings.php?tab=account" class="<?= $card ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_profile">
                        <h3 class="text-lg font-bold text-gray-800">Profile Information</h3>
                        <p class="mb-5 text-sm text-gray-500">The display name appears in the sidebar. Your username is used to sign in.</p>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="<?= $L ?>" for="display_name">DISPLAY NAME</label><input id="display_name" name="display_name" value="<?= e($me['display_name']) ?>" maxlength="100" placeholder="e.g. Maria Santos" class="<?= $I ?>"></div>
                            <div><label class="<?= $L ?>" for="username">USERNAME *</label><input id="username" name="username" value="<?= e($me['username']) ?>" maxlength="50" required class="<?= $I ?>"></div>
                        </div>
                        <div class="mt-5 flex justify-end border-t pt-4"><button class="<?= $saveBtn ?>">SAVE PROFILE</button></div>
                    </form>

                    <form method="POST" action="settings.php?tab=account" class="<?= $card ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_password">
                        <h3 class="text-lg font-bold text-gray-800">Change Password</h3>
                        <p class="mb-5 text-sm text-gray-500">The new password must contain at least 8 characters.</p>
                        <div class="grid grid-cols-3 gap-4">
                            <div><label class="<?= $L ?>" for="cp1">CURRENT PASSWORD</label><input id="cp1" type="password" name="current_password" autocomplete="current-password" required class="<?= $I ?>"></div>
                            <div><label class="<?= $L ?>" for="cp2">NEW PASSWORD</label><input id="cp2" type="password" name="new_password" minlength="8" autocomplete="new-password" required class="<?= $I ?>"></div>
                            <div><label class="<?= $L ?>" for="cp3">CONFIRM NEW PASSWORD</label><input id="cp3" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required class="<?= $I ?>"></div>
                        </div>
                        <div class="mt-5 flex justify-end border-t pt-4"><button class="<?= $saveBtn ?>">CHANGE PASSWORD</button></div>
                    </form>

                <?php elseif ($tab === 'registration'): ?>
                    <form method="POST" action="settings.php?tab=registration" class="<?= $card ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_registration">
                        <h3 class="text-lg font-bold text-gray-800">Intern Registration</h3>
                        <p class="mb-2 text-sm text-gray-500">New interns register through the link on the sign-in page. Their submissions remain pending until an administrator approves them on the Validation page.</p>

                        <div class="border-y border-gray-100">
                            <?php toggle('registration_open', 'Accept new registrations', 'When disabled, the public Registration page displays a notice that registration is closed.', $regOpen); ?>
                        </div>

                        <div class="mt-5">
                            <label class="<?= $L ?>" for="regUrl">REGISTRATION PAGE LINK (SHARE WITH NEW INTERNS)</label>
                            <div class="flex gap-2">
                                <input id="regUrl" readonly value="<?= e($regUrl) ?>" class="<?= $I ?> bg-gray-50" onclick="this.select()">
                                <button type="button" id="copyBtn" onclick="copyLink()" class="rounded border border-gray-200 px-4 text-xs font-bold text-figmaBlue hover:bg-gray-50">COPY</button>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center justify-between rounded-lg bg-gray-50 p-4 text-sm text-gray-600">
                            <span><b><?= $pendingCount ?></b> submission<?= $pendingCount === 1 ? '' : 's' ?> pending review</span>
                            <a href="validation.php" class="text-xs font-bold text-figmaBlue hover:underline">Go to Validation &rarr;</a>
                        </div>
                        <div class="mt-5 flex justify-end border-t pt-4"><button class="<?= $saveBtn ?>">SAVE SETTINGS</button></div>
                    </form>
                <?php endif; ?>

                </div>
            </div>
        </div>
    </main>

<script>
    function copyLink() {
        const input = document.getElementById('regUrl'), btn = document.getElementById('copyBtn');
        const done = () => { btn.textContent = 'COPIED'; setTimeout(() => btn.textContent = 'COPY', 1500); };
        if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done); else { input.select(); document.execCommand('copy'); done(); }
    }
</script>
</body>
</html>