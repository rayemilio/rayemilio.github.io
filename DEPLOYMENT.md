# Objects on Purpose deployment

The current arrangement is intentional: GitHub Pages hosts the public portfolio; a dedicated NFS site hosts the live printer. Keep these two publishing paths separate. Neither deployment targets the rayemilio.com project or hosting site.

## 1. Public portfolio → GitHub Pages

Verified publishing settings:

- Repository: `rayemilio/rayemilio.github.io`.
- Source branch: `v2-design`; source folder: repository root (`/`).
- Custom domain: `objectsonpurpose.com`; HTTPS enforced.
- `CNAME` contains `objectsonpurpose.com`; `.nojekyll` keeps the plain static-site layout.
- There is no root package build step. Pages serves the committed HTML, CSS, JavaScript, and other public files.

Edit the relevant HTML/assets, preview in the Codex in-app Browser or an isolated test browser, then review and commit the intended files. This example publishes an edit to the public printer project page; replace the staged path with the files you actually changed:

```sh
cd /Users/rayemilio/Documents/Visual_Studio_Code/WEB_PROJECTS/coding_portfolio
git branch --show-current
# Expected: v2-design
git status --short
git diff --check
git add -- printer/index.html
git diff --cached
git commit -m "Update printer project page"
git push origin v2-design
```

If the branch is different or unrelated work is present, resolve that before publishing. Include any new public images/styles referenced by the page. Do not stage credentials or private runtime files.

The VS Code task **Publish GitHub Pages** calls the shared local `deploy.sh`, which stages all changes, commits, and pushes the current branch. Review the branch and complete change set first. This task does not run the printer's NFS deployment.

After pushing, check the repository's **Actions** tab for the `pages-build-deployment` result, then open the affected URL and check its assets and links. With GitHub CLI, from the checkout:

```sh
gh run list --branch v2-design --limit 3 --json status,conclusion,url,headSha
```

Pushing `backend/printer/` saves its source to GitHub, but does not execute PHP or update the live printer service.

## 2. Live printer backend → NFS

The service is `https://printer.objectsonpurpose.com/`. Hosting identity is recorded in [backend/printer/hosting.json](backend/printer/hosting.json):

- NFS site: `objectsonpurpose`.
- SSH destination: `rayemilio_objectsonpurpose@ssh.nyc1.nearlyfreespeech.net`.
- Web PHP: 8.4 at migration; HTTPS certificates are automatically managed by NFS.
- DNS: `printer.objectsonpurpose.com` is a CNAME to `objectsonpurpose.nfshost.com`.

Use this site's SSH destination for every printer deployment. GitHub Pages continues to own the apex and `www` portfolio hostnames; routine code updates require no DNS changes.

Prerequisites: Python 3, PHP CLI, Node.js/npm, OpenSSH (`ssh`/`scp`), OpenSSL, and authorized SSH access to the dedicated site. The deployer uses `BatchMode=yes`, so SSH access must already work without an interactive password prompt. Browser sign-in to NFS does not by itself establish command-line SSH access.

From the backend directory, install test dependencies once:

```sh
cd /Users/rayemilio/Documents/Visual_Studio_Code/WEB_PROJECTS/coding_portfolio/backend/printer
npm install --ignore-scripts
npx playwright install chromium
```

Test the current code, then deploy it:

```sh
cd /Users/rayemilio/Documents/Visual_Studio_Code/WEB_PROJECTS/coding_portfolio/backend/printer
npm run test:relay
npm run test:http
python3 tools/deploy.py --host rayemilio_objectsonpurpose@ssh.nyc1.nearlyfreespeech.net
```

Tests use temporary credentials/state and a simulated device, with an isolated Playwright browser. They do not connect to a physical printer. The HTTP fixture uses local ports 8894 and 8896. Existing isolated browser/package installations can be selected with the environment variables documented in [the backend README](backend/printer/README.md#build-test-and-deploy).

The deployer checks its destination against `hosting.json`, runs PHP/relay checks, verifies uploaded file hashes, creates a new release under `/home/protected/releases/`, switches `/home/protected/app`, and installs only the entry point and routing rules into `/home/public/`.

**Do not upload the whole repository or backend directory into the NFS document root.** The release contains `app/` and `public/`; tests, tools, and documentation are source/development material. Routine deployment never copies or resets the password hash, device token, queue, raster uploads, or sessions. An empty replacement site requires separate private runtime provisioning before this code-only deployer can activate it.

Save backend changes in Git as well, using reviewed file paths and a descriptive commit, and push `v2-design`. Deploy the same reviewed source revision to NFS. A successful Pages build says nothing about whether NFS was updated; record the NFS release path printed by the deployer separately.

## 3. Shared printer interface → hosted console

The editable shared interface lives in the physical printer project, not in the generated hosted JavaScript bundle. For an interface change:

```sh
cd /Users/rayemilio/Documents/Visual_Studio_Code/PHYSICAL_PRODUCTS/printer
npm run build:firmware

cd /Users/rayemilio/Documents/Visual_Studio_Code/WEB_PROJECTS/coding_portfolio/backend/printer
node tools/sync-console.mjs /Users/rayemilio/Documents/Visual_Studio_Code/PHYSICAL_PRODUCTS/printer/dist-firmware
```

Use `dist-firmware`, which contains the device-compatible interface, rather than the Mac Compose build in `dist/`. Synchronization copies the referenced JS/CSS into `app/assets/`, rewrites `app/console.html`, and adds remote API mode and the CSRF placeholder. It does not publish or update firmware. Review the resulting files, run the backend tests, commit the synced files, and use the NFS deployment in section 2.

The sync tool can retain older content-addressed assets; only files referenced by the current console are active. Do not replace its input with development-server or Mac-bridge HTML.

## 4. Physical controller and local Mac tools

Physical project: `/Users/rayemilio/Documents/Visual_Studio_Code/PHYSICAL_PRODUCTS/printer`.
Its `README.md`, `docs/FIRMWARE_HANDOFF.md`, and versioned release guides describe local builds and the application-only firmware updater. The Mac bridge and simulator also live there.

Installed at the October 16, 2026 migration: classic ESP32 firmware **1.8.6**, using `https://printer.objectsonpurpose.com/printer-device.php`. Device Wi-Fi, pairing, password, and print settings were preserved. Verify current status before future firmware work; a firmware update is a separate hardware operation from either website deployment.

## Verify a backend deployment without printing

1. Open `https://printer.objectsonpurpose.com/` and sign in with the existing printer password. Confirm the console and assets load.
2. Open **Status**. If the controller is powered on and has internet access, confirm recent printer contact and its expected firmware version. A device shown offline can still mean a Wi-Fi or power issue even when the website works.
3. In a separate signed-out session, the console should show its password form and `GET /printer/api/v1/status` should return 401. The device endpoint requires its separate device token.
4. Confirm the public project page at `https://objectsonpurpose.com/printer/` still links to the live console.

No print request is required to check hosting, sign-in, or relay contact. “Printer ready” indicates a recent controller response, not paper availability or proof of physical output. The migration submitted no print jobs; paper output was not tested.

## Runtime ownership and recovery

On the dedicated NFS site:

```text
/home/public/index.php                     public entry point
/home/public/.htaccess                     public routing
/home/protected/releases/<release>/app/     versioned backend and console
/home/protected/app                        active app symlink
/home/protected/printer-password.hash       existing password hash, no username
/home/protected/printer-device-token        existing device pairing token
/home/protected/printer-relay.json          heartbeat, queue, and history
/home/protected/printer-raster/             uploaded raster data
/home/protected/printer-sessions/           independent browser sessions
```

Live runtime files are not a Git backup. Keep any runtime backups private and preserve their file permissions; never publish their contents. `.local/printer-migration-20261016/` holds ignored initial migration material on the original Mac; it is a historical snapshot, not a current copy of the live queue or an ongoing backup service.

For a portfolio regression, revert the affected Git commit and push `v2-design`; Pages republishes it. Reverting backend source in Git alone does not roll back NFS.

For a backend regression, either deploy a tested earlier source revision with the same deployer or restore the earlier app target recorded in a release's `previous-app`. The same release stores `previous-index.php` and `previous.htaccess` when those public files existed; restore those too if the entry point/routing changed. Switch the app symlink by atomic rename, as the deployer does. Restore code only; do not replace the live queue, sessions, password, or token with old snapshots, and do not replay historical jobs.

The rayemilio.com printer routes, account, backend, and active runtime are retired. Its other private-page accounts remain independent. Historical rollback records are inactive and are not a deployment destination or fallback.
