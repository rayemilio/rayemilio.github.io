# Objects on Purpose

Objects on Purpose intentionally uses split hosting: the public portfolio is on GitHub Pages, and the password-protected live printer runs on its own NearlyFreeSpeech.NET (NFS) site. Both belong to this project. Neither uses the rayemilio.com backend or authentication.

## Where everything lives

| Part | Public address | Source | Runs on |
| --- | --- | --- | --- |
| Portfolio and featured project pages | https://objectsonpurpose.com/ | Repository root: `index.html`, project folders, `_resources/` | GitHub Pages |
| Public printer project page | https://objectsonpurpose.com/printer/ | `printer/index.html` | GitHub Pages |
| Live printer, sign-in, API, and device relay | https://printer.objectsonpurpose.com/ | `backend/printer/` | NFS site `objectsonpurpose` |
| Shared printer interface, simulator, Mac bridge, and ESP32 firmware | Local development; `http://printer.local/` on the printer's Wi-Fi | Separate local project: `/Users/rayemilio/Documents/Visual_Studio_Code/PHYSICAL_PRODUCTS/printer` | Mac / physical ESP32 |

Local checkout: `/Users/rayemilio/Documents/Visual_Studio_Code/WEB_PROJECTS/coding_portfolio`.
Git repository: <https://github.com/rayemilio/rayemilio.github.io>, publishing branch **`v2-design`**.

The GitHub repository name and NFS account username contain the owner's name; they do not connect this project to the separate personal website.

## Choose the deployment

| What changed? | Publish with |
| --- | --- |
| Portfolio text, layout, images, or a featured project page | Commit and push the intended files to `v2-design`; GitHub Pages publishes automatically. |
| Printer login styling, authentication, relay logic, or hosted console files | Test and run the printer's NFS deployment command. A Git push alone does not deploy these changes to NFS. |
| Shared printer interface | Build the firmware interface in the physical project, sync it into `backend/printer/`, then deploy the NFS backend. |
| ESP32 firmware or the device-served interface | Follow the physical project's firmware release/update instructions. An NFS deployment does not update the controller. |

Full commands, verification, and recovery guidance are in [DEPLOYMENT.md](DEPLOYMENT.md). Printer-specific runtime and authentication details are in [backend/printer/README.md](backend/printer/README.md).

## Credentials and runtime data

The repository holds source code and non-secret hosting configuration. GitHub Pages publishes static files from the repository root; it does not execute the PHP backend. Backend source in this public repository is not a secret.

The live printer password hash, device token, sessions, queue, and uploaded raster data live outside the NFS document root under `/home/protected/`. They are never committed or replaced by a routine code deployment. Local private migration/test files belong in ignored `.local/`; `_resources/docs/` is also ignored and is not the shared operations guide.
