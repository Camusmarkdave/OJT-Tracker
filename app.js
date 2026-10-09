/* app.js - shared client-side helpers for InternTrack */

const INTERN_FIELDS = ['first_name', 'last_name', 'course', 'school', 'department', 'batch_year', 'status', 'start_date', 'end_date'];

/* ------------------------------------------------------------------ */
/* Date validation                                                     */
/* ------------------------------------------------------------------ */

/** Formats a YYYY-MM-DD string as "Oct 10, 2026". */
function fmtDate(v) {
    return new Date(v + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

/** Hides the live date-error box and clears the red borders. */
function resetDateErrors(form) {
    form.querySelectorAll('input[type=date]').forEach(i => i.classList.remove('border-red-500', 'ring-1', 'ring-red-500'));
    const box = form.querySelector('[data-date-error]');
    if (box) { box.classList.add('hidden'); box.textContent = ''; }
}

/**
 * Checks that the end date is not earlier than the start date.
 * Shows a message under the dates and returns true when the dates are in a valid sequence.
 * (The server performs the same check; this only provides immediate feedback.)
 */
function checkDates(form) {
    resetDateErrors(form);
    const start = form.querySelector('[name="start_date"]');
    const end = form.querySelector('[name="end_date"]');
    if (!start || !end) return true;

    if (start.value && end.value && end.value < start.value) {
        [start, end].forEach(el => el.classList.add('border-red-500', 'ring-1', 'ring-red-500'));
        const box = form.querySelector('[data-date-error]');
        if (box) {
            box.textContent = 'Invalid date range: the end date (' + fmtDate(end.value) + ') cannot be earlier than the start date (' + fmtDate(start.value) + ').';
            box.classList.remove('hidden');
        }
        return false;
    }
    return true;
}

/** Fills an intern form from a record. Field ids are prefix + field name (e.g. edit_first_name). */
function fillForm(prefix, data) {
    INTERN_FIELDS.forEach(k => {
        const el = document.getElementById(prefix + k);
        if (!el) return;
        const v = data[k] == null ? '' : String(data[k]);
        // If a record uses a department that is no longer in the list, keep it selectable.
        if (el.tagName === 'SELECT' && v && ![...el.options].some(o => o.value === v)) el.add(new Option(v, v));
        el.value = v;
    });
    const first = document.getElementById(prefix + 'first_name');
    if (first && first.form) resetDateErrors(first.form);
}

/* ------------------------------------------------------------------ */
/* Confirmation dialog (replaces the plain browser confirm box)        */
/* ------------------------------------------------------------------ */

/**
 * Shows a confirmation dialog and returns a Promise that resolves to true (confirmed) or false (cancelled).
 * confirmDialog({ title, message, okLabel, danger }).then(ok => { ... })
 */
function confirmDialog({ title = 'Confirm Action', message = '', okLabel = 'Confirm', danger = true } = {}) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[70] flex items-center justify-center bg-black/50 p-4';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');

        const box = document.createElement('div');
        box.className = 'w-full max-w-md rounded-lg bg-white p-6 shadow-2xl';

        const head = document.createElement('div');
        head.className = 'flex items-start gap-4';

        const icon = document.createElement('div');
        icon.className = 'flex h-11 w-11 shrink-0 items-center justify-center rounded-full ' + (danger ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-figmaBlue');
        icon.innerHTML = '<svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';

        const text = document.createElement('div');
        const h = document.createElement('h3');
        h.className = 'text-base font-bold text-gray-900';
        h.textContent = title;
        const p = document.createElement('p');
        p.className = 'mt-1 text-sm leading-relaxed text-gray-500';
        p.textContent = message; // textContent, so names from the database can't inject HTML
        text.append(h, p);
        head.append(icon, text);

        const actions = document.createElement('div');
        actions.className = 'mt-6 flex justify-end gap-3';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        cancel.className = 'rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-figmaBlue';

        const ok = document.createElement('button');
        ok.type = 'button';
        ok.textContent = okLabel;
        ok.className = 'rounded-md px-4 py-2 text-sm font-semibold text-white shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 ' +
            (danger ? 'bg-red-600 hover:bg-red-700 focus-visible:ring-red-600' : 'bg-figmaBlue hover:bg-blue-900 focus-visible:ring-figmaBlue');

        actions.append(cancel, ok);
        box.append(head, actions);
        overlay.append(box);
        document.body.append(overlay);

        const finish = result => {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            resolve(result);
        };
        const onKey = ev => {
            if (ev.key === 'Escape') { ev.stopPropagation(); finish(false); } // don't also close a modal behind the dialog
        };
        document.addEventListener('keydown', onKey, true);
        cancel.addEventListener('click', () => finish(false));
        ok.addEventListener('click', () => finish(true));
        overlay.addEventListener('mousedown', ev => { if (ev.target === overlay) finish(false); });
        cancel.focus(); // the safe choice is focused by default
    });
}

// Any form with a data-confirm attribute asks first, e.g.
// <form data-confirm="This action cannot be undone." data-confirm-title="Delete Record?" data-confirm-ok="Delete">
document.addEventListener('submit', ev => {
    const form = ev.target;
    if (!form.dataset || !form.dataset.confirm) return;
    ev.preventDefault();
    confirmDialog({
        title: form.dataset.confirmTitle || 'Confirm Action',
        message: form.dataset.confirm,
        okLabel: form.dataset.confirmOk || 'Confirm',
    }).then(ok => { if (ok) form.submit(); });
}, true);

/* ------------------------------------------------------------------ */
/* Wiring                                                              */
/* ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-intern-form]').forEach(form => {
        const onDate = ev => {
            if (ev.target.type !== 'date') return;
            const server = form.querySelector('[data-server-errors]');
            if (server) server.classList.add('hidden');   // the user is correcting the dates, so hide the earlier server message
            checkDates(form);
        };
        form.addEventListener('input', onDate);
        form.addEventListener('change', onDate);
        form.addEventListener('submit', ev => {
            if (!checkDates(form)) {
                ev.preventDefault();
                const box = form.querySelector('[data-date-error]');
                if (box) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });

    // Escape closes any open modal
    document.addEventListener('keydown', ev => {
        if (ev.key === 'Escape') document.querySelectorAll('[data-modal]:not(.hidden)').forEach(m => m.classList.add('hidden'));
    });
});