<?php
// Generar un PIN de 6 dígitos
$pin = rand(100000, 999999);
$nombre = "Usuario Ejemplo";
$email = "dipsadayde@necub.com";
$asunto = 'mail verification';
$mensaje = '
<!DOCTYPE html>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correo de Verificación</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }

        .container {
            width: 100%;
            padding: 10px;
            text-align: center;
        }

        .email-content {
            background-color: #fff;
            max-width: 500px;
            margin: auto;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .email-header {
            background-color: #023047;
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            font-size: 20px;
            font-weight: bold;
        }

        .email-body {
            padding: 20px;
            color: #333;
            font-size: 16px;
            line-height: 1.5;
        }

        .verify-btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #fb8500;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 16px;
            margin-top: 15px;
        }

        .email-footer {
            font-size: 12px;
            color: #777;
            margin-top: 20px;
        }
        .logo-img{
          height: 70px;
          width: 70px;
          float: right;
          border-radius: 50%;
          margin-top: -10px
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="email-content">
            <div class="email-header">
                Código de Verificación: <br><strong>'.$pin.'</strong>
            </div>
            <div class="email-body">
                <h2>Hola, '.$nombre.'</h2>
                <p>Gracias por registrarte en nuestro sitio. Por favor, haz clic en el botón de abajo para verificar tu correo electrónico.</p>
                <a href="#" class="verify-btn">Verificar Cuenta</a>
                <p>Si no solicitaste este correo, simplemente ignóralo.</p>
            </div>
            <div class="email-footer">
                &copy; 2024 BicentenarioBoLive. Todos los derechos reservados.
            </div>
        </div>
    </div>
</body>
</html>
';
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= 'From: '.$email.' ' . "\r\n" . 'Reply-To: j.pablo.xyz@gmail.com ';
$headers .= 'test o pruebas' . "\r\n";    
if(mail($email, $asunto, $mensaje, $headers)) {
  echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
    echo "Correo enviado";
    echo "<script>
      Swal.fire({
            title: '¡Éxito!',
            text: 'Datos guardados. Revisa tu correo para ver el PIN de verificación.',
            icon: 'success',
            showConfirmButton: false,
            timer: 1500
        }).then(() => {
            window.location.href = 'verify_pin.php';
        });
    </script>";
}
else{
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
    echo "Error al enviar correo";
    echo "<script>
      Swal.fire({
            title: '¡Error!',
            text: 'No se pudo enviar el correo de verificación.',
            icon: 'error',
            showConfirmButton: false,
            timer: 1500
        }).then(() => {
            window.location.href = '../../index.php';
        });
    </script>";
}
?>