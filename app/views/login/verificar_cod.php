<?php
// app/views/login/verificar_cod.php
declare(strict_types=1);
session_start();
include_once '../../models/conexion.php';

/* ==== Config (mismas keys que en signup) ==== */
$APP_ENV           = getenv('APP_ENV') ?: 'dev';
$IDOMUS_BASE_URL   = getenv('IDOMUS_BASE_URL') ?: 'http://localhost/iDomus';
$MAIL_FROM         = getenv('MAIL_FROM') ?: 'no-reply@idomus.com';
$MAIL_REPLY_TO     = getenv('MAIL_REPLY_TO') ?: 'soporte@idomus.com';

const MAX_INTENTOS_VERIF = 5;
const LOCK_MINUTOS_VERIF = 10;
const NS_VERIF = 'idomus_verify_guard';

if (!isset($_SESSION[NS_VERIF])) {
  $_SESSION[NS_VERIF] = []; // por correo
}

/* ==== Utils rate limit ==== */
function slot_locked_v(array $slot): array {
  $now = time();
  $left = max(0, (int)($slot['lock_until'] ?? 0) - $now);
  return [$left > 0, $left];
}
function add_strike_v(string $correo): array {
  $now = time();
  $e = &$_SESSION[NS_VERIF][$correo];
  if (!is_array($e)) $e = ['intentos'=>0,'lock_until'=>0];
  $e['intentos']++;
  if ($e['intentos'] >= MAX_INTENTOS_VERIF) {
    $e['lock_until'] = $now + LOCK_MINUTOS_VERIF*60;
    $e['intentos']   = 0;
  }
  return $e;
}
function clear_strikes_v(string $correo): void {
  unset($_SESSION[NS_VERIF][$correo]);
}

/* ==== Obtener correo de forma robusta ==== */
$correo = filter_input(INPUT_GET, 'correo', FILTER_SANITIZE_EMAIL);
if (!$correo) {
  // Soporta el caso ?alguien@dominio.com sin clave
  $raw = urldecode($_SERVER['QUERY_STRING'] ?? '');
  if (filter_var($raw, FILTER_VALIDATE_EMAIL)) $correo = $raw;
}
$correo = $correo ? strtolower(trim($correo)) : '';

/* ==== Reenviar código (GET action=resend) ==== */
$swal = null;
if ($correo && isset($_GET['action']) && $_GET['action']==='resend') {
  try {
    // ¿Existe el usuario?
    $stmt = $conexion->prepare('SELECT iduser, nombre, correo FROM usuario WHERE correo=:c LIMIT 1');
    $stmt->execute([':c'=>$correo]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
      $swal = ['icon'=>'error','title'=>'No encontrado','text'=>'El correo no está registrado.'];
    } else {
      $codigo = random_int(100000, 999999);
      $up = $conexion->prepare('UPDATE usuario SET codigo_verificacion=:k WHERE correo=:c');
      $up->execute([':k'=>$codigo, ':c'=>$correo]);

      // Email
      $asunto = 'iDomus - Nuevo código de verificación';
      $verifUrl = rtrim($IDOMUS_BASE_URL,'/').'/app/views/login/verificar_cod.php?correo='.urlencode($correo);
      $html = '<html><body style="font-family:Arial,sans-serif">
        <div style="max-width:520px;margin:auto;background:#fff;border-radius:12px;padding:20px;
             box-shadow:0 6px 24px rgba(0,0,0,.08)">
          <h2 style="margin:0 0 10px;color:#023047">Nuevo código</h2>
          <p>Hola <b>'.htmlspecialchars($u['nombre']).'</b>, tu nuevo código es:</p>
          <div style="font-size:28px;letter-spacing:3px;color:#023047;margin:12px 0;"><b>'.$codigo.'</b></div>
          <p>Ingresa este código en la pantalla de verificación:</p>
          <p><a href="'.htmlspecialchars($verifUrl).'" 
             style="display:inline-block;background:#1BAAA6;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">
             Verificar ahora</a></p>
          <p style="color:#6c7a89;font-size:12px">Si no solicitaste este correo, ignóralo.</p>
        </div>
      </body></html>';

      $headers  = "MIME-Version: 1.0\r\n";
      $headers .= "Content-type: text/html; charset=UTF-8\r\n";
      $headers .= "From: iDomus <".$MAIL_FROM.">\r\n";
      $headers .= "Reply-To: ".$MAIL_REPLY_TO."\r\n";

      @mail($correo, $asunto, $html, $headers);

      $swal = ['icon'=>'success','title'=>'Código reenviado','text'=>'Revisa tu bandeja de entrada o spam.'];
    }
  } catch (Throwable $e) {
    $swal = ['icon'=>'error','title'=>'Ups','text'=>'No pudimos reenviar el código. Intenta más tarde.'];
  }
}

/* ==== POST: verificar código ==== */
$lock_seconds = 0;
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $correo = strtolower(trim($_POST['correo'] ?? ''));
  $codigo = trim($_POST['codigo'] ?? '');

  // Rate limit
  $slot = $_SESSION[NS_VERIF][$correo] ?? ['intentos'=>0,'lock_until'=>0];
  [$locked, $left] = slot_locked_v($slot);
  if ($locked) {
    $lock_seconds = $left;
    $mensaje = 'Demasiados intentos. Vuelve a intentarlo en '.gmdate('i\m s\s', $left).'.';
  } else {
    // Validaciones
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
      $mensaje = 'Correo inválido.';
    } elseif (!preg_match('/^\d{6}$/', $codigo)) {
      $mensaje = 'El código debe tener 6 dígitos.';
      add_strike_v($correo);
    } else {
      // Buscar y verificar
      $stmt = $conexion->prepare('SELECT iduser, codigo_verificacion FROM usuario WHERE correo=:c LIMIT 1');
      $stmt->execute([':c'=>$correo]);
      $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($usuario && hash_equals((string)$usuario['codigo_verificacion'], $codigo)) {
        // Marcar verificado
        $upd = $conexion->prepare("
          UPDATE usuario 
             SET verificado = TRUE,
                 codigo_verificacion = NULL
                 -- , fecha_verificado = NOW()
           WHERE correo = :c
        ");
        $upd->execute([':c'=>$correo]);

        clear_strikes_v($correo);
        header('Location: login.php?verificado=ok');
        exit;
      } else {
        $slot = add_strike_v($correo);
        [$l,$s] = slot_locked_v($slot);
        if ($l) {
          $lock_seconds = $s;
          $mensaje = 'Bloqueado temporalmente. Vuelve en '.gmdate('i\m s\s', $s).'.';
        } else {
          $restan = MAX_INTENTOS_VERIF - (int)$slot['intentos'];
          $mensaje = "Código incorrecto. Intentos restantes: {$restan}.";
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Verificar Código</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root { --accent:#1BAAA6; --dark:#0F3557; }
    html,body { width:100%; height:100%; margin:0; overflow-x:hidden; }
    *{ box-sizing:border-box; }
    body{
      min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px;
      background-image:
        repeating-linear-gradient(45deg,#bfc9d1 0 1px,transparent 1px 10px),
        repeating-linear-gradient(-45deg,#bfc9d1 0 1px,transparent 1px 10px);
      background-size:16px 16px; font-family:'Segoe UI', Arial, sans-serif;
    }
    .verify-card{
      background:#fff; border-radius:16px; width:clamp(300px, 92vw, 420px);
      padding:24px 20px; box-shadow:0 4px 24px rgba(0,0,0,.1); text-align:center; position:relative;
    }
    .verify-title{ font-size:1.6rem; color:var(--dark); margin:6px 0 6px; font-weight:800; }
    .hint{ color:#586b7a; font-size:.95rem; margin-bottom:8px; }
    form{ display:flex; flex-direction:column; gap:12px; margin-top:4px; }
    input[type="number"], input[type="text"]{
      width:100%; max-width:100%; height:48px;
      padding:12px; border:2px solid #bfc9d1; border-radius:10px; background:#f7fafd;
      font-size:1.05rem; text-align:center; letter-spacing:2px;
      transition:border .2s, box-shadow .25s ease;
    }
    input:focus{ border-color:var(--accent); box-shadow:0 0 6px rgba(27,170,166,.35), inset 0 0 2px rgba(27,170,166,.25); outline:none; }
    .actions{ display:flex; gap:10px; flex-wrap:wrap; justify-content:center; }
    .btn{
      appearance:none; border:none; cursor:pointer; border-radius:10px; padding:12px 14px; font-weight:700;
    }
    .btn-primary{ background:var(--dark); color:#fff; width:100%; }
    .btn-primary:hover{ background:var(--accent); }
    .btn-link{ background:transparent; color:var(--accent); text-decoration:underline; padding:6px 8px; font-weight:600; }
    .verify-msg{ color:#d32f2f; font-weight:600; margin:6px 0; }
    .small{ font-size:.9rem; color:#6c7a89; }
  </style>
</head>
<body>
  <div class="verify-card">
    <div class="verify-title">Verificar cuenta</div>
    <div class="hint">
      Hemos enviado un código de 6 dígitos a:
      <b><?php
        echo $correo ? htmlspecialchars(preg_replace('/(^.).*(@.*$)/','*$2',$correo)) : '—';
      ?></b>
    </div>

    <?php
      $countAttr = ($lock_seconds>0) ? ' data-left="'.$lock_seconds.'"' : '';
      if (!empty($mensaje)) {
        echo '<div class="verify-msg" id="msgLock"'.$countAttr.'>'.htmlspecialchars($mensaje);
        if ($lock_seconds>0) echo '<br><span class="small">Reintentar en <span id="countdown"></span></span>';
        echo '</div>';
      }
    ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="correo" value="<?= htmlspecialchars($correo) ?>">
      <input type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" name="codigo" placeholder="Ingresa tu código (6 dígitos)" required>
      <div class="actions">
        <button type="submit" class="btn btn-primary">Verificar</button>
        <?php if ($correo): ?>
          <a class="btn btn-link" href="?correo=<?= urlencode($correo) ?>&action=resend">Reenviar código</a>
        <?php endif; ?>
        <a class="btn btn-link" href="login.php">Volver al login</a>
      </div>
    </form>
  </div>

  <?php if ($swal): ?>
    <script>
      Swal.fire({icon:'<?= $swal['icon'] ?>', title:'<?= addslashes($swal['title']) ?>', text:'<?= addslashes($swal['text']) ?>'});
    </script>
  <?php endif; ?>

  <script>
    // Countdown bloqueo (si aplica)
    (function(){
      const msg = document.getElementById('msgLock');
      if (!msg) return;
      const left = parseInt(msg.getAttribute('data-left') || '0', 10);
      if (!left) return;
      const span = document.getElementById('countdown');
      let remain = left;
      function tick(){
        if (!span) return;
        const m = Math.floor(remain/60);
        const s = remain % 60;
        span.textContent = String(m).padStart(2,'0')+'m '+String(s).padStart(2,'0')+'s';
        if (remain>0){ remain--; setTimeout(tick,1000); }
      }
      tick();
    })();
  </script>
</body>
</html>