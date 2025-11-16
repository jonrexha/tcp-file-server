<?php
// server.php - TCP server core with authentication system
require __DIR__ . '/config.php';
require __DIR__ . '/file_utils.php';
require __DIR__ . '/logger.php';
require __DIR__ . '/auth.php';

// Use Jon's utility function to ensure files directory exists
ensure_directory_exists($config['files_dir']);

// Initialize authenticator
$authenticator = new UserAuthenticator($config['admin_password']);

$ip = $config['ip'];
$port = $config['port'];
$server_socket = @stream_socket_server("tcp://{$ip}:{$port}", $errno, $errstr);

if (!$server_socket) {
    log_message("Could not start server: $errstr ($errno)", 'ERROR');
    die("Could not start server: $errstr ($errno)\n");
}

stream_set_blocking($server_socket, false);
log_message("TCP server running on {$ip}:{$port}");

$clients = [];
$next_id = 1;

function send_to_client($socket, $string, &$clients = null, $client_id = null) {
    $written = @fwrite($socket, $string);
    if ($written === false) {
        return false;
    }
    
    // Track bytes sent if client tracking is enabled
    if ($clients !== null && $client_id !== null && isset($clients[$client_id])) {
        if (!isset($clients[$client_id]['bytes_sent'])) {
            $clients[$client_id]['bytes_sent'] = 0;
        }
        $clients[$client_id]['bytes_sent'] += strlen($string);
    }
    
    return true;
}

function handle_client_message($client_id, $data, $socket, &$clients, $authenticator) {
    $data = trim($data);
    
    // Track message count
    if (!isset($clients[$client_id]['messages_received'])) {
        $clients[$client_id]['messages_received'] = 0;
    }
    $clients[$client_id]['messages_received']++;
    
    // Track bytes received
    if (!isset($clients[$client_id]['bytes_received'])) {
        $clients[$client_id]['bytes_received'] = 0;
    }
    $clients[$client_id]['bytes_received'] += strlen($data);
    
    log_message("Client #$client_id sent: $data");
    
    // Update client activity in authenticator
    if ($authenticator->is_authenticated($client_id)) {
        $authenticator->update_activity($client_id);
    }
    
    // Handle authentication command
    if (stripos($data, '/auth ') === 0) {
        $response = handle_auth_command($data, $client_id, $authenticator);
        send_to_client($socket, $response . "\n", $clients, $client_id);
        return true;
    }
    
    // Handle logout command
    if (strtolower(trim($data)) === '/logout') {
        if ($authenticator->logout($client_id)) {
            send_to_client($socket, "Logged out successfully.\n", $clients, $client_id);
            // Update client info
            $clients[$client_id]['username'] = null;
            $clients[$client_id]['role'] = null;
        } else {
            send_to_client($socket, "ERROR: Not currently authenticated.\n", $clients, $client_id);
        }
        return true;
    }
    
    // Handle whoami command
    if (strtolower(trim($data)) === '/whoami') {
        $user = $authenticator->get_user($client_id);
        if ($user) {
            $session_duration = time() - $user['authenticated_at'];
            $response = "USER INFO:\n";
            $response .= "Username: {$user['username']}\n";
            $response .= "Role: {$user['role']}\n";
            $response .= "Authenticated: " . date('Y-m-d H:i:s', $user['authenticated_at']) . "\n";
            $response .= "Session duration: " . format_duration($session_duration) . "\n";
        } else {
            $response = "Not authenticated. Use /auth <username> [password] to login.";
        }
        send_to_client($socket, $response . "\n", $clients, $client_id);
        return true;
    }
    
    // Handle users command (admin only)
    if (strtolower(trim($data)) === '/users') {
        if (!$authenticator->is_admin($client_id)) {
            send_to_client($socket, "ERROR: ADMIN privileges required to view user list.\n", $clients, $client_id);
        } else {
            $response = get_user_summary($authenticator);
            send_to_client($socket, $response . "\n", $clients, $client_id);
        }
        return true;
    }
    
    // Handle help command
    if (strtolower(trim($data)) === '/help' || strtolower(trim($data)) === 'help') {
        $response = get_auth_help();
        send_to_client($socket, $response . "\n", $clients, $client_id);
        return true;
    }
    
    // Handle stats command
    if (strtoupper(trim($data)) === 'STATS') {
        $active_clients = count($clients);
        $authenticated_users = $authenticator->get_user_count();
        
        $response = "SERVER STATISTICS:\n";
        $response .= "Active connections: $active_clients\n";
        $response .= "Authenticated users: {$authenticated_users['total']} ";
        $response .= "({$authenticated_users['admins']} admin, {$authenticated_users['read_only']} read-only)\n";
        $response .= "Server time: " . date('Y-m-d H:i:s') . "\n";
        
        send_to_client($socket, $response . "\n", $clients, $client_id);
        return true;
    }
    
    // Validate other commands for permissions
    if (preg_match('/^\/([a-zA-Z]+)/', $data, $matches)) {
        $command = $matches[1];
        $validation = $authenticator->validate_command($client_id, $command);
        
        if (!$validation['allowed']) {
            send_to_client($socket, $validation['message'] . "\n", $clients, $client_id);
            return true;
        }
    }
    
    // Update client information with authentication data
    if ($authenticator->is_authenticated($client_id)) {
        $user = $authenticator->get_user($client_id);
        $clients[$client_id]['username'] = $user['username'];
        $clients[$client_id]['role'] = $user['role'];
    }
    
    // Simple echo response for non-command messages
    // But only if user has echo permission or is not using a command
    if (!preg_match('/^\//', $data) || $authenticator->has_permission($client_id, 'echo')) {
        $response = "Server Echo: $data\n";
        send_to_client($socket, $response, $clients, $client_id);
    } else {
        send_to_client($socket, "ERROR: Unknown command or insufficient permissions. Use /help for available commands.\n", $clients, $client_id);
    }
    
    return true;
}

function cleanup_inactive_clients(&$clients, $inactivity_timeout, $authenticator) {
    $current_time = time();
    $removed_count = 0;
    
    foreach ($clients as $id => $client) {
        if ($current_time - $client['last_active'] > $inactivity_timeout) {
            log_message("Client #$id timed out due to inactivity", 'INFO');
            send_to_client($client['socket'], "TIMEOUT - Closing connection due to inactivity\n");
            fclose($client['socket']);
            
            // Clean up authentication session
            $authenticator->logout($id);
            
            unset($clients[$id]);
            $removed_count++;
        }
    }
    
    if ($removed_count > 0) {
        log_message("Cleaned up $removed_count inactive clients");
    }
    
    return $removed_count;
}

// Main server loop
log_message("Server entering main loop...");
try {
    while (true) {
        // Build stream array for stream_select
        $read_sockets = [$server_socket];
        foreach ($clients as $client) {
            $read_sockets[] = $client['socket'];
        }

        $write_sockets = $except_sockets = null;
        
        // Wait for activity on sockets (200ms timeout)
        if (@stream_select($read_sockets, $write_sockets, $except_sockets, 0, 200000) === false) {
            // Error on select, sleep briefly and continue
            usleep(100000);
            continue;
        }

        // Check for new connections
        if (in_array($server_socket, $read_sockets, true)) {
            $new_client_socket = @stream_socket_accept($server_socket, 0);
            if ($new_client_socket) {
                stream_set_blocking($new_client_socket, false);
                $client_address = stream_socket_get_name($new_client_socket, true);
                $client_id = $next_id++;
                
                $clients[$client_id] = [
                    'socket' => $new_client_socket,
                    'ip' => $client_address,
                    'last_active' => time(),
                    'username' => null,
                    'role' => null,
                    'connected_at' => time(),
                    'messages_received' => 0,
                    'messages_sent' => 0,
                    'bytes_received' => 0,
                    'bytes_sent' => 0
                ];
                
                log_client_connect($client_id, $client_address);
                
                // Send welcome message
                $welcome_message = "WELCOME ClientID: $client_id\n";
                $welcome_message .= "You are not authenticated\n";
                $welcome_message .= "Use /auth <username> [password] to authenticate\n";
                $welcome_message .= "Use /help for available commands\n";
                $welcome_message .= "Type any message to echo, or 'quit' to disconnect\n";
                
                send_to_client($new_client_socket, $welcome_message, $clients, $client_id);
                $clients[$client_id]['messages_sent']++;
            }
            
            // Remove server socket from read list to avoid reprocessing
            $server_key = array_search($server_socket, $read_sockets, true);
            if ($server_key !== false) {
                unset($read_sockets[$server_key]);
            }
        }

        // Handle data from existing clients
        foreach ($read_sockets as $read_socket) {
            // Find which client this socket belongs to
            $client_id = null;
            foreach ($clients as $id => $client) {
                if ($client['socket'] === $read_socket) {
                    $client_id = $id;
                    break;
                }
            }
            
            if ($client_id === null) {
                continue; // Should not happen, but skip if it does
            }

            // Read data from client
            $data = @fgets($read_socket);
            
            if ($data === false || $data === "") {
                // Check if connection was closed
                $socket_meta = stream_get_meta_data($read_socket);
                if ($socket_meta['eof']) {
                    log_client_disconnect($client_id, 'disconnected (EOF)');
                    
                    // Clean up authentication session
                    $authenticator->logout($client_id);
                    
                    fclose($read_socket);
                    unset($clients[$client_id]);
                    continue;
                } else {
                    // No data available, skip this client
                    continue;
                }
            }

            // Update client activity timestamp
            $clients[$client_id]['last_active'] = time();
            
            // Handle the client message with authentication support
            handle_client_message($client_id, $data, $read_socket, $clients, $authenticator);
            
            // Check for quit command
            if (trim($data) === 'quit' || trim($data) === 'exit') {
                log_client_disconnect($client_id, 'requested disconnect');
                
                // Clean up authentication session
                $authenticator->logout($client_id);
                
                send_to_client($read_socket, "Goodbye!\n");
                fclose($read_socket);
                unset($clients[$client_id]);
                continue;
            }
        }

        // Clean up inactive clients
        cleanup_inactive_clients($clients, $config['inactivity_timeout'], $authenticator);
        
        // Clean up inactive authentication sessions (every 5 minutes)
        static $last_auth_cleanup = 0;
        if (time() - $last_auth_cleanup >= 300) { // 5 minutes
            $removed_sessions = $authenticator->cleanup_inactive_sessions();
            if ($removed_sessions > 0) {
                log_message("Cleaned up $removed_sessions inactive authentication sessions");
            }
            $last_auth_cleanup = time();
        }

        // Small sleep to prevent busy looping
        usleep(10000);
        
        // Periodic status update (every 30 seconds)
        static $last_status_update = 0;
        if (time() - $last_status_update >= 30) {
            $active_clients = count($clients);
            $user_stats = $authenticator->get_user_count();
            log_message("Server status: $active_clients active clients, {$user_stats['total']} authenticated users");
            $last_status_update = time();
        }
    }
} catch (Exception $e) {
    log_message("Server error: " . $e->getMessage(), 'ERROR');
} finally {
    // Cleanup on exit
    log_message("Server shutting down...", 'INFO');
    
    // Close all client connections
    foreach ($clients as $client_id => $client) {
        send_to_client($client['socket'], "Server is shutting down. Goodbye!\n");
        fclose($client['socket']);
        log_client_disconnect($client_id, 'server shutdown');
        
        // Clean up authentication sessions
        $authenticator->logout($client_id);
    }
    
    // Close server socket
    if ($server_socket) {
        fclose($server_socket);
    }
    
    log_message("Server shutdown complete", 'INFO');
}