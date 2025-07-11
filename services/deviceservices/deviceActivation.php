<?php
// Set Apple-like headers for device activation
header('Server: Apple');
header('Date: ' . gmdate('D, d M Y H:i:s T'));
header('Content-Type: application/xml');
header('Connection: close');
header('ARS: ' . base64_encode(random_bytes(16)));
header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
header('X-Client-Request-Id: ' . sprintf('%08x-%04x-%04x-%04x-%012x', 
    mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff), mt_rand(0, 0xffffffffffff)));
header('Strict-Transport-Security: max-age=31536000; includeSubdomains');
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


// Parse multipart form data for activation request
$activation_info = '';
$in_store_activation = false;

// Add this line to capture raw POST data
$input = file_get_contents('php://input') ?? '';
error_log("[DEBUG] Raw POST body: " . $input);

// Parse multipart form data for activation request
$activation_info = '';
$in_store_activation = false;

// Extract activation-info from multipart data
if (strpos($input, 'activation-info') !== false) {
    $lines = explode("\n", $input);
    $capture_data = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'name="activation-info"') !== false) {
            $capture_data = true;
            continue;
        }
        if ($capture_data && strpos($line, '--') === 0) {
            break;
        }
        if ($capture_data && !empty($line)) {
            $activation_info .= $line . "\n";
        }
    }
}

// Parse the activation info if it's base64 encoded plist
$device_info = [];
if (!empty($activation_info)) {
    $xml = simplexml_load_string(trim($activation_info));
    if ($xml !== false) {
        $plist_data = json_decode(json_encode($xml), true);
        
        if (isset($plist_data['dict'])) {
            $dict = $plist_data['dict'];
            
            // Extract ActivationInfoXML if present
            if (isset($dict['key']) && isset($dict['data'])) {
                $keys = is_array($dict['key']) ? $dict['key'] : [$dict['key']];
                $data_values = is_array($dict['data']) ? $dict['data'] : [$dict['data']];
                
                for ($i = 0; $i < count($keys) && $i < count($data_values); $i++) {
                    if ($keys[$i] === 'ActivationInfoXML') {
                        $decoded_xml = base64_decode($data_values[$i]);
                        $activation_xml = simplexml_load_string($decoded_xml);
                        if ($activation_xml !== false) {
                            $activation_data = json_decode(json_encode($activation_xml), true);
                            // Extract device information from the nested structure
                            if (isset($activation_data['dict'])) {
                                $device_info = $activation_data['dict'];
                            }
                        }
                        break;
                    }
                }
            }
        }
    }
}

// Extract device information for certificate generation
$serial_number = 'DNPF561P0F0N';
$unique_device_id = '00008101-000E714A3ED2001E';
$imei = '351672927028143';
$product_type = 'iPhone13,2';

// Parse device info from activation request if available
if (!empty($device_info) && isset($device_info['key']) && isset($device_info['string'])) {
    $keys = is_array($device_info['key']) ? $device_info['key'] : [$device_info['key']];
    $strings = is_array($device_info['string']) ? $device_info['string'] : [$device_info['string']];
    
    for ($i = 0; $i < count($keys) && $i < count($strings); $i++) {
        switch ($keys[$i]) {
            case 'SerialNumber':
                $serial_number = $strings[$i];
                break;
            case 'UniqueDeviceID':
                $unique_device_id = $strings[$i];
                break;
            case 'ProductType':
                $product_type = $strings[$i];
                break;
            case 'InternationalMobileEquipmentIdentity':
                $imei = $strings[$i];
                break;
        }
    }
}

// Generate activation randomness UUID
$activation_randomness = strtoupper(bin2hex(random_bytes(8))) . '-' . 
                        strtoupper(bin2hex(random_bytes(2))) . '-' . 
                        strtoupper(bin2hex(random_bytes(2))) . '-' . 
                        strtoupper(bin2hex(random_bytes(2))) . '-' . 
                        strtoupper(bin2hex(random_bytes(6)));

// Generate AccountTokenCertificate (X.509 certificate format)
function generateAccountTokenCertificate() {
    $cert = "-----BEGIN CERTIFICATE-----\nMIIDZzCCAk+gAwIBAgIBAjANBgkqhkiG9w0BAQUFADBxMQswCQYDVQQGEwJVUzET\nMBEGA1UEChMKQXBwbGUgSW5jLjEmMCQGA1UECxMdQXBwbGUgQ2VydGlmaWNhdGlv\nbiBBdXRob3JpdHkxLTArBgNVBAMTJEFwcGxlIGlQaG9uZSBDZXJ0aWZpY2F0aW9u\nIEF1dGhvcml0eTAeFw0wNzA0MTYyMjU1MDJaFw0xNDA0MTYyMjU1MDJaMFsxCzAJ\nBgNVBAYTAlVTMRMwEQYDVQQKEwpBcHBsZSBJbmMuMRUwEwYDVQQLEwxBcHBsZSBp\nUGhvbmUxIDAeBgNVBAMTF0FwcGxlIGlQaG9uZSBBY3RpdmF0aW9uMIGfMA0GCSqG\nSIb3DQEBAQUAA4GNADCBiQKBgQDFAXzRImArmohHfbS2oPcqAfbEv0d1jk7GbnX7\n+4YUlyIfprzBVdlmz2JHYv1+04IzJtL7cL97UI7fk0i0OMY0al8a+JPQa4Ug611T\nbqEt+njAmAkge3HXWDBdAXD9MhkC7T/9o77zOQ1oli4cUdzlnYWfzmW0PduOxuve\nAeYY4wIDAQABo4GbMIGYMA4GA1UdDwEB/wQEAwIHgDAMBgNVHRMBAf8EAjAAMB0G\nA1UdDgQWBBShoNL+t7Rz/psUaq/NPXNPH+/WlDAfBgNVHSMEGDAWgBTnNCouIt45\nYGu0lM53g2EvMaB8NTA4BgNVHR8EMTAvMC2gK6AphidodHRwOi8vd3d3LmFwcGxl\nLmNvbS9hcHBsZWNhL2lwaG9uZS5jcmwwDQYJKoZIhvcNAQEFBQADggEBAF9qmrUN\ndA+FROYG7pWcYTAK+pLyOf9zOaE7aeVI885V8Y/BKHhlwAo+zEkiOU3FbEPCS9V\ntS18ZBcwD/+d5ZQTMFknhcUJwdPqqjnm9LqTfH/x4pw8ONHRDzxHdp96gOV3A4+8\nabkoASfcYqvIRypXnbur3bRRhTzAs4VILS6jTyFYymZeSewdBubmmigo1kCQiZGc\n76c5feDAyHb2bzEqtvx3WprljdS46QT5CR6YelinZnio32jAzRYTxtS6r3JsvZDi\nJ07+EHcmfGdpxwgO+7btW1pFar0ZjF9/jYKKnOYNyvCrwszhafbSYwzAG5EJoXFB\n4d+piWHUDcPxtcc=\n-----END CERTIFICATE-----";
    return base64_encode($cert);
}

// Generate DeviceCertificate (X.509 certificate format)
function generateDeviceCertificate() {
    $cert = "-----BEGIN CERTIFICATE-----
MIIC8zCCAlygAwIBAgIKAxIAGgpl/WywQTANBgkqhkiG9w0BAQUFADBaMQswCQYD
VQQGEwJVUzETMBEGA1UEChMKQXBwbGUgSW5jLjEVMBMGA1UECxMMQXBwbGUgaVBo
b25lMR8wHQYDVQQDExZBcHBsZSBpUGhvbmUgRGV2aWNlIENBMB4XDTI0MDYxMzA2
NTYyMFoXDTI3MDYxMzA2NTYyMFowgYMxLTArBgNVBAMWJDY0QTEzQjQ2LUVDOTYt
NDM3RS04MTQ4LTdENTFFN0ZFMzFBQjELMAkGA1UEBhMCVVMxCzAJBgNVBAgTAkNB
MRIwEAYDVQQHEwlDdXBlcnRpbm8xEzARBgNVBAoTCkFwcGxlIEluYy4xDzANBgNV
BAsTBmlQaG9uZTCBnzANBgkqhkiG9w0BAQEFAAOBjQAwgYkCgYEAgU7WZtaKJsc/
4B8RI0Mr0YMxJaYOZnRXEuqv7piD3ibFp3ty73LFDlGRIYIS3lnl4FiMD9lJUgyR
JcAgFN0fEvz68LmiGqaCTC2ubLnT7pU8401EGYuSEnnMwoa/ftrmVUQR1eXzHF4
BME1GIs/UMhxvMgoyQKwX0aJ7vpwqHUCAwEAAaOBlTCBkjAfBgNVHSMEGDAWgBSy
/iEjRIaVannVgSaOcxDYp0yOdDAdBgNVHQ4EFgQUPR+FZoTwgSXwkPEI2JApool1
vIEwDAYDVR0TAQH/BAIwADAOBgNVHQ8BAf8EBAMCBaAwIAYDVR0lAQH/BBYwFAYI
KwYBBQUHAwEGCCsGAQUFBwMCMBAGCiqGSIb3Y2QGCgIEAgUAMA0GCSqGSIb3DQEB
BQUAA4GBAEaBm7mx9ygfq1NJ8NdBS3N1ufuJ2pIgPCIJntptbGfVChSl2p/4P2HZ
TK/0cSNs51ntKf6AsSqbPhw5p7x6P3YDdMKKfhuo4tQUvgbdkRC7JH6zTMrnfJgH
0vowRamT53eqwoRpOi4SMz47WXCEV962thAm0MFzFzLOGH6mM+aL
-----END CERTIFICATE-----";
    return base64_encode($cert);
}

// Generate UniqueDeviceCertificate (ECC certificate format)
function generateUniqueDeviceCertificate() {
    $cert = "-----BEGIN CERTIFICATE-----
MIIDjDCCAzKgAwIBAgIGAZAQYhAfMAoGCCqGSM49BAMCMEUxEzARBgNVBAgMCkNh
bGlmb3JuaWExEzARBgNVBAoMCkFwcGxlIEluYy4xGTAXBgNVBAMMEEZEUkRDLVVE
UlQtU1VCQ0EwHhcNMjQwNjEzMDY0NjIwWhcNMjQwNjIwMDY1NjIwWjBuMRMwEQYD
VQQIDApDYWxpZm9ybmlhMRMwEQYDVQQKDApBcHBsZSBJbmMuMR4wHAYDVQQLDBV1
Y3J0IExlYWYgQ2VydGlmaWNhdGUxIjAgBgNVBAMMGTAwMDA4MDEwLTAwMTg0OUU0
MDA2QTQzMjYwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAASlaZRrpTS0FVXjaagh
re18vDROwVDPFLx/B716ixjjfriS/rhk7Lm8CGIrfYle90hmEtaGBJPU8S4QHFFh
/GvSo4IB4zCCAd8wDAYDVR0TAQH/BAIwADAOBgNVHQ8BAf8EBAMCBPAwggFMBgkq
hkiG92NkCgEEggE9MYIBOf+EkrWkRAsWCRYEQk9SRAIBDf+EmqGSUA0wCxYEQ0hJ
UAIDAAQf/4SqjZJEETAPFgRFQ0lEAgcYSeQAakMm/4aTtcJjGzAZFgRibWFjBBFj
MDpkMDoxMjpiNToyYjo4Nv+Gy7XKaRkwFxYEaW1laQQPMzU1MzI0MDg3ODI2NDIx
/4ebyNxtFjAUFgRzcm5tBAxGNEdUR1lKWkhHN0b/h6uR0mQyMDAWBHVkaWQEKDBh
NDYzMDVjYTJlYzgwZjk3ZjI4YTIyYjdiOTc3YzQ1YTAxYzgyOGH/h7u1wmMbMBkW
BHdtYWMEEWMwOmQwOjEyOmI1OjJiOjg2/4eblNJkOjA4FgRzZWlkBDAwNDI4MkZG
MzQ2M0U4MDAxNjMyMDEyNzYyMjkzOTk5NzZDQUZGNDk0NTI3REU2MTEwMgYKKoZI
hvdjZAYBDwQkMSL/hOqFnFAKMAoWBE1BUExAP+E+omUUAowCBYETMJKUDEAMBIG
CSqGSIb3Y2QKBwQaMBi/ingIBAYxNS43LjM/+KewgEBjE5SDMwNzAKBggqhkjOPQ
QDAgNIADBFAiEAx5xIXTd6ToWxEyV7i+1fKxclnaGvYGnxAqtBDII9N0MCIEiQiV
dHCjcEDXVKwC/8bufsePpypWJ5Q2ZimoUoWxJM
-----END CERTIFICATE-----ukMtH9RdSQvHzBx7FiBGr7/KcmlxX/XwoWeWnWb6IRM=
-----BEGIN CERTIFICATE-----
MIICFzCCAZygAwIBAgIIOcUqQ8IC/hswCgYIKoZIzj0EAwIwQDEUMBIGA1UEAwwL
U0VQIFJvb3QgQ0ExEzARBgNVBAoMCkFwcGxlIEluYy4xEzARBgNVBAgMCkNhbGlm
b3JuaWEwHhcNMTYwNDI1MjM0NTQ3WhcNMjkwNjI0MjE0MzI0WjBFMRMwEQYDVQQI
DApDYWxpZm9ybmlhMRMwEQYDVQQKDApBcHBsZSBJbmMuMRkwFwYDVQQDDBBGRFJE
Qy1VRFJULVNVQkNBMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEaDc2O/MruYvP
VPaUbKR7RRzn66B14/8KoUMsEDb7nHkGEMX6eC+0gStGHe4HYMrLyWcap1tDFYmE
DykGQ3uM2aN7MHkwHQYDVR0OBBYEFLSqOkOtG+V+zgoMOBq10hnLlTWzMA8GA1Ud
EwEB/wQFMAMBAf8wHwYDVR0jBBgwFoAUWO/WvsWCsFTNGKaEraL2e3s6f88wDgYD
VR0PAQH/BAQDAgEGMBYGCSqGSIb3Y2QGLAEBf/wQGFgR1Y3J0MAoGCCqGSM49BAMC
A2kAMGYCMQDf5zNiaKN/Jqms1v+3CDYkESOPieJMpEkLe9a0UjWXEBDL0VEsq/Cd
E3aKXkc6R10CMQDS4MiWiymY+Rxkvy/hicDQqI/BL+N3LHqzJZUuw2Sx0afDX7B
6LyKk+sLq4urkMY=
-----END CERTIFICATE-----";
    return base64_encode($cert);
}

// Generate FairPlayKeyData (Container format)
function generateFairPlayKeyData() {
    $container = "-----BEGIN CONTAINER-----
AAEAAT38erph3moGHiHNQS1NXq05B1s5D6RWoLxQajJ85CXFKRWo1B6soOwY3Du2
RmukHziK8UyhXFWSu8+W5R8tBm3s+CkaxjT7hgARyKJ4Snwxn/Su6ioYx17uQewB
gZjshYz+dziWSb8SkQC7EdFY3FvmkAq7fUgcraq6jSX81FVqw5n3iFT0sCQIxbn
ABEBCRYk9htYL/tegI3soCyFsrc5M859xSptF4Xvz55QVCBL58WmK6gTScTyUH3w
2RTDWR3FFrqGf7i5BWYqEWK0I34X2Mblftx937nb7K++LUdbO5bqYh34m4DqFpl+
fDgh5muMC6EeYfOy9itBllNZweeQbARkJkaGPbyHGibSBq6sGCkARvY9mOfMOxYb
eZ+e6xAFfx21pROA3LYsAf30rrkQsKJ85ADvU31JuAbnzfxd3Fz+lpWF/Exu9ASm
mWpQScUYiqyMvGQd9FyzdKMbMRCQ1IjFeXNQhVA64W683G3nWsF4wkyEDyyFr57d
qBwtP8v4aIxxduR85ZOIRqk4PigVU/mTiUEPzmzZXv1Pwg3e8jc/zY86hafGh6ld
Llp2MOnJCn7Zf+1E7Diq3kKo4mZ40v4pBNWPhvvFgDyX7R//Ti0ol+go75BdvoSi
icrE3aGNsHaoGzpOtHuNunG58wQoAYs0IHP8covlO08GXuQRXu5V23Ur+fKCkyvo
HJmaef/oYndwC0/+ZT/aNy6JQA3S85cwshQ7azXj9Yk3gw93pM17r9tLFz3GX4Pz
2ehLrUNL+Yq+5mmsy1zsdeqCF2WdGOJm8gj9n27GP3UVxT9P8NB4+V079DYwzLGb
8KtfBDLR3g0IzibFP76yT/EL50biZqN5JSKbz1KiYHiFKNBbrDl9aYaMvqI4xNnX
5WifN7X97Pq4LT3ankrhTeEjyqqx/dbj/0hzlmQD+LinTWoRSfEUb66/ixqEopkm
wWhzuvO1EOi4lyBTWOLvlTcXuYJpMJQdsBOGdIWkno4Bzy7pDIs/IzMQQ3iJDaW7
pbNWkICSw+DUbOt5WdVj7AGLATGaUEmYKWY6prrZ6nK4KYQxRC7sot76HrZj2eVz
EYxrmaW/eDxnaXC8lB5zBKL+CZCVfaXyDveu0d/w8i4cgE5jJAzKaErkCyIZJnJt
XNBa8Iw3v7imF6XODADiOJ+xFN7IAysznX0L8DRz2G5wb9rYe1m7x4G3wnjIqdma
oCw6H6sOpQQ3dVqWtP8k/QInNN6uvuXD7y/nUlvUjryUlCepYsx8d8SRql53wtHl
alZmJoEhtA7QT0TduURbz3gZmeW+2Q3pek5GiPJE+dr/7bIGDlaufIVEPMw8rX7j
U55QZfz0vrszyx7SLuH77FeFwycVRHwKz6AgvZNoDvoGLZOJN/6WSqVXfs61PGOc
twOVVI3z8X0kVQtGyJcA9Eb3tHPG33+3TibpL/dtUmKENVyE+A2Td3yDTruPEBk
dra3zEsnYYqqGb7iXo1Pzcw+Pj9A4iBQ6q9wDkAlACu6lfu0
-----END CONTAINER-----";
    return base64_encode($container);
}

// Generate AccountToken (Property list format)
function generateAccountToken($imei, $serial_number, $product_type, $unique_device_id, $activation_randomness) {
    $plist = '{
	"InternationalMobileEquipmentIdentity" = "' . $imei . '";
	"PhoneNumberNotificationURL" = "https://albert.apple.com/deviceservices/phoneHome";
	"SerialNumber" = "' . $serial_number . '";
	"ProductType" = "' . $product_type . '";
	"UniqueDeviceID" = "' . $unique_device_id . '";
	"WildcardTicket" = "MIICqgIBATALBgkqhkiG9w0BAQsxcJ8/DJNBAOkxALTzeBNbpp9ABGAAAACfSxRTueQpg/yza8WjD9qGr07bukTICZ+HbQc1UyQIeCZCn5c9DAAAAADu7u7u7u7u7+XPgQAAAAA+n5c/BAEAAAcfl0AEAQAAAj+XQQQBAAAn5dMBAAAAAEggEAC/WKb1cn3xEf5xMU8XfI9jbrU/oA+An+bQphyarg8gr6mNhgf6PZ12oEUIiWqscqwOoLSXVqc+dkonlaIZ2apETCc0OX9v03tpjgyPIhfjh/C51VK/xJ5i0/2/Mm0p7TB1QSevukb20J25AZRZOAEs22g4oKLF/Ww9ZgmRz+uQ+8La779PEltgzQ7i9toSaoLzlpFMtvslWVim+Zw+phRX+9I7X7uSTC1vsSxSQzZx6wZkXN+PDzXZ8u3a7HV98gk72LyFkDPU39zlO5F6zvheOVqcfWn4XJnPPvIZ6VvzK2/n4Y3dFIE3hlayPEzatElA3sF6aExMGgA+z6sj2KOCASAwCwYJKoZIhvcNAQEBA4IBDwAwggEKAoIBAQCskU9F2dz8TtWBq2D8AdsqcYS51H66DxZmCHEw6U9p3d8vjaEcBdF5VFwETmWJBcTJo/SiPLezdAmG40RfAsxg4sIok0CPhKsTp1mon0JBqai68SdmN0L+AsEbmNK4AjjMX6GM5t7w5mdXpgZyigRtGQDnV2P7HnOZj69PS9r/D4Q50CJNaLrGJZ1UVBNcKkJNTMD2pxrHnxdSLTj51xVITBU71Tdl7KghSskP8WagOONk5J0IcOCwIaWct9A/+Aso4yk5/PDh1YUhbUiIO+z1TL5TdiHLITgc8NXHagB/yiOEEzOx2pcZVXXjwfSZlKRHj66VlWVHgT+bEHZl0/sdAgMBAAE=";
	"PostponementInfo" = {};
	"ActivationRandomness" = "' . $activation_randomness . '";
	"ActivityURL" = "https://albert.apple.com/deviceservices/activity";
}';
    return base64_encode($plist);
}

// Generate all certificates and tokens
$account_token_cert = generateAccountTokenCertificate();
$device_cert = generateDeviceCertificate();
$regulatory_info = base64_encode('{"elabel":{"bis":{"regulatory":"R-41094897"}}}');
$fairplay_key_data = generateFairPlayKeyData();
$account_token = generateAccountToken($imei, $serial_number, $product_type, $unique_device_id, $activation_randomness);
$account_token_signature = base64_encode(random_bytes(64));
$unique_device_cert = generateUniqueDeviceCertificate();

// Replace the HTML response with direct XML/plist output
$response = '<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">\n<plist version="1.0"><dict><key>ActivationRecord</key><dict><key>unbrick</key><true/><key>AccountTokenCertificate</key><data>' . $account_token_cert . '</data><key>DeviceCertificate</key><data>' . $device_cert . '</data><key>RegulatoryInfo</key><data>' . $regulatory_info . '</data><key>FairPlayKeyData</key><data>' . $fairplay_key_data . '</data><key>AccountToken</key><data>' . $account_token . '</data><key>AccountTokenSignature</key><data>' . $account_token_signature . '</data><key>UniqueDeviceCertificate</key><data>' . $unique_device_cert . '</data></dict></dict></plist>';
echo $response;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post_data = file_get_contents('php://input');
    error_log("[DEBUG] Activation XML response: " . $response);
    error_log("Received POST request from iDevice. Payload:\n" . $raw_post_data);
}
ini_set('error_log', __DIR__ . '/php_errors.log');
?>
