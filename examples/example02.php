<?php
require_once('../ros.class.php');

// 1. Instantiate the class
$API = new RouterosAPI();

// Debugging configuration (optional, prints log to console)
$API->debug = true; 

// 2. Attempt to connect to RouterOS
// IP Address, Username, Password
if ($API->connect('192.168.88.1', 'admin', 'your_password')) {

    // 3. Define the new Hotspot user data
    $newUser = [
        'name'     => 'john_doe',        // Username
        'password' => '12345',           // Password
        'profile'  => 'default',         // User Profile (must exist)
        'server'   => 'hotspot1',        // Assigned server (or 'all')
        'comment'  => 'User created via PHP API'
    ];

    // 4. Execute the add command
    // The RouterOS path is: /ip/hotspot/user/add
    $response = $API->comm('/ip/hotspot/user/add', $newUser);

    // 5. Verify the response
    // If there is an error, MikroTik returns an array with '!trap'
    if (isset($response['!trap'])) {
        echo "Error creating user: " . $response['!trap'][0]['message'];
    } else {
        // Success returns the internal ID of the new item
        echo "User created successfully. Assigned ID: " . $response[0]['.id'];
    }

    // 6. Disconnect
    $API->disconnect();

} else {
    echo "Failed to connect to MikroTik.";
}
?>
