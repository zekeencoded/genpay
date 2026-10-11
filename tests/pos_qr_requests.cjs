const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');

const page = fs.readFileSync(require('node:path').join(__dirname, '../merchant/pos.php'), 'utf8');
const generate = page.slice(page.indexOf('async function generatePaymentQr()'), page.indexOf('</script>', page.indexOf('async function generatePaymentQr()')));
const elements = new Map();
const calls = [];
let release;
let failNext = false;
const context = vm.createContext({
    crypto: webcrypto, FormData,
    document: { getElementById: id => {
        if (!elements.has(id)) elements.set(id, { style: {} });
        return elements.get(id);
    } },
    merchantWalletId: 1, POS_CSRF: 'test',
    orderTotals: () => ({ items: [{ id: '1', qty: context.qty, price: 10 }], total: context.qty * 10 }),
    fetch: async (url, { body }) => {
        calls.push(Object.fromEntries(body));
        if (failNext) { failNext = false; throw new Error('Response lost'); }
        await new Promise(resolve => { release = resolve; });
        return { json: async () => ({ success: true, order_id: 1, qr_payload: '{}', short_code: 'ABCD-EFGH' }) };
    },
    renderPosQr() {}, setQrStatus() {}, startStatusPolling() {}, refreshLiveQueue() {},
    setPosTab(name) { context.visibleTab = name; },
    alert() {}, gcAmount: String, qty: 1,
});
vm.runInContext("let qrRequestPending = false; let qrDraftSignature = ''; let qrDraftKey = '';\n" + generate, context);

(async () => {
    const first = context.generatePaymentQr();
    await context.generatePaymentQr();
    assert.equal(calls.length, 1, 'Overlapping clicks must send one request');
    release();
    await first;
    const repeated = context.generatePaymentQr();
    release();
    await repeated;
    assert.equal(calls[0].request_key, calls[1].request_key, 'Unchanged cart must reuse its request key');
    failNext = true;
    await context.generatePaymentQr();
    const retry = context.generatePaymentQr();
    release();
    await retry;
    assert.equal(calls[2].request_key, calls[3].request_key, 'Lost responses must retry the same order');
    context.qty = 2;
    const changed = context.generatePaymentQr();
    release();
    await changed;
    assert.notEqual(calls[3].request_key, calls[4].request_key, 'Changed cart must get a new request key');
    assert.equal(elements.get('chargeBtn').disabled, false);
    assert.equal(context.visibleTab, 'queue', 'Generated order must show the Live Order tab');
    console.log('PASS: overlapping clicks, repeat generation, lost-response retry, and changed cart');
})().catch(error => { console.error(error); process.exitCode = 1; });
