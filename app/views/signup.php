<?php
  include_once '../models/conexion.php';
  if ($_SERVER["REQUEST_METHOD"] == "POST") {
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
                          <a href="https://verificar_cod.php?correo=" . urlencode($correo)" class="verify-btn">Verificar Cuenta</a>
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
              header("Location: verificar_cod.php?correo=" . urlencode($correo));
              exit;
          } catch (PDOException $e) {
              $error = "❌ Error en el registro: " . $e->getMessage();
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
  <!-- recaptcha -->
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
    <h2>signup</h2>
    <?php if (isset($error)) { echo '<div style="color:red;">' . $error . '</div>'; } ?>
    <form id="signupForm" method="POST" action="signup.php" onsubmit="return validarFormulario()">
    <label>Nombre:</label>
    <input type="text" name="nombre" required>
    <br>
    <label>Apellido:</label>
    <input type="text" name="apellido" required>
    <br>
    <label>Email:</label>
    <input type="email" name="correo" required>
    <br>
    <label>Contraseña:</label>
    <input type="password" name="password" id="password" required minlength="6">
    <input type="checkbox" onclick="togglePassword('password')"> Mostrar contraseña
    <br>
    <label>Repetir contraseña:</label>
    <input type="password" name="password2" id="password2" required minlength="6">
    <input type="checkbox" onclick="togglePassword('password2')"> Mostrar contraseña
    <br>
    <!-- reCAPTCHA -->
    <div class="form-group">
        <div class="g-recaptcha" data-sitekey="6LdKk-gqAAAAAD9mEchU4Ai9-sAQTzDzIzTcHwWO">
        </div>
    </div>
    <button type="submit">Registrarse</button>
  </form>

    <script>
        // Mostrar/ocultar contraseña para cualquier campo
        function togglePassword(inputId) {
            let pass = document.getElementById(inputId);
            pass.type = (pass.type === "password") ? "text" : "password";
        }

        // Validaciones extra del formulario
        function validarFormulario() {
            let passInput = document.getElementById("password");
            let pass2Input = document.getElementById("password2");
            let pass = passInput.value;
            let pass2 = pass2Input.value;
            let recaptchaElem = document.getElementsByName('g-recaptcha-response')[0];
            let recaptcha = recaptchaElem ? recaptchaElem.value.trim() : '';

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
                            if (!recaptcha) {
                                alert("❌ Debes marcar el recaptcha de Google antes de continuar.");
              e.preventDefault();
              return;
            }

            return true;  
        }
    </script>
</body>
</html>