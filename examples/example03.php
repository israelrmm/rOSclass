<?php
require_once('../ros.class.php');

// 1. Initialize the API class
$API = new RouterosAPI();

// 2. Connect to your MikroTik device
if ($API->connect('192.168.88.1', 'admin', 'your_password')) {

    // 3. Define the command string
    // We use 'count=5' to ensure the command terminates
    $pingCommand = "/ping 8.8.8.8 count=5";

    // 4. Execute using execmd
    // This method will split the string and send it to the router
    $response = $API->execmd($pingCommand);

    // 5. Display the results
    echo "--- Ping Results (via execmd) ---" . PHP_EOL;

    foreach ($response as $packet) {
        // Checking for successful replies
        if (isset($packet['host'])) {
            $status = $packet['status'] ?? 'Received';
            $time = $packet['time'] ?? 'N/A';
            echo "Host: {$packet['host']} | Status: {$status} | Time: {$time}ms" . PHP_EOL;
        }
    }

    // 6. Close the connection
    $API->disconnect();

} else {
    echo "Connection Failed.";
}
?>
