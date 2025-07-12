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
    $device_info = parse_plist_for_device_info($post_data);
    $stmt = $pdo->prepare(
        "INSERT INTO devices (udid, serial_number, product_type, imei) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$device_info['udid'], $device_info['serial_number'], $device_info['product_type'], $device_info['imei']]);
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE udid = ?");
    $stmt->execute([$device_info['udid']]);
    $device_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- iCloud Lock Logic ---

$is_locked = !empty($device_record['apple_id']);
$can_activate = false;

if ($is_locked) {
    if ($is_icloud_login_attempt) {
        if ($_POST['apple_id'] === $device_record['apple_id'] && $_POST['password'] === $device_record['password']) {
            $can_activate = true;
        } else {
            generate_icloud_form($device_record, "Incorrect Apple ID or password.");
            exit();
        }
    } else {
        generate_icloud_form($device_record);
        exit();
    }
} else {
    $can_activate = true;
}

// --- Final Activation ---

if ($can_activate) {
    // Call the Python script to generate the dynamic activation record
    $udid = escapeshellarg($device_record['udid']);
    $serial = escapeshellarg($device_record['serial_number']);
    $imei = escapeshellarg($device_record['imei']);
    $product_type = escapeshellarg($device_record['product_type']);

    // IMPORTANT: Assumes python3 is in the PATH and the generator script is in the parent directory.
    // You may need to adjust the path to activation_record_generator.py
    $command = "python3 ../../activation_record_generator.py $udid $serial $imei $product_type";
    $activation_record_plist = shell_exec($command);

    if (empty($activation_record_plist)) {
        http_response_code(500);
        header('Content-Type: text/plain');
        die("Failed to generate activation record from Python script.");
    }

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
    $description = "This device is linked to an Apple ID. Enter the Apple ID and password that were used to set up this device.";
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

// Close the database connection
$pdo = null;
?>
