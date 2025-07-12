<?php

// --- Database Connection ---
$db_path = __DIR__ . '/activation.db';
$pdo = null;
try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    die("Database connection failed: " . $e->getMessage());
}

// --- Request Parsing ---
$post_data = file_get_contents('php://input');

if (empty($post_data)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die("Missing request body");
}

function parse_plist_for_device_info($plist_string) {
    $udid_pattern = '/<key>UniqueDeviceID<\/key>\s*<string>(.*?)<\/string>/';
    $serial_pattern = '/<key>SerialNumber<\/key>\s*<string>(.*?)<\/string>/';
    $product_type_pattern = '/<key>ProductType<\/key>\s*<string>(.*?)<\/string>/';
    $imei_pattern = '/<key>InternationalMobileEquipmentIdentity<\/key>\s*<string>(.*?)<\/string>/';

    $udid = preg_match($udid_pattern, $plist_string, $matches) ? $matches[1] : null;
    $serial = preg_match($serial_pattern, $plist_string, $matches) ? $matches[1] : null;
    $product_type = preg_match($product_type_pattern, $plist_string, $matches) ? $matches[1] : null;
    $imei = preg_match($imei_pattern, $plist_string, $matches) ? $matches[1] : null;

    return [
        'udid' => $udid,
        'serial_number' => $serial,
        'product_type' => $product_type,
        'imei' => $imei,
    ];
}

// --- Device Lookup ---
$is_icloud_login_attempt = isset($_POST['apple_id']) && isset($_POST['password']);

if ($is_icloud_login_attempt) {
    $udid = $_POST['udid'];
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE udid = ?");
    $stmt->execute([$udid]);
} else {
    $device_info = parse_plist_for_device_info($post_data);
    $udid = $device_info['udid'];
    $serial_number = $device_info['serial_number'];

    if (!$udid || !$serial_number) {
        http_response_code(400);
        header('Content-Type: text/plain');
        die("Failed to parse UDID and SerialNumber from activation-info");
    }
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE udid = ? OR serial_number = ?");
    $stmt->execute([$udid, $serial_number]);
}

$device_record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$device_record && !$is_icloud_login_attempt) {
    $stmt = $pdo->prepare(
        "INSERT INTO devices (udid, serial_number, product_type, imei) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$udid, $serial_number, $device_info['product_type'], $device_info['imei']]);
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE udid = ?");
    $stmt->execute([$udid]);
    $device_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- iCloud Lock Logic ---

$is_locked = !empty($device_record['apple_id']);
$can_activate = false;

if ($is_locked) {
    if ($is_icloud_login_attempt) {
        if ($_POST['apple_id'] === $device_record['apple_id'] && $_POST['password'] === $device_record['password']) {
            $can_activate = true; // Credentials correct
        } else {
            generate_icloud_form($device_record, "Incorrect Apple ID or password.");
            exit();
        }
    } else {
        generate_icloud_form($device_record);
        exit();
    }
} else {
    // Not locked, can activate
    $can_activate = true;
}

// --- Final Activation ---

if ($can_activate) {
    $activation_record_plist = generate_activation_record($device_record);

    // Update database
    $stmt = $pdo->prepare("UPDATE devices SET activation_state = 'Activated', activation_record = ? WHERE id = ?");
    $stmt->execute([$activation_record_plist, $device_record['id']]);

    // Send response to client
    header('Content-Type: text/xml');
    echo $activation_record_plist;
}


// --- Helper Functions ---

function generate_icloud_form($device, $error_message = null) {
    header('Content-Type: application/x-buddyml');
    $description = "This {$device['product_type']} is linked to an Apple ID. Enter the Apple ID and password that were used to set up this device.";
    if ($error_message) {
        $description = "<p color='red'>{$error_message}</p>" . $description;
    }

    $xml = <<<XML
<plist version="1.0">
<dict>
    <key>page</key>
    <dict>
        <key>navigationBar</key>
        <dict><key>title</key><string>Activation Lock</string></dict>
        <key>tableView</key>
        <dict>
            <key>section</key>
            <dict>
                <footer>{$description}</footer>
                <key>editableTextRow</key>
                <array>
                    <dict><key>id</key><string>apple_id</string><key>label</key><string>Apple ID</string><key>placeholder</key><string>example@icloud.com</string></dict>
                    <dict><key>id</key><string>password</string><key>label</key><string>Password</string><key>secure</key><true/></dict>
                </array>
            </dict>
        </dict>
    </dict>
    <key>serverInfo</key>
    <dict><key>udid</key><string>{$device['udid']}</string></dict>
</dict>
</plist>
XML;
    echo $xml;
}

function generate_activation_record($device) {
    // These would be dynamically generated in a real scenario
    $account_token_cert = base64_encode(random_bytes(512));
    $device_cert = base64_encode(random_bytes(512));
    $account_token = base64_encode(random_bytes(256));
    $account_token_signature = base64_encode(random_bytes(128));

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
    '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n" .
    '<plist version="1.0"><dict><key>ActivationRecord</key><dict>' .
    '<key>unbrick</key><true/>' .
    '<key>AccountTokenCertificate</key><data>' . $account_token_cert . '</data>' .
    '<key>DeviceCertificate</key><data>' . $device_cert . '</data>' .
    '<key>AccountToken</key><data>' . $account_token . '</data>' .
    '<key>AccountTokenSignature</key><data>' . $account_token_signature . '</data>' .
    '</dict></dict></plist>';
}

// Close the database connection
$pdo = null;
?>
