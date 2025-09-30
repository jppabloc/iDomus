<?php
include_once '../../models/conexion.php';
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
        .verify-card {
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
        .verify-title {
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
        input[type="text"] {
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
        input[type="text"]:focus {
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
        .verify-msg {
            width: 100%;
            text-align: center;
            margin-bottom: 12px;
            color: #d32f2f;
            font-size: 1.05rem;
            font-weight: 500;
        }
        @media (max-width: 500px) {
            .verify-card {
                padding: 18px 4vw 18px 4vw;
                border-radius: 0;
            }
            .verify-title {
                font-size: 1.3rem;
                margin-top: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="verify-card">
        <h2 class="verify-title">Verificar Código</h2>
        <?php if ($mensaje) echo '<p class="verify-msg">' . htmlspecialchars($mensaje) . '</p>'; ?>
        <form method="POST">
            <input type="hidden" name="correo" value="<?php echo htmlspecialchars($correo); ?>">
            <label>Código de verificación:</label>
            <input placeholder="codigo" type="number" name="codigo" required>
            <button type="submit">Verificar</button>
        </form>
    </div>
</body>
</html>