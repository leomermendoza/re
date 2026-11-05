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

define('LOCAL_HOST', 'localhost');
define('LOCAL_DB',   'tracked_local');
define('LOCAL_USER', 'root');
define('LOCAL_PASS', 'Akiesha050225');

define('SCANNER_KEY', 'tracked_secret_key');
define('DENIED_LOCK_THRESHOLD', 3);

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function server_online() {
    $sock = @fsockopen(SERVER_HOST, SERVER_PORT, $errno, $errstr, 2);
    if ($sock) { fclose($sock); return true; }
    return false;
}
function db_connect($host, $user, $pass, $db, $port = 3306, $timeout = 2) {
    $c = mysqli_init();
    if (!$c) return null;
    @$c->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1,(int)$timeout));
    if (!@$c->real_connect($host, $user, $pass, $db, $port)) return null;
    $c->set_charset('utf8mb4');
    return $c;
}
function table_exists($conn, $table) {
    $t = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW TABLES LIKE '$t'");
    return $r && $r->num_rows > 0;
}
function column_exists($conn, $table, $column) {
    $t=$conn->real_escape_string($table); $c=$conn->real_escape_string($column);
    $r=@$conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && $r->num_rows > 0;
}
function ensure_local_monitoring_schema($conn) {
    @$conn->query("CREATE TABLE IF NOT EXISTS student_movement_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        server_id BIGINT UNSIGNED NULL,
        student_id INT NOT NULL,
        rfid_uid VARCHAR(50) NOT NULL,
        movement_type ENUM('IN','OUT') NOT NULL,
        movement_context VARCHAR(40) NOT NULL DEFAULT 'NORMAL',
        authorization_reason VARCHAR(255) NULL,
        verified_at DATETIME NOT NULL,
        verified_by VARCHAR(80) NOT NULL DEFAULT 'Guard Kiosk',
        source VARCHAR(30) NOT NULL DEFAULT 'KIOSK',
        event_id INT NULL,
        synced TINYINT(1) NOT NULL DEFAULT 0,
        sync_attempts INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_local_server_movement (server_id),
        KEY idx_local_movement_student_time (student_id,verified_at),
        KEY idx_local_movement_sync (synced,sync_attempts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("CREATE TABLE IF NOT EXISTS movement_policy (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        hs_controlled_exit_enabled TINYINT(1) NOT NULL DEFAULT 1,
        hs_dismissal_start TIME NOT NULL DEFAULT '15:30:00',
        hs_early_exit_requires_reason TINYINT(1) NOT NULL DEFAULT 1,
        hs_allow_reentry TINYINT(1) NOT NULL DEFAULT 1,
        sms_every_movement TINYINT(1) NOT NULL DEFAULT 1,
        sms_early_exit TINYINT(1) NOT NULL DEFAULT 1,
        same_card_cooldown_seconds TINYINT UNSIGNED NOT NULL DEFAULT 4,
        updated_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("ALTER TABLE movement_policy ADD COLUMN IF NOT EXISTS hs_controlled_exit_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER id");
    @$conn->query("INSERT IGNORE INTO movement_policy(id) VALUES(1)");
    @$conn->query("CREATE TABLE IF NOT EXISTS event_attendance_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        rfid_uid VARCHAR(50) NOT NULL,
        event_id INT NOT NULL,
        event_action VARCHAR(20) NOT NULL,
        event_time DATETIME NOT NULL,
        status VARCHAR(30) NULL,
        synced TINYINT(1) NOT NULL DEFAULT 0,
        sync_attempts INT NOT NULL DEFAULT 0,
        KEY idx_event_queue_sync (synced,sync_attempts,event_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("CREATE TABLE IF NOT EXISTS sms_sync_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        server_id BIGINT UNSIGNED NULL,
        mobile VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        synced TINYINT(1) NOT NULL DEFAULT 0,
        sync_attempts INT NOT NULL DEFAULT 0,
        KEY idx_sms_sync_queue (synced,sync_attempts,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("CREATE TABLE IF NOT EXISTS sync_runtime_status (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        server_online TINYINT(1) NOT NULL DEFAULT 0,
        last_check DATETIME NULL,
        last_success DATETIME NULL,
        last_error VARCHAR(500) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("INSERT IGNORE INTO sync_runtime_status(id,server_online) VALUES(1,0)");
    if (table_exists($conn,'attendance')) {
        if (!column_exists($conn,'attendance','event_id')) @$conn->query("ALTER TABLE attendance ADD COLUMN event_id INT NULL AFTER student_id");
        if (!column_exists($conn,'attendance','attendance_source')) @$conn->query("ALTER TABLE attendance ADD COLUMN attendance_source VARCHAR(20) NOT NULL DEFAULT 'LEGACY' AFTER event_id");
    }
    if (table_exists($conn,'rejected_scans') && !column_exists($conn,'rejected_scans','movement_context')) {
        @$conn->query("ALTER TABLE rejected_scans ADD COLUMN movement_context VARCHAR(40) NOT NULL DEFAULT 'NORMAL' AFTER scan_type");
    }
    if (table_exists($conn,'students')) {
        if (!column_exists($conn,'students','scan_enabled')) @$conn->query("ALTER TABLE students ADD COLUMN scan_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
        if (!column_exists($conn,'students','denied_attempt_count')) @$conn->query("ALTER TABLE students ADD COLUMN denied_attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER scan_enabled");
        if (!column_exists($conn,'students','scan_locked_at')) @$conn->query("ALTER TABLE students ADD COLUMN scan_locked_at DATETIME NULL AFTER denied_attempt_count");
        if (!column_exists($conn,'students','scan_lock_reason')) @$conn->query("ALTER TABLE students ADD COLUMN scan_lock_reason VARCHAR(255) NULL AFTER scan_locked_at");
        if (!column_exists($conn,'students','scan_unlocked_at')) @$conn->query("ALTER TABLE students ADD COLUMN scan_unlocked_at DATETIME NULL AFTER scan_lock_reason");
        if (!column_exists($conn,'students','scan_access_updated_at')) @$conn->query("ALTER TABLE students ADD COLUMN scan_access_updated_at DATETIME NULL DEFAULT NULL AFTER scan_unlocked_at");
    }
}
function queue_sms($conn, $mobile, $message, $use_local = false) {
    $m=$conn->real_escape_string($mobile); $g=$conn->real_escape_string($message);
    if ($use_local) {
        if (!table_exists($conn,'sms_sync_queue')) return false;
        return @$conn->query("INSERT INTO sms_sync_queue (mobile,message,synced,sync_attempts) VALUES ('$m','$g',0,0)");
    }
    if (!table_exists($conn,'sms_queue')) return false;
    return @$conn->query("INSERT INTO sms_queue (mobile,message,status) VALUES ('$m','$g','pending')");
}
function cached_server_offline($conn) {
    if (!table_exists($conn,'sync_runtime_status')) return false;
    $r=@$conn->query("SELECT server_online,last_check FROM sync_runtime_status WHERE id=1 LIMIT 1");
    if(!$r || !$r->num_rows) return false;
    $x=$r->fetch_assoc();
    if(empty($x['last_check'])) return false;
    $age=time()-strtotime($x['last_check']);
    if($age>120) return false; // stale status: do not falsely label kiosk offline
    return (int)$x['server_online']!==1;
}
function student_public($s, $use_local = false) {
    $photo = trim($s['photo_path'] ?? '');
    if ($photo !== '' && !preg_match('/^https?:\/\//i', $photo)) {
        $rel=ltrim($photo,'/');
        $localFile=rtrim($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html','/').'/tracked/'.$rel;
        // Local-first DB does not imply the image is stored locally. Prefer a local copy when present,
        // otherwise load the student's photo from the Admin server without blocking the scan transaction.
        $base = ($use_local && is_file($localFile)) ? 'http://localhost/tracked/' : 'http://' . SERVER_HOST . '/tracked/';
        $photo = $base . $rel;
    }
    return [
        'id'=>(int)($s['id']??0),'full_name'=>$s['full_name']??'','student_id_no'=>$s['student_id_no']??'',
        'education_level'=>$s['education_level']??'College','course'=>$s['course']??'','track'=>$s['track']??'',
        'strand'=>$s['strand']??'','year_level'=>$s['year_level']??'','section'=>$s['section']??'',
        'photo_path'=>$photo,'parent_mobile'=>$s['parent_mobile']??'',
        'scan_enabled'=>(int)($s['scan_enabled']??1),'denied_attempt_count'=>(int)($s['denied_attempt_count']??0),
        'scan_locked_at'=>$s['scan_locked_at']??null,'scan_lock_reason'=>$s['scan_lock_reason']??null
    ];
}
function student_access_columns_ready($conn) {
    return table_exists($conn,'students') && column_exists($conn,'students','scan_enabled') && column_exists($conn,'students','denied_attempt_count');
}
function mirror_access_state_to_local($student_id,$rfid) {
    if (SERVER_HOST==='localhost' || SERVER_HOST==='127.0.0.1') return;
    $srv=db_connect(SERVER_HOST,SERVER_USER,SERVER_PASS,SERVER_DB,SERVER_PORT);
    $loc=db_connect(LOCAL_HOST,LOCAL_USER,LOCAL_PASS,LOCAL_DB);
    if(!$srv||!$loc){ if($srv)$srv->close(); if($loc)$loc->close(); return; }
    ensure_local_monitoring_schema($loc);
    if(!student_access_columns_ready($srv) || !student_access_columns_ready($loc)){ $srv->close(); $loc->close(); return; }
    $rf=$srv->real_escape_string($rfid);
    $r=@$srv->query("SELECT scan_enabled,denied_attempt_count,scan_locked_at,scan_lock_reason,scan_unlocked_at,scan_access_updated_at FROM students WHERE id=".(int)$student_id." AND rfid_uid='$rf' LIMIT 1");
    if($r&&$r->num_rows){
        $a=$r->fetch_assoc();
        $reason=trim((string)($a['scan_lock_reason']??''))===''?'NULL':"'".$loc->real_escape_string($a['scan_lock_reason'])."'";
        $locked=empty($a['scan_locked_at'])?'NULL':"'".$loc->real_escape_string($a['scan_locked_at'])."'";
        $unlocked=empty($a['scan_unlocked_at'])?'NULL':"'".$loc->real_escape_string($a['scan_unlocked_at'])."'";
        $updated=empty($a['scan_access_updated_at'])?"NOW()":"'".$loc->real_escape_string($a['scan_access_updated_at'])."'";
        @$loc->query("UPDATE students SET scan_enabled=".(int)$a['scan_enabled'].",denied_attempt_count=".(int)$a['denied_attempt_count'].",scan_locked_at=$locked,scan_lock_reason=$reason,scan_unlocked_at=$unlocked,scan_access_updated_at=$updated WHERE id=".(int)$student_id);
    }
    $srv->close();$loc->close();
}
function increment_denied_attempt($conn,$student,$reason,$at,$use_local) {
    if(!student_access_columns_ready($conn)) return ['attempts'=>0,'locked'=>false,'remaining'=>DENIED_LOCK_THRESHOLD];
    $sid=(int)$student['id'];$rs=$conn->real_escape_string($reason);$ts=$conn->real_escape_string($at);
    @$conn->query("UPDATE students SET denied_attempt_count=LEAST(255,denied_attempt_count+1),scan_access_updated_at='$ts' WHERE id=$sid");
    $r=@$conn->query("SELECT denied_attempt_count,scan_enabled FROM students WHERE id=$sid LIMIT 1");
    $attempts=0;$locked=false;
    if($r&&$r->num_rows){$a=$r->fetch_assoc();$attempts=(int)$a['denied_attempt_count'];$locked=((int)$a['scan_enabled']===0);}
    if(!$locked && $attempts>=DENIED_LOCK_THRESHOLD){
        $lockReason=$conn->real_escape_string('3 consecutive denied guard verification attempts');
        @$conn->query("UPDATE students SET scan_enabled=0,denied_attempt_count=".DENIED_LOCK_THRESHOLD.",scan_locked_at='$ts',scan_lock_reason='$lockReason',scan_unlocked_at=NULL,scan_access_updated_at='$ts' WHERE id=$sid");
        $attempts=DENIED_LOCK_THRESHOLD;$locked=true;
        if(table_exists($conn,'student_scan_access_audit')){
            @$conn->query("INSERT INTO student_scan_access_audit(student_id,action,attempt_count,reason,performed_by,created_at) VALUES($sid,'LOCKED',$attempts,'$rs','Guard Kiosk','$ts')");
        }
    }
    if(!$use_local) mirror_access_state_to_local($sid,$student['rfid_uid']);
    return ['attempts'=>$attempts,'locked'=>$locked,'remaining'=>max(0,DENIED_LOCK_THRESHOLD-$attempts)];
}
function reset_denied_attempts_after_accept($conn,$student,$at,$use_local) {
    if(!student_access_columns_ready($conn)) return;
    $sid=(int)$student['id'];$ts=$conn->real_escape_string($at);
    @$conn->query("UPDATE students SET denied_attempt_count=0,scan_access_updated_at='$ts' WHERE id=$sid AND scan_enabled=1 AND denied_attempt_count<>0");
    if(!$use_local) mirror_access_state_to_local($sid,$student['rfid_uid']);
}
function get_policy($conn) {
    $d=['hs_controlled_exit_enabled'=>1,'hs_dismissal_start'=>'15:30:00','hs_early_exit_requires_reason'=>1,'hs_allow_reentry'=>1,'sms_every_movement'=>1,'sms_early_exit'=>1,'same_card_cooldown_seconds'=>4];
    if (!table_exists($conn,'movement_policy')) return $d;
    $r=@$conn->query("SELECT * FROM movement_policy WHERE id=1 LIMIT 1");
    if ($r && $r->num_rows) $d=array_merge($d,$r->fetch_assoc());
    return $d;
}
function get_active_event($conn, $today) {
    if (!table_exists($conn,'intramural_events')) return null;
    $d=$conn->real_escape_string($today);
    $r=@$conn->query("SELECT * FROM intramural_events WHERE is_active=1 AND override_enabled=1 AND start_date IS NOT NULL AND end_date IS NOT NULL AND start_date<='$d' AND end_date>='$d' ORDER BY start_date DESC,id DESC LIMIT 1");
    return ($r && $r->num_rows)?$r->fetch_assoc():null;
}
function in_window($now,$start,$end) {
    if(!$start||!$end) return false;
    $n=substr($now,0,8);$s=substr($start,0,8);$e=substr($end,0,8);
    if($s<=$e) return $n>=$s && $n<=$e;
    return $n>=$s || $n<=$e; // supports overnight window
}
function last_today_movement($conn,$student_id,$today) {
    $d=$conn->real_escape_string($today);
    $r=@$conn->query("SELECT * FROM student_movement_logs WHERE student_id=".(int)$student_id." AND DATE(verified_at)='$d' ORDER BY verified_at DESC,id DESC LIMIT 1");
    return ($r && $r->num_rows)?$r->fetch_assoc():null;
}
function latest_movement_age_seconds($conn,$student_id) {
    $r=@$conn->query("SELECT TIMESTAMPDIFF(SECOND,verified_at,NOW()) age_sec FROM student_movement_logs WHERE student_id=".(int)$student_id." ORDER BY verified_at DESC,id DESC LIMIT 1");
    if(!$r||!$r->num_rows) return null;
    return (int)$r->fetch_assoc()['age_sec'];
}
function determine_movement($conn,$student,$today,$now,$policy,$event) {
    $last=last_today_movement($conn,(int)$student['id'],$today);
    $type=(!$last || ($last['movement_type']??'OUT')==='OUT')?'IN':'OUT';
    $level=$student['education_level']??'College';
    $isHS=in_array($level,['Senior High School','Junior High School'],true);
    $context='NORMAL'; $requires=false; $dismissal=null;
    if($isHS && (int)($policy['hs_controlled_exit_enabled']??1)===1) {
        $dismissal=$event ? ($event['hs_time_out_start']??$policy['hs_dismissal_start']) : $policy['hs_dismissal_start'];
        if($type==='OUT' && $now < substr($dismissal,0,8)) {
            $context='EARLY_EXIT';
            $requires=(int)($policy['hs_early_exit_requires_reason']??1)===1;
        } elseif($type==='IN' && $last && ($last['movement_context']??'')==='EARLY_EXIT') {
            if((int)($policy['hs_allow_reentry']??1)!==1) return ['status'=>'reentry_blocked','message'=>'High School re-entry is disabled by Campus Policy.'];
            $context='AUTHORIZED_REENTRY';
        }
    }
    return ['status'=>'ok','movement_type'=>$type,'movement_context'=>$context,'requires_authorization'=>$requires,'dismissal_start'=>$dismissal,'last'=>$last];
}
function insert_rejected($conn,$student_id,$rfid,$type,$context,$reason,$at) {
    $rf=$conn->real_escape_string($rfid);$ty=$conn->real_escape_string($type);$cx=$conn->real_escape_string($context);$rs=$conn->real_escape_string($reason);$ts=$conn->real_escape_string($at);
    $hasCtx=column_exists($conn,'rejected_scans','movement_context');
    $hasSync=column_exists($conn,'rejected_scans','synced');
    $cols='student_id,rfid_uid,scan_type'.($hasCtx?',movement_context':'').',rejection_reason,rejected_at'.($hasSync?',synced,sync_attempts':'');
    $vals=(int)$student_id.",'$rf','$ty'".($hasCtx?",'$cx'":'').",'$rs','$ts'".($hasSync?',0,0':'');
    return @$conn->query("INSERT INTO rejected_scans($cols) VALUES($vals)");
}
function insert_movement($conn,$student,$type,$context,$reason,$at,$event_id,$use_local) {
    $rf=$conn->real_escape_string($student['rfid_uid']);$ty=$conn->real_escape_string($type);$cx=$conn->real_escape_string($context);$rs=trim($reason)===''?'NULL':"'".$conn->real_escape_string($reason)."'";$ts=$conn->real_escape_string($at);$ev=$event_id? (int)$event_id : 'NULL';
    $sql="INSERT INTO student_movement_logs(student_id,rfid_uid,movement_type,movement_context,authorization_reason,verified_at,verified_by,source,event_id".($use_local?',synced':'').") VALUES(".(int)$student['id'].",'$rf','$ty','$cx',$rs,'$ts','Guard Kiosk','KIOSK',$ev".($use_local?',0':'').")";
    $ok=@$conn->query($sql);
    return $ok ? (int)$conn->insert_id : 0;
}
function mirror_online_movement_to_local($server_id,$student,$type,$context,$reason,$at,$event_id) {
    $local=db_connect(LOCAL_HOST,LOCAL_USER,LOCAL_PASS,LOCAL_DB); if(!$local)return;
    ensure_local_monitoring_schema($local);
    $rf=$local->real_escape_string($student['rfid_uid']);
    $sr=@$local->query("SELECT id FROM students WHERE rfid_uid='$rf' LIMIT 1"); if(!$sr||!$sr->num_rows){$local->close();return;}
    $sid=(int)$sr->fetch_assoc()['id'];$ty=$local->real_escape_string($type);$cx=$local->real_escape_string($context);$rs=trim($reason)===''?'NULL':"'".$local->real_escape_string($reason)."'";$ts=$local->real_escape_string($at);$ev=$event_id?(int)$event_id:'NULL';
    @$local->query("INSERT IGNORE INTO student_movement_logs(server_id,student_id,rfid_uid,movement_type,movement_context,authorization_reason,verified_at,verified_by,source,event_id,synced) VALUES(".(int)$server_id.",$sid,'$rf','$ty','$cx',$rs,'$ts','Guard Kiosk','KIOSK',$ev,1)");
    $local->close();
}
function event_schedule_values($event,$student) {
    $prefix=(($student['education_level']??'College')==='College')?'college_':'hs_';
    return [
        'time_in_start'=>$event[$prefix.'time_in_start']??'06:00:00','ontime_cutoff'=>$event[$prefix.'ontime_cutoff']??'07:30:00','late_cutoff'=>$event[$prefix.'late_cutoff']??'08:30:00',
        'lunch_enabled'=>(int)($event[$prefix.'lunch_enabled']??0),'lunch_out_start'=>$event[$prefix.'lunch_out_start']??'11:30:00','lunch_out_end'=>$event[$prefix.'lunch_out_end']??'12:15:00','lunch_in_start'=>$event[$prefix.'lunch_in_start']??'12:15:00','lunch_in_end'=>$event[$prefix.'lunch_in_end']??'13:15:00',
        'time_out_start'=>$event[$prefix.'time_out_start']??'16:00:00','time_out_end'=>$event[$prefix.'time_out_end']??'18:00:00'
    ];
}
function record_event_attendance($conn,$student,$event,$movement_type,$now_full,$now) {
    if(!$event || !table_exists($conn,'attendance') || !column_exists($conn,'attendance','event_id')) return null;
    $eid=(int)$event['id'];$sid=(int)$student['id'];$sched=event_schedule_values($event,$student);$today=date('Y-m-d');
    $r=@$conn->query("SELECT * FROM attendance WHERE student_id=$sid AND event_id=$eid AND DATE(scan_time)='$today' ORDER BY id DESC LIMIT 1");$att=($r&&$r->num_rows)?$r->fetch_assoc():null;
    if($movement_type==='IN') {
        if(!$att && in_window($now,$sched['time_in_start'],$sched['late_cutoff'])) {
            $status=$now<=substr($sched['ontime_cutoff'],0,8)?'On Time':'Late';$st=$conn->real_escape_string($status);$ts=$conn->real_escape_string($now_full);
            if(@$conn->query("INSERT INTO attendance(student_id,event_id,attendance_source,scan_time,status,scan_type,sms_sent_in,sms_sent_out) VALUES($sid,$eid,'EVENT','$ts','$st','IN',0,0)")) return ['action'=>'EVENT_IN','status'=>$status];
        }
        if($att && (int)$sched['lunch_enabled']===1 && !empty($att['lunch_out']) && empty($att['lunch_in']) && in_window($now,$sched['lunch_in_start'],$sched['lunch_in_end'])) {
            $ts=$conn->real_escape_string($now_full);@$conn->query("UPDATE attendance SET lunch_in='$ts' WHERE id=".(int)$att['id']);return ['action'=>'EVENT_LUNCH_IN','status'=>'Lunch In'];
        }
    } else {
        if($att && (int)$sched['lunch_enabled']===1 && empty($att['lunch_out']) && in_window($now,$sched['lunch_out_start'],$sched['lunch_out_end'])) {
            $ts=$conn->real_escape_string($now_full);@$conn->query("UPDATE attendance SET lunch_out='$ts' WHERE id=".(int)$att['id']);return ['action'=>'EVENT_LUNCH_OUT','status'=>'Lunch Out'];
        }
        if($att && empty($att['time_out']) && in_window($now,$sched['time_out_start'],$sched['time_out_end'])) {
            $ts=$conn->real_escape_string($now_full);@$conn->query("UPDATE attendance SET time_out='$ts' WHERE id=".(int)$att['id']);return ['action'=>'EVENT_OUT','status'=>'Time Out'];
        }
    }
    return null;
}
function queue_event_sync($conn,$rfid,$event_id,$effect,$at) {
    if(!$effect || !table_exists($conn,'event_attendance_queue')) return;
    $rf=$conn->real_escape_string($rfid);$act=$conn->real_escape_string($effect['action']??'');$ts=$conn->real_escape_string($at);$st=$conn->real_escape_string($effect['status']??'');
    @$conn->query("INSERT INTO event_attendance_queue(rfid_uid,event_id,event_action,event_time,status) VALUES('$rf',".(int)$event_id.",'$act','$ts','$st')");
}

/*
 * FAST LOCAL DIRECT-SCAN MODE
 * The kiosk commits each valid RFID movement directly to tracked_local first.
 * No guard ACCEPT/REJECT verification stage is used.
 * High School early-exit reason is still enforced when campus policy requires it.
 * tracked_sync.py pushes queued records to the Admin server in the background.
 */
$use_local=true;
$conn=db_connect(LOCAL_HOST,LOCAL_USER,LOCAL_PASS,LOCAL_DB,3306,1);
if(!$conn){
    // Emergency fallback only: use the Admin DB if the local MariaDB service is unavailable.
    $use_local=false;
    $conn=db_connect(SERVER_HOST,SERVER_USER,SERVER_PASS,SERVER_DB,SERVER_PORT,2);
    if(!$conn)respond(['status'=>'error','message'=>'Local and server databases are unavailable.'],500);
}
if($use_local) ensure_local_monitoring_schema($conn);
$offline = $use_local ? cached_server_offline($conn) : false;
if(!table_exists($conn,'student_movement_logs')) respond(['status'=>'migration_required','message'=>'Student monitoring schema is not ready.'],500);

$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))$body=[];
$key=trim($body['scanner_key']??'');$action=strtolower(trim($body['action']??''));$rfid_uid=trim($body['rfid_uid']??'');$reject_reason=trim($body['rejection_reason']??'');$auth_reason=trim($body['authorization_reason']??'');
if($key!==SCANNER_KEY)respond(['status'=>'error','message'=>'Unauthorized.'],401);
if($rfid_uid==='')respond(['status'=>'error','message'=>'RFID required.'],400);
// Guard rejection is intentionally disabled in direct-scan mode. A recognized,
// active student is recorded automatically unless campus policy requires an
// authorized High School early-exit reason.
if($action==='reject')respond(['status'=>'verification_disabled','message'=>'Guard ACCEPT/REJECT verification is disabled.'],410);
if($action!=='' && $action!=='accept')respond(['status'=>'error','message'=>'Invalid scan request.'],400);

$rf=$conn->real_escape_string($rfid_uid);$r=@$conn->query("SELECT * FROM students WHERE rfid_uid='$rf' AND is_active=1 LIMIT 1");if(!$r||!$r->num_rows)respond(['status'=>'unknown','message'=>'Student not found.','offline'=>$offline]);$student=$r->fetch_assoc();$sid=(int)$student['id'];
$now_full=date('Y-m-d H:i:s');$today=date('Y-m-d');$now=date('H:i:s');$policy=get_policy($conn);$event=get_active_event($conn,$today);
if(student_access_columns_ready($conn) && (int)($student['scan_enabled']??1)!==1){
    respond(['status'=>'access_locked','message'=>'RFID campus IN/OUT scanning is temporarily disabled after 3 denied verification attempts. Please proceed to the SPMO Office for reactivation.','student'=>student_public($student,$use_local),'denied_attempts'=>(int)($student['denied_attempt_count']??DENIED_LOCK_THRESHOLD),'offline'=>$offline]);
}

$age=latest_movement_age_seconds($conn,$sid);$cool=max(2,(int)($policy['same_card_cooldown_seconds']??4));if($age!==null && $age>=0 && $age<$cool){respond(['status'=>'cooldown','message'=>'Card already processed. Please wait a moment before tapping the same ID again.','cooldown_remaining'=>$cool-$age,'student'=>student_public($student,$use_local),'offline'=>$offline]);}
$stage=determine_movement($conn,$student,$today,$now,$policy,$event);if(($stage['status']??'')!=='ok')respond(['status'=>$stage['status'],'message'=>$stage['message']??'Movement not allowed.','student'=>student_public($student,$use_local),'offline'=>$offline]);
$type=$stage['movement_type'];$context=$stage['movement_context'];

// Direct scan: do not return a verification screen. Continue to the fresh
// movement-state check below and commit this IN/OUT movement in this request.

// Re-check immediately before commit to avoid stale IN/OUT state.
$age=latest_movement_age_seconds($conn,$sid);if($age!==null&&$age>=0&&$age<$cool)respond(['status'=>'cooldown','message'=>'This card was just processed.','cooldown_remaining'=>$cool-$age,'student'=>student_public($student,$use_local),'offline'=>$offline]);
$stage=determine_movement($conn,$student,$today,$now,$policy,$event);if(($stage['status']??'')!=='ok')respond(['status'=>$stage['status'],'message'=>$stage['message']??'Movement not allowed.','student'=>student_public($student,$use_local),'offline'=>$offline]);$type=$stage['movement_type'];$context=$stage['movement_context'];
if($stage['requires_authorization'] && $auth_reason==='') respond(['status'=>'authorization_required','message'=>'Select an authorized High School early-exit reason before this OUT movement can be recorded.','student'=>student_public($student,$use_local),'movement_type'=>$type,'movement_context'=>$context,'offline'=>$offline],400);

$event_id=$event?(int)$event['id']:null;$movement_id=insert_movement($conn,$student,$type,$context,$auth_reason,$now_full,$event_id,$use_local);if(!$movement_id)respond(['status'=>'error','message'=>'Failed to save campus movement. Check student_movement_logs migration.'],500);
if(!$use_local)mirror_online_movement_to_local($movement_id,$student,$type,$context,$auth_reason,$now_full,$event_id);
reset_denied_attempts_after_accept($conn,$student,$now_full,$use_local);

$event_effect=record_event_attendance($conn,$student,$event,$type,$now_full,$now);if($use_local&&$event_effect&&$event_id)queue_event_sync($conn,$rfid_uid,$event_id,$event_effect,$now_full);

$config=[];if(table_exists($conn,'system_config')){$cr=@$conn->query("SELECT config_key,config_value FROM system_config");if($cr)while($x=$cr->fetch_assoc())$config[$x['config_key']]=$x['config_value'];}
$sms_enabled=($config['sms_enabled']??'1')==='1' && (int)($policy['sms_every_movement']??1)===1;$school=$config['school_name']??'TrackED';$sms_q=false;
if($sms_enabled&&!empty($student['parent_mobile'])){
    $t=date('h:i A');$d=date('M j, Y');$name=$student['full_name'];$level=$student['education_level']??'College';
    if($type==='IN'){
        if($context==='AUTHORIZED_REENTRY')$msg="[$school] Si $name ay muling pumasok sa campus ngayong $d, $t (authorized re-entry).";
        else $msg="[$school] Si $name ay pumasok sa campus ngayong $d, $t.";
    }else{
        if($context==='EARLY_EXIT' && (int)($policy['sms_early_exit']??1)===1)$msg="[$school] Si $name ay AUTHORIZED EARLY EXIT ngayong $d, $t. Reason: $auth_reason.";
        else $msg="[$school] Si $name ay lumabas ng campus ngayong $d, $t.";
    }
    $sms_q=queue_sms($conn,$student['parent_mobile'],$msg,$use_local);
}
respond(['status'=>'accepted','student'=>student_public($student,$use_local),'movement_type'=>$type,'scan_type'=>$type,'movement_context'=>$context,'authorization_reason'=>$auth_reason?:null,'verified_at'=>$now_full,'sms_queued'=>$sms_q,'event_active'=>!!$event,'event_name'=>$event['name']??null,'event_effect'=>$event_effect,'offline'=>$offline]);
