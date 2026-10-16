import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const usage = "Usage: node tools/sync-console.mjs <absolute-or-relative-printer-dist-directory>";

function inside(parent, child) {
  const relative = path.relative(parent, child);
  return relative === "" || (!relative.startsWith(`..${path.sep}`) && relative !== ".." && !path.isAbsolute(relative));
}

function requiredFile(file, description) {
  let real;
  try { real = fs.realpathSync(file); } catch { throw new Error(`${description} does not exist: ${file}`); }
  if (!fs.statSync(real).isFile()) throw new Error(`${description} is not a file: ${file}`);
  return real;
}

function readBuildArtifacts(distPath) {
  let dist;
  try { dist = fs.realpathSync(distPath); } catch { throw new Error(`Printer dist directory does not exist: ${distPath}`); }
  if (!fs.statSync(dist).isDirectory()) throw new Error(`Printer dist path is not a directory: ${distPath}`);
  const htmlFile = requiredFile(path.join(dist, "index.html"), "Printer build index");
  if (!inside(dist, htmlFile)) throw new Error("Printer build index escapes the supplied dist directory.");
  const html = fs.readFileSync(htmlFile, "utf8");
  const developmentMarkers = [
    /<meta\s+name=["']printer-dev-loop["']/i,
    /<meta\s+name=["']printer-build-id["']/i,
    /window\.__printerDevLoop\b/i,
    /\/__mac_dev\/state/i,
    /mac-dev-loop-notice|mac-printer-notice|mac-bridge/i,
  ];
  if (developmentMarkers.some((pattern) => pattern.test(html))) {
    throw new Error("Printer build contains a Mac/dev-loop injected marker or script; sync a production Vite dist instead.");
  }
  if ((html.match(/<head\b[^>]*>/gi) || []).length !== 1 || (html.match(/<\/head\s*>/gi) || []).length !== 1 || !/<div\s+id=["']app["'][^>]*>/i.test(html)) {
    throw new Error("Printer build index must be a complete app HTML shell with one head and an app mount.");
  }
  const scripts = [...html.matchAll(/<script\b[^>]*\bsrc=(['"])([^'"]+)\1[^>]*>/gi)].map((match) => match[2]);
  const styles = [...html.matchAll(/<link\b(?=[^>]*\brel=(['"])stylesheet\1)[^>]*\bhref=(['"])([^'"]+)\2[^>]*>/gi)].map((match) => match[3]);
  const isLocalAsset = (value, extension) => /^\/?(?:\.\/)?assets\/[A-Za-z0-9._-]+\.(?:js|css)$/.test(value) && value.endsWith(`.${extension}`);
  const script = scripts.find((value) => isLocalAsset(value, "js"));
  const style = styles.find((value) => isLocalAsset(value, "css"));
  if (!script || !style || scripts.filter((value) => isLocalAsset(value, "js")).length !== 1 || styles.filter((value) => isLocalAsset(value, "css")).length !== 1) {
    throw new Error("Printer build must expose exactly one local JavaScript entry and one local stylesheet in index.html.");
  }
  const asset = (reference, extension) => {
    const relative = reference.replace(/^\//, "").replace(/^\.\//, "");
    const file = requiredFile(path.resolve(dist, relative), `Printer ${extension} asset`);
    if (!inside(dist, file) || !inside(path.join(dist, "assets"), file) || path.extname(file) !== `.${extension}`) {
      throw new Error(`Printer ${extension} asset escapes the supplied dist assets directory.`);
    }
    return file;
  };
  return { js: asset(script, "js"), css: asset(style, "css"), html, scriptRef: script, styleRef: style };
}

export function syncPrinter(sourceDist, siteRoot = root) {
  const destinationRoot = fs.realpathSync(siteRoot);
  const pagePath = path.join(destinationRoot, "app", "console.html");
  if (!inside(destinationRoot, pagePath)) throw new Error("Hosted printer page escapes the website root.");
  const artifacts = readBuildArtifacts(sourceDist);
  let html = artifacts.html;
  if (/<meta\s+name=["']printer-(?:console-mode|api-prefix)["']/i.test(html) || /<!--\s*PRIVATE_CSRF_META\s*-->/i.test(html)) {
    throw new Error("Printer source dist must not contain host-only console, API, or CSRF metadata.");
  }
  const shellHead = html.match(/<head\b[^>]*>/i);
  if (!shellHead) throw new Error("Printer source dist is missing its head element.");
  const hostedMetadata = `\n    <meta name="printer-console-mode" content="remote" />\n    <meta name="printer-api-prefix" content="/printer/api/v1/" />\n    <!-- PRIVATE_CSRF_META -->`;
  html = html.replace(shellHead[0], `${shellHead[0]}${hostedMetadata}`);
  const escaped = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const scriptPattern = new RegExp(`(<script\\b[^>]*\\bsrc=["'])${escaped(artifacts.scriptRef)}(["'][^>]*>)`, "g");
  const stylePattern = new RegExp(`(<link\\b(?=[^>]*\\brel=["']stylesheet["'])[^>]*\\bhref=["'])${escaped(artifacts.styleRef)}(["'][^>]*>)`, "g");
  if ((html.match(scriptPattern) || []).length !== 1 || (html.match(stylePattern) || []).length !== 1) {
    throw new Error("Printer build asset references could not be safely rewritten for the hosted page.");
  }
  html = html.replace(scriptPattern, `$1/assets/${path.basename(artifacts.js)}$2`);
  html = html.replace(stylePattern, `$1/assets/${path.basename(artifacts.css)}$2`);

  const assetRoot = path.join(destinationRoot, "app", "assets");
  fs.mkdirSync(assetRoot, { recursive: true });
  const jsName = path.basename(artifacts.js);
  const cssName = path.basename(artifacts.css);
  fs.copyFileSync(artifacts.js, path.join(assetRoot, jsName));
  fs.copyFileSync(artifacts.css, path.join(assetRoot, cssName));
  const temporaryPage = `${pagePath}.sync-${process.pid}`;
  try {
    fs.writeFileSync(temporaryPage, html, { mode: 0o644 });
    fs.renameSync(temporaryPage, pagePath);
  } finally {
    if (fs.existsSync(temporaryPage)) fs.unlinkSync(temporaryPage);
  }
  return { js: `app/assets/${jsName}`, css: `app/assets/${cssName}`, page: "app/console.html" };
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const sourceDist = process.argv[2];
  if (!sourceDist || process.argv.length !== 3) {
    console.error(usage);
    process.exitCode = 2;
  } else {
    try {
      const synced = syncPrinter(sourceDist);
      console.log(`Synced ${synced.js}, ${synced.css}, and ${synced.page}; remote and CSRF metadata preserved.`);
    } catch (error) {
      console.error(error instanceof Error ? error.message : String(error));
      process.exitCode = 1;
    }
  }
}
