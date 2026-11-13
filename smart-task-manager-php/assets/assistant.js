(() => {
    'use strict';
    const form = document.getElementById('ai-lab-form');
    if (!form) return;
    const result = document.getElementById('lab-result');
    const status = document.getElementById('lab-status-message');
    const button = form.querySelector('button[type="submit"]');
    let busy = false;
    form.addEventListener('change', () => {
        status.textContent = 'Inputs changed. Select “Explain this change” to update the preview.';
        result.dataset.stale = 'true';
    });
    document.getElementById('lab-task').addEventListener('change', (event) => {
        const option = event.target.selectedOptions[0];
        form.elements.importance.value = option.dataset.importance;
        form.elements.status.value = option.dataset.status;
        form.elements.postpone_count.value = option.dataset.postponements;
        form.elements.deadline.value = 'keep';
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        const body = new FormData(form);
        // Disable inputs during a request so an old response cannot describe newer form values.
        const controls = [...form.querySelectorAll('select, button')];
        controls.forEach(control => { control.disabled = true; });
        button.textContent = 'Calculating…';
        result.setAttribute('aria-busy', 'true');
        status.textContent = 'Comparing your scenario with the original…';
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch('assistant.php', {method:'POST', body, credentials:'same-origin',
                headers:{Accept:'application/json'}, signal:controller.signal});
            if (response.redirected || !(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Your session may have expired. Refresh the page and sign in again.');
            }
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'The preview could not be calculated. Refresh and try again.');
            if (typeof data.html !== 'string') throw new Error('The preview was incomplete. Please try again.');
            // This HTML comes only from our authenticated PHP template, which escapes all user text.
            result.innerHTML = data.html;
            delete result.dataset.stale;
            status.textContent = 'Preview updated. No tasks or reminders were changed.';
        } catch (error) {
            status.textContent = error.name === 'AbortError' ? 'The request timed out. Please try again.' : error.message;
        } finally {
            window.clearTimeout(timeout);
            busy = false;
            controls.forEach(control => { control.disabled = false; });
            button.textContent = 'Explain this change';
            result.removeAttribute('aria-busy');
        }
    });
})();
