<?php
include_once '../models/conexion.php';
// Obtener el correo desde la URL
if (isset($_GET['correo'])) {
    $correo = $_GET['correo'];
} else {
    $correo = urldecode($_SERVER['QUERY_STRING']);
}

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo = $_POST['codigo'] ?? '';
    $correo = $_POST['correo'] ?? '';
    // Buscar usuario con ese correo y código
    $stmt = $conexion->prepare('SELECT * FROM usuario WHERE correo = :correo AND codigo_verificacion = :codigo');
    $stmt->execute([':correo' => $correo, ':codigo' => $codigo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($usuario) {
        // Actualizar campo verificado a true (boolean)
        $stmt = $conexion->prepare('UPDATE usuario SET verificado = true WHERE correo = :correo');
        $stmt->execute([':correo' => $correo]);
        // Redirigir a login.php
        header('Location: login.php?verificado=ok');
        exit;
    } else {
        $mensaje = '❌ Código incorrecto o correo no encontrado.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Verificar Código</title>
</head>
<body>
    <h2>Verificar Código</h2>
    <?php if ($mensaje) echo '<p>' . htmlspecialchars($mensaje) . '</p>'; ?>
    <form method="POST">
        <input type="hidden" name="correo" value="<?php echo htmlspecialchars($correo); ?>">
        <label>Código de verificación:</label>
        <input type="number" name="codigo" required>
        <button type="submit">Verificar</button>
    </form>
</body>
</html>