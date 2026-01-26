<?php
/**
 * Authentication & Authorization Class
 */

require_once __DIR__ . '/database.php';

class Auth {
    private static $instance = null;
    private $db;
    private $user = null;
    
    private function __construct() {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->loadUser();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function loadUser() {
        if (isset($_SESSION['user_id'])) {
            $this->user = $this->db->fetchOne(
                "SELECT * FROM users WHERE id = ? AND is_active = 1",
                [$_SESSION['user_id']]
            );
            if (!$this->user) {
                $this->logout();
            }
        }
    }
    
    public function isLoggedIn() {
        return $this->user !== null;
    }
    
    public function isAdmin() {
        return $this->user && $this->user['role'] === 'admin';
    }
    
    public function getUser() {
        return $this->user;
    }
    
    public function getUserId() {
        return $this->user ? $this->user['id'] : null;
    }
    
    public function getUserName() {
        return $this->user ? $this->user['name'] : '';
    }
    
    /**
     * Login
     */
    public function login($username, $password) {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ? AND is_active = 1",
            [$username]
        );
        
        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        
        $_SESSION['user_id'] = $user['id'];
        $this->user = $user;
        
        $this->db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        
        return ['success' => true];
    }
    
    /**
     * Logout
     */
    public function logout() {
        $_SESSION = [];
        session_destroy();
        $this->user = null;
    }
    
    /**
     * Require login
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }
    
    /**
     * Require admin
     */
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: index.php?error=access_denied');
            exit;
        }
    }
    
    /**
     * Get allowed ports for current user
     */
    public function getAllowedPorts($permission = 'can_send') {
        if ($this->isAdmin()) {
            return $this->db->fetchAll(
                "SELECT gp.*, g.name as gateway_name, g.type as gateway_type 
                 FROM gateway_ports gp 
                 LEFT JOIN gateways g ON gp.gateway_id = g.id 
                 WHERE gp.is_active = 1 
                 ORDER BY g.name, gp.port_number"
            );
        }
        
        $field = $permission === 'can_send' ? 'can_send' : 'can_receive';
        return $this->db->fetchAll(
            "SELECT gp.*, g.name as gateway_name, g.type as gateway_type 
             FROM gateway_ports gp 
             INNER JOIN user_ports up ON gp.id = up.port_id
             LEFT JOIN gateways g ON gp.gateway_id = g.id 
             WHERE up.user_id = ? AND up.{$field} = 1 AND gp.is_active = 1 
             ORDER BY g.name, gp.port_number",
            [$this->user['id']]
        );
    }
    
    /**
     * Get allowed gateways for current user
     */
    public function getAllowedGateways($permission = 'can_send') {
        if ($this->isAdmin()) {
            return $this->db->fetchAll(
                "SELECT * FROM gateways WHERE is_active = 1 ORDER BY priority DESC"
            );
        }
        
        $field = $permission === 'can_send' ? 'can_send' : 'can_receive';
        return $this->db->fetchAll(
            "SELECT DISTINCT g.* FROM gateways g
             INNER JOIN user_ports up ON g.id = up.gateway_id
             WHERE up.user_id = ? AND up.{$field} = 1 AND g.is_active = 1
             ORDER BY g.priority DESC",
            [$this->user['id']]
        );
    }
    
    /**
     * Get allowed port IDs for SQL IN clause
     */
    public function getAllowedPortIds($permission = 'can_send') {
        $ports = $this->getAllowedPorts($permission);
        return array_column($ports, 'id');
    }
    
    /**
     * Check if user can access port
     */
    public function canAccessPort($portId, $permission = 'can_send') {
        if ($this->isAdmin()) return true;
        
        $field = $permission === 'can_send' ? 'can_send' : 'can_receive';
        $access = $this->db->fetchOne(
            "SELECT id FROM user_ports WHERE user_id = ? AND port_id = ? AND {$field} = 1",
            [$this->user['id'], $portId]
        );
        return $access !== null;
    }
    
    /**
     * Get port filter for inbox/outbox queries
     */
    public function getPortFilter($portColumn = 'port') {
        if ($this->isAdmin()) {
            return ['where' => '1=1', 'params' => []];
        }
        
        $portIds = $this->getAllowedPortIds('can_receive');
        if (empty($portIds)) {
            return ['where' => '1=0', 'params' => []];
        }
        
        // Get port numbers for these IDs
        $ports = $this->db->fetchAll(
            "SELECT port_number FROM gateway_ports WHERE id IN (" . implode(',', $portIds) . ")"
        );
        $portNumbers = array_column($ports, 'port_number');
        
        if (empty($portNumbers)) {
            return ['where' => '1=0', 'params' => []];
        }
        
        $placeholders = implode(',', array_fill(0, count($portNumbers), '?'));
        return [
            'where' => "{$portColumn} IN ({$placeholders})",
            'params' => $portNumbers
        ];
    }
    
    // ========== User Management (Admin) ==========
    
    public function getAllUsers() {
        return $this->db->fetchAll(
            "SELECT u.*, 
                    (SELECT COUNT(*) FROM user_ports WHERE user_id = u.id) as ports_count
             FROM users u ORDER BY u.role DESC, u.name"
        );
    }
    
    public function getUserById($id) {
        return $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    }
    
    public function createUser($data) {
        $exists = $this->db->fetchOne("SELECT id FROM users WHERE username = ?", [$data['username']]);
        if ($exists) {
            return ['success' => false, 'error' => 'Username exists'];
        }
        
        $this->db->query(
            "INSERT INTO users (username, password, name, email, role, is_active) VALUES (?, ?, ?, ?, ?, ?)",
            [
                $data['username'],
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['name'],
                $data['email'] ?? null,
                $data['role'] ?? 'user',
                $data['is_active'] ?? 1
            ]
        );
        
        return ['success' => true, 'user_id' => $this->db->lastInsertId()];
    }
    
    public function updateUser($id, $data) {
        $fields = [];
        $params = [];
        
        foreach (['name', 'email', 'role', 'is_active'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (!empty($data['password'])) {
            $fields[] = "password = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($fields)) return ['success' => true];
        
        $params[] = $id;
        $this->db->query("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?", $params);
        
        return ['success' => true];
    }
    
    public function deleteUser($id) {
        if ($id == $this->getUserId()) {
            return ['success' => false, 'error' => 'Cannot delete yourself'];
        }
        $this->db->query("DELETE FROM users WHERE id = ?", [$id]);
        return ['success' => true];
    }
    
    public function getUserPorts($userId) {
        return $this->db->fetchAll(
            "SELECT up.*, gp.port_number, gp.port_name, g.name as gateway_name, g.type as gateway_type
             FROM user_ports up
             INNER JOIN gateway_ports gp ON up.port_id = gp.id
             LEFT JOIN gateways g ON up.gateway_id = g.id
             WHERE up.user_id = ?
             ORDER BY g.name, gp.port_number",
            [$userId]
        );
    }
    
    public function setUserPorts($userId, $ports) {
        // Clear existing
        $this->db->query("DELETE FROM user_ports WHERE user_id = ?", [$userId]);
        
        // Insert new
        foreach ($ports as $p) {
            $this->db->query(
                "INSERT INTO user_ports (user_id, gateway_id, port_id, can_send, can_receive) VALUES (?, ?, ?, ?, ?)",
                [$userId, $p['gateway_id'], $p['port_id'], $p['can_send'] ?? 1, $p['can_receive'] ?? 1]
            );
        }
        
        return ['success' => true];
    }
    
    public function changePassword($userId, $oldPassword, $newPassword) {
        $user = $this->db->fetchOne("SELECT password FROM users WHERE id = ?", [$userId]);
        if (!$user || !password_verify($oldPassword, $user['password'])) {
            return ['success' => false, 'error' => 'Wrong password'];
        }
        
        $this->db->query(
            "UPDATE users SET password = ? WHERE id = ?",
            [password_hash($newPassword, PASSWORD_DEFAULT), $userId]
        );
        
        return ['success' => true];
    }
}

function auth() {
    return Auth::getInstance();
}
