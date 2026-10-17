# Objects on Purpose — printer service

Standalone PHP backend for <https://printer.objectsonpurpose.com/>. This code,
authentication, device relay, releases and runtime state belong to Objects on
Purpose. The main portfolio remains on GitHub Pages; this PHP service runs on a
dedicated hosting site. There are no selectable accounts or dependencies on the
personal website.

## Deployment boundary

See the project-wide [deployment guide](../../DEPLOYMENT.md) for the two publishing paths and the [project overview](../../README.md) for source locations. The public portfolio is published automatically from the `v2-design` branch root on GitHub Pages. This service is deployed explicitly to NFS; committing or pushing its source does not update the live service.

Current NFS destination: `rayemilio_objectsonpurpose@ssh.nyc1.nearlyfreespeech.net`, site `objectsonpurpose`; confirm `hosting.json` before deploying. Run all commands below from `coding_portfolio/backend/printer/` unless the physical project is named explicitly.

## Layout

- `app/`: password-only authentication, relay, console and protected browser assets.
- `public/`: the only public server files: front controller and Apache routing.
- `tests/`: authentication, CSRF, session isolation, queue, device and raster tests.
- `tools/`: frontend synchronization and code-only deployment.
- `hosting.json`: non-secret identity of the dedicated hosting site.

On the server, code is installed under `/home/protected/releases/<release>/app`,
with `/home/protected/app` pointing to the active release. Only `public/` is
copied to `/home/public`. Never deploy this directory itself as a document root.
The PHP source in the portfolio repository contains no live credentials.

Runtime files live outside the document root and are never overwritten by deploys:

```text
/home/protected/printer-password.hash   one password hash, without a username
/home/protected/printer-device-token   device pairing token
/home/protected/printer-relay.json     device heartbeat and job history/queue
/home/protected/printer-raster/        uploaded bitmap chunks
/home/protected/printer-sessions/      sessions for this service only
```

The browser uses its own `__Host-oop_printer` session cookie with Secure, HttpOnly
and SameSite=Strict. Password changes invalidate existing sessions. Sessions expire
after 30 minutes of inactivity or 12 hours total. Mutations require a session CSRF
token and same-origin request. Credentials and state must never be committed to Git.
The existing password hash is preserved during the initial migration; no password
reset is needed. The device token is independent of the browser password.

## Routes

- `/`: sign-in or authenticated console.
- `/assets/<filename>`: authenticated console assets.
- `/printer/api/v1/...`: authenticated status and print queue API.
- `/logout/`: POST with the current session CSRF token.
- `/printer-device.php`: token-authenticated ESP32 relay endpoint.

The device must use `https://printer.objectsonpurpose.com/printer-device.php`.
Firmware 1.8.6 migrates its saved relay address while preserving Wi-Fi, pairing,
baud and print settings. Do not leave an old-site proxy or fallback endpoint.

## Build, test and deploy

Build the shared interface in the physical printer project with
`npm run build:firmware`, then synchronize its production output:

```sh
node tools/sync-console.mjs /Users/rayemilio/Documents/Visual_Studio_Code/PHYSICAL_PRODUCTS/printer/dist-firmware
```

The synchronization adds remote mode, the API prefix, and the CSRF injection
marker. It copies only the exact referenced JS and CSS. It does not publish.

```sh
npm install --ignore-scripts
npx playwright install chromium
npm run test:relay
npm run test:http
python3 tools/deploy.py --host rayemilio_objectsonpurpose@ssh.nyc1.nearlyfreespeech.net
```

The browser tests create a temporary service, disposable password/token and
simulated device on local ports 8894/8896. They never submit a physical print. Use a Playwright-managed
isolated Chromium installation; `PRINTER_TEST_BROWSER` may identify an existing
dedicated test executable. Never use an everyday browser profile. Optional
`PRINTER_TEST_ARTIFACTS` selects the screenshot/report directory and
`PRINTER_PLAYWRIGHT_MODULE` selects an existing test-package installation.

Deployment verifies uploaded hashes, runs PHP/relay checks, and atomically switches
the app release. It refuses destinations not matching `hosting.json`. Keep the
previous app link for rollback; never roll back runtime files over newer jobs.
The initial runtime migration and DNS/alias transfer are a separate operation.

## Installed migration — October 16, 2026

The independent `objectsonpurpose` hosting site is live with PHP 8.4 and automatic HTTPS. The existing printer password hash and device token were preserved, with 19 terminal job records. No personal accounts or sessions were transferred. Firmware 1.8.6 is installed and its genuine heartbeat reaches this service. Sign-in, protected API/assets and session isolation were verified on the live domain; no physical print request was submitted. The former personal-site printer paths and identity are retired. Historical rollback material is private and inactive.
