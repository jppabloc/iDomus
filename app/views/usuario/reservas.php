<?php
// app/views/usuario/reservas.php
declare(strict_types=1);
session_start();
require_once '../../models/conexion.php';

if (empty($_SESSION['iduser'])) {
  header('Location: ../login/login.php'); exit;
}
$iduser  = (int)$_SESSION['iduser'];
$nombreS = $_SESSION['nombre'] ?? 'Usuario';
$rolS    = strtolower($_SESSION['rol'] ?? 'usuario');
function is_admin(): bool { return (strtolower($_SESSION['rol'] ?? '') === 'admin'); }

// Cargar áreas para combo
$areas = [];
$st = $conexion->query("SELECT a.id_area, a.nombre_area, e.nombre AS edificio
                        FROM area_comun a
                        JOIN edificio e ON e.id_edificio=a.id_edificio
                        WHERE UPPER(a.estado)='DISPONIBLE'
                        ORDER BY e.nombre, a.nombre_area");
$areas = $st->fetchAll(PDO::FETCH_ASSOC);

// Filtros GET (para inicializar UI)
$id_area = (int)($_GET['id_area'] ?? 0);
$desde   = $_GET['desde'] ?? '';
$hasta   = $_GET['hasta'] ?? '';
$verTodas = (isset($_GET['all']) && $_GET['all']=='1' && is_admin());
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Reservas</title>
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
    .nav-link.active{ color:#fff; background:rgba(255,255,255,.12); border-radius:20px; padding:6px 12px; }
    .nav-link:hover{ color:#fff; background:rgba(255,255,255,.12); border-radius:20px; }
    .card-domus{border:0;border-radius:14px;box-shadow:0 6px 18px rgba(15,53,87,.08);background:#fff;}
    .btn-domus{background:var(--dark);color:#fff;border:none;}
    .btn-domus:hover{background:var(--acc);}
    .table thead th{ background:var(--acc); color:#fff; border:none; }

    .legend-info {
      font-size: 0.9rem;
      color: #333333;
      background: #f2f4f8;
      border-left: 4px solid #1BAAA6;
      padding: 8px 12px;
      border-radius: 8px;
      margin-top: 12px;
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
        <li class="nav-item"><a class="nav-link active" href="./reservas.php">Reservas</a></li>
        <li class="nav-item"><a class="nav-link" href="./pagos.php">Pagos</a></li>
      </ul>

      <div class="dropdown">
        <a class="nav-link dropdown-toggle text-white" href="#" data-bs-toggle="dropdown">
          <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($nombreS) ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li class="dropdown-header small text-muted">Rol: <?= htmlspecialchars($rolS) ?></li>
          <li><a class="dropdown-item" href="./perfil.php"><i class="bi bi-person-badge me-2"></i>Mi perfil</a></li>
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

  <!-- Filtros + acciones -->
  <div class="card-domus p-3 mb-3">
    <form class="row g-2" id="formFiltros">
      <div class="col-12 col-md-3">
        <label class="form-label">Área común</label>
        <select class="form-select" name="id_area" id="f_id_area">
          <option value="0">Todas</option>
          <?php foreach($areas as $a): ?>
            <option value="<?= (int)$a['id_area'] ?>" <?= $id_area==(int)$a['id_area']?'selected':'' ?>>
              <?= htmlspecialchars($a['edificio'].' · '.$a['nombre_area']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Desde</label>
        <input type="date" class="form-control" name="desde" id="f_desde" value="<?= htmlspecialchars($desde) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Hasta</label>
        <input type="date" class="form-control" name="hasta" id="f_hasta" value="<?= htmlspecialchars($hasta) ?>">
      </div>
      <?php if (is_admin()): ?>
      <div class="col-6 col-md-2">
        <label class="form-label">Ver</label>
        <select class="form-select" name="all" id="f_all">
          <option value="0" <?= !$verTodas?'selected':'' ?>>Solo mis reservas</option>
          <option value="1" <?= $verTodas?'selected':'' ?>>Todas (admin)</option>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-6 col-md-3 align-self-end">
        <button type="button" class="btn btn-domus w-100" id="btnFiltrar"><i class="bi bi-funnel me-1"></i>Aplicar</button>
      </div>
    </form>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="m-0">Mis reservas <?= is_admin() && $verTodas ? '(todas)' : '' ?></h5>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" id="btnExportCsv">
        <i class="bi bi-file-earmark-spreadsheet"></i> CSV
      </a>
      <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalReserva">
        <i class="bi bi-calendar-plus"></i> Nueva reserva
      </button>
    </div>
  </div>

  <div class="card-domus p-2">
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="tabla">
        <thead>
          <tr>
            <th>#</th>
            <th>Área</th>
            <th>Edificio</th>
            <th>Inicio</th>
            <th>Fin</th>
            <th>Estado</th>
            <?php if (is_admin()): ?><th>Solicitante</th><?php endif; ?>
            <th style="width:180px;">Acciones</th>
          </tr>
        </thead>
        <tbody id="tbody"></tbody>
      </table>
    </div>
  </div>

  <?php
  // ===== EVENTOS CALENDARIO =====
  $cond = "r.estado='APROBADA'";
  if (!$verTodas && !is_admin()) {
    $cond .= " AND r.id_usuario=$iduser";
  }
  if ($id_area > 0) {
    $cond .= " AND r.id_area=$id_area";
  }
  if ($desde) {
    $cond .= " AND r.fecha_inicio >= '$desde'";
  }
  if ($hasta) {
    $cond .= " AND r.fecha_fin <= '$hasta'";
  }

  $rowsCal = $conexion->query("
    SELECT r.id_reserva, a.nombre_area, e.nombre AS edificio,
          u.nombre || ' ' || u.apellido AS usuario,
          r.fecha_inicio, r.fecha_fin
    FROM reserva r
    JOIN area_comun a ON a.id_area = r.id_area
    JOIN edificio e   ON e.id_edificio = a.id_edificio
    JOIN usuario u    ON u.iduser = r.id_usuario
    WHERE $cond
    ORDER BY r.fecha_inicio
  ")->fetchAll(PDO::FETCH_ASSOC);

  $palette = ['#1BAAA6','#0F3557','#4D4D4D','#333333','#19B9A2','#124870'];
  $events  = [];
  foreach ($rowsCal as $ev) {
    $ix = crc32((string)$ev['nombre_area']) % count($palette);
    $color = $palette[$ix];
    $events[] = [
      'title' => $ev['nombre_area'].' · '.$ev['usuario'],
      'start' => date('c', strtotime($ev['fecha_inicio'])),
      'end'   => date('c', strtotime($ev['fecha_fin'])),
      'backgroundColor' => $color,
      'borderColor' => $color,
      'textColor' => '#fff'
    ];
  }
  ?>
  <div class="card-domus p-3 mt-4">
    <h5><i class="bi bi-calendar-event"></i> Calendario de reservas aprobadas</h5>
    <div id="calendar"></div>
    <div class="legend-info">
      <i class="bi bi-info-circle-fill text-success"></i>
      Los colores del calendario identifican cada área común.  
      Solo se muestran las <strong>reservas aprobadas</strong>.
    </div>
  </div>

</main>

<!-- Modal Nueva Reserva -->
<div class="modal fade" id="modalReserva" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="formReserva">
      <div class="modal-header">
        <h5 class="modal-title">Nueva reserva</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="alertR" class="alert d-none"></div>
        <div class="mb-2">
          <label class="form-label">Área</label>
          <select class="form-select" name="id_area" id="r_id_area" required>
            <option value="">Selecciona…</option>
            <?php foreach($areas as $a): ?>
              <option value="<?= (int)$a['id_area'] ?>">
                <?= htmlspecialchars($a['edificio'].' · '.$a['nombre_area']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row g-2">
          <div class="col-12 col-md-6">
            <label class="form-label">Inicio</label>
            <input type="datetime-local" class="form-control" name="fecha_inicio" id="r_inicio" required>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Fin</label>
            <input type="datetime-local" class="form-control" name="fecha_fin" id="r_fin" required>
          </div>
        </div>
        <div class="mt-2">
          <label class="form-label">Nota (opcional)</label>
          <textarea class="form-control" name="nota" id="r_nota" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-domus" type="submit">Reservar</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API = 'reservas_api.php';

// ------ Helpers UI ------
function showAlert(el, type, msg){
  el.className = 'alert alert-'+type;
  el.textContent = msg;
  el.classList.remove('d-none');
}
function fmt(dt){ return dt ? dt.replace('T',' ').substring(0,16) : ''; }

// ------ Cargar tabla ------
async function loadTable() {
  const params = new URLSearchParams();
  params.set('action','list');
  const id_area = document.getElementById('f_id_area').value;
  const desde   = document.getElementById('f_desde').value;
  const hasta   = document.getElementById('f_hasta').value;
  params.set('id_area', id_area || '0');
  if (desde) params.set('desde',desde);
  if (hasta) params.set('hasta',hasta);
  <?php if (is_admin()): ?>
    const all = document.getElementById('f_all').value;
    params.set('all', all);
  <?php endif; ?>

  const res = await fetch(API+'?'+params.toString(), {cache:'no-store'});
  const js  = await res.json().catch(()=>({success:false,message:'JSON inválido'}));
  const tb  = document.getElementById('tbody');
  tb.innerHTML = '';

  if (!js.success) {
    tb.innerHTML = `<tr><td colspan="<?= is_admin()?8:7 ?>" class="text-center text-danger">${js.message||'Error'}</td></tr>`;
    return;
  }
  if (!js.rows || js.rows.length===0) {
    tb.innerHTML = `<tr><td colspan="<?= is_admin()?8:7 ?>" class="text-center text-muted">Sin resultados</td></tr>`;
    return;
  }

  js.rows.forEach((r,idx)=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${r.id_reserva}</td>
      <td>${r.nombre_area}</td>
      <td>${r.edificio||''}</td>
      <td>${fmt(r.fecha_inicio)}</td>
      <td>${fmt(r.fecha_fin)}</td>
      <td><span class="badge ${r.estado==='APROBADA'?'bg-success':(r.estado==='PENDIENTE'?'bg-warning text-dark':(r.estado==='CANCELADA'?'bg-secondary':'bg-danger'))}">${r.estado}</span></td>
      <?php if (is_admin()): ?>
      <td>${r.solicitante||'—'}</td>
      <?php endif; ?>
      <td>
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-danger" onclick="cancelar(${r.id_reserva})"><i class="bi bi-x-circle"></i> Cancelar</button>
          <?php if (is_admin()): ?>
          <button class="btn btn-outline-success" onclick="aprobar(${r.id_reserva})"><i class="bi bi-check2-circle"></i> Aprobar</button>
          <?php endif; ?>
        </div>
      </td>
    `;
    tb.appendChild(tr);
  });
}

// ------ Filtros ------
document.getElementById('btnFiltrar').addEventListener('click', loadTable);

// ------ Export CSV ------
document.getElementById('btnExportCsv').addEventListener('click', ()=>{
  const params = new URLSearchParams();
  params.set('action','export_csv');
  params.set('id_area', document.getElementById('f_id_area').value || '0');
  const d = document.getElementById('f_desde').value;
  const h = document.getElementById('f_hasta').value;
  if (d) params.set('desde',d);
  if (h) params.set('hasta',h);
  <?php if (is_admin()): ?>
    params.set('all', document.getElementById('f_all').value);
  <?php endif; ?>
  window.location = API+'?'+params.toString();
});

// ------ Crear reserva ------
const formReserva = document.getElementById('formReserva');
const alertR = document.getElementById('alertR');

formReserva.addEventListener('submit', async (e)=>{
  e.preventDefault();
  alertR.classList.add('d-none');
  const fd = new FormData(formReserva);
  fd.set('action','create');

  const res = await fetch(API, { method:'POST', body:fd });
  const js  = await res.json().catch(()=>({success:false,message:'JSON inválido'}));
  if (js.success) {
    showAlert(alertR,'success', js.message||'Creado');
    setTimeout(()=> {
      const m = bootstrap.Modal.getInstance(document.getElementById('modalReserva'));
      m?.hide();
      formReserva.reset();
      loadTable();
    }, 600);
  } else {
    showAlert(alertR,'danger', js.message||'Error');
  }
});

// ------ Cancelar ------
async function cancelar(id){
  if (!confirm('¿Cancelar la reserva #'+id+'?')) return;
  const fd = new FormData();
  fd.set('action','cancel');
  fd.set('id_reserva', id);
  const res = await fetch(API, { method:'POST', body:fd });
  const js = await res.json().catch(()=>({success:false,message:'JSON inválido'}));
  if (js.success) { loadTable(); } else { alert(js.message||'Error'); }
}

// ------ Aprobar (admin) ------
async function aprobar(id){
  if (!confirm('¿Aprobar la reserva #'+id+'?')) return;
  const fd = new FormData();
  fd.set('action','approve');
  fd.set('id_reserva', id);
  const res = await fetch(API, { method:'POST', body:fd });
  const js = await res.json().catch(()=>({success:false,message:'JSON inválido'}));
  if (js.success) { loadTable(); } else { alert(js.message||'Error'); }
}

// Inicial
loadTable();
</script>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/main.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<style>
  .fc .fc-col-header-cell-cushion,
  .fc .fc-daygrid-day-number{
    color:#000 !important;  /* números y días en negro */
    font-weight:600;
  }
  .fc .fc-toolbar-title{ color:#0F3557; }
  .fc .fc-button-primary{ background:#0F3557; border:none; }
  .fc .fc-button-primary:hover{ background:#1BAAA6; }
  .fc .fc-daygrid-event{ border-radius:8px; font-weight:600; }
</style>

<script>
const events = <?= json_encode($events, JSON_UNESCAPED_UNICODE) ?>;
const cal = new FullCalendar.Calendar(document.getElementById('calendar'), {
  initialView: 'dayGridMonth',
  height: 'auto',
  locale: 'es',
  headerToolbar: {
    left:'prev,next today',
    center:'title',
    right:'dayGridMonth,timeGridWeek,timeGridDay'
  },
  events,
  nowIndicator:true,
  dayMaxEventRows:3,
  eventDisplay:'block'
});
cal.render();
</script>
</body>
</html>