<?php
session_start();
require_once 'db.php';
require_login(); // Security check
require_permission('users.manage'); // Super Admin only

$me = current_user();
$createErrors = [];
$old = [];
$openModal = '';

/** Active Super Admin accounts other than the given one (at least one must always remain). */
function other_active_supers(PDO $pdo, int $excludeId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE `role` = 'super_admin' AND is_active = 1 AND id <> ?");
    $st->execute([$excludeId]);
    return (int)$st->fetchColumn();
}

function find_user(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT id, username, display_name, `role`, is_active FROM users WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['action'] ?? '';

    /* ---------- Create account ---------- */
    if ($act === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $display  = mb_substr(trim($_POST['display_name'] ?? ''), 0, 100);
        $role     = $_POST['role'] ?? ROLE_ADMIN;
        $pw       = trim($_POST['password'] ?? '');
        $pw2      = trim($_POST['confirm_password'] ?? '');

        $exists = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $exists->execute([$username]);

        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $createErrors[] = 'The username must contain 3 to 50 characters, using only letters, numbers, periods, hyphens, and underscores.';
        } elseif ($exists->fetchColumn()) {
            $createErrors[] = 'That username is already in use. Please choose a different username.';
        }
        if (!isset(ROLE_LABELS[$role])) { $createErrors[] = 'Please select a valid role.'; }
        if (strlen($pw) < 8) { $createErrors[] = 'The password must contain at least 8 characters.'; }
        elseif ($pw !== $pw2) { $createErrors[] = 'The password and its confirmation do not match.'; }

        if (!$createErrors) {
            $pdo->prepare("INSERT INTO users (username, password_hash, display_name, `role`, is_active) VALUES (?,?,?,?,1)")
                ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $display !== '' ? $display : null, $role]);
            audit_log($pdo, 'user_created', "Created the account \"$username\" with the role " . role_label($role) . '.');
            flash('success', "The account \"$username\" was created with the role " . role_label($role) . '.');
            redirect('accounts.php');
        }
        $old = ['username' => $username, 'display_name' => $display, 'role' => $role];
        $openModal = 'create';

    /* ---------- Update account (name, role, status) ---------- */
    } elseif ($act === 'update_user') {
        $target = find_user($pdo, (int)($_POST['id'] ?? 0));
        if (!$target) {
            flash('error', 'The selected account no longer exists.');
        } else {
            $isSelf  = (int)$target['id'] === (int)$me['id'];
            $display = mb_substr(trim($_POST['display_name'] ?? ''), 0, 100);
            $role    = $_POST['role'] ?? $target['role'];
            $active  = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;
            if ($isSelf) { $role = $target['role']; $active = 1; } // you cannot change your own role or status

            if (!isset(ROLE_LABELS[$role])) {
                flash('error', 'Please select a valid role.');
            } elseif ($target['role'] === ROLE_SUPER && (int)$target['is_active'] === 1 && ($role !== ROLE_SUPER || !$active)
                      && other_active_supers($pdo, (int)$target['id']) === 0) {
                flash('error', 'At least one active Super Admin account is required. Assign the Super Admin role to another account first.');
            } else {
                $changes = [];
                if ($display !== (string)$target['display_name']) { $changes[] = 'Display name: ' . ($target['display_name'] ?: '(blank)') . ' → ' . ($display !== '' ? $display : '(blank)'); }
                if ($role !== $target['role']) { $changes[] = 'Role: ' . role_label($target['role']) . ' → ' . role_label($role); }
                if ($active !== (int)$target['is_active']) { $changes[] = 'Status: ' . ((int)$target['is_active'] ? 'Active' : 'Deactivated') . ' → ' . ($active ? 'Active' : 'Deactivated'); }

                $pdo->prepare("UPDATE users SET display_name = ?, `role` = ?, is_active = ? WHERE id = ?")
                    ->execute([$display !== '' ? $display : null, $role, $active, $target['id']]);
                audit_log($pdo, 'user_updated', "Updated the account \"{$target['username']}\". " . ($changes ? 'Changes: ' . implode('; ', $changes) . '.' : 'No fields were changed.'));
                flash('success', "The account \"{$target['username']}\" was updated successfully.");
            }
        }
        redirect('accounts.php');

    /* ---------- Reset password ---------- */
    } elseif ($act === 'reset_password') {
        $target = find_user($pdo, (int)($_POST['id'] ?? 0));
        $pw  = trim($_POST['password'] ?? '');
        $pw2 = trim($_POST['confirm_password'] ?? '');
        if (!$target) {
            flash('error', 'The selected account no longer exists.');
        } elseif (strlen($pw) < 8) {
            flash('error', 'The new password must contain at least 8 characters.');
        } elseif ($pw !== $pw2) {
            flash('error', 'The new password and its confirmation do not match.');
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $target['id']]);
            audit_log($pdo, 'user_password_reset', "Reset the password of the account \"{$target['username']}\".");
            flash('success', "The password for \"{$target['username']}\" was reset. Please share the new password with the account holder securely.");
        }
        redirect('accounts.php');

    /* ---------- Delete account ---------- */
    } elseif ($act === 'delete_user') {
        $target = find_user($pdo, (int)($_POST['id'] ?? 0));
        if (!$target) {
            flash('error', 'The selected account no longer exists.');
        } elseif ((int)$target['id'] === (int)$me['id']) {
            flash('error', 'You cannot delete your own account while signed in.');
        } elseif ($target['role'] === ROLE_SUPER && (int)$target['is_active'] === 1 && other_active_supers($pdo, (int)$target['id']) === 0) {
            flash('error', 'At least one active Super Admin account is required, so this account cannot be deleted.');
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target['id']]);
            audit_log($pdo, 'user_deleted', "Deleted the account \"{$target['username']}\" (" . role_label($target['role']) . ').');
            flash('success', "The account \"{$target['username']}\" was deleted. Its past activity remains in the audit log.");
        }
        redirect('accounts.php');
    }
}

$users = $pdo->query("SELECT id, username, display_name, `role`, is_active, last_login_at, created_at FROM users ORDER BY `role` = 'super_admin' DESC, username")->fetchAll();
$userData = [];
foreach ($users as $u) {
    $userData[(int)$u['id']] = ['id' => (int)$u['id'], 'username' => $u['username'], 'display_name' => (string)$u['display_name'],
                                'role' => $u['role'], 'is_active' => (int)$u['is_active']];
}

$I = INPUT_CLS; $L = LABEL_CLS;
$btnEdit = 'inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-figmaBlue shadow-sm transition hover:border-figmaBlue hover:bg-figmaBlue hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-figmaBlue focus-visible:ring-offset-1';
$btnDel  = 'inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 shadow-sm transition hover:border-red-600 hover:bg-red-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-1';

page_start($pdo, 'Admin Accounts', 'accounts');
?>

        <!-- Header -->
        <header class="h-20 bg-white px-8 flex justify-between items-center border-b-2 border-blue-400 shadow-sm shrink-0">
            <div>
                <div class="text-xs text-gray-400 font-medium mb-1">Administration <span class="mx-1">></span> <span class="text-gray-800">Admin Accounts</span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">ADMIN ACCOUNTS</h2>
            </div>
            <button onclick="openCreate()" class="bg-[#0fb871] text-white px-5 py-2.5 rounded font-bold text-sm flex items-center gap-2 hover:bg-green-600 transition shadow-sm">
                <span>+</span> ADD ACCOUNT
            </button>
        </header>

        <div class="p-8 overflow-y-auto flex-1 space-y-8">

            <div>
                <?php echo flash_html(); ?>

                <div class="mb-6 flex items-start gap-3 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-figmaBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div>Admin accounts are used for day-to-day intern record management. <b>Only Super Admins can access this page and the Audit Logs.</b> Deactivating an account prevents sign-in while preserving its activity history.</div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                <th class="p-4">Account</th>
                                <th class="p-4">Role</th>
                                <th class="p-4">Status</th>
                                <th class="p-4">Last Sign-in</th>
                                <th class="p-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($users as $u): $isSelf = (int)$u['id'] === (int)$me['id']; $label = $u['display_name'] ?: $u['username']; ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 rounded-md bg-figmaBlue text-white flex items-center justify-center font-bold text-sm"><?php echo e(strtoupper(mb_substr($label, 0, 2))); ?></div>
                                        <div>
                                            <div class="text-sm font-bold text-gray-900"><?php echo e($label); ?><?php if ($isSelf): ?> <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-gray-500">You</span><?php endif; ?></div>
                                            <div class="text-xs text-gray-400">@<?php echo e($u['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4"><?php echo role_badge($u['role']); ?></td>
                                <td class="p-4">
                                    <?php if ((int)$u['is_active']): ?>
                                        <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-green-700">Active</span>
                                    <?php else: ?>
                                        <span class="rounded-full bg-gray-200 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-gray-600">Deactivated</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-xs text-gray-500"><?php echo $u['last_login_at'] ? e(date('M j, Y g:i A', strtotime($u['last_login_at']))) : 'Never'; ?></td>
                                <td class="p-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" onclick="openEdit(<?php echo (int)$u['id']; ?>)" class="<?php echo $btnEdit; ?>" aria-label="Edit <?php echo e($label); ?>">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                            Edit
                                        </button>
                                        <button type="button" onclick="openReset(<?php echo (int)$u['id']; ?>)" class="<?php echo $btnEdit; ?>" aria-label="Reset password for <?php echo e($label); ?>">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                                            Reset Password
                                        </button>
                                        <?php if (!$isSelf): ?>
                                        <form method="POST" action="accounts.php" data-confirm="The account &quot;<?php echo e($u['username']); ?>&quot; will no longer be able to sign in. Its past activity will remain in the audit log." data-confirm-title="Delete Account?" data-confirm-ok="Delete Account">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                            <button type="submit" class="<?php echo $btnDel; ?>" aria-label="Delete <?php echo e($label); ?>">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                Delete
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Role permissions: a clear comparison of what each role may do -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-100">
                <div class="border-b border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800">Role Permissions</h3>
                    <p class="text-sm text-gray-500">A Super Admin can perform every function available to an Admin, in addition to the system administration functions listed below.</p>
                </div>
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                            <th class="p-4">Function</th>
                            <th class="p-4 text-center w-40"><?php echo role_badge(ROLE_ADMIN); ?></th>
                            <th class="p-4 text-center w-40"><?php echo role_badge(ROLE_SUPER); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (PERMISSION_GROUPS as $group => $perms): ?>
                            <tr class="bg-gray-50/70"><td colspan="3" class="px-4 py-2 text-[10px] font-bold uppercase tracking-wider text-gray-400"><?php echo e($group); ?></td></tr>
                            <?php foreach ($perms as $key => $label): ?>
                            <tr class="border-t border-gray-100">
                                <td class="px-4 py-3 text-sm text-gray-700"><?php echo e($label); ?></td>
                                <?php foreach ([ROLE_ADMIN, ROLE_SUPER] as $role): ?>
                                    <td class="px-4 py-3 text-center">
                                        <?php if (in_array($role, PERMISSIONS[$key], true)): ?>
                                            <span class="font-black text-green-600" title="Permitted">&#10003;</span>
                                        <?php else: ?>
                                            <span class="text-gray-300" title="Not permitted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

<!-- Create Account Modal -->
<div id="createModal" data-modal class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6 relative overflow-y-auto max-h-[90vh]">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-lg font-bold text-gray-800">Add Account</h3>
            <button onclick="closeModals()" aria-label="Close" class="text-gray-400 hover:text-gray-600 font-bold text-xl">&times;</button>
        </div>
        <form method="POST" action="accounts.php" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_user">
            <?php echo errors_box($createErrors); ?>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="<?php echo $L; ?>" for="c_username">USERNAME *</label><input id="c_username" name="username" value="<?php echo e($old['username'] ?? ''); ?>" maxlength="50" required autocomplete="off" class="<?php echo $I; ?>"></div>
                <div><label class="<?php echo $L; ?>" for="c_display">DISPLAY NAME</label><input id="c_display" name="display_name" value="<?php echo e($old['display_name'] ?? ''); ?>" maxlength="100" placeholder="e.g. Maria Santos" class="<?php echo $I; ?>"></div>
            </div>
            <div>
                <label class="<?php echo $L; ?>" for="c_role">ROLE *</label>
                <select id="c_role" name="role" class="<?php echo $I; ?>">
                    <?php foreach (ROLE_LABELS as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($old['role'] ?? ROLE_ADMIN) === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-[11px] text-gray-400">Admin: manages intern records and validation. Super Admin: full system access, including accounts and audit logs.</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="<?php echo $L; ?>" for="c_pw">PASSWORD *</label><input id="c_pw" type="password" name="password" minlength="8" required autocomplete="new-password" class="<?php echo $I; ?>"></div>
                <div><label class="<?php echo $L; ?>" for="c_pw2">CONFIRM PASSWORD *</label><input id="c_pw2" type="password" name="confirm_password" minlength="8" required autocomplete="new-password" class="<?php echo $I; ?>"></div>
            </div>
            <p class="text-[11px] text-gray-400">The password must contain at least 8 characters. Share it with the account holder securely and advise them to change it after signing in.</p>
            <div class="flex justify-end gap-3 border-t pt-4 mt-4">
                <button type="button" onclick="closeModals()" class="px-4 py-2 border rounded text-xs font-bold text-gray-600 hover:bg-gray-100">CANCEL</button>
                <button type="submit" class="px-4 py-2 bg-[#0fb871] text-white rounded text-xs font-bold hover:bg-green-600 transition">CREATE ACCOUNT</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Account Modal -->
<div id="editModal" data-modal class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6 relative overflow-y-auto max-h-[90vh]">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-lg font-bold text-gray-800">Edit Account <span id="e_title" class="font-normal text-gray-400"></span></h3>
            <button onclick="closeModals()" aria-label="Close" class="text-gray-400 hover:text-gray-600 font-bold text-xl">&times;</button>
        </div>
        <form method="POST" action="accounts.php" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="id" id="e_id">
            <div><label class="<?php echo $L; ?>" for="e_display">DISPLAY NAME</label><input id="e_display" name="display_name" maxlength="100" class="<?php echo $I; ?>"></div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="<?php echo $L; ?>" for="e_role">ROLE</label>
                    <select id="e_role" name="role" class="<?php echo $I; ?>">
                        <?php foreach (ROLE_LABELS as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo e($label); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="<?php echo $L; ?>" for="e_active">STATUS</label>
                    <select id="e_active" name="is_active" class="<?php echo $I; ?>">
                        <option value="1">Active</option>
                        <option value="0">Deactivated</option>
                    </select>
                </div>
            </div>
            <p id="e_self" class="hidden rounded bg-yellow-50 p-3 text-xs text-yellow-800">This is your own account. For security, you cannot change your own role or deactivate your own account.</p>
            <div class="flex justify-end gap-3 border-t pt-4 mt-4">
                <button type="button" onclick="closeModals()" class="px-4 py-2 border rounded text-xs font-bold text-gray-600 hover:bg-gray-100">CANCEL</button>
                <button type="submit" class="px-4 py-2 bg-figmaBlue text-white rounded text-xs font-bold hover:bg-blue-900 transition">SAVE CHANGES</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" data-modal class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-lg font-bold text-gray-800">Reset Password <span id="r_title" class="font-normal text-gray-400"></span></h3>
            <button onclick="closeModals()" aria-label="Close" class="text-gray-400 hover:text-gray-600 font-bold text-xl">&times;</button>
        </div>
        <form method="POST" action="accounts.php" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="r_id">
            <div><label class="<?php echo $L; ?>" for="r_pw">NEW PASSWORD *</label><input id="r_pw" type="password" name="password" minlength="8" required autocomplete="new-password" class="<?php echo $I; ?>"></div>
            <div><label class="<?php echo $L; ?>" for="r_pw2">CONFIRM NEW PASSWORD *</label><input id="r_pw2" type="password" name="confirm_password" minlength="8" required autocomplete="new-password" class="<?php echo $I; ?>"></div>
            <p class="text-[11px] text-gray-400">The password must contain at least 8 characters. The account holder will need the new password at their next sign-in.</p>
            <div class="flex justify-end gap-3 border-t pt-4 mt-4">
                <button type="button" onclick="closeModals()" class="px-4 py-2 border rounded text-xs font-bold text-gray-600 hover:bg-gray-100">CANCEL</button>
                <button type="submit" class="px-4 py-2 bg-figmaBlue text-white rounded text-xs font-bold hover:bg-blue-900 transition">RESET PASSWORD</button>
            </div>
        </form>
    </div>
</div>

<script src="app.js"></script>
<script>
    const USERS = <?php echo json_safe($userData); ?>;
    const ME_ID = <?php echo (int)$me['id']; ?>;
    const $ = id => document.getElementById(id);

    function closeModals() { document.querySelectorAll('[data-modal]').forEach(m => m.classList.add('hidden')); }
    function openCreate() { $('createModal').classList.remove('hidden'); $('c_username').focus(); }

    function openEdit(id) {
        const u = USERS[id]; if (!u) return;
        const self = id === ME_ID;
        $('e_id').value = id;
        $('e_title').textContent = '(@' + u.username + ')';
        $('e_display').value = u.display_name;
        $('e_role').value = u.role;
        $('e_active').value = String(u.is_active);
        $('e_role').disabled = self;      // disabled fields are not submitted; the server keeps the current values
        $('e_active').disabled = self;
        $('e_self').classList.toggle('hidden', !self);
        $('editModal').classList.remove('hidden');
    }

    function openReset(id) {
        const u = USERS[id]; if (!u) return;
        $('r_id').value = id;
        $('r_title').textContent = '(@' + u.username + ')';
        $('r_pw').value = ''; $('r_pw2').value = '';
        $('resetModal').classList.remove('hidden');
    }

    <?php if ($openModal === 'create'): ?>
    document.addEventListener('DOMContentLoaded', openCreate); // re-open with the server's error message
    <?php endif; ?>
</script>

</body>
</html>