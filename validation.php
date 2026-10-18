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
            $hasMissingDept = trim((string)($rec['department'] ?? '')) === '';
            $msg = $hasMissingDept
                ? "The submission from $name cannot be approved yet. Please use Edit to fill in the department and other required fields before approving."
                : "The submission from $name cannot be approved yet. " . implode(' ', $issues) . ' Please use Edit to correct the record first.';
            flash('error', $msg);
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
        delete_intern_resume($rec); // remove the physical file before deleting the row
        $pdo->prepare("DELETE FROM interns WHERE id = ? AND validation_status <> 'Approved'")->execute([$id]);
        audit_log($pdo, 'validation_deleted', 'Permanently deleted the submission of ' . intern_summary($rec) . '.');
        flash('success', "The submission from $name was permanently deleted.");

    } elseif ($act === 'edit') {
        $d = clean_intern_input($_POST);
        $errs = validate_intern($d);

        // Check if HR uploaded a resume file
        $hasResumeUpload = isset($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasResumeUpload && !$errs) {
            $resumeErr = save_intern_resume($pdo, $id, $_FILES['resume']);
            if ($resumeErr) {
                $errs[] = $resumeErr;
            }
        }

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

// Submissions for the current tab, with automated verification run on each one.
// resume_file is intentionally excluded from the JSON sent to the browser; only has_resume (a boolean flag) is exposed.
$list = $pdo->prepare("SELECT * FROM interns WHERE validation_status = ? ORDER BY created_at DESC, id DESC");
$list->execute([$tab === 'rejected' ? 'Rejected' : 'Pending']);
$records = [];
foreach ($list->fetchAll() as $r) {
    // Lazy resume-text extraction: attempt only when status is NULL (i.e. never tried yet).
    // 'done', 'empty', and 'failed' are all terminal states that must not be retried on every page load.
    if (!empty($r['resume_file']) && $r['resume_text_status'] === null) {
        $filePath = RESUME_UPLOAD_DIR . '/' . basename($r['resume_file']);
        if (is_file($filePath)) {
            try {
                $extracted = extract_pdf_text($filePath);
                if ($extracted !== null && trim($extracted) !== '') {
                    $r['resume_text']        = $extracted;
                    $r['resume_text_status'] = 'done';
                } else {
                    $r['resume_text']        = null;
                    $r['resume_text_status'] = 'empty';
                }
            } catch (\Throwable $ex) {
                error_log('InternTrack: PDF extraction failed for intern #' . $r['id'] . ': ' . $ex->getMessage());
                $r['resume_text']        = null;
                $r['resume_text_status'] = 'failed';
            }
            $pdo->prepare("UPDATE interns SET resume_text = ?, resume_text_status = ? WHERE id = ?")
                ->execute([$r['resume_text'], $r['resume_text_status'], $r['id']]);
        }
    }

    $dup = find_duplicate($pdo, $r, (int)$r['id']);
    $r['issues']    = validate_intern($r);
    $r['duplicate'] = $dup ? ($dup['validation_status'] === 'Approved' ? 'an accepted record' : 'another pending submission') : null;
    $r['submitted'] = date('M j, Y g:i A', strtotime($r['created_at']));
    $r['reviewed']  = $r['validated_at'] ? date('M j, Y g:i A', strtotime($r['validated_at'])) : null;
    $r['has_resume'] = !empty($r['resume_file']); // expose a boolean only — never the raw filename
    $r['resume_name'] = $r['resume_name'] ?? null; // sanitised display name is safe to show

    // Warning-only checks comparing typed fields with extracted resume text
    $r['resume_verification'] = verify_resume_matches($r['resume_text'] ?? null, $r);

    // Strip the stored filename and text so they are never sent to the browser
    unset($r['resume_file'], $r['resume_text'], $r['resume_text_status']);
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
                            <td class="p-4 text-sm text-gray-600"><?php echo e(trim((string)$r['department']) !== '' ? $r['department'] : 'Not yet assigned'); ?></td>
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

<!-- Review modal: widened on large screens to accommodate the PDF panel side-by-side with the details -->
<div id="reviewModal" data-modal class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl xl:max-w-5xl relative overflow-y-auto max-h-[92vh]">

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

            <!-- Two-column layout on XL screens: left = checks + details, right = PDF viewer -->
            <div class="xl:flex xl:divide-x xl:divide-gray-100">

                <!-- Left column: verification + field details -->
                <div class="xl:w-1/2 space-y-6 p-6">
                    <div id="rvChecks" class="space-y-2"></div>
                    <dl id="rvDetails" class="grid grid-cols-2 gap-x-6 gap-y-4"></dl>
                    <div id="rvReason" class="hidden rounded border-l-4 border-red-400 bg-red-50 p-3 text-sm text-red-700"></div>
                </div>

                <!-- Right column: resume panel -->
                <div class="xl:w-1/2 p-6 flex flex-col gap-3">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-wider">RESUME</div>
                    <!-- Shown when a resume exists -->
                    <div id="rvResumePanel" class="hidden flex-col gap-2 flex-1">
                        <div class="flex items-center justify-between">
                            <span id="rvResumeName" class="text-xs text-gray-500 truncate"></span>
                            <a id="rvResumeTab" href="#" target="_blank" rel="noopener"
                               class="ml-2 shrink-0 rounded border border-figmaBlue px-3 py-1 text-[10px] font-bold text-figmaBlue hover:bg-figmaBlue hover:text-white transition">
                                Open in new tab &nearr;
                            </a>
                        </div>
                        <iframe id="rvResumeFrame" src="" title="Intern resume"
                            class="w-full rounded border border-gray-200 bg-gray-50"
                            style="height:420px">
                        </iframe>
                    </div>
                    <!-- Shown when no resume was attached -->
                    <div id="rvNoResume" class="hidden rounded bg-gray-50 border border-gray-200 px-4 py-3 text-sm text-gray-400">
                        No resume attached.
                    </div>
                </div>

            </div><!-- end xl:flex -->

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
                    <form method="POST" action="<?php echo e($formAction); ?>" class="flex items-center gap-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" class="rid">
                        <span id="approveDisabledMsg" class="hidden text-xs text-red-600 font-medium"></span>
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
        <form id="editPane" method="POST" action="<?php echo e($formAction); ?>" enctype="multipart/form-data" <?php echo form_attrs(); ?> class="hidden">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" class="rid">
            <div class="flex items-center justify-between border-b p-6">
                <h3 class="text-lg font-bold text-gray-800">Edit Submitted Record</h3>
                <button type="button" onclick="closeReview()" aria-label="Close" class="text-2xl leading-none text-gray-400 hover:text-gray-600">&times;</button>
            </div>
            <div class="space-y-4 p-6">
                <?php intern_fields($pdo, 'rv_', $reopen['data'] ?? [], ['errors' => $reopen['errors'] ?? []]); ?>

                <!-- Resume upload / replacement -->
                <div class="rounded-md border border-gray-200 bg-gray-50/70 p-4">
                    <label class="<?php echo LABEL_CLS; ?>" for="rv_resume">ATTACH / REPLACE RESUME (PDF ONLY, MAX <?php echo RESUME_MAX_BYTES / 1024 / 1024; ?> MB)</label>
                    <div id="rvEditResumeCurrent" class="hidden mb-2 text-xs text-gray-600 flex items-center gap-2">
                        <span class="font-bold text-gray-500">Current Resume:</span>
                        <span id="rvEditResumeName" class="font-medium text-figmaBlue"></span>
                        <span class="text-gray-400">(uploading a new file will replace this)</span>
                    </div>
                    <input type="file" name="resume" id="rv_resume" accept=".pdf,application/pdf"
                        class="w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-figmaBlue file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white hover:file:bg-blue-900 focus:outline-none focus:ring-1 focus:ring-figmaBlue">
                    <p class="mt-1 text-[11px] text-gray-400">Attach a resume to this intern's record if one was submitted late or needs updating.</p>
                </div>
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

        const hasMissingDept = !r.department || r.department.trim() === '';

        // Automated verification, so problems are visible at a glance
        let items = [];
        if (r.issues.length) {
            items = r.issues.map(t => {
                if (hasMissingDept && t.toLowerCase().includes('department')) {
                    return ['bad', 'Department is required. Please use Edit to fill in the department and other required fields before approving.'];
                }
                return ['bad', t];
            });
        } else {
            items = [['ok', 'All required information is complete and the dates are in a valid sequence.']];
        }
        if (r.duplicate) items.push(['warn', 'A record with the same name and batch year already exists (' + r.duplicate + '). Please confirm that this is not a duplicate submission.']);

        // Resume text checks: warning-only and neutral note (never added to r.issues, never blocks approval)
        if (r.resume_verification && r.resume_verification.has_resume) {
            const rv = r.resume_verification;
            if (!rv.readable && rv.note) {
                // Neutral note for unreadable/scanned PDF
                items.push(['neutral', rv.note]);
            } else if (rv.warnings && rv.warnings.length > 0) {
                // Yellow warnings for missing matches
                rv.warnings.forEach(w => items.push(['warn', w]));
            } else if (rv.all_matched) {
                items.push(['ok', 'Entered name, school, and course were found in the resume.']);
            }
        }

        const style = {
            ok:      ['bg-green-50 text-green-700', '&#10003;'],
            bad:     ['bg-red-50 text-red-700', '&#10005;'],
            warn:    ['bg-yellow-50 text-yellow-800', '!'],
            neutral: ['bg-gray-100 text-gray-700', '&#8505;']
        };
        $('rvChecks').innerHTML = '<div class="text-xs font-bold text-gray-500">AUTOMATED VERIFICATION</div>' +
            items.map(([k, t]) => `<div class="flex items-start gap-2 rounded px-3 py-2 text-sm ${style[k][0]}"><span class="font-black">${style[k][1]}</span><span>${esc(t)}</span></div>`).join('');

        const deptDisplay = (r.department && r.department.trim() !== '') ? esc(r.department) : 'Not yet assigned';
        const batchYearDisplay = esc(r.batch_year) + (hasMissingDept ? ' (Set by HR)' : '');

        const fields = [
            ['School', esc(r.school) || '-'],
            ['Course', esc(r.course) || '-'],
            ['Department', deptDisplay],
            ['Batch Year', batchYearDisplay],
            ['Status', esc(r.status) || '-'],
            ['Internship Duration', duration(r)],
            ['Start Date', fmt(r.start_date)],
            ['End Date', fmt(r.end_date)],
        ];
        $('rvDetails').innerHTML = fields.map(([k, v]) =>
            `<div><dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">${k}</dt><dd class="mt-0.5 text-sm font-semibold text-gray-800">${v}</dd></div>`).join('');

        const reason = $('rvReason');
        reason.classList.toggle('hidden', !(r.validation_status === 'Rejected'));
        reason.textContent = 'Reason for rejection: ' + (r.rejection_reason || 'No reason provided.');

        // Resume panel: show the embedded viewer when a resume exists; show neutral note otherwise.
        // The Approve button is NOT disabled just because there is no resume.
        const resumeUrl = 'resume.php?id=' + id;
        if (r.has_resume) {
            $('rvResumeFrame').src = resumeUrl;
            $('rvResumeTab').href  = resumeUrl;
            $('rvResumeName').textContent = r.resume_name || 'resume.pdf';
            $('rvResumePanel').classList.remove('hidden');
            $('rvResumePanel').style.display = 'flex';
            $('rvNoResume').classList.add('hidden');
        } else {
            $('rvResumeFrame').src = '';
            $('rvResumePanel').classList.add('hidden');
            $('rvResumePanel').style.display = '';
            $('rvNoResume').classList.remove('hidden');
        }

        if (CAN_REVIEW) {
            const hasIssues = r.issues.length > 0;
            $('approveBtn').disabled = hasIssues;
            const disabledMsg = hasMissingDept
                ? 'Please use Edit to fill in the department and other required fields before approving.'
                : 'Correct the issues using Edit before approving';
            $('approveBtn').title = hasIssues ? disabledMsg : '';
            const msgEl = $('approveDisabledMsg');
            if (msgEl) {
                if (hasIssues && hasMissingDept) {
                    msgEl.textContent = 'Please use Edit to fill in the department and other required fields before approving.';
                    msgEl.classList.remove('hidden');
                } else {
                    msgEl.textContent = '';
                    msgEl.classList.add('hidden');
                }
            }
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
        const rec = data || current;
        const curPanel = $('rvEditResumeCurrent');
        const curName = $('rvEditResumeName');
        if (rec && rec.has_resume) {
            if (curName) curName.textContent = rec.resume_name || 'resume.pdf';
            if (curPanel) curPanel.classList.remove('hidden');
        } else {
            if (curPanel) curPanel.classList.add('hidden');
        }
        const fileInput = $('rv_resume');
        if (fileInput) fileInput.value = '';
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