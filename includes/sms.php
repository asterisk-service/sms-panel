<?php
/**
 * SMS Core Functions
 * Send, Receive, Anti-Spam
 * Supports: OpenVox, GoIP
 * Multiple gateways support
 */

require_once __DIR__ . '/database.php';

class SMS {
    private $db;
    private $spamInterval;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadSettings();
    }

    private function loadSettings() {
        $settings = $this->db->fetchAll("SELECT setting_key, setting_value FROM settings");
        $config = [];
        foreach ($settings as $s) {
            $config[$s['setting_key']] = $s['setting_value'];
        }
        
        $this->spamInterval = (int)($config['spam_interval'] ?? SPAM_INTERVAL);
    }
    
    /**
     * Get all active gateways
     */
    public function getGateways($activeOnly = true) {
        $sql = "SELECT * FROM gateways";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY is_default DESC, priority DESC, name ASC";
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Get default gateway
     */
    public function getDefaultGateway() {
        // First try to get the default gateway
        $gw = $this->db->fetchOne("SELECT * FROM gateways WHERE is_default = 1 AND is_active = 1");
        if ($gw) return $gw;
        
        // Otherwise get highest priority active gateway
        $gw = $this->db->fetchOne("SELECT * FROM gateways WHERE is_active = 1 ORDER BY priority DESC LIMIT 1");
        if ($gw) return $gw;
        
        // Fallback to legacy settings (if any exist in settings table)
        $settings = $this->db->fetchAll("SELECT setting_key, setting_value FROM settings");
        $config = [];
        foreach ($settings as $s) {
            $config[$s['setting_key']] = $s['setting_value'];
        }
        
        return [
            'id' => 0,
            'name' => 'Default',
            'type' => $config['gateway_type'] ?? 'openvox',
            'host' => $config['gateway_host'] ?? '',
            'port' => $config['gateway_port'] ?? 80,
            'username' => $config['gateway_user'] ?? '',
            'password' => $config['gateway_pass'] ?? '',
            'channels' => 8
        ];
    }
    
    /**
     * Get gateway by ID
     */
    public function getGateway($id) {
        if ($id <= 0) {
            return $this->getDefaultGateway();
        }
        $gw = $this->db->fetchOne("SELECT * FROM gateways WHERE id = ?", [$id]);
        return $gw ?: $this->getDefaultGateway();
    }
    
    /**
     * Update gateway usage statistics
     */
    private function updateGatewayUsage($gatewayId) {
        if ($gatewayId > 0) {
            $this->db->query(
                "UPDATE gateways SET messages_sent = messages_sent + 1, last_used_at = NOW() WHERE id = ?",
                [$gatewayId]
            );
        }
    }

    /**
     * Check if number is spam-blocked
     */
    public function isSpamBlocked($phoneNumber) {
        $phone = $this->normalizePhone($phoneNumber);
        $result = $this->db->fetchOne(
            "SELECT last_sent FROM spam_log 
             WHERE phone_number = ? 
             AND last_sent > DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY last_sent DESC LIMIT 1",
            [$phone, $this->spamInterval]
        );
        
        if ($result) {
            $lastSent = strtotime($result['last_sent']);
            $waitTime = $this->spamInterval - (time() - $lastSent);
            return ['blocked' => true, 'wait_seconds' => $waitTime];
        }
        return ['blocked' => false];
    }

    /**
     * Update spam log after sending
     */
    private function updateSpamLog($phoneNumber) {
        $phone = $this->normalizePhone($phoneNumber);
        $this->db->insert('spam_log', [
            'phone_number' => $phone,
            'last_sent' => date('Y-m-d H:i:s')
        ]);
        
        // Cleanup old records (older than 1 hour)
        $this->db->query(
            "DELETE FROM spam_log WHERE last_sent < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
    }

    /**
     * Normalize phone number
     */
    public function normalizePhone($phone) {
        // Remove all non-digit characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Remove leading +
        $hasPlus = (substr($phone, 0, 1) === '+');
        $phone = ltrim($phone, '+');
        
        // Russian number normalization
        // 9167193249 (10 digits) → +79167193249
        // 79167193249 (11 digits starting with 7) → +79167193249
        // 89167193249 (11 digits starting with 8) → +79167193249
        
        if (strlen($phone) == 10 && $phone[0] == '9') {
            // 9167193249 → +79167193249
            return '+7' . $phone;
        }
        
        if (strlen($phone) == 11) {
            if ($phone[0] == '8') {
                // 89167193249 → +79167193249
                return '+7' . substr($phone, 1);
            }
            if ($phone[0] == '7') {
                // 79167193249 → +79167193249
                return '+' . $phone;
            }
        }
        
        // For other formats, just add + if it was there or return as-is
        if ($hasPlus || strlen($phone) >= 11) {
            return '+' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Format port number to OpenVox format
     * Converts: 1 -> gsm-1.1, 4 -> gsm-1.4, 5 -> gsm-2.1, 8 -> gsm-2.4, etc.
     * OpenVox format: gsm-{module}.{port} where each module has 4 ports
     */
    public function formatPort($port) {
        // If already in gsm-x.x format, return as-is
        if (strpos($port, 'gsm-') === 0) {
            return $port;
        }
        
        $portNum = (int)$port;
        
        // OpenVox modules have 4 ports each:
        // Module 1: gsm-1.1 to gsm-1.4 (ports 1-4)
        // Module 2: gsm-2.1 to gsm-2.4 (ports 5-8)
        // Module 3: gsm-3.1 to gsm-3.4 (ports 9-12), etc.
        $slot = ceil($portNum / 4);
        $slotPort = (($portNum - 1) % 4) + 1;
        
        return "gsm-{$slot}.{$slotPort}";
    }
    
    /**
     * Parse port from OpenVox format back to number
     * Converts: gsm-1.1 -> 1, gsm-1.4 -> 4, gsm-2.1 -> 5, gsm-2.4 -> 8, etc.
     */
    public function parsePort($gsmPort) {
        if (preg_match('/gsm-(\d+)\.(\d+)/', $gsmPort, $matches)) {
            $slot = (int)$matches[1];
            $port = (int)$matches[2];
            return (($slot - 1) * 4) + $port;
        }
        return (int)$gsmPort;
    }

    /**
     * Send SMS via Gateway (OpenVox or GoIP)
     * @param string $phoneNumber - Recipient phone number
     * @param string $message - Message text
     * @param int|null $port - Port/channel number
     * @param int|null $templateId - Template ID if using template
     * @param int|null $gatewayId - Gateway ID (0 or null = use default)
     */
    public function send($phoneNumber, $message, $port = null, $templateId = null, $gatewayId = null) {
        $phone = $this->normalizePhone($phoneNumber);
        
        // Check anti-spam
        $spamCheck = $this->isSpamBlocked($phone);
        if ($spamCheck['blocked']) {
            return [
                'success' => false,
                'error' => "Anti-spam protection: Please wait {$spamCheck['wait_seconds']} seconds before sending to this number again."
            ];
        }

        // Get gateway settings
        $gateway = $this->getGateway($gatewayId ?? 0);
        $gwType = $gateway['type'] ?? 'openvox';
        $gwHost = $gateway['host'];
        $gwPort = $gateway['port'] ?? 80;
        $gwUser = $gateway['username'] ?? '';
        $gwPass = $gateway['password'] ?? '';
        $gwId = $gateway['id'] ?? 0;
        $gwName = $gateway['name'] ?? 'Default';

        // Log file
        $logFile = __DIR__ . '/../logs/outgoing_' . date('Y-m-d') . '.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Gateway doesn't support newlines in GET URL - replace with space
        $cleanMessage = str_replace(["\r\n", "\r", "\n"], ' ', $message);
        
        // Build URL based on gateway type
        if ($gwType === 'goip') {
            // GoIP Gateway
            $params = [
                'u' => $gwUser,
                'p' => $gwPass,
                'l' => $port ? (int)$port : 1,
                'n' => $phone,
                'm' => $cleanMessage
            ];
            $url = "http://{$gwHost}:{$gwPort}/default/en_US/send.html?" . http_build_query($params);
            $portForDb = $port ? (string)$port : null;
        } else {
            // OpenVox Gateway
            $params = [
                'username' => $gwUser,
                'password' => $gwPass,
                'phonenumber' => $phone,
                'message' => $cleanMessage,
                'report' => 'JSON',
                'timeout' => 30
            ];
            if ($port) {
                $params['port'] = $this->formatPort($port);
            }
            $url = "http://{$gwHost}:{$gwPort}/sendsms?" . http_build_query($params);
            $portForDb = $port ? $this->formatPort($port) : null;
        }

        file_put_contents($logFile, date('Y-m-d H:i:s') . " [{$gwType}:{$gwName}] SEND: phone={$phone}, port=" . ($port ?? 'auto') . ", msg=" . mb_substr($cleanMessage, 0, 50) . "\n", FILE_APPEND);

        // Create outbox record
        $outboxId = $this->db->insert('outbox', [
            'phone_number' => $phone,
            'message' => $message,
            'port' => $portForDb,
            'status' => 'pending',
            'template_id' => $templateId,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Send request to gateway
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
        file_put_contents($logFile, date('Y-m-d H:i:s') . " RESPONSE: HTTP {$httpCode}, error=" . ($error ?: 'none') . ", body=" . mb_substr($response, 0, 200) . "\n", FILE_APPEND);

        // Parse response
        $status = 'failed';
        $statusMessage = $response;
        $usedPort = null;

        if ($error) {
            $statusMessage = "Connection error: " . $error;
            file_put_contents($logFile, date('Y-m-d H:i:s') . " ERROR: {$statusMessage}\n", FILE_APPEND);
        } elseif ($httpCode != 200) {
            $statusMessage = "HTTP error: " . $httpCode . " - " . mb_substr($response, 0, 100);
            file_put_contents($logFile, date('Y-m-d H:i:s') . " ERROR: {$statusMessage}\n", FILE_APPEND);
        } else {
            // Parse response based on gateway type
            if ($gwType === 'goip') {
                $responseLower = strtolower($response);
                if (strpos($responseLower, 'sending') !== false || 
                    strpos($responseLower, 'ok') !== false ||
                    strpos($responseLower, 'success') !== false ||
                    strpos($responseLower, 'sent') !== false) {
                    $status = 'sent';
                    $this->updateSpamLog($phone);
                    $usedPort = $port;
                }
                $statusMessage = trim($response);
            } else {
                // OpenVox JSON response
                $json = json_decode($response, true);
                
                if ($json && isset($json['report'])) {
                    foreach ($json['report'] as $reports) {
                        foreach ($reports as $report) {
                            if (is_array($report) && isset($report[0])) {
                                $r = $report[0];
                            } else {
                                $r = $report;
                            }
                            
                            if (isset($r['result'])) {
                                $result = strtolower($r['result']);
                                if ($result === 'success' || $result === 'sending' || $result === 'sent') {
                                    $status = 'sent';
                                    $this->updateSpamLog($phone);
                                } elseif ($result === 'delivered') {
                                    $status = 'delivered';
                                    $this->updateSpamLog($phone);
                                }
                                if (isset($r['port'])) {
                                    $usedPort = $r['port'];
                                }
                            }
                        }
                    }
                    $statusMessage = $json['message'] ?? $response;
                } else {
                    // Fallback text parsing
                    if (stripos($response, 'success') !== false || 
                        stripos($response, 'sending') !== false ||
                        stripos($response, 'sent') !== false) {
                        $status = 'sent';
                        $this->updateSpamLog($phone);
                    }
                }
            }
        }

        // Update outbox record
        $this->db->update('outbox', [
            'status' => $status,
            'status_message' => $statusMessage,
            'port' => $usedPort ?: $portForDb,
            'sent_at' => $status != 'failed' ? date('Y-m-d H:i:s') : null
        ], 'id = ?', [$outboxId]);

        // Log final status
        file_put_contents($logFile, date('Y-m-d H:i:s') . " RESULT: status={$status}, outbox_id={$outboxId}\n\n", FILE_APPEND);

        // Update gateway usage statistics
        if ($status != 'failed') {
            $this->updateGatewayUsage($gwId);
        }

        // Update template usage if used
        if ($templateId) {
            $this->db->query(
                "UPDATE templates SET usage_count = usage_count + 1 WHERE id = ?",
                [$templateId]
            );
        }
        
        // Update port usage counter
        if ($status != 'failed' && $port) {
            $this->updatePortUsage($port);
        }

        return [
            'success' => $status != 'failed',
            'status' => $status,
            'message' => $statusMessage,
            'outbox_id' => $outboxId,
            'gateway_id' => $gwId
        ];
    }

    /**
     * Send bulk SMS to multiple numbers
     */
    public function sendBulk($phoneNumbers, $message, $templateId = null, $gatewayId = null) {
        $results = [];
        foreach ($phoneNumbers as $phone) {
            $results[$phone] = $this->send($phone, $message, null, $templateId, $gatewayId);
            // Small delay between sends
            usleep(500000); // 0.5 second
        }
        return $results;
    }

    /**
     * Receive SMS (called from webhook)
     */
    public function receive($data) {
        $phone = $this->normalizePhone($data['phonenumber'] ?? '');
        $message = $data['message'] ?? '';
        $port = $data['port'] ?? '';
        $portName = $data['portname'] ?? '';
        $time = $data['time'] ?? date('Y-m-d H:i:s');
        $imsi = $data['imsi'] ?? '';

        if (empty($phone) || empty($message)) {
            return ['success' => false, 'error' => 'Missing phone or message'];
        }

        $id = $this->db->insert('inbox', [
            'phone_number' => $phone,
            'message' => $message,
            'port' => $port,
            'port_name' => $portName,
            'imsi' => $imsi,
            'received_at' => $time,
            'is_read' => 0
        ]);

        return ['success' => true, 'id' => $id];
    }

    /**
     * Get inbox messages
     */
    public function getInbox($page = 1, $perPage = 20, $search = '', $unreadOnly = false, $portNumbers = null) {
        $offset = ($page - 1) * $perPage;
        $where = '1=1';
        $params = [];

        if ($search) {
            $where .= " AND (phone_number LIKE ? OR message LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($unreadOnly) {
            $where .= " AND is_read = 0";
        }
        
        // Filter by allowed ports (for non-admin users)
        if ($portNumbers !== null && !empty($portNumbers)) {
            $placeholders = implode(',', array_fill(0, count($portNumbers), '?'));
            $where .= " AND port IN ({$placeholders})";
            $params = array_merge($params, $portNumbers);
        } elseif ($portNumbers !== null && empty($portNumbers)) {
            // User has no allowed ports - return empty result
            return ['messages' => [], 'total' => 0, 'pages' => 0, 'current_page' => $page];
        }

        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM inbox WHERE {$where}",
            $params
        )['cnt'];

        $messages = $this->db->fetchAll(
            "SELECT i.*, c.name as contact_name 
             FROM inbox i 
             LEFT JOIN contacts c ON i.phone_number = c.phone_number 
             WHERE {$where} 
             ORDER BY received_at DESC 
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'messages' => $messages,
            'total' => $total,
            'pages' => ceil($total / $perPage),
            'page' => $page
        ];
    }

    /**
     * Get outbox messages
     */
    public function getOutbox($page = 1, $perPage = 20, $search = '', $status = '', $portNumbers = null) {
        $offset = ($page - 1) * $perPage;
        $where = '1=1';
        $params = [];

        if ($search) {
            $where .= " AND (phone_number LIKE ? OR message LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }
        
        // Filter by allowed ports (for non-admin users)
        if ($portNumbers !== null && !empty($portNumbers)) {
            $placeholders = implode(',', array_fill(0, count($portNumbers), '?'));
            $where .= " AND port IN ({$placeholders})";
            $params = array_merge($params, $portNumbers);
        } elseif ($portNumbers !== null && empty($portNumbers)) {
            // User has no allowed ports - return empty result
            return ['messages' => [], 'total' => 0, 'pages' => 0, 'current_page' => $page];
        }

        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM outbox WHERE {$where}",
            $params
        )['cnt'];

        $messages = $this->db->fetchAll(
            "SELECT o.*, c.name as contact_name, t.name as template_name 
             FROM outbox o 
             LEFT JOIN contacts c ON o.phone_number = c.phone_number 
             LEFT JOIN templates t ON o.template_id = t.id 
             WHERE {$where} 
             ORDER BY created_at DESC 
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'messages' => $messages,
            'total' => $total,
            'pages' => ceil($total / $perPage),
            'page' => $page
        ];
    }

    /**
     * Mark message as read
     */
    public function markAsRead($id) {
        $this->db->update('inbox', ['is_read' => 1], 'id = ?', [$id]);
    }

    /**
     * Mark all as read
     */
    public function markAllAsRead() {
        $this->db->query("UPDATE inbox SET is_read = 1 WHERE is_read = 0");
    }

    /**
     * Get unread count
     */
    public function getUnreadCount() {
        return $this->db->fetchOne("SELECT COUNT(*) as cnt FROM inbox WHERE is_read = 0")['cnt'];
    }

    /**
     * Delete message
     */
    public function deleteInbox($id) {
        $this->db->delete('inbox', 'id = ?', [$id]);
    }

    public function deleteOutbox($id) {
        $this->db->delete('outbox', 'id = ?', [$id]);
    }

    /**
     * Process template with variables
     */
    public function processTemplate($templateContent, $variables = []) {
        $message = $templateContent;
        foreach ($variables as $key => $value) {
            $message = str_replace("{{$key}}", $value, $message);
        }
        return $message;
    }

    /**
     * Get statistics
     */
    public function getStats() {
        return [
            'inbox_total' => (int)($this->db->fetchOne("SELECT COUNT(*) as cnt FROM inbox")['cnt'] ?? 0),
            'inbox_unread' => $this->getUnreadCount(),
            'unread' => $this->getUnreadCount(),
            'outbox_total' => (int)($this->db->fetchOne("SELECT COUNT(*) as cnt FROM outbox")['cnt'] ?? 0),
            'outbox_sent' => (int)($this->db->fetchOne("SELECT COUNT(*) as cnt FROM outbox WHERE status = 'sent'")['cnt'] ?? 0),
            'outbox_failed' => (int)($this->db->fetchOne("SELECT COUNT(*) as cnt FROM outbox WHERE status = 'failed'")['cnt'] ?? 0),
            'pending' => (int)($this->db->fetchOne("SELECT COUNT(*) as cnt FROM outbox WHERE status = 'pending'")['cnt'] ?? 0),
            'failed' => (int)($this->db->fetchOne("SELECT COUNT(*) as cnt FROM outbox WHERE status = 'failed'")['cnt'] ?? 0),
            'contacts_total' => (int)($this->db->fetchOne("SELECT COUNT(*) as cnt FROM contacts WHERE is_active = 1")['cnt'] ?? 0),
            'templates_total' => (int)($this->db->fetchOne("SELECT COUNT(*) as cnt FROM templates WHERE is_active = 1")['cnt'] ?? 0),
            'today_sent' => (int)($this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM outbox WHERE DATE(created_at) = CURDATE()"
            )['cnt'] ?? 0),
            'today_received' => (int)($this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM inbox WHERE DATE(received_at) = CURDATE()"
            )['cnt'] ?? 0)
        ];
    }
    
    /**
     * Get active gateway ports
     */
    public function getActivePorts() {
        return $this->db->fetchAll(
            "SELECT * FROM gateway_ports WHERE is_active = 1 ORDER BY port_number"
        );
    }
    
    /**
     * Get next linear port (round-robin)
     */
    public function getNextLinearPort() {
        $ports = $this->getActivePorts();
        if (empty($ports)) return null;
        
        // Get last used port from outbox
        $lastUsed = $this->db->fetchOne(
            "SELECT port FROM outbox WHERE port IS NOT NULL ORDER BY id DESC LIMIT 1"
        );
        
        $lastPort = $lastUsed ? (int)$lastUsed['port'] : 0;
        $portNumbers = array_column($ports, 'port_number');
        
        // Find next port in sequence
        $currentIndex = array_search($lastPort, $portNumbers);
        $nextIndex = ($currentIndex === false) ? 0 : (($currentIndex + 1) % count($portNumbers));
        
        return (int)$portNumbers[$nextIndex];
    }
    
    /**
     * Get least used port
     */
    public function getLeastUsedPort() {
        $port = $this->db->fetchOne(
            "SELECT port_number FROM gateway_ports 
             WHERE is_active = 1 
             ORDER BY messages_sent ASC, last_used_at ASC NULLS FIRST
             LIMIT 1"
        );
        
        return $port ? (int)$port['port_number'] : null;
    }
    
    /**
     * Update port usage counter
     */
    public function updatePortUsage($portNumber) {
        if (!$portNumber) return;
        
        $this->db->query(
            "UPDATE gateway_ports SET 
                messages_sent = messages_sent + 1, 
                last_used_at = NOW() 
             WHERE port_number = ?",
            [$portNumber]
        );
    }
    
    /**
     * Send bulk SMS with port mode
     */
    public function sendBulkWithPort($phoneNumbers, $message, $portMode = 'random', $specificPort = null, $gatewayId = null) {
        $results = [];
        $linearPortIndex = 0;
        $ports = $this->getActivePorts();
        $portNumbers = array_column($ports, 'port_number');
        
        foreach ($phoneNumbers as $phone) {
            $port = null;
            
            switch ($portMode) {
                case 'specific':
                    $port = $specificPort;
                    break;
                case 'linear':
                    if (!empty($portNumbers)) {
                        $port = $portNumbers[$linearPortIndex % count($portNumbers)];
                        $linearPortIndex++;
                    }
                    break;
                case 'least_used':
                    $port = $this->getLeastUsedPort();
                    break;
                // random - port stays null, gateway decides
            }
            
            $result = $this->send($phone, $message, $port, null, $gatewayId);
            
            // Update port counter if successful
            if ($result['success'] && $port) {
                $this->updatePortUsage($port);
            }
            
            $results[$phone] = $result;
            
            // Small delay between sends
            usleep(100000); // 100ms
        }
        
        return $results;
    }
}
