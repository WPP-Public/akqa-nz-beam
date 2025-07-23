<?php

$files = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php'
];

$filename = null;
foreach ($files as $file) {
    if (file_exists($file)) {
        $filename = $file;
        break;
    }
}

if (!$filename) {
    echo 'You must first install the vendors using composer.' . PHP_EOL;
    exit(1);
}

require_once $filename;
