<?php
// app/views/login/cambiar_contrasena.php
declare(strict_types=1);
session_start();
include_once '../../models/conexion.php';

// ====== Config ======
const PWD_REGEX = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{6,}$/'; // 6+ con may/min/número/especial
const MAX_TRIES_CODE = 5;       // Intentos para validar código
const TRIES_WINDOW_S = 10 * 60; // 10 min de ventana

// ====== Guard de rate limiting por correo ======
if (!isset($_SESSION['reset_guard'])) {
  $_SESSION['reset_guard'] = []; // [email => ['count'=>int,'first'=>timestamp]]
}

function register_try(string $email): void {
  $g = &$_SESSION['reset_guard'][$email];
  $now = time();
  if (!is_array($g) || ($now - ($g['first'] ?? 0)) > TRIES_WINDOW_S) {
    $g = ['count'=>0,'first'=>$now];
  }
  $g['count']++;
}
function tries_left(string $email): int {
  $g = $_SESSION['reset_guard'][$email] ?? null;
  if (!$g) return MAX_TRIES_CODE;
  if ((time() - ($g['first'] ?? 0)) > TRIES_WINDOW_S) return MAX_TRIES_CODE;
  return max(0, MAX_TRIES_CODE - (int)$g['count']);
}

// ====== Estado inicial ======
$correo        = trim($_GET['correo'] ?? ($_POST['correo'] ?? ''));
$correo        = filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : '';
$stage         = $_POST['stage'] ?? 'code'; // 'code' | 'reset'
$codigo_valido = false;
$mensaje       = '';
$swal          = null;

// ====== POST ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($stage === 'code') {
    // Validar código
    $codigo = trim((string)($_POST['codigo'] ?? ''));
    if (!$correo) {
      $mensaje = '❌ Falta el correo. Abre el enlace desde tu email.';
    } elseif (!ctype_digit($codigo) || strlen($codigo) > 10) {
      $mensaje = '❌ Código inválido.';
      register_try($correo);
    } else {
      // Rate limit
      if (tries_left($correo) <= 0) {
        $mensaje = '⚠️ Demasiados intentos. Intenta nuevamente en unos minutos.';
      } else {
        $stmt = $conexion->prepare('SELECT iduser FROM usuario WHERE correo = :correo AND codigo_verificacion = :codigo');
        $stmt->execute([':correo'=>$correo, ':codigo'=>$codigo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($usuario) {
          // Éxito: pasamos a etapa reset (en la misma request)
          $codigo_valido = true;
          $stage = 'reset';
          // Persistimos el código y correo en inputs ocultos
        } else {
          $mensaje = '❌ Código de verificación incorrecto o expirado.';
          register_try($correo);
        }
      }
    }
  } elseif ($stage === 'reset') {
    // Cambiar contraseña (revalidamos correo+codigo por seguridad)
    $codigo            = trim((string)($_POST['codigo'] ?? ''));
    $nueva             = (string)($_POST['nueva_contrasena'] ?? '');
    $repetir           = (string)($_POST['repetir_contrasena'] ?? '');

    if (!$correo) {
      $mensaje = '❌ Falta el correo. Abre el enlace desde tu email.';
    } elseif (!ctype_digit($codigo)) {
      $mensaje = '❌ Código inválido.';
    } elseif (empty($nueva) || empty($repetir)) {
      $mensaje = '❌ Debes completar ambos campos de contraseña.';
      $codigo_valido = true; // mantenemos la vista de reset
    } elseif ($nueva !== $repetir) {
      $mensaje = '❌ Las contraseñas no coinciden.';
      $codigo_valido = true;
    } elseif (!preg_match(PWD_REGEX, $nueva)) {
      $mensaje = '❌ La contraseña debe tener al menos 6 caracteres e incluir mayúsculas, minúsculas, números y un carácter especial.';
      $codigo_valido = true;
    } else {
      // Revalidar código
      $stmt = $conexion->prepare('SELECT iduser FROM usuario WHERE correo = :correo AND codigo_verificacion = :codigo');
      $stmt->execute([':correo'=>$correo, ':codigo'=>$codigo]);
      $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($usuario) {
        $hash = password_hash($nueva, PASSWORD_BCRYPT);
        $up = $conexion->prepare('UPDATE usuario SET contrasena = :pwd, codigo_verificacion = NULL WHERE correo = :correo');
        $up->execute([':pwd'=>$hash, ':correo'=>$correo]);

        // Limpieza de rate limiting
        unset($_SESSION['reset_guard'][$correo]);

        $swal = [
          'icon' => 'success',
          'title'=> 'Contraseña actualizada',
          'text' => 'Ya puedes iniciar sesión con tu nueva contraseña.',
          'redirect' => 'login.php?recuperacion=ok'
        ];
      } else {
        $mensaje = '❌ Código de verificación incorrecto o expirado.';
      }
    }
  }
} else {
  // GET plano: si viene correo, mantenemos stage=code
  $stage = 'code';
}

// Si venimos de un POST code exitoso, marcamos bandera para renderizar el formulario de reset
if ($stage === 'reset') {
  $codigo_valido = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Cambiar Contraseña</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- SweetAlert2 + Bootstrap Icons -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

  <style>
    :root { --accent:#1BAAA6; --dark:#0F3557; }
    html, body { width:100%; overflow-x:hidden; }
    *, *::before, *::after { box-sizing:border-box; }

    body {
      min-height: 100vh;
      margin: 0; padding: 16px;
      display: flex; justify-content: center; align-items: center;
      font-family: 'Segoe UI', Arial, sans-serif;
      background:
        repeating-linear-gradient(45deg, #bfc9d1 0 1px, transparent 1px 10px),
        repeating-linear-gradient(-45deg, #bfc9d1 0 1px, transparent 1px 10px);
      background-size: 16px 16px;
    }
    .card {
      background:#fff; border-radius:16px; width:clamp(300px,92vw,420px);
      padding: clamp(16px, 4vw, 28px); box-shadow:0 4px 24px rgba(0,0,0,.1);
      position:relative; text-align:center;
    }
    h2 {
      font-size:1.6rem; color:var(--dark); margin:4px 0 18px; font-weight:800;
    }
    form { display:flex; flex-direction:column; gap:12px; width:100%; text-align:left; }
    .input-group { position:relative; width:100%; }
    input[type="text"], input[type="number"], input[type="password"] {
      width:100%; max-width:100%; height:48px;
      padding:12px 44px 12px 12px;
      background:#f7fafd; border:2px solid #bfc9d1; border-radius:10px;
      font-size:1rem; transition:border .2s, box-shadow .25s ease;
    }
    input:focus {
      border-color:var(--accent);
      box-shadow:0 0 6px rgba(27,170,166,.35), inset 0 0 2px rgba(27,170,166,.25);
      outline:none;
    }
    .toggle-pass {
      position:absolute; right:10px; top:50%; transform:translateY(-50%);
      width:28px; height:28px; display:flex; align-items:center; justify-content:center;
      font-size:1.15rem; color:#555; cursor:pointer; user-select:none;
    }
    .toggle-pass:hover { color:var(--accent); }
    .msg {
      color:#d32f2f; margin:0 0 6px; font-weight:600; text-align:center;
    }
    button[type="submit"] {
      width:100%; background:var(--dark); color:#fff;
      border:none; border-radius:10px; padding:12px;
      font-size:1rem; font-weight:700; cursor:pointer;
      transition:background .2s; box-shadow:0 2px 8px rgba(0,0,0,.08);
    }
    button[type="submit"]:hover { background:var(--accent); }
    small.helper { color:#607d8b; display:block; margin-top:-6px; }
  </style>
</head>
<body>
  <div class="card">
    <h2>Cambiar Contraseña</h2>

    <?php if ($mensaje): ?>
      <div class="msg"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if (!$codigo_valido): ?>
      <!-- Etapa 1: validar código -->
      <form method="POST" autocomplete="off">
        <input type="hidden" name="stage" value="code">
        <div class="input-group">
          <input type="text" name="correo" placeholder="Tu correo" value="<?= htmlspecialchars($correo) ?>" required>
        </div>
        <div class="input-group">
          <input type="text" name="codigo" placeholder="Código de verificación" inputmode="numeric" pattern="\d*" maxlength="10" required>
        </div>
        <?php
          $left = $correo ? tries_left($correo) : MAX_TRIES_CODE;
          if ($left < MAX_TRIES_CODE) {
            echo '<small class="helper">Intentos restantes para este correo: '.(int)$left.'</small>';
          }
        ?>
        <button type="submit">Validar código</button>
      </form>
    <?php else: ?>
      <!-- Etapa 2: establecer nueva contraseña -->
      <form method="POST" autocomplete="off" id="formReset">
        <input type="hidden" name="stage"  value="reset">
        <input type="hidden" name="correo" value="<?= htmlspecialchars($correo) ?>">
        <input type="hidden" name="codigo" value="<?= htmlspecialchars($_POST['codigo'] ?? '') ?>">

        <div class="input-group">
          <input type="password" name="nueva_contrasena" id="pwd1" placeholder="Nueva contraseña" required>
          <i class="bi bi-eye toggle-pass" data-target="pwd1" aria-label="Mostrar/Ocultar"></i>
        </div>
        <div class="input-group">
          <input type="password" name="repetir_contrasena" id="pwd2" placeholder="Repetir contraseña" required>
          <i class="bi bi-eye toggle-pass" data-target="pwd2" aria-label="Mostrar/Ocultar"></i>
        </div>
        <small class="helper">Mín. 6 caracteres, incluir mayúscula, minúscula, número y símbolo.</small>

        <button type="submit" id="btnReset">Cambiar contraseña</button>
      </form>
    <?php endif; ?>
  </div>

  <script>
    // Toggle mostrar/ocultar
    document.querySelectorAll('.toggle-pass').forEach(icon=>{
      icon.addEventListener('click', ()=>{
        const id = icon.getAttribute('data-target');
        const input = document.getElementById(id);
        if (!input) return;
        const toText = input.type === 'password';
        input.type = toText ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !toText);
        icon.classList.toggle('bi-eye-slash', toText);
      });
    });

    // Validación básica en cliente (etapa reset)
    const formReset = document.getElementById('formReset');
    if (formReset) {
      const btn = document.getElementById('btnReset');
      formReset.addEventListener('submit', (e)=>{
        const p1 = document.getElementById('pwd1').value;
        const p2 = document.getElementById('pwd2').value;
        const re = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{6,}$/;
        if (!re.test(p1)) {
          e.preventDefault();
          Swal.fire({icon:'error',title:'Contraseña insegura',text:'Debe tener 6+ caracteres, con mayúscula, minúscula, número y símbolo.'});
          return;
        }
        if (p1 !== p2) {
          e.preventDefault();
          Swal.fire({icon:'error',title:'No coinciden',text:'Las contraseñas deben ser iguales.'});
          return;
        }
        btn.disabled = true;
        btn.textContent = 'Actualizando...';
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