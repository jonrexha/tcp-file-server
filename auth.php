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
            'last_activity' => time()
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
        }
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
            'help'     // Can view help
        ];
        
        return in_array($permission, $read_permissions);
    }
    
    public function validate_command($client_id, $command) {
        if (!isset($this->user_sessions[$client_id])) {
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
            'download', 'upload', 'shutdown', 'kick'
        ];
        
        if (in_array($command, $admin_commands) && $user['role'] !== 'admin') {
            return [
                'allowed' => false,
                'message' => "ERROR: ADMIN privileges required for /$command command"
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
            unset($this->user_sessions[$client_id]);
            log_message("Client #$client_id (user: $username) logged out", 'INFO');
            return true;
        }
        return false;
    }
    
    public function get_active_users() {
        return $this->user_sessions;
    }
    
    public function get_user_count() {
        return [
            'total' => count($this->user_sessions),
            'admins' => count(array_filter($this->user_sessions, function($user) {
                return $user['role'] === 'admin';
            })),
            'read_only' => count(array_filter($this->user_sessions, function($user) {
                return $user['role'] === 'read';
            }))
        ];
    }
    
    public function cleanup_inactive_sessions($timeout_seconds = 7200) { // 2 hours default
        $current_time = time();
        $removed_count = 0;
        
        foreach ($this->user_sessions as $client_id => $session) {
            if ($current_time - $session['last_activity'] > $timeout_seconds) {
                log_message("Removed inactive session for client #$client_id (user: {$session['username']})", 'INFO');
                unset($this->user_sessions[$client_id]);
                $removed_count++;
            }
        }
        
        return $removed_count;
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
            $summary .= "  Client #$client_id - {$user['username']} ({$user['role']}) - ";
            $summary .= "Session: " . format_duration($session_duration) . "\n";
        }
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
    $help = "AUTHENTICATION HELP:\n";
    $help .= "/auth <username>              - Authenticate as read-only user\n";
    $help .= "/auth <username> <password>   - Authenticate as admin user\n";
    $help .= "/logout                       - Log out from current session\n";
    $help .= "/whoami                       - Show current user information\n";
    $help .= "/users                        - List all authenticated users (admin only)\n";
    $help .= "\n";
    $help .= "READ-ONLY users can: echo messages, view stats, use help\n";
    $help .= "ADMIN users can: manage files, view all users, use all commands\n";
    
    return $help;
}