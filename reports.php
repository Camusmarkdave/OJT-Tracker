<?php
session_start();
require_once 'db.php';
require_login(); // Security check

// Only accepted records are reported on (pending/rejected submissions live on the Validation page)
$rows = $pdo->query("SELECT id, first_name, last_name, course, school, department, start_date, end_date, status, batch_year
                     FROM interns WHERE validation_status='Approved'")->fetchAll();

// Group spellings that only differ by case/spacing (e.g. "UP Diliman" vs "up diliman")
const NO_SCHOOL = 'No school listed';
const NO_DEPT   = 'No department';
$canon = function (?string $v, array &$map, string $fallback): string {
    $v = trim((string)$v);
    if ($v === '') { return $fallback; }
    return $map[mb_strtolower($v)] ??= $v;
};
$schoolMap = $deptMap = [];
$data = [];
foreach ($rows as $r) {
    $data[] = [
        'id'         => (int)$r['id'],
        'name'       => trim($r['first_name'] . ' ' . $r['last_name']),
        'course'     => (string)$r['course'],
        'school'     => $canon($r['school'], $schoolMap, NO_SCHOOL),
        'department' => $canon($r['department'], $deptMap, NO_DEPT),
        'start'      => $r['start_date'] ? date('M j, Y', strtotime($r['start_date'])) : '',
        'end'        => $r['end_date'] ? date('M j, Y', strtotime($r['end_date'])) : '',
        'status'     => (string)$r['status'],
        'batch'      => (int)$r['batch_year'],
    ];
}

$total   = count($data);
$active  = count(array_filter($data, fn($d) => $d['status'] === 'Active'));
$nSchool = count(array_unique(array_column($data, 'school')));
$nDept   = count(array_unique(array_column($data, 'department')));

$orderParam = $_GET['order'] ?? 'count_desc';
if (!in_array($orderParam, ['count_desc', 'count_asc', 'alpha_asc', 'alpha_desc'], true)) { $orderParam = 'count_desc'; }
$view = ($_GET['view'] ?? 'school') === 'department' ? 'department' : 'school';

page_start($pdo, 'Reports', 'reports');
?>

        <!-- Header -->
        <header class="h-20 bg-white px-8 flex justify-between items-center border-b-2 border-blue-400 shadow-sm shrink-0">
            <div>
                <div class="text-xs text-gray-400 font-medium mb-1">Reports <span class="mx-1">></span> <span class="text-gray-800">Summary</span></div>
                <h2 class="text-2xl font-black uppercase text-gray-900 tracking-tight">ANALYTICS & REPORTS</h2>
            </div>
            <button onclick="window.print()" class="flex items-center gap-2 rounded border border-gray-200 bg-white px-4 py-2 text-xs font-bold text-gray-600 hover:bg-gray-50 print:hidden">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                PRINT REPORT
            </button>
        </header>

        <!-- Scrollable Body -->
        <div class="p-8 overflow-y-auto flex-1 flex flex-col gap-6">

        <?php if ($total === 0): ?>
            <div class="flex flex-1 flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
                <h3 class="mb-1 text-lg font-bold text-gray-800">No accepted intern records yet</h3>
                <p class="mb-5 max-w-sm text-sm text-gray-500">Reports are built from accepted records. Add an intern, or approve a submitted registration, to see your numbers here.</p>
                <div class="flex gap-3">
                    <a href="dashboard.php" class="rounded bg-figmaBlue px-4 py-2 text-xs font-bold text-white hover:bg-blue-900">GO TO INTERN RECORDS</a>
                    <a href="validation.php" class="rounded border border-gray-300 px-4 py-2 text-xs font-bold text-gray-600 hover:bg-gray-50">OPEN VALIDATION</a>
                </div>
            </div>
        <?php else: ?>

            <!-- Summary -->
            <div class="grid grid-cols-4 gap-6">
                <?php foreach ([['Accepted interns', $total, 'border-l-figmaBlue'], ['Schools', $nSchool, 'border-l-[#0fb871]'], ['Departments', $nDept, 'border-l-figmaYellow'], ['Active interns', $active, 'border-l-red-400']] as [$label, $n, $cls]): ?>
                <div class="bg-white p-5 rounded-lg shadow-sm border border-gray-100 border-l-4 <?php echo $cls; ?>">
                    <div class="text-3xl font-black text-gray-800 leading-none"><?php echo $n; ?></div>
                    <div class="text-[10px] font-bold text-gray-400 tracking-wider uppercase mt-1"><?php echo $label; ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Controls -->
            <div class="flex flex-wrap items-center justify-between gap-4 print:hidden">
                <div class="flex items-center gap-4">
                    <div id="viewToggle" class="flex overflow-hidden rounded-md border border-gray-200 text-xs font-bold shadow-sm">
                        <button data-view="school" class="px-5 py-2.5">BY SCHOOL</button>
                        <button data-view="department" class="border-l border-gray-200 px-5 py-2.5">BY DEPARTMENT</button>
                    </div>
                    <input id="listSearch" type="text" class="w-64 rounded-md border border-gray-200 bg-white px-4 py-2.5 text-sm focus:outline-none focus:ring-1 focus:ring-figmaBlue">
                </div>
                <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2">
                    <label for="orderSelect" class="text-xs font-bold uppercase tracking-wider text-gray-500">Sort By</label>
                    <select id="orderSelect" class="cursor-pointer border-none bg-transparent text-sm font-bold text-gray-800 focus:outline-none">
                        <option value="count_desc">Descending Order</option>
                        <option value="count_asc">Ascending Order</option>
                        <option value="alpha_asc">Alphabetical (A-Z)</option>
                        <option value="alpha_desc">Alphabetical (Z-A)</option>
                    </select>
                </div>
            </div>

            <!-- Master / detail: pick an item on the left, see its interns on the right -->
            <div class="grid flex-1 grid-cols-12 items-start gap-6">
                <div class="col-span-4 overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm print:hidden">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <div id="listTitle" class="text-sm font-bold text-gray-800"></div>
                        <div class="text-xs text-gray-400">Select one to see who is in it.</div>
                    </div>
                    <div id="list" class="max-h-[calc(100vh-24rem)] min-h-[200px] divide-y divide-gray-100 overflow-y-auto"></div>
                </div>
                <div id="detail" class="col-span-8 rounded-lg border border-gray-100 bg-white shadow-sm print:col-span-12"></div>
            </div>

        <?php endif; ?>
        </div>
    </main>

<?php if ($total > 0): ?>
<script>
    const DATA = <?php echo json_safe($data); ?>;
    const TOTAL = DATA.length;
    const NONE = <?php echo json_safe([NO_SCHOOL, NO_DEPT]); ?>;
    const STATUS_CLS = { Active: 'bg-green-100 text-green-700', Completed: 'bg-blue-100 text-figmaBlue' };
    const state = { view: <?php echo json_safe($view); ?>, order: <?php echo json_safe($orderParam); ?>, q: '', selected: null };

    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const plural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');
    const otherKey = () => state.view === 'school' ? 'department' : 'school';
    const badge = s => `<span class="rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ${STATUS_CLS[s] || 'bg-gray-100 text-gray-600'}">${esc(s)}</span>`;

    function buildGroups(rows, key) {
        const m = new Map();
        rows.forEach(r => { if (!m.has(r[key])) m.set(r[key], []); m.get(r[key]).push(r); });
        return [...m].map(([name, items]) => ({ name, items }));
    }

    function sortGroups(list) {
        const byName = (a, b) => a.name.localeCompare(b.name);
        const o = state.order;
        return list.sort((a, b) =>
            o === 'count_desc' ? (b.items.length - a.items.length || byName(a, b)) :
            o === 'count_asc'  ? (a.items.length - b.items.length || byName(a, b)) :
            o === 'alpha_desc' ? byName(b, a) : byName(a, b));
    }

    function renderControls() {
        document.querySelectorAll('#viewToggle button').forEach(b => {
            const on = b.dataset.view === state.view;
            b.className = b.className.replace(/bg-figmaBlue text-white|bg-white text-gray-500 hover:bg-gray-50/g, '').trim() +
                (on ? ' bg-figmaBlue text-white' : ' bg-white text-gray-500 hover:bg-gray-50');
        });
        $('listSearch').placeholder = state.view === 'school' ? 'Find a school...' : 'Find a department...';
        $('orderSelect').value = state.order;
    }

    function render() {
        renderControls();
        const q = state.q.trim().toLowerCase();
        const list = sortGroups(buildGroups(DATA, state.view).filter(g => !q || g.name.toLowerCase().includes(q)));
        if (!list.some(g => g.name === state.selected)) state.selected = list.length ? list[0].name : null;

        $('listTitle').textContent = plural(list.length, state.view === 'school' ? 'school' : 'department');
        const max = Math.max(1, ...list.map(g => g.items.length));
        $('list').innerHTML = list.length ? list.map(g => {
            const n = g.items.length, on = g.name === state.selected;
            return `<button data-name="${esc(g.name)}" class="block w-full border-l-4 px-5 py-3.5 text-left transition ${on ? 'border-figmaBlue bg-blue-50' : 'border-transparent hover:bg-gray-50'}">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="truncate text-sm font-bold ${on ? 'text-figmaBlue' : 'text-gray-800'}">${esc(g.name)}</span>
                    <span class="shrink-0 text-sm font-black ${on ? 'text-figmaBlue' : 'text-gray-700'}">${n}</span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-figmaBlue" style="width:${Math.round(n / max * 100)}%"></div></div>
                <div class="mt-1 text-[10px] text-gray-400">${Math.round(n / TOTAL * 100)}% of all interns</div>
            </button>`;
        }).join('') : '<div class="p-8 text-center text-sm text-gray-400">Nothing matches your search.</div>';

        renderDetail(list.find(g => g.name === state.selected));
    }

    function renderDetail(g) {
        const box = $('detail');
        if (!g) { box.innerHTML = '<div class="p-12 text-center text-sm text-gray-400">Select an item on the left to see its interns.</div>'; return; }

        const subs = buildGroups(g.items, otherKey()).sort((a, b) => b.items.length - a.items.length || a.name.localeCompare(b.name));
        const counts = { Active: 0, Completed: 0 };
        g.items.forEach(r => counts[r.status] = (counts[r.status] || 0) + 1);
        const isSchool = state.view === 'school';
        const openLink = NONE.includes(g.name) ? '' :
            `<a href="dashboard.php?${isSchool ? 'school' : 'search'}=${encodeURIComponent(g.name)}" class="shrink-0 rounded border border-gray-200 px-3 py-2 text-xs font-bold text-figmaBlue hover:bg-gray-50 print:hidden">Open in Intern Records</a>`;
        const period = r => r.start && r.end ? `${esc(r.start)} - ${esc(r.end)}` : r.start ? `From ${esc(r.start)}` : r.end ? `Until ${esc(r.end)}` : '-';

        box.innerHTML = `
            <div class="flex items-start justify-between gap-4 border-b border-gray-100 p-6">
                <div class="min-w-0">
                    <div class="text-xs font-medium text-gray-400">${isSchool ? 'School' : 'Department'}</div>
                    <h3 class="truncate text-2xl font-black text-gray-900">${esc(g.name)}</h3>
                    <div class="mt-1 text-sm text-gray-500">${plural(g.items.length, 'intern')} across ${plural(subs.length, isSchool ? 'department' : 'school')}</div>
                </div>
                ${openLink}
            </div>
            <div class="flex flex-wrap gap-3 border-b border-gray-100 bg-gray-50 px-6 py-4">
                ${Object.entries(counts).map(([s, n]) => `<div class="flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-1.5">${badge(s)}<span class="text-sm font-black text-gray-800">${n}</span></div>`).join('')}
            </div>
            <div class="space-y-4 p-6">
                <div class="text-xs font-bold uppercase tracking-wider text-gray-400">${isSchool ? 'Interns grouped by department' : 'Interns grouped by school'}</div>
                ${subs.map(s => `
                <details open class="overflow-hidden rounded-lg border border-gray-200">
                    <summary class="flex cursor-pointer items-center justify-between bg-gray-50 px-4 py-3 hover:bg-gray-100">
                        <span class="text-sm font-bold text-gray-800">${esc(s.name)}</span>
                        <span class="rounded-full bg-figmaBlue px-2.5 py-0.5 text-xs font-bold text-white">${plural(s.items.length, 'intern')}</span>
                    </summary>
                    <table class="w-full text-left">
                        <thead><tr class="border-b border-gray-100 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                            <th class="px-4 py-2">Name</th><th class="px-4 py-2">Course</th><th class="px-4 py-2">Batch</th><th class="px-4 py-2">Internship period</th><th class="px-4 py-2">Status</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            ${s.items.sort((a, b) => a.name.localeCompare(b.name)).map(r => `
                            <tr>
                                <td class="px-4 py-2.5 text-sm font-semibold text-gray-800">${esc(r.name)}</td>
                                <td class="px-4 py-2.5 text-sm text-gray-500">${esc(r.course) || '-'}</td>
                                <td class="px-4 py-2.5 text-sm text-gray-500">${r.batch}</td>
                                <td class="px-4 py-2.5 text-sm text-gray-500">${period(r)}</td>
                                <td class="px-4 py-2.5">${badge(r.status)}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </details>`).join('')}
            </div>`;
    }

    $('list').addEventListener('click', ev => {
        const b = ev.target.closest('[data-name]');
        if (b) { state.selected = b.dataset.name; render(); }
    });
    $('viewToggle').addEventListener('click', ev => {
        const b = ev.target.closest('[data-view]');
        if (b && b.dataset.view !== state.view) { state.view = b.dataset.view; state.q = ''; $('listSearch').value = ''; state.selected = null; render(); }
    });
    $('listSearch').addEventListener('input', ev => { state.q = ev.target.value; render(); });
    $('orderSelect').addEventListener('change', ev => { state.order = ev.target.value; render(); });

    render();
</script>
<?php endif; ?>

</body>
</html>