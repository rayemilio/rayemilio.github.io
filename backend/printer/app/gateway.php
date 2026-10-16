<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/auth.php';

function oop_printer_stream_asset(string $name): void
{
    if (!preg_match('/^[A-Za-z0-9._-]+\.(js|css|woff2|png|svg)$/D', $name, $match)) {
        throw new OopPrinterHttpException('Not found.', 404);
    }
    $root = realpath(__DIR__ . '/assets');
    $file = realpath(__DIR__ . '/assets/' . $name);
    if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
        throw new OopPrinterHttpException('Not found.', 404);
    }
    $mime = ['js' => 'text/javascript; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'woff2' => 'font/woff2', 'png' => 'image/png', 'svg' => 'image/svg+xml'];
    oop_printer_headers($mime[$match[1]]);
    header('Content-Length: ' . filesize($file));
    session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') readfile($file);
}

function oop_printer_render_console(): void
{
    $html = file_get_contents(__DIR__ . '/console.html');
    if (!is_string($html)) throw new RuntimeException('Printer console is unavailable.');
    $csrf = oop_printer_escape((string)$_SESSION['csrf']);
    $html = str_replace('<!-- PRIVATE_CSRF_META -->', '<meta name="private-csrf-token" content="' . $csrf . '">', $html);
    $logout = '<form class="printer-session-control" method="post" action="/logout/"><input type="hidden" name="csrf" value="' . $csrf . '"><button type="submit">Sign out ↗</button></form>';
    $logout .= <<<'HTML'
<style>
 .printer-session-control { position: fixed; right: 16px; bottom: 16px; z-index: 10; }
 .printer-session-control button { background: #fafaf8; color: #111; border: 1px solid #d1cec6; padding: 10px 14px; font: inherit; cursor: pointer; }
 .printer-session-control.in-sidebar { position: static; margin: 0; padding-top: 16px; border-top: 1px solid var(--line, #d4d4d4); }
 .printer-session-control.in-sidebar button { border: 0; background: transparent; color: var(--muted, #666); padding: 10px 0; font: inherit; font-size: 12px; }
 .printer-session-control button:hover { text-decoration: underline; text-underline-offset: 3px; }
</style>
<script>
 window.addEventListener('DOMContentLoaded', function () {
   const form = document.querySelector('.printer-session-control');
   const sidebar = document.querySelector('#navigation-panel .aside-bottom');
   if (form && sidebar) { form.classList.add('in-sidebar'); sidebar.appendChild(form); }
 }, { once: true });
</script>
HTML;
    $html = str_replace('</body>', $logout . "\n</body>", $html);
    header('Content-Length: ' . strlen($html));
    session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo $html;
}

function oop_printer_main(): void
{
    try {
        $host = preg_replace('/:\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
        $config = json_decode((string)file_get_contents(__DIR__ . '/host.json'), true, 16, JSON_THROW_ON_ERROR);
        if (!in_array($host, $config['hosts'] ?? [], true)) throw new OopPrinterHttpException('Not found.', 404);
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || preg_match('/%(?:2f|5c|00)/i', $path)) throw new OopPrinterHttpException('Not found.', 404);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($path === '/printer-device.php') { require __DIR__ . '/device.php'; return; }
        oop_printer_headers();
        $page = in_array($path, ['/', '/index.php', '/index.html'], true);
        $logout = $path === '/logout/';
        $asset = preg_match('#^/assets/([A-Za-z0-9._-]+)$#D', $path, $assetMatch) === 1;
        $api = preg_match('#^/printer/api/v1/(status|jobs|jobs/cancel|jobs/raster/(?:start|chunk|commit|abort))$#D', $path, $apiMatch) === 1;
        if (!$page && !$logout && !$asset && !$api) throw new OopPrinterHttpException('Not found.', 404);
        if ((!$api && !in_array($method, $logout ? ['POST'] : ($page ? ['GET', 'HEAD', 'POST'] : ['GET', 'HEAD']), true))) {
            throw new OopPrinterHttpException('Method not allowed.', 405);
        }
        if (!$page && !oop_printer_existing_cookie()) throw new OopPrinterHttpException('Sign in required.', 401);
        $authenticated = oop_printer_start_session();
        if ($logout) { if (!$authenticated) throw new OopPrinterHttpException('Sign in required.', 401); oop_printer_logout(); }
        if (!$authenticated) {
            if (!$page) throw new OopPrinterHttpException('Sign in required.', 401);
            $error = $method === 'POST' ? oop_printer_login() : null;
            oop_printer_render_login($error);
            return;
        }
        if ($api) {
            if ($method !== 'GET') oop_printer_validate_csrf(true);
            session_write_close();
            require_once __DIR__ . '/relay-api.php';
            oop_printer_owner_api($apiMatch[1]);
        }
        if ($asset) { oop_printer_stream_asset($assetMatch[1]); return; }
        if ($method === 'POST') throw new OopPrinterHttpException('Method not allowed.', 405);
        oop_printer_render_console();
    } catch (OopPrinterHttpException $error) {
        oop_printer_headers('text/plain; charset=UTF-8');
        http_response_code($error->status);
        echo $error->getMessage();
    } catch (Throwable $error) {
        error_log('Objects on Purpose printer: ' . $error->getMessage());
        oop_printer_headers('text/plain; charset=UTF-8');
        http_response_code(500);
        echo 'The printer is temporarily unavailable.';
    }
}

if (!defined('OOP_PRINTER_TEST')) oop_printer_main();
