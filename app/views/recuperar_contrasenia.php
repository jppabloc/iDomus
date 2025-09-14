<?php
include_once '../models/conexion.php';
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $correo = $_POST['correo'] ?? '';
    // Verificar si el correo existe
    $stmt = $conexion->prepare('SELECT * FROM usuario WHERE correo = :correo');
    $stmt->execute([':correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($usuario) {
        // Generar código de recuperación
        $codigo = rand(100000, 999999);
        // Guardar el código en la base de datos
        $stmt = $conexion->prepare('UPDATE usuario SET codigo_verificacion = :codigo WHERE correo = :correo');
        $stmt->execute([':codigo' => $codigo, ':correo' => $correo]);
        // Enviar el código al correo
        $asunto = 'iDomus - Código de Recuperación';
        $mensajeCorreo = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Código de Recuperación</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
                .container { width: 100%; padding: 10px; text-align: center; }
                .email-content { background: #fff; max-width: 500px; margin: auto; padding: 20px; 
                                 border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
                .email-header { background: #023047; color: white; padding: 15px; 
                                border-radius: 8px 8px 0 0; font-size: 20px; font-weight: bold; }
                .email-body { padding: 20px; color: #333; font-size: 16px; line-height: 1.5; }
                .verify-btn { display: inline-block; padding: 12px 24px; background: #fb8500; 
                              color: white; text-decoration: none; border-radius: 6px; font-size: 16px; margin-top: 15px; }
                .email-footer { font-size: 12px; color: #777; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="email-content">
                    <div class="email-header">
                        Tu código de recuperación iDomus: <br><strong>'.$codigo.'</strong>
                    </div>
                    <div class="email-body">
                        <h2>Hola, '.$usuario['nombre'].'</h2>
                        <p>Solicitaste recuperar tu contraseña en <b>iDomus</b>. Usa el código anterior para continuar.</p>
                        <a href="http://localhost/idomus/app/views/cambiar_contrasenia.php?correo='.urlencode($correo).'&codigo='.$codigo.'" class="verify-btn">Cambiar contraseña</a>
                        <p>Si no solicitaste este correo, puedes ignorarlo.</p>
                    </div>
                    <div class="email-footer">
                        &copy; '.date("Y").' iDomus. Todos los derechos reservados.
                    </div>
                </div>
            </div>
        </body>
        </html>';
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: iDomus <noreply@idomus.com>\r\n";
        $headers .= "Reply-To: soporte@idomus.com\r\n";
        mail($correo, $asunto, $mensajeCorreo, $headers);
    // Redirigir a cambiar_contrasenia.php solo con el correo
    header("Location: cambiar_contrasenia.php?correo=" . urlencode($correo));
    exit;
    } else {
        $mensaje = "❌ El correo no está registrado.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperar Contraseña</title>
</head>
<body>
    <h2>Recuperar Contraseña</h2>
    <?php if ($mensaje) echo '<p>' . htmlspecialchars($mensaje) . '</p>'; ?>
    <form method="POST">
        <label>Correo electrónico:</label>
        <input type="email" name="correo" required>
        <button type="submit">Enviar código</button>
    </form>
</body>
</html>