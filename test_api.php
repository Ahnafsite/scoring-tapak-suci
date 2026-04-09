<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$request = \Illuminate\Http\Request::create('/api/partai/sync/2', 'POST', ['fight_schedule_id' => 40]);
$controller = app()->make(\App\Http\Controllers\Api\MatchSyncController::class);
try {
    $response = $controller->syncMatch($request, 2);
    echo "STATUS: " . $response->getStatusCode() . "\n";
    echo "BODY: " . $response->getContent() . "\n";
} catch (\Throwable $e) {
    echo "UNCAUGHT FATAL ERR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
