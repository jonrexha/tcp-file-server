<?php
// auth.php - Complete authentication and user role management system
require __DIR__ . '/logger.php';

class UserAuthenticator {
    private $admin_password;
    private $user_sessions;
    
    public function __construct($admin_password) {
        $this->admin_password = $admin_password;
        $this->user_sessions = [];
        log_message("UserAuthenticator initialized with admin password protection", 'INFO');
    }
    
    public function authenticate($client_id, $username, $password = null) {
        $username = trim($username);
        
        // Validate username
        if (empty($username)) {
            log_message("Client #$client_id attempted authentication with empty username", 'WARNING');
            return [
                'success' => false,
                'message' => "ERROR: Username cannot be empty"
            ];
        }
        
        // Check username length
        if (strlen($username) > 50) {
            log_message("Client #$client_id attempted authentication with too long username", 'WARNING');
            return [
                'success' => false,
                'message' => "ERROR: Username too long (max 50 characters)"
            ];
        }
        
        // Check for special characters in username
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
            log_message("Client #$client_id attempted authentication with invalid username characters", 'WARNING');
            return [
                'success' => false,
                'message' => "ERROR: Username can only contain letters, numbers, underscores, hyphens, and dots"
            ];
        }
        
        // Check if client is already authenticated
        if (isset($this->user_sessions[$client_id])) {
            $current_user = $this->user_sessions[$client_id]['username'];
            log_message("Client #$client_id attempted re-authentication as '$username' (currently '$current_user')", 'WARNING');
            return [
                'success' => false,
                'message' => "ERROR: Already authenticated as $current_user. Use /logout first."
            ];
        }
        
        $role = 'read'; // Default role
        
        // Check for admin authentication
        if ($password !== null) {
            if ($password === $this->admin_password) {
                $role = 'admin';
                log_message("Client #$client_id authenticated as ADMIN with username: '$username'", 'INFO');
            } else {
                log_message("Client #$client_id failed admin authentication with username: '$username'", 'WARNING');
                return [
                    'success' => false,
                    'message' => "ERROR: Invalid admin password"
                ];
            }
        } else {
            log_message("Client #$client_id authenticated as READ-ONLY with username: '$username'", 'INFO');
        }
        
        // Create user session
        $this->user_sessions[$client_id] = [
            'username' => $username,
            'role' => $role,
            'authenticated_at' => time(),
            'last_activity' => time(),
            'client_id' => $client_id
        ];
        
        $message = "AUTH OK - Welcome $username! ";
        $message .= ($role === 'admin') ? "You have ADMIN privileges." : "You have READ-ONLY access.";
        
        return [
            'success' => true,
            'message' => $message,
            'user_data' => $this->user_sessions[$client_id]
        ];
    }
    
    public function update_activity($client_id) {
        if (isset($this->user_sessions[$client_id])) {
            $this->user_sessions[$client_id]['last_activity'] = time();
            return true;
        }
        return false;
    }
    
    public function get_user($client_id) {
        if (isset($this->user_sessions[$client_id])) {
            return $this->user_sessions[$client_id];
        }
        return null;
    }
    
    public function is_authenticated($client_id) {
        return isset($this->user_sessions[$client_id]);
    }
    
    public function is_admin($client_id) {
        return isset($this->user_sessions[$client_id]) && 
               $this->user_sessions[$client_id]['role'] === 'admin';
    }
    
    public function has_permission($client_id, $permission) {
        if (!isset($this->user_sessions[$client_id])) {
            return false;
        }
        
        $user = $this->user_sessions[$client_id];
        
        // Admin has all permissions
        if ($user['role'] === 'admin') {
            return true;
        }
        
        // Define permissions for read-only users
        $read_permissions = [
            'auth',    // Can authenticate
            'stats',   // Can view stats
            'echo',    // Can use echo functionality
            'help',    // Can view help
            'whoami',  // Can view own info
            'logout'   // Can logout
        ];
        
        return in_array($permission, $read_permissions);
    }
    
    public function validate_command($client_id, $command) {
        if (!isset($this->user_sessions[$client_id])) {
            // Check if this is a command that doesn't require authentication
            $public_commands = ['auth', 'help', 'quit', 'exit'];
            if (in_array($command, $public_commands)) {
                return [
                    'allowed' => true,
                    'message' => "OK"
                ];
            }
            
            return [
                'allowed' => false,
                'message' => "ERROR: Authentication required. Use /auth <username> [password]"
            ];
        }
        
        $user = $this->user_sessions[$client_id];
        $command = strtolower(trim($command, '/'));
        
        // Define admin-only commands
        $admin_commands = [
            'list', 'read', 'delete', 'search', 'info', 
            'download', 'upload', 'shutdown', 'kick', 'users'
        ];
        
        if (in_array($command, $admin_commands) && $user['role'] !== 'admin') {
            return [
                'allowed' => false,
                'message' => "ERROR: ADMIN privileges required for /$command command"
            ];
        }
        
        // Check if read-only user has permission for this command
        if ($user['role'] === 'read' && !$this->has_permission($client_id, $command)) {
            return [
                'allowed' => false,
                'message' => "ERROR: Insufficient permissions for /$command command"
            ];
        }
        
        return [
            'allowed' => true,
            'message' => "OK"
        ];
    }
    
    public function logout($client_id) {
        if (isset($this->user_sessions[$client_id])) {
            $username = $this->user_sessions[$client_id]['username'];
            $session_duration = time() - $this->user_sessions[$client_id]['authenticated_at'];
            unset($this->user_sessions[$client_id]);
            
            log_message("Client #$client_id (user: $username) logged out after " . 
                       $this->format_duration($session_duration) . " session", 'INFO');
            return true;
        }
        return false;
    }
    
    public function get_active_users() {
        return $this->user_sessions;
    }
    
    public function get_user_count() {
        $admins = 0;
        $read_only = 0;
        
        foreach ($this->user_sessions as $user) {
            if ($user['role'] === 'admin') {
                $admins++;
            } else {
                $read_only++;
            }
        }
        
        return [
            'total' => count($this->user_sessions),
            'admins' => $admins,
            'read_only' => $read_only
        ];
    }
    
    public function get_user_stats() {
        $stats = [
            'total_users' => count($this->user_sessions),
            'admins' => 0,
            'read_only' => 0,
            'sessions' => []
        ];
        
        foreach ($this->user_sessions as $client_id => $user) {
            if ($user['role'] === 'admin') {
                $stats['admins']++;
            } else {
                $stats['read_only']++;
            }
            
            $stats['sessions'][] = [
                'client_id' => $client_id,
                'username' => $user['username'],
                'role' => $user['role'],
                'session_duration' => time() - $user['authenticated_at'],
                'last_activity' => $user['last_activity']
            ];
        }
        
        return $stats;
    }
    
    public function cleanup_inactive_sessions($timeout_seconds = 7200) { // 2 hours default
        $current_time = time();
        $removed_count = 0;
        
        foreach ($this->user_sessions as $client_id => $session) {
            if ($current_time - $session['last_activity'] > $timeout_seconds) {
                $username = $session['username'];
                $session_duration = $current_time - $session['authenticated_at'];
                
                log_message("Removed inactive session for client #$client_id (user: $username) after " . 
                           $this->format_duration($session_duration) . " inactivity", 'INFO');
                unset($this->user_sessions[$client_id]);
                $removed_count++;
            }
        }
        
        return $removed_count;
    }
    
    public function force_logout($client_id) {
        if (isset($this->user_sessions[$client_id])) {
            $username = $this->user_sessions[$client_id]['username'];
            unset($this->user_sessions[$client_id]);
            log_message("Forcefully logged out client #$client_id (user: $username)", 'WARNING');
            return true;
        }
        return false;
    }
    
    public function change_user_role($client_id, $new_role) {
        if (!isset($this->user_sessions[$client_id])) {
            return false;
        }
        
        $valid_roles = ['admin', 'read'];
        if (!in_array($new_role, $valid_roles)) {
            return false;
        }
        
        $old_role = $this->user_sessions[$client_id]['role'];
        $username = $this->user_sessions[$client_id]['username'];
        
        $this->user_sessions[$client_id]['role'] = $new_role;
        
        log_message("Changed role for client #$client_id (user: $username) from $old_role to $new_role", 'INFO');
        return true;
    }
    
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return "$seconds seconds";
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remaining_seconds = $seconds % 60;
            return "$minutes minutes, $remaining_seconds seconds";
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $remaining_seconds = $seconds % 60;
            return "$hours hours, $minutes minutes, $remaining_seconds seconds";
        }
    }
    
    public function __destruct() {
        $session_count = count($this->user_sessions);
        if ($session_count > 0) {
            log_message("UserAuthenticator shutting down with $session_count active sessions", 'INFO');
        }
    }
}

// Helper functions for authentication handling
function handle_auth_command($data, $client_id, $authenticator) {
    // Parse auth command: /auth username [password]
    $parts = preg_split('/\s+/', $data, 3); // Limit to 3 parts max
    
    if (count($parts) < 2) {
        return "ERROR: Usage: /auth <username> [password]";
    }
    
    $username = $parts[1];
    $password = $parts[2] ?? null;
    
    $result = $authenticator->authenticate($client_id, $username, $password);
    return $result['message'];
}

function get_user_summary($authenticator) {
    $user_count = $authenticator->get_user_count();
    $active_users = $authenticator->get_active_users();
    
    $summary = "USER SUMMARY:\n";
    $summary .= "Total authenticated users: {$user_count['total']}\n";
    $summary .= "Admins: {$user_count['admins']}\n";
    $summary .= "Read-only users: {$user_count['read_only']}\n";
    
    if (!empty($active_users)) {
        $summary .= "\nActive Users:\n";
        foreach ($active_users as $client_id => $user) {
            $session_duration = time() - $user['authenticated_at'];
            $inactive_time = time() - $user['last_activity'];
            $summary .= "  Client #$client_id - {$user['username']} ({$user['role']}) - ";
            $summary .= "Session: " . format_duration($session_duration) . " - ";
            $summary .= "Inactive: " . format_duration($inactive_time) . "\n";
        }
    } else {
        $summary .= "\nNo active authenticated users.\n";
    }
    
    return $summary;
}

function format_duration($seconds) {
    if ($seconds < 60) {
        return "$seconds seconds";
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        return "$minutes minutes";
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "$hours hours, $minutes minutes";
    }
}

// Command help system
function get_auth_help() {
    $help = "AUTHENTICATION & COMMANDS HELP\n";
    $help .= str_repeat("=", 50) . "\n\n";
    
    $help .= "AUTHENTICATION COMMANDS:\n";
    $help .= "/auth <username>              - Authenticate as read-only user\n";
    $help .= "/auth <username> <password>   - Authenticate as admin user\n";
    $help .= "/logout                       - Log out from current session\n";
    $help .= "/whoami                       - Show current user information\n";
    $help .= "/users                        - List all authenticated users (admin only)\n\n";
    
    $help .= "SERVER COMMANDS:\n";
    $help .= "STATS                         - Show server statistics\n";
    $help .= "/help                         - Show this help message\n";
    $help .= "quit / exit                   - Disconnect from server\n\n";
    
    $help .= "FILE COMMANDS (Admin only):\n";
    $help .= "/list                         - List all files on server\n";
    $help .= "/upload <filename>            - Upload a file to server\n";
    $help .= "/download <filename>          - Download a file from server\n";
    $help .= "/read <filename>              - View file content\n";
    $help .= "/delete <filename>            - Delete a file\n";
    $help .= "/search <keyword>             - Search for files\n";
    $help .= "/info <filename>              - Get file information\n\n";
    
    $help .= "PERMISSIONS:\n";
    $help .= "READ-ONLY users can: echo messages, view stats, use help, authenticate\n";
    $help .= "ADMIN users can: manage files, view all users, use all commands\n\n";
    
    $help .= "EXAMPLES:\n";
    $help .= "  /auth guest                 -> Read-only access\n";
    $help .= "  /auth admin sekrete123      -> Admin access\n";
    $help .= "  /whoami                     -> Show your user info\n";
    $help .= "  STATS                       -> Server statistics\n";
    
    return $help;
}

// Utility function to check if a string is an authentication command
function is_auth_command($data) {
    $auth_commands = [
        '/auth', '/logout', '/whoami', '/users', '/help'
    ];
    
    foreach ($auth_commands as $cmd) {
        if (stripos($data, $cmd) === 0) {
            return true;
        }
    }
    
    return false;
}