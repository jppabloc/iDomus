<?php
// app/views/dashboard/consumos.php
session_start();
require_once '../../models/conexion.php';

// --------- Guardas y rol admin ---------
if (empty($_SESSION['iduser']) || ($_SESSION['rol'] ?? '') !== 'admin') {
  header('Location: ../login/login.php');
  exit;
}

// ======= Helpers: cargar combos =======
function getEdificios(PDO $db): array {
  $st = $db->query("SELECT id_edificio, nombre FROM edificio ORDER BY nombre");
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function getBloques(PDO $db, ?int $id_edificio): array {
  if (!$id_edificio) return [];
  $st = $db->prepare("SELECT id_bloque, nombre FROM bloque WHERE id_edificio = :e ORDER BY nombre");
  $st->execute([':e'=>$id_edificio]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function getUnidades(PDO $db, ?int $id_bloque): array {
  if (!$id_bloque) return [];
  $st = $db->prepare("SELECT id_unidad, nro_unidad FROM unidad WHERE id_bloque = :b ORDER BY nro_unidad");
  $st->execute([':b'=>$id_bloque]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ======= Filtros desde GET =======
$view        = (isset($_GET['view']) && $_GET['view']==='usuario') ? 'usuario' : 'unidad';
$id_edificio = isset($_GET['edificio']) ? (int)$_GET['edificio'] : 0;
$id_bloque   = isset($_GET['bloque'])   ? (int)$_GET['bloque']   : 0;
$id_unidad   = isset($_GET['unidad'])   ? (int)$_GET['unidad']   : 0;
$tipo        = isset($_GET['tipo'])     ? strtoupper(trim($_GET['tipo'])) : 'ALL';

// Fechas (por defecto: mes actual)
$hoy         = (new DateTime())->format('Y-m-d');
$mesInicio   = (new DateTime('first day of this month'))->format('Y-m-d');
$desde       = isset($_GET['desde']) && $_GET['desde'] ? $_GET['desde'] : $mesInicio;
$hasta       = isset($_GET['hasta']) && $_GET['hasta'] ? $_GET['hasta'] : $hoy;

// Combos
$edificios = getEdificios($conexion);
$bloques   = getBloques($conexion, $id_edificio ?: null);
$unidades  = getUnidades($conexion, $id_bloque ?: null);

// ======= Construcción WHERE + parámetros =======
$params = [];
$WHERE  = "WHERE 1=1";

// Rango fechas: usamos DATE(c.fecha_registro)
$WHERE .= " AND DATE(c.fecha_registro) BETWEEN :d1 AND :d2";
$params[':d1'] = $desde;
$params[':d2'] = $hasta;

// Tipo consumo
if ($tipo && $tipo !== 'ALL') {
  $WHERE .= " AND UPPER(c.tipo) = :tipo";
  $params[':tipo'] = $tipo;
}

// Filtrado por ubicación
if ($id_unidad) {
  $WHERE .= " AND c.id_unidad = :u";
  $params[':u'] = $id_unidad;
} else {
  if ($id_bloque) {
    $WHERE .= " AND u.id_bloque = :b";
    $params[':b'] = $id_bloque;
  }
  if ($id_edificio) {
    $WHERE .= " AND e.id_edificio = :e";
    $params[':e'] = $id_edificio;
  }
}

// ======= Totales (KPI) =======
$sqlTot = "
SELECT 
  COALESCE(SUM(CASE WHEN UPPER(c.tipo)='AGUA'    THEN c.cantidad ELSE 0 END),0)    AS total_agua,
  COALESCE(SUM(CASE WHEN UPPER(c.tipo)='ENERGIA' THEN c.cantidad ELSE 0 END),0)    AS total_energia
FROM consumo c
JOIN unidad   u ON u.id_unidad  = c.id_unidad
JOIN bloque   b ON b.id_bloque  = u.id_bloque
JOIN edificio e ON e.id_edificio= b.id_edificio
$WHERE
";
$st = $conexion->prepare($sqlTot);
$st->execute($params);
$tot = $st->fetch(PDO::FETCH_ASSOC) ?: ['total_agua'=>0,'total_energia'=>0];
$total_agua    = (float)$tot['total_agua'];
$total_energia = (float)$tot['total_energia'];

// ======= Serie diaria para Chart =======
$sqlSerie = "
SELECT DATE(c.fecha_registro) AS dia,
  COALESCE(SUM(CASE WHEN UPPER(c.tipo)='AGUA'    THEN c.cantidad ELSE 0 END),0)    AS agua,
  COALESCE(SUM(CASE WHEN UPPER(c.tipo)='ENERGIA' THEN c.cantidad ELSE 0 END),0)    AS energia
FROM consumo c
JOIN unidad   u ON u.id_unidad  = c.id_unidad
JOIN bloque   b ON b.id_bloque  = u.id_bloque
JOIN edificio e ON e.id_edificio= b.id_edificio
$WHERE
GROUP BY DATE(c.fecha_registro)
ORDER BY dia
";
$stS = $conexion->prepare($sqlSerie);
$stS->execute($params);
$rowsSerie = $stS->fetchAll(PDO::FETCH_ASSOC);

// Preparamos arrays para ChartJS
$labels = [];
$dataAgua = [];
$dataEner = [];
foreach ($rowsSerie as $r) {
  $labels[]  = $r['dia'];
  $dataAgua[] = (float)$r['agua'];
  $dataEner[] = (float)$r['energia'];
}

// ======= Tabla (detalle) =======
// Vista por Unidad (default) o por Usuario (priorizando PROPIETARIO)
if ($view === 'usuario') {
  $sqlTabla = "
  SELECT DISTINCT ON (c.id_consumo)
    c.id_consumo, c.tipo, c.cantidad, c.unidad, c.fecha_registro,
    u.nro_unidad, b.nombre AS bloque, e.nombre AS edificio,
    us.iduser,
    (us.nombre || ' ' || us.apellido) AS usuario,
    COALESCE(ru.tipo_residencia,'') AS tipo_residencia
  FROM consumo c
  JOIN unidad   u ON u.id_unidad  = c.id_unidad
  JOIN bloque   b ON b.id_bloque  = u.id_bloque
  JOIN edificio e ON e.id_edificio= b.id_edificio
  LEFT JOIN residente_unidad ru ON ru.id_unidad = u.id_unidad
  LEFT JOIN usuario us ON us.iduser = ru.id_usuario
  $WHERE
  ORDER BY c.id_consumo,
           CASE WHEN ru.tipo_residencia='PROPIETARIO' THEN 0 ELSE 1 END,
           us.iduser
  LIMIT 500
  ";
} else {
  $sqlTabla = "
  SELECT 
    c.id_consumo, c.tipo, c.cantidad, c.unidad, c.fecha_registro,
    u.nro_unidad, b.nombre AS bloque, e.nombre AS edificio
  FROM consumo c
  JOIN unidad   u ON u.id_unidad  = c.id_unidad
  JOIN bloque   b ON b.id_bloque  = u.id_bloque
  JOIN edificio e ON e.id_edificio= b.id_edificio
  $WHERE
  ORDER BY c.fecha_registro DESC
  LIMIT 500
  ";
}
$stT = $conexion->prepare($sqlTabla);
$stT->execute($params);
$rowsTabla = $stT->fetchAll(PDO::FETCH_ASSOC);

// ======= Top Usuarios (cuando view=usuario) =======
$topUsers = [];
if ($view === 'usuario') {
  $sqlTop = "
  SELECT
    us.iduser,
    (us.nombre || ' ' || us.apellido) AS usuario,
    SUM(CASE WHEN UPPER(c.tipo)='AGUA'    THEN c.cantidad ELSE 0 END) AS agua,
    SUM(CASE WHEN UPPER(c.tipo)='ENERGIA' THEN c.cantidad ELSE 0 END) AS energia
  FROM consumo c
  JOIN unidad   u ON u.id_unidad  = c.id_unidad
  JOIN bloque   b ON b.id_bloque  = u.id_bloque
  JOIN edificio e ON e.id_edificio= b.id_edificio
  LEFT JOIN residente_unidad ru ON ru.id_unidad = u.id_unidad
  LEFT JOIN usuario us ON us.iduser = ru.id_usuario
  $WHERE
  GROUP BY us.iduser, usuario
  ORDER BY (COALESCE(SUM(CASE WHEN UPPER(c.tipo)='AGUA'    THEN c.cantidad ELSE 0 END),0)
          + COALESCE(SUM(CASE WHEN UPPER(c.tipo)='ENERGIA' THEN c.cantidad ELSE 0 END),0)) DESC NULLS LAST
  LIMIT 10
  ";
  $stTop = $conexion->prepare($sqlTop);
  $stTop->execute($params);
  $topUsers = $stTop->fetchAll(PDO::FETCH_ASSOC);
}

// --------- Salida HTML ---------
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iDomus · Consumos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --accent:#1BAAA6; --dark:#0F3557; }
    body { background:#f7fafd; font-family:'Segoe UI', Arial, sans-serif; }
    .navbar { background:#023047; }
    .brand-badge{ color:#fff; font-weight:700; }
    .container-xxl { max-width: 1200px; }

    .card-kpi{ border:none; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    .kpi-value{ font-size:1.6rem; font-weight:800; color:#0F3557; }
    .kpi-sub{ color:#6b7a8a; font-size:.95rem; }
    .btn-domus { background: var(--dark); color:#fff; border:none; border-radius:10px; padding:.55rem .9rem; font-weight:600; }
    .btn-domus:hover{ background: var(--accent); }
    .card-chart{ border:none; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    .table thead th { background: var(--accent); color:#fff; border:none; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark sticky-top shadow-sm">
  <div class="container-fluid">
    <a class="btn btn-outline-light me-2" href="dashboard.php">
      <i class="bi bi-arrow-left"></i>
    </a>
    <span class="brand-badge">iDomus · Consumos</span>
    <div class="d-flex align-items-center gap-2">
      <a href="../home/home.php" class="btn btn-sm btn-outline-light"><i class="bi bi-house-door"></i> Home</a>
      <a href="../login/login.php?logout=1" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right"></i> Salir</a>
    </div>
  </div>
</nav>

<div class="container-xxl my-4">

  <!-- Filtros -->
  <div class="card p-3 mb-3">
    <form class="row g-2" method="get">
      <div class="col-6 col-md-2">
        <label class="form-label">Agrupar por</label>
        <select class="form-select" name="view">
          <option value="unidad"  <?= $view==='unidad'?'selected':'' ?>>Unidad</option>
          <option value="usuario" <?= $view==='usuario'?'selected':'' ?>>Usuario</option>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Edificio</label>
        <select class="form-select" name="edificio" onchange="this.form.submit()">
          <option value="0">Todos</option>
          <?php foreach($edificios as $e): ?>
            <option value="<?= (int)$e['id_edificio'] ?>" <?= $id_edificio==(int)$e['id_edificio']?'selected':'' ?>>
              <?= htmlspecialchars($e['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Bloque</label>
        <select class="form-select" name="bloque" onchange="this.form.submit()">
          <option value="0">Todos</option>
          <?php foreach($bloques as $b): ?>
            <option value="<?= (int)$b['id_bloque'] ?>" <?= $id_bloque==(int)$b['id_bloque']?'selected':'' ?>>
              <?= htmlspecialchars($b['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Unidad</label>
        <select class="form-select" name="unidad">
          <option value="0">Todas</option>
          <?php foreach($unidades as $u): ?>
            <option value="<?= (int)$u['id_unidad'] ?>" <?= $id_unidad==(int)$u['id_unidad']?'selected':'' ?>>
              <?= htmlspecialchars($u['nro_unidad']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Tipo</label>
        <select class="form-select" name="tipo">
          <option value="ALL"      <?= $tipo==='ALL'?'selected':''      ?>>Todos</option>
          <option value="AGUA"     <?= $tipo==='AGUA'?'selected':''     ?>>Agua</option>
          <option value="ENERGIA"  <?= $tipo==='ENERGIA'?'selected':''  ?>>Energía</option>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Desde</label>
        <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Hasta</label>
        <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="form-control">
      </div>

      <div class="col-12 col-md-3 align-self-end">
        <button class="btn btn-domus w-100" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
      </div>
    </form>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi p-3">
        <div class="kpi-sub">Total Agua (rango)</div>
        <div class="kpi-value"><?= number_format($total_agua, 1) ?> m³</div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi p-3">
        <div class="kpi-sub">Total Energía (rango)</div>
        <div class="kpi-value"><?= number_format($total_energia, 0) ?> kWh</div>
      </div>
    </div>
  </div>

  <!-- Gráfica -->
  <div class="card card-chart p-3 mb-3">
    <div class="d-flex justify-content-between">
      <h6 class="mb-2">Consumo diario (rango seleccionado)</h6>
    </div>
    <canvas id="chartConsumos" height="140"></canvas>
  </div>

  <!-- Tabla detalle -->
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="mb-0">Detalle de consumos (<?= $view==='usuario'?'por Usuario':'por Unidad' ?>)</h6>
      <div class="d-flex gap-2">
        <!-- Placeholders export -->
        <button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-file-earmark-spreadsheet"></i> Excel</button>
        <button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-file-earmark-pdf"></i> PDF</button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead>
          <tr>
            <th>Fecha</th>
            <?php if ($view==='usuario'): ?>
              <th>Usuario</th>
              <th>Tipo residencia</th>
            <?php endif; ?>
            <th>Tipo</th>
            <th class="text-end">Cantidad</th>
            <th>Unidad</th>
            <th>Bloque</th>
            <th>Edificio</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rowsTabla): ?>
            <tr><td colspan="<?= $view==='usuario'?8:6 ?>" class="text-center text-muted">Sin resultados</td></tr>
          <?php else: foreach ($rowsTabla as $r): ?>
            <tr>
              <td><?= htmlspecialchars(substr((string)$r['fecha_registro'],0,16)) ?></td>
              <?php if ($view==='usuario'): ?>
                <td><?= htmlspecialchars($r['usuario'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['tipo_residencia'] ?? '') ?></td>
              <?php endif; ?>
              <td><?= htmlspecialchars($r['tipo']) ?></td>
              <td class="text-end">
                <?= number_format((float)$r['cantidad'], 2) ?> <?= htmlspecialchars($r['unidad'] ?? '') ?>
              </td>
              <td><?= htmlspecialchars($r['nro_unidad']) ?></td>
              <td><?= htmlspecialchars($r['bloque']) ?></td>
              <td><?= htmlspecialchars($r['edificio']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Top usuarios -->
  <?php if ($view==='usuario'): ?>
    <div class="card p-3 mb-3">
      <h6 class="mb-2">Top usuarios (rango seleccionado)</h6>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Usuario</th>
              <th class="text-end">Agua (m³)</th>
              <th class="text-end">Energía (kWh)</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$topUsers): ?>
            <tr><td colspan="4" class="text-center text-muted">Sin datos</td></tr>
          <?php else: $i=1; foreach($topUsers as $tu): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= htmlspecialchars($tu['usuario'] ?? '—') ?></td>
              <td class="text-end"><?= number_format((float)$tu['agua'],1) ?></td>
              <td class="text-end"><?= number_format((float)$tu['energia'],0) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  // Datos para ChartJS
  const labels   = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  const dataAgua = <?= json_encode($dataAgua) ?>;
  const dataEner = <?= json_encode($dataEner) ?>;

  const ctx = document.getElementById('chartConsumos').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Agua (m³)',
          data: dataAgua,
          borderColor: '#1BAAA6',
          backgroundColor: 'rgba(27,170,166,.18)',
          borderWidth: 2,
          tension: .3
        },
        {
          label: 'Energía (kWh)',
          data: dataEner,
          borderColor: '#0F3557',
          backgroundColor: 'rgba(15,53,87,.18)',
          borderWidth: 2,
          tension: .3
        }
      ]
    },
    options: {
      responsive: true,
      plugins:{ legend:{ display:true, position:'bottom' } },
      scales:{
        y:{ beginAtZero:true }
      }
    }
  });
</script>
</body>
</html>