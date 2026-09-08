<?php
function money($amount): string
{
    return number_format((float) $amount, 0, ',', '.');
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function genCode(string $prefix): string
{
    return $prefix . strtoupper(base_convert((string) (microtime(true) * 1000), 10, 36));
}

/** Lấy giá trị POST đã trim, hoặc chuỗi rỗng. */
function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function postFloat(string $key, float $default = 0): float
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = (float) $v;
    return $n >= 0 ? $n : $default;
}

function postInt(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = (int) $v;
    return $n >= 0 ? $n : $default;
}
