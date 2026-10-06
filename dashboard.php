<?php
session_start();
require_once 'db.php';
require_login(); // Security check

$addErrors = [];
$editErrors = [];
$old = [];
$openModal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Add intern (added by an admin, so the record is accepted right away)
    if ($action === 'add_intern') {
        $old = clean_intern_input($_POST);
        $addErrors = validate_intern($old);
        if (!$addErrors) {
            insert_intern($pdo, $old, 'Approved', 'admin');
            flash('success', $old['first_name'] . ' ' . $old['last_name'] . ' was added.');
            redirect('dashboard.php');
        }
        $openModal = 'add';

    // Edit intern
    } elseif ($action === 'edit_intern') {
        $old = clean_intern_input($_POST);
        $old['id'] = (int)($_POST['id'] ?? 0);
        $editErrors = validate_intern($old);
        if (!$editErrors) {
            update_intern($pdo, $old['id'], $old);
            flash('success', $old['first_name'] . ' ' . $old['last_name'] . ' was updated.');
            redirect('dashboard.php');
        }
        $openModal = 'edit';

    // Delete intern
    } elseif ($action === 'delete_intern') {
        $pdo->prepare("DELETE FROM interns WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Intern record deleted.');
        redirect('dashboard.php');
    }
}

// Stats (accepted records only - pending/rejected submissions are handled on the Validation page)
$stats = ['Total' => 0, 'Active' => 0, 'Completed' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM interns WHERE validation_status='Approved' GROUP BY status");
while ($row = $stmt->fetch()) {
    if (array_key_exists($row['status'], $stats)) {
        $stats[$row['status']] = (int)$row['count'];
    }
    $stats['Total'] += (int)$row['count'];
}

// Search + status + school filters
$search       = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$schoolFilter = trim($_GET['school'] ?? '');
if (!in_array($statusFilter, STATUSES, true)) { $statusFilter = ''; }
$schools = get_schools($pdo); // schools entered through + Add Intern (and approved registrations)

$sql = "SELECT * FROM interns WHERE validation_status='Approved'";
$params = [];

if ($search !== '') {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR school LIKE ? OR department LIKE ?)";
    $term = "%{$search}%";
    array_push($params, $term, $term, $term, $term);
}
if ($statusFilter !== '') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}
if ($schoolFilter !== '') {
    $sql .= " AND school = ?";
    $params[] = $schoolFilter;
}
$sql .= " ORDER BY created_at DESC, id DESC";
$internsStmt = $pdo->prepare($sql);
$internsStmt->execute($params);
$interns = $internsStmt->fetchAll();

// Keeps the school + search filters when switching the status tabs
$tabLink = fn($status) => 'dashboard.php' . (($q = http_build_query(array_filter(['status' => $status, 'school' => $schoolFilter, 'search' => $search]))) ? "?$q" : '');
$tabCls  = fn($on) => $on ? 'bg-figmaBlue text-white' : 'bg-white text-gray-500 hover:bg-gray-50';
$pending = pending_count($pdo);
$filtered = ($search !== '' || $schoolFilter !== '' || $statusFilter !== '');

page_start($pdo, 'Dashboard', 'dashboard');
?>

        <!-- Header -->
        <header class="h-20 bg-white px-8 flex justify-between items-center border-b-2 border-blue-400 shadow-sm shrink-0">
            <div>
                <div class="text-xs text-gray-400 font-medium mb-1">Dashboard <span class="mx-1">></span> <span class="text-gray-800">Intern Records</span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">INTERN RECORDS</h2>
            </div>
            <div class="flex items-center gap-4">
                <a href="validation.php" title="<?php echo $pending; ?> record(s) waiting for validation" class="relative h-10 w-10 rounded border border-gray-200 flex items-center justify-center text-gray-500 hover:bg-gray-50">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                    <?php if ($pending > 0): ?><span class="absolute -right-1.5 -top-1.5 min-w-[18px] rounded-full bg-red-500 px-1 text-center text-[10px] font-black leading-[18px] text-white"><?php echo $pending; ?></span><?php endif; ?>
                </a>
                <button onclick="openModal()" class="bg-[#0fb871] text-white px-5 py-2.5 rounded font-bold text-sm flex items-center gap-2 hover:bg-green-600 transition shadow-sm">
                <span>+</span> ADD INTERN
                </button>
            </div>
        </header>

        <!-- Scrollable Body -->
        <div class="p-8 overflow-y-auto flex-1">

            <?php echo flash_html(); ?>

            <?php if ($pending > 0): ?>
                <a href="validation.php" class="mb-6 flex items-center justify-between rounded-lg border border-yellow-300 bg-yellow-50 px-5 py-3 text-sm text-yellow-800 hover:bg-yellow-100">
                    <span><b><?php echo $pending; ?></b> self-registered intern<?php echo $pending === 1 ? '' : 's'; ?> waiting for your validation.</span>
                    <span class="font-bold">Review now &rarr;</span>
                </a>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="grid grid-cols-3 gap-6 mb-8">
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
            </div>

            <!-- Toolbar Section: search + school filter + status tabs -->
            <div class="bg-white p-4 rounded-t-lg border-b border-gray-100 flex items-center justify-between gap-4 shadow-sm">
                <form method="GET" action="dashboard.php" class="flex flex-1 items-center gap-3">
                    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?php echo e($statusFilter); ?>"><?php endif; ?>
                    <input type="text" id="searchInput" name="search" value="<?php echo e($search); ?>" placeholder="Search name, school, department..." onkeydown="if(event.key==='Enter')event.preventDefault()" class="bg-gray-50 border border-gray-200 text-sm rounded-md px-4 py-2 w-full max-w-sm focus:outline-none focus:ring-1 focus:ring-figmaBlue">
                    <select name="school" onchange="this.form.submit()" title="Filter by school" class="bg-gray-50 border border-gray-200 text-sm rounded-md px-3 py-2 max-w-[240px] focus:outline-none focus:ring-1 focus:ring-figmaBlue <?php echo $schoolFilter !== '' ? 'border-figmaBlue font-bold text-figmaBlue' : 'text-gray-600'; ?>">
                        <option value="">All schools (<?php echo count($schools); ?>)</option>
                        <?php foreach ($schools as $s): ?>
                            <option value="<?php echo e($s); ?>" <?php echo strcasecmp($schoolFilter, $s) === 0 ? 'selected' : ''; ?>><?php echo e($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($filtered): ?>
                        <a href="dashboard.php" class="whitespace-nowrap text-xs font-bold text-red-500 hover:text-red-700">&times; Clear filters</a>
                    <?php endif; ?>
                </form>
                <div class="flex items-center gap-4">
                    <div class="flex border border-gray-200 rounded-md overflow-hidden text-xs font-bold shadow-sm">
                        <a href="<?php echo e($tabLink('')); ?>" class="px-4 py-2 <?php echo $tabCls($statusFilter === ''); ?>">ALL</a>
                        <a href="<?php echo e($tabLink('Active')); ?>" class="px-4 py-2 border-l border-gray-200 <?php echo $tabCls($statusFilter === 'Active'); ?>">ACTIVE</a>
                        <a href="<?php echo e($tabLink('Completed')); ?>" class="px-4 py-2 border-l border-gray-200 <?php echo $tabCls($statusFilter === 'Completed'); ?>">COMPLETED</a>
                    </div>
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
                            <th class="p-5">Status</th>
                            <th class="p-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="internTableBody" class="divide-y divide-gray-100">
                        <?php if (count($interns) > 0): ?>
                            <?php foreach ($interns as $intern): $fullName = $intern['first_name'] . ' ' . $intern['last_name']; ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-5 flex items-center gap-4">
                                        <div class="h-10 w-10 rounded-md bg-figmaBlue text-white flex items-center justify-center font-bold text-sm">
                                            <?php echo e(strtoupper(mb_substr($intern['first_name'], 0, 1) . mb_substr($intern['last_name'], 0, 1))); ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-gray-900"><?php echo e($fullName); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo e($intern['course'] ?: 'N/A'); ?></div>
                                        </div>
                                    </td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo e($intern['school']); ?></td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo e($intern['department']); ?></td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo fmt_date($intern['start_date']); ?></td>
                                    <td class="p-5 text-sm text-gray-600"><?php echo fmt_date($intern['end_date']); ?></td>
                                    <td class="p-5"><?php echo status_badge((string)$intern['status']); ?></td>
                                    <td class="p-5">
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" onclick="openEditModal(<?php echo e(json_encode($intern)); ?>)" title="Edit <?php echo e($fullName); ?>" aria-label="Edit <?php echo e($fullName); ?>"
                                                class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-figmaBlue shadow-sm transition hover:border-figmaBlue hover:bg-figmaBlue hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-figmaBlue focus-visible:ring-offset-1">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                Edit
                                            </button>
                                            <button type="button" onclick="deleteIntern(<?php echo (int)$intern['id']; ?>, <?php echo e(json_encode($fullName)); ?>)" title="Delete <?php echo e($fullName); ?>" aria-label="Delete <?php echo e($fullName); ?>"
                                                class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 shadow-sm transition hover:border-red-600 hover:bg-red-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-1">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="p-10 text-center text-sm text-gray-400">
                                    <?php echo $filtered ? 'No interns match your filters.' : 'No interns found. Click "+ ADD INTERN" to get started.'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div class="p-5 border-t border-gray-100 flex items-center justify-between">
                    <div class="text-xs text-gray-400" id="showingStats">Showing <span class="font-bold text-gray-700"><?php echo count($interns); ?></span> interns</div>
                    <div class="flex gap-2">
                        <button id="prevBtn" onclick="changePage(-1)" class="border border-gray-200 text-gray-500 rounded px-3 py-1 text-xs font-bold hover:bg-gray-50 disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed">&larr; Prev</button>
                        <button id="pageIndicator" class="bg-figmaBlue text-white rounded px-3 py-1 text-xs font-bold">1</button>
                        <button id="nextBtn" onclick="changePage(1)" class="border border-gray-200 text-gray-500 rounded px-3 py-1 text-xs font-bold hover:bg-gray-50 disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed">Next &rarr;</button>
                    </div>
                </div>
            </div>

        </div>
    </main>

<!-- Add Intern Modal -->
<div id="addInternModal" data-modal class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6 relative overflow-y-auto max-h-[90vh]">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-lg font-bold text-gray-800">Add New Intern</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 font-bold text-xl">&times;</button>
        </div>

        <form method="POST" action="dashboard.php" <?php echo form_attrs(); ?> class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_intern">

            <?php intern_fields($pdo, 'add_', $openModal === 'add' ? $old : [], ['errors' => $addErrors]); ?>

            <div class="flex justify-end gap-3 border-t pt-4 mt-4">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded text-xs font-bold text-gray-600 hover:bg-gray-100">CANCEL</button>
                <button type="submit" class="px-4 py-2 bg-[#0fb871] text-white rounded text-xs font-bold hover:bg-green-600 transition">SAVE INTERN</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Intern Modal -->
<div id="editInternModal" data-modal class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6 relative overflow-y-auto max-h-[90vh]">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-lg font-bold text-gray-800">Edit Intern</h3>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 font-bold text-xl">&times;</button>
        </div>

        <form method="POST" action="dashboard.php" <?php echo form_attrs(); ?> class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_intern">
            <input type="hidden" name="id" id="edit_id">

            <?php intern_fields($pdo, 'edit_', [], ['errors' => $editErrors]); ?>

            <div class="flex justify-end gap-3 border-t pt-4 mt-4">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 border rounded text-xs font-bold text-gray-600 hover:bg-gray-100">CANCEL</button>
                <button type="submit" class="px-4 py-2 bg-figmaBlue text-white rounded text-xs font-bold hover:bg-blue-900 transition">UPDATE INTERN</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden Delete Form (outside table to avoid browser stripping) -->
<form id="deleteInternForm" method="POST" action="dashboard.php" class="hidden">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="delete_intern">
    <input type="hidden" name="id" id="delete_id">
</form>

<script src="app.js"></script>
<script>
    function openModal() {
        document.getElementById('addInternModal').classList.remove('hidden');
    }
    function closeModal() {
        document.getElementById('addInternModal').classList.add('hidden');
    }

    function openEditModal(intern, keepErrors) {
        const modal = document.getElementById('editInternModal');
        document.getElementById('edit_id').value = intern.id;
        fillForm('edit_', intern);
        if (!keepErrors) modal.querySelectorAll('[data-server-errors]').forEach(el => el.classList.add('hidden'));
        modal.classList.remove('hidden');
    }
    function closeEditModal() {
        document.getElementById('editInternModal').classList.add('hidden');
    }

    // Asks for confirmation in a proper dialog before deleting
    function deleteIntern(id, name) {
        confirmDialog({
            title: 'Delete intern record?',
            message: 'You are about to permanently delete ' + name + '. This cannot be undone.',
            okLabel: 'Delete record'
        }).then(ok => {
            if (!ok) return;
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteInternForm').submit();
        });
    }

    // Client-Side Pagination & Live Filtering
    const rowsPerPage = 10;
    let currentPage = 1;

    function renderTable() {
        const rows = Array.from(document.querySelectorAll('#internTableBody tr'));
        const noFoundRow = rows.find(r => r.cells.length === 1);
        if (noFoundRow) noFoundRow.style.display = 'none';

        const searchTerm = document.getElementById('searchInput')?.value.toLowerCase() || '';

        const filteredRows = rows.filter(row => {
            if (row.cells.length === 1) return false;
            return row.textContent.toLowerCase().includes(searchTerm);
        });

        const totalPages = Math.ceil(filteredRows.length / rowsPerPage) || 1;
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;

        rows.forEach(r => r.style.display = 'none');
        filteredRows.slice(start, end).forEach(r => r.style.display = '');

        if (filteredRows.length === 0 && noFoundRow) {
            noFoundRow.style.display = '';
        }

        const pageIndicator = document.getElementById('pageIndicator');
        if (pageIndicator) pageIndicator.textContent = currentPage + ' / ' + totalPages;

        const prevBtn = document.getElementById('prevBtn');
        if (prevBtn) prevBtn.disabled = currentPage === 1;

        const nextBtn = document.getElementById('nextBtn');
        if (nextBtn) nextBtn.disabled = currentPage === totalPages;

        const showingStats = document.getElementById('showingStats');
        if (showingStats) {
            showingStats.innerHTML = `Showing <span class="font-bold text-gray-700">${filteredRows.length}</span> interns`;
        }
    }

    function changePage(delta) {
        currentPage += delta;
        renderTable();
    }

    document.getElementById('searchInput')?.addEventListener('input', function () {
        currentPage = 1;
        renderTable();
    });

    // Initialize on load
    document.addEventListener('DOMContentLoaded', () => {
        renderTable();
        <?php if ($openModal === 'add'): ?>
        openModal();
        <?php elseif ($openModal === 'edit'): ?>
        openEditModal(<?php echo json_safe($old); ?>, true); // re-open with the server's error message
        <?php endif; ?>
    });
</script>

</body>
</html>