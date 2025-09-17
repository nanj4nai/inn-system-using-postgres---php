<?php
// db.php

$host = "localhost";   // or your server IP
$port = "5432";        // default PostgreSQL port
$dbname = "inn_system"; // change to your actual DB name
$user = "postgres";   // your PostgreSQL username
$password = "admin"; // your PostgreSQL password

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // throw exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC // return assoc arrays
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]));
}
