<?php
// logger.php - Simple logging utility

function log_message($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Print to console
    echo $formatted;
    
    // Also write to log file
    file_put_contents(__DIR__ . '/server.log', $formatted, FILE_APPEND | LOCK_EX);
}

function log_client_connect($client_id, $ip) {
    log_message("Client #$client_id connected from $ip");
}

function log_client_disconnect($client_id, $reason = 'disconnected') {
    log_message("Client #$client_id $reason");
}

function log_file_operation($operation, $filename, $client_id = null) {
    $client_info = $client_id ? " by client #$client_id" : "";
    log_message("File $operation: $filename$client_info");
}