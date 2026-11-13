(() => {
  'use strict';
  const form = document.querySelector('#chat-form');
  if (!form) return;
  const messages = document.querySelector('#chat-messages');
  const proposal = document.querySelector('#chat-proposal');
  const input = document.querySelector('#chat-message');
  const consent = document.querySelector('#chat-consent');
  const status = document.querySelector('#chat-status');
  const error = document.querySelector('#chat-error');
  const ready = document.querySelector('[data-chat-ready]').dataset.chatReady === 'true';
  const provider = document.querySelector('[data-provider]').dataset.provider;
  let busy = false;
  let loaded = false;
  let thinkingTimer;
  let pendingMessage;
  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function setBusy(value, label = '') {
    busy = value;
    form.setAttribute('aria-busy', String(value));
    document.querySelectorAll('#chat-form button, #chat-clear, .chat-prompt, #chat-proposal button').forEach(button => {
      button.disabled = value || (!ready && (button.id === 'chat-send' || button.classList.contains('chat-prompt')));
    });
    input.disabled = value || !ready;
    status.textContent = label;
  }
  function showError(message) {
    error.textContent = message;
    error.hidden = false;
  }
  async function request(operation, fields = {}) {
    const body = new URLSearchParams({ operation, csrf: document.querySelector('meta[name="csrf-token"]').content, ...fields });
    const response = await fetch('chat_api.php', { method: 'POST', headers: { Accept: 'application/json' }, body, credentials: 'same-origin' });
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('The server could not respond. Refresh the page and check that your session and database are available.'); }
    if (!response.ok) throw new Error(data.error || 'This request could not be completed.');
    return data;
  }
  function render(state) {
    messages.replaceChildren();
    const history = state.history.length ? state.history : [{role:'assistant', content: ready
      ? 'Hello! I can help you plan your day, explain your priorities, or prepare a task change. What would you like to work on?'
      : 'Welcome to SmartTask chat. Connect an AI model using the setup steps to ask questions and prepare task changes. This welcome message is part of the interface, not an AI-generated response.'}];
    history.forEach(turn => {
      const article = element('article', 'chat-message chat-message-' + turn.role);
      article.append(element('span', 'chat-speaker', turn.role === 'user' ? 'You' : 'SmartTask'));
      article.append(element('p', '', turn.content));
      messages.append(article);
    });
    messages.scrollTop = messages.scrollHeight;
    proposal.replaceChildren();
    proposal.hidden = !state.pending;
    if (state.pending) {
      const pending = state.pending;
      proposal.append(element('p', 'eyebrow', 'REVIEW BEFORE SAVING'));
      proposal.append(element('h3', '', pending.label + ': ' + pending.title));
      const details = element('ul', '');
      pending.details.forEach(detail => details.append(element('li', '', detail)));
      proposal.append(details, element('p', 'field-help', 'Local time: ' + pending.timezone + ' · Proposal expires after 15 minutes.'));
      const actions = element('div', 'actions');
      const approve = element('button', 'btn primary', 'Confirm change');
      const cancel = element('button', 'btn ghost', 'Cancel');
      approve.type = cancel.type = 'button';
      approve.addEventListener('click', () => act('confirm', {token: pending.token}, 'Saving your confirmed change…'));
      cancel.addEventListener('click', () => act('cancel', {token: pending.token}, 'Cancelling proposal…'));
      actions.append(approve, cancel);
      proposal.append(actions);
    }
    if (state.saved) {
      const link = element('a', 'text-link chat-saved-link', 'View saved task →');
      link.href = 'edit_task.php?id=' + Number(state.saved.task_id);
      messages.append(link);
      link.focus();
    }
  }
  async function act(operation, fields = {}, label = '') {
    if (busy) return false;
    error.hidden = true;
    setBusy(true, label);
    // Local inference can take two minutes. Show that the submitted message was
    // received and keep the original draft available if the request fails.
    if (operation === 'message') {
      pendingMessage = element('article', 'chat-message chat-message-user');
      pendingMessage.append(element('span', 'chat-speaker', 'You'), element('p', '', fields.message));
      messages.append(pendingMessage);
      messages.scrollTop = messages.scrollHeight;
      const started = Date.now();
      thinkingTimer = window.setInterval(() => {
        const elapsed = Math.floor((Date.now() - started) / 1000);
        status.textContent = 'SmartTask is thinking… ' + elapsed + 's' +
          (provider === 'Local AI (Ollama)' ? ' · Local replies can take up to two minutes.' : '');
      }, 1000);
    }
    try {
      const state = await request(operation, fields);
      render(state);
      loaded = true;
      return true;
    } catch (failure) {
      showError(failure.message);
      return false;
    } finally {
      window.clearInterval(thinkingTimer);
      if (pendingMessage) { pendingMessage.remove(); pendingMessage = null; }
      setBusy(false);
    }
  }
  input.addEventListener('keydown', event => {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.isComposing) {
      event.preventDefault();
      form.requestSubmit();
    }
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!loaded) { showError('Refresh the page to load the conversation before sending.'); return; }
    const message = input.value.trim();
    if (!message || busy || !ready) return;
    if (!consent.checked) { showError('Please allow ' + provider + ' to process your message and task summary first.'); consent.focus(); return; }
    if (await act('message', { message, consent: 'yes', provider }, 'SmartTask is thinking…')) { input.value = ''; input.focus(); }
  });
  document.querySelectorAll('.chat-prompt').forEach(button => button.addEventListener('click', () => {
    input.value = button.dataset.prompt;
    input.focus();
  }));
  document.querySelector('#chat-clear').addEventListener('click', async () => {
    if (await act('clear', {}, 'Starting a new conversation…')) { input.value=''; consent.checked=false; }
  });
  act('state', {}, 'Loading conversation…');
})();
