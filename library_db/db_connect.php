<?php
// db_connect.php

$servername = "localhost";   // usually 'localhost' in XAMPP/phpMyAdmin
$username   = "root";        // default MySQL user in XAMPP
$password   = "";            // default password is empty
$dbname     = "library_db";  // your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>