<?php
// app/views/login/prueba.php
declare(strict_types=1);
include_once '../../models/conexion.php';

try {
    echo "<h3>Creación de administrador iDomus</h3>";

    // Paso 1: hash seguro
    $correo = 'admin@admin.com';
    $passPlano = '123456';
    $nombre = 'Admin';
    $apellido = 'System';
    $hash = password_hash($passPlano, PASSWORD_DEFAULT);

    // Paso 2: buscar si ya existe
    $st = $conexion->prepare("SELECT iduser FROM usuario WHERE correo=:c");
    $st->execute([':c'=>$correo]);
    $iduser = $st->fetchColumn();

    if ($iduser) {
        echo "<p>✅ El usuario ya existe (ID: $iduser).</p>";
    } else {
        // Crear usuario
        $st = $conexion->prepare("
            INSERT INTO usuario (nombre, apellido, correo, contrasena, verificado)
            VALUES (:n, :a, :c, :p, true)
            RETURNING iduser
        ");
        $st->execute([':n'=>$nombre, ':a'=>$apellido, ':c'=>$correo, ':p'=>$hash]);
        $iduser = $st->fetchColumn();
        echo "<p>👤 Usuario creado con ID $iduser</p>";
    }

    // Paso 3: asegurar rol admin existe
    $st = $conexion->query("SELECT idrol FROM rol WHERE lower(nombre_rol)='admin'");
    $idrol = $st->fetchColumn();
    if (!$idrol) {
        $conexion->query("INSERT INTO rol (nombre_rol) VALUES ('admin')");
        $idrol = $conexion->lastInsertId('rol_idrol_seq');
        echo "<p>⚙️ Rol admin creado (ID $idrol)</p>";
    }

    // Paso 4: vincular usuario con rol admin
    $st = $conexion->prepare("SELECT 1 FROM usuario_rol WHERE iduser=:u AND idrol=:r");
    $st->execute([':u'=>$iduser, ':r'=>$idrol]);
    if (!$st->fetch()) {
        $st = $conexion->prepare("INSERT INTO usuario_rol (iduser, idrol) VALUES (:u,:r)");
        $st->execute([':u'=>$iduser, ':r'=>$idrol]);
        echo "<p>🔗 Rol admin asignado al usuario</p>";
    } else {
        echo "<p>✅ Ya tenía rol admin</p>";
    }

    echo "<hr><p><b>Usuario:</b> admin@admin.com<br><b>Contraseña:</b> 123456</p>";
    echo "<p>Ahora puedes iniciar sesión normalmente en tu login.php</p>";

} catch (Throwable $e) {
    echo "<p style='color:red'>❌ Error: ".$e->getMessage()."</p>";
}
?>