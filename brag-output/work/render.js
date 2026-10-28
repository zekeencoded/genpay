// usage: node render.js stills t1,t2,...   |   node render.js frames
const puppeteer = require(process.env.PPTR);
const path = require('path'), fs = require('fs');
const FPS = 30, DUR = 52;
(async () => {
  const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: 'new', args: ['--hide-scrollbars'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1920, height: 1080 });
  await page.goto('file:///' + path.join(__dirname, 'index.html').replace(/\\/g, '/'), { waitUntil: 'networkidle0' });
  // hidden scenes don't trigger font loads, so request every weight up front
  await page.evaluate(async () => {
    await Promise.all(['500', '600', '700', '800'].map(w => document.fonts.load(`${w} 40px "Plus Jakarta Sans"`))
      .concat(['500', '600', '700'].map(w => document.fonts.load(`${w} 40px "IBM Plex Mono"`)),
        document.fonts.load('900 40px "Font Awesome 6 Free"')));
    await document.fonts.ready;
  });
  const ok = await page.evaluate(() => document.fonts.check('800 40px "Plus Jakarta Sans"') && document.fonts.check('700 40px "IBM Plex Mono"'));
  if (!ok) throw new Error('fonts did not load');
  const [mode, list] = process.argv.slice(2);
  const outDir = path.join(__dirname, mode);
  fs.mkdirSync(outDir, { recursive: true });
  const times = mode === 'stills' ? list.split(',').map(Number) : [...Array(FPS * DUR).keys()].map(i => i / FPS);
  for (const [i, t] of times.entries()) {
    await page.evaluate(t => seek(t), t);
    const name = mode === 'stills' ? `s_${t.toFixed(2)}.jpg` : `f_${String(i).padStart(5, '0')}.jpg`;
    await page.screenshot({ path: path.join(outDir, name), type: 'jpeg', quality: 92 });
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
