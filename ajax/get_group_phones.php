<?php
/**
 * AJAX Get Group Phones
 * Returns JSON array of phone numbers for a group
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/contacts.php';

$groupId = (int)($_GET['group_id'] ?? 0);

if ($groupId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid group ID', 'phones' => []]);
    exit;
}

try {
    $contacts = new Contacts();
    $groupContacts = $contacts->getByGroup($groupId);
    
    $phones = [];
    $names = [];
    foreach ($groupContacts as $c) {
        $phones[] = $c['phone_number'];
        $names[] = [
            'name' => $c['name'],
            'phone' => $c['phone_number']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($phones),
        'phones' => $phones,
        'contacts' => $names
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to get group contacts',
        'phones' => []
    ]);
}
