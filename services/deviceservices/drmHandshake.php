<?php
// Set Apple-like headers for DRM handshake
header('Content-Type: application/xml');
header('Server: Apple');
header('Date: ' . gmdate('D, d M Y H:i:s T'));
header('Connection: close');

// The client expects a plist response containing a HandshakeResponseMessage.
// For the purpose of this simulation, we can generate a random one.
$handshake_response_message = base64_encode(random_bytes(128));

// The SUInfo is also expected. In a real scenario, this would contain
// subscriber information. We can leave it empty for simulation.
$su_info = base64_encode('');

// Other fields that might be expected in the response.
$server_kp = base64_encode(random_bytes(32));
$fdr_blob = base64_encode(random_bytes(20));

// Construct the XML plist response
$xml_response = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
'<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n" .
'<plist version="1.0">' . "\n" .
'<dict>' . "\n" .
'	<key>HandshakeResponseMessage</key>' . "\n" .
'	<data>' . $handshake_response_message . '</data>' . "\n" .
'	<key>SUInfo</key>' . "\n" .
'	<data>' . $su_info . '</data>' . "\n" .
'	<key>serverKP</key>' . "\n" .
'	<data>' . $server_kp . '</data>' . "\n" .
'	<key>FDRBlob</key>' . "\n" .
'	<data>' . $fdr_blob . '</data>' . "\n" .
'</dict>' . "\n" .
'</plist>';

echo $xml_response;
?>
