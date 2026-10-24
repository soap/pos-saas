<?php

/**
 * ทดสอบ concurrency จริงด้วย request พร้อมกันหลายรายการ (spec §10: Order number, Idempotency, Refund, Cashier)
 *
 * วิธีใช้ (ต้องรัน server แบบหลาย worker):
 *   PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8000
 *   php scripts/concurrency_check.php http://127.0.0.1:8000 owner@pos.test password
 *
 * หมายเหตุ: script นี้จะปิดกะที่เปิดอยู่ของสาขาแรกเมื่อจบ
 */
[$_, $base, $email, $password] = $argv + [null, 'http://127.0.0.1:8000', 'owner@pos.test', 'password'];
$base = rtrim($base, '/').'/api/v1';

function req(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, opts($method, $body, $headers));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, json_decode((string) $raw, true)];
}

function opts(string $method, ?array $body, array $headers): array
{
    $h = ['Accept: application/json', 'Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }

    return [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
        CURLOPT_TIMEOUT => 30,
    ];
}

/** ยิง request พร้อมกัน: $calls = [[method, url, body, headers], ...] */
function parallel(array $calls): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($calls as $i => [$m, $u, $b, $h]) {
        $ch = curl_init($u);
        curl_setopt_array($ch, opts($m, $b, $h));
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($ch), true)];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    return $out;
}

function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? '  PASS ' : '  FAIL ').$name.($detail ? " — {$detail}" : '').PHP_EOL;
    if (! $ok) {
        $GLOBALS['failed'] = true;
    }
}

$failed = false;
[$code, $login] = req('POST', "{$base}/auth/login", ['email' => $email, 'password' => $password]);
if ($code !== 200) {
    exit("login failed ({$code})\n");
}
$auth = ['Authorization' => 'Bearer '.$login['token']];
[, $me] = req('GET', "{$base}/me", null, $auth);
$tenantId = $me['tenants'][0]['id'];
$storeId = $me['tenants'][0]['stores'][0]['id'];
$H = $auth + ['X-Tenant-Id' => $tenantId, 'X-Store-Id' => $storeId];
$key = fn () => bin2hex(random_bytes(12));

[, $menu] = req('GET', "{$base}/menu", null, $H);
$water = null;
foreach ($menu['data']['categories'] as $c) {
    foreach ($c['products'] as $p) {
        if (empty($p['modifier_groups'])) {
            $water = $p;
            break 2;
        }
    }
}
$item = ['product_id' => $water['id'], 'quantity' => 1];
echo "Store {$storeId}, product {$water['name']}\n";

// 1) เลขออเดอร์ไม่ซ้ำเมื่อสร้างพร้อมกัน
$N = 20;
$res = parallel(array_map(fn () => ['POST', "{$base}/orders", ['order_type' => 'takeaway', 'items' => [$item]], $H + ['Idempotency-Key' => $key()]], range(1, $N)));
$numbers = array_map(fn ($r) => $r[1]['data']['order_number'] ?? null, array_filter($res, fn ($r) => $r[0] === 201));
check("{$N} concurrent orders → unique order numbers", count($numbers) === $N && count(array_unique($numbers)) === $N, 'created='.count($numbers));

// 2) Idempotency-Key เดียวกันพร้อมกัน → สร้างได้ออเดอร์เดียว
$same = 'same-'.$key();
$res = parallel(array_map(fn () => ['POST', "{$base}/orders", ['order_type' => 'takeaway', 'items' => [$item]], $H + ['Idempotency-Key' => $same]], range(1, 10)));
$ids = array_unique(array_filter(array_map(fn ($r) => $r[0] === 201 ? $r[1]['data']['id'] : null, $res)));
$codes = array_count_values(array_map(fn ($r) => $r[0], $res));
check('10 concurrent same Idempotency-Key → 1 order', count($ids) === 1, json_encode($codes));

// 3) refund พร้อมกันไม่เกินยอดที่ชำระ
[, $cur] = req('GET', "{$base}/cashier-shifts/current", null, $H);
$shiftId = $cur['data']['id'] ?? req('POST', "{$base}/cashier-shifts/open", ['opening_float_satang' => 100000], $H + ['Idempotency-Key' => $key()])[1]['data']['id'];
[, $order] = req('POST', "{$base}/orders", ['order_type' => 'takeaway', 'items' => [$item + ['quantity' => 10]]], $H + ['Idempotency-Key' => $key()]);
[, $pay] = req('POST', "{$base}/orders/{$order['data']['id']}/payments", ['method' => 'cash'], $H + ['Idempotency-Key' => $key()]);
$amount = $pay['data']['amount_satang'];
$part = intdiv($amount * 6, 10);
$res = parallel(array_map(fn () => ['POST', "{$base}/payments/{$pay['data']['id']}/refunds", ['amount_satang' => $part, 'reason' => 'concurrency test'], $H + ['Idempotency-Key' => $key()]], range(1, 10)));
$ok = count(array_filter($res, fn ($r) => $r[0] === 201));
check('10 concurrent refunds of 60% → only 1 succeeds', $ok === 1, json_encode(array_count_values(array_map(fn ($r) => $r[0], $res))));

// 4) ปิดกะพร้อมกัน → ปิดได้ครั้งเดียว
$res = parallel(array_map(fn () => ['POST', "{$base}/cashier-shifts/{$shiftId}/close", ['counted_cash_satang' => 0], $H], range(1, 10)));
$ok = count(array_filter($res, fn ($r) => $r[0] === 200));
check('10 concurrent shift close → closed once', $ok === 1, json_encode(array_count_values(array_map(fn ($r) => $r[0], $res))));

echo $failed ? "\nSOME CHECKS FAILED\n" : "\nALL CHECKS PASSED\n";
exit($failed ? 1 : 0);
