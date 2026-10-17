const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.join(__dirname, '../student/cart.php'), 'utf8');
const scriptStart = page.indexOf('    const CSRF =');
new vm.Script(page.slice(scriptStart, page.indexOf('</script>', scriptStart)).replace(/<\?=[\s\S]*?\?>/g, '1'));
assert.equal(typeof require('../assets/js/vendor/jsQR-1.4.0.js'), 'function');
function block(start, end) {
    const from = page.indexOf(start);
    const to = page.indexOf(end, from);
    assert.ok(from >= 0 && to > from, `Missing source: ${start}`);
    return page.slice(from, to);
}
function stream() {
    const track = { stopped: false, stop() { this.stopped = true; } };
    return { track, getTracks: () => [track] };
}
async function cameraChecks() {
    const requests = [];
    const context = vm.createContext({
        activeStream: null, cameraRequest: 0, currentFacingMode: 'environment', scanLoopRunning: false,
        navigator: { mediaDevices: { getUserMedia: () => new Promise(resolve => requests.push(resolve)) } },
        video: { play: async () => {} }, requestAnimationFrame() {}, scanLoop() {},
        showFrameMsg() {}, hideFrameMsg() {}, setCameraStatus() {},
    });
    vm.runInContext(block('    function stopScannerStream()', '    function scanLoop('), context);
    const first = context.startScanner();
    const second = context.startScanner('user');
    const oldStream = stream();
    const newStream = stream();
    requests[1](newStream);
    await second;
    requests[0](oldStream);
    await first;
    assert.equal(context.activeStream, newStream, 'Late camera request cannot replace the selected camera');
    assert.equal(oldStream.track.stopped, true, 'Unused camera streams must be released');
    context.video.play = async () => { throw new Error('Playback blocked'); };
    const retry = context.startScanner();
    const failedStream = stream();
    requests[2](failedStream);
    await retry;
    assert.equal(failedStream.track.stopped, true, 'Playback failure must release the camera');
    assert.equal(context.activeStream, null);
}
async function scanChecks() {
    let scheduled = 0;
    let decoded = 0;
    let scans = 0;
    let failFrame = true;
    let qr = { data: 'payment-qr' };
    const context = vm.createContext({
        activeStream: {}, scannerReady: false, scanningPaused: false, scanCooldown: false,
        posLookupPending: false, paymentInFlight: false, lastDecodeAt: 0, lastDetectedPayload: '',
        document: { hidden: false }, video: { readyState: 4, HAVE_ENOUGH_DATA: 4, videoWidth: 1280, videoHeight: 720 },
        canvas: {}, canvasContext: {
            drawImage() { if (failFrame) throw new Error('Frame not ready'); },
            getImageData: () => ({ data: [], width: 960, height: 540 }),
        },
        jsQR() { decoded++; return qr; }, handleScannedPayload: async () => { scans++; },
        requestAnimationFrame() { scheduled++; }, setTimeout(fn) { fn(); },
        showAlert() {}, showFrameMsg() {}, setCameraStatus() {},
    });
    vm.runInContext(block('    function scanLoop(', '    function switchCamera()'), context);
    context.scanLoop(200);
    assert.equal(decoded, 0, 'Wait for the pending order before scanning');
    context.scannerReady = true;
    context.scanLoop(400);
    assert.equal(scheduled, 2, 'A transient canvas error must schedule another frame');
    failFrame = false;
    context.scanLoop(600);
    await new Promise(resolve => setImmediate(resolve));
    context.scanLoop(800);
    assert.equal(scans, 1, 'Holding the same QR must not reopen payment or add duplicates');
    qr = null;
    context.scanLoop(1000);
    qr = { data: 'payment-qr' };
    context.scanLoop(1200);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(scans, 2, 'Removing and showing a QR must allow retry');
    context.posLookupPending = true;
    context.scanLoop(1400);
    assert.equal(scans, 2, 'Manual POS lookup must prevent concurrent camera scans');
}
async function paymentChecks() {
    const events = {};
    const alerts = [];
    const fields = {};
    let calls = 0;
    let release;
    const context = vm.createContext({
        FormData, pendingWalletPayload: { type: 'payment', token: 'a'.repeat(32) },
        paymentInFlight: false, posLookupPending: false, CSRF: 'csrf',
        POS_CHECKOUT_API: 'pos', CHECKOUT_API: 'cart', cartPayConfirmBtn: {
            addEventListener(name, handler) { events[name] = handler; },
        }, cartPayConfirmModal: { hide() {} }, window: {},
        fetch: async (url, { body }) => {
            calls++; Object.assign(fields, Object.fromEntries(body));
            await new Promise(resolve => { release = resolve; });
            return { json: async () => ({ success: true, reference: 'PAID' }) };
        }, showAlert(message) { alerts.push(message); }, updateUiState() {}, refreshCart: async () => {}, setPaused() {},
    });
    vm.runInContext(block('    cartPayConfirmBtn.addEventListener("click"', '    async function handleScannedPayload('), context);
    const payment = events.click();
    await events.click();
    assert.equal(calls, 1, 'Repeated confirmation must send one payment request');
    assert.equal(fields.action, 'pay_qr_order');
    assert.equal(fields.token, 'a'.repeat(32));
    release();
    await payment;
    assert.ok(alerts.some(message => message.includes('PAID')));
    assert.equal(context.paymentInFlight, false);

    const confirmation = vm.createContext({
        currentPendingOrder: { reference: 'CART-1', merchant_user_id: 10, merchant_label: 'Verified stall', lines: [], total: 25 },
        pendingWalletPayload: null, showAlert(message) { alerts.push(message); }, setPaused() {},
        cartSheet: { classList: { remove() {} } }, payConfirmMerchant: {}, payConfirmItemCount: {}, payConfirmTotal: {},
        payConfirmLineList: {}, document: { getElementById: () => ({}) }, gcAmt: String, escapeHtml: String,
        cartPayConfirmModal: { show() {} },
    });
    vm.runInContext(block('    function openPayConfirmModal(', '    async function lookupPosQr('), confirmation);
    confirmation.openPayConfirmModal({ merchant_user_id: 11 });
    assert.equal(confirmation.pendingWalletPayload, null, 'Wrong merchant must not open payment confirmation');
    confirmation.openPayConfirmModal({ merchant_user_id: 10, merchant: 'Untrusted QR name' });
    assert.equal(confirmation.pendingWalletPayload.order_reference, 'CART-1', 'Static wallet QR must bind checkout to the confirmed order');
    assert.equal(confirmation.payConfirmMerchant.textContent, 'Verified stall');
}
async function lookupChecks() {
    let calls = 0;
    let release;
    const context = vm.createContext({
        FormData, CSRF: 'csrf', POS_VALIDATE_API: 'validate', paymentInFlight: false,
        posLookupPending: false, pendingWalletPayload: null, setPaused() {},
        fetch: async () => {
            calls++;
            await new Promise(resolve => { release = resolve; });
            return { json: async () => ({ success: true, token: 'b'.repeat(32), merchant: 'Verified', amount: 25, lines: [] }) };
        }, cartSheet: { classList: { remove() {} } }, payConfirmMerchant: {}, payConfirmItemCount: {},
        payConfirmTotal: {}, payConfirmLineList: {}, gcAmt: String, escapeHtml: String,
        document: { getElementById: () => ({}) }, cartPayConfirmModal: { show() {} }, showAlert() {},
    });
    vm.runInContext(block('    async function lookupPosQr(', '    const posCodeModalEl ='), context);
    const lookup = context.lookupPosQr('CODE');
    await context.lookupPosQr('ANOTHER-CODE');
    assert.equal(calls, 1, 'Overlapping QR/code lookups must resolve a single bill');
    release(); await lookup;
    assert.equal(context.pendingWalletPayload.token, 'b'.repeat(32));
    assert.equal(context.posLookupPending, false);
    await context.lookupPosQr('CODE');
    assert.equal(calls, 1, 'An open confirmation must prevent another bill lookup');
}
(async () => {
    await cameraChecks(); await scanChecks(); await paymentChecks(); await lookupChecks();
    console.log('PASS: camera races, playback failure, scan recovery, repeated QR, lookup pause, duplicate payment, and merchant/order confirmation');
})().catch(error => { console.error(error); process.exitCode = 1; });
