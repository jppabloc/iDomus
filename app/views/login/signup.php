<?php
// app/views/login/signup.php
declare(strict_types=1);
include_once '../../models/conexion.php';

//www.google.com/recaptcha/admin/create

// ===== Config =====
$APP_ENV            = getenv('APP_ENV') ?: 'dev'; // 'dev' | 'prod'
$RECAPTCHA_SITE_KEY = getenv('RECAPTCHA_SITE_KEY') ?: '6LcOteArAAAAAII9hmrhYyZF_f2WR0w30P-iPHnU'; // TU site key (widget)
$RECAPTCHA_SECRET   = getenv('RECAPTCHA_SECRET')  ?: '6LcOteArAAAAAAFO8P4EiMRH9HTXhLfR_GxVxHP8';                                           // TU secret key (server)
$IDOMUS_BASE_URL    = rtrim(getenv('IDOMUS_BASE_URL') ?: 'http://localhost/iDomus', '/');
$MAIL_FROM          = getenv('MAIL_FROM')     ?: 'no-reply@idomus.com';
$MAIL_REPLY_TO      = getenv('MAIL_REPLY_TO') ?: 'soporte@idomus.com';

$swal = null;

// --- util: validar reCAPTCHA v2 checkbox ---
function validar_recaptcha_v2(string $secret, ?string $token, ?string $ip): array {
  if (!$secret) return ['ok'=>false, 'msg'=>'Falta RECAPTCHA_SECRET en el servidor.'];
  if (!$token)  return ['ok'=>false, 'msg'=>'Marca “No soy un robot”.'];

  $payload = http_build_query([
    'secret'   => $secret,
    'response' => $token,
    'remoteip' => $ip ?? ''
  ]);
  $url = 'https://www.google.com/recaptcha/api/siteverify';

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_TIMEOUT        => 8,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok'=>false, 'msg'=>"Error cURL: $err"];
  } else {
    $opts = ['http'=>[
      'header'=>"Content-type: application/x-www-form-urlencoded\r\n",
      'method'=>'POST',
      'content'=>$payload,
      'timeout'=>8,
    ]];
    $context = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) return ['ok'=>false, 'msg'=>'No se pudo contactar con Google reCAPTCHA.'];
  }

  $json = json_decode($raw, true);
  if (!is_array($json) || empty($json['success'])) {
    return ['ok'=>false, 'msg'=>'Verificación reCAPTCHA inválida.'];
  }
  return ['ok'=>true, 'msg'=>'OK'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // 1) reCAPTCHA obligatorio (como pediste)
  $token = $_POST['g-recaptcha-response'] ?? '';
  $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
  $rc    = validar_recaptcha_v2($RECAPTCHA_SECRET, $token, $ip);

  if (!$rc['ok']) {
    $swal = ['icon'=>'error','title'=>'reCAPTCHA','text'=>$rc['msg'],'redirect'=>null];
  } else {
    // 2) Campos
    $nombre    = trim($_POST['nombre']    ?? '');
    $apellido  = trim($_POST['apellido']  ?? '');
    $correo    = trim($_POST['correo']    ?? '');
    $password  = (string)($_POST['password']  ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    // 3) Validaciones servidor
    $errors = [];
    if ($nombre==='' || $apellido==='' || $correo==='') $errors[] = 'Completa todos los campos.';
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL))     $errors[] = 'Correo no válido.';
    if (strlen($password) < 6)                           $errors[] = 'Mínimo 6 caracteres.';
    if (!preg_match('/[A-Z]/', $password))               $errors[] = 'Al menos una mayúscula.';
    if (!preg_match('/[a-z]/', $password))               $errors[] = 'Al menos una minúscula.';
    if (!preg_match('/[0-9]/', $password))               $errors[] = 'Al menos un número.';
    if (!preg_match('/[^A-Za-z0-9]/', $password))        $errors[] = 'Al menos un carácter especial.';
    if ($password !== $password2)                        $errors[] = 'Las contraseñas no coinciden.';

    if ($errors) {
      $swal = ['icon'=>'error','title'=>'Datos inválidos','text'=>implode(' ', $errors),'redirect'=>null];
    } else {
      $passwordHash = password_hash($password, PASSWORD_BCRYPT);
      $codigo = random_int(100000, 999999);

      try {
        // 4) Insertar usuario y rol
        $stmt = $conexion->prepare("
          INSERT INTO usuario (nombre, apellido, correo, contrasena, codigo_verificacion)
          VALUES (:nombre, :apellido, :correo, :contrasena, :codigo)
          RETURNING iduser
        ");
        $stmt->execute([
          ':nombre'     => $nombre,
          ':apellido'   => $apellido,
          ':correo'     => $correo,
          ':contrasena' => $passwordHash,
          ':codigo'     => $codigo
        ]);
        $idUser = $stmt->fetchColumn();

        $stmtRol = $conexion->prepare("INSERT INTO usuario_rol (iduser, idrol) VALUES (:iduser, 1)");
        $stmtRol->execute([':iduser' => $idUser]);

        // 5) Email verificación
        $asunto   = 'iDomus - Verificación de Correo';
        $verifUrl = $IDOMUS_BASE_URL.'/app/views/login/verificar_cod.php?correo='.urlencode($correo);
        $html = '
        <!doctype html><html><head><meta charset="UTF-8">
        <style>
          body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px}
          .card{max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:20px;box-shadow:0 6px 24px rgba(0,0,0,.1)}
          .hdr{background:#023047;color:#fff;border-radius:10px;padding:16px;font-weight:bold;text-align:center}
          .code{font-size:28px;letter-spacing:3px;color:#023047;margin:16px 0;text-align:center}
          .btn{display:inline-block;background:#fb8500;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px}
          .foot{font-size:12px;color:#6c7a89;margin-top:18px;text-align:center}
        </style></head><body>
          <div class="card">
            <div class="hdr">Tu código de verificación</div>
            <p>Hola <b>'.htmlspecialchars($nombre,ENT_QUOTES).'</b>, gracias por registrarte en <b>iDomus</b>.</p>
            <div class="code">'.htmlspecialchars((string)$codigo,ENT_QUOTES).'</div>
            <p>Ingresa este código en la pantalla de verificación:</p>
            <p style="text-align:center;margin:18px 0;">
              <a class="btn" href="'.htmlspecialchars($verifUrl,ENT_QUOTES).'">Verificar cuenta</a>
            </p>
            <div class="foot">&copy; '.date('Y').' iDomus</div>
          </div>
        </body></html>';

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: iDomus <".$MAIL_FROM.">\r\n";
        $headers .= "Reply-To: ".$MAIL_REPLY_TO."\r\n";
        @mail($correo, $asunto, $html, $headers);

        $swal = [
          'icon'=>'success',
          'title'=>'¡Registro exitoso!',
          'text'=>'Te enviamos un código. Ingresa el código para activar tu cuenta.',
          'redirect'=>$verifUrl
        ];
      } catch (PDOException $e) {
        $msg = ($e->getCode()==='23505') ? 'El correo ya está registrado.' : ('Error: '.$e->getMessage());
        $swal = ['icon'=>'error','title'=>'Error en el registro','text'=>$msg,'redirect'=>null];
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Registro iDomus</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <!-- Google reCAPTCHA v2 checkbox -->
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <!-- Bootstrap Icons para el toggle (sin emojis) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css"/>

  <style>
    :root{ --accent:#1BAAA6; --dark:#0F3557; }
    html,body{ width:100%; overflow-x:hidden; }
    *,*::before,*::after{ box-sizing:border-box; }
    body{
      min-height:100vh; margin:0; display:flex; justify-content:center; align-items:center;
      font-family:'Segoe UI',Arial,sans-serif; background:#f0f4f8; padding:16px;
    }
    .signup-card{
      background:#fff; border-radius:16px;
      width:clamp(300px, 92vw, 440px);
      padding:clamp(16px, 4vw, 28px);
      box-shadow:0 4px 24px rgba(0,0,0,.1); text-align:center; position:relative;
    }
    .signup-logo{
      width:72px; height:72px; margin-bottom:14px; border-radius:50%;
      border:3px solid var(--accent); box-shadow:0 0 16px rgba(27,170,166,.6);
      object-fit:cover; background:#fff;
    }
    .signup-title{ font-size:1.6rem; color:var(--dark); margin-bottom:12px; font-weight:800; }
    form{ display:flex; flex-direction:column; gap:12px; }

    .input-group{ position:relative; }
    .input-group input{
      width:100%; padding:12px 44px 12px 12px; background:#f7fafd;
      border:2px solid #bfc9d1; border-radius:10px; font-size:1rem;
      transition:border .2s, box-shadow .25s ease;
      box-shadow:0 0 0.5px rgba(27,170,166,.25), inset 0 0 0.5px rgba(27,170,166,.15);
    }
    .input-group input:focus{
      border-color:var(--accent);
      box-shadow:0 0 6px rgba(27,170,166,.35), inset 0 0 2px rgba(27,170,166,.25);
      outline:none;
    }
    .toggle-pass{
      position:absolute; right:10px; top:50%; transform:translateY(-50%);
      font-size:1.1rem; color:#555; cursor:pointer;
    }
    .toggle-pass:hover{ color:var(--accent); }

    .g-recaptcha{ display:flex; justify-content:center; }

    button[type="submit"]{
      width:100%; background:var(--dark); color:#fff; border:none;
      border-radius:10px; padding:12px; font-size:1rem; font-weight:700;
      cursor:pointer; transition:background .2s; box-shadow:0 2px 8px rgba(0,0,0,.08);
    }
    button[type="submit"]:hover{ background:var(--accent); }
    .forgot{ margin-top:10px; color:var(--accent); text-decoration:none; display:inline-block; }
  </style>
</head>
<body>
  <div class="signup-card">
    <img src="../../../public/img/iDomus_logo.png" alt="iDomus Logo" class="signup-logo">
    <div class="signup-title">Registro</div>

    <form method="POST" action="signup.php" onsubmit="return validarFormulario()">
      <div class="input-group"><input type="text" name="nombre" placeholder="Nombre" required></div>
      <div class="input-group"><input type="text" name="apellido" placeholder="Apellido" required></div>
      <div class="input-group"><input type="email" name="correo" placeholder="Correo" required></div>

      <div class="input-group">
        <input type="password" name="password" id="password" placeholder="Contraseña" required minlength="6">
        <i class="bi bi-eye toggle-pass" data-target="password" aria-label="Mostrar/Ocultar"></i>
      </div>
      <div class="input-group">
        <input type="password" name="password2" id="password2" placeholder="Repetir contraseña" required minlength="6">
        <i class="bi bi-eye toggle-pass" data-target="password2" aria-label="Mostrar/Ocultar"></i>
      </div>

      <!-- reCAPTCHA v2 checkbox (obligatorio) -->
      <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>"></div>

      <button type="submit">Registrarse</button>
    </form>

    <a class="forgot" href="../login/login.php">¿Ya tienes cuenta? Inicia sesión</a>
  </div>

  <script>
    // Toggle ojo (sin emojis)
    document.querySelectorAll('.toggle-pass').forEach(icon=>{
      icon.addEventListener('click', ()=>{
        const id = icon.getAttribute('data-target');
        const input = document.getElementById(id);
        if(!input) return;
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        icon.classList.toggle('bi-eye', showing);
        icon.classList.toggle('bi-eye-slash', !showing);
      });
    });

    // Validación cliente (UX extra)
    function validarFormulario(){
      const p1 = document.getElementById('password').value;
      const p2 = document.getElementById('password2').value;

      if(p1.length < 6){ Swal.fire({icon:'error',title:'Contraseña muy corta',text:'Mínimo 6 caracteres.'}); return false; }
      if(!/[A-Z]/.test(p1)){ Swal.fire({icon:'error',title:'Falta mayúscula',text:'Incluye al menos una letra mayúscula.'}); return false; }
      if(!/[a-z]/.test(p1)){ Swal.fire({icon:'error',title:'Falta minúscula',text:'Incluye al menos una letra minúscula.'}); return false; }
      if(!/[0-9]/.test(p1)){ Swal.fire({icon:'error',title:'Falta número',text:'Incluye al menos un número.'}); return false; }
      if(!/[^A-Za-z0-9]/.test(p1)){ Swal.fire({icon:'error',title:'Falta carácter especial',text:'Incluye al menos un carácter especial.'}); return false; }
      if(p1 !== p2){ Swal.fire({icon:'error',title:'No coinciden',text:'Las contraseñas deben ser iguales.'}); return false; }

      // Google insertará el textarea con el token (si no está, el submit fallará en servidor).
      return true;
    }

    // SweetAlert desde PHP
    <?php if ($swal): ?>
      Swal.fire({
        icon: '<?= $swal['icon'] ?>',
        title: '<?= addslashes($swal['title']) ?>',
        text: '<?= addslashes($swal['text']) ?>',
        confirmButtonText: 'Aceptar'
      }).then(()=>{ <?php if(!empty($swal['redirect'])): ?> window.location.href='<?= $swal['redirect'] ?>'; <?php endif; ?> });
    <?php endif; ?>
  </script>
</body>
</html>