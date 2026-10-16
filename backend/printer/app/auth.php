<?php
declare(strict_types=1);

require_once __DIR__ . '/relay-lib.php';
require_once __DIR__ . '/password.php';

const OOP_PRINTER_COOKIE = '__Host-oop_printer';

final class OopPrinterHttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}

function oop_printer_headers(string $type = 'text/html; charset=UTF-8'): void
{
    header('Content-Type: ' . $type);
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate');
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function oop_printer_password_hash(): string
{
    $file = oop_printer_site_root() . '/protected/printer-password.hash';
    $hash = is_file($file) ? trim((string)file_get_contents($file)) : '';
    if (!preg_match('/^(?:\$apr1\$[A-Za-z0-9.\/]{1,8}\$[A-Za-z0-9.\/]{22}|\$2[aby]\$\d\d\$[A-Za-z0-9.\/]{53})$/D', $hash)) {
        throw new RuntimeException('Printer password storage is unavailable.');
    }
    return $hash;
}

function oop_printer_session_directory(): string
{
    return oop_printer_site_root() . '/protected/printer-sessions';
}

function oop_printer_existing_cookie(): bool
{
    $id = $_COOKIE[OOP_PRINTER_COOKIE] ?? '';
    return is_string($id) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $id) === 1
        && is_file(oop_printer_session_directory() . '/sess_' . $id);
}

function oop_printer_start_session(): bool
{
    $directory = oop_printer_session_directory();
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Printer session storage is unavailable.');
    }
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', '43200');
    session_save_path($directory);
    session_name(OOP_PRINTER_COOKIE);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    if (!session_start()) throw new RuntimeException('Printer session could not be started.');
    $now = time();
    $fingerprint = hash('sha256', oop_printer_password_hash());
    $authenticated = ($_SESSION['authenticated'] ?? false) === true
        && isset($_SESSION['password_revision']) && is_string($_SESSION['password_revision'])
        && hash_equals($fingerprint, $_SESSION['password_revision'])
        && ($now - (int)($_SESSION['login_at'] ?? 0)) <= 43200
        && ($now - (int)($_SESSION['last_activity'] ?? 0)) <= 1800;
    if (!$authenticated) {
        unset($_SESSION['authenticated'], $_SESSION['password_revision'], $_SESSION['login_at'], $_SESSION['last_activity'], $_SESSION['rotated_at']);
    } else {
        $_SESSION['last_activity'] = $now;
        if ($now - (int)($_SESSION['rotated_at'] ?? 0) > 900) {
            session_regenerate_id(true);
            $_SESSION['rotated_at'] = $now;
        }
    }
    if (!is_string($_SESSION['csrf'] ?? null)) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $authenticated;
}

function oop_printer_validate_origin(): void
{
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
        throw new OopPrinterHttpException('Cross-site request rejected.', 403);
    }
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $expected = 'https://' . strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (!hash_equals($expected, strtolower($origin))) {
            throw new OopPrinterHttpException('Cross-site request rejected.', 403);
        }
    }
}

function oop_printer_validate_csrf(bool $json = false): void
{
    oop_printer_validate_origin();
    $token = $json ? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') : ($_POST['csrf'] ?? '');
    if (!is_string($token) || $token === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        throw new OopPrinterHttpException('Request validation failed.', 403);
    }
}

function oop_printer_login(): ?string
{
    oop_printer_validate_csrf();
    // This service has one password, no user records or selectable accounts.
    if (array_diff(array_keys($_POST), ['csrf', 'password']) !== []) {
        throw new OopPrinterHttpException('Unexpected sign-in field.', 400);
    }
    $password = $_POST['password'] ?? '';
    if (!is_string($password) || strlen($password) > 1024 || $password === ''
        || !oop_printer_verify_password($password, oop_printer_password_hash())) {
        $attempts = min(8, (int)($_SESSION['failed_attempts'] ?? 0) + 1);
        $_SESSION['failed_attempts'] = $attempts;
        usleep((min(1500, 150 * (2 ** min(3, $attempts - 1))) + random_int(0, 150)) * 1000);
        return 'Password not recognized.';
    }
    session_regenerate_id(true);
    unset($_SESSION['failed_attempts']);
    $_SESSION['authenticated'] = true;
    $_SESSION['password_revision'] = hash('sha256', oop_printer_password_hash());
    $_SESSION['login_at'] = $_SESSION['last_activity'] = $_SESSION['rotated_at'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    header('Location: /', true, 303);
    exit;
}

function oop_printer_logout(): never
{
    oop_printer_validate_csrf();
    $_SESSION = [];
    setcookie(OOP_PRINTER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_destroy();
    header('Location: /', true, 303);
    exit;
}

function oop_printer_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function oop_printer_render_login(?string $error = null): void
{
    http_response_code($error === null ? 200 : 401);
    $csrf = oop_printer_escape((string)$_SESSION['csrf']);
    $styles = file_get_contents(__DIR__ . '/login.css');
    $alert = $error === null ? '' : '<p class="access-alert" role="alert">' . oop_printer_escape($error) . '</p>';
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate">
  <meta name="referrer" content="same-origin">
  <title>Printer — Objects on Purpose</title>
  <style>
    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body { margin: 0; background: var(--paper); color: var(--ink); font-family: var(--sans); -webkit-font-smoothing: antialiased; }
    .access-frame { margin: 0 auto; display: grid; grid-template-rows: auto 1fr; }
    .access-nav { display: flex; justify-content: space-between; align-items: center; border-bottom: var(--rule); text-transform: uppercase; }
    .access-brand { color: inherit; text-decoration: none; }
    .access-main { display: grid; align-items: center; }
    .access-card { width: 100%; margin: 0 auto; }
    h1 { margin: 0; }
    .access-kicker, .access-field span, .access-submit { text-transform: uppercase; }
    .access-field, .access-field span { display: block; }
    .access-field input, .access-submit { width: 100%; border-radius: 0; appearance: none; }
    .access-submit { cursor: pointer; }
    {$styles}
  </style>
</head>
<body>
  <div class="access-frame">
    <header class="access-nav"><a class="access-brand" href="https://objectsonpurpose.com/printer/">Objects on Purpose</a></header>
    <main class="access-main">
      <section class="access-card" aria-labelledby="access-title">
        <p class="access-kicker">Live printer</p>
        <h1 id="access-title">Printer</h1>
        <p class="access-copy">Enter the password to open the live printer.</p>
        {$alert}
        <form method="post" action="/">
          <input type="hidden" name="csrf" value="{$csrf}">
          <label class="access-field"><span>Password</span><input name="password" type="password" autocomplete="current-password" maxlength="1024" required autofocus></label>
          <button class="access-submit" type="submit">Open printer &rarr;</button>
        </form>
      </section>
    </main>
  </div>
</body>
</html>
HTML;
}
