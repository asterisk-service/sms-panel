<?php
/**
 * Contacts (Phone Book) Functions
 * CRUD, Import, Export
 */

require_once __DIR__ . '/database.php';

class Contacts {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get all contacts with pagination
     */
    public function getAll($page = 1, $perPage = 20, $search = '', $groupId = null) {
        $offset = ($page - 1) * $perPage;
        $where = 'c.is_active = 1';
        $params = [];

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
        return $this->db->fetchOne(
            "SELECT c.*, g.name as group_name 
             FROM contacts c 
             LEFT JOIN contact_groups g ON c.group_id = g.id 
             WHERE c.id = ?",
            [$id]
        );
    }

    /**
     * Get contact by phone
     */
    public function getByPhone($phone) {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return $this->db->fetchOne(
            "SELECT * FROM contacts WHERE phone_number = ?",
            [$phone]
        );
    }

    /**
     * Create contact
     */
    public function create($data) {
        $phone = preg_replace('/[^0-9+]/', '', $data['phone_number']);
        
        // Check for duplicate
        if ($this->getByPhone($phone)) {
            return ['success' => false, 'error' => 'Phone number already exists'];
        }

        $id = $this->db->insert('contacts', [
            'name' => $data['name'],
            'phone_number' => $phone,
            'company' => $data['company'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'group_id' => $data['group_id'] ?? 1,
            'is_active' => 1
        ]);

        return ['success' => true, 'id' => $id];
    }

    /**
     * Update contact
     */
    public function update($id, $data) {
        $phone = preg_replace('/[^0-9+]/', '', $data['phone_number']);
        
        // Check for duplicate (excluding current)
        $existing = $this->db->fetchOne(
            "SELECT id FROM contacts WHERE phone_number = ? AND id != ?",
            [$phone, $id]
        );
        if ($existing) {
            return ['success' => false, 'error' => 'Phone number already exists'];
        }

        $this->db->update('contacts', [
            'name' => $data['name'],
            'phone_number' => $phone,
            'company' => $data['company'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'group_id' => $data['group_id'] ?? 1
        ], 'id = ?', [$id]);

        return ['success' => true];
    }

    /**
     * Delete contact (soft delete)
     */
    public function delete($id) {
        $this->db->update('contacts', ['is_active' => 0], 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Permanently delete contact
     */
    public function permanentDelete($id) {
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

        // Default mapping
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

        // Read header
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
                    'group_id' => (int)($row[$mapping['group_id']] ?? 1) ?: 1
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
     * Import contacts from vCard
     */
    public function importVCard($filePath) {
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $content = file_get_contents($filePath);
        $cards = explode('END:VCARD', $content);
        
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($cards as $card) {
            if (empty(trim($card))) continue;
            
            $data = ['name' => '', 'phone_number' => '', 'email' => '', 'company' => ''];
            
            // Parse FN (Full Name)
            if (preg_match('/FN:(.+)/i', $card, $m)) {
                $data['name'] = trim($m[1]);
            } elseif (preg_match('/N:([^;]+);([^;]+)/i', $card, $m)) {
                $data['name'] = trim($m[2] . ' ' . $m[1]);
            }
            
            // Parse TEL (Phone)
            if (preg_match('/TEL[^:]*:([+0-9\s\-()]+)/i', $card, $m)) {
                $data['phone_number'] = preg_replace('/[^0-9+]/', '', $m[1]);
            }
            
            // Parse EMAIL
            if (preg_match('/EMAIL[^:]*:(.+)/i', $card, $m)) {
                $data['email'] = trim($m[1]);
            }
            
            // Parse ORG (Company)
            if (preg_match('/ORG:(.+)/i', $card, $m)) {
                $data['company'] = trim($m[1]);
            }

            if (empty($data['name']) || empty($data['phone_number'])) {
                $skipped++;
                continue;
            }

            $data['group_id'] = 1;
            $result = $this->create($data);
            if ($result['success']) {
                $imported++;
            } else {
                $skipped++;
                $errors[] = "{$data['phone_number']}: {$result['error']}";
            }
        }

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
        $where = 'is_active = 1';
        $params = [];
        
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

    /**
     * Export contacts to vCard
     */
    public function exportVCard($groupId = null) {
        $where = 'is_active = 1';
        $params = [];
        
        if ($groupId) {
            $where .= " AND group_id = ?";
            $params[] = $groupId;
        }

        $contacts = $this->db->fetchAll(
            "SELECT * FROM contacts WHERE {$where} ORDER BY name",
            $params
        );

        $output = '';
        foreach ($contacts as $c) {
            $output .= "BEGIN:VCARD\n";
            $output .= "VERSION:3.0\n";
            $output .= "FN:{$c['name']}\n";
            
            $nameParts = explode(' ', $c['name'], 2);
            $lastName = $nameParts[1] ?? '';
            $firstName = $nameParts[0];
            $output .= "N:{$lastName};{$firstName};;;\n";
            
            $output .= "TEL;TYPE=CELL:{$c['phone_number']}\n";
            
            if (!empty($c['email'])) {
                $output .= "EMAIL:{$c['email']}\n";
            }
            if (!empty($c['company'])) {
                $output .= "ORG:{$c['company']}\n";
            }
            if (!empty($c['notes'])) {
                $output .= "NOTE:{$c['notes']}\n";
            }
            
            $output .= "END:VCARD\n";
        }

        return $output;
    }

    // === Group Functions ===

    /**
     * Get all groups
     */
    public function getGroups() {
        return $this->db->fetchAll("SELECT * FROM contact_groups ORDER BY name");
    }

    /**
     * Create group
     */
    public function createGroup($name, $description = '', $color = '#3498db') {
        return $this->db->insert('contact_groups', [
            'name' => $name,
            'description' => $description,
            'color' => $color
        ]);
    }

    /**
     * Update group
     */
    public function updateGroup($id, $name, $description = '', $color = '#3498db') {
        $this->db->update('contact_groups', [
            'name' => $name,
            'description' => $description,
            'color' => $color
        ], 'id = ?', [$id]);
    }

    /**
     * Delete group
     */
    public function deleteGroup($id) {
        // Move contacts to default group
        $this->db->update('contacts', ['group_id' => 1], 'group_id = ?', [$id]);
        $this->db->delete('contact_groups', 'id = ? AND id != 1', [$id]);
    }

    /**
     * Get contacts for SMS sending (for autocomplete)
     */
    public function getForSMS($search = '', $limit = 20) {
        $where = 'is_active = 1';
        $params = [];

        if ($search) {
            $where .= " AND (name LIKE ? OR phone_number LIKE ?)";
            $params = ["%{$search}%", "%{$search}%"];
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
        return $this->db->fetchAll(
            "SELECT phone_number FROM contacts WHERE group_id = ? AND is_active = 1",
            [$groupId]
        );
    }
}
