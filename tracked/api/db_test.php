 SUCCESS: Nakikita ng PHP ang port 3306.\n";
    fclose($sock);
} else {
    echo " -> FAILED: Hindi ma-reach ng PHP ang port 3306. Error: \(errstr (\)errno)\n";
}

echo "\n2. Testing PHP MySQL connection (Standard)...\n";
\(conn1 = @new mysqli(\)host, \(user,\)pass, $db);
if ($conn1->connect_error) {
    echo " -> FAILED: " . $conn1->connect_error . "\n";
} else {
    echo " -> SUCCESS: Naka-connect nang normal.\n";
}

echo "\n3. Testing PHP MySQL connection (No SSL / ssl=0)...\n";
$conn2 = mysqli_init();
$conn2->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
@\(conn2->real_connect(\)host, \(user,\)pass, $db, 3306, null, MYSQLI_CLIENT_SSL_DONT_VERIFY);
if ($conn2->connect_error) {
    echo " -> FAILED: " . $conn2->connect_error . "\n";
} else {
    echo " -> SUCCESS: Naka-connect gamit ang ssl=0.\n";
}
