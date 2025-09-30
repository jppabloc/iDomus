<?php
include_once '../../models/conexion.php';
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
        .change-card {
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
        .change-title {
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
        input[type="text"], input[type="password"] {
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
        input[type="text"]:focus, input[type="password"]:focus {
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
        .change-msg {
            width: 100%;
            text-align: center;
            margin-bottom: 12px;
            color: #d32f2f;
            font-size: 1.05rem;
            font-weight: 500;
        }
        @media (max-width: 500px) {
            .change-card {
                padding: 18px 4vw 18px 4vw;
                border-radius: 0;
            }
            .change-title {
                font-size: 1.3rem;
                margin-top: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="change-card">
        <h2 class="change-title">Cambiar Contraseña</h2>
        <?php if ($mensaje) echo '<p class="change-msg">' . htmlspecialchars($mensaje) . '</p>'; ?>
        <form method="POST">
            <input type="hidden" name="correo" value="<?php echo htmlspecialchars($correo); ?>">
            <input type="hidden" name="codigo" value="<?php echo htmlspecialchars($codigo); ?>">
            <?php if (!$codigo_valido): ?>
                <label>Introduce el código de verificación enviado a tu correo:</label>
                <input type="text" name="codigo" value="" placeholder="codigo" required>
                <button type="submit">Validar código</button>
            <?php else: ?>
                <label>Nueva contraseña:</label>
                <input type="password" name="nueva_contrasena" placeholder="contrasenia" required>
                <label>Repetir contraseña:</label>
                <input type="password" name="repetir_contrasena" placeholder="repita contrasenia" required>
                <button type="submit">Cambiar contraseña</button>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>
