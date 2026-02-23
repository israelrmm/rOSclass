<?php
require_once('../ros.class.php');

// 1. Initialize the API class
$API = new RouterosAPI();

// 2. Connect to your MikroTik device
if ($API->connect('192.168.88.1', 'admin', 'your_password')) {

    // 3. Define the command and the new value
    // The RouterOS path is: /system/identity/set
    // The property we want to change is 'name'
    $newIdentity = [
        'name' => 'ChangedIdentityRouter'
    ];

    // 4. Execute using the comm method
    $response = $API->comm('/system/identity/set', $newIdentity);

    // 5. Check for success or errors
    // Successful 'set' commands usually return an empty array (just the !done tag)
    if (isset($response['!trap'])) {
        echo "Error: " . $response['!trap'][0]['message'];
    } else {
        echo "System identity updated successfully to 'RosClassRouter'.";
    }

    // 6. Always close the connection
    $API->disconnect();

} else {
    echo "Connection Failed.";
}
?>
