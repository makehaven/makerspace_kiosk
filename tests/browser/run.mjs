// Playwright is not a dependency of this module; it is borrowed from the
// site repo's playwright-tests/ install. Override with KIOSK_PLAYWRIGHT if you
// are running this module standalone.
const PLAYWRIGHT = process.env.KIOSK_PLAYWRIGHT
  || '../../../../../../playwright-tests/node_modules/playwright/index.mjs';
const { chromium } = await import(PLAYWRIGHT);
import { makeServer, modes } from './server.mjs';

const results = [];
function check(name, pass, detail = '') {
  results.push({ name, pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function withPage(opts, fn) {
  modes.feed = 'ok'; modes.probe = 'ok'; modes.payload = 1;
  const server = makeServer(opts);
  await new Promise(r => server.listen(0, r));
  const port = server.address().port;
  const base = `http://127.0.0.1:${port}`;
  const browser = await chromium.launch();
  const page = await browser.newPage();
  let navigations = 0;
  page.on('framenavigated', f => { if (f === page.mainFrame()) navigations++; });
  const ctl = (q) => fetch(`${base}/ctl?${q}`).then(r => r.text());
  await page.goto(base);
  try {
    await fn({ page, ctl, navs: () => navigations - 1, base });
  } finally {
    await browser.close();
    server.close();
  }
}

// ---------------------------------------------------------------- scenario 1
await withPage({ intervalMs: 150, jitterRatio: 0, staleAfterMs: 600 }, async ({ page }) => {
  await page.waitForFunction('window.__renders >= 3', null, { timeout: 5000 });
  const cls = await page.locator('.mk-status').getAttribute('class');
  check('happy path renders repeatedly', true, `${await page.evaluate('window.__renders')} renders`);
  check('chip shows healthy state', cls.includes('is-ok'), cls);
});

// ---------------------------------------------------------------- scenario 2
// A Pantheon deploy: the host answers 503. The board must hold its last good
// render, must NOT reload into the outage, and must back off.
await withPage({ intervalMs: 120, jitterRatio: 0, reloadAfterFailures: 3, staleAfterMs: 400 }, async ({ page, ctl, navs }) => {
  await page.waitForFunction('window.__renders >= 2', null, { timeout: 5000 });
  await ctl('feed=503');
  await sleep(1500);
  const text = await page.locator('#grid').textContent();
  const st = await page.evaluate('window.__kiosk.state()');
  const evs = await page.evaluate('window.__events');
  check('503 keeps last good render on screen', text === 'payload:1', text);
  check('503 does not trigger a reload', navs() === 0, `${navs()} reloads`);
  check('503 does not count toward reload threshold', st.consecutiveFailures === 0, JSON.stringify(st));
  check('503 backs the poll interval off', st.currentDelay > 120, `delay=${st.currentDelay}ms`);
  check('503 marks the board stale for staff', evs.includes('stale'), JSON.stringify(evs));
});

// ---------------------------------------------------------------- scenario 3
// Network-level failure with the site genuinely unreachable: failures count,
// but the probe must block the reload so we never strand on an error page.
await withPage({ intervalMs: 100, jitterRatio: 0, reloadAfterFailures: 3, staleAfterMs: 5000 }, async ({ page, ctl, navs }) => {
  await page.waitForFunction('window.__renders >= 1', null, { timeout: 5000 });
  await ctl('feed=drop&probe=drop');
  await sleep(1800);
  const evs = await page.evaluate('window.__events');
  check('network failure increments failure count',
    evs.some(e => e.startsWith('error:network:')), JSON.stringify(evs.slice(0, 4)));
  check('probe blocks reload while site is unreachable', navs() === 0, `${navs()} reloads`);
});

// ---------------------------------------------------------------- scenario 4
// Same failure, but the site is reachable again — the probe passes, so the
// wedged tab is allowed to recover itself.
await withPage({ intervalMs: 100, jitterRatio: 0, reloadAfterFailures: 3, staleAfterMs: 5000, reloadCooldownMs: 60000 }, async ({ page, ctl, navs }) => {
  await page.waitForFunction('window.__renders >= 1', null, { timeout: 5000 });
  await ctl('feed=drop&probe=ok');
  await sleep(3000);
  check('probe-gated reload fires once the site answers', navs() >= 1, `${navs()} reloads`);
  check('cooldown stops a broken feed from reload-looping', navs() === 1, `${navs()} reloads in 3s`);
});

// ------------------------------------------------------------- scenario 4b
// The cooldown must not be able to disable recovery permanently: a short
// cooldown lets a second attempt through once the window elapses.
await withPage({ intervalMs: 100, jitterRatio: 0, reloadAfterFailures: 2, staleAfterMs: 5000, reloadCooldownMs: 900 }, async ({ page, ctl, navs }) => {
  await page.waitForFunction('window.__renders >= 1', null, { timeout: 5000 });
  await ctl('feed=drop&probe=ok');
  await sleep(3500);
  check('recovery resumes after the cooldown window', navs() >= 2 && navs() <= 5, `${navs()} reloads in 3.5s`);
});

// ---------------------------------------------------------------- scenario 5
// Recovery: stale clears and cadence returns to normal.
await withPage({ intervalMs: 120, jitterRatio: 0, reloadAfterFailures: 99, staleAfterMs: 350 }, async ({ page, ctl }) => {
  await page.waitForFunction('window.__renders >= 2', null, { timeout: 5000 });
  await ctl('feed=503');
  await page.waitForFunction('window.__events.includes("stale")', null, { timeout: 5000 });
  const staleCls = await page.locator('.mk-status').getAttribute('class');
  await ctl('feed=ok&payload=7');
  await page.waitForFunction('window.__events.includes("recover")', null, { timeout: 8000 });
  const st = await page.evaluate('window.__kiosk.state()');
  const text = await page.locator('#grid').textContent();
  check('stale state is visible on the chip', staleCls.includes('is-stale'), staleCls);
  check('recovery clears stale and resets cadence', st.currentDelay === 120 && !st.stale, JSON.stringify(st));
  check('recovery renders the new payload', text === 'payload:7', text);
});

// ---------------------------------------------------------------- scenario 6
// A malformed payload is a real failure: the board is showing untruth.
await withPage({ intervalMs: 120, jitterRatio: 0, reloadAfterFailures: 99, staleAfterMs: 400 }, async ({ page, ctl }) => {
  await page.waitForFunction('window.__renders >= 2', null, { timeout: 5000 });
  const before = await page.evaluate('window.__renders');
  await ctl('feed=garbage');
  await sleep(900);
  const evs = await page.evaluate('window.__events');
  const after = await page.evaluate('window.__renders');
  check('unparseable payload counts as a failure',
    evs.some(e => e.startsWith('error:network:')), JSON.stringify(evs.slice(0, 3)));
  check('unparseable payload does not re-render the board', after === before, `${before} -> ${after}`);
});

// ---------------------------------------------------------------- scenario 7
await withPage({ intervalMs: 200, jitterRatio: 0 }, async ({ page }) => {
  await page.waitForFunction('window.__renders >= 1', null, { timeout: 5000 });
  const j = await page.evaluate(`(function(){
    var vals=[]; for (var i=0;i<200;i++) vals.push(MakerspaceKiosk._internals.jitter(1000, 0.1));
    return { min: Math.min.apply(null, vals), max: Math.max.apply(null, vals), uniq: new Set(vals).size };
  })()`);
  check('jitter spreads the fleet without runaway drift',
    j.min >= 900 && j.max <= 1100 && j.uniq > 50, JSON.stringify(j));
});

// ---------------------------------------------------------------- scenario 8
// A slow feed is a live loop, not a wedged tab. The watchdog must not reload
// out from under an in-flight request - doing so during the outage that made
// the server slow is precisely the wrong move.
await withPage({ intervalMs: 200, jitterRatio: 0, wedgeFactor: 2, requestTimeoutMs: 5000, reloadCooldownMs: 0 }, async ({ page, ctl, navs }) => {
  await page.waitForFunction('window.__renders >= 1', null, { timeout: 5000 });
  await ctl('feed=slow');
  await sleep(4000);
  check('slow feed does not trip the wedge watchdog', navs() === 0, `${navs()} reloads`);
  check('slow feed still renders when it answers',
    (await page.evaluate('window.__renders')) >= 2, `${await page.evaluate('window.__renders')} renders`);
});

// ---------------------------------------------------------------- scenario 9
// A connection that opens and never answers must not stall the loop forever
// behind the overlap guard. requestTimeoutMs bounds it into a normal failure.
await withPage({ intervalMs: 200, jitterRatio: 0, requestTimeoutMs: 700, reloadAfterFailures: 99, staleAfterMs: 900 }, async ({ page, ctl }) => {
  await page.waitForFunction('window.__renders >= 1', null, { timeout: 5000 });
  await ctl('feed=hang');
  await sleep(3000);
  const evs = await page.evaluate('window.__events');
  check('hung request times out instead of stalling the loop',
    evs.filter(e => e.startsWith('error:network:')).length >= 2, JSON.stringify(evs.slice(0, 4)));
  check('hung request still surfaces as stale', evs.includes('stale'), JSON.stringify(evs.slice(-3)));
});

console.log('');
const failed = results.filter(r => !r.pass);
console.log(`${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
