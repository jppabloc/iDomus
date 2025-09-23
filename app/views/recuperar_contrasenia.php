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
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            background: #fff;
            background-image:
                repeating-linear-gradient(45deg, #bfc9d1 0 1px, transparent 1px 10px),
                repeating-linear-gradient(-45deg, #bfc9d1 0 1px, transparent 1px 10px);
            background-size: 16px 16px;
            font-family: 'Segoe UI', Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .recover-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 4px 24px rgba(51,51,51,0.13);
            max-width: 370px;
            width: 95vw;
            margin: 60px auto 0 auto;
            padding: 36px 28px 28px 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }
        .recover-title {
            margin-top: 8px;
            margin-bottom: 24px;
            font-size: 2rem;
            color: #0F3557;
            font-family: 'Comic Sans MS', 'Segoe UI', Arial, sans-serif;
            text-align: center;
            font-weight: 700;
            letter-spacing: 1.5px;
        }
        form {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        label {
            display: none;
        }
        input[type="email"] {
            width: 100%;
            padding: 12px 14px;
            margin-bottom: 16px;
            border: 2px solid #bfc9d1;
            border-radius: 10px;
            font-size: 1.08rem;
            background: #f7fafd;
            color: #333;
            outline: none;
            transition: border 0.2s;
        }
        input[type="email"]:focus {
            border: 2px solid #1BAAA6;
        }
        button[type="submit"] {
            width: 100%;
            background: #0F3557;
            color: #1BAAA6;
            border: none;
            border-radius: 10px;
            padding: 13px 0;
            font-size: 1.15rem;
            font-weight: 700;
            margin-top: 8px;
            margin-bottom: 18px;
            cursor: pointer;
            box-shadow: 0 2px 8px #bfc9d1;
            transition: background 0.2s, color 0.2s;
        }
        button[type="submit"]:hover {
            background: #1BAAA6;
            color: #fff;
        }
        .recover-msg {
            width: 100%;
            text-align: center;
            margin-bottom: 12px;
            color: #d32f2f;
            font-size: 1.05rem;
            font-weight: 500;
        }
        @media (max-width: 500px) {
            .recover-card {
                padding: 18px 4vw 18px 4vw;
                border-radius: 0;
            }
            .recover-title {
                font-size: 1.3rem;
                margin-top: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="recover-card">
        <div class="recover-title">Recuperar Contraseña</div>
        <?php if ($mensaje) echo '<div class="recover-msg">' . htmlspecialchars($mensaje) . '</div>'; ?>
        <form method="POST">
            <label>Correo electrónico:</label>
            <input type="email" name="correo" placeholder="correo" required>
            <button type="submit">Enviar código</button>
        </form>
    </div>
</body>
</html>