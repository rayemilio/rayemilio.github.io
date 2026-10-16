<?php
declare(strict_types=1);

const OOP_PRINTER_ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

function oop_printer_apr1_to64(int $value, int $length): string
{
    $output = '';
    for ($index = 0; $index < $length; $index += 1) {
        $output .= OOP_PRINTER_ITOA64[$value & 0x3f];
        $value >>= 6;
    }
    return $output;
}

function oop_printer_apr1(string $password, string $salt): string
{
    $salt = substr(preg_replace('/[^A-Za-z0-9.\\/]/', '', $salt) ?? '', 0, 8);
    $length = strlen($password);
    $digest = md5($password . $salt . $password, true);
    $parts = $password . '$apr1$' . $salt;

    for ($remaining = $length; $remaining > 0; $remaining -= 16) {
        $parts .= substr($digest, 0, min(16, $remaining));
    }
    for ($remaining = $length; $remaining > 0; $remaining >>= 1) {
        $parts .= ($remaining & 1) ? "\0" : $password[0];
    }

    $digest = md5($parts, true);
    for ($round = 0; $round < 1000; $round += 1) {
        $next = ($round & 1) ? $password : $digest;
        if ($round % 3) {
            $next .= $salt;
        }
        if ($round % 7) {
            $next .= $password;
        }
        $next .= ($round & 1) ? $digest : $password;
        $digest = md5($next, true);
    }

    $encoded =
        oop_printer_apr1_to64((ord($digest[0]) << 16) | (ord($digest[6]) << 8) | ord($digest[12]), 4) .
        oop_printer_apr1_to64((ord($digest[1]) << 16) | (ord($digest[7]) << 8) | ord($digest[13]), 4) .
        oop_printer_apr1_to64((ord($digest[2]) << 16) | (ord($digest[8]) << 8) | ord($digest[14]), 4) .
        oop_printer_apr1_to64((ord($digest[3]) << 16) | (ord($digest[9]) << 8) | ord($digest[15]), 4) .
        oop_printer_apr1_to64((ord($digest[4]) << 16) | (ord($digest[10]) << 8) | ord($digest[5]), 4) .
        oop_printer_apr1_to64(ord($digest[11]), 2);

    return '$apr1$' . $salt . '$' . $encoded;
}

function oop_printer_verify_password(string $password, string $hash): bool
{
    if (str_starts_with($hash, '$apr1$')) {
        $parts = explode('$', $hash);
        if (count($parts) !== 4 || $parts[2] === '') {
            return false;
        }
        return hash_equals($hash, oop_printer_apr1($password, $parts[2]));
    }

    if (preg_match('/^\\$2[aby]\\$/', $hash)) {
        return password_verify($password, $hash);
    }

    return false;
}

