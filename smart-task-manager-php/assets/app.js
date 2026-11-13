(() => {
    'use strict';
    document.addEventListener('submit', (event) => {
        const message = event.submitter?.dataset.confirm || event.target.dataset.confirm;
        if (message && !window.confirm(message)) event.preventDefault();
    });
    document.addEventListener('click', (event) => {
        const control = event.target.closest('[data-confirm]');
        // Forms confirm at submission, including keyboard submission and the chosen submit button.
        if (control && !control.closest('form') && !window.confirm(control.dataset.confirm)) event.preventDefault();
        const mobileLink = event.target.closest('.mobile-nav a');
        if (mobileLink) mobileLink.closest('details')?.removeAttribute('open');
    });
    const navToggle = document.querySelector('[data-nav-toggle]');
    if (navToggle) {
        const nav = document.getElementById(navToggle.getAttribute('aria-controls')) || document.querySelector('.nav');
        navToggle.addEventListener('click', () => {
            const open = navToggle.getAttribute('aria-expanded') !== 'true';
            navToggle.setAttribute('aria-expanded', String(open));
            if (nav) { nav.classList.toggle('is-open', open); nav.classList.toggle('open', open); }
        });
    }
    if (document.body.dataset.authenticated !== 'true') return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrf) return;
    const permissionButton = document.getElementById('enable-notifications');
    const permissionStatus = document.getElementById('notification-permission-status');
    // Browser alerts need permission; ordinary in-app reminder cards do not.
    function updatePermissionStatus() {
        if (!permissionButton) return;
        if (!('Notification' in window)) {
            permissionButton.disabled = true;
            permissionButton.textContent = 'In-app reminders available';
            if (permissionStatus) permissionStatus.textContent = 'This browser does not support browser alerts. Reminders still appear in the app.';
        } else if (Notification.permission === 'granted') {
            permissionButton.disabled = true;
            permissionButton.textContent = 'Browser alerts enabled';
            if (permissionStatus) permissionStatus.textContent = 'Browser alerts are enabled while the app is open. Your delivery preferences still apply.';
        } else if (Notification.permission === 'denied') {
            permissionButton.disabled = true;
            permissionButton.textContent = 'Browser alerts blocked';
            if (permissionStatus) permissionStatus.textContent = 'You can allow alerts in this browser’s site settings. In-app reminders remain available.';
        }
    }
    if (permissionButton) {
        updatePermissionStatus();
        permissionButton.addEventListener('click', async () => {
            if (!('Notification' in window)) return;
            try { await Notification.requestPermission(); updatePermissionStatus(); }
            catch (_) { if (permissionStatus) permissionStatus.textContent = 'Browser alerts could not be enabled. You can still use in-app reminders.'; }
        });
    }
    const shown = new Set();
    const pendingAcknowledgements = new Set();
    let polling = false;
    let notificationCenter = document.getElementById('notification-center');
    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        // Display task text as text, so user-entered HTML cannot become executable markup.
        if (text) node.textContent = text;
        return node;
    }
    function hiddenField(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = name; input.value = String(value);
        return input;
    }
    function showReminder(reminder) {
        if (!notificationCenter) {
            notificationCenter = element('section', 'container notification-center');
            notificationCenter.id = 'notification-center';
            notificationCenter.setAttribute('aria-live', 'polite');
            notificationCenter.setAttribute('aria-label', 'Due reminders');
            const main = document.querySelector('main');
            if (main) main.before(notificationCenter); else document.body.append(notificationCenter);
        }
        const card = element('article', 'panel notification-card');
        card.dataset.reminderId = String(reminder.reminder_id);
        card.append(element('p', 'eyebrow', 'A reminder for you'));
        card.append(element('h2', '', reminder.title));
        card.append(element('p', '', reminder.message || 'Time to take the next step on this task.'));
        const controls = element('form', 'actions');
        controls.method = 'post'; controls.action = 'reminder_action.php';
        controls.append(hiddenField('csrf', csrf), hiddenField('reminder_id', reminder.reminder_id));
        for (const [action, label] of [['dismiss', 'Dismiss'], ['snooze', 'Snooze 15 minutes']]) {
            const button = element('button', 'btn secondary', label);
            button.type = 'submit'; button.name = 'action'; button.value = action;
            controls.append(button);
        }
        const manage = element('a', 'btn secondary', 'Manage reminders');
        manage.href = 'reminders.php'; controls.append(manage); card.append(controls); notificationCenter.append(card);
        shown.add(reminder.reminder_id);
        pendingAcknowledgements.add(reminder.reminder_id);
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                const alert = new Notification(reminder.title, { body: reminder.message || 'Your task reminder is ready.', tag: `smart-task-reminder-${reminder.reminder_id}` });
                alert.onclick = () => { window.focus(); alert.close(); };
            } catch (_) { /* The visible in-app reminder remains available. */ }
        }
    }
    async function acknowledgeDisplayedReminders() {
        for (const reminderId of pendingAcknowledgements) {
            try {
                const body = new URLSearchParams({ csrf, action: 'ack', reminder_id: String(reminderId) });
                const response = await fetch('notifications.php', { method: 'POST', credentials: 'same-origin', body, headers: { Accept: 'application/json' } });
                if (!response.ok) continue;
                const result = await response.json();
                // A failed request or quiet-hours deferral is retried without showing another alert.
                if (typeof result.acknowledged === 'boolean' && !result.deferred) pendingAcknowledgements.delete(reminderId);
            } catch (_) { /* Keep this displayed reminder queued for the next poll. */ }
        }
    }
    async function poll() {
        // Poll every 30 seconds while visible, with one request at a time and at most three cards.
        if (polling || document.hidden) return;
        polling = true;
        try {
            await acknowledgeDisplayedReminders();
            if (notificationCenter && notificationCenter.children.length >= 3) return;
            const response = await fetch('notifications.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            if (!Array.isArray(data.reminders)) return;
            for (const reminder of data.reminders) {
                if (shown.has(reminder.reminder_id) || !Number.isInteger(reminder.reminder_id)) continue;
                if (notificationCenter && notificationCenter.children.length >= 3) break;
                showReminder(reminder);
                // Acknowledge after adding the reminder to the live region, never on a read.
                await acknowledgeDisplayedReminders();
            }
        } catch (_) { /* A later poll retries after transient connection errors. */ }
        finally { polling = false; }
    }
    poll();
    window.setInterval(poll, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
