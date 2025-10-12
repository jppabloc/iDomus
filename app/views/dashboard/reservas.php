<?php
// app/views/dashboard/reservas.php
declare(strict_types=1);
session_start();
require_once '../../models/conexion.php';

// --- SOLO ADMIN ---
if (empty($_SESSION['iduser']) || (($_SESSION['rol'] ?? '') !== 'admin')) {
  header('Location: ../login/login.php'); exit;
}
$idAdmin = (int)$_SESSION['iduser'];

// ===== Helpers UI =====
function estado_badge(string $estado): string {
  $e = strtoupper(trim($estado));
  return match($e){
    'APROBADA'  => 'bg-success',
    'CANCELADA' => 'bg-danger',
    'PENDIENTE' => 'bg-warning text-dark',
    'RECHAZADA' => 'bg-secondary',
    default     => 'bg-secondary'
  };
}
// Normaliza fechas (dd/mm/aaaa o yyyy-mm-ddTHH:MM)
function normDate(?string $d): ?string {
  $d = trim((string)$d);
  if ($d==='') return null;
  if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#',$d,$m)) {
    return "{$m[3]}-{$m[2]}-{$m[1]}";
  }
  if (preg_match('#^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}#',$d)) {
    return str_replace('T',' ',substr($d,0,16));
  }
  return $d;
}

// ===== INSERTAR NUEVA RESERVA (ADMIN) =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='crear') {
  $id_area    = (int)($_POST['id_area'] ?? 0);
  $id_usuario = (int)($_POST['id_usuario'] ?? 0);
  $inicio     = normDate($_POST['fecha_inicio'] ?? '');
  $fin        = normDate($_POST['fecha_fin'] ?? '');
  $nota       = trim($_POST['nota'] ?? '');

  if ($id_area && $id_usuario && $inicio && $fin) {
    // Evita NOTICES en salida (por triggers)
    $conexion->exec("SET client_min_messages TO WARNING");

    // Validación solapamiento (PENDIENTE/APROBADA) para la misma área
    $sqlOv = "SELECT 1
              FROM reserva
              WHERE id_area=:a
                AND estado IN ('PENDIENTE','APROBADA')
                AND tstzrange(fecha_inicio, fecha_fin, '[)') &&
                    tstzrange(:i::timestamp, :f::timestamp, '[)')
              LIMIT 1";
    $stOv = $conexion->prepare($sqlOv);
    $stOv->execute([':a'=>$id_area, ':i'=>$inicio, ':f'=>$fin]);
    if ($stOv->fetchColumn()) {
      header('Location: reservas.php?err=solapamiento'); exit;
    }

    // Inserta y devuelve id_reserva
    $sql = "INSERT INTO reserva (id_area, id_usuario, fecha_inicio, fecha_fin, estado, nota)
            VALUES (:a,:u,:i,:f,'PENDIENTE',:n)
            RETURNING id_reserva";
    $st = $conexion->prepare($sql);
    $st->execute([':a'=>$id_area, ':u'=>$id_usuario, ':i'=>$inicio, ':f'=>$fin, ':n'=>$nota]);
    $idNew = (int)$st->fetchColumn();

    // (opcional) auditoría si existe la tabla
    try {
      $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, modulo, ip_origen)
                          VALUES (:u,:acc,'Reservas',:ip)")
               ->execute([
                 ':u'=>$idAdmin,
                 ':acc'=>"Creó reserva #$idNew para usuario $id_usuario",
                 ':ip'=>$_SERVER['REMOTE_ADDR'] ?? 'cli'
               ]);
    } catch (\Throwable $e) { /* ignorar si no existe tabla */ }

    header('Location: reservas.php?ok=1'); exit;
  } else {
    header('Location: reservas.php?err=datos'); exit;
  }
}

// ===== CONFIRMAR (ADMIN) =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='confirmar') {
  $id = (int)($_POST['id_reserva'] ?? 0);
  if ($id > 0) {
    try {
      $conexion->prepare("
        UPDATE reserva
           SET estado='APROBADA', aprobado_por=:adm, fecha_aprobacion=NOW()
         WHERE id_reserva=:id
      ")->execute([':adm'=>$idAdmin, ':id'=>$id]);
    } catch (\Throwable $e) {
      // fallback si no existen columnas
      $conexion->prepare("UPDATE reserva SET estado='APROBADA' WHERE id_reserva=:id")
               ->execute([':id'=>$id]);
    }
  }
  header('Location: reservas.php'); exit;
}

// ===== CANCELAR (ADMIN) =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='cancelar') {
  $id = (int)($_POST['id_reserva'] ?? 0);
  $motivo = trim($_POST['motivo_cancelacion'] ?? '');
  if ($id > 0) {
    try {
      $conexion->prepare("
        UPDATE reserva
           SET estado='CANCELADA',
               cancelado_por=:adm,
               fecha_cancelacion=NOW(),
               motivo_cancelacion=:mot
         WHERE id_reserva=:id
      ")->execute([':adm'=>$idAdmin, ':mot'=>$motivo, ':id'=>$id]);
    } catch (\Throwable $e) {
      $conexion->prepare("UPDATE reserva SET estado='CANCELADA' WHERE id_reserva=:id")
               ->execute([':id'=>$id]);
    }
  }
  header('Location: reservas.php'); exit;
}

// ===== LISTA RESERVAS =====
try {
  $sqlLista = "
    SELECT r.id_reserva,
           a.nombre_area,
           (u.nombre||' '||u.apellido) AS usuario,
           r.fecha_inicio, r.fecha_fin, r.estado, r.nota,
           r.fecha_aprobacion, r.fecha_cancelacion, r.motivo_cancelacion,
           ua.nombre||' '||ua.apellido AS admin_aprobo,
           uc.nombre||' '||uc.apellido AS admin_cancelo
      FROM reserva r
      JOIN area_comun a ON a.id_area=r.id_area
      JOIN usuario u    ON u.iduser=r.id_usuario
 LEFT JOIN usuario ua   ON ua.iduser=r.aprobado_por
 LEFT JOIN usuario uc   ON uc.iduser=r.cancelado_por
  ORDER BY r.fecha_inicio DESC";
  $reservas = $conexion->query($sqlLista)->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
  // versión mínima si no existen columnas de auditoría
  $sqlLista = "
    SELECT r.id_reserva,
           a.nombre_area,
           (u.nombre||' '||u.apellido) AS usuario,
           r.fecha_inicio, r.fecha_fin, r.estado, r.nota
      FROM reserva r
      JOIN area_comun a ON a.id_area=r.id_area
      JOIN usuario u    ON u.iduser=r.id_usuario
  ORDER BY r.fecha_inicio DESC";
  $reservas = $conexion->query($sqlLista)->fetchAll(PDO::FETCH_ASSOC);
}

// ===== COMBOS: ÁREAS y USUARIOS =====
$areas = $conexion->query("
  SELECT id_area, nombre_area
    FROM area_comun
   WHERE UPPER(estado)='DISPONIBLE'
ORDER BY nombre_area")->fetchAll(PDO::FETCH_ASSOC);

$usuarios = $conexion->query("
  SELECT iduser, (nombre||' '||apellido) AS nombre
    FROM usuario
ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// ===== EVENTOS CALENDARIO (APROBADAS) =====
$rowsCal = $conexion->query("
  SELECT r.id_reserva, a.nombre_area,
         (u.nombre||' '||u.apellido) AS usuario,
         r.fecha_inicio, r.fecha_fin
    FROM reserva r
    JOIN area_comun a ON a.id_area=r.id_area
    JOIN usuario u    ON u.iduser=r.id_usuario
   WHERE r.estado='APROBADA'
ORDER BY r.fecha_inicio")->fetchAll(PDO::FETCH_ASSOC);

$palette = ['#1BAAA6','#0F3557','#4D4D4D','#333333','#19B9A2','#124870'];
$events = [];
foreach($rowsCal as $ev){
  $ix = crc32((string)$ev['nombre_area']) % count($palette);
  $color = $palette[$ix];
  $events[] = [
    'id'    => (int)$ev['id_reserva'],
    'title' => $ev['nombre_area'].' · '.$ev['usuario'],
    'start' => date('c', strtotime($ev['fecha_inicio'])),
    'end'   => date('c', strtotime($ev['fecha_fin'])),
    'backgroundColor' => $color,
    'borderColor'     => $color,
    'textColor'       => '#fff'
  ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reservas · iDomus (Admin)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/main.min.css" rel="stylesheet">
  <style>
    :root{ --primary:#0F3557; --secondary:#1BAAA6; --tertiary:#4D4D4D; --text:#333333; }
    body{ background:#f7fafd; font-family:'Segoe UI',Arial,sans-serif; color:var(--text);}
    .navbar{ background:var(--primary); }
    .btn-domus{ background:var(--primary); color:#fff; border:none; }
    .btn-domus:hover{ background:var(--secondary); }
    table thead th{ background:var(--secondary); color:#fff; }
    .small-muted{ font-size:.85rem; color:#6b7a8a; }

    /* FullCalendar */
    .fc .fc-col-header-cell-cushion,
    .fc .fc-daygrid-day-number{ color:#000 !important; font-weight:600; }
    .fc .fc-toolbar-title{ color:var(--primary); }
    .fc .fc-button-primary{ background:var(--primary); border:none; }
    .fc .fc-button-primary:hover{ background:var(--secondary); }
    .fc .fc-daygrid-event{ border-radius:8px; font-weight:600; }
  </style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand text-white" href="dashboard.php"><i class="bi bi-arrow-left-circle"></i> Volver</a>
  <span class="text-white fw-bold">Gestión de Reservas (Admin)</span>
</nav>

<div class="container my-4">
  <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success">✅ Reserva registrada correctamente.</div>
  <?php endif; ?>
  <?php if (isset($_GET['err']) && $_GET['err']==='solapamiento'): ?>
    <div class="alert alert-warning">⚠ El rango seleccionado se solapa con otra reserva PENDIENTE/APROBADA para esa área.</div>
  <?php elseif(isset($_GET['err'])): ?>
    <div class="alert alert-danger">❌ Datos incompletos.</div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Reservas registradas</h4>
    <button class="btn btn-domus" data-bs-toggle="modal" data-bs-target="#modalNueva">+ Nueva reserva</button>
  </div>

  <div class="table-responsive shadow-sm mb-4">
    <table class="table table-bordered table-striped align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>Área común</th>
          <th>Usuario</th>
          <th>Inicio</th>
          <th>Fin</th>
          <th>Estado</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($reservas as $i=>$r):
        $estado = strtoupper(trim($r['estado'] ?? ''));
        $badge  = estado_badge($estado);
      ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($r['nombre_area'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['usuario'] ?? '') ?></td>
          <td><?= !empty($r['fecha_inicio'])? date('d/m/Y H:i',strtotime($r['fecha_inicio'])):'—' ?></td>
          <td><?= !empty($r['fecha_fin'])? date('d/m/Y H:i',strtotime($r['fecha_fin'])):'—' ?></td>
          <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($estado) ?></span></td>
          <td>
            <?php if ($estado==='PENDIENTE'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="confirmar">
                <input type="hidden" name="id_reserva" value="<?= (int)$r['id_reserva'] ?>">
                <button class="btn btn-sm btn-outline-success"><i class="bi bi-check2-circle"></i> Confirmar</button>
              </form>
              <button class="btn btn-sm btn-outline-danger"
                      data-bs-toggle="modal"
                      data-bs-target="#modalCancelar"
                      data-id="<?= (int)$r['id_reserva'] ?>">
                <i class="bi bi-x-circle"></i> Cancelar
              </button>
            <?php elseif ($estado==='APROBADA'): ?>
              <div class="small-muted">
                <i class="bi bi-person-check"></i>
                Aprobada por: <b><?= htmlspecialchars($r['admin_aprobo'] ?? '—') ?></b><br>
                <i class="bi bi-clock"></i>
                <?= !empty($r['fecha_aprobacion'])? date('d/m/Y H:i',strtotime($r['fecha_aprobacion'])): '—' ?>
              </div>
            <?php elseif ($estado==='CANCELADA'): ?>
              <div class="small-muted">
                <i class="bi bi-person-x"></i>
                Cancelada por: <b><?= htmlspecialchars($r['admin_cancelo'] ?? '—') ?></b><br>
                <i class="bi bi-clock"></i>
                <?= !empty($r['fecha_cancelacion'])? date('d/m/Y H:i',strtotime($r['fecha_cancelacion'])): '—' ?><br>
                <?php if(!empty($r['motivo_cancelacion'])): ?>
                  <i class="bi bi-chat-left-text"></i>
                  Motivo: <em><?= htmlspecialchars($r['motivo_cancelacion']) ?></em>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span class="badge <?= $badge ?>"><?= htmlspecialchars($estado) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Calendario -->
  <div class="card p-3 shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Calendario de Reservas (APROBADAS)</h5>
    </div>
    <div id="calendar"></div>
  </div>
</div>

<!-- Modal Nueva reserva -->
<div class="modal fade" id="modalNueva" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="">
        <input type="hidden" name="action" value="crear">
        <div class="modal-header">
          <h5 class="modal-title">Nueva reserva</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Área común</label>
            <select class="form-select" name="id_area" required>
              <option value="">Seleccione…</option>
              <?php foreach($areas as $a): ?>
                <option value="<?= (int)$a['id_area'] ?>"><?= htmlspecialchars($a['nombre_area']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Usuario solicitante</label>
            <select class="form-select" name="id_usuario" required>
              <option value="">Seleccione…</option>
              <?php foreach($usuarios as $u): ?>
                <option value="<?= (int)$u['iduser'] ?>"><?= htmlspecialchars($u['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2">
            <div class="col-12 col-md-6">
              <label class="form-label">Fecha inicio</label>
              <input type="datetime-local" name="fecha_inicio" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Fecha fin</label>
              <input type="datetime-local" name="fecha_fin" class="form-control" required>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">Nota / descripción</label>
            <textarea name="nota" class="form-control" rows="2" placeholder="Ej. Reunión vecinal…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button type="submit" class="btn btn-domus">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Cancelar (con motivo) -->
<div class="modal fade" id="modalCancelar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="">
      <input type="hidden" name="action" value="cancelar">
      <input type="hidden" name="id_reserva" id="cancel_id" value="0">
      <div class="modal-header">
        <h5 class="modal-title">Cancelar reserva</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>¿Desea cancelar esta reserva? (opcional) Indique el motivo:</p>
        <textarea name="motivo_cancelacion" class="form-control" rows="3" placeholder="Motivo de cancelación"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="submit" class="btn btn-outline-danger">Cancelar reserva</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
  // Pasa id al modal de cancelar
  const modalCancelar = document.getElementById('modalCancelar');
  if (modalCancelar) {
    modalCancelar.addEventListener('show.bs.modal', ev=>{
      const id = ev.relatedTarget?.getAttribute('data-id') || '0';
      document.getElementById('cancel_id').value = id;
    });
  }

  // FullCalendar
  const events = <?= json_encode($events, JSON_UNESCAPED_UNICODE) ?>;
  const cal = new FullCalendar.Calendar(document.getElementById('calendar'), {
    initialView: 'dayGridMonth',
    height: 'auto',
    locale: 'es',
    headerToolbar: { left:'prev,next today', center:'title', right:'dayGridMonth,timeGridWeek,timeGridDay' },
    events,
    eventTimeFormat: { hour: '2-digit', minute:'2-digit', hour12:false },
    nowIndicator: true,
    dayMaxEventRows: 3,
    eventDisplay: 'block'
  });
  cal.render();
</script>
</body>
</html>