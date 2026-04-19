<?php

$data = str_repeat('A', 1024 * 1024 * 100); // 100MB
$key = 'mask';

// Method 1: Loop (Current)
$start = microtime(true);
$payload1 = '';
for ($i = 0; $i < strlen($data); $i++) {
    $payload1 .= $data[$i] ^ $key[$i % 4];
}
$time1 = microtime(true) - $start;

// Method 2: Native XOR
$start = microtime(true);
$repeatedKey = str_repeat($key, (int) ceil(strlen($data) / 4));
$payload2 = $data ^ $repeatedKey;
$time2 = microtime(true) - $start;

echo "Loop Time: " . number_format($time1, 6) . "s\n";
echo "Native Time: " . number_format($time2, 6) . "s\n";
echo "Are Results Identical? " . ($payload1 === $payload2 ? "YES" : "NO") . "\n";
echo "Speedup: " . number_format($time1 / $time2, 2) . "x\n";
