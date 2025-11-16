<?php
require __DIR__ . '/logger.php';

if ($argc < 4) {
    echo "Usage: php client.php <server_ip> <port> <username> [password]\n";
    exit(1);
}

$server = $argv[1];
$port = (int) $argv[2];
$username = $argv[3];
$password = $argv[4] ?? null;

$fp = stream_socket_client("tcp://{$server}:{$port}", $errno, $errstr, 5);
if (!$fp) {
    die("Connect failed: $errstr ($errno)\n");
}
stream_set_blocking($fp, false);
echo "Connected to $server:$port\n";


if ($password !== null) {
    fwrite($fp, "/auth {$username} {$password}\n");
} else {
    fwrite($fp, "/auth {$username}\n");
}


$start = time();
while (time() - $start < 2) {
    $line = @fgets($fp);
    if ($line !== false) {
        echo $line;
        if (strpos($line, 'AUTH OK') !== false) {
            echo "Authentication successful!\n";
        }
    }
    usleep(100000);
}

function read_server_nonblocking($fp)
{
    $out = "";
    while (($line = @fgets($fp)) !== false) {
        $out .= $line;
    }
    return $out;
}

function wait_for_response($fp, $timeout_seconds = 5)
{
    $start = time();
    $response = "";

    while (time() - $start < $timeout_seconds) {
        $chunk = read_server_nonblocking($fp);
        if ($chunk !== "") {
            $response .= $chunk;
            if (
                strpos($response, "\n") !== false ||
                strpos($response, "ERROR") !== false ||
                strpos($response, "OK") !== false ||
                strpos($response, "DOWNLOAD_BEGIN") !== false ||
                strpos($response, "UPLOAD_READY") !== false
            ) {
                break;
            }
        }
        usleep(100000);
    }

    return $response;
}
