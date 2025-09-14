<?php
include_once '../models/conexion.php';
$mensaje = '';
$correo = $_GET['correo'] ?? '';
$codigo = '';
$codigo_valido = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $correo = $_POST['correo'] ?? '';
    $codigo = $_POST['codigo'] ?? '';
    // Si viene el campo nueva_contrasena, es porque ya se validó el código
    if (isset($_POST['nueva_contrasena'])) {
        $nueva_contrasena = $_POST['nueva_contrasena'] ?? '';
        $repetir_contrasena = $_POST['repetir_contrasena'] ?? '';
        if (empty($nueva_contrasena) || empty($repetir_contrasena)) {
            $mensaje = '❌ Debes completar ambos campos de contraseña.';
            $codigo_valido = true;
        } elseif ($nueva_contrasena !== $repetir_contrasena) {
            $mensaje = '❌ Las contraseñas no coinciden.';
            $codigo_valido = true;
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_+\-=\[\]{};:\\|,.<>\/?]).{6,}$/', $nueva_contrasena)) {
            $mensaje = '❌ La contraseña debe tener al menos 6 caracteres, incluir mayúsculas, minúsculas, números y un carácter especial.';
            $codigo_valido = true;
        } else {
            // Verificar código
            $stmt = $conexion->prepare('SELECT * FROM usuario WHERE correo = :correo AND codigo_verificacion = :codigo');
            $stmt->execute([':correo' => $correo, ':codigo' => $codigo]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($usuario) {
                // Actualizar contraseña (encriptada)
                $hash = password_hash($nueva_contrasena, PASSWORD_DEFAULT);
                $stmt = $conexion->prepare('UPDATE usuario SET contrasena = :contrasena, codigo_verificacion = NULL WHERE correo = :correo');
                $stmt->execute([':contrasena' => $hash, ':correo' => $correo]);
                header('Location: login.php?recuperacion=ok');
                exit;
            } else {
                $mensaje = '❌ Código de verificación incorrecto o expirado.';
            }
        }
    } else {
        // Solo validar el código
        $stmt = $conexion->prepare('SELECT * FROM usuario WHERE correo = :correo AND codigo_verificacion = :codigo');
        $stmt->execute([':correo' => $correo, ':codigo' => $codigo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($usuario) {
            $codigo_valido = true;
        } else {
            $mensaje = '❌ Código de verificación incorrecto o expirado.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cambiar Contraseña</title>
</head>
<body>
    <h2>Cambiar Contraseña</h2>
    <?php if ($mensaje) echo '<p>' . htmlspecialchars($mensaje) . '</p>'; ?>
    <form method="POST">
        <input type="hidden" name="correo" value="<?php echo htmlspecialchars($correo); ?>">
        <input type="hidden" name="codigo" value="<?php echo htmlspecialchars($codigo); ?>">
        <?php if (!$codigo_valido): ?>
            <label>Introduce el código de verificación enviado a tu correo:</label>
            <input type="text" name="codigo" value="" required><br>
            <button type="submit">Validar código</button>
        <?php else: ?>
            <label>Nueva contraseña:</label>
            <input type="password" name="nueva_contrasena" required><br>
            <label>Repetir contraseña:</label>
            <input type="password" name="repetir_contrasena" required><br>
            <button type="submit">Cambiar contraseña</button>
        <?php endif; ?>
    </form>
</body>
</html>
