<?php
// app/views/dashboard/usuarios.php
session_start();
require_once '../../models/conexion.php';

// ====== Guardia: solo ADMIN ======
if (empty($_SESSION['iduser']) || ($_SESSION['rol'] ?? '') !== 'admin') {
  header('Location: ../login/login.php');
  exit;
}

// Datos de sesión para el chip de usuario en navbar
$nombre = $_SESSION['nombre'] ?? 'Administrador';
$rol    = $_SESSION['rol'] ?? 'admin';

// ====== Helpers ======
function json_out($ok, $msg = '', $extra = []) {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(array_merge(['success'=>$ok, 'message'=>$msg], $extra));
  exit;
}
function base_url() {
  return (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' .
         $_SERVER['HTTP_HOST'] .
         rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}

/** Carga usuarios con su rol (para vista y export) */
function fetch_usuarios(PDO $db): array {
  $sql = "
    SELECT 
      u.iduser, u.nombre, u.apellido, u.correo, u.verificado,
      (SELECT ur.idrol FROM usuario_rol ur WHERE ur.iduser=u.iduser LIMIT 1) AS idrol,
      (SELECT r.nombre_rol FROM usuario_rol ur JOIN rol r ON r.idrol=ur.idrol WHERE ur.iduser=u.iduser LIMIT 1) AS rol
    FROM usuario u
    ORDER BY u.iduser ASC
  ";
  $st = $db->query($sql);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ====== Exportaciones (GET) ======
if (isset($_GET['export'])) {
  $type = strtolower(trim($_GET['export']));
  $usuarios = [];
  try { $usuarios = fetch_usuarios($conexion); } catch (Throwable $e) { $usuarios = []; }

  if ($type === 'csv') {
    // === Excel-friendly CSV ===
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="usuarios_idomus.csv"');
    $out = fopen('php://output', 'w');
    // BOM para ñ y acentos en Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    // Cabeceras
    fputcsv($out, ['ID','Nombre','Apellido','Correo','Verificado','Rol'], ';');
    foreach ($usuarios as $u) {
      fputcsv($out, [
        $u['iduser'],
        $u['nombre'],
        $u['apellido'],
        $u['correo'],
        ((int)$u['verificado']===1?'Sí':'No'),
        ($u['rol'] ?: 'usuario')
      ], ';');
    }
    fclose($out);
    exit;
  }

  if ($type === 'pdf') {
    // Intentar con Dompdf; si no existe, fallback a HTML imprimible
    $html = '<!doctype html><html><head><meta charset="utf-8">
      <style>
        body{font-family: DejaVu Sans, Arial, sans-serif; font-size:12px;}
        h2{margin:0 0 10px 0;}
        table{width:100%; border-collapse:collapse;}
        th,td{border:1px solid #ddd; padding:6px;}
        th{background:#f0f0f0;}
      </style>
      </head><body>
      <h2>Usuarios iDomus</h2>
      <table>
      <thead><tr>
        <th style="width:50px;">ID</th>
        <th>Nombre</th>
        <th>Apellido</th>
        <th>Correo</th>
        <th style="width:80px;">Verificado</th>
        <th style="width:90px;">Rol</th>
      </tr></thead><tbody>';
    foreach ($usuarios as $u) {
      $html .= '<tr>'.
        '<td>'.(int)$u['iduser'].'</td>'.
        '<td>'.htmlspecialchars($u['nombre']??'').'</td>'.
        '<td>'.htmlspecialchars($u['apellido']??'').'</td>'.
        '<td>'.htmlspecialchars($u['correo']??'').'</td>'.
        '<td>'.(((int)$u['verificado']===1)?'Sí':'No').'</td>'.
        '<td>'.htmlspecialchars($u['rol'] ?: 'usuario').'</td>'.
      '</tr>';
    }
    $html .= '</tbody></table></body></html>';

    // ¿Existe Dompdf?
    $dompdf_ok = false;
    $autoload = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($autoload)) {
      require_once $autoload;
      try {
        $dompdf_ok = true;
        $dompdf = new Dompdf\Dompdf(['isRemoteEnabled'=>true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('usuarios_idomus.pdf', ['Attachment'=>true]);
        exit;
      } catch (\Throwable $e) {
        $dompdf_ok = false;
      }
    }

    // Fallback: HTML imprimible
    if (!$dompdf_ok) {
      header('Content-Type: text/html; charset=UTF-8');
      echo $html . '<script>window.print()</script>';
      exit;
    }
  }

  // Tipo no reconocido
  header('HTTP/1.1 400 Bad Request');
  echo 'Export no soportado.';
  exit;
}

// ====== Acciones AJAX ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  $action = $_POST['action'] ?? '';

  // ---- Invitar por correo (pre_registro) ----
  if ($action === 'pre_register') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      json_out(false, 'Correo inválido.');
    }

    try {
      $st = $conexion->prepare('SELECT 1 FROM usuario WHERE correo=:c LIMIT 1');
      $st->execute([':c'=>$email]);
      if ($st->fetchColumn()) {
        json_out(false, 'Ya existe un usuario con este correo.');
      }

      $token   = bin2hex(random_bytes(16));
      $expira  = (new DateTime('+7 days'))->format('Y-m-d H:i:s');
      $creador = (int)($_SESSION['iduser'] ?? null);

      $conexion->beginTransaction();
      $conexion->prepare('DELETE FROM pre_registro WHERE email=:e')->execute([':e'=>$email]);
      $sql = 'INSERT INTO pre_registro (email, token, expira_en, usado, creado_por) 
              VALUES (:e, :t, :x, false, :u)';
      $conexion->prepare($sql)->execute([
        ':e'=>$email, ':t'=>$token, ':x'=>$expira, ':u'=>$creador
      ]);
      $conexion->commit();

      $signup_link = base_url() . '/../login/signup.php?token=' . urlencode($token);

      $asunto = 'iDomus · Invitación de registro';
      $msg = '
      <!doctype html>
      <html><head><meta charset="utf-8"></head><body>
        <div style="max-width:520px;margin:auto;font-family:Arial,sans-serif">
          <h2>Invitación a iDomus</h2>
          <p>Has sido invitado a registrarte en iDomus. Tu enlace de registro:</p>
          <p><a href="'.htmlspecialchars($signup_link).'" 
                style="display:inline-block;padding:10px 16px;background:#0F3557;color:#fff;border-radius:6px;text-decoration:none">
                Completar registro</a></p>
          <p>El enlace expira el: <b>'.htmlspecialchars($expira).'</b></p>
          <p>Si no solicitaste esta invitación, ignora este correo.</p>
        </div>
      </body></html>';
      $hdr  = "MIME-Version: 1.0\r\n";
      $hdr .= "Content-type: text/html; charset=UTF-8\r\n";
      $hdr .= "From: iDomus <no-reply@idomus.com>\r\n";
      $hdr .= "Reply-To: soporte@idomus.com\r\n";
      @mail($email, $asunto, $msg, $hdr);

      json_out(true, 'Invitación generada y enviada.', ['link'=>$signup_link]);

    } catch (Throwable $e) {
      if ($conexion->inTransaction()) $conexion->rollBack();
      json_out(false, 'Error: '.$e->getMessage());
    }
  }

  // ---- Crear / Editar ----
  if ($action === 'save_user') {
    $iduser     = (int)($_POST['iduser'] ?? 0);
    $nombre     = trim($_POST['nombre']   ?? '');
    $apellido   = trim($_POST['apellido'] ?? '');
    $correo     = trim($_POST['correo']   ?? '');
    $verificado = isset($_POST['verificado']) && $_POST['verificado'] === '1' ? 1 : 0;
    $new_pass   = $_POST['new_password'] ?? '';

    if ($nombre === '' || $apellido === '' || $correo === '') {
      json_out(false, 'Faltan datos obligatorios.');
    }

    try {
      if ($iduser > 0) {
        $st = $conexion->prepare('SELECT 1 FROM usuario WHERE correo=:c AND iduser<>:id LIMIT 1');
        $st->execute([':c'=>$correo, ':id'=>$iduser]);
      } else {
        $st = $conexion->prepare('SELECT 1 FROM usuario WHERE correo=:c LIMIT 1');
        $st->execute([':c'=>$correo]);
      }
      if ($st->fetchColumn()) {
        json_out(false, 'El correo ya está registrado.');
      }

      if ($iduser > 0) {
        if (strlen($new_pass) >= 6) {
          $hash = password_hash($new_pass, PASSWORD_BCRYPT);
          $sql = 'UPDATE usuario 
                  SET nombre=:n, apellido=:a, correo=:c, verificado=:v, contrasena=:p 
                  WHERE iduser=:id';
          $params = [':n'=>$nombre, ':a'=>$apellido, ':c'=>$correo, ':v'=>$verificado, ':p'=>$hash, ':id'=>$iduser];
        } else {
          $sql = 'UPDATE usuario 
                  SET nombre=:n, apellido=:a, correo=:c, verificado=:v
                  WHERE iduser=:id';
          $params = [':n'=>$nombre, ':a'=>$apellido, ':c'=>$correo, ':v'=>$verificado, ':id'=>$iduser];
        }
        $conexion->prepare($sql)->execute($params);
        json_out(true, 'Usuario actualizado.');
      } else {
        $password_inicial = (strlen($new_pass) >= 6) ? $new_pass : 'Cambiar123!';
        $hash = password_hash($password_inicial, PASSWORD_BCRYPT);
        $sql = 'INSERT INTO usuario (nombre, apellido, correo, contrasena, verificado)
                VALUES (:n,:a,:c,:p,:v) RETURNING iduser';
        $st  = $conexion->prepare($sql);
        $st->execute([':n'=>$nombre, ':a'=>$apellido, ':c'=>$correo, ':p'=>$hash, ':v'=>$verificado]);
        $idNew = (int)$st->fetchColumn();

        $stR = $conexion->prepare('SELECT idrol FROM rol WHERE LOWER(nombre_rol)=LOWER(:r) LIMIT 1');
        $stR->execute([':r'=>'usuario']);
        $idRol = (int)($stR->fetchColumn() ?: 0);
        if ($idRol > 0) {
          $conexion->prepare('INSERT INTO usuario_rol (iduser, idrol) VALUES (:u,:r)')
                   ->execute([':u'=>$idNew, ':r'=>$idRol]);
        }

        json_out(true, 'Usuario creado.');
      }
    } catch (Throwable $e) {
      json_out(false, 'Error: '.$e->getMessage());
    }
  }

  // ---- Eliminar usuario ----
  if ($action === 'delete_user') {
    $id = (int)($_POST['iduser'] ?? 0);
    if ($id <= 0) json_out(false, 'ID inválido.');
    if ($id == (int)$_SESSION['iduser']) {
      json_out(false, 'No puedes eliminarte a ti mismo.');
    }

    try {
      $conexion->beginTransaction();
      $conexion->prepare('DELETE FROM usuario_rol WHERE iduser=:id')->execute([':id'=>$id]);
      $conexion->prepare('DELETE FROM usuario WHERE iduser=:id')->execute([':id'=>$id]);
      $conexion->commit();
      json_out(true, 'Usuario eliminado.');
    } catch (Throwable $e) {
      if ($conexion->inTransaction()) $conexion->rollBack();
      json_out(false, 'Error: '.$e->getMessage());
    }
  }

  // ---- Cambiar rol ----
  if ($action === 'change_role') {
    $iduser = (int)($_POST['iduser'] ?? 0);
    $idrol  = (int)($_POST['idrol']  ?? 0);
    if ($iduser <= 0 || $idrol <= 0) json_out(false, 'Parámetros inválidos.');

    try {
      $st = $conexion->prepare('SELECT 1 FROM usuario WHERE iduser=:id LIMIT 1');
      $st->execute([':id'=>$iduser]);
      if (!$st->fetchColumn()) json_out(false, 'El usuario no existe.');

      $st = $conexion->prepare('SELECT nombre_rol FROM rol WHERE idrol=:r LIMIT 1');
      $st->execute([':r'=>$idrol]);
      $rolNombre = $st->fetchColumn();
      if (!$rolNombre) json_out(false, 'El rol no existe.');

      $conexion->beginTransaction();
      $conexion->prepare('DELETE FROM usuario_rol WHERE iduser=:id')->execute([':id'=>$iduser]);
      $conexion->prepare('INSERT INTO usuario_rol (iduser, idrol) VALUES (:u,:r)')
               ->execute([':u'=>$iduser, ':r'=>$idrol]);
      $conexion->commit();

      json_out(true, 'Rol actualizado a: '.$rolNombre, ['rol'=>$rolNombre]);
    } catch (Throwable $e) {
      if ($conexion->inTransaction()) $conexion->rollBack();
      json_out(false, 'Error: '.$e->getMessage());
    }
  }

  json_out(false, 'Acción inválida.');
}

// ====== Datos para render ======
$roles = [];
try {
  $rs = $conexion->query('SELECT idrol, nombre_rol FROM rol ORDER BY nombre_rol ASC');
  $roles = $rs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $roles = []; }

$usuarios = [];
try { $usuarios = fetch_usuarios($conexion); } catch (Throwable $e) { $usuarios = []; }

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>iDomus · Usuarios (Admin)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root{ --accent:#1BAAA6; --dark:#0F3557; }
    body{ background:#f7fafd; font-family:'Segoe UI', Arial, sans-serif; }
    .navbar{ background:#023047; }
    .brand-badge{ color:#fff; font-weight:700; }
    .container-xxl{ max-width:1200px; }
    .card-box{ border:none; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    .table thead th{ background:var(--accent); color:#fff; border:none; }
    .btn-domus{ background:var(--dark); color:#fff; border:none; border-radius:10px; }
    .btn-domus:hover{ background:var(--accent); }
    .badge-rol{ background:#e7f7f6; color:#0F3557; font-weight:600; }
    .user-chip{
      background:#e9f7f6; color:#0F3557; border-radius:20px; padding:.35rem .6rem; font-weight:600;
      display:inline-flex; gap:.4rem; align-items:center;
    }
    .user-chip i{ color:#1BAAA6; }
    .user-chip-sm{ background:#e9f7f6; color:#0F3557; border-radius:18px; padding:.25rem .45rem; font-weight:600;
      display:inline-flex; gap:.35rem; align-items:center; font-size:.9rem; }
    .user-chip-sm i{ color:#1BAAA6; }
    .badge-admin { background:#023047; color:#fff; font-weight:600; }
    .badge-usuario { background:#e7f7f6; color:#0F3557; font-weight:600; }
    .badge-moderador { background:#ffd166; color:#000; font-weight:600; }
    .badge-default { background:#ccc; color:#333; font-weight:600; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark sticky-top shadow-sm mb-3">
  <div class="container-fluid">
    <a href="dashboard.php" class="btn btn-outline-light me-2">
      <i class="bi bi-arrow-left"></i> Volver
    </a>
    <span class="brand-badge">iDomus · Usuarios</span>
    <div class="d-flex align-items-center gap-2">
      <span class="user-chip d-none d-sm-inline-flex">
        <i class="bi bi-person-circle"></i>
        <?= htmlspecialchars($nombre) ?> · <?= htmlspecialchars(ucfirst($rol)) ?>
      </span>
      <span class="user-chip-sm d-inline-flex d-sm-none">
        <i class="bi bi-person-circle"></i>
        <?= htmlspecialchars(ucfirst($rol)) ?>
      </span>
      <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="bi bi-house-door"></i> Dashboard</a>
      <a href="../login/login.php?logout=1" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right"></i> Salir</a>
    </div>
  </div>
</nav>

<div class="container-xxl">

  <div class="card card-box p-3 mb-3">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
      <div class="d-flex flex-wrap gap-2">
        <!-- Invitar por correo -->
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalInvite" id="btnInvite">
          <i class="bi bi-envelope-plus"></i> Invitar por correo
        </button>

        <!-- Crear completo -->
        <button class="btn btn-domus" data-bs-toggle="modal" data-bs-target="#modalUser" id="btnAddUser">
          <i class="bi bi-person-plus"></i> Nuevo usuario (completo)
        </button>

        <!-- Exportaciones -->
        <a class="btn btn-outline-primary" href="usuarios.php?export=csv" target="_blank">
          <i class="bi bi-file-earmark-excel"></i> Exportar Excel
        </a>
        <a class="btn btn-outline-danger" href="usuarios.php?export=pdf" target="_blank">
          <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
        </a>
      </div>

      <div class="input-group" style="max-width:300px;">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" id="filtrar" class="form-control" placeholder="Buscar...">
      </div>
    </div>
  </div>

  <div class="card card-box p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="tablaUsuarios">
        <thead>
          <tr>
            <th>#</th>
            <th>Nombre</th>
            <th>Correo</th>
            <th>Verificado</th>
            <th>Rol</th>
            <th style="width:230px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($usuarios as $u): ?>
            <tr data-id="<?= (int)$u['iduser'] ?>">
              <td><?= (int)$u['iduser'] ?></td>
              <td><?= htmlspecialchars(($u['nombre'] ?? '').' '.($u['apellido'] ?? '')) ?></td>
              <td><?= htmlspecialchars($u['correo'] ?? '') ?></td>
              <td>
                <?php if ((int)$u['verificado'] === 1): ?>
                  <span class="badge bg-success">Sí</span>
                <?php else: ?>
                  <span class="badge bg-secondary">No</span>
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $rolNombre = strtolower($u['rol'] ?? 'usuario');
                  $colorClass = match($rolNombre) {
                    'admin'     => 'badge-admin',
                    'usuario'   => 'badge-usuario',
                    'moderador' => 'badge-moderador',
                    default     => 'badge-default'
                  };
                ?>
                <span class="badge <?= $colorClass ?>">
                  <?= htmlspecialchars(ucfirst($rolNombre)) ?>
                </span>
              </td>
              <td>
                <div class="btn-group">
                  <button 
                    class="btn btn-sm btn-outline-primary btn-edit"
                    data-id="<?= (int)$u['iduser'] ?>"
                    data-nombre="<?= htmlspecialchars($u['nombre'] ?? '') ?>"
                    data-apellido="<?= htmlspecialchars($u['apellido'] ?? '') ?>"
                    data-correo="<?= htmlspecialchars($u['correo'] ?? '') ?>"
                    data-verificado="<?= (int)$u['verificado'] ?>"
                    data-bs-toggle="modal" data-bs-target="#modalUser"
                  >
                    <i class="bi bi-pencil"></i> Editar
                  </button>
                  <button 
                    class="btn btn-sm btn-outline-info btn-rol"
                    data-id="<?= (int)$u['iduser'] ?>"
                    data-idrol="<?= (int)($u['idrol'] ?? 0) ?>"
                    data-rol="<?= htmlspecialchars($u['rol'] ?? 'usuario') ?>"
                    data-bs-toggle="modal" data-bs-target="#modalRol"
                  >
                    <i class="bi bi-shield-lock"></i> Rol
                  </button>
                  <button
                    class="btn btn-sm btn-outline-danger btn-del"
                    data-id="<?= (int)$u['iduser'] ?>"
                    data-nombre="<?= htmlspecialchars(($u['nombre']??'').' '.($u['apellido']??'')) ?>"
                    data-bs-toggle="modal" data-bs-target="#modalDelete"
                  >
                    <i class="bi bi-trash"></i> Eliminar
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$usuarios): ?>
            <tr><td colspan="6" class="text-center text-muted">No hay usuarios.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- MODAL: Invitar por correo -->
<div class="modal fade" id="modalInvite" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="formInvite" autocomplete="off">
      <div class="modal-header">
        <h5 class="modal-title">Invitar por correo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="alertInvite" class="alert d-none"></div>

        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="action" value="pre_register">

        <label class="form-label">Correo</label>
        <input type="email" class="form-control" name="email" id="invite_email" placeholder="correo@dominio.com" required>

        <div class="mt-3 d-none" id="invite_link_box">
          <label class="form-label">Enlace de invitación</label>
          <div class="input-group">
            <input type="text" readonly class="form-control" id="invite_link">
            <button type="button" class="btn btn-outline-secondary" id="btnCopyLink">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
          <div class="form-text">Copia y comparte este enlace si el correo no se entrega.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" id="btnInviteReset">Nueva invitación</button>
        <button class="btn btn-success" type="submit" id="btnInviteSubmit">
          <span class="label">Generar invitación</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Crear/Editar usuario -->
<div class="modal fade" id="modalUser" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="formUser">
      <div class="modal-header">
        <h5 class="modal-title" id="modalUserTitle">Nuevo usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div id="alertUser" class="alert d-none" role="alert"></div>

        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="action" value="save_user">
        <input type="hidden" name="iduser" id="user_id" value="0">

        <div class="mb-2">
          <label class="form-label">Nombre</label>
          <input type="text" class="form-control" name="nombre" id="user_nombre" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Apellido</label>
          <input type="text" class="form-control" name="apellido" id="user_apellido" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Correo</label>
          <input type="email" class="form-control" name="correo" id="user_correo" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Contraseña (opcional para editar)</label>
          <input type="password" class="form-control" name="new_password" id="user_pass" placeholder="(dejar vacío para no cambiar)">
          <div class="form-text">Para nuevo usuario: si se deja vacío, se usará <b>Cambiar123!</b></div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" value="1" name="verificado" id="user_verificado">
          <label class="form-check-label" for="user_verificado">Verificado</label>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-domus" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Cambiar rol -->
<div class="modal fade" id="modalRol" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="formRol">
      <div class="modal-header">
        <h5 class="modal-title">Cambiar rol</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div id="alertRol" class="alert d-none" role="alert"></div>

        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="action" value="change_role">
        <input type="hidden" name="iduser" id="rol_id" value="0">

        <label class="form-label">Rol</label>
        <select class="form-select" name="idrol" id="rol_select" required>
          <?php foreach($roles as $r): ?>
            <option value="<?= (int)$r['idrol'] ?>"><?= htmlspecialchars($r['nombre_rol']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-domus" type="submit">Actualizar rol</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Confirmar eliminación -->
<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="formDelete">
      <div class="modal-header">
        <h5 class="modal-title">Eliminar usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div id="alertDel" class="alert d-none" role="alert"></div>

        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="iduser" id="del_id" value="0">
        <p class="mb-0">¿Seguro que deseas eliminar al usuario <b id="del_nombre"></b>?</p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger" type="submit">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ====== Filtro simple ======
const inputFiltro = document.getElementById('filtrar');
const tabla       = document.getElementById('tablaUsuarios').querySelector('tbody');
if (inputFiltro && tabla) {
  inputFiltro.addEventListener('input', ()=>{
    const q = inputFiltro.value.toLowerCase();
    tabla.querySelectorAll('tr').forEach(tr=>{
      const txt = tr.innerText.toLowerCase();
      tr.style.display = txt.includes(q) ? '' : 'none';
    });
  });
}

// ====== MODAL: Invitar por correo ======
const modalInvite    = document.getElementById('modalInvite');
const formInvite     = document.getElementById('formInvite');
const alertInvite    = document.getElementById('alertInvite');
const inviteEmail    = document.getElementById('invite_email');
const inviteLinkBox  = document.getElementById('invite_link_box');
const inviteLink     = document.getElementById('invite_link');
const btnInviteSubmit= document.getElementById('btnInviteSubmit');
const btnInviteReset = document.getElementById('btnInviteReset');
const btnCopyLink    = document.getElementById('btnCopyLink');

function resetInviteModal(clearEmail = true) {
  alertInvite.className = 'alert d-none';
  alertInvite.textContent = '';
  inviteLinkBox.classList.add('d-none');
  inviteLink.value = '';
  if (clearEmail) inviteEmail.value = '';
  btnInviteSubmit.disabled = false;
  btnInviteSubmit.innerHTML = '<span class="label">Generar invitación</span>';
}

modalInvite.addEventListener('shown.bs.modal', () => {
  resetInviteModal(true);
  inviteEmail.focus();
});

btnInviteReset.addEventListener('click', () => {
  resetInviteModal(true);
  inviteEmail.focus();
});

btnCopyLink.addEventListener('click', async () => {
  try {
    await navigator.clipboard.writeText(inviteLink.value);
    btnCopyLink.innerHTML = '<i class="bi bi-clipboard-check"></i>';
    setTimeout(()=> btnCopyLink.innerHTML = '<i class="bi bi-clipboard"></i>', 1200);
  } catch {
    alert('No se pudo copiar. Selecciona y copia manualmente.');
  }
});

formInvite.addEventListener('submit', async (e) => {
  e.preventDefault();
  btnInviteSubmit.disabled = true;
  btnInviteSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Enviando...';

  const data = new FormData(formInvite);
  let js = {};
  try {
    const res = await fetch('usuarios.php', { method: 'POST', body: data, cache: 'no-store' });
    js = await res.json();
  } catch (err) {
    js = { success:false, message:'No se pudo conectar con el servidor.' };
  }

  if (js.success) {
    alertInvite.className = 'alert alert-success';
    alertInvite.textContent = js.message || 'Invitación generada.';
    alertInvite.classList.remove('d-none');
    if (js.link) {
      inviteLinkBox.classList.remove('d-none');
      inviteLink.value = js.link;
    }
  } else {
    alertInvite.className = 'alert alert-danger';
    alertInvite.textContent = js.message || 'Error.';
    alertInvite.classList.remove('d-none');
  }
  btnInviteSubmit.disabled = false;
  btnInviteSubmit.innerHTML = '<span class="label">Generar invitación</span>';
});

// ====== MODAL Crear/Editar ======
const modalUser   = document.getElementById('modalUser');
const formUser    = document.getElementById('formUser');
const alertUser   = document.getElementById('alertUser');

const user_id     = document.getElementById('user_id');
const user_nombre = document.getElementById('user_nombre');
const user_ap     = document.getElementById('user_apellido');
const user_correo = document.getElementById('user_correo');
const user_pass   = document.getElementById('user_pass');
const user_verif  = document.getElementById('user_verificado');
const modalUserTitle = document.getElementById('modalUserTitle');

document.getElementById('btnAddUser')?.addEventListener('click', ()=>{
  modalUserTitle.textContent = 'Nuevo usuario (completo)';
  user_id.value = '0';
  user_nombre.value = '';
  user_ap.value = '';
  user_correo.value = '';
  user_pass.value = '';
  user_verif.checked = false;
  alertUser.classList.add('d-none');
  alertUser.textContent = '';
});

document.querySelectorAll('.btn-edit').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    modalUserTitle.textContent = 'Editar usuario';
    user_id.value     = btn.getAttribute('data-id');
    user_nombre.value = btn.getAttribute('data-nombre') || '';
    user_ap.value     = btn.getAttribute('data-apellido') || '';
    user_correo.value = btn.getAttribute('data-correo') || '';
    user_pass.value   = '';
    user_verif.checked= (btn.getAttribute('data-verificado') === '1');
    alertUser.classList.add('d-none');
    alertUser.textContent = '';
  });
});

formUser.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const data = new FormData(formUser);
  const res  = await fetch('usuarios.php', { method:'POST', body:data });
  let js = {};
  try{ js = await res.json(); }catch{ js = {success:false, message:'Respuesta inválida'} }
  if (js.success) {
    alertUser.className = 'alert alert-success';
    alertUser.textContent = js.message || 'Guardado.';
    alertUser.classList.remove('d-none');
    setTimeout(()=> location.reload(), 800);
  } else {
    alertUser.className = 'alert alert-danger';
    alertUser.textContent = js.message || 'Error.';
    alertUser.classList.remove('d-none');
  }
});

// ====== MODAL Rol ======
const formRol   = document.getElementById('formRol');
const rolAlert  = document.getElementById('alertRol');
const rolId     = document.getElementById('rol_id');
const rolSel    = document.getElementById('rol_select');

document.querySelectorAll('.btn-rol').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.getAttribute('data-id');
    const idrolActual = parseInt(btn.getAttribute('data-idrol') || '0', 10);
    rolId.value = id;
    let marcado = false;
    for (const opt of rolSel.options) {
      if (parseInt(opt.value,10) === idrolActual) {
        opt.selected = true;
        marcado = true;
        break;
      }
    }
    if (!marcado && rolSel.options.length>0) rolSel.selectedIndex = 0;
    rolAlert.classList.add('d-none');
    rolAlert.textContent = '';
  });
});

formRol.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const data = new FormData(formRol);
  const res  = await fetch('usuarios.php', { method:'POST', body:data });
  let js = {};
  try{ js = await res.json(); }catch{ js = {success:false, message:'Respuesta inválida'} }
  if (js.success) {
    rolAlert.className = 'alert alert-success';
    rolAlert.textContent = js.message || 'Rol actualizado.';
    rolAlert.classList.remove('d-none');
    setTimeout(()=> location.reload(), 700);
  } else {
    rolAlert.className = 'alert alert-danger';
    rolAlert.textContent = js.message || 'Error.';
    rolAlert.classList.remove('d-none');
  }
});

// ====== MODAL Delete ======
const formDel   = document.getElementById('formDelete');
const delAlert  = document.getElementById('alertDel');
const delId     = document.getElementById('del_id');
const delNombre = document.getElementById('del_nombre');

document.querySelectorAll('.btn-del').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    delId.value = btn.getAttribute('data-id');
    delNombre.textContent = btn.getAttribute('data-nombre') || '';
    delAlert.classList.add('d-none');
    delAlert.textContent = '';
  });
});

formDel.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const data = new FormData(formDel);
  const res  = await fetch('usuarios.php', { method:'POST', body:data });
  let js = {};
  try{ js = await res.json(); }catch{ js = {success:false, message:'Respuesta inválida'} }
  if (js.success) {
    delAlert.className = 'alert alert-success';
    delAlert.textContent = js.message || 'Eliminado.';
    delAlert.classList.remove('d-none');
    setTimeout(()=> location.reload(), 700);
  } else {
    delAlert.className = 'alert alert-danger';
    delAlert.textContent = js.message || 'Error.';
    delAlert.classList.remove('d-none');
  }
});
</script>
</body>
</html>