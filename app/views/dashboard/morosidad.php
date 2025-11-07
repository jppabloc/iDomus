<?php
// app/views/dashboard/morosidad.php
session_start();
require_once '../../models/conexion.php';

// tasa de mora
$tasa_anual  = 0.12;
$tasa_diaria = $tasa_anual / 365.0;

// ====== Guardas: solo ADMIN ======
if (empty($_SESSION['iduser']) || ($_SESSION['rol'] ?? '') !== 'admin') {
  header('Location: ../login/login.php');
  exit;
}

function json_out($ok, $msg = '', $extra = []) {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(array_merge(['success'=>$ok, 'message'=>$msg], $extra));
  exit;
}

function audit(PDO $db, int $iduser, string $accion, string $modulo = 'morosidad') {
  try {
    $db->prepare("INSERT INTO auditoria (id_usuario, accion, ip_origen, modulo) VALUES (:u,:a,:ip,:m)")
       ->execute([
         ':u'=>$iduser, ':a'=>$accion,
         ':ip'=>($_SERVER['REMOTE_ADDR'] ?? 'n/a'),
         ':m'=>$modulo
       ]);
  } catch (\Throwable $e) {}
}

function send_receipt_email(PDO $db, int $id_pago): void {
  $sql = "SELECT p.id_pago, p.monto, p.fecha_pago, p.concepto, p.estado,
                 u.iduser, u.nombre, u.apellido, u.correo
          FROM pago p
          JOIN usuario u ON u.iduser = p.id_usuario
          WHERE p.id_pago = :id LIMIT 1";
  $st = $db->prepare($sql);
  $st->execute([':id'=>$id_pago]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return;

  $nombre = htmlspecialchars($row['nombre'].' '.$row['apellido']);
  $monto  = number_format((float)$row['monto'],2);
  $fecha  = htmlspecialchars($row['fecha_pago']);
  $concepto = htmlspecialchars($row['concepto'] ?: 'Pago de cuota');
  $correo = $row['correo'];

  $asunto = "iDomus - Recibo de pago #".$row['id_pago'];
  $html = '
  <!doctype html><html><head><meta charset="utf-8"><style>
    body{font-family:Arial,sans-serif;background:#f4f6f8;padding:16px;}
    .card{max-width:560px;margin:auto;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.09);padding:18px;}
    .hdr{background:#023047;color:#fff;padding:14px;border-radius:10px;font-weight:700;}
    .row{margin:10px 0;}
    .foot{color:#6c7a89;font-size:12px;margin-top:12px;text-align:center;}
  </style></head>
  <body>
    <div class="card">
      <div class="hdr">Recibo de pago iDomus</div>
      <p>Hola <b>'.$nombre.'</b>, te confirmamos el registro de tu pago.</p>
      <div class="row"><b>Concepto:</b> '.$concepto.'</div>
      <div class="row"><b>Monto:</b> Bs '.$monto.'</div>
      <div class="row"><b>Fecha:</b> '.$fecha.'</div>
      <div class="foot">&copy; '.date('Y')." iDomus".'</div>
    </div>
  </body></html>';

  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type: text/html; charset=UTF-8\r\n";
  $headers .= "From: iDomus <noreply@idomus.com>\r\n";

  @mail($correo, $asunto, $html, $headers);
}

/* ===================== FIX PHP < 8: polyfill ===================== */
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    if ($needle === '') return true;
    return substr($haystack, 0, strlen($needle)) === $needle;
  }
}

/* ====== AJAX: crear cuota / registrar pago / cálculos / recibo ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  $action  = $_POST['action'] ?? '';
  $adminId = (int)($_SESSION['iduser'] ?? 0);

  // ---- Crear cuota pendiente ----
  if ($action === 'add_quota') {
    $iduser           = (int)($_POST['iduser'] ?? 0);
    $id_unidad        = (int)($_POST['id_unidad'] ?? 0);
    $conceptoCat      = strtoupper(trim($_POST['concepto'] ?? 'OTROS')); // AGUA | ENERGIA | OTROS
    $conceptoDet      = trim($_POST['concepto_detalle'] ?? '');
    $monto            = (float)($_POST['monto']  ?? 0);
    $fecha_gen        = $_POST['fecha_generacion']   ?? date('Y-m-d');
    $fecha_venc       = $_POST['fecha_vencimiento']  ?? date('Y-m-d');

    if ($iduser <= 0 || $id_unidad <= 0 || $monto <= 0) {
      json_out(false, 'Datos inválidos (usuario, unidad y monto son obligatorios).');
    }
    if (!in_array($conceptoCat, ['AGUA','ENERGIA','OTROS'], true)) {
      $conceptoCat = 'OTROS';
    }

    $conceptoFinal = $conceptoCat.($conceptoDet!==''? ': '.$conceptoDet : '');

    try {
      $sql = "INSERT INTO cuota_mantenimiento (id_unidad, monto, fecha_generacion, fecha_vencimiento, estado, concepto)
              VALUES (:u, :m, :fg, :fv, 'PENDIENTE', :c)";
      $ok = $conexion->prepare($sql)->execute([
        ':u'=>$id_unidad, ':m'=>$monto, ':fg'=>$fecha_gen, ':fv'=>$fecha_venc, ':c'=>$conceptoFinal
      ]);
      if (!$ok) throw new Exception('No se pudo crear la cuota.');
      audit($conexion, $adminId, "Creó cuota PENDIENTE (concepto={$conceptoFinal}) para user #$iduser unidad #$id_unidad por Bs $monto");
      json_out(true, 'Cuota creada correctamente.');
    } catch (\Throwable $e) {
      json_out(false, 'Error: '.$e->getMessage());
    }
  }

  // ---- Registrar pago (recalcula mora y paga el TOTAL) ----
  if ($action === 'pay_quota') {
    $id_cuota = (int)($_POST['id_cuota'] ?? 0);
    if ($id_cuota <= 0) json_out(false, 'ID de cuota inválido.');

    $sql = "SELECT c.id_cuota, c.id_unidad, c.monto, c.fecha_vencimiento, c.estado, c.concepto,
                   ru.id_usuario
            FROM cuota_mantenimiento c
            JOIN residente_unidad ru ON ru.id_unidad = c.id_unidad
            WHERE c.id_cuota = :id
            LIMIT 1";
    $st = $conexion->prepare($sql);
    $st->execute([':id'=>$id_cuota]);
    $cuota = $st->fetch(PDO::FETCH_ASSOC);

    if (!$cuota) json_out(false, 'Cuota no encontrada.');
    if (strtoupper($cuota['estado']) !== 'PENDIENTE') json_out(false, 'La cuota ya no está pendiente.');

    // Recalcular mora al momento del pago
    $monto_base = (float)$cuota['monto'];
    $hoy        = new DateTimeImmutable('now');
    $vence      = new DateTimeImmutable($cuota['fecha_vencimiento']);
    $interes    = 0.0;
    if ($vence < $hoy) {
      $dias_mora = (int)$hoy->diff($vence)->days;
      $interes   = round($monto_base * ($tasa_diaria * $dias_mora), 2);
    }
    $monto_total = round($monto_base + $interes, 2);

    $id_usuario = (int)$cuota['id_usuario'];
    $concepto   = 'Pago de '.$cuota['concepto'].' (cuota #'.$id_cuota.')';

    try {
      $conexion->beginTransaction();

      // 1) Marcar cuota como PAGADO
      $conexion->prepare("UPDATE cuota_mantenimiento SET estado='PAGADO' WHERE id_cuota=:id")
               ->execute([':id'=>$id_cuota]);

      // 2) Crear registro en pago por el TOTAL (base + mora)
      //    (sin columna 'medio' porque no existe en tu tabla)
      $conexion->prepare("
        INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado, registrado_por)
        VALUES (:u, :m, CURRENT_DATE, :c, 'PAGADO', :adm)
      ")->execute([
        ':u'=>$id_usuario, ':m'=>$monto_total, ':c'=>$concepto, ':adm'=>$adminId
      ]);
      $id_pago = (int)$conexion->query("SELECT LASTVAL()")->fetchColumn();

      $conexion->commit();

      audit($conexion, $adminId, "Registró pago id_pago=$id_pago por cuota id_cuota=$id_cuota, Bs $monto_total");

      send_receipt_email($conexion, $id_pago);

      json_out(true, 'Pago registrado y recibo enviado.');
    } catch (\Throwable $e) {
      if ($conexion->inTransaction()) $conexion->rollBack();
      json_out(false, 'Error: '.$e->getMessage());
    }
  }

  // ---- Calcular mora (no guarda) ----
  if ($action === 'calc_mora') {
    $id_cuota = (int)($_POST['id_cuota'] ?? 0);
    if ($id_cuota <= 0) json_out(false, 'ID de cuota inválido.');
    $st = $conexion->prepare("SELECT id_cuota, monto, fecha_vencimiento, estado FROM cuota_mantenimiento WHERE id_cuota=:id LIMIT 1");
    $st->execute([':id'=>$id_cuota]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) json_out(false, 'Cuota no encontrada.');

    $hoy = new DateTimeImmutable('now');
    $vence = new DateTimeImmutable($c['fecha_vencimiento']);
    $dias_mora = 0;
    $monto_interes = 0.0;
    if (strtoupper($c['estado']) === 'PENDIENTE' && $vence < $hoy) {
      $dias_mora = (int)$hoy->diff($vence)->days;
      $monto_interes = round(((float)$c['monto']) * ($tasa_diaria * $dias_mora), 2);
    }
    $total = round((float)$c['monto'] + $monto_interes, 2);

    json_out(true, 'Cálculo listo.', [
      'id_cuota'=>$id_cuota,
      'monto'=>number_format((float)$c['monto'],2,'.',''),
      'dias_mora'=>$dias_mora,
      'interes'=>number_format($monto_interes,2,'.',''),
      'total'=>number_format($total,2,'.','')
    ]);
  }

  // ---- Recibo (para modal): datos de cuota + pago PAGADO ----
  if ($action === 'get_recibo') {
    $id_cuota = (int)($_POST['id_cuota'] ?? 0);
    if ($id_cuota <= 0) json_out(false, 'ID de cuota inválido.');

    // Datos de cuota y usuario
    $sql = "SELECT c.id_cuota, c.concepto, c.monto, c.fecha_vencimiento, c.estado,
                   un.nro_unidad, b.nombre AS bloque, e.nombre AS edificio,
                   ru.id_usuario, u.nombre, u.apellido, u.correo
              FROM cuota_mantenimiento c
              JOIN unidad un   ON un.id_unidad = c.id_unidad
              JOIN bloque b    ON b.id_bloque  = un.id_bloque
              JOIN edificio e  ON e.id_edificio = b.id_edificio
              JOIN residente_unidad ru ON ru.id_unidad = un.id_unidad
              JOIN usuario u   ON u.iduser = ru.id_usuario
             WHERE c.id_cuota = :id
             LIMIT 1";
    $st = $conexion->prepare($sql);
    $st->execute([':id'=>$id_cuota]);
    $cuota = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cuota) json_out(false, 'No se encontró la cuota.');

    // Pago asociado
    $conceptoPago = 'Pago de '.$cuota['concepto'].' (cuota #'.$id_cuota.')';
    $stp = $conexion->prepare("
      SELECT p.id_pago, p.monto, p.fecha_pago, p.estado
        FROM pago p
       WHERE p.id_usuario = :u
         AND p.concepto = :c
         AND UPPER(p.estado) = 'PAGADO'
       ORDER BY p.id_pago DESC
       LIMIT 1
    ");
    $stp->execute([':u'=>(int)$cuota['id_usuario'], ':c'=>$conceptoPago]);
    $pago = $stp->fetch(PDO::FETCH_ASSOC);
    if (!$pago) json_out(false, 'No se encontró un pago PAGADO para esta cuota.');

    $unidadLbl = trim(($cuota['edificio']??'').' · '.($cuota['bloque']??'').' · '.$cuota['nro_unidad']);
    $residente = trim(($cuota['nombre']??'').' '.($cuota['apellido']??''));

    json_out(true, 'Recibo listo.', [
      'id_pago'     => (int)$pago['id_pago'],
      'concepto'    => $cuota['concepto'],
      'monto'       => number_format((float)$pago['monto'],2,'.',''),
      'fecha_pago'  => (string)($pago['fecha_pago'] ?? date('Y-m-d')),
      'unidad'      => $unidadLbl,
      'residente'   => $residente,
      'correo'      => (string)($cuota['correo'] ?? ''),
    ]);
  }

  json_out(false, 'Acción inválida.');
}

/* ====== Filtros ====== */
$q        = trim($_GET['q'] ?? '');
$desde    = $_GET['desde'] ?? '';
$hasta    = $_GET['hasta'] ?? '';
$estado   = strtoupper(trim($_GET['estado'] ?? 'PENDIENTE')); // PENDIENTE|TODOS
$fconcept = strtoupper(trim($_GET['concepto'] ?? 'TODOS'));   // AGUA|ENERGIA|OTROS|TODOS

$where = [];
$params = [];

if ($estado !== 'TODOS') {
  $where[] = "UPPER(c.estado) = 'PENDIENTE'";
}
if ($fconcept !== 'TODOS') {
  $where[] = "UPPER(c.concepto) LIKE :cn";
  $params[':cn'] = $fconcept.'%';
}
if ($q !== '') {
  $where[] = "(LOWER(u.nombre) LIKE :q OR LOWER(u.apellido) LIKE :q OR LOWER(u.correo) LIKE :q OR LOWER(un.nro_unidad) LIKE :q OR LOWER(c.concepto) LIKE :q)";
  $params[':q'] = '%'.strtolower($q).'%';
}
if ($desde !== '') {
  $where[] = "c.fecha_vencimiento >= :desde";
  $params[':desde'] = $desde;
}
if ($hasta !== '') {
  $where[] = "c.fecha_vencimiento <= :hasta";
  $params[':hasta'] = $hasta;
}
$whereSQL = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// Consulta base (incluye “Registrado por” si ya fue pagado)
$sqlList = "
  SELECT 
    c.id_cuota, c.monto, c.fecha_generacion, c.fecha_vencimiento, c.estado, c.concepto,
    u.iduser, u.nombre, u.apellido, u.correo,
    un.id_unidad, un.nro_unidad, b.nombre AS bloque, e.nombre AS edificio,
    px.id_pago AS pago_id, px.registrado_por AS pago_admin_id, ua.nombre AS admin_nom, ua.apellido AS admin_ape
  FROM cuota_mantenimiento c
  JOIN unidad un    ON un.id_unidad = c.id_unidad
  JOIN bloque b     ON b.id_bloque  = un.id_bloque
  JOIN edificio e   ON e.id_edificio = b.id_edificio
  JOIN residente_unidad ru ON ru.id_unidad = un.id_unidad
  JOIN usuario u    ON u.iduser = ru.id_usuario
  LEFT JOIN LATERAL (
     SELECT p.id_pago, p.registrado_por
       FROM pago p
      WHERE p.id_usuario = ru.id_usuario
        AND p.concepto = ('Pago de ' || c.concepto || ' (cuota #' || c.id_cuota || ')')
        AND UPPER(p.estado)='PAGADO'
      ORDER BY p.id_pago DESC
      LIMIT 1
  ) px ON true
  LEFT JOIN usuario ua ON ua.iduser = px.registrado_por
  $whereSQL
  ORDER BY c.fecha_vencimiento ASC, c.id_cuota ASC
";
$st = $conexion->prepare($sqlList);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Morosidad</title>
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
    .btn-domus{ background:var(--dark); color:#fff; border:none; border-radius:10px; }
    .btn-domus:hover{ background:var(--accent); }
    .badge-chip{ background:#e9f7f6; color:#0F3557; border-radius:20px; padding:.25rem .6rem; font-weight:600; }
    .qr-box{width:220px;height:220px;border:2px dashed #1BAAA6;border-radius:12px;background:#e9f7f6;padding:8px;display:flex;align-items:center;justify-content:center;}
    .table-wrap{ overflow-x: visible; }
    .table th, .table td{ white-space: normal; word-break: break-word; }
    @media (max-width: 576px){
      .th-email,.td-email,
      .th-edificio,.td-edificio,
      .th-bloque,.td-bloque,
      .th-vence,.td-vence,
      .th-registrado,.td-registrado{
        display:none !important;
      }
      .table thead th, .table tbody td{ padding:.35rem .45rem; font-size:.82rem; }
      .btn{ padding:.25rem .5rem; font-size:.78rem; }
    }
    .base-amount { color: #0F3557; font-weight:600; display:block; }
    .mora-amount { color: #d9534f; font-weight:700; display:block; margin-top:4px; font-size:0.95rem; }
    .mora-small  { color:#8b0000; font-size:0.82rem; }
  </style>
</head>
<body>
<!-- Navbar -->
<nav class="navbar navbar-dark sticky-top shadow-sm mb-3">
  <div class="container-fluid">
    <a href="dashboard.php" class="btn btn-outline-light me-2"><i class="bi bi-arrow-left"></i> Volver</a>
    <span class="brand-badge">iDomus · Morosidad</span>
    <div class="d-flex align-items-center gap-2">
      <span class="badge-chip d-none d-sm-inline-flex align-items-center gap-1">
        <i class="bi bi-person-circle" style="color:#1BAAA6"></i>
        <?= htmlspecialchars($_SESSION['nombre'] ?? 'Admin') ?> · <?= htmlspecialchars(ucfirst($_SESSION['rol'] ?? 'admin')) ?>
      </span>
      <a href="../login/login.php?logout=1" class="btn btn-sm btn-outline-light">
        <i class="bi bi-box-arrow-right"></i> Salir
      </a>
    </div>
  </div>
</nav>

<div class="container-xxl">

  <!-- Filtros -->
  <div class="card card-box p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-12 col-md-3">
        <label class="form-label">Buscar (usuario/correo/unidad/concepto)</label>
        <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Ej. Juan, A-101, vidrio roto">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Desde (venc.)</label>
        <input type="date" name="desde" class="form-control" value="<?= htmlspecialchars($desde) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Hasta (venc.)</label>
        <input type="date" name="hasta" class="form-control" value="<?= htmlspecialchars($hasta) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Estado</label>
        <select name="estado" class="form-select">
          <option value="PENDIENTE" <?= $estado==='PENDIENTE'?'selected':'' ?>>Pendiente</option>
          <option value="TODOS"     <?= $estado==='TODOS'?'selected':'' ?>>Todos</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Concepto (agrupado)</label>
        <select name="concepto" class="form-select">
          <option value="TODOS"   <?= $fconcept==='TODOS'?'selected':'' ?>>Todos</option>
          <option value="AGUA"    <?= $fconcept==='AGUA'?'selected':'' ?>>Agua</option>
          <option value="ENERGIA" <?= $fconcept==='ENERGIA'?'selected':'' ?>>Energía</option>
          <option value="OTROS"   <?= $fconcept==='OTROS'?'selected':'' ?>>Otros / Libre</option>
        </select>
      </div>
      <div class="col-12 col-md-1">
        <button class="btn btn-domus w-100"><i class="bi bi-filter"></i></button>
      </div>
    </form>
  </div>

  <!-- Tabla -->
  <div class="card card-box p-3">
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Usuario</th>
            <th class="th-email">Correo</th>
            <th>Unidad</th>
            <th class="th-edificio">Edificio</th>
            <th class="th-bloque">Bloque</th>
            <th>Concepto</th>
            <th class="text-end">Monto (Bs)</th>
            <th class="th-vence">Vence</th>
            <th>Estado</th>
            <th class="th-registrado">Registrado por</th>
            <th style="width:220px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <?php
            $estadoCuota = strtoupper(trim($r['estado'] ?? ''));
            $esPendiente = ($estadoCuota === 'PENDIENTE');
          ?>
          <tr data-id="<?= (int)$r['id_cuota'] ?>">
            <td><?= (int)$r['id_cuota'] ?></td>
            <td><?= htmlspecialchars($r['nombre'].' '.$r['apellido']) ?></td>
            <td class="td-email"><?= htmlspecialchars($r['correo']) ?></td>
            <td><?= htmlspecialchars($r['nro_unidad']) ?></td>
            <td class="td-edificio"><?= htmlspecialchars($r['edificio']) ?></td>
            <td class="td-bloque"><?= htmlspecialchars($r['bloque']) ?></td>
            <td>
              <?php
                $tag = 'secondary';
                $uc = strtoupper($r['concepto']);
                if (str_starts_with($uc,'AGUA'))    $tag='info';
                if (str_starts_with($uc,'ENERGIA')) $tag='warning';
              ?>
              <span class="badge bg-<?= $tag ?>"><?= htmlspecialchars($r['concepto']) ?></span>
            </td>
            <td class="text-end">
              <?php
                $monto_base = (float)$r['monto'];
                $dias_mora = 0;
                $interes = 0.0;
                $total_con_mora = $monto_base;

                try {
                  $hoy = new DateTimeImmutable('today');
                  $vence = new DateTimeImmutable($r['fecha_vencimiento']);
                  if ($esPendiente && $vence < $hoy) {
                    $dias_mora = (int)$hoy->diff($vence)->days;
                    $interes = round($monto_base * ($tasa_diaria * $dias_mora), 2);
                    $total_con_mora = round($monto_base + $interes, 2);
                  }
                } catch (Exception $e) {
                  $dias_mora = 0; $interes = 0.0; $total_con_mora = $monto_base;
                }
              ?>
              <span class="base-amount">Bs <?= number_format($monto_base, 2, ',', '.') ?></span>
              <?php if ($dias_mora > 0): ?>
                <span class="mora-amount">Bs <?= number_format($total_con_mora, 2, ',', '.') ?></span>
                <small class="mora-small">(+Bs <?= number_format($interes, 2, ',', '.') ?> de mora · <?= $dias_mora ?> días)</small>
              <?php endif; ?>
            </td>

            <td class="td-vence"><?= htmlspecialchars($r['fecha_vencimiento']) ?></td>
            <td>
              <?php if ($esPendiente): ?>
                <span class="badge bg-warning text-dark">Pendiente</span>
              <?php else: ?>
                <span class="badge bg-success">Pagado</span>
              <?php endif; ?>
            </td>
            <td class="td-registrado">
              <?php
                $adm = trim(($r['admin_nom']??'').' '.($r['admin_ape']??''));
                echo $adm ? htmlspecialchars($adm) : '—';
              ?>
            </td>
            <td>
              <?php if ($esPendiente): ?>
                <button class="btn btn-sm btn-outline-primary btn-pay"
                        data-id="<?= (int)$r['id_cuota'] ?>"
                        data-user="<?= htmlspecialchars($r['nombre'].' '.$r['apellido']) ?>"
                        data-unidad="<?= htmlspecialchars($r['nro_unidad']) ?>"
                        data-concepto="<?= htmlspecialchars($r['concepto']) ?>"
                        data-vence="<?= htmlspecialchars($r['fecha_vencimiento']) ?>"
                        data-monto="<?= number_format((float)$r['monto'],2,'.','') ?>"
                        data-bs-toggle="modal" data-bs-target="#modalPay">
                  <i class="bi bi-qr-code"></i> Pagar (QR)
                </button>
              <?php else: ?>
                <button class="btn btn-sm btn-outline-success btn-recibo"
                        data-id="<?= (int)$r['id_cuota'] ?>"
                        data-user="<?= htmlspecialchars($r['nombre'].' '.$r['apellido']) ?>"
                        data-unidad="<?= htmlspecialchars($r['nro_unidad']) ?>"
                        data-concepto="<?= htmlspecialchars($r['concepto']) ?>">
                  <i class="bi bi-check2-circle"></i> Ver recibo
                </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="12" class="text-center text-muted">No hay datos</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL: Recibo (simple) -->
<div class="modal fade" id="modalRecibo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#198754;color:#fff;">
        <h5 class="modal-title"><i class="bi bi-receipt-cutoff"></i> Pago registrado</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="recibo_alert" class="alert d-none"></div>
        <div class="mb-2"><b>Residente:</b> <span id="r_residente">—</span></div>
        <div class="mb-2"><b>Unidad:</b> <span id="r_unidad">—</span></div>
        <div class="mb-2"><b>Concepto:</b> <span id="r_concepto">—</span></div>
        <div class="mb-2"><b>Monto:</b> Bs <span id="r_monto">0.00</span></div>
        <div class="mb-2"><b>Fecha de pago:</b> <span id="r_fecha">—</span></div>
        <div class="mb-2"><b>ID de pago:</b> <span id="r_idpago">—</span></div>
        <div class="small text-muted">Este recibo es referencial (no es un comprobante bancario).</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-outline-primary" type="button" onclick="window.print()">
          <i class="bi bi-printer"></i> Imprimir
        </button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: Registrar pago con QR -->
<div class="modal fade" id="modalPay" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="formPay">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-qr-code"></i> Registrar pago (QR)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="alertPay" class="alert d-none"></div>
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="action" value="pay_quota">
        <input type="hidden" name="id_cuota" id="pay_id_cuota" value="0">

        <div class="mb-2">
          <label class="form-label">Usuario</label>
          <input type="text" class="form-control" id="pay_user" disabled>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Unidad</label>
            <input type="text" class="form-control" id="pay_unidad" disabled>
          </div>
          <div class="col-6">
            <label class="form-label">Vence</label>
            <input type="text" class="form-control" id="pay_vence" disabled>
          </div>
        </div>

        <div class="mb-2 mt-2">
          <label class="form-label">Concepto</label>
          <input type="text" class="form-control" id="pay_concepto" disabled>
        </div>

        <div class="mb-2">
          <label class="form-label">Monto a pagar (Bs)</label>
          <input type="text" class="form-control" id="pay_monto" disabled>
        </div>

        <!-- Desglose de mora en el modal de pago -->
        <div id="pay_mora_box" class="small text-muted" style="display:none;">
          <div><b>Mora:</b> Bs <span id="pay_mora_interes">0.00</span> (<span id="pay_mora_dias">0</span> días)</div>
          <div><b>Total (base + mora):</b> Bs <span id="pay_total">0.00</span></div>
        </div>

        <div class="my-3 d-flex justify-content-center">
          <div class="qr-box">
            <img id="pay_qr" src="" alt="QR" style="width:100%;height:100%;object-fit:contain;">
          </div>
        </div>
        <div class="small text-muted text-center">Escanee el QR con el celular o confirme con “Pagar ahora”.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-domus" type="submit"><i class="bi bi-cash-coin"></i> Pagar ahora</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ====== Modal: pagar (rellena datos + CALC MORA + QR TOTAL) ======
document.querySelectorAll('.btn-pay').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const id       = btn.getAttribute('data-id')||'0';
    const user     = btn.getAttribute('data-user')||'—';
    const unidad   = btn.getAttribute('data-unidad')||'—';
    const vence    = btn.getAttribute('data-vence')||'—';
    const montoStr = btn.getAttribute('data-monto')||'0.00';
    const concepto = btn.getAttribute('data-concepto')||'—';
    const montoBase = Number(montoStr || 0);

    // Set campos fijos
    document.getElementById('pay_id_cuota').value = id;
    document.getElementById('pay_user').value     = user;
    document.getElementById('pay_unidad').value   = unidad;
    document.getElementById('pay_vence').value    = vence;
    document.getElementById('pay_concepto').value = concepto;

    // Calcular mora por AJAX
    const fd = new FormData();
    fd.append('ajax','1'); fd.append('action','calc_mora'); fd.append('id_cuota', id);

    let total = montoBase, interes = 0, dias = 0;
    try{
      const res = await fetch('morosidad.php', { method:'POST', body: fd });
      const js  = await res.json();
      if(js?.success){
        total   = Number(js.total || montoBase);
        interes = Number(js.interes || 0);
        dias    = Number(js.dias_mora || 0);
      }
    }catch(e){}

    // Mostrar monto final y desglose
    const fmt = (v)=> {
      try { return Number(v||0).toLocaleString('es-BO',{minimumFractionDigits:2,maximumFractionDigits:2}); }
      catch { return Number(v||0).toFixed(2); }
    };
    document.getElementById('pay_monto').value       = fmt(total);
    document.getElementById('pay_mora_interes').textContent = fmt(interes);
    document.getElementById('pay_mora_dias').textContent    = String(dias);
    document.getElementById('pay_total').textContent        = fmt(total);
    document.getElementById('pay_mora_box').style.display   = interes>0 ? 'block' : 'none';

    // QR con TOTAL (base + mora)
    const texto = `iDomus|Cuota#${id}|Unidad:${unidad}|Concepto:${concepto}|MontoTotal:Bs ${total.toFixed(2)}|Base:Bs ${montoBase.toFixed(2)}|Mora:Bs ${interes.toFixed(2)}|Vence:${vence}`;
    const data  = encodeURIComponent(texto);
    const url   = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${data}`;
    document.getElementById('pay_qr').src = url;

    // Limpiar alertas
    const a = document.getElementById('alertPay');
    a.classList.add('d-none'); a.textContent='';
  });
});

// ====== Submit: pagar (AJAX) ======
document.getElementById('formPay').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const a = document.getElementById('alertPay');
  const data = new FormData(e.target);
  const res  = await fetch('morosidad.php', { method:'POST', body:data });
  let js = {};
  try{ js = await res.json(); }catch{ js = {success:false, message:'Respuesta inválida'} }
  if (js.success) {
    a.className = 'alert alert-success'; a.textContent = js.message || 'Pago registrado.';
    a.classList.remove('d-none');
    setTimeout(()=> location.reload(), 900);
  } else {
    a.className = 'alert alert-danger'; a.textContent = js.message || 'Error.';
    a.classList.remove('d-none');
  }
});

// ====== Modal: recibo (para cuotas ya pagadas) ======
const modalRecibo = new bootstrap.Modal(document.getElementById('modalRecibo'));
document.querySelectorAll('.btn-recibo').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const id = btn.getAttribute('data-id')||'0';
    const fd = new FormData();
    fd.append('ajax','1');
    fd.append('action','get_recibo');
    fd.append('id_cuota', id);

    const alertBox = document.getElementById('recibo_alert');
    alertBox.classList.add('d-none'); alertBox.textContent='';

    try{
      const res = await fetch('morosidad.php', { method:'POST', body: fd });
      const js  = await res.json();
      if(!js?.success){
        alertBox.className='alert alert-danger'; alertBox.textContent = js?.message || 'No se pudo cargar el recibo.'; alertBox.classList.remove('d-none');
      }else{
        document.getElementById('r_residente').textContent = js.residente || '—';
        document.getElementById('r_unidad').textContent    = js.unidad || '—';
        document.getElementById('r_concepto').textContent  = js.concepto || '—';
        document.getElementById('r_monto').textContent     = (Number(js.monto||0)).toLocaleString('es-BO',{minimumFractionDigits:2,maximumFractionDigits:2});
        document.getElementById('r_fecha').textContent     = js.fecha_pago || '—';
        document.getElementById('r_idpago').textContent    = js.id_pago || '—';
      }
    }catch(e){
      alertBox.className='alert alert-danger'; alertBox.textContent = 'Error de conexión.'; alertBox.classList.remove('d-none');
    }
    modalRecibo.show();
  });
});
</script>
</body>
</html>