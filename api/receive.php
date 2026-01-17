<?php
/**
 * SMS Receive API Endpoint
 * This endpoint receives incoming SMS from OpenVox Gateway
 * 
 * Configure on OpenVox Gateway:
 * SMS Settings -> SMS Forwarding -> HTTP
 * URL: http://YOUR_SERVER_IP/sms-panel/api/receive.php
 * 
 * OpenVox sends these parameters:
 * - from / sender / phonenumber - sender's phone number
 * - smscontent / content / message / text - SMS text
 * - port - port number (gsm-1.1)
 * - time / recvtime - receive time
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../includes/sms.php';

// Log incoming request
$logFile = __DIR__ . '/../logs/incoming_' . date('Y-m-d') . '.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$timestamp = date('Y-m-d H:i:s');
$rawInput = file_get_contents('php://input');

$requestData = [
    'time' => $timestamp,
    'method' => $_SERVER['REQUEST_METHOD'],
    'get' => $_GET,
    'post' => $_POST,
    'raw' => $rawInput,
    'request' => $_REQUEST
];

file_put_contents($logFile, json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

// Parse raw input if POST data is empty (OpenVox might send as raw body)
if (empty($_POST) && !empty($rawInput)) {
    parse_str($rawInput, $parsedBody);
    $_REQUEST = array_merge($_REQUEST, $parsedBody);
}

// Get phone number - try multiple possible parameter names
$phoneNumber = '';
$phoneParams = ['from', 'sender', 'phonenumber', 'phone', 'srcnum', 'src'];
foreach ($phoneParams as $param) {
    if (!empty($_REQUEST[$param])) {
        $phoneNumber = $_REQUEST[$param];
        break;
    }
}

// Get message - try multiple possible parameter names
$message = '';
$messageParams = ['smscontent', 'content', 'message', 'text', 'sms', 'msg'];
foreach ($messageParams as $param) {
    if (!empty($_REQUEST[$param])) {
        $message = $_REQUEST[$param];
        break;
    }
}

// URL decode message if needed (OpenVox URL encodes messages)
$message = urldecode($message);

// Get other parameters
$port = $_REQUEST['port'] ?? $_REQUEST['portname'] ?? '';
$portName = $_REQUEST['portname'] ?? $_REQUEST['port'] ?? '';
$time = $_REQUEST['time'] ?? $_REQUEST['recvtime'] ?? $_REQUEST['datetime'] ?? date('Y-m-d H:i:s');
$imsi = $_REQUEST['imsi'] ?? '';

// Also check for delivery status report
$status = $_REQUEST['status'] ?? $_REQUEST['dlrstatus'] ?? '';
$messageId = $_REQUEST['message_id'] ?? $_REQUEST['id'] ?? $_REQUEST['msgid'] ?? $_REQUEST['smsid'] ?? '';

// Log parsed data
file_put_contents($logFile, "PARSED: phone={$phoneNumber}, message={$message}, port={$port}, status={$status}, msgid={$messageId}\n", FILE_APPEND);

// Check if this is a delivery status report
if (!empty($messageId) && !empty($status)) {
    require_once __DIR__ . '/../includes/campaign.php';
    
    try {
        $campaign = new Campaign();
        $campaign->updateDeliveryStatus($messageId, $status, $time);
        
        echo "OK: Delivery status updated";
        file_put_contents($logFile, "DELIVERY STATUS: ID {$messageId} = {$status}\n", FILE_APPEND);
        exit;
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage();
        file_put_contents($logFile, "DELIVERY ERROR: {$e->getMessage()}\n", FILE_APPEND);
        exit;
    }
}

// Validate required fields for incoming SMS
if (empty($phoneNumber)) {
    echo "ERROR: Missing phone number. Tried params: " . implode(', ', $phoneParams);
    file_put_contents($logFile, "ERROR: Missing phone number\n", FILE_APPEND);
    exit;
}

if (empty($message)) {
    echo "ERROR: Missing message. Tried params: " . implode(', ', $messageParams);
    file_put_contents($logFile, "ERROR: Missing message\n", FILE_APPEND);
    exit;
}

// Prepare data for saving
$data = [
    'phonenumber' => $phoneNumber,
    'message' => $message,
    'port' => $port,
    'portname' => $portName,
    'time' => $time,
    'imsi' => $imsi
];

// Process SMS
try {
    $sms = new SMS();
    $result = $sms->receive($data);
    
    if ($result['success']) {
        echo "OK: Message received, ID: " . $result['id'];
        file_put_contents($logFile, "SUCCESS: ID {$result['id']}, phone={$phoneNumber}, msg=" . mb_substr($message, 0, 50) . "\n", FILE_APPEND);
    } else {
        echo "ERROR: " . $result['error'];
        file_put_contents($logFile, "ERROR: {$result['error']}\n", FILE_APPEND);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
    file_put_contents($logFile, "EXCEPTION: {$e->getMessage()}\n", FILE_APPEND);
}
