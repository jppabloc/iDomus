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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        .login-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 4px 24px rgba(51,51,51,0.13);
            max-width: 350px;
            width: 95vw;
            margin: 60px auto 0 auto;
            padding: 36px 28px 28px 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }
        .login-logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 2px 8px #1BAAA6, 0 0 16px #0F3557 inset;
            border: 3px solid #1BAAA6;
            position: absolute;
            top: -35px;
            left: 50%;
            transform: translateX(-50%);
            filter: drop-shadow(0 0 8px #1BAAA6);
        }
        .login-title {
            margin-top: 48px;
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
        input[type="email"], input[type="password"] {
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
        input[type="email"]:focus, input[type="password"]:focus {
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
        .forgot {
            color: #1BAAA6;
            text-align: center;
            font-size: 1.05rem;
            margin-top: 0;
            margin-bottom: 0;
            text-decoration: none;
            display: block;
            font-family: 'Comic Sans MS', 'Segoe UI', Arial, sans-serif;
            border-bottom: 2px dashed #1BAAA6;
            width: fit-content;
            margin-left: auto;
            margin-right: auto;
            padding-bottom: 2px;
            transition: color 0.2s, border-color 0.2s;
        }
        .forgot:hover {
            color: #0F3557;
            border-color: #0F3557;
        }
        .login-msg {
            width: 100%;
            text-align: center;
            margin-bottom: 12px;
            color: #d32f2f;
            font-size: 1.05rem;
            font-weight: 500;
        }
        @media (max-width: 500px) {
            .login-card {
                padding: 18px 4vw 18px 4vw;
                border-radius: 0;
            }
            .login-title {
                font-size: 1.3rem;
                margin-top: 38px;
            }
            .login-logo {
                width: 54px;
                height: 54px;
                top: -27px;
            }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="../../public/img/iDomus_logo.png" alt="iDomus Logo" class="login-logo">
        <div class="login-title">Login</div>
        <?php if ($mensaje) echo '<div class="login-msg">' . htmlspecialchars($mensaje) . '</div>'; ?>
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
                <input type="email" name="correo" placeholder="Correo" required>
                <input type="password" name="password" placeholder="Contraseña" required>
                <button type="submit">Ingresar</button>
            </form>
            <a class="forgot" href="recuperar_contrasenia.php">Olvidaste tu contraseña.</a>
        <?php endif; ?>
    </div>
</body>
</html>