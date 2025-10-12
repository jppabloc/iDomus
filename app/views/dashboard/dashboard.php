<?php
// app/views/dashboard/dashboard.php
declare(strict_types=1);
session_start();
require_once '../../models/conexion.php';

// --------- Guardas y rol admin ---------
if (empty($_SESSION['iduser']) || ($_SESSION['rol'] ?? '') !== 'admin') {
  header('Location: ../login/login.php');
  exit;
}
// Datos de sesión para el chip de usuario en navbar
$nombre = $_SESSION['nombre'] ?? 'Administrador';
$rol    = $_SESSION['rol'] ?? 'admin';

// --------- Métricas rápidas ----------
/** Total usuarios, verificados, pendientes */
$tot_usuarios = $verificados = $pendientes = 0;
$stmt = $conexion->query("SELECT 
  COUNT(*) AS total,
  SUM(CASE WHEN COALESCE(verificado,false) = true THEN 1 ELSE 0 END) AS verif
FROM usuario");
$u = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'verif'=>0];
$tot_usuarios = (int)$u['total'];
$verificados  = (int)$u['verif'];
$pendientes   = max(0, $tot_usuarios - $verificados);

/** Pagos del mes actual */
$stmt = $conexion->query("
  SELECT COALESCE(SUM(monto),0) AS total_mes
  FROM pago
  WHERE DATE_TRUNC('month', fecha_pago) = DATE_TRUNC('month', CURRENT_DATE)
");
$pagos_mes = (float)($stmt->fetchColumn() ?: 0);

/** Morosidad (cuotas pendientes) */
$stmt = $conexion->query("
  SELECT COUNT(*) AS cnt, COALESCE(SUM(monto),0) AS total
  FROM cuota_mantenimiento
  WHERE UPPER(estado) = 'PENDIENTE'
");
$m = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'total'=>0];
$cuotas_pend = (int)$m['cnt'];
$monto_pend  = (float)$m['total'];

/** Consumo del mes (AGUA + ENERGIA) */
$stmt = $conexion->query("
  SELECT 
    SUM(CASE WHEN UPPER(tipo)='AGUA' THEN cantidad ELSE 0 END) AS agua,
    SUM(CASE WHEN UPPER(tipo)='ENERGIA' THEN cantidad ELSE 0 END) AS energia
  FROM consumo
  WHERE DATE_TRUNC('month', fecha_registro) = DATE_TRUNC('month', CURRENT_DATE)
");
$c = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['agua'=>0,'energia'=>0];
$cons_agua   = (float)$c['agua'];
$cons_energia= (float)$c['energia'];

/** Serie pagos últimos 6 meses */
$stmt = $conexion->query("
  SELECT TO_CHAR(DATE_TRUNC('month', fecha_pago), 'YYYY-MM') AS ym, 
         SUM(monto) AS total
  FROM pago
  WHERE fecha_pago >= (CURRENT_DATE - INTERVAL '5 months')
  GROUP BY ym
  ORDER BY ym
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$labelsPagos = [];
$dataPagos   = [];
// Construir eje meses completos (para que no falten huecos)
$period = new DatePeriod(
  (new DateTime('first day of -5 month'))->setTime(0,0),
  new DateInterval('P1M'),
  (new DateTime('first day of next month'))->setTime(0,0)
);
$map = [];
foreach ($rows as $r) { $map[$r['ym']] = (float)$r['total']; }
foreach ($period as $dt) {
  $k = $dt->format('Y-m');
  $labelsPagos[] = $k;
  $dataPagos[]   = $map[$k] ?? 0.0;
}

/** Serie morosidad últimos 6 meses (cuotas generadas pendientes por mes) */
$stmt = $conexion->query("
  SELECT TO_CHAR(DATE_TRUNC('month', fecha_generacion), 'YYYY-MM') AS ym,
         COUNT(*) AS cnt
  FROM cuota_mantenimiento
  WHERE fecha_generacion >= (CURRENT_DATE - INTERVAL '5 months')
    AND UPPER(estado)='PENDIENTE'
  GROUP BY ym
  ORDER BY ym
");
$rowsM = $stmt->fetchAll(PDO::FETCH_ASSOC);
$labelsMor  = [];
$dataMorCnt = [];
$mapM = [];
foreach ($rowsM as $r) { $mapM[$r['ym']] = (int)$r['cnt']; }
foreach ($period as $dt) {
  $k = $dt->format('Y-m');
  $labelsMor[]  = $k;
  $dataMorCnt[] = $mapM[$k] ?? 0;
}
/** Reservas: activas ahora (APROBADAS y en curso) */
$stmt = $conexion->query("
  SELECT COUNT(*) 
  FROM reserva 
  WHERE estado = 'APROBADA'
    AND NOW() BETWEEN fecha_inicio AND fecha_fin
");
$reservas_activas = (int)$stmt->fetchColumn();

/** Reservas: aprobadas este mes (por fecha_inicio) */
$stmt = $conexion->query("
  SELECT COUNT(*) 
  FROM reserva 
  WHERE estado = 'APROBADA'
    AND DATE_TRUNC('month', fecha_inicio) = DATE_TRUNC('month', CURRENT_DATE)
");
$reservas_mes = (int)$stmt->fetchColumn();

/** Reservas: próximas 7 días (opcional, útil para planificar) */
$stmt = $conexion->query("
  SELECT COUNT(*) 
  FROM reserva 
  WHERE estado = 'APROBADA'
    AND fecha_inicio >= NOW()
    AND fecha_inicio < NOW() + INTERVAL '7 days'
");
$reservas_proximas = (int)$stmt->fetchColumn();

// --------- Salida HTML ---------
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iDomus · Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --accent:#1BAAA6; --dark:#0F3557; }
    body { background:#f7fafd; font-family:'Segoe UI', Arial, sans-serif; }
    .navbar { background:#023047; }
    .brand-badge{ color:#fff; font-weight:700; }
    .container-xxl { max-width: 1200px; }
    .card-kpi{
      border:none; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,.08);
    }
    .card-kpi .icon{
      width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center;
      background:#e9f7f6; color:var(--accent); font-size:1.25rem;
    }
    .kpi-value{ font-size:1.6rem; font-weight:800; color:#0F3557; }
    .kpi-sub{ color:#6b7a8a; font-size:.95rem; }
    .btn-domus {
      background: var(--dark); color:#fff; border:none; border-radius:10px; padding:.55rem .9rem; font-weight:600;
    }
    .btn-domus:hover{ background: var(--accent); }
    .card-chart{ border:none; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    .offcanvas-header{ border-bottom:1px solid #eee; }
    .list-menu .btn { text-align:left; }
    .table thead th { background: var(--accent); color:#fff; border:none; }
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
<nav class="navbar navbar-dark sticky-top shadow-sm">
  <div class="container-fluid">
    <button class="btn btn-outline-light me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral">
      <i class="bi bi-list"></i>
    </button>
    <span class="brand-badge">iDomus · Dashboard</span>
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

    <a href="../login/login.php?logout=1" class="btn btn-sm btn-outline-light">
    <i class="bi bi-box-arrow-right"></i> Salir
    </a>
  </div>
  

  </div>
</nav>

<!-- Menú lateral -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="menuLateral">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">Menú</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body">
    <div class="list-menu d-grid gap-2">
      <a href="dashboard.php" class="btn btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i> Panel</a>
      <a href="finanzas.php"  class="btn btn-outline-primary"><i class="bi bi-cash-coin me-1"></i> Finanzas</a>
      <a href="consumos.php"  class="btn btn-outline-primary"><i class="bi bi-lightning-charge me-1"></i> Consumos</a>
      <a href="morosidad.php" class="btn btn-outline-primary"><i class="bi bi-exclamation-octagon me-1"></i> Morosidad</a>
      <a href="usuarios.php" class="btn btn-outline-primary"><i class="bi bi-people me-1"></i> Usuarios</a>
      <a href="../operacion/actas.php" class="btn btn-outline-primary">
        <i class="bi bi-journal-check me-1"></i> Actas (entrega/devolución)
      </a>
    </div>
  </div>
</div>

<!-- Contenido -->
<div class="container-xxl my-4">

  <!-- KPIs -->
  <div class="row g-3">
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="kpi-sub">Usuarios</div>
            <div class="kpi-value"><?= number_format($tot_usuarios) ?></div>
            <div class="text-muted small">✔ <?= $verificados ?> · ⏳ <?= $pendientes ?></div>
          </div>
          <div class="icon"><i class="bi bi-people"></i></div>
        </div>
        <div class="mt-3">
          <a href="usuarios.php" class="btn btn-sm btn-domus">Gestionar</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="kpi-sub">Pagos (mes)</div>
            <div class="kpi-value">Bs <?= number_format($pagos_mes,2) ?></div>
            <div class="text-muted small">Actual</div>
          </div>
          <div class="icon"><i class="bi bi-cash-coin"></i></div>
        </div>
        <div class="mt-3">
          <a href="finanzas.php" class="btn btn-sm btn-domus">Ver finanzas</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="kpi-sub">Morosidad</div>
            <div class="kpi-value"><?= $cuotas_pend ?> pend.</div>
            <div class="text-muted small">Bs <?= number_format($monto_pend,2) ?></div>
          </div>
          <div class="icon"><i class="bi bi-exclamation-octagon"></i></div>
        </div>
        <div class="mt-3">
          <a href="morosidad.php" class="btn btn-sm btn-domus">Ver morosidad</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="kpi-sub">Consumos (mes)</div>
            <div class="kpi-value"><?= number_format($cons_agua,1) ?> m³</div>
            <div class="text-muted small"><?= number_format($cons_energia,0) ?> kWh</div>
          </div>
          <div class="icon"><i class="bi bi-lightning-charge"></i></div>
        </div>
        <div class="mt-3">
          <a href="consumos.php" class="btn btn-sm btn-domus">Ver consumos</a>
        </div>
      </div>
    </div>

    <!-- KPI: Reservas -->
<div class="col-12 col-md-6 col-lg-3">
  <div class="card card-kpi p-3">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <div class="kpi-sub">Reservas (ahora)</div>
        <div class="kpi-value"><?= number_format($reservas_activas) ?></div>
        <div class="text-muted small">
          Mes: <?= number_format($reservas_mes) ?> · Próx. 7 días: <?= number_format($reservas_proximas) ?>
        </div>
      </div>
      <div class="icon"><i class="bi bi-calendar-check"></i></div>
    </div>
    <div class="mt-3">
      <a href="reservas.php" class="btn btn-sm btn-domus">Ver reservas</a>
    </div>
  </div>
</div>

    <!-- Reservas activas -->
<div class="col-12 col-md-6 col-lg-3">
  <div class="card card-kpi p-3">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <div class="kpi-sub">Reservas activas</div>
        <?php
          $stmt = $conexion->query("
            SELECT COUNT(*) FROM reserva WHERE estado IN ('ACTIVA', 'CONFIRMADA')
          ");
          $reservas_activas = (int)$stmt->fetchColumn();
        ?>
        <div class="kpi-value"><?= $reservas_activas ?></div>
        <div class="text-muted small">Salones / Áreas</div>
      </div>
      <div class="icon"><i class="bi bi-calendar-check"></i></div>
    </div>
    <div class="mt-3">
      <a href="reservas.php" class="btn btn-sm btn-domus">Ver reservas</a>
    </div>
  </div>
</div>
  </div>

  <!-- Gráficas -->
  <div class="row g-3 mt-1">
    <div class="col-12 col-lg-6">
      <div class="card card-chart p-3">
        <div class="d-flex justify-content-between">
          <h6 class="mb-3">Pagos últimos 6 meses</h6>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="finanzas.php"><i class="bi bi-arrow-right-circle"></i></a>
          </div>
        </div>
        <canvas id="chartPagos" height="140"></canvas>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="card card-chart p-3">
        <div class="d-flex justify-content-between">
          <h6 class="mb-3">Morosidad (cuotas pendientes) 6 meses</h6>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="morosidad.php"><i class="bi bi-arrow-right-circle"></i></a>
          </div>
        </div>
        <canvas id="chartMorosidad" height="140"></canvas>
      </div>
    </div>
  </div>

  <!-- Accesos rápidos -->
  <div class="row g-3 mt-1">
    <div class="col-12">
      <div class="card card-chart p-3">
        <div class="d-flex flex-wrap gap-2">
          <a href="finanzas.php"  class="btn btn-outline-primary"><i class="bi bi-cash-stack me-1"></i> Finanzas</a>
          <a href="consumos.php"  class="btn btn-outline-primary"><i class="bi bi-lightning me-1"></i> Consumos</a>
          <a href="morosidad.php" class="btn btn-outline-primary"><i class="bi bi-clipboard-x me-1"></i> Morosidad</a>
          <a href="usuarios.php" class="btn btn-outline-primary"><i class="bi bi-people me-1"></i> Usuarios</a>
          <a href="../operacion/actas.php" class="btn btn-outline-primary">
            <i class="bi bi-clipboard-check me-1"></i> Actas
          </a>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  // Datos desde PHP
  const labelsPagos = <?= json_encode($labelsPagos, JSON_UNESCAPED_UNICODE) ?>;
  const dataPagos   = <?= json_encode($dataPagos) ?>;

  const labelsMor   = <?= json_encode($labelsMor, JSON_UNESCAPED_UNICODE) ?>;
  const dataMor     = <?= json_encode($dataMorCnt) ?>;

  // Chart Pagos (barra)
  const ctxP = document.getElementById('chartPagos').getContext('2d');
  new Chart(ctxP, {
    type: 'bar',
    data: {
      labels: labelsPagos,
      datasets: [{
        label: 'Bs',
        data: dataPagos
      }]
    },
    options: {
      responsive: true,
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true } }
    }
  });

  // Chart Morosidad (línea)
  const ctxM = document.getElementById('chartMorosidad').getContext('2d');
  new Chart(ctxM, {
    type: 'line',
    data: {
      labels: labelsMor,
      datasets: [{
        label: 'Cuotas pendientes',
        data: dataMor,
        tension: .3
      }]
    },
    options: {
      responsive: true,
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, precision:0 } }
    }
  });
</script>
</body>
</html>