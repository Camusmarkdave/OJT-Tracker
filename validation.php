<?php
session_start();
require_once 'db.php';
require_login(); // Security check
require_permission('validation.view');

$canReview = can('validation.review');  // approve, reject, correct, return to pending (Admin and Super Admin)
$canDelete = can('validation.delete');  // permanently delete a submission (Super Admin only)

$tab = ($_GET['tab'] ?? 'pending') === 'rejected' ? 'rejected' : 'pending';
$reopen = null; // set when a correction fails validation, so the edit form re-opens with the errors

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id  = (int)($_POST['id'] ?? 0);
    $act = $_POST['action'] ?? '';

    // Delete is restricted to Super Admins; every other action requires review permission
    require_permission($act === 'delete' ? 'validation.delete' : 'validation.review');

    // Only records that have not yet been accepted can be handled here
    $st = $pdo->prepare("SELECT * FROM interns WHERE id = ? AND validation_status <> 'Approved'");
    $st->execute([$id]);
    $rec = $st->fetch();

    if (!$rec) {
        flash('error', 'The selected submission is no longer awaiting validation.');
        redirect('validation.php?tab=' . $tab);
    }
    $name = $rec['first_name'] . ' ' . $rec['last_name'];
    $approve = fn() => $pdo->prepare("UPDATE interns SET validation_status='Approved', validated_at=NOW(), rejection_reason=NULL WHERE id=?")->execute([$id]);

    if ($act === 'approve') {
        $issues = validate_intern($rec);
        if ($issues) {
            flash('error', "The submission from $name cannot be approved yet. " . implode(' ', $issues) . ' Please use Edit to correct the record first.');
        } else {
            $approve();
            audit_log($pdo, 'validation_approved', 'Approved the registration of ' . intern_summary($rec) . '.');
            flash('success', "The registration of $name was approved and added to Intern Records.");
        }

    } elseif ($act === 'reject') {
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);
        $pdo->prepare("UPDATE interns SET validation_status='Rejected', rejection_reason=?, validated_at=NOW() WHERE id=?")
            ->execute([$reason !== '' ? $reason : null, $id]);
        audit_log($pdo, 'validation_rejected', 'Rejected the registration of ' . intern_summary($rec) . '. Reason: ' . ($reason !== '' ? $reason : 'not provided') . '.');
        flash('success', "The registration of $name was rejected.");

    } elseif ($act === 'reopen') {
        $pdo->prepare("UPDATE interns SET validation_status='Pending', rejection_reason=NULL, validated_at=NULL WHERE id=?")->execute([$id]);
        audit_log($pdo, 'validation_reopened', 'Returned the registration of ' . intern_summary($rec) . ' to pending review.');
        flash('success', "The registration of $name was returned to pending review.");

    } elseif ($act === 'delete') {
        $pdo->prepare("DELETE FROM interns WHERE id = ? AND validation_status <> 'Approved'")->execute([$id]);
        audit_log($pdo, 'validation_deleted', 'Permanently deleted the submission of ' . intern_summary($rec) . '.');
        flash('success', "The submission from $name was permanently deleted.");

    } elseif ($act === 'edit') {
        $d = clean_intern_input($_POST);
        $errs = validate_intern($d);
        if ($errs) {
            $reopen = ['id' => $id, 'data' => $d, 'errors' => $errs];
        } else {
            update_intern($pdo, $id, $d);
            audit_log($pdo, 'validation_edited', 'Edited the submitted record of ' . trim($rec['first_name'] . ' ' . $rec['last_name']) . '. Changes: ' . intern_diff($rec, $d) . '.');
            if (($_POST['then'] ?? '') === 'approve') {
                $approve();
                audit_log($pdo, 'validation_approved', 'Approved the registration of ' . intern_summary($d) . ' after correcting it.');
                flash('success', "The changes were saved. The registration of $name was approved and added to Intern Records.");
            } else {
                flash('success', "The changes to the submission from $name were saved.");
            }
        }
    }
    if (!$reopen) { redirect('validation.php?tab=' . $tab); }
}

// Counts for the tabs
$counts = ['Pending' => 0, 'Rejected' => 0];
foreach ($pdo->query("SELECT validation_status, COUNT(*) AS c FROM interns WHERE validation_status IN ('Pending','Rejected') GROUP BY validation_status") as $r) {
    $counts[$r['validation_status']] = (int)$r['c'];
}

// Submissions for the current tab, with automated verification run on each one
$list = $pdo->prepare("SELECT * FROM interns WHERE validation_status = ? ORDER BY created_at DESC, id DESC");
$list->execute([$tab === 'rejected' ? 'Rejected' : 'Pending']);
$records = [];
foreach ($list->fetchAll() as $r) {
    $dup = find_duplicate($pdo, $r, (int)$r['id']);
    $r['issues']    = validate_intern($r);
    $r['duplicate'] = $dup ? ($dup['validation_status'] === 'Approved' ? 'an accepted record' : 'another pending submission') : null;
    $r['submitted'] = date('M j, Y g:i A', strtotime($r['created_at']));
    $r['reviewed']  = $r['validated_at'] ? date('M j, Y g:i A', strtotime($r['validated_at'])) : null;
    $records[(int)$r['id']] = $r;
}

$tabCls = fn($on) => $on ? 'bg-figmaBlue text-white' : 'bg-white text-gray-500 hover:bg-gray-50';
$formAction = 'validation.php?tab=' . $tab;

page_start($pdo, 'Validation', 'validation');
?>

        <!-- Header -->
        <header class="h-20 bg-white px-8 flex justify-between items-center border-b-2 border-blue-400 shadow-sm shrink-0">
            <div>
                <div class="text-xs text-gray-400 font-medium mb-1">Validation <span class="mx-1">></span> <span class="text-gray-800">Submitted Registrations</span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">RECORD VALIDATION</h2>
            </div>
            <div class="flex items-center gap-3">
                <!-- Visible whenever search text is applied -->
                <button type="button" id="clearSearchBtn" onclick="clearSearch()" title="Remove the search filter and show all submissions"
                    class="hidden inline-flex items-center gap-2 whitespace-nowrap rounded-md border border-red-300 bg-red-50 px-4 py-2 text-xs font-bold text-red-700 shadow-sm transition hover:bg-red-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-1">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    CLEAR FILTERS
                </button>
                <input type="text" id="searchInput" placeholder="Search submissions" aria-label="Search submissions" class="bg-gray-50 border border-gray-200 text-sm rounded-md px-4 py-2 w-64 focus:outline-none focus:ring-1 focus:ring-figmaBlue">
            </div>
        </header>

        <div class="p-8 overflow-y-auto flex-1">

            <?php echo flash_html(); ?>

            <div class="mb-6 flex items-start gap-3 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-figmaBlue" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <div>Registrations submitted through the public Registration page are held here for review. <b>Submitted records are excluded from Intern Records, Reports, and the school filter until they are approved.</b> Select a name to review the complete details.</div>
            </div>

            <div class="mb-4 flex border border-gray-200 rounded-md overflow-hidden text-xs font-bold shadow-sm w-fit">
                <a href="validation.php?tab=pending" class="px-5 py-2.5 <?php echo $tabCls($tab === 'pending'); ?>">PENDING REVIEW (<?php echo $counts['Pending']; ?>)</a>
                <a href="validation.php?tab=rejected" class="px-5 py-2.5 border-l border-gray-200 <?php echo $tabCls($tab === 'rejected'); ?>">REJECTED (<?php echo $counts['Rejected']; ?>)</a>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-x-auto">
                <?php if (!$records): ?>
                    <div class="p-14 text-center">
                        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-green-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        <div class="text-sm font-bold text-gray-800"><?php echo $tab === 'pending' ? 'No Submissions Pending Review' : 'No Rejected Submissions'; ?></div>
                        <div class="mt-1 text-sm text-gray-400"><?php echo $tab === 'pending' ? 'New registrations will appear here for review.' : 'Submissions that have been rejected will be listed here.'; ?></div>
                    </div>
                <?php else: ?>
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                            <th class="p-4">Intern</th>
                            <th class="p-4">School</th>
                            <th class="p-4">Department</th>
                            <th class="p-4">Date Submitted</th>
                            <th class="p-4"><?php echo $tab === 'pending' ? 'Verification' : 'Reason for Rejection'; ?></th>
                            <th class="p-4 text-right"></th>
                        </tr>
                    </thead>
                    <tbody id="submissionBody" class="divide-y divide-gray-100">
                        <?php foreach ($records as $id => $r): $n = count($r['issues']); ?>
                        <tr onclick="openReview(<?php echo $id; ?>)" class="cursor-pointer hover:bg-blue-50/50 transition">
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-9 w-9 rounded-md bg-figmaBlue text-white flex items-center justify-center font-bold text-xs"><?php echo e(strtoupper(mb_substr($r['first_name'], 0, 1) . mb_substr($r['last_name'], 0, 1))); ?></div>
                                    <div>
                                        <div class="text-sm font-bold text-figmaBlue"><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo e($r['course'] ?: 'Course not specified'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 text-sm text-gray-600"><?php echo e($r['school'] ?: '-'); ?></td>
                            <td class="p-4 text-sm text-gray-600"><?php echo e($r['department']); ?></td>
                            <td class="p-4 text-xs text-gray-500"><?php echo e($r['submitted']); ?></td>
                            <td class="p-4">
                                <?php if ($tab === 'rejected'): ?>
                                    <span class="text-xs text-gray-500"><?php echo e($r['rejection_reason'] ?: 'No reason provided'); ?></span>
                                <?php elseif ($n): ?>
                                    <span class="rounded-full bg-red-100 px-2.5 py-0.5 text-[10px] font-bold uppercase text-red-600"><?php echo $n; ?> issue<?php echo $n === 1 ? '' : 's'; ?> found</span>
                                <?php elseif ($r['duplicate']): ?>
                                    <span class="rounded-full bg-yellow-100 px-2.5 py-0.5 text-[10px] font-bold uppercase text-yellow-700">Possible duplicate</span>
                                <?php else: ?>
                                    <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-[10px] font-bold uppercase text-green-700">No issues found</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-right"><span class="text-xs font-bold text-figmaBlue">Review &rarr;</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </main>

<!-- Review modal: complete details first, then Approve / Reject / Edit -->
<div id="reviewModal" data-modal class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl relative overflow-y-auto max-h-[92vh]">

        <!-- Details view -->
        <div id="viewPane">
            <div class="flex items-start justify-between gap-4 border-b p-6">
                <div class="flex items-center gap-4">
                    <div id="rvAvatar" class="h-14 w-14 rounded-md bg-figmaBlue text-white flex items-center justify-center text-lg font-bold"></div>
                    <div>
                        <h3 id="rvName" class="text-xl font-black text-gray-900"></h3>
                        <div id="rvMeta" class="text-xs text-gray-500"></div>
                    </div>
                </div>
                <button type="button" onclick="closeReview()" aria-label="Close" class="text-2xl leading-none text-gray-400 hover:text-gray-600">&times;</button>
            </div>

            <div class="space-y-6 p-6">
                <div id="rvChecks" class="space-y-2"></div>
                <dl id="rvDetails" class="grid grid-cols-2 gap-x-6 gap-y-4"></dl>
                <div id="rvReason" class="hidden rounded border-l-4 border-red-400 bg-red-50 p-3 text-sm text-red-700"></div>
            </div>

            <?php if ($canReview): ?>
            <!-- Reject: an optional reason is requested first -->
            <form id="rejectBox" method="POST" action="<?php echo e($formAction); ?>" class="hidden border-t bg-red-50 p-6">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" class="rid">
                <label class="block text-xs font-bold text-red-800 mb-1" for="rejectReason">REASON FOR REJECTION (OPTIONAL)</label>
                <textarea id="rejectReason" name="reason" rows="2" maxlength="500" placeholder="e.g. The school name is incomplete. Please submit a corrected registration." class="w-full rounded border border-red-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-red-400"></textarea>
                <div class="mt-3 flex justify-end gap-2">
                    <button type="button" onclick="hideReject()" class="rounded border border-gray-300 bg-white px-4 py-2 text-xs font-bold text-gray-600 hover:bg-gray-50">CANCEL</button>
                    <button type="submit" class="rounded bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700">&#10005; CONFIRM REJECTION</button>
                </div>
            </form>
            <?php endif; ?>

            <div id="viewFooter" class="flex items-center justify-between gap-3 border-t bg-gray-50 p-4">
                <?php if ($canReview): ?>
                <!-- Pending submissions -->
                <div data-for="Pending" class="flex w-full items-center justify-between gap-3">
                    <div class="flex gap-2">
                        <button type="button" onclick="showReject()" class="rounded border border-red-300 bg-white px-4 py-2 text-xs font-bold text-red-600 hover:bg-red-50">&#10005; REJECT</button>
                        <button type="button" onclick="showEdit()" class="rounded border border-figmaBlue bg-white px-4 py-2 text-xs font-bold text-figmaBlue hover:bg-blue-50">&#9998; EDIT</button>
                    </div>
                    <form method="POST" action="<?php echo e($formAction); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" class="rid">
                        <button type="submit" id="approveBtn" class="rounded bg-[#0fb871] px-5 py-2 text-xs font-bold text-white hover:bg-green-600 disabled:cursor-not-allowed disabled:opacity-40">&#10003; APPROVE</button>
                    </form>
                </div>
                <!-- Rejected submissions -->
                <div data-for="Rejected" class="flex w-full items-center justify-between gap-3">
                    <?php if ($canDelete): ?>
                    <form method="POST" action="<?php echo e($formAction); ?>" data-confirm="This will permanently delete the submission. This action cannot be undone." data-confirm-title="Delete Submission?" data-confirm-ok="Delete Submission">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" class="rid">
                        <button type="submit" class="rounded border border-red-300 bg-white px-4 py-2 text-xs font-bold text-red-600 hover:bg-red-50">DELETE</button>
                    </form>
                    <?php else: ?><span></span><?php endif; ?>
                    <div class="flex gap-2">
                        <button type="button" onclick="showEdit()" class="rounded border border-figmaBlue bg-white px-4 py-2 text-xs font-bold text-figmaBlue hover:bg-blue-50">&#9998; EDIT</button>
                        <form method="POST" action="<?php echo e($formAction); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="reopen">
                            <input type="hidden" name="id" class="rid">
                            <button type="submit" class="rounded bg-figmaBlue px-4 py-2 text-xs font-bold text-white hover:bg-blue-900">RETURN TO PENDING</button>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <span class="text-xs text-gray-500">You do not have permission to review submissions.</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canReview): ?>
        <!-- Edit view -->
        <form id="editPane" method="POST" action="<?php echo e($formAction); ?>" <?php echo form_attrs(); ?> class="hidden">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" class="rid">
            <div class="flex items-center justify-between border-b p-6">
                <h3 class="text-lg font-bold text-gray-800">Edit Submitted Record</h3>
                <button type="button" onclick="closeReview()" aria-label="Close" class="text-2xl leading-none text-gray-400 hover:text-gray-600">&times;</button>
            </div>
            <div class="space-y-4 p-6">
                <?php intern_fields($pdo, 'rv_', $reopen['data'] ?? [], ['errors' => $reopen['errors'] ?? []]); ?>
            </div>
            <div class="flex items-center justify-between gap-3 border-t bg-gray-50 p-4">
                <button type="button" onclick="showView()" class="text-xs font-bold text-gray-500 hover:text-figmaBlue">&larr; Back to details</button>
                <div class="flex gap-2">
                    <button type="submit" class="rounded border border-figmaBlue bg-white px-4 py-2 text-xs font-bold text-figmaBlue hover:bg-blue-50">SAVE CHANGES</button>
                    <button type="submit" name="then" value="approve" class="rounded bg-[#0fb871] px-4 py-2 text-xs font-bold text-white hover:bg-green-600">&#10003; SAVE AND APPROVE</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script src="app.js"></script>
<script>
    const RECORDS = <?php echo json_safe($records); ?>;
    const CAN_REVIEW = <?php echo $canReview ? 'true' : 'false'; ?>;
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = v => v ? fmtDate(v) : '-';
    let current = null;

    function duration(r) {
        if (!r.start_date || !r.end_date || r.end_date < r.start_date) return '-';
        const days = Math.round((new Date(r.end_date) - new Date(r.start_date)) / 864e5) + 1;
        return days + ' day' + (days === 1 ? '' : 's') + ' (approximately ' + (days / 7).toFixed(1) + ' weeks)';
    }

    function openReview(id) {
        const r = RECORDS[id];
        if (!r) return;
        current = r;
        document.querySelectorAll('.rid').forEach(i => i.value = id);
        $('rvAvatar').textContent = (r.first_name.charAt(0) + r.last_name.charAt(0)).toUpperCase();
        $('rvName').textContent = r.first_name + ' ' + r.last_name;
        $('rvMeta').textContent = 'Submitted ' + r.submitted + (r.reviewed ? ' | Reviewed ' + r.reviewed : '');

        // Automated verification, so problems are visible at a glance
        const items = r.issues.length ? r.issues.map(t => ['bad', t]) : [['ok', 'All required information is complete and the dates are in a valid sequence.']];
        if (r.duplicate) items.push(['warn', 'A record with the same name and batch year already exists (' + r.duplicate + '). Please confirm that this is not a duplicate submission.']);
        const style = { ok: ['bg-green-50 text-green-700', '&#10003;'], bad: ['bg-red-50 text-red-700', '&#10005;'], warn: ['bg-yellow-50 text-yellow-800', '!'] };
        $('rvChecks').innerHTML = '<div class="text-xs font-bold text-gray-500">AUTOMATED VERIFICATION</div>' +
            items.map(([k, t]) => `<div class="flex items-start gap-2 rounded px-3 py-2 text-sm ${style[k][0]}"><span class="font-black">${style[k][1]}</span><span>${esc(t)}</span></div>`).join('');

        const fields = [
            ['School', r.school], ['Course', r.course], ['Department', r.department], ['Batch Year', r.batch_year],
            ['Status', r.status], ['Internship Duration', duration(r)],
            ['Start Date', fmt(r.start_date)], ['End Date', fmt(r.end_date)],
        ];
        $('rvDetails').innerHTML = fields.map(([k, v]) =>
            `<div><dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">${k}</dt><dd class="mt-0.5 text-sm font-semibold text-gray-800">${esc(v) || '-'}</dd></div>`).join('');

        const reason = $('rvReason');
        reason.classList.toggle('hidden', !(r.validation_status === 'Rejected'));
        reason.textContent = 'Reason for rejection: ' + (r.rejection_reason || 'No reason provided.');

        if (CAN_REVIEW) {
            $('approveBtn').disabled = r.issues.length > 0;
            $('approveBtn').title = r.issues.length ? 'Correct the issues using Edit before approving' : '';
            document.querySelectorAll('[data-for]').forEach(el => el.style.display = el.dataset.for === r.validation_status ? '' : 'none');
            hideReject();
        }
        showView();
        $('reviewModal').classList.remove('hidden');
    }

    function closeReview() { $('reviewModal').classList.add('hidden'); }
    function showView() { if (CAN_REVIEW) $('editPane').classList.add('hidden'); $('viewPane').classList.remove('hidden'); }
    function showEdit(data, keepErrors) {
        fillForm('rv_', data || current);
        if (!keepErrors) $('editPane').querySelectorAll('[data-server-errors]').forEach(el => el.classList.add('hidden'));
        $('viewPane').classList.add('hidden');
        $('editPane').classList.remove('hidden');
    }
    function showReject() { $('rejectBox').classList.remove('hidden'); $('rejectReason').focus(); }
    function hideReject() { $('rejectBox').classList.add('hidden'); $('rejectReason').value = ''; }

    // Live search over the submissions list, with a visible Clear Filters button
    function applySearch() {
        const q = $('searchInput').value.trim().toLowerCase();
        document.querySelectorAll('#submissionBody tr').forEach(tr => tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none');
        $('clearSearchBtn').classList.toggle('hidden', q === '');
    }
    function clearSearch() { $('searchInput').value = ''; applySearch(); $('searchInput').focus(); }
    $('searchInput').addEventListener('input', applySearch);

    <?php if ($reopen): ?>
    document.addEventListener('DOMContentLoaded', () => {
        openReview(<?php echo (int)$reopen['id']; ?>);
        showEdit(<?php echo json_safe($reopen['data']); ?>, true); // re-open the edit form with the server's error message
    });
    <?php endif; ?>
</script>

</body>
</html>