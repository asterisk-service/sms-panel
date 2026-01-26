<?php
/**
 * Contacts (Phone Book) Functions
 * User-based filtering
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

class Contacts {
    private $db;
    private $userId;
    private $isAdmin;

    public function __construct() {
        $this->db = Database::getInstance();
        $auth = Auth::getInstance();
        $this->userId = $auth->getUserId();
        $this->isAdmin = $auth->isAdmin();
    }

    /**
     * Get user filter for queries
     */
    private function getUserFilter($alias = '') {
        $prefix = $alias ? "{$alias}." : '';
        if ($this->isAdmin) {
            return ['where' => '1=1', 'params' => []];
        }
        return [
            'where' => "({$prefix}user_id = ? OR {$prefix}user_id IS NULL)",
            'params' => [$this->userId]
        ];
    }

    /**
     * Get all contacts with pagination
     */
    public function getAll($page = 1, $perPage = 20, $search = '', $groupId = null) {
        $offset = ($page - 1) * $perPage;
        $userFilter = $this->getUserFilter('c');
        $where = "c.is_active = 1 AND {$userFilter['where']}";
        $params = $userFilter['params'];

        if ($search) {
            $where .= " AND (c.name LIKE ? OR c.phone_number LIKE ? OR c.company LIKE ? OR c.email LIKE ?)";
            $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        if ($groupId) {
            $where .= " AND c.group_id = ?";
            $params[] = $groupId;
        }

        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM contacts c WHERE {$where}",
            $params
        )['cnt'];

        $contacts = $this->db->fetchAll(
            "SELECT c.*, g.name as group_name, g.color as group_color 
             FROM contacts c 
             LEFT JOIN contact_groups g ON c.group_id = g.id 
             WHERE {$where} 
             ORDER BY c.name ASC 
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'contacts' => $contacts,
            'total' => $total,
            'pages' => ceil($total / $perPage),
            'page' => $page
        ];
    }

    /**
     * Get single contact
     */
    public function get($id) {
        $userFilter = $this->getUserFilter('c');
        return $this->db->fetchOne(
            "SELECT c.*, g.name as group_name 
             FROM contacts c 
             LEFT JOIN contact_groups g ON c.group_id = g.id 
             WHERE c.id = ? AND {$userFilter['where']}",
            array_merge([$id], $userFilter['params'])
        );
    }

    /**
     * Get contact by phone
     */
    public function getByPhone($phone) {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        $userFilter = $this->getUserFilter();
        return $this->db->fetchOne(
            "SELECT * FROM contacts WHERE phone_number = ? AND {$userFilter['where']}",
            array_merge([$phone], $userFilter['params'])
        );
    }

    /**
     * Create contact
     */
    public function create($data) {
        $phone = preg_replace('/[^0-9+]/', '', $data['phone_number']);
        
        // Check for duplicate within user's contacts
        if ($this->getByPhone($phone)) {
            return ['success' => false, 'error' => 'Phone number already exists'];
        }

        $id = $this->db->insert('contacts', [
            'user_id' => $this->userId,
            'name' => $data['name'],
            'phone_number' => $phone,
            'company' => $data['company'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'group_id' => $data['group_id'] ?? null,
            'is_active' => 1
        ]);

        return ['success' => true, 'id' => $id];
    }

    /**
     * Update contact
     */
    public function update($id, $data) {
        $phone = preg_replace('/[^0-9+]/', '', $data['phone_number']);
        $userFilter = $this->getUserFilter();
        
        // Check for duplicate (excluding current)
        $existing = $this->db->fetchOne(
            "SELECT id FROM contacts WHERE phone_number = ? AND id != ? AND {$userFilter['where']}",
            array_merge([$phone, $id], $userFilter['params'])
        );
        if ($existing) {
            return ['success' => false, 'error' => 'Phone number already exists'];
        }

        // Verify ownership
        $contact = $this->get($id);
        if (!$contact) {
            return ['success' => false, 'error' => 'Contact not found'];
        }

        $this->db->update('contacts', [
            'name' => $data['name'],
            'phone_number' => $phone,
            'company' => $data['company'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'group_id' => $data['group_id'] ?? null
        ], 'id = ?', [$id]);

        return ['success' => true];
    }

    /**
     * Delete contact (soft delete)
     */
    public function delete($id) {
        $contact = $this->get($id);
        if (!$contact) {
            return ['success' => false, 'error' => 'Contact not found'];
        }
        $this->db->update('contacts', ['is_active' => 0], 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Permanently delete contact
     */
    public function permanentDelete($id) {
        $contact = $this->get($id);
        if (!$contact) {
            return ['success' => false, 'error' => 'Contact not found'];
        }
        $this->db->delete('contacts', 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Import contacts from CSV
     */
    public function importCSV($filePath, $mapping = null) {
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'error' => 'Cannot open file'];
        }

        if (!$mapping) {
            $mapping = [
                'name' => 0,
                'phone_number' => 1,
                'company' => 2,
                'email' => 3,
                'notes' => 4,
                'group_id' => 5
            ];
        }

        $header = fgetcsv($handle, 0, ';');
        
        $imported = 0;
        $skipped = 0;
        $errors = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            try {
                $data = [
                    'name' => trim($row[$mapping['name']] ?? ''),
                    'phone_number' => trim($row[$mapping['phone_number']] ?? ''),
                    'company' => trim($row[$mapping['company']] ?? ''),
                    'email' => trim($row[$mapping['email']] ?? ''),
                    'notes' => trim($row[$mapping['notes']] ?? ''),
                    'group_id' => (int)($row[$mapping['group_id']] ?? 0) ?: null
                ];

                if (empty($data['name']) || empty($data['phone_number'])) {
                    $skipped++;
                    continue;
                }

                $result = $this->create($data);
                if ($result['success']) {
                    $imported++;
                } else {
                    $skipped++;
                    $errors[] = "{$data['phone_number']}: {$result['error']}";
                }
            } catch (Exception $e) {
                $skipped++;
                $errors[] = "Row error: " . $e->getMessage();
            }
        }

        fclose($handle);

        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Export contacts to CSV
     */
    public function exportCSV($groupId = null) {
        $userFilter = $this->getUserFilter();
        $where = "is_active = 1 AND {$userFilter['where']}";
        $params = $userFilter['params'];
        
        if ($groupId) {
            $where .= " AND group_id = ?";
            $params[] = $groupId;
        }

        $contacts = $this->db->fetchAll(
            "SELECT name, phone_number, company, email, notes, group_id 
             FROM contacts WHERE {$where} ORDER BY name",
            $params
        );

        $output = "Name;Phone;Company;Email;Notes;GroupID\n";
        foreach ($contacts as $c) {
            $output .= implode(';', [
                '"' . str_replace('"', '""', $c['name']) . '"',
                $c['phone_number'],
                '"' . str_replace('"', '""', $c['company'] ?? '') . '"',
                $c['email'] ?? '',
                '"' . str_replace('"', '""', $c['notes'] ?? '') . '"',
                $c['group_id']
            ]) . "\n";
        }

        return $output;
    }

    // === Group Functions ===

    /**
     * Get all groups for current user
     */
    public function getGroups() {
        $userFilter = $this->getUserFilter();
        return $this->db->fetchAll(
            "SELECT * FROM contact_groups WHERE {$userFilter['where']} ORDER BY name",
            $userFilter['params']
        );
    }

    /**
     * Get single group
     */
    public function getGroup($id) {
        $userFilter = $this->getUserFilter();
        return $this->db->fetchOne(
            "SELECT * FROM contact_groups WHERE id = ? AND {$userFilter['where']}",
            array_merge([$id], $userFilter['params'])
        );
    }

    /**
     * Create group
     */
    public function createGroup($name, $description = '', $color = '#3498db') {
        return $this->db->insert('contact_groups', [
            'user_id' => $this->userId,
            'name' => $name,
            'description' => $description,
            'color' => $color
        ]);
    }

    /**
     * Update group
     */
    public function updateGroup($id, $name, $description = '', $color = '#3498db') {
        $group = $this->getGroup($id);
        if (!$group) return false;
        
        $this->db->update('contact_groups', [
            'name' => $name,
            'description' => $description,
            'color' => $color
        ], 'id = ?', [$id]);
        return true;
    }

    /**
     * Delete group
     */
    public function deleteGroup($id) {
        $group = $this->getGroup($id);
        if (!$group) return false;
        
        // Move contacts to no group
        $this->db->update('contacts', ['group_id' => null], 'group_id = ?', [$id]);
        $this->db->delete('contact_groups', 'id = ?', [$id]);
        return true;
    }

    /**
     * Get contacts for SMS autocomplete
     */
    public function getForSMS($search = '', $limit = 20) {
        $userFilter = $this->getUserFilter();
        $where = "is_active = 1 AND {$userFilter['where']}";
        $params = $userFilter['params'];

        if ($search) {
            $where .= " AND (name LIKE ? OR phone_number LIKE ?)";
            $params = array_merge($params, ["%{$search}%", "%{$search}%"]);
        }

        return $this->db->fetchAll(
            "SELECT id, name, phone_number FROM contacts 
             WHERE {$where} ORDER BY name LIMIT {$limit}",
            $params
        );
    }

    /**
     * Get contacts by group for bulk SMS
     */
    public function getByGroup($groupId) {
        $userFilter = $this->getUserFilter();
        return $this->db->fetchAll(
            "SELECT phone_number, name FROM contacts 
             WHERE group_id = ? AND is_active = 1 AND {$userFilter['where']}",
            array_merge([$groupId], $userFilter['params'])
        );
    }
}
