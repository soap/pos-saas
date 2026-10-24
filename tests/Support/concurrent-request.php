<?php

/**
 * ยิง HTTP request หนึ่งรายการในโปรเซสแยก (ใช้โดย ConcurrentRequests)
 *
 * argv[1] = base64(json{method, uri, headers, body, start_at, database})
 * boot แอปและเปิด connection ให้เสร็จก่อน แล้วรอถึง start_at เพื่อให้ทุกโปรเซสยิงพร้อมกัน
 * พิมพ์ผลเป็น json{status, body} ทาง stdout
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$spec = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// กันยิงใส่ฐานข้อมูลผิดตัว (เช่น config ถูก cache ไว้ด้วยค่า dev)
$database = $app['db']->connection()->getDatabaseName();
if ($database !== $spec['database']) {
    fwrite(STDERR, "refusing to run against database [{$database}], expected [{$spec['database']}]");
    exit(1);
}
$app['db']->connection()->getPdo();

$server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
foreach ($spec['headers'] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create($spec['uri'], $spec['method'], [], [], [], $server, json_encode($spec['body']));

if ($spec['start_at'] > microtime(true)) {
    time_sleep_until($spec['start_at']);
}

$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
$kernel->terminate($request, $response);
