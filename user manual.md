This manual provides a comprehensive guide to using the RouterosAPI PHP class to manage MikroTik devices via the RouterOS API protocol.

# ---

**RouterOS PHP API Client (v2026)**

**Compatible with PHP 7.4+ and RouterOS v6.x / v7.x**  
This lightweight, object-oriented library allows you to communicate with MikroTik routers to execute commands, retrieve data, and manage configurations.

## ---

**1\. Quick Start**

To get started, include the class and initiate a connection.

PHP

require\_once 'ros.class.php';

$api \= new RouterosAPI();

// Enable debug to see the communication in the console  
$api-\>debug \= true; 

if ($api-\>connect('192.168.88.1', 'admin', 'your\_password')) {  
    echo "Connected successfully\!";  
    $api-\>disconnect();  
} else {  
    echo "Connection failed.";  
}

## ---

**2\. Configuration Properties**

You can customize the behavior of the client by adjusting these public properties before calling connect():

| Property | Type | Default | Description |
| :---- | :---- | :---- | :---- |
| $debug | bool | false | Prints raw API traffic to the output. |
| $port | int | 8728 | API Port (use 8729 for SSL). |
| $ssl | bool | false | Set to true to use an encrypted connection. |
| $timeout | int | 25 | Socket timeout in seconds. |
| $attempts | int | 3 | Number of connection attempts before failing. |
| $delay | int | 2 | Seconds to wait between connection attempts. |

## ---

**3\. Core Methods**

### **connect(string $ip, string $user, string $pass)**

Establishes a connection. It automatically handles both the modern post-v6.43 login method and the older MD5 challenge-response method.

### **comm(string $command, array $params \= \[\])**

The **recommended** way to send commands. It accepts a command path and an optional associative array of parameters.  
**Example: Fetching IP Addresses**

PHP

$addresses \= $api-\>comm("/ip/address/print");  
print\_r($addresses);

**Example: Adding a Firewall Rule**

PHP

$api-\>comm("/ip/firewall/filter/add", \[  
    "chain"  \=\> "input",  
    "src-address" \=\> "1.1.1.1",  
    "action" \=\> "drop",  
    "comment" \=\> "Blocked by PHP"  
\]);

### **execmd(string $query)**

A "quick-and-dirty" method to execute a command string similar to the MikroTik CLI.

PHP

$api-\>execmd("/system/reboot");

## ---

**4\. Understanding Data Flow**

The API follows a specific workflow of encoding lengths and reading response tags (\!re, \!done, \!trap).

### **How Responses are Parsed:**

* **\!re**: Identifies a data record. These are grouped into an associative array.  
* **\!done**: Signals the end of the command execution.  
* **\!trap**: Indicates an error occurred (e.g., "invalid command").

## ---

**5\. Advanced Usage & Filtering**

### **Using Queries (Filters)**

RouterOS allows you to filter results at the API level using ? operators.

PHP

// Find the ID of an interface named 'ether1'  
$result \= $api-\>comm("/interface/print", \[  
    "?name" \=\> "ether1"  
\]);

$interfaceID \= $result\[0\]\['.id'\];

### **SSL Connection**

To connect securely, ensure your RouterOS has the api-ssl service enabled.

PHP

$api-\>ssl \= true;  
$api-\>port \= 8729;  
$api-\>connect('192.168.88.1', 'admin', 'password');

## ---

**6\. Error Handling**

If connect() returns false, you can inspect the error details:

PHP

if (\!$api-\>connect('10.0.0.1', 'admin', 'pass')) {  
    echo "Error Number: " . $api-\>error\_no;  
    echo "Error String: " . $api-\>error\_str;  
}

## ---

**Best Practices**

1. **Always Disconnect**: Although the class has a \_\_destruct method to close the socket, it is cleaner to call $api-\>disconnect() manually when finished.  
2. **Sanitize Inputs**: If you are taking user input from a web form to update the router, ensure you validate the data before passing it to comm().  
3. **Use comm over execmd**: The comm method is more robust as it handles parameter escaping and structure better than a single string.

**Would you like me to generate a specific implementation example, such as a script to manage PPPoE users or Hotspot vouchers?**