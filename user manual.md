This guide provides a comprehensive overview of how to use the RouterosAPI class to manage MikroTik devices via PHP.

## ---

**🚀 Getting Started**

The RouterosAPI class allows you to communicate with MikroTik’s RouterOS using its native API protocol. It handles connection, authentication, and the complex length-encoding required by the device.

### **Prerequisites**

* **RouterOS:** API service must be enabled (/ip service enable api).  
* **PHP:** Version 7.4 or higher (due to typed properties).  
* **Port:** Default is 8728 (or 8729 for SSL).

## ---

**1\. Connection & Authentication**

To start, include the file and instantiate the class. The connect method handles the login handshake automatically.

PHP

require\_once 'ros.class.php';

$api \= new RouterosAPI();

// Configuration  
$api-\>debug \= true; // Set to true to see the raw "conversation"  
$api-\>ssl \= false;  // Change to true if using API-SSL

if ($api-\>connect('192.168.88.1', 'admin', 'your\_password')) {  
    echo "Connected successfully\!";  
      
    // ... your code here ...

    $api-\>disconnect();  
} else {  
    echo "Connection failed.";  
}

## ---

**2\. Executing Commands**

There are two main ways to send commands: execmd() (string-based) and comm() (array-based).

### **Method A: Using execmd (Quick Commands)**

Best for simple "print" commands or one-liners.

PHP

$addresses \= $api-\>execmd("/ip/address/print");  
print\_r($addresses);

### **Method B: Using comm (Recommended for Parameters)**

The comm() method is cleaner when you need to send specific arguments or filters.

PHP

// Adding a firewall rule  
$api-\>comm("/ip/firewall/filter/add", \[  
    "chain"  \=\> "input",  
    "src-address" \=\> "1.2.3.4",  
    "action" \=\> "drop",  
    "comment" \=\> "Dropped by PHP Script"  
\]);

## ---

**3\. Filtering Data (Queries)**

RouterOS allows you to filter results using specific prefixes:

* ? : Query (Where)  
* \~ : Regular Expression

PHP

// Get only the Ethernet interface named 'ether1'  
$results \= $api-\>comm("/interface/print", \[  
    "?name" \=\> "ether1"  
\]);

// Find all users where the name matches a pattern  
$users \= $api-\>comm("/user/print", \[  
    "?.id" \=\> "\*1", // Filtering by internal ID  
\]);

## ---

**4\. Understanding the Response**

The library automatically parses the RouterOS response into an **associative array**.

| RouterOS Tag | PHP Array Key | Description |
| :---- | :---- | :---- |
| \!re | Numeric Index | A standard data row (repeated). |
| \!done | (Ends loop) | Indicates the command finished successfully. |
| \!trap | \['\!trap'\] | Contains error messages from the router. |

**Example of an Error Catch:**

PHP

$result \= $api-\>comm("/ip/bad/path/print");

if (isset($result\['\!trap'\])) {  
    echo "Error: " . $result\['\!trap'\]\[0\]\['message'\];  
}

## ---

**5\. Advanced Usage: SSL & Retries**

If your environment is unstable or requires high security, use the built-in properties:

PHP

$api \= new RouterosAPI();  
$api-\>port \= 8729;  
$api-\>ssl \= true;  
$api-\>attempts \= 5; // Try connecting 5 times before giving up  
$api-\>delay \= 3;    // Wait 3 seconds between attempts  
$api-\>timeout \= 10; // Socket timeout

$api-\>connect('10.0.0.1', 'api\_user', 'secure\_pass');

## ---

**🛠 Troubleshooting Common Issues**

1. **"Connection Refused":** Ensure the API service is enabled in MikroTik under /ip service. Check that your PHP server's IP is allowed in the address field of that service.  
2. **SSL Errors:** The class is configured to ignore self-signed certificate errors by default (allow\_self\_signed \=\> true). If it fails, ensure your PHP has the openssl extension enabled.  
3. **Empty Responses:** Some commands (like add or set) do not return data, only a \!done status. Check the return value of comm() to ensure it's not an error trap.

---

**Would you like me to generate a specific implementation script, such as a dashboard to monitor traffic or a user-management portal using this class?**