<?php
/**
 * Campaign Class - Bulk SMS Sender
 * User-based filtering
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

class Campaign {
    private $db;
    private $currentPort = 0;
    private $userId;
    private $isAdmin;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $auth = Auth::getInstance();
        $this->userId = $auth->getUserId();
        $this->isAdmin = $auth->isAdmin();
    }
    
    /**
     * Get user filter
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
     * Create a new campaign
     */
    public function create($data) {
        $name = $data['name'] ?? 'Campaign ' . date('Y-m-d H:i');
        $message = $data['message'] ?? '';
        $gatewayId = !empty($data['gateway_id']) ? (int)$data['gateway_id'] : null;
        $portMode = $data['port_mode'] ?? 'random';
        $specificPort = $data['specific_port'] ?? null;
        $sendDelay = (int)($data['send_delay'] ?? 1000);
        $numbers = $data['numbers'] ?? [];
        
        if (empty($message)) {
            return ['success' => false, 'error' => 'Message is required'];
        }
        
        if (empty($numbers)) {
            return ['success' => false, 'error' => 'At least one phone number is required'];
        }
        
        // Create campaign with user_id
        $this->db->query(
            "INSERT INTO campaigns (user_id, name, message, total_count, gateway_id, port_mode, specific_port, send_delay, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
            [$this->userId, $name, $message, count($numbers), $gatewayId, $portMode, $specificPort, $sendDelay]
        );
        
        $campaignId = $this->db->lastInsertId();
        
        // Insert campaign messages
        foreach ($numbers as $item) {
            $phone = is_array($item) ? ($item['phone'] ?? $item[0] ?? '') : $item;
            $contactName = is_array($item) ? ($item['name'] ?? $item[1] ?? null) : null;
            
            $phone = $this->normalizePhone($phone);
            if (empty($phone)) continue;
            
            // Replace {name} in message if contact name provided
            $personalMessage = $message;
            if ($contactName) {
                $personalMessage = str_replace('{name}', $contactName, $personalMessage);
            }
            
            $this->db->query(
                "INSERT INTO campaign_messages (campaign_id, phone_number, contact_name, message, status) 
                 VALUES (?, ?, ?, ?, 'pending')",
                [$campaignId, $phone, $contactName, $personalMessage]
            );
        }
        
        return ['success' => true, 'campaign_id' => $campaignId];
    }
    
    /**
     * Normalize phone number to +7XXXXXXXXXX format
     */
    public function normalizePhone($phone) {
        // Remove all non-digit characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Remove leading +
        $hasPlus = (substr($phone, 0, 1) === '+');
        $phone = ltrim($phone, '+');
        
        // Russian number normalization
        if (strlen($phone) == 10 && $phone[0] == '9') {
            return '+7' . $phone;
        }
        
        if (strlen($phone) == 11) {
            if ($phone[0] == '8') {
                return '+7' . substr($phone, 1);
            }
            if ($phone[0] == '7') {
                return '+' . $phone;
            }
        }
        
        if ($hasPlus || strlen($phone) >= 11) {
            return '+' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Get campaign by ID
     */
    public function get($id) {
        $userFilter = $this->getUserFilter();
        return $this->db->fetchOne(
            "SELECT * FROM campaigns WHERE id = ? AND {$userFilter['where']}",
            array_merge([$id], $userFilter['params'])
        );
    }
    
    /**
     * Get all campaigns with pagination
     */
    public function getAll($page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        $userFilter = $this->getUserFilter();
        
        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM campaigns WHERE {$userFilter['where']}",
            $userFilter['params']
        )['cnt'];
        
        $campaigns = $this->db->fetchAll(
            "SELECT * FROM campaigns WHERE {$userFilter['where']} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($userFilter['params'], [$perPage, $offset])
        );
        
        return [
            'campaigns' => $campaigns,
            'total' => $total,
            'pages' => ceil($total / $perPage)
        ];
    }
    
    /**
     * Get campaign messages
     */
    public function getMessages($campaignId, $page = 1, $perPage = 50, $status = null) {
        $offset = ($page - 1) * $perPage;
        
        $where = "campaign_id = ?";
        $params = [$campaignId];
        
        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }
        
        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM campaign_messages WHERE $where", 
            $params
        )['cnt'];
        
        $params[] = $perPage;
        $params[] = $offset;
        
        $messages = $this->db->fetchAll(
            "SELECT * FROM campaign_messages WHERE $where ORDER BY id ASC LIMIT ? OFFSET ?",
            $params
        );
        
        return [
            'messages' => $messages,
            'total' => $total,
            'pages' => ceil($total / $perPage)
        ];
    }
    
    /**
     * Get campaign statistics
     */
    public function getStats($campaignId) {
        return $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total,
                SUM(status = 'pending') as pending,
                SUM(status = 'sending') as sending,
                SUM(status = 'sent') as sent,
                SUM(status = 'failed') as failed,
                SUM(status = 'delivered') as delivered
             FROM campaign_messages WHERE campaign_id = ?",
            [$campaignId]
        );
    }
    
    /**
     * Start or resume campaign
     */
    public function start($campaignId) {
        $campaign = $this->get($campaignId);
        if (!$campaign) {
            return ['success' => false, 'error' => 'Campaign not found'];
        }
        
        if ($campaign['status'] === 'completed') {
            return ['success' => false, 'error' => 'Campaign already completed'];
        }
        
        $this->db->query(
            "UPDATE campaigns SET status = 'running', started_at = COALESCE(started_at, NOW()) WHERE id = ?",
            [$campaignId]
        );
        
        return ['success' => true];
    }
    
    /**
     * Pause campaign
     */
    public function pause($campaignId) {
        $this->db->query(
            "UPDATE campaigns SET status = 'paused' WHERE id = ?",
            [$campaignId]
        );
        return ['success' => true];
    }
    
    /**
     * Cancel campaign
     */
    public function cancel($campaignId) {
        $this->db->query(
            "UPDATE campaigns SET status = 'cancelled', completed_at = NOW() WHERE id = ?",
            [$campaignId]
        );
        return ['success' => true];
    }
    
    /**
     * Delete campaign
     */
    public function delete($campaignId) {
        $this->db->query("DELETE FROM campaign_messages WHERE campaign_id = ?", [$campaignId]);
        $this->db->query("DELETE FROM campaigns WHERE id = ?", [$campaignId]);
        return ['success' => true];
    }
    
    /**
     * Get next port based on mode
     * @param int $campaignId - Campaign ID
     * @param int|null $gatewayId - Gateway ID (null = all gateways)
     */
    public function getNextPort($campaignId, $gatewayId = null) {
        $campaign = $this->get($campaignId);
        
        // Get active ports, filtered by gateway if specified
        if ($gatewayId) {
            $activePorts = $this->db->fetchAll(
                "SELECT port_number, port_name FROM gateway_ports WHERE is_active = 1 AND gateway_id = ? ORDER BY port_number",
                [$gatewayId]
            );
        } else {
            $activePorts = $this->db->fetchAll(
                "SELECT port_number, port_name FROM gateway_ports WHERE is_active = 1 ORDER BY port_number"
            );
        }
        
        if (empty($activePorts)) {
            return ['port' => 1, 'port_name' => 'Port 1'];
        }
        
        if ($campaign['port_mode'] === 'specific' && $campaign['specific_port']) {
            return ['port' => $campaign['specific_port'], 'port_name' => 'Port ' . $campaign['specific_port']];
        }
        
        if ($campaign['port_mode'] === 'random') {
            $port = $activePorts[array_rand($activePorts)];
            return ['port' => $port['port_number'], 'port_name' => $port['port_name']];
        }
        
        // Linear mode - round robin
        $lastUsed = $this->db->fetchOne(
            "SELECT port FROM campaign_messages WHERE campaign_id = ? AND port IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$campaignId]
        );
        
        $lastPort = $lastUsed ? $lastUsed['port'] : 0;
        $portNumbers = array_column($activePorts, 'port_number');
        
        // Find next port in sequence
        $currentIndex = array_search($lastPort, $portNumbers);
        $nextIndex = ($currentIndex === false) ? 0 : (($currentIndex + 1) % count($portNumbers));
        
        $nextPort = $activePorts[$nextIndex];
        return ['port' => $nextPort['port_number'], 'port_name' => $nextPort['port_name']];
    }
    
    /**
     * Send next message in campaign
     */
    public function sendNext($campaignId) {
        $campaign = $this->get($campaignId);
        
        if (!$campaign || $campaign['status'] !== 'running') {
            return ['success' => false, 'error' => 'Campaign not running', 'completed' => true];
        }
        
        // Get next pending message
        $message = $this->db->fetchOne(
            "SELECT * FROM campaign_messages WHERE campaign_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 1",
            [$campaignId]
        );
        
        if (!$message) {
            // No more messages - campaign complete
            $this->db->query(
                "UPDATE campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?",
                [$campaignId]
            );
            $this->updateCampaignCounts($campaignId);
            return ['success' => true, 'completed' => true];
        }
        
        // Mark as sending
        $this->db->query(
            "UPDATE campaign_messages SET status = 'sending' WHERE id = ?",
            [$message['id']]
        );
        
        // Get port to use (consider gateway_id)
        $gatewayId = $campaign['gateway_id'] ?? null;
        $portInfo = $this->getNextPort($campaignId, $gatewayId);
        
        // Send SMS via gateway
        $result = $this->sendViaGateway($message['phone_number'], $message['message'], $portInfo['port'], $gatewayId);
        
        // Update message status
        if ($result['success']) {
            $this->db->query(
                "UPDATE campaign_messages SET 
                    status = 'sent', 
                    port = ?, 
                    port_name = ?,
                    gateway_response = ?,
                    gateway_message_id = ?,
                    sent_at = NOW()
                 WHERE id = ?",
                [
                    $portInfo['port'], 
                    $portInfo['port_name'],
                    $result['response'] ?? '',
                    $result['message_id'] ?? null,
                    $message['id']
                ]
            );
            
            // Update port usage
            $this->db->query(
                "UPDATE gateway_ports SET last_used_at = NOW(), messages_sent = messages_sent + 1 WHERE port_number = ?",
                [$portInfo['port']]
            );
        } else {
            $this->db->query(
                "UPDATE campaign_messages SET 
                    status = 'failed', 
                    port = ?, 
                    port_name = ?,
                    error_message = ?,
                    sent_at = NOW()
                 WHERE id = ?",
                [
                    $portInfo['port'], 
                    $portInfo['port_name'],
                    $result['error'] ?? 'Unknown error',
                    $message['id']
                ]
            );
        }
        
        // Update campaign counts
        $this->updateCampaignCounts($campaignId);
        
        return [
            'success' => true,
            'completed' => false,
            'message_id' => $message['id'],
            'phone' => $message['phone_number'],
            'status' => $result['success'] ? 'sent' : 'failed',
            'port' => $portInfo['port'],
            'delay' => $campaign['send_delay']
        ];
    }
    
    /**
     * Update campaign counts from messages
     */
    private function updateCampaignCounts($campaignId) {
        $stats = $this->getStats($campaignId);
        $this->db->query(
            "UPDATE campaigns SET 
                sent_count = ?, 
                failed_count = ?, 
                delivered_count = ?
             WHERE id = ?",
            [
                (int)$stats['sent'] + (int)$stats['delivered'],
                (int)$stats['failed'],
                (int)$stats['delivered'],
                $campaignId
            ]
        );
    }
    
    /**
     * Send SMS via Gateway (OpenVox or GoIP)
     */
    private function sendViaGateway($phone, $message, $port = null, $gatewayId = null) {
        // Get gateway by ID or default
        if ($gatewayId) {
            $gateway = $this->db->fetchOne("SELECT * FROM gateways WHERE id = ? AND is_active = 1", [$gatewayId]);
        }
        
        if (empty($gateway)) {
            $gateway = $this->db->fetchOne("SELECT * FROM gateways WHERE is_default = 1 AND is_active = 1");
        }
        
        if (empty($gateway)) {
            $gateway = $this->db->fetchOne("SELECT * FROM gateways WHERE is_active = 1 ORDER BY priority DESC LIMIT 1");
        }
        
        if (empty($gateway)) {
            return ['success' => false, 'error' => 'No active gateway found'];
        }
        
        $gwType = $gateway['type'] ?? 'openvox';
        $host = $gateway['host'];
        $gwPort = $gateway['port'] ?? 80;
        $user = $gateway['username'] ?? '';
        $pass = $gateway['password'] ?? '';
        
        // Gateway doesn't support newlines in GET URL - replace with space
        $cleanMessage = str_replace(["\r\n", "\r", "\n"], ' ', $message);
        
        // Build URL based on gateway type
        if ($gwType === 'goip') {
            // GoIP Gateway
            $params = [
                'u' => $user,
                'p' => $pass,
                'l' => $port ? (int)$port : 1,
                'n' => $phone,
                'm' => $cleanMessage
            ];
            $url = "http://{$host}:{$gwPort}/default/en_US/send.html?" . http_build_query($params);
        } else {
            // OpenVox Gateway
            $params = [
                'username' => $user,
                'password' => $pass,
                'phonenumber' => $phone,
                'message' => $cleanMessage,
                'report' => 'JSON',
                'timeout' => 30
            ];
            if ($port) {
                $params['port'] = $this->formatPort($port);
            }
            $url = "http://{$host}:{$gwPort}/sendsms?" . http_build_query($params);
        }
        
        // Log outgoing request
        $logFile = __DIR__ . '/../logs/campaign_' . date('Y-m-d') . '.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, date('Y-m-d H:i:s') . " [{$gwType}] SEND: phone={$phone}, port=" . ($port ?? 'auto') . "\n", FILE_APPEND);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Log response
        file_put_contents($logFile, date('Y-m-d H:i:s') . " RESPONSE: HTTP {$httpCode}, body=" . mb_substr($response, 0, 200) . "\n", FILE_APPEND);
        
        if ($error) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " ERROR: {$error}\n", FILE_APPEND);
            return ['success' => false, 'error' => $error];
        }
        
        if ($httpCode != 200) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " ERROR: HTTP {$httpCode}\n", FILE_APPEND);
            return ['success' => false, 'error' => "HTTP $httpCode", 'response' => $response];
        }
        
        // Parse response based on gateway type
        $success = false;
        $messageId = null;
        
        if ($gwType === 'goip') {
            // GoIP response
            $responseLower = strtolower($response);
            if (strpos($responseLower, 'sending') !== false || 
                strpos($responseLower, 'ok') !== false ||
                strpos($responseLower, 'success') !== false ||
                strpos($responseLower, 'sent') !== false) {
                $success = true;
            }
        } else {
            // OpenVox JSON response
            $json = json_decode($response, true);
            if ($json && isset($json['report'])) {
                foreach ($json['report'] as $reports) {
                    foreach ($reports as $report) {
                        $r = is_array($report) && isset($report[0]) ? $report[0] : $report;
                        if (isset($r['result'])) {
                            $result = strtolower($r['result']);
                            if ($result === 'success' || $result === 'sending' || $result === 'sent') {
                                $success = true;
                            }
                        }
                    }
                }
            } else {
                // Fallback text parsing
                if (stripos($response, 'success') !== false || 
                    stripos($response, 'sending') !== false ||
                    stripos($response, 'sent') !== false) {
                    $success = true;
                }
            }
        }
        
        file_put_contents($logFile, date('Y-m-d H:i:s') . " RESULT: success=" . ($success ? 'true' : 'false') . "\n\n", FILE_APPEND);
        
        return [
            'success' => $success,
            'response' => $response,
            'message_id' => $messageId
        ];
    }
    
    /**
     * Format port number to OpenVox format
     * Converts: 1 -> gsm-1.1, 2 -> gsm-1.2, 9 -> gsm-2.1, etc.
     */
    public function formatPort($port) {
        if (strpos($port, 'gsm-') === 0) {
            return $port;
        }
        
        $portNum = (int)$port;
        // OpenVox modules have 4 ports each
        $slot = ceil($portNum / 4);
        $slotPort = (($portNum - 1) % 4) + 1;
        
        return "gsm-{$slot}.{$slotPort}";
    }
    
    /**
     * Update message delivery status (called from webhook)
     */
    public function updateDeliveryStatus($messageId, $status, $timestamp = null) {
        $newStatus = ($status === 'delivered' || $status === 'DELIVRD') ? 'delivered' : 'failed';
        
        $this->db->query(
            "UPDATE campaign_messages SET 
                status = ?, 
                delivered_at = ?
             WHERE gateway_message_id = ?",
            [$newStatus, $timestamp ?? date('Y-m-d H:i:s'), $messageId]
        );
        
        // Get campaign ID and update counts
        $msg = $this->db->fetchOne(
            "SELECT campaign_id FROM campaign_messages WHERE gateway_message_id = ?",
            [$messageId]
        );
        
        if ($msg) {
            $this->updateCampaignCounts($msg['campaign_id']);
        }
    }
    
    /**
     * Get gateway ports
     */
    public function getPorts() {
        return $this->db->fetchAll("SELECT * FROM gateway_ports ORDER BY port_number");
    }
    
    /**
     * Update port settings
     */
    public function updatePort($portNumber, $data) {
        $this->db->query(
            "UPDATE gateway_ports SET port_name = ?, sim_number = ?, is_active = ? WHERE port_number = ?",
            [
                $data['port_name'] ?? "Port $portNumber",
                $data['sim_number'] ?? null,
                $data['is_active'] ?? 1,
                $portNumber
            ]
        );
        return ['success' => true];
    }
    
    /**
     * Parse CSV/text input into numbers array
     */
    public static function parseNumbers($input, $separator = 'auto') {
        $numbers = [];
        $lines = preg_split('/[\r\n]+/', trim($input));
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Try to detect separator
            if ($separator === 'auto') {
                if (strpos($line, ';') !== false) {
                    $separator = ';';
                } elseif (strpos($line, ',') !== false) {
                    $separator = ',';
                } elseif (strpos($line, "\t") !== false) {
                    $separator = "\t";
                } else {
                    $separator = ',';
                }
            }
            
            $parts = explode($separator, $line);
            $phone = preg_replace('/[^0-9+]/', '', trim($parts[0] ?? ''));
            $name = trim($parts[1] ?? '');
            
            if (!empty($phone)) {
                $numbers[] = [
                    'phone' => $phone,
                    'name' => $name ?: null
                ];
            }
        }
        
        return $numbers;
    }
    
    /**
     * Import numbers from CSV file
     */
    public static function importFromCSV($filePath) {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        return self::parseNumbers($content);
    }
}
