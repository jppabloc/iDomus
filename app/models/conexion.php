<?php
$host = "localhost";
$dbname = "idomus";
$user = "postgres";
$password = "123456";

try {
    $conexion = new PDO("pgsql:host=$host;dbname=$dbname", $user, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "Conexión exitosa a la base de datos";
} catch (PDOException $e) {
    die("Error en la conexión: " . $e->getMessage());
}
?>
