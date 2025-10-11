<?php
// app/views/dashboard/finanzas.php
session_start();
require_once '../../models/conexion.php';

// ---------- Guardia: solo admin ----------
if (empty($_SESSION['iduser']) || (($_SESSION['rol'] ?? '') !== 'admin')) {
  header('Location: ../login/login.php');
  exit;
}

// Datos de sesión para el chip de usuario en navbar
$nombre = $_SESSION['nombre'] ?? 'Administrador';
$rol    = $_SESSION['rol'] ?? 'admin';


// ---------- Utilidades ----------
function sql_date($s) {
  // espera formato YYYY-MM-DD (desde <input type=date>)
  return preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $s) ? $s : null;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- Parámetros de filtro ----------
$hoy    = new DateTimeImmutable('today');
$desdeD = $hoy->modify('first day of -5 month')->format('Y-m-d'); // default: últimos 6 meses
$hastaD = $hoy->format('Y-m-d');

$from   = sql_date($_GET['from'] ?? $desdeD) ?: $desdeD;
$to     = sql_date($_GET['to']   ?? $hastaD) ?: $hastaD;
$estado = trim($_GET['estado']   ?? ''); // '', PAGADO, PENDIENTE, ANULADO

$id_edificio = (int)($_GET['id_edificio'] ?? 0);
$id_bloque   = (int)($_GET['id_bloque']   ?? 0);
$id_unidad   = (int)($_GET['id_unidad']   ?? 0);

// ---------- Catálogos para selects (cascada) ----------
$edificios = $conexion->query("SELECT id_edificio, nombre FROM edificio ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$bloques   = $conexion->query("SELECT id_bloque, id_edificio, nombre FROM bloque ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$unidades  = $conexion->query("SELECT id_unidad, id_bloque, nro_unidad FROM unidad ORDER BY nro_unidad")->fetchAll(PDO::FETCH_ASSOC);

// ---------- Construir WHERE dinámico ----------
$where = [];
$params = [];

$where[] = "p.fecha_pago BETWEEN :from AND :to";
$params[':from'] = $from;
$params[':to']   = $to;

if ($estado !== '') {
  $where[] = "UPPER(p.estado) = :estado";
  $params[':estado'] = strtoupper($estado);
}
if ($id_unidad > 0) {
  $where[] = "un.id_unidad = :id_unidad";
  $params[':id_unidad'] = $id_unidad;
} elseif ($id_bloque > 0) {
  $where[] = "b.id_bloque = :id_bloque";
  $params[':id_bloque'] = $id_bloque;
} elseif ($id_edificio > 0) {
  $where[] = "e.id_edificio = :id_edificio";
  $params[':id_edificio'] = $id_edificio;
}
$whereSQL = $where ? ("WHERE ".implode(" AND ", $where)) : "";

// ---------- Consulta base (pagos + datos de ubicación) ----------
$sqlBase = "
  FROM pago p
  JOIN usuario u ON u.iduser = p.id_usuario
  LEFT JOIN residente_unidad ru ON ru.id_usuario = u.iduser
  LEFT JOIN unidad un ON un.id_unidad = ru.id_unidad
  LEFT JOIN bloque b ON b.id_bloque = un.id_bloque
  LEFT JOIN edificio e ON e.id_edificio = b.id_edificio
  $whereSQL
";

// ---------- KPIs ----------
/** Total registros del rango */
$st = $conexion->prepare("SELECT COUNT(*) $sqlBase");
$st->execute($params);
$total_reg = (int)$st->fetchColumn();

/** Total pagado (solo estado PAGADO si no se filtró otro estado) */
$st = $conexion->prepare("SELECT COALESCE(SUM(CASE WHEN UPPER(p.estado)='PAGADO' THEN p.monto ELSE 0 END),0) $sqlBase");
$st->execute($params);
$total_pagado = (float)$st->fetchColumn();

/** Promedio mensual (suma pagado / #meses del rango) */
$dt_from = DateTime::createFromFormat('Y-m-d', $from);
$dt_to   = DateTime::createFromFormat('Y-m-d', $to);
$months  = 1;
if ($dt_from && $dt_to) {
  $y1=$dt_from->format('Y'); $m1=$dt_from->format('m');
  $y2=$dt_to->format('Y');   $m2=$dt_to->format('m');
  $months = (($y2 - $y1) * 12) + ($m2 - $m1) + 1;
  if ($months <= 0) $months = 1;
}
$prom_mensual = $total_pagado / $months;

/** Top usuario (quien más pagó en el rango) */
$st = $conexion->prepare("
  SELECT u.nombre, u.apellido, SUM(p.monto) AS total_u
  $sqlBase
  GROUP BY u.iduser, u.nombre, u.apellido
  ORDER BY total_u DESC
  LIMIT 1
");
$st->execute($params);
$top_row = $st->fetch(PDO::FETCH_ASSOC);
$top_usuario = $top_row ? ($top_row['nombre'].' '.$top_row['apellido']) : '—';
$top_total   = $top_row ? (float)$top_row['total_u'] : 0;

/** Serie mensual (sum monto por mes, normalmente PAGADO) */
$st = $conexion->prepare("
  SELECT TO_CHAR(DATE_TRUNC('month', p.fecha_pago), 'YYYY-MM') AS ym,
         SUM(CASE WHEN UPPER(p.estado)='PAGADO' THEN p.monto ELSE 0 END) AS total_mes
  $sqlBase
  GROUP BY ym
  ORDER BY ym
");
$st->execute($params);
$rowsMes = $st->fetchAll(PDO::FETCH_ASSOC);

// Construir ejes completos mes a mes dentro del rango
$labels = [];
$data   = [];
$map    = [];
foreach ($rowsMes as $r) { $map[$r['ym']] = (float)$r['total_mes']; }
$start  = (new DateTime($from))->modify('first day of this month')->setTime(0,0);
$end    = (new DateTime($to))->modify('first day of next month')->setTime(0,0);
$period = new DatePeriod($start, new DateInterval('P1M'), $end);
foreach ($period as $dt) {
  $k = $dt->format('Y-m');
  $labels[] = $k;
  $data[]   = $map[$k] ?? 0.0;
}

/** Tabla de pagos (detallada) */
$st = $conexion->prepare("
  SELECT 
    p.id_pago, p.fecha_pago, p.monto, p.estado, p.concepto,
    u.nombre, u.apellido, u.correo,
    e.nombre AS edificio, b.nombre AS bloque, un.nro_unidad
  $sqlBase
  ORDER BY p.fecha_pago DESC, p.id_pago DESC
");
$st->execute($params);
$pagos = $st->fetchAll(PDO::FETCH_ASSOC);

// ---------- Export CSV (mismos filtros) ----------
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="finanzas_'.date('Ymd_His').'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Fecha','Monto','Estado','Concepto','Nombre','Apellido','Correo','Edificio','Bloque','Unidad']);
  foreach ($pagos as $p) {
    fputcsv($out, [
      $p['id_pago'],
      $p['fecha_pago'],
      number_format((float)$p['monto'], 2, '.', ''),
      $p['estado'],
      $p['concepto'],
      $p['nombre'],
      $p['apellido'],
      $p['correo'],
      $p['edificio'],
      $p['bloque'],
      $p['nro_unidad'],
    ]);
  }
  fclose($out);
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>iDomus · Finanzas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --accent:#1BAAA6; --dark:#0F3557; }
    body { background:#f7fafd; font-family:'Segoe UI', Arial, sans-serif; }
    .navbar { background:#023047; }
    .brand-badge{ color:#fff; font-weight:700; }
    .container-xxl { max-width: 1200px; }
    .card-kpi{ border:none; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    .card-chart{ border:none; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    .kpi-title{ font-size:.9rem; color:#6b7a8a; }
    .kpi-value{ font-size:1.6rem; font-weight:800; color:#0F3557; }
    .btn-domus{ background:var(--dark); color:#fff; border:none; border-radius:10px; padding:.55rem .9rem; font-weight:600; }
    .btn-domus:hover{ background:var(--accent); }
    .table thead th { background: var(--accent); color:#fff; border:none; }
    .small-muted{ color:#6b7a8a; font-size:.85rem; }
    /* Print-friendly */
    @media print{
      .no-print{ display:none!important; }
      .card { box-shadow:none!important; }
    }
  .user-chip{
      background:#e9f7f6; 
      color:#0F3557; 
      border-radius:20px; 
      padding:.35rem .6rem; 
      font-weight:600; 
      display:inline-flex; 
      gap:.4rem; 
      align-items:center; 
    }
  .user-chip i{ color:#1BAAA6; }
  .user-chip-sm{
    background:#e9f7f6;
    color:#0F3557;
    border-radius:18px;
    padding:.25rem .45rem;
    font-weight:600;
    display:inline-flex;
    gap:.35rem;
    align-items:center;
    font-size:.9rem;
  }
  .user-chip-sm i{ color:#1BAAA6; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark sticky-top shadow-sm no-print">
  <div class="container-fluid">
    <a href="dashboard.php" class="btn btn-outline-light me-2"><i class="bi bi-arrow-left"></i></a>
    <span class="brand-badge">iDomus · Finanzas</span>
    <div class="d-flex align-items-center gap-2">
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
      <a href="../home/home.php" class="btn btn-sm btn-outline-light"><i class="bi bi-house-door"></i> Home</a>
      <a href="../login/login.php?logout=1" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right"></i> Salir</a>
    </div>
  </div>
</nav>

<div class="container-xxl my-4">

  <!-- Filtros -->
  <div class="card p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-12 col-md-2">
        <label class="form-label small">Desde</label>
        <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label small">Hasta</label>
        <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label small">Estado</label>
        <select name="estado" class="form-select">
          <option value="" <?= $estado===''?'selected':'' ?>>Todos</option>
          <option value="PAGADO"   <?= $estado==='PAGADO'?'selected':'' ?>>PAGADO</option>
          <option value="PENDIENTE"<?= $estado==='PENDIENTE'?'selected':'' ?>>PENDIENTE</option>
          <option value="ANULADO"  <?= $estado==='ANULADO'?'selected':'' ?>>ANULADO</option>
        </select>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label small">Edificio</label>
        <select id="selEdificio" name="id_edificio" class="form-select">
          <option value="0">Todos</option>
          <?php foreach($edificios as $e): ?>
            <option value="<?= (int)$e['id_edificio'] ?>" <?= $id_edificio==(int)$e['id_edificio']?'selected':'' ?>>
              <?= h($e['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label small">Bloque</label>
        <select id="selBloque" name="id_bloque" class="form-select">
          <option value="0">Todos</option>
          <!-- Se llena por JS -->
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label small">Unidad</label>
        <select id="selUnidad" name="id_unidad" class="form-select">
          <option value="0">Todas</option>
          <!-- Se llena por JS -->
        </select>
      </div>

      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-domus"><i class="bi bi-funnel"></i> Filtrar</button>
        <a class="btn btn-outline-secondary" href="finanzas.php"><i class="bi bi-eraser"></i> Limpiar</a>

        <a class="btn btn-outline-success ms-auto" 
           href="?from=<?=h($from)?>&to=<?=h($to)?>&estado=<?=h($estado)?>&id_edificio=<?=$id_edificio?>&id_bloque=<?=$id_bloque?>&id_unidad=<?=$id_unidad?>&export=csv">
          <i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV
        </a>
        <button type="button" class="btn btn-outline-danger" onclick="window.print()">
          <i class="bi bi-filetype-pdf"></i> Exportar PDF
        </button>
      </div>
      <div class="col-12 small-muted">
        * El PDF usa la función de impresión del navegador (Guardar como PDF). No requiere librerías.
      </div>
    </form>
  </div>

  <!-- KPIs -->
  <div class="row g-3">
    <div class="col-12 col-md-3">
      <div class="card card-kpi p-3">
        <div class="kpi-title">Total registros</div>
        <div class="kpi-value"><?= number_format($total_reg) ?></div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-kpi p-3">
        <div class="kpi-title">Total pagado</div>
        <div class="kpi-value">Bs <?= number_format($total_pagado,2) ?></div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-kpi p-3">
        <div class="kpi-title">Promedio mensual</div>
        <div class="kpi-value">Bs <?= number_format($prom_mensual,2) ?></div>
        <div class="small-muted"><?= $months ?> mes(es)</div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-kpi p-3">
        <div class="kpi-title">Top aportante</div>
        <div class="kpi-value"><?= h($top_usuario) ?></div>
        <div class="small-muted">Bs <?= number_format($top_total,2) ?></div>
      </div>
    </div>
  </div>

  <!-- Gráfica -->
  <div class="card card-chart p-3 my-3">
    <div class="d-flex justify-content-between align-items-center">
      <h6 class="mb-0">Pagos por mes (PAGADO)</h6>
      <span class="small-muted"><?= h($from) ?> → <?= h($to) ?></span>
    </div>
    <canvas id="chartPagos" height="130"></canvas>
  </div>

  <!-- Tabla -->
  <div class="card p-3 my-3">
    <h6>Pagos del rango</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Fecha</th>
            <th>Monto</th>
            <th>Estado</th>
            <th>Concepto</th>
            <th>Nombre</th>
            <th>Apellido</th>
            <th>Correo</th>
            <th>Edificio</th>
            <th>Bloque</th>
            <th>Unidad</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$pagos): ?>
            <tr><td colspan="11" class="text-center text-muted">Sin datos para el filtro.</td></tr>
          <?php else: ?>
            <?php foreach($pagos as $p): ?>
              <tr>
                <td><?= (int)$p['id_pago'] ?></td>
                <td><?= h($p['fecha_pago']) ?></td>
                <td>Bs <?= number_format((float)$p['monto'],2) ?></td>
                <td><?= h($p['estado']) ?></td>
                <td><?= h($p['concepto']) ?></td>
                <td><?= h($p['nombre']) ?></td>
                <td><?= h($p['apellido']) ?></td>
                <td><?= h($p['correo']) ?></td>
                <td><?= h($p['edificio']) ?></td>
                <td><?= h($p['bloque']) ?></td>
                <td><?= h($p['nro_unidad']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  // Datos para la gráfica
  const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  const data   = <?= json_encode($data) ?>;
  const ctx    = document.getElementById('chartPagos').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Bs',
        data: data,
        backgroundColor: '#1BAAA6',
        borderRadius: 6
      }]
    },
    options: {
      responsive: true,
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true } }
    }
  });

  // --- Selects en cascada (edificio -> bloque -> unidad) ---
  const bloques  = <?= json_encode($bloques, JSON_UNESCAPED_UNICODE) ?>;
  const unidades = <?= json_encode($unidades, JSON_UNESCAPED_UNICODE) ?>;

  const selE = document.getElementById('selEdificio');
  const selB = document.getElementById('selBloque');
  const selU = document.getElementById('selUnidad');

  const selB_value = <?= (int)$id_bloque ?>;
  const selU_value = <?= (int)$id_unidad ?>;

  function fillBloques(){
    const eid = parseInt(selE.value||'0',10);
    selB.innerHTML = '<option value="0">Todos</option>';
    bloques.filter(b=> !eid || b.id_edificio===eid)
           .forEach(b=>{
             const opt = document.createElement('option');
             opt.value = b.id_bloque;
             opt.textContent = b.nombre;
             if (selB_value && selB_value==b.id_bloque) opt.selected = true;
             selB.appendChild(opt);
           });
    fillUnidades();
  }
  function fillUnidades(){
    const bid = parseInt(selB.value||'0',10);
    selU.innerHTML = '<option value="0">Todas</option>';
    unidades.filter(u=> !bid || u.id_bloque===bid)
            .forEach(u=>{
              const opt = document.createElement('option');
              opt.value = u.id_unidad;
              opt.textContent = u.nro_unidad;
              if (selU_value && selU_value==u.id_unidad) opt.selected = true;
              selU.appendChild(opt);
            });
  }
  selE.addEventListener('change', ()=>{ selB.value=0; selU.value=0; fillBloques(); });
  selB.addEventListener('change', ()=>{ selU.value=0; fillUnidades(); });

  // Inicializar con lo que vino del servidor
  fillBloques();
</script>
</body>
</html>