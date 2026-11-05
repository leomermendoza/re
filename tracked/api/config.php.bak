cat << 'EOF' > /var/www/html/tracked/api/config.php
options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);

if ($use_local) {
    @$conn->real_connect(LOCAL_HOST, LOCAL_USER, LOCAL_PASS, LOCAL_DB, 3306, null, MYSQLI_CLIENT_SSL_DONT_VERIFY);
} else {
    @$conn->real_connect(SERVER_HOST, SERVER_USER, SERVER_PASS, SERVER_DB, 3306, null, MYSQLI_CLIENT_SSL_DONT_VERIFY);
}

if ($conn->connect_error) {
    echo json_encode(['offline' => true, 'school_name' => 'TrackED Attendance System']);
    exit;
}

$config = [];
\(res =\)conn->query("SELECT config_key, config_value FROM system_config");
if ($res) {
    while (\(r =\)res->fetch_assoc()) {
        \(config[\)r['config_key']] = $r['config_value'];
    }
}
echo json_encode($config);
EOF
