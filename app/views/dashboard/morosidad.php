<?php
// app/views/dashboard/morosidad.php
session_start();
require_once '../../models/conexion.php';

if (empty($_SESSION['iduser']) || ($_SESSION['rol'] ?? '') !== 'admin') {
  header('Location: ../login/login.php');
  exit;
}

// Marcar como pagada (acción rápida)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_pagado'], $_POST['id_cuota'])) {
  $id = (int)$_POST['id_cuota'];
  $st = $conexion->prepare("UPDATE cuota_mantenimiento SET estado='PAGADO' WHERE id_cuota=:id");
  $st->execute([':id'=>$id]);
  header('Location: morosidad.php?ok=1');
  exit;
}

/** Listado de morosidad con NOMBRE del residente principal */
$sql = "
SELECT 
  cm.id_cuota,
  cm.concepto,
  cm.monto,
  cm.fecha_generacion,
  cm.fecha_vencimiento,
  UPPER(cm.estado) AS estado,
  u.nro_unidad,
  b.nombre  AS bloque,
  e.nombre  AS edificio,
  COALESCE(res.nombre || ' ' || res.apellido, '(Sin residente)') AS residente,
  GREATEST(0, (CURRENT_DATE - cm.fecha_vencimiento))::int AS dias_atraso
FROM cuota_mantenimiento cm
JOIN unidad   u ON u.id_unidad = cm.id_unidad
JOIN bloque   b ON b.id_bloque = u.id_bloque
JOIN edificio e ON e.id_edificio = b.id_edificio
LEFT JOIN LATERAL (
  SELECT uu.*
  FROM residente_unidad ru 
  JOIN usuario uu ON uu.iduser = ru.id_usuario
  WHERE ru.id_unidad = u.id_unidad
  ORDER BY CASE WHEN ru.tipo_residencia='PROPIETARIO' THEN 0 ELSE 1 END, uu.iduser
  LIMIT 1
) AS res ON true
WHERE UPPER(cm.estado) = 'PENDIENTE'
ORDER BY cm.fecha_vencimiento ASC, e.nombre, b.nombre, u.nro_unidad
";
$rows = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>iDomus · Morosidad</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root { --accent:#1BAAA6; --dark:#0F3557; }
    body { background:#f7fafd; font-family: 'Segoe UI', Arial, sans-serif; }
    .navbar { background:#023047; }
    .container-xxl { max-width: 1100px; }
    .card { border:none; border-radius:16px; box-shadow:0 6px 24px rgba(0,0,0,.08); }
    .table thead th { background:var(--accent); color:#fff; border:none; }
    .btn-domus { background:var(--dark); color:#fff; border:none; border-radius:10px; font-weight:600; }
    .btn-domus:hover { background:var(--accent); }
  </style>
</head>
<body>

<nav class="navbar navbar-dark sticky-top shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
    <span class="navbar-text text-white">Morosidad</span>
    <a class="btn btn-sm btn-outline-light" href="../login/login.php?logout=1"><i class="bi bi-box-arrow-right"></i> Salir</a>
  </div>
</nav>

<div class="container-xxl my-4">
  <div class="card p-3">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Cuotas pendientes</h5>
      <div class="d-flex gap-2">
        <a href="export_morosidad.php?fmt=excel" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="export_morosidad.php?fmt=pdf"   class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
      </div>
    </div>
    <div class="table-responsive mt-3">
      <table class="table table-bordered align-middle">
        <thead>
          <tr>
            <th>Residente</th>
            <th>Unidad</th>
            <th>Bloque</th>
            <th>Edificio</th>
            <th>Concepto</th>
            <th>Monto (Bs)</th>
            <th>Generada</th>
            <th>Vence</th>
            <th>Atraso (días)</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="10" class="text-center text-muted">Sin cuotas pendientes</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['residente']) ?></td>
              <td><?= htmlspecialchars($r['nro_unidad']) ?></td>
              <td><?= htmlspecialchars($r['bloque']) ?></td>
              <td><?= htmlspecialchars($r['edificio']) ?></td>
              <td><?= htmlspecialchars($r['concepto'] ?: 'Cuota Mantenimiento') ?></td>
              <td class="text-end"><?= number_format((float)$r['monto'], 2) ?></td>
              <td><?= htmlspecialchars($r['fecha_generacion']) ?></td>
              <td><?= htmlspecialchars($r['fecha_vencimiento']) ?></td>
              <td class="text-center"><?= (int)$r['dias_atraso'] ?></td>
              <td>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="id_cuota" value="<?= (int)$r['id_cuota'] ?>">
                  <button name="marcar_pagado" class="btn btn-sm btn-success"
                          onclick="return confirm('¿Marcar esta cuota como PAGADA?')">
                    <i class="bi bi-check2-circle"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</body>
</html>