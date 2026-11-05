<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

date_default_timezone_set('Asia/Manila');
mysqli_report(MYSQLI_REPORT_OFF);

define('SERVER_HOST', '192.168.2.103');
define('SERVER_PORT', 3306);
define('SERVER_DB',   'tracked_db');
define('SERVER_USER', 'tracked_user');
define('SERVER_PASS', 'Akiesha050225');

define('LOCAL_HOST',  'localhost');
define('LOCAL_DB',    'tracked_local');
define('LOCAL_USER',  'root');
define('LOCAL_PASS',  'MyNewPassword123!');

define('SCANNER_KEY', 'tracked_secret_key');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function server_online() {
    $sock = @fsockopen(SERVER_HOST, SERVER_PORT, $e, $m, 2);
    if ($sock) {
        fclose($sock);
        return true;
    }
    return false;
}

function queue_sms($conn, $mobile, $message) {
    $m = $conn->real_escape_string($mobile);
    $g = $conn->real_escape_string($message);

    return $conn->query(
        "INSERT INTO sms_queue (mobile,message,status)
         VALUES ('$m','$g','pending')"
    );
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

$scanner_key = $body['scanner_key'] ?? '';
$action      = strtoupper(trim($body['action'] ?? ''));
$rfid_uid    = trim($body['rfid_uid'] ?? '');
$reason      = trim($body['reason'] ?? '');

if ($scanner_key !== SCANNER_KEY)
    respond(['status'=>'error','message'=>'Unauthorized.'], 401);

if (!$rfid_uid)
    respond(['status'=>'error','message'=>'rfid_uid required.'], 400);

if (!in_array($action, ['ACCEPT','REJECT'], true))
    respond(['status'=>'error','message'=>'Invalid verification action.'], 400);

$use_local = !server_online();
$conn = null;

if (!$use_local) {
    $try = @new mysqli(
        SERVER_HOST,
        SERVER_USER,
        SERVER_PASS,
        SERVER_DB
    );

    if ($try && !$try->connect_error) {
        $conn = $try;
    } else {
        $use_local = true;
    }
}

if ($use_local) {
    $conn = @new mysqli(
        LOCAL_HOST,
        LOCAL_USER,
        LOCAL_PASS,
        LOCAL_DB
    );

    if (!$conn || $conn->connect_error)
        respond([
            'status'=>'error',
            'message'=>'Local DB unavailable.'
        ], 500);
}

$conn->set_charset('utf8mb4');

$rfid = $conn->real_escape_string($rfid_uid);

$res = $conn->query(
    "SELECT * FROM students
     WHERE rfid_uid='$rfid'
     AND is_active=1
     LIMIT 1"
);

if (!$res || $res->num_rows === 0)
    respond([
        'status'=>'unknown',
        'message'=>'Student not found.',
        'offline'=>$use_local
    ]);

$student = $res->fetch_assoc();
$student_id = (int)$student['id'];

$now_full  = date('Y-m-d H:i:s');
$today     = date('Y-m-d');
$today_day = date('l');
$now       = date('H:i');

$config = [];

$cr = $conn->query(
    "SELECT config_key, config_value FROM system_config"
);

if ($cr) {
    while ($r = $cr->fetch_assoc()) {
        $config[$r['config_key']] = $r['config_value'];
    }
}

$time_in_start  = $config['time_in_start']  ?? '06:00';
$time_in_end    = $config['time_in_end']    ?? '12:00';
$time_out_start = $config['time_out_start'] ?? '15:00';
$time_out_end   = $config['time_out_end']   ?? '18:00';

$sms_enabled = ($config['sms_enabled'] ?? '1') === '1';
$school_name = $config['school_name'] ?? 'TrackED';

$in_window =
    ($now >= $time_in_start && $now <= $time_in_end);

$out_window =
    ($now >= $time_out_start && $now <= $time_out_end);

if (!$in_window && !$out_window)
    respond([
        'status'=>'outside_window',
        'message'=>"Not allowed at $now.",
        'offline'=>$use_local
    ]);

$scan_type = $in_window ? 'IN' : 'OUT';

/*
|--------------------------------------------------------------------------
| REJECT
|--------------------------------------------------------------------------
*/

if ($action === 'REJECT') {

    if ($reason === '')
        respond([
            'status'=>'error',
            'message'=>'Rejection reason required.'
        ], 400);

    $reason_sql = $conn->real_escape_string($reason);

    $ok = $conn->query(
        "INSERT INTO rejected_scans
        (student_id, rfid_uid, scan_type, rejection_reason, rejected_at)
        VALUES
        ($student_id, '$rfid_uid', '$scan_type', '$reason_sql', '$now_full')"
    );

    if (!$ok)
        respond([
            'status'=>'error',
            'message'=>'Failed to save rejected scan.',
            'db_error'=>$conn->error
        ], 500);

    respond([
        'status'=>'rejected',
        'student'=>[
            'id'=>$student['id'],
            'full_name'=>$student['full_name']
        ],
        'scan_type'=>$scan_type,
        'reason'=>$reason,
        'rejected_at'=>$now_full,
        'offline'=>$use_local
    ]);
}

/*
|--------------------------------------------------------------------------
| ACCEPT - TIME IN
|--------------------------------------------------------------------------
*/

if ($scan_type === 'IN') {

    $ex = $conn->query(
        "SELECT * FROM attendance
         WHERE student_id=$student_id
         AND DATE(scan_time)='$today'
         LIMIT 1"
    );

    $rec = ($ex && $ex->num_rows > 0)
        ? $ex->fetch_assoc()
        : null;

    if ($rec) {
        respond([
            'status'=>'duplicate',
            'student'=>sp($student),
            'attendance_status'=>$rec['status'],
            'scan_type'=>'IN',
            'offline'=>$use_local
        ]);
    }

    /*
     * Get schedule / cutoff
     */

    if (
        (int)$student['is_irregular'] &&
        !empty($student['irregular_days_json'])
    ) {
        $ontime_cut = '07:30:00';
    } else {

        $c = $conn->real_escape_string($student['course']);
        $y = $conn->real_escape_string($student['year_level']);

        $sr = $conn->query(
            "SELECT * FROM schedules
             WHERE course='$c'
             AND year_level='$y'
             LIMIT 1"
        );

        if ($sr && $sr->num_rows > 0) {
            $s = $sr->fetch_assoc();
            $ontime_cut = $s['ontime_cutoff'] ?? '07:30:00';
        } else {
            $ontime_cut = '07:30:00';
        }
    }

    $att_status =
        (date('H:i:s') <= $ontime_cut)
        ? 'On Time'
        : 'Late';

    $es = $conn->real_escape_string($att_status);

    /*
     * IMPORTANT:
     * Attendance is inserted ONLY AFTER ACCEPT.
     */

    $ok = $conn->query(
        "INSERT INTO attendance
        (student_id,scan_time,status,scan_type,sms_sent_in)
        VALUES
        ($student_id,'$now_full','$es','IN',0)"
    );

    if (!$ok)
        respond([
            'status'=>'error',
            'message'=>'Failed to save attendance.',
            'db_error'=>$conn->error
        ], 500);

    $att_id = (int)$conn->insert_id;

    /*
     * Offline queue
     */

    if ($use_local) {

        $re = $conn->real_escape_string($rfid_uid);

        $conn->query(
            "INSERT INTO offline_queue
            (rfid_uid,scan_time,scan_type,status)
            VALUES
            ('$re','$now_full','IN','$es')"
        );
    }

    /*
     * SMS
     */

    $sms_q = false;

    if (
        $sms_enabled &&
        !empty($student['parent_mobile'])
    ) {

        $t = date('h:i A');

        $st =
            $att_status === 'On Time'
            ? 'ON TIME'
            : 'LATE';

        $msg =
            "[{$school_name}] Si {$student['full_name']} ay pumasok na ngayong {$today_day}, {$t}. Status: {$st}.";

        if (
            queue_sms(
                $conn,
                $student['parent_mobile'],
                $msg
            )
        ) {

            $conn->query(
                "UPDATE attendance
                 SET sms_sent_in=1
                 WHERE id=$att_id"
            );

            $sms_q = true;
        }
    }

    respond([
        'status'=>'accepted',
        'scan_type'=>'IN',
        'student'=>sp($student),
        'attendance_status'=>$att_status,
        'scan_time'=>$now_full,
        'sms_queued'=>$sms_q,
        'offline'=>$use_local
    ]);
}

/*
|--------------------------------------------------------------------------
| ACCEPT - TIME OUT
|--------------------------------------------------------------------------
*/

if ($scan_type === 'OUT') {

    $ex = $conn->query(
        "SELECT * FROM attendance
         WHERE student_id=$student_id
         AND DATE(scan_time)='$today'
         LIMIT 1"
    );

    $rec = ($ex && $ex->num_rows > 0)
        ? $ex->fetch_assoc()
        : null;

    if (!$rec)
        respond([
            'status'=>'no_timein',
            'student'=>sp($student),
            'offline'=>$use_local
        ]);

    if (!empty($rec['time_out']))
        respond([
            'status'=>'duplicate',
            'student'=>sp($student),
            'scan_type'=>'OUT',
            'offline'=>$use_local
        ]);

    $att_id = (int)$rec['id'];

    $ok = $conn->query(
        "UPDATE attendance
         SET time_out='$now_full'
         WHERE id=$att_id"
    );

    if (!$ok)
        respond([
            'status'=>'error',
            'message'=>'Failed to save time out.',
            'db_error'=>$conn->error
        ], 500);

    if ($use_local) {

        $re = $conn->real_escape_string($rfid_uid);

        $conn->query(
            "INSERT INTO offline_queue
            (rfid_uid,scan_time,scan_type,status)
            VALUES
            ('$re','$now_full','OUT','On Time')"
        );
    }

    $sms_q = false;

    if (
        $sms_enabled &&
        !empty($student['parent_mobile'])
    ) {

        $t = date('h:i A');

        $msg =
            "[{$school_name}] Si {$student['full_name']} ay umuwi na ngayong {$today_day}, {$t}. Ingatan po niya sarili.";

        if (
            queue_sms(
                $conn,
                $student['parent_mobile'],
                $msg
            )
        ) {

            $conn->query(
                "UPDATE attendance
                 SET sms_sent_out=1
                 WHERE id=$att_id"
            );

            $sms_q = true;
        }
    }

    respond([
        'status'=>'accepted',
        'scan_type'=>'OUT',
        'student'=>sp($student),
        'time_in'=>$rec['scan_time'],
        'time_out'=>$now_full,
        'sms_queued'=>$sms_q,
        'offline'=>$use_local
    ]);
}

function sp($s) {
    return [
        'id'=>$s['id'],
        'full_name'=>$s['full_name'],
        'year_level'=>$s['year_level'] ?? '',
        'course'=>$s['course'] ?? '',
        'section'=>$s['section'] ?? '',
        'photo_path'=>
            'http://192.168.2.103/tracked/' .
            ($s['photo_path'] ?? ''),
        'student_id_no'=>$s['student_id_no'] ?? '',
        'parent_mobile'=>$s['parent_mobile'] ?? ''
    ];
}