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

function handle_upload($fp, $local_path)
{
    if (!is_file($local_path)) {
        return "Local file not found: $local_path\n";
    }

    $filename = basename($local_path);
    $size = filesize($local_path);

    fwrite($fp, "/upload $filename $size\n");
    echo "Initiating upload: $filename ($size bytes)\n";

    $response = wait_for_response($fp, 10);
    echo $response;

    if (strpos($response, "UPLOAD_READY") !== false) {
        $fh = fopen($local_path, 'rb');
        $total_sent = 0;
        while (!feof($fh)) {
            $chunk = fread($fh, 8192);
            if ($chunk === false)
                break;
            $sent = fwrite($fp, $chunk);
            if ($sent === false)
                break;
            $total_sent += $sent;
        }
        fclose($fh);

        echo "Sent $total_sent bytes. Waiting for confirmation...\n";

        $ack = wait_for_response($fp, 10);
        echo $ack;

        if (strpos($ack, "UPLOAD_OK") !== false) {
            return "Upload completed successfully!\n";
        } else {
            return "Upload may have failed. Server response: $ack\n";
        }
    } else {
        return "Upload failed. Server response: $response\n";
    }
}

function handle_download($fp, $filename)
{
    fwrite($fp, "/download $filename\n");
    echo "Requesting download: $filename\n";

    $response = wait_for_response($fp, 10);
    echo $response;

    if (preg_match('/DOWNLOAD_BEGIN\s+(\S+)\s+(\d+)/i', $response, $matches)) {
        $expected_size = (int) $matches[2];
        $filename = $matches[1];

        echo "Downloading $filename (size: $expected_size bytes)\n";

        $output_filename = "downloaded_" . $filename;
        $outfh = fopen($output_filename, 'wb');
        $received = 0;
        $start_time = time();
        $timeout = 30;

        stream_set_blocking($fp, true);
        stream_set_timeout($fp, $timeout);

        while ($received < $expected_size && time() - $start_time < $timeout) {
            $chunk_size = min(8192, $expected_size - $received);
            $chunk = fread($fp, $chunk_size);

            if ($chunk === false || $chunk === '') {
                break;
            }

            fwrite($outfh, $chunk);
            $received += strlen($chunk);

            if ($expected_size > 0) {
                $percent = intval(($received / $expected_size) * 100);
                echo "\rProgress: $percent% ($received/$expected_size bytes)";
            }
        }

        fclose($outfh);
        stream_set_blocking($fp, false);

        echo "\nDownloaded $received bytes to $output_filename\n";

        usleep(500000);
        $end_marker = wait_for_response($fp, 5);
        if (strpos($end_marker, "DOWNLOAD_END") !== false) {
            echo "Download completed successfully!\n";
        } else {
            echo "Download finished (end marker not received)\n";
        }

        return "Download saved as: $output_filename\n";
    } else {
        return "Download failed. Server response: $response\n";
    }
}

echo "\nType /help for available commands, or 'quit' to exit.\n";

while (true) {
    $input = readline("> ");
    if ($input === false)
        break;
    $input = trim($input);
    if ($input === "")
        continue;

    if (preg_match('/^\/upload\s+(.+)$/i', $input, $m)) {
        $local_path = trim($m[1]);
        $result = handle_upload($fp, $local_path);
        echo $result;
        continue;
    }

    if (preg_match('/^\/download\s+(.+)$/i', $input, $m)) {
        $filename = trim($m[1]);
        $result = handle_download($fp, $filename);
        echo $result;
        continue;
    }

    if ($input === 'quit' || $input === 'exit') {
        fwrite($fp, "quit\n");
        break;
    }

    fwrite($fp, $input . "\n");

    usleep(300000);
    $response = read_server_nonblocking($fp);
    if ($response !== "") {
        echo $response;
    }
}

fclose($fp);
echo "Disconnected from server.\n";