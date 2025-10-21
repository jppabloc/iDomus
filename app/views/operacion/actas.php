<?php
// app/views/operacion/actas.php
declare(strict_types=1);
session_start();
require_once '../../models/conexion.php';

/** Solo admin por ahora (luego puedes abrir a rol operador) */
if (empty($_SESSION['iduser']) || ($_SESSION['rol'] ?? '') !== 'admin') {
  header('Location: ../login/login.php'); exit;
}
$idUser = (int)$_SESSION['iduser'];
$nombre = $_SESSION['nombre'] ?? 'Admin';

// Datos de sesión para el chip de usuario en navbar
$nombre = $_SESSION['nombre'] ?? 'Administrador';
$rol    = $_SESSION['rol'] ?? 'admin';

/* ==== ACCIONES POST ==== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $accion = $_POST['action'] ?? '';
  try {
    if ($accion==='generar_entrega') {
      $id_reserva = (int)($_POST['id_reserva'] ?? 0);
      $obs = trim($_POST['observ_entrega'] ?? '');
      if ($id_reserva<=0) throw new Exception('Reserva inválida');

      // Validar que esté aprobada y no exista acta
      $st = $conexion->prepare("SELECT estado FROM reserva WHERE id_reserva=:id");
      $st->execute([':id'=>$id_reserva]);
      $estado = strtoupper((string)$st->fetchColumn());
      if ($estado!=='APROBADA') throw new Exception('La reserva no está APROBADA');
      $existe = $conexion->prepare("SELECT 1 FROM acta_reserva WHERE id_reserva=:id");
      $existe->execute([':id'=>$id_reserva]);
      if ($existe->fetchColumn()) throw new Exception('Ya existe acta para esta reserva');

      // Crear acta en ENTREGADO
      $conexion->prepare("
        INSERT INTO acta_reserva (id_reserva, estado, fecha_entrega, entregado_por, observ_entrega)
        VALUES (:r,'ENTREGADO',NOW(),:u,:obs)
      ")->execute([':r'=>$id_reserva, ':u'=>$idUser, ':obs'=>$obs]);

      header('Location: actas.php?ok=entregado'); exit;
    }

    if ($accion==='recibir_devolucion') {
      $id_acta = (int)($_POST['id_acta'] ?? 0);
      $obs = trim($_POST['observ_devolucion'] ?? '');
      $danos_json = $_POST['danos'] ?? '[]';
      $danos = json_decode($danos_json,true);
      if (!is_array($danos)) $danos = [];

      // validar acta
      $st = $conexion->prepare("SELECT estado FROM acta_reserva WHERE id_acta=:id");
      $st->execute([':id'=>$id_acta]);
      $estado = strtoupper((string)$st->fetchColumn());
      if ($estado!=='ENTREGADO') throw new Exception('El acta no está en estado ENTREGADO');

      // Inserta daños (si hay)
      $total = 0;
      if (!empty($danos)) {
        $ins = $conexion->prepare("
          INSERT INTO acta_danio (id_acta, descripcion, monto, foto_url)
          VALUES (:a,:d,:m,:f)
        ");
        foreach ($danos as $d) {
          $desc = trim($d['descripcion'] ?? '');
          $monto= (float)($d['monto'] ?? 0);
          $foto = trim($d['foto_url'] ?? '');
          if ($desc!=='') {
            $ins->execute([':a'=>$id_acta,':d'=>$desc,':m'=>$monto,':f'=>$foto]);
            $total += $monto;
          }
        }
      }

      // Actualiza acta
      $nuevo = ($total>0) ? 'DEVUELTO_DANOS':'DEVUELTO';
      $conexion->prepare("
        UPDATE acta_reserva
           SET estado=:est, fecha_devolucion=NOW(), recibido_por=:u,
               observ_devolucion=:obs, cargo_total=:tot
         WHERE id_acta=:id
      ")->execute([':est'=>$nuevo, ':u'=>$idUser, ':obs'=>$obs, ':tot'=>$total, ':id'=>$id_acta]);

      header('Location: actas.php?ok=devuelto'); exit;
    }

  } catch(Exception $e) {
    header('Location: actas.php?err='.urlencode($e->getMessage())); exit;
  }
}

/* ==== LISTADOS ==== */

/** Pendientes de entrega = reservas APROBADAS sin acta */
$pendEntrega = $conexion->query("
  SELECT r.id_reserva, a.nombre_area, u.nombre||' '||u.apellido AS usuario,
         r.fecha_inicio, r.fecha_fin
  FROM reserva r
  JOIN area_comun a ON a.id_area=r.id_area
  JOIN usuario u ON u.iduser=r.id_usuario
  LEFT JOIN acta_reserva ar ON ar.id_reserva=r.id_reserva
  WHERE r.estado='APROBADA' AND ar.id_acta IS NULL
  ORDER BY r.fecha_inicio ASC
")->fetchAll(PDO::FETCH_ASSOC);

/** Pendientes de devolución = actas ENTREGADO cuyo fin ya pasó */
$pendDevol = $conexion->query("
  SELECT ar.id_acta, r.id_reserva, a.nombre_area, u.nombre||' '||u.apellido AS usuario,
         r.fecha_inicio, r.fecha_fin, ar.fecha_entrega
  FROM acta_reserva ar
  JOIN reserva r ON r.id_reserva=ar.id_reserva
  JOIN area_comun a ON a.id_area=r.id_area
  JOIN usuario u ON u.iduser=r.id_usuario
  WHERE ar.estado='ENTREGADO' AND r.fecha_fin <= NOW()
  ORDER BY r.fecha_fin ASC
")->fetchAll(PDO::FETCH_ASSOC);

/** Últimos cerrados (devolución) */
$cerrados = $conexion->query("
  SELECT ar.id_acta, r.id_reserva, a.nombre_area, u.nombre||' '||u.apellido AS usuario,
         ar.estado, ar.cargo_total, ar.fecha_devolucion
  FROM acta_reserva ar
  JOIN reserva r ON r.id_reserva=ar.id_reserva
  JOIN area_comun a ON a.id_area=r.id_area
  JOIN usuario u ON u.iduser=r.id_usuario
  WHERE ar.estado IN ('DEVUELTO','DEVUELTO_DANOS','CERRADO')
  ORDER BY ar.fecha_devolucion DESC
  LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>iDomus · Actas de Entrega/Devolución</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{ --primary:#0F3557; --acc:#1BAAA6; --text:#333; }
body{background:#f7fafd;font-family:'Segoe UI',Arial,sans-serif;color:var(--text);}
.navbar{background:var(--primary);}
.card-domus{border:0;border-radius:14px;box-shadow:0 6px 18px rgba(15,53,87,.08);background:#fff;}
.table thead th{ background:var(--acc); color:#fff; border:none; }
.badge-soft{ background:#eef7f7; color:#0F3557; font-weight:700;}

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
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand text-white" href="../dashboard/dashboard.php"><i class="bi bi-arrow-left-circle"></i> Volver</a>
  
  <!-- Derecha -->
    <div class="ms-auto d-flex align-items-center gap-3">
      

      <!-- Desktop (≥576px): nombre + rol -->
      <span class="user-chip d-none d-sm-inline-flex">
        <i class="bi bi-person-circle"></i>
        <?= htmlspecialchars($nombre) ?> · <?= htmlspecialchars(ucfirst($rol)) ?>
      </span>

      <!-- Móvil (<576px): versión compacta -->
      <span class="user-chip-sm d-inline-flex d-sm-none">
        <i class="bi bi-person-circle"></i>
        <?= htmlspecialchars(ucfirst($rol)) ?>
      </span>
      <!-- Título -->
      <span class="text-white fw-bold">Actas de Entrega / Devolución</span>
    </div>

</nav>

<div class="container-xxl my-4">
  <?php if(isset($_GET['ok'])): ?>
    <div class="alert alert-success">Operación realizada correctamente.</div>
  <?php elseif(isset($_GET['err'])): ?>
    <div class="alert alert-danger">Error: <?= htmlspecialchars($_GET['err']) ?></div>
  <?php endif; ?>

  <!-- Pendientes de entrega -->
  <div class="card-domus p-3 mb-3">
    <h5 class="mb-3"><i class="bi bi-box-arrow-in-right"></i> Pendientes de entrega</h5>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead><tr>
          <th>#Res</th><th>Área</th><th>Usuario</th><th>Inicio</th><th>Fin</th><th>Acción</th>
        </tr></thead>
        <tbody>
          <?php if(empty($pendEntrega)): ?>
            <tr><td colspan="6" class="text-muted text-center">Sin pendientes</td></tr>
          <?php else: foreach($pendEntrega as $r): ?>
            <tr>
              <td><?= (int)$r['id_reserva'] ?></td>
              <td><?= htmlspecialchars($r['nombre_area']) ?></td>
              <td><?= htmlspecialchars($r['usuario']) ?></td>
              <td><?= date('d/m/Y H:i', strtotime($r['fecha_inicio'])) ?></td>
              <td><?= date('d/m/Y H:i', strtotime($r['fecha_fin'])) ?></td>
              <td>
                <form method="post" class="d-flex gap-2">
                  <input type="hidden" name="action" value="generar_entrega">
                  <input type="hidden" name="id_reserva" value="<?= (int)$r['id_reserva'] ?>">
                  <input name="observ_entrega" class="form-control form-control-sm" placeholder="Obs. entrega (opc)">
                  <button class="btn btn-sm btn-success"><i class="bi bi-check2-circle"></i> Entregar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pendientes de devolución -->
  <div class="card-domus p-3 mb-3">
    <h5 class="mb-3"><i class="bi bi-arrow-return-left"></i> Pendientes de devolución</h5>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead><tr>
          <th>#Acta</th><th>#Res</th><th>Área</th><th>Usuario</th><th>Fin</th><th>Entrega</th><th>Recibir</th>
        </tr></thead>
        <tbody>
          <?php if(empty($pendDevol)): ?>
            <tr><td colspan="7" class="text-muted text-center">Sin pendientes</td></tr>
          <?php else: foreach($pendDevol as $r): ?>
            <tr>
              <td><?= (int)$r['id_acta'] ?></td>
              <td><?= (int)$r['id_reserva'] ?></td>
              <td><?= htmlspecialchars($r['nombre_area']) ?></td>
              <td><?= htmlspecialchars($r['usuario']) ?></td>
              <td><?= date('d/m/Y H:i', strtotime($r['fecha_fin'])) ?></td>
              <td><?= date('d/m/Y H:i', strtotime($r['fecha_entrega'])) ?></td>
              <td>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal"
                        data-bs-target="#modalRecibir"
                        data-id="<?= (int)$r['id_acta'] ?>"
                        data-area="<?= htmlspecialchars($r['nombre_area']) ?>"
                        data-usuario="<?= htmlspecialchars($r['usuario']) ?>">
                  <i class="bi bi-box-arrow-in-down"></i> Recibir
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Últimos cerrados -->
  <div class="card-domus p-3 mb-2">
    <h5 class="mb-3"><i class="bi bi-journal-check"></i> Últimos cerrados</h5>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead><tr>
          <th>#Acta</th><th>#Res</th><th>Área</th><th>Usuario</th><th>Estado</th><th>Cargo</th><th>Devolución</th>
        </tr></thead>
        <tbody>
          <?php if(empty($cerrados)): ?>
            <tr><td colspan="7" class="text-muted text-center">Sin registros</td></tr>
          <?php else: foreach($cerrados as $r): ?>
            <tr>
              <td><?= (int)$r['id_acta'] ?></td>
              <td><?= (int)$r['id_reserva'] ?></td>
              <td><?= htmlspecialchars($r['nombre_area']) ?></td>
              <td><?= htmlspecialchars($r['usuario']) ?></td>
              <td>
                <span class="badge <?= $r['estado']==='DEVUELTO_DANOS'?'bg-danger':'bg-success' ?>">
                  <?= htmlspecialchars($r['estado']) ?>
                </span>
              </td>
              <td>Bs <?= number_format((float)$r['cargo_total'],2) ?></td>
              <td><?= $r['fecha_devolucion'] ? date('d/m/Y H:i', strtotime($r['fecha_devolucion'])):'—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Modal Recibir -->
<div class="modal fade" id="modalRecibir" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="recibir_devolucion">
      <input type="hidden" name="id_acta" id="rec_id_acta" value="0">
      <div class="modal-header">
        <h5 class="modal-title">Recibir devolución · <span id="rec_info"></span></h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Observación de devolución</label>
          <textarea class="form-control" name="observ_devolucion" rows="2" placeholder="Ej. Se devolvió limpio."></textarea>
        </div>

        <div class="border rounded p-2">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong>Daños (opcional)</strong>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addDanio()"><i class="bi bi-plus"></i> Añadir</button>
          </div>
          <div id="danios"></div>
        </div>

        <!-- Campo oculto que llevará el JSON de daños -->
        <input type="hidden" name="danos" id="danos_json" value="[]">
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" onclick="serializeDanios()" type="submit">Recibir devolución</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modalRec = document.getElementById('modalRecibir');
modalRec.addEventListener('show.bs.modal', ev=>{
  const btn = ev.relatedTarget;
  const id  = btn.getAttribute('data-id');
  const area= btn.getAttribute('data-area');
  const usu = btn.getAttribute('data-usuario');
  document.getElementById('rec_id_acta').value = id;
  document.getElementById('rec_info').textContent = area+' · '+usu;
  document.getElementById('danios').innerHTML = '';
  document.getElementById('danos_json').value = '[]';
});

function addDanio(){
  const wrap = document.getElementById('danios');
  const row = document.createElement('div');
  row.className = 'row g-2 align-items-center mb-2';
  row.innerHTML = `
    <div class="col-md-6"><input class="form-control form-control-sm d-desc" placeholder="Descripción del daño"></div>
    <div class="col-md-3"><input type="number" step="0.01" class="form-control form-control-sm d-monto" placeholder="Monto (Bs)"></div>
    <div class="col-md-2"><input class="form-control form-control-sm d-foto" placeholder="Foto URL (opc)"></div>
    <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove()"><i class="bi bi-x"></i></button></div>
  `;
  wrap.appendChild(row);
}

function serializeDanios(){
  const rows = document.querySelectorAll('#danios .row');
  const out = [];
  rows.forEach(r=>{
    const desc = r.querySelector('.d-desc').value.trim();
    const monto= parseFloat(r.querySelector('.d-monto').value || '0');
    const foto = r.querySelector('.d-foto').value.trim();
    if(desc!==''){
      out.push({descripcion:desc, monto:isNaN(monto)?0:monto, foto_url:foto});
    }
  });
  document.getElementById('danos_json').value = JSON.stringify(out);
}
</script>
</body>
</html>