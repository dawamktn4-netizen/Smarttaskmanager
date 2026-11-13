// Regression: a full notification area must still retry failed acknowledgements,
// without repeating either the visible cards or browser alerts.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function element() {
    return { children: [], dataset: {}, setAttribute() {}, append(...nodes) { this.children.push(...nodes); } };
}
let center = null;
let scheduledPoll;
let mode = 'offline';
let alerts = 0;
let feedReads = 0;
let acknowledgementAttempts = 0;
let acknowledgements = 0;
const document = {
    hidden: false,
    body: { dataset: { authenticated: 'true' } },
    addEventListener() {},
    getElementById(id) { return id === 'notification-center' ? center : null; },
    querySelector(selector) {
        if (selector === 'meta[name="csrf-token"]') return { content: 'test-token' };
        if (selector === 'main') return { before(node) { center = node; } };
        return null;
    },
    createElement: element,
};
class Notification {
    static permission = 'granted';
    constructor() { alerts++; }
}
const context = {
    document, Notification, URLSearchParams,
    window: { Notification, setInterval(fn) { scheduledPoll = fn; } },
    async fetch(_url, options) {
        if (options.method === 'POST') {
            acknowledgementAttempts++;
            if (mode === 'offline') throw new Error('Connection interrupted');
            if (mode === 'deferred') return { ok: true, async json() { return { acknowledged: false, deferred: true }; } };
            acknowledgements++;
            return { ok: true, async json() { return { acknowledged: true }; } };
        }
        feedReads++;
        return { ok: true, async json() { return { reminders: [1, 2, 3].map(id => ({ reminder_id: id, title: `Reminder ${id}` })) }; } };
    },
};
(async () => {
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/app.js'), 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(center.children.length, 3);
    assert.equal(alerts, 3);
    assert.ok(acknowledgementAttempts >= 3);
    mode = 'deferred';
    await scheduledPoll();
    assert.equal(acknowledgements, 0);
    mode = 'online';
    await scheduledPoll();
    assert.equal(acknowledgements, 3, 'All displayed reminders retry after network failure and quiet-hours deferral');
    await scheduledPoll();
    assert.equal(acknowledgements, 3, 'Successful acknowledgements are not retried');
    assert.equal(center.children.length, 3, 'No duplicate cards');
    assert.equal(alerts, 3, 'No duplicate browser alerts');
    assert.equal(feedReads, 1, 'Full card area does not fetch more reminders');
    console.log('PASS: reminder acknowledgement retries, deferral, card limit and duplicate-alert prevention.');
})().catch(error => { console.error(error); process.exitCode = 1; });
