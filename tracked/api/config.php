<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');
mysqli_report(MYSQLI_REPORT_OFF);

require_once __DIR__ . '/verify.php';

$conn = @new mysqli(
    SERVER_HOST,
    SERVER_USER,
    SERVER_PASS,
    SERVER_DB,
    SERVER_PORT
);

if ($conn->connect_error) {
    echo json_encode([
        'offline' => true,
        'school_name' => 'TrackED Attendance System'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');

$config = [];
$res = $conn->query("SELECT config_key, config_value FROM system_config");

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $config[$r['config_key']] = $r['config_value'];
    }
}

$config['offline'] = false;
echo json_encode($config);
$conn->close();
