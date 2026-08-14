<?php

require_once 'vendor/autoload.php';

// Configure your ClickHouse connection
$config = [
    'host'     => '127.0.0.1', // ClickHouse host
    'port'     => '8123',      // Default ClickHouse HTTP port
    'username' => 'default',   // Database username
    'password' => 'admin',          // Database password
    'https'    => false,       // Set to true if using SSL
    'database' => 'rd'        // Sets the default database for all queries
];

try {
    $db = new ClickHouseDB\Client($config);
    
    // Select the database
    $db->database('default');

    // Run a simple query
    $response = $db->select('SELECT 1');
    
    print_r($response->rows());
    
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}

