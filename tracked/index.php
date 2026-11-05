<?php
session_start();

define('DB_HOST','localhost');
define('DB_USER','root');
define('DB_PASS','');
define('DB_NAME','tracked_db');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) $db_error = "Database not connected. Running in demo mode.";

// ── MASTER DATA ─────────────────────────────────────────
$COURSES     = ['BSIT', 'BSCUSTOM', 'BSCRIM', 'BSHM'];
$YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$DAYS        = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

// ── SUMMARY STATS ────────────────────────────────────────
$today_date = date('Y-m-d');
$today_day  = date('l'); // e.g. "Monday"
$total_students = $present_today = $late_today = $absent_today = 0;

if (!isset($db_error)) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM students WHERE is_active=1");
    if ($r) $total_students = $r->fetch_assoc()['c'];

    $r = $conn->query("SELECT COUNT(*) AS c FROM attendance WHERE DATE(scan_time)='$today_date' AND status='On Time'");
    if ($r) $present_today = $r->fetch_assoc()['c'];

    $r = $conn->query("SELECT COUNT(*) AS c FROM attendance WHERE DATE(scan_time)='$today_date' AND status='Late'");
    if ($r) $late_today = $r->fetch_assoc()['c'];

    $absent_today = max(0, $total_students - $present_today - $late_today);
} else {
    $total_students = 312; $present_today = 215; $late_today = 31; $absent_today = 66;
}







// ── AJAX: GET STUDENTS ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_students') {
    header('Content-Type: application/json');
    $s  = isset($_GET['s'])      ? $conn->real_escape_string(trim($_GET['s']))      : '';
    $fc = isset($_GET['course']) ? $conn->real_escape_string(trim($_GET['course'])) : '';
    $fy = isset($_GET['year'])   ? $conn->real_escape_string(trim($_GET['year']))   : '';
    $fi = isset($_GET['irreg'])  ? (int)$_GET['irreg']                              : -1;

    $w = ["s.is_active=1"];
    if ($s)            $w[] = "(s.full_name LIKE '%$s%' OR s.rfid_uid LIKE '%$s%')";
    if ($fc !== '')    $w[] = "s.course='$fc'";
    if ($fy !== '')    $w[] = "s.year_level='$fy'";
    if ($fi >= 0)      $w[] = "s.is_irregular=$fi";
    $ws = implode(' AND ', $w);
    $out = [];

    if (!isset($db_error)) {
        $res = $conn->query("
            SELECT s.*,
                (SELECT status FROM attendance WHERE student_id=s.id AND DATE(scan_time)='$today_date' ORDER BY scan_time DESC LIMIT 1) AS today_status,
                (SELECT scan_time FROM attendance WHERE student_id=s.id AND DATE(scan_time)='$today_date' ORDER BY scan_time DESC LIMIT 1) AS today_time,
                sch.days_json, sch.ontime_cutoff, sch.late_cutoff
            FROM students s
            LEFT JOIN schedules sch ON sch.course=s.course AND sch.year_level=s.year_level
            WHERE $ws ORDER BY s.full_name");
        if (!$res) {
            echo json_encode([]); exit;
        }
        while ($r = $res->fetch_assoc()) {
            $r['sched_days'] = json_decode($r['days_json'] ?? '[]', true);
            $r['irreg_days'] = json_decode($r['irregular_days_json'] ?? '[]', true);
            $out[] = $r;
        }
    } else {
        // Demo data
        $out = [
            ['id'=>1,'rfid_uid'=>'A1B2C3D4','student_id_no'=>'2021-00001','full_name'=>'Juan dela Cruz',   'course'=>'BSIT',    'year_level'=>'1st Year','section'=>'A','parent_mobile'=>'+639171234567','photo_path'=>'','is_irregular'=>0,'today_status'=>'On Time','today_time'=>date('Y-m-d').' 07:28:00','sched_days'=>['Monday','Tuesday'],             'irreg_days'=>[],'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
            ['id'=>2,'rfid_uid'=>'E5F6G7H8','student_id_no'=>'2021-00002','full_name'=>'Maria Santos',     'course'=>'BSHM',    'year_level'=>'2nd Year','section'=>'B','parent_mobile'=>'+639189876543','photo_path'=>'','is_irregular'=>0,'today_status'=>'Late',    'today_time'=>date('Y-m-d').' 08:10:00','sched_days'=>['Wednesday','Thursday'],          'irreg_days'=>[],'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
            ['id'=>3,'rfid_uid'=>'I9J0K1L2','student_id_no'=>'2020-00015','full_name'=>'Carlos Reyes',     'course'=>'BSIT',    'year_level'=>'3rd Year','section'=>'A','parent_mobile'=>'+639201112233','photo_path'=>'','is_irregular'=>1,'today_status'=>null,      'today_time'=>null,                      'sched_days'=>['Monday','Wednesday','Friday'],   'irreg_days'=>['Monday','Wednesday','Friday'],'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
            ['id'=>4,'rfid_uid'=>'M3N4O5P6','student_id_no'=>'2022-00088','full_name'=>'Ana Gonzales',     'course'=>'BSCRIM',  'year_level'=>'1st Year','section'=>'C','parent_mobile'=>'+639334455667','photo_path'=>'','is_irregular'=>0,'today_status'=>'On Time','today_time'=>date('Y-m-d').' 07:25:00','sched_days'=>['Mon','Tue','Wed','Thu'],         'irreg_days'=>[],'ontime_cutoff'=>'06:00:00','late_cutoff'=>'07:00:00'],
            ['id'=>5,'rfid_uid'=>'Q7R8S9T0','student_id_no'=>'2023-00042','full_name'=>'Paolo Villanueva', 'course'=>'BSCUSTOM','year_level'=>'4th Year','section'=>'A','parent_mobile'=>'+639556677889','photo_path'=>'','is_irregular'=>0,'today_status'=>null,      'today_time'=>null,                      'sched_days'=>['Thursday','Friday'],            'irreg_days'=>[],'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
            ['id'=>6,'rfid_uid'=>'L1M2N3O4','student_id_no'=>'2019-00009','full_name'=>'Rosa Mendoza',     'course'=>'BSHM',    'year_level'=>'3rd Year','section'=>'A','parent_mobile'=>'+639667788990','photo_path'=>'','is_irregular'=>1,'today_status'=>'On Time','today_time'=>date('Y-m-d').' 07:29:00','sched_days'=>['Thursday','Friday'],            'irreg_days'=>['Monday','Tuesday','Thursday'],'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
        ];
    }
    echo json_encode($out); exit;
}

// ── AJAX: GET SCHEDULES ───────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_schedules') {
    header('Content-Type: application/json');
    $out = [];
    if (!isset($db_error)) {
        $res = $conn->query("SELECT * FROM schedules ORDER BY course, year_level");
        while ($r = $res->fetch_assoc()) {
            $r['days'] = json_decode($r['days_json'] ?? '[]', true);
            $out[] = $r;
        }
    } else {
        $out = [
            ['course'=>'BSIT',    'year_level'=>'1st Year','days'=>['Monday','Tuesday'],                      'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
            ['course'=>'BSIT',    'year_level'=>'2nd Year','days'=>['Wednesday','Thursday'],                   'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
            ['course'=>'BSIT',    'year_level'=>'3rd Year','days'=>['Monday','Wednesday','Friday'],            'ontime_cutoff'=>'07:00:00','late_cutoff'=>'08:00:00'],
            ['course'=>'BSIT',    'year_level'=>'4th Year','days'=>['Tuesday','Thursday'],                     'ontime_cutoff'=>'07:00:00','late_cutoff'=>'08:00:00'],
            ['course'=>'BSHM',    'year_level'=>'1st Year','days'=>['Monday','Tuesday','Wednesday'],           'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
            ['course'=>'BSHM',    'year_level'=>'2nd Year','days'=>['Thursday','Friday'],                      'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
            ['course'=>'BSCRIM',  'year_level'=>'1st Year','days'=>['Monday','Tuesday','Wednesday','Thursday'],'ontime_cutoff'=>'06:00:00','late_cutoff'=>'07:00:00'],
            ['course'=>'BSCUSTOM','year_level'=>'1st Year','days'=>['Monday','Wednesday'],                     'ontime_cutoff'=>'07:30:00','late_cutoff'=>'08:30:00'],
        ];
    }
    echo json_encode($out); exit;
}

// ── AJAX: SAVE SCHEDULE ───────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'save_schedule') {
    header('Content-Type: application/json');
    $c    = $conn->real_escape_string($_POST['course']         ?? '');
    $y    = $conn->real_escape_string($_POST['year_level']     ?? '');
    $days = json_encode($_POST['days'] ?? []);
    $ot   = $conn->real_escape_string($_POST['ontime_cutoff']  ?? '07:30:00');
    $lt   = $conn->real_escape_string($_POST['late_cutoff']    ?? '08:30:00');
    $tout = $conn->real_escape_string($_POST['timeout_cutoff'] ?? '15:00:00');
    $dj   = $conn->real_escape_string($days);

    if (!isset($db_error)) {
        // Check if schedule already exists
        $exists = $conn->query("SELECT id FROM schedules WHERE course='$c' AND year_level='$y' LIMIT 1");
        if ($exists && $exists->num_rows > 0) {
            // UPDATE existing row
            $row = $exists->fetch_assoc();
            $id  = (int)$row['id'];
            $ok  = $conn->query("UPDATE schedules SET days_json='$dj', ontime_cutoff='$ot', late_cutoff='$lt', timeout_cutoff='$tout' WHERE id=$id");
        } else {
            // INSERT new row
            $ok = $conn->query("INSERT INTO schedules (course,year_level,days_json,ontime_cutoff,late_cutoff,timeout_cutoff)
                VALUES ('$c','$y','$dj','$ot','$lt','$tout')");
        }
        if ($ok) echo json_encode(['status'=>'success','message'=>"Schedule saved for $c $y."]);
        else     echo json_encode(['status'=>'error','message'=>$conn->error]);
    } else {
        echo json_encode(['status'=>'info','message'=>"Demo mode — $c $y schedule simulated."]);
    }
    exit;
}

// ── AJAX: REGISTER STUDENT ────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'register') {
    header('Content-Type: application/json');
    $rfid    = $conn->real_escape_string(trim($_POST['rfid_uid']       ?? ''));
    $idno    = $conn->real_escape_string(trim($_POST['student_id_no']  ?? ''));
    $name    = $conn->real_escape_string(trim($_POST['full_name']      ?? ''));
    $course  = $conn->real_escape_string($_POST['course']              ?? '');
    $year    = $conn->real_escape_string($_POST['year_level']          ?? '');
    $sec     = $conn->real_escape_string(trim($_POST['section']        ?? ''));
    $mob     = $conn->real_escape_string(trim($_POST['parent_mobile']  ?? ''));
    $irreg   = isset($_POST['is_irregular']) ? 1 : 0;
    $idays   = $conn->real_escape_string(json_encode($_POST['irreg_days'] ?? []));
    $photo   = '';

    if (!isset($db_error)) {
        if (!empty($_FILES['student_photo']['name'])) {
            $dir = 'uploads/students/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['student_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fn = uniqid('s_') . ".$ext";
                if (move_uploaded_file($_FILES['student_photo']['tmp_name'], $dir.$fn))
                    $photo = $dir.$fn;
            }
        }
        $chk = $conn->query("SELECT id FROM students WHERE rfid_uid='$rfid'");
        if ($chk && $chk->num_rows > 0) {
            echo json_encode(['status'=>'error','message'=>'RFID already registered.']); exit;
        }
        if ($conn->query("INSERT INTO students (rfid_uid,student_id_no,full_name,course,year_level,section,parent_mobile,photo_path,is_irregular,irregular_days_json,is_active)
                VALUES ('$rfid','$idno','$name','$course','$year','$sec','$mob','$photo',$irreg,'$idays',1)"))
            echo json_encode(['status'=>'success','message'=>"$name registered successfully!"]);
        else
            echo json_encode(['status'=>'error','message'=>$conn->error]);
    } else {
        echo json_encode(['status'=>'info','message'=>"Demo: $name would be registered."]);
    }
    exit;
}

// ── AJAX: UPDATE STUDENT ──────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'update_student') {
    header('Content-Type: application/json');
    $id      = intval($_POST['student_id']     ?? 0);
    $idno    = $conn->real_escape_string(trim($_POST['student_id_no']  ?? ''));
    $name    = $conn->real_escape_string(trim($_POST['full_name']      ?? ''));
    $course  = $conn->real_escape_string($_POST['course']              ?? '');
    $year    = $conn->real_escape_string($_POST['year_level']          ?? '');
    $sec     = $conn->real_escape_string(trim($_POST['section']        ?? ''));
    $mob     = $conn->real_escape_string(trim($_POST['parent_mobile']  ?? ''));
    $irreg   = isset($_POST['is_irregular']) && $_POST['is_irregular'] === '1' ? 1 : 0;
    $idays   = $conn->real_escape_string(json_encode($_POST['irreg_days'] ?? []));

    if (!isset($db_error) && $id) {
        $ok = $conn->query("UPDATE students SET
            student_id_no='$idno', full_name='$name', course='$course',
            year_level='$year', section='$sec', parent_mobile='$mob',
            is_irregular=$irreg, irregular_days_json='$idays'
            WHERE id=$id");
        if ($ok) echo json_encode(['status'=>'success','message'=>"$name updated successfully."]);
        else     echo json_encode(['status'=>'error','message'=>$conn->error]);
    } else {
        echo json_encode(['status'=>'info','message'=>"Demo mode — changes simulated for record $id."]);
    }
    exit;
}


if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    $id = intval($_POST['student_id'] ?? 0);
    if (!isset($db_error) && $id)
        $conn->query("UPDATE students SET is_active=0 WHERE id=$id");
    echo json_encode(['status'=>'success']); exit;
}

// ── AJAX: LIVE SCANS ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_live_scans') {
    header('Content-Type: application/json');
    $out = [];
    if (!isset($db_error)) {
        $res = $conn->query("SELECT a.id,s.rfid_uid,s.student_id_no,s.full_name,s.course,s.year_level,s.section,s.photo_path,s.is_irregular,a.scan_time,a.status
            FROM attendance a JOIN students s ON a.student_id=s.id
            ORDER BY a.scan_time DESC LIMIT 15");
        while ($r = $res->fetch_assoc()) $out[] = $r;
    } else {
        $d = [
            ['Juan dela Cruz',   'BSIT',    '1st Year','A',0,'On Time'],
            ['Maria Santos',     'BSHM',    '2nd Year','B',0,'Late'],
            ['Carlos Reyes',     'BSIT',    '3rd Year','A',1,'On Time'],
            ['Ana Gonzales',     'BSCRIM',  '1st Year','C',0,'On Time'],
            ['Rosa Mendoza',     'BSHM',    '3rd Year','A',1,'Late'],
        ];
        $t = time();
        foreach ($d as $i => $n)
            $out[] = ['id'=>$i+1,'rfid_uid'=>strtoupper(substr(md5($n[0]),0,8)),'student_id_no'=>'2021-0000'.($i+1),'full_name'=>$n[0],'course'=>$n[1],'year_level'=>$n[2],'section'=>$n[3],'photo_path'=>'','is_irregular'=>$n[4],'scan_time'=>date('Y-m-d H:i:s',$t-$i*300),'status'=>$n[5]];
    }
    echo json_encode($out); exit;
}

// ── AJAX: ATTENDANCE REPORT (with filters + time_out + absent) ────
if (isset($_GET['action']) && $_GET['action'] === 'get_report') {
    header('Content-Type: application/json');
    $df     = $conn->real_escape_string($_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days')));
    $dt     = $conn->real_escape_string($_GET['date_to']   ?? date('Y-m-d'));
    $fc     = isset($_GET['course']) ? $conn->real_escape_string(trim($_GET['course'])) : '';
    $fy     = isset($_GET['year'])   ? $conn->real_escape_string(trim($_GET['year']))   : '';
    $fst    = isset($_GET['status']) ? $conn->real_escape_string(trim($_GET['status'])) : '';
    $fi     = isset($_GET['irreg'])  ? trim($_GET['irreg'])                              : '';

    $out = [];
    if (!isset($db_error)) {

        // ── Scanned students (On Time / Late) ────────────────
        if ($fst !== 'Absent') {
            $w = ["DATE(a.scan_time) BETWEEN '$df' AND '$dt'"];
            if ($fc !== '')     $w[] = "s.course='$fc'";
            if ($fy !== '')     $w[] = "s.year_level='$fy'";
            if ($fst !== '')    $w[] = "a.status='$fst'";
            if ($fi === '0' || $fi === '1') $w[] = "s.is_irregular=" . (int)$fi;
            $ws = implode(' AND ', $w);

            $res = $conn->query("
                SELECT a.id, a.scan_time, a.time_out, a.status, a.scan_type,
                       s.rfid_uid, s.student_id_no, s.full_name,
                       s.course, s.year_level, s.section, s.is_irregular
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE $ws
                ORDER BY a.scan_time DESC
                LIMIT 500");
            if ($res) while ($r = $res->fetch_assoc()) $out[] = $r;
        }

        // ── Absent students (no scan in date range) ───────────
        if ($fst === '' || $fst === 'Absent') {
            $sw = ["s.is_active=1"];
            if ($fc !== '')     $sw[] = "s.course='$fc'";
            if ($fy !== '')     $sw[] = "s.year_level='$fy'";
            if ($fi === '0' || $fi === '1') $sw[] = "s.is_irregular=" . (int)$fi;
            $sws = implode(' AND ', $sw);

            $absent_res = $conn->query("
                SELECT s.id, s.rfid_uid, s.student_id_no, s.full_name,
                       s.course, s.year_level, s.section, s.is_irregular
                FROM students s
                WHERE $sws
                  AND s.id NOT IN (
                      SELECT DISTINCT student_id FROM attendance
                      WHERE DATE(scan_time) BETWEEN '$df' AND '$dt'
                  )
                ORDER BY s.full_name
                LIMIT 500");
            if ($absent_res) {
                while ($r = $absent_res->fetch_assoc()) {
                    $r['scan_time'] = null;
                    $r['time_out']  = null;
                    $r['status']    = 'Absent';
                    $r['scan_type'] = null;
                    $r['id']        = 'abs_' . $r['id'];
                    $out[] = $r;
                }
            }
        }

    } else {
        // Demo data
        $out = [
            ['id'=>1,'rfid_uid'=>'A1B2C3D4','student_id_no'=>'2021-00001','full_name'=>'Juan dela Cruz','course'=>'BSIT','year_level'=>'1st Year','section'=>'A','is_irregular'=>0,'scan_time'=>date('Y-m-d').' 07:28:00','time_out'=>date('Y-m-d').' 16:05:00','status'=>'On Time','scan_type'=>'IN'],
            ['id'=>2,'rfid_uid'=>'E5F6G7H8','student_id_no'=>'2021-00002','full_name'=>'Maria Santos',  'course'=>'BSHM','year_level'=>'2nd Year','section'=>'B','is_irregular'=>0,'scan_time'=>date('Y-m-d').' 08:10:00','time_out'=>null,                        'status'=>'Late',    'scan_type'=>'IN'],
            ['id'=>3,'rfid_uid'=>'I9J0K1L2','student_id_no'=>'2020-00015','full_name'=>'Carlos Reyes',  'course'=>'BSIT','year_level'=>'3rd Year','section'=>'A','is_irregular'=>1,'scan_time'=>date('Y-m-d').' 07:29:00','time_out'=>date('Y-m-d').' 16:30:00','status'=>'On Time','scan_type'=>'IN'],
            ['id'=>'abs_4','rfid_uid'=>'M3N4O5P6','student_id_no'=>'2022-00088','full_name'=>'Ana Gonzales','course'=>'BSCRIM','year_level'=>'1st Year','section'=>'C','is_irregular'=>0,'scan_time'=>null,'time_out'=>null,'status'=>'Absent','scan_type'=>null],
            ['id'=>'abs_5','rfid_uid'=>'Q7R8S9T0','student_id_no'=>'2023-00042','full_name'=>'Paolo Villanueva','course'=>'BSCUSTOM','year_level'=>'4th Year','section'=>'A','is_irregular'=>0,'scan_time'=>null,'time_out'=>null,'status'=>'Absent','scan_type'=>null],
        ];
        if ($fst !== '') $out = array_values(array_filter($out, fn($r) => $r['status'] === $fst));
        if ($fc  !== '') $out = array_values(array_filter($out, fn($r) => $r['course'] === $fc));
        if ($fy  !== '') $out = array_values(array_filter($out, fn($r) => $r['year_level'] === $fy));
    }
    echo json_encode(array_values($out)); exit;
}

// ── AJAX: GET SMS QUEUE STATUS ────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_sms_status') {
    header('Content-Type: application/json');
    $out = ['pending'=>0,'sent'=>0,'failed'=>0,'recent'=>[]];
    if (!isset($db_error)) {
        $res = $conn->query("SELECT status, COUNT(*) as cnt FROM sms_queue GROUP BY status");
        while ($r = $res->fetch_assoc()) $out[$r['status']] = (int)$r['cnt'];
        $res = $conn->query("SELECT mobile, LEFT(message,60) as msg, status, created_at FROM sms_queue ORDER BY created_at DESC LIMIT 10");
        while ($r = $res->fetch_assoc()) $out['recent'][] = $r;
    }
    echo json_encode($out); exit;
}

// ── AJAX: SAVE SYSTEM CONFIG ──────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'save_config') {
    header('Content-Type: application/json');
    $fields = ['time_in_start','time_in_end','time_out_start','time_out_end','school_name','sms_enabled'];
    if (!isset($db_error)) {
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $k = $conn->real_escape_string($key);
                $v = $conn->real_escape_string($_POST[$key]);
                $conn->query("INSERT INTO system_config (config_key,config_value) VALUES ('$k','$v') ON DUPLICATE KEY UPDATE config_value='$v'");
            }
        }
        echo json_encode(['status'=>'success','message'=>'Configuration saved.']);
    } else {
        echo json_encode(['status'=>'info','message'=>'Demo mode.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TrackED — Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <!-- Phosphor Icons (CDN) -->
    <script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js" defer></script>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="ph-fill ph-fingerprint"></i></div>
        <div class="brand-text">
            <span class="brand-name">TrackED</span>
            <span class="brand-sub">Attendance System v2</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <p class="nav-label">MAIN MENU</p>
        <a href="#" class="nav-item active" data-section="dashboard"><i class="ph-fill ph-squares-four"></i><span>Dashboard</span><div class="nav-indicator"></div></a>
        <a href="#" class="nav-item" data-section="registration"><i class="ph-fill ph-user-plus"></i><span>Student Registration</span><div class="nav-indicator"></div></a>
        <a href="#" class="nav-item" data-section="records"><i class="ph-fill ph-users-three"></i><span>Student Records</span><div class="nav-indicator"></div></a>
        <a href="#" class="nav-item" data-section="reports"><i class="ph-fill ph-chart-bar"></i><span>Attendance Reports</span><div class="nav-indicator"></div></a>
        <p class="nav-label" style="margin-top:2rem">CONFIGURATION</p>
        <a href="#" class="nav-item" data-section="schedules"><i class="ph-fill ph-calendar-check"></i><span>Schedule Manager</span><div class="nav-indicator"></div></a>
        <a href="#" class="nav-item" data-section="settings"><i class="ph-fill ph-gear-six"></i><span>Settings</span><div class="nav-indicator"></div></a>
    </nav>
    <div class="sidebar-footer">
        <div class="connection-status"><span class="status-dot" id="status-dot"></span><span id="status-text">Checking...</span></div>
        <p class="footer-credit">TrackED v2.0 — LAN Mode</p>
    </div>
</aside>

<div class="main-wrapper">
<header class="topbar">
    <div class="topbar-left">
        <button class="menu-toggle" id="menu-toggle"><i class="ph ph-list"></i></button>
        <div class="page-breadcrumb"><span id="page-title">Dashboard</span></div>
    </div>
    <div class="topbar-right">
        <div class="topbar-datetime"><i class="ph ph-calendar-blank"></i><span id="topbar-date"><?= date('l, F j, Y') ?></span></div>
        <div class="topbar-clock"><i class="ph ph-clock"></i><span id="topbar-clock"></span></div>
        <div class="topbar-divider"></div>
        <div class="admin-profile">
            <div class="admin-avatar"><img src="logo.jpg" alt="Admin Avatar"></div>
            <div class="admin-info"><span class="admin-name">Registrar Admin</span><span class="admin-role">System Administrator</span></div>
        </div>
    </div>
</header>
<main class="content-area">

<!-- ══ DASHBOARD ══════════════════════════════════════════ -->
<section class="section active" id="section-dashboard">
    <div class="welcome-banner">
        <div class="welcome-text">
            <h1>Good <?= (date('H')<12)?'Morning':((date('H')<18)?'Afternoon':'Evening') ?>, Admin 👋</h1>
            <p>Today is <strong><?= date('l') ?></strong> — monitoring active schedules.</p>
        </div>
        <div class="welcome-date-badge"><i class="ph-fill ph-calendar-check"></i><span><?= date('M j, Y') ?></span></div>
    </div>

    <div class="cards-grid">
        <div class="stat-card total">
            <div class="card-icon-wrap"><i class="ph-fill ph-student"></i></div>
            <div class="card-body"><p class="card-label">Total Students</p><h2 class="card-value" id="stat-total"><?= $total_students ?></h2><p class="card-sub">Enrolled &amp; active</p></div>
        </div>
        <div class="stat-card present">
            <div class="card-icon-wrap"><i class="ph-fill ph-check-circle"></i></div>
            <div class="card-body"><p class="card-label">Present Today</p><h2 class="card-value" id="stat-present"><?= $present_today ?></h2><p class="card-sub">On-time arrivals</p></div>
        </div>
        <div class="stat-card late">
            <div class="card-icon-wrap"><i class="ph-fill ph-clock-countdown"></i></div>
            <div class="card-body"><p class="card-label">Late Arrivals</p><h2 class="card-value" id="stat-late"><?= $late_today ?></h2><p class="card-sub">After cutoff time</p></div>
        </div>
        <div class="stat-card absent">
            <div class="card-icon-wrap"><i class="ph-fill ph-x-circle"></i></div>
            <div class="card-body"><p class="card-label">Absentees</p><h2 class="card-value" id="stat-absent"><?= $absent_today ?></h2><p class="card-sub">No scan recorded</p></div>
        </div>
    </div>

    <!-- Today's Active Schedules -->
    <div class="panel" style="margin-bottom:1.5rem">
        <div class="panel-header">
            <div class="panel-title-group">
                <i class="ph-fill ph-calendar-dots" style="color:var(--primary);font-size:1.2rem;"></i>
                <h3 class="panel-title">Today's Active Schedules</h3>
                <span class="day-badge" id="today-day-badge"><?= date('l') ?></span>
            </div>
        </div>
        <div id="today-schedules-list" class="today-schedules-list">
            <div class="loader-row"><div class="loader-dots"><span></span><span></span><span></span></div> Loading schedules...</div>
        </div>
    </div>

    <!-- Live Scan Feed -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title-group">
                <div class="live-pulse"><span class="pulse-ring"></span><span class="pulse-dot"></span></div>
                <h3 class="panel-title">Live Scan Feed</h3>
                <span class="refresh-badge" id="last-refresh">Fetching...</span>
            </div>
            <div class="panel-actions">
                <button class="btn-icon" id="refresh-btn"><i class="ph ph-arrows-clockwise"></i></button>
                <label class="toggle-label"><input type="checkbox" id="auto-refresh-toggle" checked><span class="toggle-switch"></span><span>Auto</span></label>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>#</th><th>Student</th><th>Course / Year</th><th>Section</th><th>Scan Time</th><th>Status</th><th>Type</th></tr></thead>
                <tbody id="live-tbody"><tr><td colspan="7" class="table-loading"><div class="loader-dots"><span></span><span></span><span></span></div> Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ══ REGISTRATION ════════════════════════════════════════ -->
<section class="section" id="section-registration">
    <div class="section-header">
        <h2>Student Registration</h2>
        <p>Register a new student. Schedule is auto-applied from Schedule Manager based on course and year level.</p>
    </div>
    <div class="reg-layout">
        <div class="panel reg-form-panel">
            <div class="panel-header">
                <div class="panel-title-group"><i class="ph-fill ph-user-plus" style="color:var(--primary);font-size:1.2rem;"></i><h3 class="panel-title">New Student Form</h3></div>
            </div>
            <form id="reg-form" class="reg-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="register">

                <!-- RFID -->
                <div class="form-group rfid-group" style="margin-bottom:1rem;">
                    <label><i class="ph ph-wifi-high"></i> RFID Card UID <span class="field-required">*</span></label>
                    <div class="rfid-input-wrap">
                        <input type="text" id="rfid_uid" name="rfid_uid" class="form-input rfid-input"
                               placeholder="Tap RFID card near scanner..." autofocus autocomplete="off" required>
                        <div class="rfid-scan-indicator" id="rfid-indicator"><i class="ph ph-wifi-high"></i></div>
                    </div>
                    <p class="field-hint">The UID will auto-fill once the card is scanned by the Orange Pi.</p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="ph ph-identification-card"></i> Student ID No. <span class="field-required">*</span></label>
                        <input type="text" name="student_id_no" class="form-input" placeholder="e.g. 2024-00001" required>
                    </div>
                    <div class="form-group">
                        <label><i class="ph ph-user"></i> Full Name <span class="field-required">*</span></label>
                        <input type="text" name="full_name" class="form-input" placeholder="Last Name, First Name M.I." required>
                    </div>
                </div>

                <div class="form-row">
                    <!-- Course Dropdown -->
                    <div class="form-group">
                        <label><i class="ph ph-book-open"></i> Course <span class="field-required">*</span></label>
                        <select name="course" id="reg-course" class="form-input form-select" required>
                            <option value="" disabled selected>Select Course</option>
                            <?php foreach($COURSES as $c): ?>
                            <option value="<?= $c ?>"><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Year Level Dropdown -->
                    <div class="form-group">
                        <label><i class="ph ph-graduation-cap"></i> Year Level <span class="field-required">*</span></label>
                        <select name="year_level" id="reg-year" class="form-input form-select" required>
                            <option value="" disabled selected>Select Year Level</option>
                            <?php foreach($YEAR_LEVELS as $y): ?>
                            <option value="<?= $y ?>"><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="ph ph-chalkboard-teacher"></i> Section</label>
                        <input type="text" name="section" class="form-input" placeholder="e.g. A, B, C">
                    </div>
                    <div class="form-group">
                        <label><i class="ph ph-phone"></i> Parent's Mobile <span class="field-required">*</span></label>
                        <input type="tel" name="parent_mobile" id="parent_mobile" class="form-input" placeholder="+639XXXXXXXXX" required>
                    </div>
                </div>

                <!-- Schedule Preview (auto-loads on course + year change) -->
                <div class="schedule-preview-box" id="schedule-preview-box" style="display:none;">
                    <div class="sched-preview-header"><i class="ph-fill ph-calendar-check"></i><span>Auto-Applied Schedule</span></div>
                    <div id="sched-preview-content"></div>
                </div>

                <!-- Irregular Toggle -->
                <div class="irregular-toggle-group">
                    <label class="irreg-toggle-label">
                        <input type="checkbox" id="is_irregular_chk" name="is_irregular">
                        <span class="irreg-toggle-switch"></span>
                        <span class="irreg-toggle-text">
                            <strong>Irregular Student</strong>
                            <small>Student has a custom schedule different from the course default</small>
                        </span>
                    </label>
                </div>

                <!-- Irregular Custom Schedule (shows when toggle ON) -->
                <div class="irregular-schedule-box" id="irregular-schedule-box" style="display:none;">
                    <div class="irreg-box-header"><i class="ph-fill ph-calendar-x" style="color:var(--warning);"></i><span>Custom Schedule for Irregular Student</span></div>
                    <p class="irreg-box-hint">Select which days this student attends class:</p>
                    <div class="day-picker" id="irreg-day-picker">
                        <?php foreach($DAYS as $d): ?>
                        <label class="day-pill">
                            <input type="checkbox" name="irreg_days[]" value="<?= $d ?>">
                            <span><?= substr($d,0,3) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-row" style="margin-top:1rem;">
                        <div class="form-group">
                            <label><i class="ph ph-clock"></i> On-Time Cutoff</label>
                            <input type="time" name="irreg_ontime" class="form-input" value="07:30">
                            <p class="field-hint">Before this = On Time</p>
                        </div>
                        <div class="form-group">
                            <label><i class="ph ph-clock-countdown"></i> Late Cutoff</label>
                            <input type="time" name="irreg_late" class="form-input" value="08:30">
                            <p class="field-hint">Between On-Time &amp; this = Late</p>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" id="clear-form-btn"><i class="ph ph-eraser"></i> Clear</button>
                    <button type="submit" class="btn btn-primary" id="reg-submit-btn"><i class="ph ph-user-plus"></i> Register Student</button>
                </div>
            </form>
        </div>

        <!-- Photo + History Column -->
        <div class="photo-column">
            <div class="panel photo-panel">
                <div class="panel-header"><div class="panel-title-group"><i class="ph-fill ph-camera" style="color:var(--primary);font-size:1.2rem;"></i><h3 class="panel-title">Student Photo</h3></div></div>
                <div class="photo-upload-area" id="photo-drop-zone">
                    <div class="photo-preview-circle" id="photo-preview-circle">
                        <img id="photo-preview-img" src="" alt="" style="display:none;">
                        <div class="photo-placeholder" id="photo-placeholder"><i class="ph ph-user-circle"></i></div>
                        <div class="photo-overlay"><i class="ph ph-camera"></i></div>
                    </div>
                    <p class="upload-hint-main">Upload Photo</p>
                    <p class="upload-hint-sub">Drag &amp; drop or click below</p>
                    <p class="upload-hint-sub">JPG, PNG · Max 2MB</p>
                    <label class="btn btn-ghost btn-sm upload-label" for="student_photo"><i class="ph ph-upload-simple"></i> Choose Photo</label>
                    <input type="file" id="student_photo" name="student_photo" accept="image/*" hidden form="reg-form">
                </div>
            </div>
            <div class="panel">
                <div class="panel-header"><div class="panel-title-group"><i class="ph-fill ph-clock-clockwise" style="color:var(--primary);font-size:1.1rem;"></i><h3 class="panel-title">Today's Registrations</h3></div></div>
                <div style="padding:.75rem 1rem 1rem;" id="reg-history-list"><p class="empty-history">No registrations yet today.</p></div>
            </div>
        </div>
    </div>
</section>

<!-- ══ STUDENT RECORDS ═════════════════════════════════════ -->
<section class="section" id="section-records">
    <div class="section-header"><h2>Student Records</h2><p>Manage all enrolled students. Filter by course, year level, or student type.</p></div>
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title-group">
                <i class="ph-fill ph-users-three" style="color:var(--primary);font-size:1.2rem;"></i>
                <h3 class="panel-title">All Students</h3>
                <span class="count-badge" id="records-count">Loading...</span>
            </div>
            <div class="panel-actions" style="flex-wrap:wrap;gap:.6rem;">
                <div class="search-box"><i class="ph ph-magnifying-glass"></i><input type="text" id="records-search" placeholder="Search name / ID / RFID..." class="search-input"></div>
                <select id="filter-course" class="filter-select">
                    <option value="">All Courses</option>
                    <?php foreach($COURSES as $c): ?><option><?= $c ?></option><?php endforeach; ?>
                </select>
                <select id="filter-year" class="filter-select">
                    <option value="">All Years</option>
                    <?php foreach($YEAR_LEVELS as $y): ?><option><?= $y ?></option><?php endforeach; ?>
                </select>
                <select id="filter-irregular" class="filter-select">
                    <option value="-1">All Types</option>
                    <option value="0">Regular</option>
                    <option value="1">Irregular</option>
                </select>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Photo</th><th>Student</th><th>Course / Year</th><th>Schedule</th><th>Type</th><th>Today</th><th>Actions</th></tr></thead>
                <tbody id="records-tbody"><tr><td colspan="7" class="table-loading"><div class="loader-dots"><span></span><span></span><span></span></div> Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ══ REPORTS ═════════════════════════════════════════════ -->
<section class="section" id="section-reports">
    <div class="section-header"><h2>Attendance Reports</h2><p>Filter and export attendance logs by date, course, year level, and student type.</p></div>
    <div class="panel filters-panel">
        <div class="filters-row">
            <div class="filter-group"><label><i class="ph ph-calendar-blank"></i> Date From</label><input type="date" id="report-from" class="form-input" value="<?= date('Y-m-d',strtotime('-7 days')) ?>"></div>
            <div class="filter-group"><label><i class="ph ph-calendar-check"></i> Date To</label><input type="date" id="report-to" class="form-input" value="<?= date('Y-m-d') ?>"></div>
            <div class="filter-group"><label><i class="ph ph-book-open"></i> Course</label>
                <select id="report-course" class="form-input form-select">
                    <option value="">All Courses</option>
                    <?php foreach($COURSES as $c): ?><option><?= $c ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group"><label><i class="ph ph-graduation-cap"></i> Year</label>
                <select id="report-year" class="form-input form-select">
                    <option value="">All Years</option>
                    <?php foreach($YEAR_LEVELS as $y): ?><option><?= $y ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group"><label><i class="ph ph-funnel"></i> Status</label>
                <select id="report-status" class="form-input form-select"><option value="">All</option><option>On Time</option><option>Late</option><option>Absent</option></select>
            </div>
            <div class="filter-group"><label><i class="ph ph-shuffle"></i> Type</label>
                <select id="report-irreg" class="form-input form-select"><option value="">All Types</option><option value="0">Regular</option><option value="1">Irregular</option></select>
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary" id="generate-report-btn"><i class="ph ph-magnifying-glass"></i> Generate</button>
                <button class="btn btn-ghost" id="export-csv-btn"><i class="ph ph-file-xls"></i> Export Excel</button>
            </div>
        </div>
    </div>
    <div class="report-summary-row" id="report-summary-row" style="display:none;">
        <div class="summary-chip total-chip"><i class="ph-fill ph-list-numbers"></i><span>Total: <strong id="rpt-total">0</strong></span></div>
        <div class="summary-chip ontime-chip"><i class="ph-fill ph-check-circle"></i><span>On Time: <strong id="rpt-ontime">0</strong></span></div>
        <div class="summary-chip late-chip"><i class="ph-fill ph-clock-countdown"></i><span>Late: <strong id="rpt-late">0</strong></span></div>
        <div class="summary-chip" style="background:var(--danger-light,#fee2e2);color:var(--danger,#dc2626);border:1px solid var(--danger,#dc2626);border-radius:8px;padding:.4rem .9rem;display:flex;align-items:center;gap:.4rem;font-size:.82rem;"><i class="ph-fill ph-x-circle"></i><span>Absent: <strong id="rpt-absent">0</strong></span></div>
        <div class="summary-chip irreg-chip"><i class="ph-fill ph-shuffle"></i><span>Irregular: <strong id="rpt-irreg-count">0</strong></span></div>
    </div>
    <div class="panel">
        <div class="panel-header"><div class="panel-title-group"><i class="ph-fill ph-table" style="color:var(--primary);font-size:1.2rem;"></i><h3 class="panel-title">Report Results</h3><span class="count-badge" id="report-count">0 records</span></div></div>
        <div class="table-wrapper">
            <table class="data-table">
               <thead><tr><th>#</th><th>Student</th><th>Course / Year</th><th>Date</th><th>Time-IN</th><th>Time-OUT</th><th>Status</th><th>Type</th></tr></thead>
                <tbody id="report-tbody"><tr><td colspan="7" class="table-empty"><i class="ph ph-chart-bar" style="font-size:2rem;opacity:.3;"></i><p>Select filters and click Generate.</p></td></tr></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ══ SCHEDULE MANAGER ════════════════════════════════════ -->
<section class="section" id="section-schedules">
    <div class="section-header">
        <h2>Schedule Manager</h2>
        <p>Set class days and cutoff times per course and year level. Irregular students override these at registration.</p>
    </div>

    <div class="sched-layout">
        <!-- Editor -->
        <div class="panel">
            <div class="panel-header"><div class="panel-title-group"><i class="ph-fill ph-pencil-simple" style="color:var(--primary);font-size:1.2rem;"></i><h3 class="panel-title">Create / Edit Schedule</h3></div></div>
            <form id="sched-form" class="sched-form">
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="ph ph-book-open"></i> Course <span class="field-required">*</span></label>
                        <select name="course" id="sched-course" class="form-input form-select" required>
                            <option value="" disabled selected>Select Course</option>
                            <?php foreach($COURSES as $c): ?><option><?= $c ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="ph ph-graduation-cap"></i> Year Level <span class="field-required">*</span></label>
                        <select name="year_level" id="sched-year" class="form-input form-select" required>
                            <option value="" disabled selected>Select Year Level</option>
                            <?php foreach($YEAR_LEVELS as $y): ?><option><?= $y ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:1rem;">
                    <label style="margin-bottom:.6rem;"><i class="ph ph-calendar-dots"></i> Class Days <span class="field-required">*</span></label>
                    <div class="day-picker" id="sched-day-picker">
                        <?php foreach($DAYS as $d): ?>
                        <label class="day-pill">
                            <input type="checkbox" name="days[]" value="<?= $d ?>">
                            <span><?= substr($d,0,3) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="field-hint" style="margin-top:.5rem;">Only scans on selected days will count as present.</p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="ph ph-clock"></i> On-Time Cutoff</label>
                        <input type="time" name="ontime_cutoff" id="sched-ontime" class="form-input" value="07:30">
                        <p class="field-hint">Before this = On Time</p>
                    </div>
                    <div class="form-group">
                        <label><i class="ph ph-clock-countdown"></i> Late Cutoff</label>
                        <input type="time" name="late_cutoff" id="sched-late" class="form-input" value="08:30">
                        <p class="field-hint">Between On-Time &amp; this = Late</p>
                    </div>
                    <div class="form-group">
                        <label><i class="ph ph-moon"></i> Time-OUT Start</label>
                        <input type="time" name="timeout_cutoff" id="sched-timeout" class="form-input" value="15:00">
                        <p class="field-hint">When OUT scans are accepted</p>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" id="sched-clear-btn"><i class="ph ph-eraser"></i> Clear</button>
                    <button type="submit" class="btn btn-primary" id="sched-save-btn"><i class="ph ph-floppy-disk"></i> Save Schedule</button>
                </div>
            </form>
        </div>

        <!-- Schedule List -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title-group"><i class="ph-fill ph-list-checks" style="color:var(--primary);font-size:1.2rem;"></i><h3 class="panel-title">All Course Schedules</h3></div>
                <button class="btn-icon" id="reload-schedules-btn" title="Reload"><i class="ph ph-arrows-clockwise"></i></button>
            </div>
            <div id="schedules-list-container" style="padding:.5rem;">
                <div class="loader-row"><div class="loader-dots"><span></span><span></span><span></span></div> Loading...</div>
            </div>
        </div>
    </div>

    <!-- Irregular Students Table -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title-group">
                <i class="ph-fill ph-shuffle" style="color:var(--warning);font-size:1.2rem;"></i>
                <h3 class="panel-title">Irregular Students</h3>
                <span class="count-badge" id="irreg-count">0</span>
            </div>
            <span style="font-size:.78rem;color:var(--text-muted);margin-left:auto;">Custom schedules override course defaults</span>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Course / Year</th><th>Custom Class Days</th><th>On-Time</th><th>Late Cutoff</th><th>Action</th></tr></thead>
                <tbody id="irreg-tbody"><tr><td colspan="6" class="table-loading"><div class="loader-dots"><span></span><span></span><span></span></div> Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ══ SETTINGS ════════════════════════════════════════════ -->
<section class="section" id="section-settings">
    <div class="section-header">
        <h2>System Settings</h2>
        <p>Configure attendance windows, SMS notifications, and system parameters.</p>
    </div>

    <!-- Time Windows -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title-group">
                <i class="ph-fill ph-clock" style="color:var(--primary);font-size:1.2rem;"></i>
                <h3 class="panel-title">Attendance Time Windows</h3>
            </div>
        </div>
        <div class="settings-form">
            <div class="settings-info-box">
                <i class="ph-fill ph-info"></i>
                <span>The scanner auto-detects <strong>TIME-IN</strong> or <strong>TIME-OUT</strong> based on the current time. 1 IN + 1 OUT per student per day only.</span>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><i class="ph ph-sun"></i> Time-IN Window Start</label>
                    <input type="time" name="time_in_start" class="form-input config-input" value="06:00">
                    <p class="field-hint">Scanner accepts IN scans from this time</p>
                </div>
                <div class="form-group">
                    <label><i class="ph ph-sun-horizon"></i> Time-IN Window End</label>
                    <input type="time" name="time_in_end" class="form-input config-input" value="12:00">
                    <p class="field-hint">Last accepted IN scan time</p>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><i class="ph ph-moon"></i> Time-OUT Window Start</label>
                    <input type="time" name="time_out_start" class="form-input config-input" value="15:00">
                    <p class="field-hint">Scanner accepts OUT scans from this time</p>
                </div>
                <div class="form-group">
                    <label><i class="ph ph-moon-stars"></i> Time-OUT Window End</label>
                    <input type="time" name="time_out_end" class="form-input config-input" value="18:00">
                    <p class="field-hint">Last accepted OUT scan time</p>
                </div>
            </div>
        </div>
    </div>

    <!-- SMS Config -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title-group">
                <i class="ph-fill ph-chat-text" style="color:var(--primary);font-size:1.2rem;"></i>
                <h3 class="panel-title">SMS Notification (SIM800L)</h3>
            </div>
            <div class="panel-actions">
                <div class="sms-status-row" id="sms-status-row">
                    <span class="sms-stat-chip pending-chip">⏳ Pending: <strong id="sms-pending">—</strong></span>
                    <span class="sms-stat-chip sent-chip">✅ Sent: <strong id="sms-sent">—</strong></span>
                    <span class="sms-stat-chip failed-chip">❌ Failed: <strong id="sms-failed">—</strong></span>
                </div>
                <button class="btn-icon" id="reload-sms-btn" title="Refresh SMS status"><i class="ph ph-arrows-clockwise"></i></button>
            </div>
        </div>
        <div class="settings-form">
            <div class="form-row">
                <div class="form-group">
                    <label><i class="ph ph-building"></i> School Name</label>
                    <input type="text" name="school_name" class="form-input config-input" value="Your School Name" placeholder="e.g. ABC Academy">
                    <p class="field-hint">Appears in all SMS messages</p>
                </div>
                <div class="form-group">
                    <label><i class="ph ph-device-tablet"></i> UART Port (SIM800L)</label>
                    <input type="text" class="form-input" value="/dev/ttyS1" placeholder="/dev/ttyS1" readonly style="background:var(--bg-secondary)">
                    <p class="field-hint">Set in api/sms_sender.php — UART_PORT constant</p>
                </div>
            </div>
            <div class="irregular-toggle-group">
<label class="irreg-toggle-label" style="border-color:var(--primary);" for="sms_enabled_chk">
    <input type="checkbox" id="sms_enabled_chk" name="sms_enabled" value="1" checked>
    <span class="irreg-toggle-switch"></span>
    <span class="irreg-toggle-text">
        <strong>SMS Notifications Enabled</strong>
        <small>Send SMS to parents on Time-IN, Time-OUT, Late, and Absent events</small>
    </span>
</label>
            </div>

            <!-- SMS Templates Preview -->
            <div class="sms-templates">
                <p class="sms-templates-title"><i class="ph ph-envelope"></i> SMS Message Templates</p>
                <div class="sms-template-item">
                    <span class="sms-type-badge in-badge">TIME-IN</span>
                    <span class="sms-template-text">[School] Ang inyong anak na si <em>Juan dela Cruz</em> ay pumasok na ngayong Monday, 07:28 AM. Status: ON TIME ✅</span>
                </div>
                <div class="sms-template-item">
                    <span class="sms-type-badge late-badge">LATE</span>
                    <span class="sms-template-text">[School] Ang inyong anak na si <em>Juan dela Cruz</em> ay pumasok na ngayong Monday, 08:15 AM. Status: LATE ⚠️</span>
                </div>
                <div class="sms-template-item">
                    <span class="sms-type-badge out-badge">TIME-OUT</span>
                    <span class="sms-template-text">[School] Ang inyong anak na si <em>Juan dela Cruz</em> ay umuwi na ngayong Monday, 04:05 PM. Ingatan po niya sarili. 🏠</span>
                </div>
                <div class="sms-template-item">
                    <span class="sms-type-badge absent-badge">ABSENT</span>
                    <span class="sms-template-text">[School] Ang inyong anak na si <em>Juan dela Cruz</em> ay ABSENT ngayong araw (Monday, Jan 1). Mangyaring makipag-ugnayan sa paaralan. 📞</span>
                </div>
            </div>

            <!-- Recent SMS Log -->
            <div style="margin-top:1.5rem;">
                <p class="sms-templates-title"><i class="ph ph-list-bullets"></i> Recent SMS Log</p>
                <div id="sms-log-list">
                    <p style="color:var(--text-muted);font-size:.82rem;padding:.5rem 0;">Click refresh to load SMS log.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Scanner Config -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title-group">
                <i class="ph-fill ph-gear-six" style="color:var(--primary);font-size:1.2rem;"></i>
                <h3 class="panel-title">Scanner Configuration</h3>
            </div>
        </div>
        <div class="settings-form">
            <div class="form-row">
                <div class="form-group">
                    <label><i class="ph ph-device-tablet"></i> Orange Pi Scanner IP</label>
                    <input type="text" class="form-input" value="192.168.1.100">
                    <p class="field-hint">LAN IP of the Orange Pi scanner</p>
                </div>
                <div class="form-group">
                    <label><i class="ph ph-key"></i> Scanner Secret Key</label>
                    <input type="text" class="form-input" value="tracked_secret_key">
                    <p class="field-hint">Must match SCANNER_KEY in api/scan.php</p>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" id="save-config-btn">
                    <i class="ph ph-floppy-disk"></i> Save All Settings
                </button>
            </div>
        </div>
    </div>
</section>

</main>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="edit-modal">
    <div class="modal">
        <div class="modal-header"><h3><i class="ph-fill ph-pencil-simple"></i> Edit Student Profile</h3><button class="modal-close" id="modal-close"><i class="ph ph-x"></i></button></div>
        <div class="modal-body">
            <form id="edit-form">
                <input type="hidden" id="edit-id">
                <div class="form-row">
                    <div class="form-group"><label>RFID UID</label><input type="text" id="edit-rfid" class="form-input" readonly style="background:var(--bg-secondary)"></div>
                    <div class="form-group"><label>Student ID No.</label><input type="text" id="edit-idno" class="form-input"></div>
                </div>
                <div class="form-group" style="margin-bottom:1rem;"><label>Full Name *</label><input type="text" id="edit-name" class="form-input" required></div>
                <div class="form-row">
                    <div class="form-group"><label>Course *</label>
                        <select id="edit-course" class="form-input form-select">
                            <?php foreach($COURSES as $c): ?><option><?= $c ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Year Level *</label>
                        <select id="edit-year" class="form-input form-select">
                            <?php foreach($YEAR_LEVELS as $y): ?><option><?= $y ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Section</label><input type="text" id="edit-section" class="form-input"></div>
                    <div class="form-group"><label>Parent Mobile</label><input type="tel" id="edit-mobile" class="form-input"></div>
                </div>
                <div class="irregular-toggle-group" style="margin-bottom:1rem;">
                    <label class="irreg-toggle-label">
                        <input type="checkbox" id="edit-is-irregular">
                        <span class="irreg-toggle-switch"></span>
                        <span class="irreg-toggle-text"><strong>Irregular Student</strong><small>Enable custom schedule</small></span>
                    </label>
                </div>
                <div class="irregular-schedule-box" id="edit-irregular-box" style="display:none;">
                    <div class="irreg-box-header"><i class="ph-fill ph-calendar-x" style="color:var(--warning);"></i><span>Custom Class Days</span></div>
                    <div class="day-picker" id="edit-day-picker">
                        <?php foreach($DAYS as $d): ?>
                        <label class="day-pill"><input type="checkbox" name="edit_days[]" value="<?= $d ?>"><span><?= substr($d,0,3) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-actions"><button type="button" class="btn btn-ghost" id="modal-cancel">Cancel</button><button type="submit" class="btn btn-primary"><i class="ph ph-floppy-disk"></i> Save Changes</button></div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="delete-modal">
    <div class="modal modal-sm">
        <div class="modal-header danger"><h3><i class="ph-fill ph-warning"></i> Confirm Deletion</h3><button class="modal-close" id="delete-modal-close"><i class="ph ph-x"></i></button></div>
        <div class="modal-body" style="text-align:center;padding:2rem;">
            <div style="width:68px;height:68px;border-radius:50%;background:var(--danger-light);display:flex;align-items:center;justify-content:center;margin:0 auto;"><i class="ph-fill ph-trash" style="font-size:2.5rem;color:var(--danger);"></i></div>
            <p style="margin-top:1rem;">You are about to delete:</p>
            <p id="delete-student-name" style="font-weight:700;font-size:1.1rem;margin:.5rem 0;"></p>
            <p style="color:var(--text-muted);font-size:.82rem;">This action cannot be undone.</p>
            <div class="form-actions" style="justify-content:center;margin-top:1.5rem;">
                <button class="btn btn-ghost" id="delete-cancel">Cancel</button>
                <button class="btn btn-danger" id="delete-confirm"><i class="ph ph-trash"></i> Yes, Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- INVALID RFID MODAL -->
<div class="modal-overlay" id="invalid-rfid-modal">
    <div class="modal modal-sm rfid-alert-modal">
        <div class="rfid-alert-icon"><i class="ph-fill ph-warning-circle"></i></div>
        <h3>Unregistered RFID Card</h3>
        <p>The scanned card is not in the system.</p>
        <code id="invalid-rfid-uid" class="rfid-code-display"></code>
        <p class="rfid-alert-hint">Please register this student before scanning.</p>
        <button class="btn btn-primary" id="rfid-alert-close"><i class="ph ph-arrow-right"></i> Register Now</button>
    </div>
</div>

<div id="toast-container"></div>

<?php if (isset($db_error)): ?>
<div id="demo-banner" class="demo-banner">
    <i class="ph ph-info"></i>
    <span>Demo mode — <?= htmlspecialchars($db_error) ?></span>
    <button onclick="this.parentElement.style.display='none'"><i class="ph ph-x"></i></button>
</div>
<?php endif; ?>

<!-- Pass PHP arrays to JS -->
<script>
    const COURSES     = <?= json_encode($COURSES) ?>;
    const YEAR_LEVELS = <?= json_encode($YEAR_LEVELS) ?>;
    const DAYS_OF_WEEK = <?= json_encode($DAYS) ?>;
</script>
<script src="app.js"></script>
</body>
</html>
