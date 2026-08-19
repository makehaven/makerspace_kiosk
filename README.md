# Makerspace Kiosk

Shared resilience runtime for MakeHaven's unattended sign and kiosk displays.

These pages run on wall-mounted Raspberry Pis for months with no keyboard and
nobody watching. Nothing external recovers them. This module is the one place
that logic lives, so a fix lands on every screen instead of on whichever display
someone remembered to patch.

It exists because the fix did not propagate the last time. `facilitator_display`
grew self-healing in July 2026 (`da3fd4e`); a month later the other four
displays still had none, and `access_display` was failing **silently** — its
poll loop swallowed every error and its grid was append-only, so a dead feed
rendered identically to a live one.

## What it does

| Behaviour | Why |
|---|---|
| **Visible status chip** — clock, freshness, state | A stale board must never look like a live one. A ticking clock proves JS is alive; a climbing age proves the feed is not. Staff triage from the doorway. |
| **Probe-gated reload** | A reload that lands on a browser error page strands the kiosk with no JS left to retry. Every reload waits for a HEAD probe to prove the site answers. |
| **Reload cooldown, persisted across the reload** | A broken feed behind a healthy page otherwise reload-loops forever — fixing nothing, flickering the board, and billing a page load each cycle. |
| **HTTP errors ≠ network errors** | A 5xx means the host is up and deploying. Hold the last good render and back off; never reload into a deploy. Only network-level failure suggests a wedged tab. |
| **Exponential backoff** | Hammering a down site at 30s helps nobody, and Pantheon counts CDN hits toward billed traffic. |
| **Jittered intervals** | Screens reboot on the same cron and poll on the same cadence, so they converge into a thundering herd. Everything is jittered ±10%. |
| **Request timeout** | `fetch()` has no default timeout. A connection that opens and then hangs — half-dead server, captive portal, Pi that lost its route — would stall the loop forever behind the overlap guard. |
| **Wedge watchdog** | Wall-clock, not timer-count, so it survives a tab whose timers the browser suspended. Measures whether a tick *started*, never whether one *finished*: a request in flight is a live loop waiting on a slow server, and reloading it mid-request is the wrong move during the very outage that made it slow. |
| **Daily reload at a wall-clock hour** | A tab leaking memory should not run for weeks. Anchored to an hour rather than "24h after boot", which drifts into the middle of the day. |
| **Resume on visibility/online** | A display woken from sleep or an HDMI switch polls at once instead of waiting out its delay. |

## Usage

The existing displays emit raw HTML `Response` objects rather than render
arrays, so they cannot attach an asset library. The service hands back the same
assets inline — which is the better choice here anyway: a self-contained page
can heal itself without fetching anything else during the outage that broke it.

```php
// In your controller.
$kiosk = \Drupal::service('makerspace_kiosk.resilience');

$styles = $kiosk->styles();
$script = $kiosk->script(
  [
    'feedUrl'     => $feed_url,
    'screenId'    => 'faces',
    'intervalMs'  => 30000,
    'statusChipPosition' => 'bottom-right',
  ],
  [
    // Raw JS source. Throwing in here counts as a failure, which is correct:
    // a render that failed means the board is showing something wrong.
    'onData' => 'function (data) { render(data.items || []); }',
  ]
);
```

Inject the service properly in new code; `\Drupal::service()` above is shorthand
for the README.

Emit `$styles` in `<head>` and `$script` immediately before `</body>`, after the
markup your `onData` targets.

### Options

See `DEFAULTS` at the top of `js/kiosk-resilience.js` — it is the authoritative
list and each option carries its rationale. The ones you will actually set:

- `feedUrl` (required), `screenId`, `intervalMs`
- `staleAfterMs` — defaults to 6× the interval (about five missed polls)
- `requestTimeoutMs` — 20s by default; must stay well under `intervalMs × wedgeFactor`
- `statusChip`, `statusChipPosition` — `bottom-right` by default
- `dailyReloadHour` — `4` by default; `null` disables

For a light-background board, add the `mk-status--on-light` class to the chip.

### Screen identity, and monitoring for free

Passing `screenId` appends `?screen=<id>` to the feed request. It rides along on
a request we were making anyway, so the server can record last-seen per screen
without a single extra poll. Nothing consumes this yet — it is the hook for
"which sign is dead?" monitoring without adding traffic to a site already over
its Pantheon allowance.

## Tests

`tests/browser/` drives the real runtime in a real browser against a
controllable feed — 503s, dropped sockets, malformed payloads, recovery — rather
than unit-testing helpers in isolation. Kiosk bugs live in the failure paths, so
the failure paths are what gets exercised.

```bash
cd tests/browser && node run.mjs
```

Playwright is borrowed from the site repo's `playwright-tests/` install;
override with `KIOSK_PLAYWRIGHT=<path to playwright/index.mjs>`.

## Consumers

`access_display`, `facilitator_display`, `makerspace_screen_slideshow`,
`makehaven_tasks`. Each declares `makerspace_kiosk` in its `.info.yml`, so it
must be enabled first — note the ordering on any deploy that adds a consumer.

Fleet inventory, per-screen URLs, and the Raspberry Pi build recipes live in the
site repo at `docs/ops/DISPLAYS.md`.
