<?php
session_start();
include_once '../models/conexion.php';
$mensaje = '';
$nombre = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $correo = $_POST['correo'] ?? '';
    $password = $_POST['password'] ?? '';
    $stmt = $conexion->prepare('SELECT * FROM usuario WHERE correo = :correo');
    $stmt->execute([':correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($usuario && password_verify($password, $usuario['contrasena'])) {
        if ($usuario['verificado']) {
            $_SESSION['nombre'] = $usuario['nombre'];
            $nombre = $usuario['nombre'];
        } else {
            $mensaje = '❌ Debes verificar tu cuenta antes de iniciar sesión.';
        }
    } else {
        $mensaje = '❌ Correo o contraseña incorrectos.';
    }
}
if (isset($_GET['verificado']) && $_GET['verificado'] === 'ok') {
    $mensaje = '✅ Cuenta verificada correctamente. Ahora puedes iniciar sesión.';
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login iDomus</title>
</head>
<body>
    <h2>Login iDomus</h2>
    <?php if ($mensaje) echo '<p>' . htmlspecialchars($mensaje) . '</p>'; ?>
    <?php if ($nombre): ?>
        <div class="dropdown">
            <button><?php echo htmlspecialchars($nombre); ?> ▼</button>
            <div class="dropdown-content">
                <a href="#">Actualizar datos</a>
                <a href="?logout=1">Cerrar sesión</a>
            </div>
        </div>
    <?php else: ?>
        <form method="POST">
            <label>Correo:</label>
            <input type="email" name="correo" required><br>
            <label>Contraseña:</label>
            <input type="password" name="password" required><br>
            <button type="submit">Iniciar sesión</button>
        </form>
        <p>
            <a href="recuperar_contrasenia.php">Recuperar contraseña</a> |
            <a href="signup.php">Crear cuenta en iDomus</a>
        </p>
    <?php endif; ?>
</body>
</html>