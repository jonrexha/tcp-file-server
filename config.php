<?php
// config.php
return [
    'ip' => '0.0.0.0',
    'port' => 9000,
    'max_clients' => 50,
    'inactivity_timeout' => 120,
    'stats_write_interval' => 5,
    'admin_password' => 'sekrete123',
    'files_dir' => __DIR__ . '/server_files',
    'stats_file' => __DIR__ . '/server_stats.txt',
];