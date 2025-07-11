<?php
// Set Apple-like headers for DRM handshake
header('Server: Apple');
header('Date: ' . gmdate('D, d M Y H:i:s T'));
header('Content-Type: application/xml');
header('Connection: close');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Strict-Transport-Security: max-age=31536000; includeSubdomains');
header('Referrer-Policy: no-referrer');
header('X-B3-TraceId: ' . bin2hex(random_bytes(8)));
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: POST, GET, OPTIONS');
    echo '<error>Method Not Allowed</error>';
    exit();
}

$input = file_get_contents('php://input');
if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = isset($_GET['activation-info']) ? $_GET['activation-info'] : '';
}

if (empty($input)) {
    http_response_code(400);
    echo '<error>Missing activation-info or request body</error>';
    exit();
}

error_log("DRM Handshake Request: " . $input);

$collection_blob = '';
$handshake_request_message = '';
$unique_device_id = '';

if (!empty($input)) {
    $xml = simplexml_load_string($input);
    if ($xml !== false) {
        $plist_data = json_decode(json_encode($xml), true);
        
        if (isset($plist_data['dict'])) {
            $dict = $plist_data['dict'];
            
            if (isset($dict['key'])) {
                $keys = is_array($dict['key']) ? $dict['key'] : [$dict['key']];
                $values = [];
                
                if (isset($dict['data'])) {
                    $data_values = is_array($dict['data']) ? $dict['data'] : [$dict['data']];
                    $values = array_merge($values, $data_values);
                }
                if (isset($dict['string'])) {
                    $string_values = is_array($dict['string']) ? $dict['string'] : [$dict['string']];
                    $values = array_merge($values, $string_values);
                }
                
                for ($i = 0; $i < count($keys) && $i < count($values); $i++) {
                    switch ($keys[$i]) {
                        case 'CollectionBlob':
                            $collection_blob = $values[$i];
                            break;
                        case 'HandshakeRequestMessage':
                            $handshake_request_message = $values[$i];
                            break;
                        case 'UniqueDeviceID':
                            $unique_device_id = $values[$i];
                            break;
                    }
                }
            }
        }
    }
}

// Generate realistic response data based on the sample
$server_kp = base64_encode(random_bytes(32));
$fdr_blob = base64_encode(random_bytes(20));

$su_info = '';

$handshake_response = base64_encode(random_bytes(128));

// Generate the XML response content matching the sample structure
$xml_response = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>serverKP</key>
	<data>' . $server_kp . '</data>
	<key>FDRBlob</key>
	<data>' . $fdr_blob . '</data>
	<key>SUInfo</key>
	<data>' . $su_info . '</data>
	<key>HandshakeResponseMessage</key>
	<data>' . $handshake_response . '</data>
</dict>
</plist>';

// Return XML response directly (not JSON)
error_log("DRM Handshake Response generated for device: " . $unique_device_id);
echo $xml_response;
?>
