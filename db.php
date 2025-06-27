<?php
$dsn = 'mysql:host=localhost;dbname=url_shortener';
$username = 'root';
$password = 'Sayan@2004'; // Update with your MySQL password
try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
?>