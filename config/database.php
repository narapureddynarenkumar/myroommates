<?php

function detectEnv() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    
    $map = [
        'localhost' => 'dev',
        '127.0.0.1' => 'dev',
        '192.168.1.7'=> 'dev',
        'staging.yoursite.com' => 'staging',
        'test.yoursite.com' => 'staging',
        'myroommates.infinityfree.me' => 'prod',
        'myroommates.infinityfree.me' => 'prod',
    ];

    return $map[$host] ?? 'prod'; // default safe
}

// All environments in one place
$config = [
    'dev' => [
        'host' => 'localhost',
        'dbname' => 'roommate',
        'user' => 'root',
        'password' => ''
    ],
    'staging' => [
        'host' => 'staging-server',
        'dbname' => 'myapp_staging',
        'user' => 'stage_user',
        'password' => 'stage_pass'
    ],
    'prod' => [
        'host' => 'sql105.infinityfree.com',
        'dbname' => 'if0_41439349_myroommates',
        'user' => 'if0_41439349',
        'password' => 'hr2oLoE0E4yiwg'
    ]
];

$env = detectEnv();

// Safety check
if (!isset($config[$env])) {
    die("Invalid environment: $env");
}

return $config[$env];