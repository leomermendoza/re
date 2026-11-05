<?php
/**
 * TrackED — Scanner API Endpoint
 * File: api/scan.php
 *
 * Called by the Orange Pi One when a student taps their RFID card.
 * Method: POST
 * Body (JSON): { "rfid_uid": "A1B2C3D4", "scanner_key": "tracked_secret_key" }
 *
 * Response JSON:
 *   { "status": "ok", "student": {...}, "attendance_status": "On Time" }
 *   { "status": "unknown", "rfid_uid": "..." }
 *   { "status": "error", "message": "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Restrict in production to LAN IP

// ── CONFIG ──────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'tracked_db');

// Simple shared secret to prevent unauthorized inserts from outside LAN
define('SCANNER_KEY', 'tracked_secret_key'); // Change this!

// Attendance time rules
define('ONTIME_CUTOFF', '07:30:00'); // Before this = On Time
define('LATE_CUTOFF',   '08:30:00'); // After this = Late (no record = Absent)

// ── HELPERS ─────────────────────────────────────────────
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── VALIDATE METHOD ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Only POST allowed.'], 405);
}

// ── PARSE BODY ───────────────────────────────────────────
$raw   = file_get_contents('php://input');
$body  = json_decode($raw, true);

if (!$body) {
    // Also try form-encoded
    $rfid_uid    = $_POST['rfid_uid']    ?? null;
    $scanner_key = $_POST['scanner_key'] ?? null;
} else {
    $rfid_uid    = $body['rfid_uid']    ?? null;
    $scanner_key = $body['scanner_key'] ?? null;
}

// ── AUTHENTICATE ─────────────────────────────────────────
if ($scanner_key !== SCANNER_KEY) {
    respond(['status' => 'error', 'message' => 'Unauthorized.'], 401);
}

if (!$rfid_uid) {
    respond(['status' => 'error', 'message' => 'rfid_uid is required.'], 400);
}

// ── DB CONNECT ───────────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    respond(['status' => 'error', 'message' => 'DB connection failed.'], 500);
}

$rfid_uid = $conn->real_escape_string(trim($rfid_uid));

// ── FIND STUDENT ─────────────────────────────────────────
$result = $conn->query("SELECT * FROM students WHERE rfid_uid = '$rfid_uid' LIMIT 1");
if (!$result || $result->num_rows === 0) {
    // Unknown RFID — tell the dashboard to show alert
    respond([
        'status'   => 'unknown',
        'rfid_uid' => $rfid_uid,
        'message'  => 'RFID not registered.',
    ]);
}

$student = $result->fetch_assoc();
$student_id = intval($student['id']);

// ── DUPLICATE SCAN CHECK (same day) ──────────────────────
$today     = date('Y-m-d');
$dup_check = $conn->query("SELECT id, status FROM attendance WHERE student_id = $student_id AND DATE(scan_time) = '$today' LIMIT 1");
if ($dup_check && $dup_check->num_rows > 0) {
    $existing = $dup_check->fetch_assoc();
    respond([
        'status'            => 'duplicate',
        'message'           => 'Already scanned today.',
        'student'           => $student,
        'attendance_status' => $existing['status'],
    ]);
}

// ── DETERMINE ATTENDANCE STATUS ───────────────────────────
$current_time = date('H:i:s');
$att_status   = 'Late'; // default fallback
if ($current_time <= ONTIME_CUTOFF) {
    $att_status = 'On Time';
} elseif ($current_time <= LATE_CUTOFF) {
    $att_status = 'Late';
} else {
    $att_status = 'Late'; // could add 'Excused' if needed
}

// ── INSERT ATTENDANCE ─────────────────────────────────────
$scan_time = date('Y-m-d H:i:s');
$esc_status = $conn->real_escape_string($att_status);
$insert = $conn->query("INSERT INTO attendance (student_id, scan_time, status) VALUES ($student_id, '$scan_time', '$esc_status')");

if (!$insert) {
    respond(['status' => 'error', 'message' => 'Failed to record attendance.'], 500);
}

// ── SUCCESS ───────────────────────────────────────────────
respond([
    'status'            => 'ok',
    'student'           => [
        'id'           => $student['id'],
        'full_name'    => $student['full_name'],
        'year_level'   => $student['year_level'],
        'section'      => $student['section'],
        'photo_path'   => $student['photo_path'],
    ],
    'attendance_status' => $att_status,
    'scan_time'         => $scan_time,
]);
