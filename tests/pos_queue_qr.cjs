const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../merchant/pos.php'), 'utf8');
const start = source.indexOf('async function openQueueOrder(uid,');
const code = source.slice(start, source.indexOf('\nrefreshLiveQueue();', start));
const detail = { innerHTML: '', hidden: true };
const qrCalls = [];
let response = { success: true, status: 'pending', amount: 30, created_at: '2026-10-11 12:25:00',
    items: [], qr_payload: JSON.stringify({ type: 'payment', token: 'existing-order-token' }), short_code: 'ABCD-EFGH' };
function QRCode(element, options) { qrCalls.push(options.text); }
QRCode.CorrectLevel = { H: 2 };
const context = vm.createContext({
    FormData, QRCode, queueDetailEl: detail, posRegisterView: {}, openQueueUid: null, queueDetailRequest: 0,
    queueOrders: [{ uid: 'pos-41', id: 41, queue_no: 41, source: 'pos', stage: 'awaiting_payment' }],
    QUEUE_SOURCES: { pos: { label: 'Walk-in', icon: 'fa-cash-register' } },
    QUEUE_BADGES: {}, LIVE_QUEUE_API: 'api/pos.php',
    document: { getElementById: () => ({}) },
    fetch: async () => ({ json: async () => response }), renderQueue() {},
    escapeHtml: String, gcPriceHtml: String, gcAmount: String, queueTime: String,
});
vm.runInContext(code, context);
(async () => {
    await context.openQueueOrder('pos-41');
    assert.ok(detail.innerHTML.includes('id="pqPayQr"'));
    assert.ok(detail.innerHTML.includes('ABCD-EFGH'));
    assert.deepEqual(qrCalls, [response.qr_payload], 'Reopening an order renders its existing token');
    response = { ...response, status: 'paid', qr_payload: null, short_code: null };
    await context.openQueueOrder('pos-41');
    assert.ok(!detail.innerHTML.includes('id="pqPayQr"'));
    assert.equal(qrCalls.length, 1);
    // Paid pre-orders disappear from the live list and reappear as a
    // transaction with a different UID. The old ticket must still refresh.
    const originalOrder = { uid: 'cartpending-40', id: 40, queue_no: 40, source: 'cart_pending', stage: 'awaiting_payment' };
    context.QUEUE_SOURCES.cart_pending = { label: 'App pre-order', icon: 'fa-mobile-screen' };
    context.QUEUE_BADGES.served = '<span>Paid</span>';
    context.queueOrders = [originalOrder];
    response = { ...response, status: 'pending', wallet_qr_url: 'order-40-qr.png' };
    await context.openQueueOrder(originalOrder.uid);
    assert.ok(detail.innerHTML.includes('order-40-qr.png'));
    context.lastQueueSignature = 'previous';
    context.knownQueueUids = null;
    context.isLive = order => order.stage === 'awaiting_payment';
    context.flashQueue = () => {};
    context.renderQueue = orders => { context.queueOrders = orders; };
    const refreshStart = source.indexOf('async function refreshLiveQueue()');
    vm.runInContext(source.slice(refreshStart, source.indexOf('\nfunction closeQueueOrder()', refreshStart)), context);
    const nextOrder = { ...originalOrder, uid: 'cartpending-41', id: 41, queue_no: 41 };
    context.fetch = async (_, options) => ({ json: async () => options.body.get('action') === 'list_queue'
        ? { success: true, orders: [nextOrder, { uid: 'cart-132', stage: 'served', amount: 90 }] }
        : { ...response, status: 'paid', wallet_qr_url: null } });
    await context.refreshLiveQueue();
    assert.ok(!detail.innerHTML.includes('order-40-qr.png'), 'Disappearing pre-order clears its QR');
    assert.ok(detail.innerHTML.includes('Paid'), 'Original ticket shows paid status');
    assert.ok(detail.innerHTML.includes('#40'), 'Does not switch to the next pending ticket');
    // An older pending response must never restore a QR after a paid response.
    context.queueOrders = [originalOrder];
    let releasePending;
    context.fetch = () => new Promise(resolve => { releasePending = resolve; });
    const pendingRequest = context.openQueueOrder(originalOrder.uid);
    context.fetch = async () => ({ json: async () => ({ ...response, status: 'paid', wallet_qr_url: null }) });
    await context.openQueueOrder(originalOrder.uid);
    releasePending({ json: async () => ({ ...response, status: 'pending', wallet_qr_url: 'stale-qr.png' }) });
    await pendingRequest;
    assert.ok(!detail.innerHTML.includes('stale-qr.png'), 'Late pending response cannot restore a paid QR');
    console.log('PASS: pending queue details render a QR and POS code; paid details do not');
    console.log('PASS: paid pre-orders refresh after disappearing; late responses cannot restore stale QRs');
})().catch(error => { console.error(error); process.exitCode = 1; });
