<?php
/**
 * AJAX Campaign Send - Sends next message in campaign
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/campaign.php';

$campaignId = (int)($_GET['campaign_id'] ?? 0);

if ($campaignId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid campaign ID']);
    exit;
}

try {
    $campaign = new Campaign();
    $result = $campaign->sendNext($campaignId);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
