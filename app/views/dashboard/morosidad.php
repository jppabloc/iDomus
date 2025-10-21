<?php
// app/views/dashboard/morosidad.php
session_start();
require_once '../../models/conexion.php';

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

function get_user_email(PDO $db, int $iduser): ?string {
  $st = $db->prepare("SELECT correo FROM usuario WHERE iduser=:id LIMIT 1");
  $st->execute([':id'=>$iduser]);
  return $st->fetchColumn() ?: null;
}

function get_user_name(PDO $db, int $iduser): string {
  $st = $db->prepare("SELECT CONCAT(nombre,' ',apellido) FROM usuario WHERE iduser=:id LIMIT 1");
  $st->execute([':id'=>$iduser]);
  return $st->fetchColumn() ?: 'Usuario';
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

// ====== AJAX: crear cuota / registrar pago ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  $action  = $_POST['action'] ?? '';
  $adminId = (int)($_SESSION['iduser'] ?? 0);

  // ---- Crear cuota pendiente (mantiene select + detalle opcional) ----
  if ($action === 'add_quota') {
    $iduser           = (int)($_POST['iduser'] ?? 0);
    $id_unidad        = (int)($_POST['id_unidad'] ?? 0);
    $conceptoCat      = strtoupper(trim($_POST['concepto'] ?? 'OTROS')); // AGUA | ENERGIA | OTROS
    $conceptoDet      = trim($_POST['concepto_detalle'] ?? '');          // opcional
    $monto            = (float)($_POST['monto']  ?? 0);
    $fecha_gen        = $_POST['fecha_generacion']   ?? date('Y-m-d');
    $fecha_venc       = $_POST['fecha_vencimiento']  ?? date('Y-m-d');

    if ($iduser <= 0 || $id_unidad <= 0 || $monto <= 0) {
      json_out(false, 'Datos inválidos (usuario, unidad y monto son obligatorios).');
    }
    if (!in_array($conceptoCat, ['AGUA','ENERGIA','OTROS'], true)) {
      $conceptoCat = 'OTROS';
    }

    // Construir concepto final (prefijo categoría + detalle opcional)
    $conceptoFinal = $conceptoCat;
    if ($conceptoDet !== '') {
      $conceptoFinal .= ': ' . $conceptoDet;
    }

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

  // ---- Registrar pago (marcar cuota como PAGADO y crear registro pago) ----
  if ($action === 'pay_quota') {
    $id_cuota = (int)($_POST['id_cuota'] ?? 0);
    if ($id_cuota <= 0) json_out(false, 'ID de cuota inválido.');

    // Cargar cuota + usuario (por unidad)
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

    $id_usuario = (int)$cuota['id_usuario'];
    $monto      = (float)$cuota['monto'];
    $concepto   = 'Pago de '.$cuota['concepto'].' (cuota #'.$id_cuota.')';

    try {
      $conexion->beginTransaction();

      // 1) Marcar cuota como PAGADO
      $conexion->prepare("UPDATE cuota_mantenimiento SET estado='PAGADO' WHERE id_cuota=:id")
               ->execute([':id'=>$id_cuota]);

      // 2) Crear registro en pago (con registrado_por = admin)
      $conexion->prepare("
        INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado, registrado_por)
        VALUES (:u, :m, CURRENT_DATE, :c, 'PAGADO', :adm)
      ")->execute([
        ':u'=>$id_usuario, ':m'=>$monto, ':c'=>$concepto, ':adm'=>$adminId
      ]);

      $id_pago = (int)$conexion->query("SELECT LASTVAL()")->fetchColumn();

      $conexion->commit();

      audit($conexion, $adminId, "Registró pago id_pago=$id_pago por cuota id_cuota=$id_cuota, Bs $monto");

      // 3) Enviar recibo (HTML) por correo
      send_receipt_email($conexion, $id_pago);

      json_out(true, 'Pago registrado y recibo enviado.');
    } catch (\Throwable $e) {
      if ($conexion->inTransaction()) $conexion->rollBack();
      json_out(false, 'Error: '.$e->getMessage());
    }
  }

  json_out(false, 'Acción inválida.');
}

// ====== Filtros ======
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
  // Soportar prefijo (AGUA, ENERGIA, OTROS: algo)
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

// ====== Export CSV / PDF ======
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="morosidad.csv"');
  // BOM UTF-8
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['CuotaID','Usuario','Correo','Unidad','Edificio','Bloque','Concepto','Monto','Vence','Estado','RegistradoPor'], ',');
  foreach ($rows as $r) {
    $adminLabel = (trim(($r['admin_nom']??'').($r['admin_ape']??''))!=='')
      ? trim(($r['admin_nom']??'').' '.($r['admin_ape']??''))
      : '';
    fputcsv($out, [
      $r['id_cuota'],
      $r['nombre'].' '.$r['apellido'],
      $r['correo'],
      $r['nro_unidad'],
      $r['edificio'],
      $r['bloque'],
      $r['concepto'],
      number_format((float)$r['monto'],2,'.',''),
      $r['fecha_vencimiento'],
      $r['estado'],
      $adminLabel
    ], ',');
  }
  fclose($out);
  exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
  ?>
  <!doctype html><html lang="es"><head>
    <meta charset="utf-8"><title>Morosidad · iDomus</title>
    <style>
      body{font-family:Arial, sans-serif; padding:16px;}
      h2{margin:0 0 10px;}
      table{width:100%; border-collapse:collapse;}
      th,td{border:1px solid #999; padding:6px; font-size:12px;}
      th{background:#eee;}
      .muted{color:#666; font-size:12px;}
    </style>
  </head><body>
    <h2>Reporte de Morosidad</h2>
    <div class="muted">Generado: <?= date('Y-m-d H:i') ?> · Filtros: 
      <?= $q ? "q='$q' " : '' ?>
      <?= $desde ? "desde=$desde " : '' ?>
      <?= $hasta ? "hasta=$hasta " : '' ?>
      estado=<?= $estado ?> · concepto=<?= $fconcept ?>
    </div>
    <br>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Usuario</th><th>Correo</th><th>Unidad</th><th>Edificio</th><th>Bloque</th>
          <th>Concepto</th><th>Monto (Bs)</th><th>Vence</th><th>Estado</th><th>Registrado por</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id_cuota'] ?></td>
          <td><?= htmlspecialchars($r['nombre'].' '.$r['apellido']) ?></td>
          <td><?= htmlspecialchars($r['correo']) ?></td>
          <td><?= htmlspecialchars($r['nro_unidad']) ?></td>
          <td><?= htmlspecialchars($r['edificio']) ?></td>
          <td><?= htmlspecialchars($r['bloque']) ?></td>
          <td><?= htmlspecialchars($r['concepto']) ?></td>
          <td style="text-align:right;"><?= number_format((float)$r['monto'],2) ?></td>
          <td><?= htmlspecialchars($r['fecha_vencimiento']) ?></td>
          <td><?= htmlspecialchars($r['estado']) ?></td>
          <td><?= htmlspecialchars(trim(($r['admin_nom']??'').' '.($r['admin_ape']??''))) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="11" style="text-align:center;color:#666;">Sin datos</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <script>window.print()</script>
  </body></html>
  <?php
  exit;
}

// ====== Datos para UI ======
$nombreAdmin = $_SESSION['nombre'] ?? 'Admin';
$rolAdmin    = $_SESSION['rol'] ?? 'admin';

// Lista de usuarios (para el modal)
$usuariosList = $conexion->query("
  SELECT iduser, CONCAT(nombre,' ',apellido) AS nom, correo
  FROM usuario
  ORDER BY nom ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Mapa de unidades por usuario (para poblar select dinámico)
$unitsByUser = [];
$qr = $conexion->query("
  SELECT ru.id_usuario, un.id_unidad, un.nro_unidad, b.nombre AS bloque, e.nombre AS edificio
  FROM residente_unidad ru
  JOIN unidad un ON un.id_unidad = ru.id_unidad
  JOIN bloque b ON b.id_bloque = un.id_bloque
  JOIN edificio e ON e.id_edificio = b.id_edificio
  ORDER BY ru.id_usuario, e.nombre, b.nombre, un.nro_unidad
");
while ($x = $qr->fetch(PDO::FETCH_ASSOC)) {
  $uid = (int)$x['id_usuario'];
  $unitsByUser[$uid][] = [
    'id_unidad'=>(int)$x['id_unidad'],
    'label'=>$x['edificio'].' · '.$x['bloque'].' · '.$x['nro_unidad']
  ];
}

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

    /* ==== Corrección de scroll horizontal y mobile ==== */
    .table-wrap{ overflow-x: visible; } /* reemplaza a .table-responsive */
    .table th, .table td{ white-space: normal; word-break: break-word; }

    @media (max-width: 576px){
      .th-email,.td-email,
      .th-edificio,.td-edificio,
      .th-bloque,.td-bloque,
      .th-vence,.td-vence,
      .th-registrado,.td-registrado{
        display:none !important;
      }
      .table thead th, .table tbody td{
        padding:.35rem .45rem;
        font-size:.82rem;
      }
      .btn{ padding:.25rem .5rem; font-size:.78rem; }
    }
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
        <?= htmlspecialchars($nombreAdmin) ?> · <?= htmlspecialchars(ucfirst($rolAdmin)) ?>
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

  <!-- Acciones -->
  <div class="card card-box p-3 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-domus" data-bs-toggle="modal" data-bs-target="#modalAddQuota">
        <i class="bi bi-plus-circle"></i> Agregar cuota por pagar
      </button>
      <a class="btn btn-outline-success ms-auto" href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>">
        <i class="bi bi-file-earmark-spreadsheet"></i> Excel (CSV)
      </a>
      <a class="btn btn-outline-danger" href="?<?= http_build_query(array_merge($_GET,['export'=>'pdf'])) ?>" target="_blank">
        <i class="bi bi-filetype-pdf"></i> PDF
      </a>
    </div>
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
            <th style="width:200px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
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
            <td class="text-end"><?= number_format((float)$r['monto'],2) ?></td>
            <td class="td-vence"><?= htmlspecialchars($r['fecha_vencimiento']) ?></td>
            <td>
              <?php if (strtoupper($r['estado'])==='PENDIENTE'): ?>
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
              <?php if (strtoupper($r['estado'])==='PENDIENTE'): ?>
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
                <span class="text-muted small">—</span>
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

<!-- MODAL: Agregar cuota (select + detalle opcional) -->
<div class="modal fade" id="modalAddQuota" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="formAddQuota">
      <div class="modal-header">
        <h5 class="modal-title">Nueva cuota por pagar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="alertAdd" class="alert d-none"></div>
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="action" value="add_quota">

        <div class="mb-2">
          <label class="form-label">Usuario</label>
          <select class="form-select" name="iduser" id="aq_user" required>
            <option value="">Selecciona...</option>
            <?php foreach($usuariosList as $u): ?>
              <option value="<?= (int)$u['iduser'] ?>">
                <?= htmlspecialchars($u['nom']) ?> (<?= htmlspecialchars($u['correo']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-2">
          <label class="form-label">Unidad</label>
          <select class="form-select" name="id_unidad" id="aq_unidad" required>
            <option value="">Selecciona un usuario...</option>
          </select>
          <div class="form-text">Un usuario puede tener varias unidades. Selecciona a cuál aplicar.</div>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Concepto (agrupado)</label>
            <select class="form-select" name="concepto" required>
              <option value="AGUA">Agua</option>
              <option value="ENERGIA">Energía</option>
              <option value="OTROS" selected>Otros</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Monto (Bs)</label>
            <input type="number" step="0.01" min="0.1" class="form-control" name="monto" required>
          </div>
        </div>

        <!-- Detalle/observación opcional que se concatena al concepto -->
        <div class="mt-2">
          <label class="form-label">Detalle / Observación (opcional)</label>
          <input type="text" class="form-control" name="concepto_detalle" placeholder="Ej. vidrio roto, control remoto perdido…">
          <div class="form-text">Si lo completas, se grabará como “AGUA: detalle”, “ENERGIA: detalle” o “OTROS: detalle”.</div>
        </div>

        <div class="row mt-2 g-2">
          <div class="col">
            <label class="form-label">Generación</label>
            <input type="date" class="form-control" name="fecha_generacion" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col">
            <label class="form-label">Vencimiento</label>
            <input type="date" class="form-control" name="fecha_vencimiento" value="<?= date('Y-m-d', strtotime('+10 days')) ?>">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-domus" type="submit">Crear</button>
      </div>
    </form>
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
          <label class="form-label">Monto (Bs)</label>
          <input type="text" class="form-control" id="pay_monto" disabled>
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
// ====== Mapa de unidades por usuario (precargado desde PHP) ======
const USER_UNITS = <?= json_encode($unitsByUser, JSON_UNESCAPED_UNICODE) ?>;

// Poblado dinámico de unidades al elegir usuario
const selUser  = document.getElementById('aq_user');
const selUnit  = document.getElementById('aq_unidad');
selUser?.addEventListener('change', ()=>{
  const uid = parseInt(selUser.value || '0', 10);
  selUnit.innerHTML = '';
  if (!uid || !USER_UNITS[uid] || USER_UNITS[uid].length === 0) {
    selUnit.innerHTML = '<option value="">Este usuario no tiene unidad asignada</option>';
    return;
  }
  selUnit.innerHTML = '<option value="">Selecciona...</option>';
  USER_UNITS[uid].forEach(u=>{
    const opt = document.createElement('option');
    opt.value = u.id_unidad;
    opt.textContent = u.label;
    selUnit.appendChild(opt);
  });
});

// ====== Modal: pagar (rellena datos + QR) ======
document.querySelectorAll('.btn-pay').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id       = btn.getAttribute('data-id')||'0';
    const user     = btn.getAttribute('data-user')||'—';
    const unidad   = btn.getAttribute('data-unidad')||'—';
    const vence    = btn.getAttribute('data-vence')||'—';
    const monto    = btn.getAttribute('data-monto')||'0.00';
    const concepto = btn.getAttribute('data-concepto')||'—';

    document.getElementById('pay_id_cuota').value = id;
    document.getElementById('pay_user').value     = user;
    document.getElementById('pay_unidad').value   = unidad;
    document.getElementById('pay_vence').value    = vence;
    document.getElementById('pay_concepto').value = concepto;
    document.getElementById('pay_monto').value    = parseFloat(monto).toFixed(2);

    // Generar QR (informativo)
    const data = encodeURIComponent(`iDomus|Cuota#${id}|Unidad:${unidad}|Concepto:${concepto}|Monto:Bs ${monto}|Vence:${vence}`);
    const url  = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${data}`;
    document.getElementById('pay_qr').src = url;

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

// ====== Submit: agregar cuota ======
document.getElementById('formAddQuota').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const a = document.getElementById('alertAdd');
  const data = new FormData(e.target);
  const res  = await fetch('morosidad.php', { method:'POST', body:data });
  let js = {};
  try{ js = await res.json(); }catch{ js = {success:false, message:'Respuesta inválida'} }
  if (js.success) {
    a.className = 'alert alert-success'; a.textContent = js.message || 'Cuota creada.';
    a.classList.remove('d-none');
    // Limpia y resetea para permitir nuevas cuotas seguidas
    e.target.reset();
    selUnit.innerHTML = '<option value="">Selecciona un usuario...</option>';
    setTimeout(()=> location.reload(), 900);
  } else {
    a.className = 'alert alert-danger'; a.textContent = js.message || 'Error.';
    a.classList.remove('d-none');
  }
});
</script>
</body>
</html>