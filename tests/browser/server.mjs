import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const MODULE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const RUNTIME = fs.readFileSync(path.join(MODULE_ROOT, 'js/kiosk-resilience.js'), 'utf8');
const CSS = fs.readFileSync(path.join(MODULE_ROOT, 'css/kiosk-status.css'), 'utf8');

export const modes = { feed: 'ok', probe: 'ok', payload: 1 };

const PAGE = (opts) => `<!doctype html><meta charset=utf-8>
<style>${CSS}</style>
<body style="background:#000">
<div id="grid"></div>
<script>window.__events=[];window.__renders=0;</script>
<script>${RUNTIME}
window.__kiosk = MakerspaceKiosk.start(Object.assign({
  feedUrl: '/feed',
  screenId: 'harness',
  onData: function (d) {
    window.__renders++;
    document.getElementById('grid').textContent = 'payload:' + d.payload;
  },
  onError: function (info) { window.__events.push('error:' + info.kind + ':' + info.consecutiveFailures); },
  onStale: function () { window.__events.push('stale'); },
  onRecover: function () { window.__events.push('recover'); }
}, ${JSON.stringify(opts)}));
</script>`;

export function makeServer(opts) {
  return http.createServer((req, res) => {
    const url = new URL(req.url, 'http://x');

    if (url.pathname === '/ctl') {
      for (const k of ['feed', 'probe']) {
        if (url.searchParams.has(k)) modes[k] = url.searchParams.get(k);
      }
      if (url.searchParams.has('payload')) modes.payload = Number(url.searchParams.get('payload'));
      res.writeHead(200).end('ok');
      return;
    }

    if (url.pathname === '/feed') {
      if (modes.feed === 'drop') { req.socket.destroy(); return; }
      if (modes.feed === '503') { res.writeHead(503).end('deploying'); return; }
      if (modes.feed === 'garbage') { res.writeHead(200, {'content-type':'application/json'}).end('not json'); return; }
      res.writeHead(200, { 'content-type': 'application/json' })
         .end(JSON.stringify({ payload: modes.payload }));
      return;
    }

    // Page + probe target.
    if (req.method === 'HEAD') {
      if (modes.probe === 'drop') { req.socket.destroy(); return; }
      if (modes.probe === '503') { res.writeHead(503).end(); return; }
      res.writeHead(200).end();
      return;
    }
    res.writeHead(200, { 'content-type': 'text/html' }).end(PAGE(opts));
  });
}
