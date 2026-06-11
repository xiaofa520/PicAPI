<?php

$logFiles = [
    'zsy' => 'log/count.log',
    'm' => 'm/log/count.log',
    'pc' => 'pc/log/count.log',
    'ic' => 'icon/log/count.txt',
];

$optionalLogFiles = [
    'sppc' => 's/pc/log/count.log',
];

$requestCounts = [];

foreach ($logFiles as $name => $path) {
    $requestCounts[$name] = is_readable($path) ? (int) file_get_contents($path) : 0;
}

foreach ($optionalLogFiles as $name => $path) {
    if (is_readable($path)) {
        $requestCounts[$name] = (int) file_get_contents($path);
    }
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($requestCounts, JSON_UNESCAPED_UNICODE);
