<?php
/**
 * SMS Templates Functions
 */

require_once __DIR__ . '/database.php';

class Templates {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get all templates
     */
    public function getAll($activeOnly = true) {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $this->db->fetchAll(
            "SELECT * FROM templates {$where} ORDER BY usage_count DESC, name ASC"
        );
    }

    /**
     * Get single template
     */
    public function get($id) {
        return $this->db->fetchOne("SELECT * FROM templates WHERE id = ?", [$id]);
    }

    /**
     * Create template
     */
    public function create($nameOrData, $content = null) {
        // Support both formats: create($data) or create($name, $content)
        if (is_array($nameOrData)) {
            $data = $nameOrData;
        } else {
            $data = [
                'name' => $nameOrData,
                'content' => $content
            ];
        }
        
        // Extract variables from content
        preg_match_all('/\{([a-z_]+)\}/i', $data['content'], $matches);
        $variables = array_unique($matches[1]);

        $id = $this->db->insert('templates', [
            'name' => $data['name'],
            'content' => $data['content'],
            'variables' => json_encode($variables),
            'is_active' => 1
        ]);

        return ['success' => true, 'id' => $id];
    }

    /**
     * Update template
     */
    public function update($id, $data) {
        // Extract variables from content
        preg_match_all('/\{([a-z_]+)\}/i', $data['content'], $matches);
        $variables = array_unique($matches[1]);

        $this->db->update('templates', [
            'name' => $data['name'],
            'content' => $data['content'],
            'variables' => json_encode($variables)
        ], 'id = ?', [$id]);

        return ['success' => true];
    }

    /**
     * Delete template (soft)
     */
    public function delete($id) {
        $this->db->update('templates', ['is_active' => 0], 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Restore template
     */
    public function restore($id) {
        $this->db->update('templates', ['is_active' => 1], 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Permanently delete
     */
    public function permanentDelete($id) {
        $this->db->delete('templates', 'id = ?', [$id]);
        return ['success' => true];
    }

    /**
     * Process template - replace variables with values
     */
    public function process($templateId, $variables = []) {
        $template = $this->get($templateId);
        if (!$template) {
            return ['success' => false, 'error' => 'Template not found'];
        }

        $message = $template['content'];
        foreach ($variables as $key => $value) {
            $message = str_replace("{{$key}}", $value, $message);
        }

        // Check for unreplaced variables
        preg_match_all('/\{([a-z_]+)\}/i', $message, $matches);
        if (!empty($matches[0])) {
            return [
                'success' => false,
                'error' => 'Missing variables: ' . implode(', ', $matches[1]),
                'message' => $message
            ];
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * Get template variables
     */
    public function getVariables($id) {
        $template = $this->get($id);
        if (!$template) return [];
        return json_decode($template['variables'], true) ?: [];
    }

    /**
     * Increment usage count
     */
    public function incrementUsage($id) {
        $this->db->query(
            "UPDATE templates SET usage_count = usage_count + 1 WHERE id = ?",
            [$id]
        );
    }

    /**
     * Duplicate template
     */
    public function duplicate($id) {
        $template = $this->get($id);
        if (!$template) {
            return ['success' => false, 'error' => 'Template not found'];
        }

        return $this->create([
            'name' => $template['name'] . ' (Copy)',
            'content' => $template['content']
        ]);
    }
}
