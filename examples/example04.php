<?php
require_once('../ros.class.php');

// 1. Initialize the API class
$API = new RouterosAPI();

// 2. Connect to your MikroTik device
if ($API->connect('192.168.88.1', 'admin', 'your_password')) {

    // 3. Step One: Find the .id of the user named "testuser"
    // We use the '?' prefix for queries in the comm method
    $findUser = $API->comm('/ip/hotspot/user/print', [
        '?name' => 'testuser'
    ]);

    // 4. Step Two: If the user was found, delete it using the ID
    if (!empty($findUser) && isset($findUser[0]['.id'])) {
        $userId = $findUser[0]['.id']; // Extract the internal ID (e.g., *1A)

        // Execute the remove command with the ID
        $deleteResponse = $API->comm('/ip/hotspot/user/remove', [
            '.id' => $userId
        ]);

        // 5. Verify if the deletion triggered an error
        if (isset($deleteResponse['!trap'])) {
            echo "Error during deletion: " . $deleteResponse['!trap'][0]['message'];
        } else {
            echo "User 'testuser' (ID: $userId) successfully removed.";
        }
    } else {
        echo "User 'testuser' not found.";
    }

    // 6. Close the connection
    $API->disconnect();

} else {
    echo "Connection Failed.";
}
?>
