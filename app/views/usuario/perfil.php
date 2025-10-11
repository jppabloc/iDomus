<?php
// app/views/usuario/perfil.php
declare(strict_types=1);
session_start();
include_once '../../models/conexion.php';

// ===== Guard de sesión =====
if (!isset($_SESSION['iduser'])) {
  header('Location: ../login/login.php');
  exit;
}
$iduser  = (int)$_SESSION['iduser'];
$nombreS = $_SESSION['nombre'] ?? 'Usuario';
$rolS    = $_SESSION['rol']    ?? 'usuario';

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$swal = null;

// ===== Helpers =====
function is_admin(): bool { return ($_SESSION['rol'] ?? '') === 'admin'; }

// ===== Cargar datos del usuario =====
$stmt = $conexion->prepare("
  SELECT u.iduser, u.nombre, u.apellido, u.correo, 
         CASE WHEN EXISTS(SELECT 1 FROM information_schema.columns 
                          WHERE table_name='usuario' AND column_name='telefono') 
              THEN u.telefono ELSE NULL END AS telefono
  FROM usuario u
  WHERE u.iduser = :id
");
$stmt->execute([':id'=>$iduser]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) {
  // Usuario borrado? limpiar sesión.
  session_unset(); session_destroy();
  header('Location: ../login/login.php');
  exit;
}

// ===== Rol actual del usuario (para mostrar en la vista) =====
$stmt = $conexion->prepare("
  SELECT r.idrol, r.nombre_rol
  FROM usuario_rol ur
  JOIN rol r ON r.idrol = ur.idrol
  WHERE ur.iduser = :id
  LIMIT 1
");
$stmt->execute([':id'=>$iduser]);
$myRoleRow = $stmt->fetch(PDO::FETCH_ASSOC);
$myRoleId  = $myRoleRow['idrol'] ?? null;
$myRole    = $myRoleRow['nombre_rol'] ?? $rolS;

// ===== Si es admin, cargar catálogo de roles =====
$roles = [];
if (is_admin()) {
  $rs = $conexion->query("SELECT idrol, nombre_rol FROM rol ORDER BY idrol ASC");
  $roles = $rs->fetchAll(PDO::FETCH_ASSOC);
}

// ===== POST handlers =====
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  $token  = $_POST['csrf']   ?? '';
  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $swal = ['icon'=>'error','title'=>'CSRF inválido','text'=>'Vuelve a cargar la página.'];
  } else {

    // === Actualizar datos básicos ===
    if ($action === 'update_profile') {
      $nombre   = trim($_POST['nombre']   ?? '');
      $apellido = trim($_POST['apellido'] ?? '');
      $telefono = trim($_POST['telefono'] ?? '');

      if ($nombre === '' || $apellido === '') {
        $swal = ['icon'=>'error','title'=>'Datos incompletos','text'=>'Nombre y apellido son obligatorios.'];
      } else {
        // Intentar actualizar con teléfono si existe la columna
        try {
          $conexion->beginTransaction();

          // Revisar si existe columna telefono
          $colQ = $conexion->query("
            SELECT 1 FROM information_schema.columns 
            WHERE table_name='usuario' AND column_name='telefono'
          ");
          $hasTelefono = (bool)$colQ->fetchColumn();

          if ($hasTelefono) {
            $sql = "UPDATE usuario SET nombre=:n, apellido=:a, telefono=:t WHERE iduser=:id";
            $stmt = $conexion->prepare($sql);
            $stmt->execute([':n'=>$nombre, ':a'=>$apellido, ':t'=>$telefono, ':id'=>$iduser]);
          } else {
            $sql = "UPDATE usuario SET nombre=:n, apellido=:a WHERE iduser=:id";
            $stmt = $conexion->prepare($sql);
            $stmt->execute([':n'=>$nombre, ':a'=>$apellido, ':id'=>$iduser]);
          }

          $conexion->commit();

          // Refrescar sesión nombre
          $_SESSION['nombre'] = $nombre;
          $me['nombre'] = $nombre;
          $me['apellido'] = $apellido;
          $me['telefono'] = $telefono;

          $swal = ['icon'=>'success','title'=>'Perfil actualizado','text'=>'Tus datos han sido guardados.'];
          $swal['redirect'] = '../usuario/home.php';
        } catch (PDOException $e) {
          $conexion->rollBack();
          $swal = ['icon'=>'error','title'=>'Error','text'=>'No se pudo guardar: '.$e->getMessage()];
        }
      }
    }

    // === Cambio de contraseña ===
    if ($action === 'change_password') {
      $actual = (string)($_POST['pass_actual'] ?? '');
      $nueva  = (string)($_POST['pass_nueva']  ?? '');
      $repet  = (string)($_POST['pass_repet']  ?? '');

      // Traer hash actual
      $stmt = $conexion->prepare("SELECT contrasena FROM usuario WHERE iduser=:id");
      $stmt->execute([':id'=>$iduser]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $hashActual = $row['contrasena'] ?? '';

      if (!$row || !password_verify($actual, $hashActual)) {
        $swal = ['icon'=>'error','title'=>'Contraseña actual inválida','text'=>'Verifica tu contraseña actual.'];
      } else {
        // Validaciones de complejidad
        $errs = [];
        if (strlen($nueva)<6)                 $errs[]='Mínimo 6 caracteres.';
        if (!preg_match('/[A-Z]/',$nueva))    $errs[]='Al menos una mayúscula.';
        if (!preg_match('/[a-z]/',$nueva))    $errs[]='Al menos una minúscula.';
        if (!preg_match('/[0-9]/',$nueva))    $errs[]='Al menos un número.';
        if (!preg_match('/[^A-Za-z0-9]/',$nueva)) $errs[]='Al menos un carácter especial.';
        if ($nueva !== $repet)                $errs[]='La confirmación no coincide.';

        if ($errs) {
          $swal = ['icon'=>'error','title'=>'Contraseña inválida','text'=>implode(' ', $errs)];
        } else {
          $newHash = password_hash($nueva, PASSWORD_BCRYPT);
          $stmt = $conexion->prepare("UPDATE usuario SET contrasena = :h WHERE iduser = :id");
          $stmt->execute([':h'=>$newHash, ':id'=>$iduser]);
          $swal = ['icon'=>'success','title'=>'Contraseña actualizada','text'=>'Tu contraseña fue cambiada.'];
          $swal['redirect'] = '../usuario/home.php';
        }
      }
    }

    // === Cambio de rol (solo admin sobre sí mismo) ===
    if ($action === 'change_role' && is_admin()) {
      $nuevoRol = (int)($_POST['idrol'] ?? 0);
      if ($nuevoRol <= 0) {
        $swal = ['icon'=>'error','title'=>'Rol inválido','text'=>'Selecciona un rol válido.'];
      } else {
        try {
          $conexion->beginTransaction();

          // ¿Existe ya usuario_rol?
          $stmt = $conexion->prepare("SELECT 1 FROM usuario_rol WHERE iduser=:id LIMIT 1");
          $stmt->execute([':id'=>$iduser]);
          $exists = (bool)$stmt->fetchColumn();

          if ($exists) {
            $stmt = $conexion->prepare("UPDATE usuario_rol SET idrol=:r WHERE iduser=:id");
            $stmt->execute([':r'=>$nuevoRol, ':id'=>$iduser]);
          } else {
            $stmt = $conexion->prepare("INSERT INTO usuario_rol (iduser, idrol) VALUES (:id, :r)");
            $stmt->execute([':id'=>$iduser, ':r'=>$nuevoRol]);
          }

          // Actualizar nombre de rol para la vista y sesión
          $stmt = $conexion->prepare("SELECT nombre_rol FROM rol WHERE idrol=:r");
          $stmt->execute([':r'=>$nuevoRol]);
          $newName = $stmt->fetchColumn() ?: 'usuario';

          $_SESSION['rol'] = ($newName === 'admin') ? 'admin' : $newName;

          $conexion->commit();

          $swal = ['icon'=>'success','title'=>'Rol actualizado','text'=>'Tu rol fue actualizado a: '.$newName];
        } catch (PDOException $e) {
          $conexion->rollBack();
          $swal = ['icon'=>'error','title'=>'Error','text'=>'No se pudo actualizar el rol: '.$e->getMessage()];
        }
      }
    }

  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Mi perfil</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --dark:#0F3557; --acc:#1BAAA6;}
    body{font-family:'Segoe UI',Arial,sans-serif;background:#f5f7fb;}
    .navbar{background:var(--dark);}
    .navbar-brand{color:#fff;font-weight:800;letter-spacing:.5px;display:flex;align-items:center;gap:.5rem;}
    .brand-logo{width:40px;height:40px;border-radius:50%;background:#fff;border:2px solid var(--acc);box-shadow:0 0 10px rgba(27,170,166,.55);}
    .nav-link{color:#fff;font-weight:600;}
    .nav-link:hover,.nav-link.active{color:var(--acc);}
    .navbar-toggler{border:none;box-shadow:none;}
    .navbar-toggler-icon{
      background-image:url("data:image/svg+xml;utf8,<svg viewBox='0 0 32 32' xmlns='http://www.w3.org/2000/svg'><rect y='6' width='32' height='4' rx='2' fill='%23ffffff'/><rect y='14' width='32' height='4' rx='2' fill='%23ffffff'/><rect y='22' width='32' height='4' rx='2' fill='%23ffffff'/></svg>");
    }
    .avatar-icon{
      width:36px;height:36px;border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      background:linear-gradient(135deg,var(--acc),#18a19c);
      color:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.25);font-size:1.2rem;
    }
    .user-chip{display:flex;align-items:center;gap:.6rem;color:#fff;font-weight:700;text-decoration:none;}
    .card-domus{border:0;border-radius:14px;box-shadow:0 6px 18px rgba(15,53,87,.08);background:#fff;}
    .form-control:focus{border-color:var(--acc);box-shadow:0 0 0 .2rem rgba(27,170,166,.15);}
    .btn-domus{background:var(--dark);color:#fff;border:none;}
    .btn-domus:hover{background:var(--acc);}
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top">
  <div class="container-xxl">
    <a class="navbar-brand" href="../../public/index.php">
      <img src="../../../public/img/iDomus_logo.png" class="brand-logo" alt="iDomus">
      <span>iDomus</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="./home.php">Inicio</a></li>
        <li class="nav-item"><a class="nav-link" href="./reservas.php">Reservas</a></li>
        <li class="nav-item"><a class="nav-link" href="./pagos.php">Pagos</a></li>
      </ul>

      <div class="dropdown">
        <a class="user-chip dropdown-toggle" href="#" data-bs-toggle="dropdown">
          <span class="avatar-icon"><i class="bi bi-person-fill"></i></span>
          <span><?= htmlspecialchars($nombreS) ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li class="dropdown-header small text-muted">Rol: <?= htmlspecialchars($_SESSION['rol']) ?></li>
          <li><a class="dropdown-item" href="./perfil.php"><i class="bi bi-person-badge me-2"></i>Mi perfil</a></li>
          <li><a class="dropdown-item" href="../login/cambiar_contrasenia.php"><i class="bi bi-key me-2"></i>Cambiar contraseña</a></li>
          <?php if (is_admin()): ?>
            <li><a class="dropdown-item" href="../dashboard/dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li>
            <form action="../login/login.php" method="get" class="px-3 m-0">
              <input type="hidden" name="logout" value="1">
              <button class="btn btn-sm btn-outline-danger w-100" type="submit">
                <i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión
              </button>
            </form>
          </li>
        </ul>
      </div>
    </div>

  </div>
</nav>

<main class="container-xxl my-4">
  <div class="row g-4">

    <!-- Card: Datos básicos -->
    <div class="col-12 col-lg-6">
      <div class="card card-domus p-3 p-md-4">
        <h5 class="mb-3">Datos del perfil</h5>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="update_profile">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nombre</label>
              <input class="form-control" name="nombre" value="<?= htmlspecialchars($me['nombre'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Apellido</label>
              <input class="form-control" name="apellido" value="<?= htmlspecialchars($me['apellido'] ?? '') ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Correo</label>
              <input class="form-control" value="<?= htmlspecialchars($me['correo'] ?? '') ?>" readonly>
            </div>
            <div class="col-12">
              <label class="form-label">Teléfono (opcional)</label>
              <input class="form-control" name="telefono" value="<?= htmlspecialchars($me['telefono'] ?? '') ?>">
              <!-- <div class="form-text">Si tu tabla no tiene <code>telefono</code>, igual se guardarán los otros campos.</div> -->
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-domus" type="submit"><i class="bi bi-save me-1"></i>Guardar cambios</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Card: Cambiar contraseña -->
    <div class="col-12 col-lg-6">
      <div class="card card-domus p-3 p-md-4">
        <h5 class="mb-3">Cambiar contraseña</h5>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="change_password">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Contraseña actual</label>
              <input type="password" class="form-control" name="pass_actual" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nueva contraseña</label>
              <input type="password" class="form-control" name="pass_nueva" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Repetir nueva</label>
              <input type="password" class="form-control" name="pass_repet" required>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-domus" type="submit"><i class="bi bi-key me-1"></i>Actualizar contraseña</button>
          </div>
          <small class="text-muted d-block mt-2">
            Debe incluir: 1 mayúscula, 1 minúscula, 1 número y 1 carácter especial. Mínimo 6 caracteres.
          </small>
        </form>
      </div>
    </div>

    <!-- Card: Rol (solo admin) -->
    <?php if (is_admin()): ?>
      <div class="col-12">
        <div class="card card-domus p-3 p-md-4">
          <h5 class="mb-3">Rol</h5>
          <form method="post" class="row g-3 align-items-end" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="change_role">
            <div class="col-md-4">
              <label class="form-label">Rol actual</label>
              <input class="form-control" value="<?= htmlspecialchars($myRole) ?>" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Cambiar a</label>
              <select class="form-select" name="idrol" required>
                <option value="">Selecciona…</option>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= (int)$r['idrol'] ?>" <?= ($myRoleId == (int)$r['idrol'])?'selected':'' ?>>
                    <?= htmlspecialchars($r['nombre_rol']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <button class="btn btn-domus" type="submit"><i class="bi bi-shield-lock me-1"></i>Guardar rol</button>
            </div>
            <div class="col-12">
              <small class="text-muted">Solo visible para administradores. Aplica sobre tu propio usuario.</small>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
    <?php else: ?>
      location.reload();
    <?php endif; ?>
  });
</script>
<?php endif; ?>
</body>
</html>