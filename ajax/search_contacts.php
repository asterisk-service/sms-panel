<?php
/**
 * AJAX Contact Search
 * Returns JSON array of contacts matching search query
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/contacts.php';

$query = trim($_GET['q'] ?? '');
$limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

if (strlen($query) < 2) {
    echo json_encode(['success' => false, 'error' => 'Query too short', 'contacts' => []]);
    exit;
}

try {
    $contacts = new Contacts();
    $results = $contacts->search($query, $limit);
    
    $data = [];
    foreach ($results as $c) {
        $data[] = [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'phone' => $c['phone_number'],
            'company' => $c['company'] ?? '',
            'label' => $c['name'] . ' (' . $c['phone_number'] . ')'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($data),
        'contacts' => $data
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Search failed',
        'contacts' => []
    ]);
}
