<?php
// 1. Include the class file provided
require_once('../ros.class.php');

// 2. Instantiate the API object
$API = new RouterosAPI();

// Optional: Enable debug mode to see raw request/response in the console
// $API->debug = true;

// 3. Connection settings
$router_ip = '192.168.88.1'; // Change to your MikroTik IP
$username  = 'admin';          // Your username
$password  = 'your_password';  // Your password

// 4. Attempt to connect
if ($API->connect($router_ip, $username, $password)) {

    echo "<h2>Connection Successful</h2>";

    // 5. Execute the /interface/print command 
    $interfaces = $API->comm("/interface/print");

    // 6. Display the result in a readable format
    echo "<pre>";
    if (!empty($interfaces)) {
        print_r($interfaces);
    } else {
        echo "No interfaces found or empty response received.";
    }
    echo "</pre>";

    // 7. Disconnect (The __destruct method handles this, but it is good practice)
    $API->disconnect();

} else {
    // Error message if connection fails
    echo "<p style='color:red;'>Error: Could not connect to MikroTik. Verify IP, User, Password, or ensure the API service (port 8728) is enabled.</p>";
}
