<?php
  include_once '../models/conexion.php';
  if ($_SERVER["REQUEST_METHOD"] == "POST") {
      // Validar reCAPTCHA de Google del lado del servidor
      $recaptchaSecret = '6Le4VckrAAAAALFhjEBwqo_44tyiWIVP7Mw5UM0H'; // Cambia por tu clave secreta real
      $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
      $recaptchaUrl = 'https://www.google.com/recaptcha/api/siteverify';
      $recaptchaData = [
          'secret' => '6LcUlskrAAAAAIp6yHKWDVPhje4L-La9HYgfy6ff', // Cambia por tu clave secreta real
          'response' => $recaptchaResponse,
          'remoteip' => $_SERVER['REMOTE_ADDR']
      ];
      $options = [
          'http' => [
              'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
              'method'  => 'POST',
              'content' => http_build_query($recaptchaData),
          ]
      ];
      $context  = stream_context_create($options);
      $result = file_get_contents($recaptchaUrl, false, $context);
      $res = json_decode($result, true);
      // DEPURAR: Mostrar el token recibido y la respuesta de Google
      if (!isset($res['success']) || !$res['success']) {
          echo '<pre>';
          echo 'g-recaptcha-response: ';
          var_dump($recaptchaResponse);
          echo "\nRespuesta de Google:\n";
          var_dump($res);
          echo '</pre>';
          echo '<script>alert("❌ Debes completar el reCAPTCHA correctamente o revisar la configuración de tu clave secreta y dominio."); window.history.back();</script>';
          exit;
      } else {
          $nombre = $_POST['nombre'];
          $apellido = $_POST['apellido'];
          $correo = $_POST['correo'];
          $password = $_POST['password'];
          $password2 = $_POST['password2'];

          // Validar que las contraseñas coinciden
          if ($password !== $password2) {
              $error = "❌ Las contraseñas no coinciden.";
          } else {

              // Encriptar contraseña
              $passwordHash = password_hash($password, PASSWORD_BCRYPT);

              // Generar código de verificación
              $codigo = rand(100000, 999999);

              try {
                  // Insertar usuario
                  $stmt = $conexion->prepare("INSERT INTO usuario 
                      (nombre, apellido, correo, contrasena, codigo_verificacion) 
                      VALUES (:nombre, :apellido, :correo, :contrasena, :codigo) RETURNING idUser");

                  $stmt->execute([
                      ':nombre' => $nombre,
                      ':apellido' => $apellido,
                      ':correo' => $correo,
                      ':contrasena' => $passwordHash,
                      ':codigo' => $codigo
                  ]);

                  $idUser = $stmt->fetchColumn();

                  // Asignar rol "usuario" por defecto
                  $stmtRol = $conexion->prepare("INSERT INTO usuario_rol (idUser, idRol) VALUES (:idUser,   1)");
                  $stmtRol->execute([':idUser' => $idUser]);

                  // enviando el codigo al correo
                  $asunto = 'iDomus - Verificación de Correo';
                  $mensaje = '
                  <!DOCTYPE html>
                  <html lang="es">
                  <head>
                      <meta charset="UTF-8">
                      <meta name="viewport" content="width=device-width, initial-scale=1.0">
                      <title>Correo de Verificación</title>
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
                                        Tu código de verificación iDomus: <br><strong>'.$codigo.'</strong>
                                    </div>
                                    <div class="email-body">
                                        <h2>Hola, '.$nombre.'</h2>
                                        <p>Gracias por registrarte en <b>iDomus</b>. Usa el código anterior para verificar tu cuenta.</p>
                                        <a href="http://localhost/idomus/app/views/verificar_cod.php?'.$correo.'" class="verify-btn">Verificar Cuenta</a>
                                        <p>Si no solicitaste este correo, puedes ignorarlo.</p>
                              </div>
                              <div class="email-footer">
                                  &copy; '.date("Y").' iDomus. Todos los derechos reservados.
                              </div>
                          </div>
                      </div>
                  </body>
                  </html>';

                  // Cabeceras del correo
                  $headers = "MIME-Version: 1.0" . "\r\n";
                  $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                  $headers .= 'From: '.$correo.' ' . "\r\n" . 'Reply-To: j.pablo.xyz@gmail.com ';
                  $headers .= 'test o pruebas' . "\r\n"; 
                  if(mail($correo, $asunto, $mensaje, $headers))
                    echo "Correo enviado correctamente a $correo";
                  else
                    echo "Error al enviar el correo a $correo";
                  
                  // Redirigir a la página de verificación SOLO si todo fue exitoso y sin salida previa
                  header("Location: http://localhost/idomus/app/views/verificar_cod.php?$correo");
                  exit;
              } catch (PDOException $e) {
                  $error = "❌ Error en el registro: " . $e->getMessage();
              }
          }
      }
  }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>signup</title>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
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
    .signup-card {
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
    .signup-logo {
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
    .signup-title {
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
    input[type="text"], input[type="email"], input[type="password"] {
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
    input[type="text"]:focus, input[type="email"]:focus, input[type="password"]:focus {
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
    .signup-msg {
      width: 100%;
      text-align: center;
      margin-bottom: 12px;
      color: #d32f2f;
      font-size: 1.05rem;
      font-weight: 500;
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
    @media (max-width: 500px) {
      .signup-card {
        padding: 18px 4vw 18px 4vw;
        border-radius: 0;
      }
      .signup-title {
        font-size: 1.3rem;
        margin-top: 38px;
      }
      .signup-logo {
        width: 54px;
        height: 54px;
        top: -27px;
      }
    }
  </style>
</head>
<body>
  <div class="signup-card">
    <img src="../../public/img/iDomus_logo.png" alt="iDomus Logo" class="signup-logo">
    <div class="signup-title">Registro</div>
    <?php if (isset($error)) { echo '<div class="signup-msg">' . $error . '</div>'; } ?>
    <form id="signupForm" method="POST" action="signup.php" onsubmit="return validarFormulario()">
      <input type="text" name="nombre" placeholder="Nombre" required>
      <input type="text" name="apellido" placeholder="Apellido" required>
      <input type="email" name="correo" placeholder="Email" required>
      <input type="password" name="password" id="password" placeholder="Contraseña" required minlength="6">
      <input type="checkbox" onclick="togglePassword('password')"> Mostrar contraseña
      <input type="password" name="password2" id="password2" placeholder="Repetir contraseña" required minlength="6">
      <input type="checkbox" onclick="togglePassword('password2')"> Mostrar contraseña
      <div class="form-group">
        <div class="g-recaptcha" data-sitekey="6LcUlskrAAAAAHDH6-o6ZXwe6ap0JqGD1ckQHbZI"></div>
      </div>
      <button type="submit">Registrarse</button>
    </form>
    <a class="forgot" href="login.php">¿Ya tienes cuenta? Inicia sesión</a>
  </div>
  <script>
        // Mostrar/ocultar contraseña para cualquier campo
        function togglePassword(inputId) {
            let pass = document.getElementById(inputId);
            pass.type = (pass.type === "password") ? "text" : "password";
        }

        function validarFormulario() {
            // Obtener campos
            let nombre = document.getElementsByName("nombre")[0];
            let apellido = document.getElementsByName("apellido")[0];
            let correo = document.getElementsByName("correo")[0];
            let passInput = document.getElementById("password");
            let pass2Input = document.getElementById("password2");
            let recaptchaElem = document.getElementsByName('g-recaptcha-response')[0];
            let recaptcha = recaptchaElem ? recaptchaElem.value.trim() : '';

            // Validar campos vacíos
            if (!nombre.value.trim()) {
                alert("❌ Debes ingresar tu nombre.");
                nombre.focus();
                return false;
            }
            if (!apellido.value.trim()) {
                alert("❌ Debes ingresar tu apellido.");
                apellido.focus();
                return false;
            }
            if (!correo.value.trim()) {
                alert("❌ Debes ingresar tu correo electrónico.");
                correo.focus();
                return false;
            }
            if (!passInput.value.trim()) {
                alert("❌ Debes ingresar una contraseña.");
                passInput.focus();
                return false;
            }
            if (!pass2Input.value.trim()) {
                alert("❌ Debes repetir la contraseña.");
                pass2Input.focus();
                return false;
            }

            let pass = passInput.value;
            let pass2 = pass2Input.value;

            // Validar longitud mínima
            if (pass.length < 6) {
                alert("❌ La contraseña debe tener al menos 6 caracteres.");
                passInput.focus();
                return false;
            }

            // Validar mayúscula
            if (!/[A-Z]/.test(pass)) {
                alert("❌ La contraseña debe incluir al menos una letra mayúscula.");
                passInput.focus();
                return false;
            }
            // Validar minúscula
            if (!/[a-z]/.test(pass)) {
                alert("❌ La contraseña debe incluir al menos una letra minúscula.");
                passInput.focus();
                return false;
            }
            // Validar número
            if (!/[0-9]/.test(pass)) {
                alert("❌ La contraseña debe incluir al menos un número.");
                passInput.focus();
                return false;
            }
            // Validar carácter especial
            if (!/[^A-Za-z0-9]/.test(pass)) {
                alert("❌ La contraseña debe incluir al menos un carácter especial.");
                passInput.focus();
                return false;
            }

            // Validar coincidencia
            if (pass !== pass2) {
                alert("❌ Las contraseñas no coinciden.");
                pass2Input.focus();
                return false;
            }

            // Verificar que reCAPTCHA esté completado
            function onClick(e) {
              e.preventDefault();
              grecaptcha.enterprise.ready(async () => {
                const token = await grecaptcha.enterprise.execute('6Le4VckrAAAAALFhjEBwqo_44tyiWIVP7Mw5UM0H', {action: 'LOGIN'});
              });
            }

            return true;
        }
    </script>
</body>
</html>