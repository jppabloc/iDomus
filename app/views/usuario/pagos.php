<?php
// app/views/usuario/pagos.php
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

// ===== Helpers =====
function is_admin(): bool { return ($_SESSION['rol'] ?? '') === 'admin'; }

// ===== POST: pagar cuota =====
$swal = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $token  = $_POST['csrf'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $swal = ['icon'=>'error','title'=>'CSRF inválido','text'=>'Vuelve a cargar la página.'];
  } elseif ($action === 'pay_cuota') {

    $id_cuota = (int)($_POST['id_cuota'] ?? 0);
    $metodo   = trim($_POST['metodo'] ?? 'OTRO');

    if ($id_cuota <= 0) {
      $swal = ['icon'=>'error','title'=>'Parámetros inválidos','text'=>'Cuota no válida.'];
    } else {
      try {
        // Verificamos que la cuota PERTENECE a una de sus unidades y está PENDIENTE
        $sql = "
          SELECT 
            cm.id_cuota, cm.monto, cm.fecha_generacion, cm.estado,
            un.nro_unidad, b.nombre AS bloque, e.nombre AS edificio, un.id_unidad
          FROM cuota_mantenimiento cm
          JOIN unidad un  ON un.id_unidad = cm.id_unidad
          JOIN bloque b   ON b.id_bloque  = un.id_bloque
          JOIN edificio e ON e.id_edificio = b.id_edificio
          WHERE cm.id_cuota = :idc
            AND UPPER(cm.estado) = 'PENDIENTE'
            AND cm.id_unidad IN (
              SELECT ru.id_unidad FROM residente_unidad ru WHERE ru.id_usuario = :uid
            )
          LIMIT 1
        ";
        $st = $conexion->prepare($sql);
        $st->execute([':idc'=>$id_cuota, ':uid'=>$iduser]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
          $swal = ['icon'=>'error','title'=>'No disponible','text'=>'La cuota no existe, no está pendiente o no pertenece a tus unidades.'];
        } else {
          $monto = (float)$row['monto'];
          $nroU  = $row['nro_unidad'];
          $edif  = $row['edificio'];
          $desc  = "Cuota mantenimiento {$edif} {$nroU}";

          $conexion->beginTransaction();
          // 1) Update cuota a PAGADO
          $up = $conexion->prepare("UPDATE cuota_mantenimiento SET estado='PAGADO' WHERE id_cuota=:idc AND UPPER(estado)='PENDIENTE'");
          $up->execute([':idc'=>$id_cuota]);

          if ($up->rowCount() !== 1) {
            $conexion->rollBack();
            $swal = ['icon'=>'error','title'=>'No se pudo pagar','text'=>'La cuota ya fue pagada o cambió de estado.'];
          } else {
            // 2) Insert pago
            $ins = $conexion->prepare("
              INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
              VALUES (:u, :m, CURRENT_DATE, :c, 'PAGADO')
            ");
            $ins->execute([':u'=>$iduser, ':m'=>$monto, ':c'=>$desc]);

            $conexion->commit();
            $swal = ['icon'=>'success','title'=>'Pago realizado','text'=>"Se registró el pago de Bs {$monto} por {$desc}."];
          }
        }
      } catch (PDOException $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        $swal = ['icon'=>'error','title'=>'Error','text'=>'No se pudo completar el pago: '.$e->getMessage()];
      }
    }
  }
}

// ===== Datos: unidades del usuario =====
$sqlUni = "
  SELECT un.id_unidad, un.nro_unidad, b.nombre AS bloque, e.nombre AS edificio
  FROM residente_unidad ru
  JOIN unidad un  ON un.id_unidad = ru.id_unidad
  JOIN bloque b   ON b.id_bloque  = un.id_bloque
  JOIN edificio e ON e.id_edificio = b.id_edificio
  WHERE ru.id_usuario = :uid
  ORDER BY e.nombre, b.nombre, un.nro_unidad
";
$stUni = $conexion->prepare($sqlUni);
$stUni->execute([':uid'=>$iduser]);
$misUnidades = $stUni->fetchAll(PDO::FETCH_ASSOC);

// ===== Cuotas pendientes =====
$pendientes = [];
if ($misUnidades) {
  $sqlPen = "
    SELECT 
      cm.id_cuota, cm.monto, cm.fecha_generacion, cm.fecha_vencimiento, cm.estado,
      un.id_unidad, un.nro_unidad, b.nombre AS bloque, e.nombre AS edificio
    FROM cuota_mantenimiento cm
    JOIN unidad un  ON un.id_unidad = cm.id_unidad
    JOIN bloque b   ON b.id_bloque  = un.id_bloque
    JOIN edificio e ON e.id_edificio = b.id_edificio
    WHERE cm.id_unidad IN (
      SELECT ru.id_unidad FROM residente_unidad ru WHERE ru.id_usuario = :uid
    )
      AND UPPER(cm.estado)='PENDIENTE'
    ORDER BY cm.fecha_generacion DESC
  ";
  $stPen = $conexion->prepare($sqlPen);
  $stPen->execute([':uid'=>$iduser]);
  $pendientes = $stPen->fetchAll(PDO::FETCH_ASSOC);
}

// ===== Historial de pagos =====
$sqlPag = "
  SELECT p.id_pago, p.monto, p.fecha_pago, COALESCE(p.concepto,'') AS concepto, p.estado
  FROM pago p
  WHERE p.id_usuario = :uid
  ORDER BY p.fecha_pago DESC, p.id_pago DESC
";
$stPag = $conexion->prepare($sqlPag);
$stPag->execute([':uid'=>$iduser]);
$historial = $stPag->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Pagos</title>
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
    .btn-domus{background:var(--dark);color:#fff;border:none;}
    .btn-domus:hover{background:var(--acc);}
    .table thead th{background:var(--acc); color:#fff; border:none;}
    .badge-soft{
      background:#e7f7f6; color:#0F3557; font-weight:600;
    }
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
        <li class="nav-item"><a class="nav-link active" href="./pagos.php">Pagos</a></li>
      </ul>

      <div class="dropdown">
        <a class="user-chip dropdown-toggle" href="#" data-bs-toggle="dropdown">
          <span class="avatar-icon"><i class="bi bi-person-fill"></i></span>
          <span><?= htmlspecialchars($nombreS) ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li class="dropdown-header small text-muted">Rol: <?= htmlspecialchars($rolS) ?></li>
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

  <div class="card card-domus p-3 p-md-4 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <h1 class="h5 mb-1">Pagos y cuotas</h1>
        <div class="text-muted">
          <?php if ($misUnidades): ?>
            <span class="badge badge-soft"><?= count($misUnidades) ?> unidad(es) a tu nombre</span>
          <?php else: ?>
            <span class="text-danger">No tienes unidades registradas. Contacta a un administrador.</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2">
        <!-- futuro: exportaciones PDF/Excel -->
        <!-- <a href="#" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <a href="#" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-pdf me-1"></i>PDF</a> -->
      </div>
    </div>
  </div>

  <div class="row g-4">
    <!-- Pendientes -->
    <div class="col-12 col-lg-6">
      <div class="card card-domus p-3 p-md-4">
        <h5 class="mb-3">Cuotas pendientes</h5>
        <?php if (!$misUnidades): ?>
          <div class="alert alert-warning">No podemos mostrar cuotas porque no tienes unidades asociadas.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Unidad</th>
                  <th>Monto (Bs)</th>
                  <th>Generación</th>
                  <th>Vencimiento</th>
                  <th>Acción</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$pendientes): ?>
                  <tr><td colspan="5" class="text-muted text-center">Sin cuotas pendientes 🎉</td></tr>
                <?php else: foreach($pendientes as $p): ?>
                  <tr>
                    <td>
                      <div><b><?= htmlspecialchars($p['edificio'].' · '.$p['bloque'].' · '.$p['nro_unidad']) ?></b></div>
                    </td>
                    <td><?= number_format((float)$p['monto'], 2) ?></td>
                    <td><?= htmlspecialchars($p['fecha_generacion']) ?></td>
                    <td><?= htmlspecialchars($p['fecha_vencimiento']) ?></td>
                    <td>
                      <button 
                        class="btn btn-sm btn-domus btn-pagar"
                        data-idcuota="<?= (int)$p['id_cuota'] ?>"
                        data-desc="<?= htmlspecialchars($p['edificio'].' '.$p['nro_unidad']) ?>"
                        data-monto="<?= (float)$p['monto'] ?>"
                        data-bs-toggle="modal" data-bs-target="#modalPagar"
                      >
                        <i class="bi bi-credit-card"></i> Pagar
                      </button>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Historial -->
    <div class="col-12 col-lg-6">
      <div class="card card-domus p-3 p-md-4">
        <h5 class="mb-3">Historial de pagos</h5>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Concepto</th>
                <th>Monto</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$historial): ?>
                <tr><td colspan="4" class="text-muted text-center">Aún no registras pagos.</td></tr>
              <?php else: foreach($historial as $h): ?>
                <tr>
                  <td><?= htmlspecialchars($h['fecha_pago']) ?></td>
                  <td><?= htmlspecialchars($h['concepto']) ?></td>
                  <td>Bs <?= number_format((float)$h['monto'],2) ?></td>
                  <td>
                    <?php if (strtoupper((string)$h['estado'])==='PAGADO'): ?>
                      <span class="badge bg-success">PAGADO</span>
                    <?php else: ?>
                      <span class="badge bg-secondary"><?= htmlspecialchars($h['estado']) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

</main>

<!-- MODAL: Pagar cuota -->
<div class="modal fade" id="modalPagar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" autocomplete="off">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar pago</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="pay_cuota">
        <input type="hidden" name="id_cuota" id="pay_id_cuota" value="0">

        <div class="mb-2">
          <label class="form-label">Detalle</label>
          <input class="form-control" id="pay_detalle" value="" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label">Monto (Bs)</label>
          <input class="form-control" id="pay_monto" value="" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label">Método de pago</label>
          <select class="form-select" name="metodo" required>
            <option value="TARJETA">Tarjeta</option>
            <option value="QR">QR</option>
            <option value="TRANSFERENCIA">Transferencia</option>
            <option value="EFECTIVO">Efectivo</option>
            <option value="OTRO">Otro</option>
          </select>
        </div>
        <small class="text-muted">
          * Al confirmar, se registrará el pago y la cuota quedará marcada como <b>PAGADO</b>.
        </small>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-domus" type="submit"><i class="bi bi-check2-circle me-1"></i>Confirmar pago</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Poblar modal con datos de la cuota
document.querySelectorAll('.btn-pagar').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const idc   = btn.getAttribute('data-idcuota');
    const desc  = btn.getAttribute('data-desc') || '';
    const monto = btn.getAttribute('data-monto') || '';
    document.getElementById('pay_id_cuota').value = idc;
    document.getElementById('pay_detalle').value = 'Cuota de ' + desc;
    document.getElementById('pay_monto').value = monto;
  });
});
</script>

<?php if ($swal): ?>
<script>
  Swal.fire({
    icon: '<?= $swal['icon'] ?>',
    title: '<?= addslashes($swal['title']) ?>',
    text: '<?= addslashes($swal['text']) ?>',
    confirmButtonText: 'Aceptar'
  }).then(()=>{ location.href = './pagos.php'; });
</script>
<?php endif; ?>
</body>
</html>