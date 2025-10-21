<?php
// app/views/login/login.php
declare(strict_types=1);
session_start();
include_once '../../models/conexion.php';

// ===== Seguridad / Rate limiting =====
const MAX_INTENTOS = 3;
const LOCK_MINUTOS = 10;
const SESSION_NS   = 'idomus_login_guard';

if (!isset($_SESSION[SESSION_NS])) {
  $_SESSION[SESSION_NS] = ['by_email'=>[], 'by_ip'=>[]];
}

$ip           = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ahora        = time();
$mensaje      = '';
$lock_seconds = 0;     // Para countdown en frontend

// === helpers de rate limit ===
function slot_locked(array $slot, int $now): array {
  $until = (int)($slot['lock_until'] ?? 0);
  $left  = max(0, $until - $now);
  return [$left > 0, $left];
}
function add_strike(string $email, string $ip, int $now): array {
  $e = &$_SESSION[SESSION_NS]['by_email'][$email];
  if (!is_array($e)) $e = ['intentos'=>0,'lock_until'=>0];
  $e['intentos']++;
  if ($e['intentos'] >= MAX_INTENTOS) {
    $e['lock_until'] = $now + LOCK_MINUTOS*60;
    $e['intentos']   = 0;
  }
  $pi = &$_SESSION[SESSION_NS]['by_ip'][$ip];
  if (!is_array($pi)) $pi = ['intentos'=>0,'lock_until'=>0];
  $pi['intentos']++;
  if ($pi['intentos'] >= MAX_INTENTOS+2) {
    $pi['lock_until'] = $now + LOCK_MINUTOS*60;
    $pi['intentos']   = 0;
  }
  return [$e,$pi];
}
function clear_strikes(string $email, string $ip): void {
  unset($_SESSION[SESSION_NS]['by_email'][$email]);
  unset($_SESSION[SESSION_NS]['by_ip'][$ip]);
}

// === helper: obtener nombre de rol del usuario ===
function get_user_role(PDO $db, int $iduser): string {
  $sql = 'SELECT r.nombre_rol
          FROM usuario_rol ur
          JOIN rol r ON r.idrol = ur.idrol
          WHERE ur.iduser = :id
          LIMIT 1';
  $st = $db->prepare($sql);
  $st->execute([':id'=>$iduser]);
  $rol = $st->fetchColumn();
  // Normalizamos: si dice "admin", "administrador" o "adm" => admin. Caso contrario, usuario.
  $rol = $rol ? strtolower(trim($rol)) : 'usuario';
  return in_array($rol, ['admin','administrador','adm'], true) ? 'admin' : 'usuario';
}

// ====== PROCESO LOGIN ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $correo   = trim((string)($_POST['correo'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  // (A) BYPASS ADMIN (modo dev), siempre ANTES DEL RATE-LIMIT
  if ($correo === 'admin@admin' && ($password === 'admin' || $password === 'password')) {
    session_regenerate_id(true);
    $_SESSION['iduser'] = 0;
    $_SESSION['nombre'] = 'Administrador';
    $_SESSION['rol']    = 'admin';
    clear_strikes($correo, $ip);
    header('Location: ../dashboard/dashboard.php');
    exit;
  }

  // (B) Revisar bloqueo por intentos
  $slotE = $_SESSION[SESSION_NS]['by_email'][$correo] ?? ['intentos'=>0,'lock_until'=>0];
  $slotI = $_SESSION[SESSION_NS]['by_ip'][$ip]       ?? ['intentos'=>0,'lock_until'=>0];
  [$lockE,$leftE] = slot_locked($slotE, $ahora);
  [$lockI,$leftI] = slot_locked($slotI, $ahora);

  if ($lockE || $lockI) {
    $lock_seconds = max($leftE, $leftI);
    $mensaje = "Demasiados intentos fallidos. Intenta nuevamente en ".gmdate('i\m s\s', $lock_seconds).".";
  } else {
    // (C) Login real contra la base
    $stmt = $conexion->prepare('
      SELECT iduser, nombre, correo, contrasena, verificado
      FROM usuario
      WHERE correo = :correo
      LIMIT 1
    ');
    $stmt->execute([':correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($password, $usuario['contrasena'])) {
      if (!empty($usuario['verificado'])) {
        session_regenerate_id(true);
        $id = (int)$usuario['iduser'];
        $_SESSION['iduser'] = $id;
        $_SESSION['nombre'] = $usuario['nombre'];
        $_SESSION['rol']    = get_user_role($conexion, $id);

        clear_strikes($correo, $ip);

        // (D) Redirección por rol (server-side)
        if (($_SESSION['rol'] ?? '') === 'admin') {
          header('Location: ../dashboard/dashboard.php');
        } else {
          header('Location: ../usuario/home.php');
        }
        exit;
      } else {
        $mensaje = 'Debes verificar tu cuenta antes de iniciar sesión.';
      }
    } else {
      // (E) Manejo de intentos fallidos
      [$e,$pi] = add_strike($correo, $ip, $ahora);
      [$lE,$sE] = slot_locked($e, $ahora);
      [$lI,$sI] = slot_locked($pi, $ahora);
      if ($lE || $lI) {
        $lock_seconds = max($sE, $sI);
        $mensaje = "Cuenta temporalmente bloqueada. Vuelve en ".gmdate('i\m s\s',$lock_seconds).".";
      } else {
        $restan = MAX_INTENTOS - (int)$e['intentos'];
        $mensaje = "Correo o contraseña incorrectos. Intentos restantes: $restan.";
      }
    }
  }
}

// Mensajes informativos por GET
if ((isset($_GET['verificado']) && $_GET['verificado'] === 'ok')) {
  $mensaje = 'Cuenta verificada. Ahora puedes iniciar sesión.';
}
if (isset($_GET['logout'])) {
  session_unset();
  session_destroy();
  header('Location: login.php');
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Login iDomus</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    :root { --accent:#1BAAA6; --dark:#0F3557; }
    html,body { height:100%; width:100%; margin:0; overflow-x:hidden; }
    * { box-sizing:border-box; }
    body{
      min-height:100vh; display:flex; align-items:center; justify-content:center;
      background-image:
        repeating-linear-gradient(45deg, #bfc9d1 0 1px, transparent 1px 10px),
        repeating-linear-gradient(-45deg, #bfc9d1 0 1px, transparent 1px 10px);
      background-size:16px 16px; font-family:'Segoe UI', Arial, sans-serif;
      padding:16px;
    }
    .login-card{
      background:#fff; border-radius:18px; box-shadow:0 4px 24px rgba(51,51,51,0.13);
      width:clamp(300px, 92vw, 380px); padding:28px 20px; text-align:center; position:relative;
    }
    .login-logo{
      width:64px; height:64px; border-radius:50%; background:#fff; object-fit:contain;
      border:3px solid var(--accent); box-shadow:0 0 14px rgba(27,170,166,.6);
      position:absolute; top:-32px; left:50%; transform:translateX(-50%);
    }
    .login-title{ margin-top:26px; margin-bottom:10px; font-size:1.6rem; color:var(--dark); font-weight:800; }
    .login-msg{ color:#d32f2f; margin:8px 0 6px; font-weight:600; }
    form { display:flex; flex-direction:column; gap:12px; text-align:left; }
    .input-group { position:relative; width:100%; }
    input[type="email"], input[type="password"], input[type="text"]{
      display:block; width:100%; max-width:100%;
      height:48px; padding:12px 44px 12px 12px;
      background:#f7fafd; border:2px solid #bfc9d1; border-radius:10px;
      font-size:1rem; line-height:1.2; transition:border .2s, box-shadow .25s ease;
    }
    input:focus{
      border-color:var(--accent);
      box-shadow:0 0 6px rgba(27,170,166,.35), inset 0 0 2px rgba(27,170,166,.25);
      outline:none;
    }
    .toggle-pass{
      position:absolute; right:10px; top:50%; transform:translateY(-50%);
      font-size:1.15rem; color:#555; cursor:pointer; user-select:none;
      width:28px; height:28px; display:flex; align-items:center; justify-content:center;
    }
    .toggle-pass:hover{ color:var(--accent); }
    button[type="submit"]{
      width:100%; max-width:100%; background:var(--dark); color:#fff; border:none; border-radius:10px;
      padding:12px; font-size:1rem; font-weight:700; cursor:pointer; transition:background .2s;
      box-shadow:0 2px 8px rgba(0,0,0,.08);
    }
    button[type="submit"]:hover{ background:var(--accent); }
    .forgot{ color:var(--accent); text-decoration:none; margin-top:6px; display:inline-block; }
    @media (max-width:500px){
      .login-card{ padding:22px 14px; border-radius:12px; }
      .login-logo{ width:54px; height:54px; top:-27px; }
    }
  </style>
</head>
<body>
  <div class="login-card">
    <a href="../../../index.php" aria-label="Ir a inicio"><img src="../../../public/img/iDomus_logo.png" alt="iDomus Logo" class="login-logo"></a>
    <div class="login-title">Login</div>

    <?php if ($mensaje): ?>
      <div class="login-msg" id="msgLock"<?= $lock_seconds ? ' data-left="'.$lock_seconds.'"' : '' ?>>
        <?= htmlspecialchars($mensaje) ?>
        <?php if ($lock_seconds > 0): ?>
          <br><small>Reintentar en <span id="countdown"></span></small>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" id="loginForm">
      <div class="input-group">
        <input type="email" name="correo" id="correo" placeholder="Correo" required>
      </div>
      <div class="input-group">
        <input type="password" name="password" id="password" placeholder="Contraseña" required>
        <i class="bi bi-eye toggle-pass" data-target="password" aria-label="Mostrar/Ocultar"></i>
      </div>
      <button type="submit" id="btnLogin">Ingresar</button>
    </form>

    <a class="forgot" href="recuperar_contrasenia.php">¿Olvidaste tu contraseña?</a>
  </div>

  <script>
    // Toggle de contraseña
    document.querySelectorAll('.toggle-pass').forEach(icon=>{
      icon.addEventListener('click', ()=>{
        const id = icon.getAttribute('data-target');
        const input = document.getElementById(id);
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !show);
        icon.classList.toggle('bi-eye-slash', show);
      });
    });

    // Evitar doble submit
    const form = document.getElementById('loginForm');
    const btn  = document.getElementById('btnLogin');
    if (form) form.addEventListener('submit', ()=>{ btn.disabled = true; btn.textContent='Verificando...'; });

    // Countdown bloqueo
    (function(){
      const msg = document.getElementById('msgLock');
      if (!msg) return;
      const left = parseInt(msg.getAttribute('data-left') || '0', 10);
      if (!left) return;
      const span = document.getElementById('countdown');
      let remain = left;
      function tick(){
        if (span){
          const m = Math.floor(remain/60), s = remain%60;
          span.textContent = m.toString().padStart(2,'0') + 'm ' + s.toString().padStart(2,'0') + 's';
        }
        if (remain > 0){ remain--; setTimeout(tick, 1000); }
      }
      tick();
    })();
  </script>
</body>
</html>