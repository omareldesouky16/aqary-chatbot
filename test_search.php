<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(\App\Services\Chat\PropertySearchService::class);
try {
    $state = [
        'slots'=>['price'=>1000000],
        'session_id'=>'session',
        'resolution'=>[
            'outcomes'=>[
                'propertyType'=>['status'=>'resolved','canonical_id'=>101,'canonical_name'=>'Apartment'],
                'location'=>['status'=>'resolved','canonical_id'=>22,'canonical_name'=>'Smouha']
            ]
        ]
    ];
    $service->search($state, ['flags'=>[]]);
    echo "Success\n";
} catch (\Throwable $e) {
    echo $e->getMessage() . "\n" . $e->getFile() . ":" . $e->getLine();
}
