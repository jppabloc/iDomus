<?php
// app/views/login/recuperar_contrasenia.php
declare(strict_types=1);
session_start();
include_once '../../models/conexion.php';

// ===== Config (puedes mover a env) =====
$IDOMUS_BASE_URL = getenv('IDOMUS_BASE_URL') ?: 'http://localhost/iDomus';
$MAIL_FROM       = getenv('MAIL_FROM') ?: 'noreply@idomus.com';
$MAIL_REPLY_TO   = getenv('MAIL_REPLY_TO') ?: 'soporte@idomus.com';

// ===== Rate limiting simple en sesión =====
const RESET_NS         = 'reset_request_guard';
const MAX_TRIES        = 5;       // por ventana
const WINDOW_SECONDS   = 10*60;   // 10 minutos
const CODE_TTL_MIN     = 15;      // expira en 15 min (si tienes columna en DB)

if (!isset($_SESSION[RESET_NS])) {
  $_SESSION[RESET_NS] = ['by_email'=>[], 'by_ip'=>[]];
}
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$now = time();

function guard_add_try(string $key, string $bucket='by_email'): void {
  $g = &$_SESSION[RESET_NS][$bucket][$key];
  global $now;
  if (!is_array($g) || ($now - ($g['first'] ?? 0)) > WINDOW_SECONDS) {
    $g = ['count'=>0, 'first'=>$now];
  }
  $g['count']++;
}
function guard_left(string $key, string $bucket='by_email'): int {
  $g = $_SESSION[RESET_NS][$bucket][$key] ?? null;
  global $now;
  if (!$g || ($now - ($g['first'] ?? 0)) > WINDOW_SECONDS) return MAX_TRIES;
  return max(0, MAX_TRIES - (int)$g['count']);
}

$swal = null; // para SweetAlert
$mensaje_local = ''; // por si quieres mostrar inline (no necesario con swal)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $correo = trim($_POST['correo'] ?? '');
  $correo_valid = filter_var($correo, FILTER_VALIDATE_EMAIL);

  // Limites
  if (guard_left($correo, 'by_email') <= 0 || guard_left($ip, 'by_ip') <= 0) {
    // Mismo mensaje, sin revelar nada
    $swal = [
      'icon' => 'info',
      'title'=> 'Revisa tu bandeja',
      'text' => 'Si el correo existe, te enviamos un código. Si no lo ves, revisa spam.',
      'redirect' => 'cambiar_contrasenia.php?correo='.urlencode($correo)
    ];
  } elseif (!$correo_valid) {
    // No sumamos intentos por typo evidente
    $swal = [
      'icon' => 'error',
      'title'=> 'Correo inválido',
      'text' => 'Ingresa un correo válido.',
      'redirect' => null
    ];
  } else {
    // Incrementamos contadores
    guard_add_try($correo, 'by_email');
    guard_add_try($ip, 'by_ip');

    // Buscamos usuario, pero NO revelamos si existe
    $stmt = $conexion->prepare('SELECT iduser, nombre FROM usuario WHERE correo = :correo');
    $stmt->execute([':correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generamos código siempre (para no filtrar timing). Solo guardamos si existe.
    $codigo = random_int(100000, 999999);

    if ($usuario) {
      // Si tienes columna de expiración, descomenta y ajusta (Postgres):
      // $expira = (new DateTime('+'.CODE_TTL_MIN.' minutes'))->format('Y-m-d H:i:s');
      // $up = $conexion->prepare('UPDATE usuario SET codigo_verificacion = :codigo, codigo_expira_at = :expira WHERE correo = :correo');
      // $up->execute([':codigo'=>$codigo, ':expira'=>$expira, ':correo'=>$correo]);

      $up = $conexion->prepare('UPDATE usuario SET codigo_verificacion = :codigo WHERE correo = :correo');
      $up->execute([':codigo'=>$codigo, ':correo'=>$correo]);

      // Email
      $verifUrl = rtrim($IDOMUS_BASE_URL,'/').'/app/views/login/cambiar_contrasenia.php?correo='.urlencode($correo).'&codigo='.$codigo;

      $mensajeCorreo = '
      <!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Código de Recuperación</title>
        <style>
          body { font-family: Arial, sans-serif; background:#f4f6f8; margin:0; padding:20px; }
          .card { max-width:520px; margin:0 auto; background:#fff; border-radius:12px; padding:20px;
                  box-shadow:0 6px 24px rgba(0,0,0,0.08); }
          .hdr  { background:#023047; color:#fff; border-radius:10px; padding:16px; font-weight:bold; text-align:center }
          .code { font-size:28px; letter-spacing:3px; color:#023047; margin:16px 0; text-align:center }
          .btn  { display:inline-block; background:#fb8500; color:#fff; text-decoration:none;
                  padding:12px 18px; border-radius:8px }
          .foot { font-size:12px; color:#6c7a89; margin-top:18px; text-align:center }
        </style>
      </head>
      <body>
        <div class="card">
          <div class="hdr">Tu código de recuperación iDomus</div>
          <p>Hola <b>'.htmlspecialchars($usuario['nombre']).'</b>, recibimos una solicitud para restablecer tu contraseña.</p>
          <div class="code">'.htmlspecialchars((string)$codigo).'</div>
          <p>Ingresa este código en la pantalla de cambio de contraseña o haz clic aquí:</p>
          <p style="text-align:center;margin:18px 0;">
            <a class="btn" href="'.htmlspecialchars($verifUrl).'">Cambiar contraseña</a>
          </p>
          <p>Si no solicitaste este correo, puedes ignorarlo.</p>
          <div class="foot">&copy; '.date('Y').' iDomus</div>
        </div>
      </body>
      </html>';

      $headers  = "MIME-Version: 1.0\r\n";
      $headers .= "Content-type: text/html; charset=UTF-8\r\n";
      $headers .= "From: iDomus <".$MAIL_FROM.">\r\n";
      $headers .= "Reply-To: ".$MAIL_REPLY_TO."\r\n";
      @mail($correo, 'iDomus - Código de Recuperación', $mensajeCorreo, $headers);
    }

    // Mensaje uniforme (anti-enumeración) + redirect a cambiar_contrasenia
    $swal = [
      'icon' => 'info',
      'title'=> 'Revisa tu bandeja',
      'text' => 'Si el correo existe, te enviamos un código. Si no lo ves, revisa spam.',
      'redirect' => 'cambiar_contrasenia.php?correo='.urlencode($correo)
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Recuperar Contraseña</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root { --accent:#1BAAA6; --dark:#0F3557; }
    html,body{ width:100%; overflow-x:hidden; }
    *,*::before,*::after{ box-sizing:border-box; }

    body {
      min-height:100vh; margin:0; padding:16px;
      display:flex; align-items:center; justify-content:center;
      font-family:'Segoe UI', Arial, sans-serif;
      background:
        repeating-linear-gradient(45deg, #bfc9d1 0 1px, transparent 1px 10px),
        repeating-linear-gradient(-45deg, #bfc9d1 0 1px, transparent 1px 10px);
      background-size:16px 16px;
    }
    .recover-card {
      background:#fff; border-radius:16px;
      width:clamp(300px, 92vw, 420px);
      padding:clamp(16px, 4vw, 28px);
      box-shadow:0 4px 24px rgba(0,0,0,0.1);
      text-align:center;
    }
    .recover-title {
      font-size:1.6rem; color:var(--dark); margin:4px 0 18px; font-weight:800;
    }
    form { display:flex; flex-direction:column; gap:12px; width:100%; text-align:left; }
    input[type="email"] {
      width:100%; max-width:100%; height:48px;
      padding:12px; background:#f7fafd; border:2px solid #bfc9d1; border-radius:10px;
      font-size:1rem; transition:border .2s, box-shadow .25s ease;
    }
    input[type="email"]:focus {
      border-color:var(--accent);
      box-shadow:0 0 6px rgba(27,170,166,.35), inset 0 0 2px rgba(27,170,166,.25);
      outline:none;
    }
    button[type="submit"]{
      width:100%; background:var(--dark); color:#fff; border:none; border-radius:10px;
      padding:12px; font-size:1rem; font-weight:700; cursor:pointer;
      transition:background .2s; box-shadow:0 2px 8px rgba(0,0,0,.08);
    }
    button[type="submit"]:hover{ background:var(--accent); }
    .helper { color:#607d8b; font-size:.92rem; margin-top:-4px; }
  </style>
</head>
<body>
  <div class="recover-card">
    <div class="recover-title">Recuperar Contraseña</div>

    <form method="POST" autocomplete="off" id="formRecover">
      <label for="correo" class="visually-hidden">Correo electrónico</label>
      <input type="email" id="correo" name="correo" placeholder="Tu correo" required>
      <?php
        // Si quieres mostrar intentos restantes (opcional, puede delatar ritmo): 
        // $leftE = guard_left($_POST['correo'] ?? '', 'by_email');
        // $leftI = guard_left($ip, 'by_ip');
        // echo '<div class="helper">Intentos restantes (email/IP): '.(int)$leftE.' / '.(int)$leftI.'</div>';
      ?>
      <button type="submit" id="btnSend">Enviar código</button>
    </form>
  </div>

  <script>
    // UX: deshabilita doble submit
    const f = document.getElementById('formRecover');
    const b = document.getElementById('btnSend');
    if (f) {
      f.addEventListener('submit', ()=>{
        if (b){ b.disabled = true; b.textContent = 'Enviando...'; }
      });
    }
  </script>

  <?php if ($swal): ?>
  <script>
    Swal.fire({
      icon: '<?= $swal['icon'] ?>',
      title: '<?= addslashes($swal['title']) ?>',
      text: '<?= addslashes($swal['text']) ?>',
      confirmButtonText: 'Aceptar'
    }).then(()=>{
      <?php if (!empty($swal['redirect'])): ?>
      window.location.href = '<?= $swal['redirect'] ?>';
      <?php endif; ?>
    });
  </script>
  <?php endif; ?>
</body>
</html>