<?php
// app/views/usuario/pago_demo.php
declare(strict_types=1);
session_start();
require_once '../../models/conexion.php';

// 1) Autenticación básica
if (empty($_SESSION['iduser'])) {
  header('Location: ../login/login.php');
  exit;
}
$idSession = (int)$_SESSION['iduser'];
$rolSession = strtolower($_SESSION['rol'] ?? 'usuario');

// 2) Parámetro obligatorio
$id_reserva = (int)($_GET['id_reserva'] ?? 0);
if ($id_reserva <= 0) {
  http_response_code(400);
  echo "Reserva inválida."; exit;
}

// 3) Obtener la reserva + validación de acceso (dueño o admin)
$sql = "
  SELECT r.id_reserva, r.id_usuario, r.id_area, r.fecha_inicio, r.fecha_fin, r.estado,
         a.nombre_area,
         u.nombre || ' ' || u.apellido AS solicitante
  FROM reserva r
  JOIN area_comun a ON a.id_area = r.id_area
  JOIN usuario u    ON u.iduser  = r.id_usuario
  WHERE r.id_reserva = :id
";
$st = $conexion->prepare($sql);
$st->execute([':id'=>$id_reserva]);
$reserva = $st->fetch(PDO::FETCH_ASSOC);

if (!$reserva) {
  http_response_code(404);
  echo "No existe la reserva."; exit;
}

// Permitir solo al dueño o al admin ver/pagar
if (($rolSession!=='admin') && ($reserva['id_usuario'] != $idSession)) {
  http_response_code(403);
  echo "No autorizado para pagar esta reserva."; exit;
}

// 4) Monto didáctico por tipo/nombre de área
$area = strtoupper($reserva['nombre_area'] ?? '');
$monto = 40.00; // default

if (str_contains($area, 'SALON'))      $monto = 80.00;
elseif (str_contains($area, 'PARRILL')) $monto = 60.00;
elseif (str_contains($area, 'GIMNAS'))  $monto = 40.00;
elseif (str_contains($area, 'PARQUE'))  $monto = 20.00;
elseif (str_contains($area, 'JARD'))    $monto = 30.00;

// 5) Ver si ya existe un “pago” asociado (mock usando concepto)
$sqlPaid = "
  SELECT COUNT(*) 
  FROM pago
  WHERE id_usuario = :u
    AND estado = 'Pagado'
    AND concepto ILIKE :c
";
$stp = $conexion->prepare($sqlPaid);
$stp->execute([
  ':u' => (int)$reserva['id_usuario'],
  ':c' => 'Reserva #'.$id_reserva.'%'
]);
$yaPagado = ((int)$stp->fetchColumn() > 0);

// 6) Procesar “pago” (mock)
$mensaje = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pagar']) && !$yaPagado) {
  // Inserta un pago "didáctico"
  $concepto = 'Reserva #'.$id_reserva.' – '.$reserva['nombre_area'];
  $conexion->prepare("
    INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
    VALUES (:u, :m, NOW(), :c, 'Pagado')
  ")->execute([
    ':u' => (int)$reserva['id_usuario'],
    ':m' => $monto,
    ':c' => $concepto
  ]);

  // (Opcional) notificación al solicitante
  try {
    $conexion->prepare("
      INSERT INTO notificacion (id_usuario, mensaje, tipo, url, icono)
      VALUES (:u, :m, 'PAGO', :url, 'bi-cash-coin')
    ")->execute([
      ':u'  => (int)$reserva['id_usuario'],
      ':m'  => "Pago registrado para la reserva #$id_reserva ({$reserva['nombre_area']})",
      ':url'=> "../usuario/reservas.php"
    ]);
  } catch (\Throwable $e) { /* si no existe notificacion, ignorar */ }

  $yaPagado = true;
  $mensaje  = '✅ ¡Pago realizado con éxito!';
}

// 7) Formato de fechas
function fmtDT(?string $d): string {
  return $d ? date('d/m/Y H:i', strtotime($d)) : '—';
}

// 8) HTML
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Pago de Reserva #<?= (int)$id_reserva ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --dark:#0F3557; --acc:#1BAAA6;}
    body{font-family:'Segoe UI',Arial,sans-serif;background:#f5f7fb;}
    .navbar{background:var(--dark);}
    .card-domus{border:0;border-radius:14px;box-shadow:0 6px 18px rgba(15,53,87,.08);background:#fff;}
    .btn-domus{background:var(--dark);color:#fff;border:none;}
    .btn-domus:hover{background:var(--acc);}
    .qr-box{
      width: 220px; height: 220px; border:2px dashed #1BAAA6; border-radius:12px;
      display:flex; align-items:center; justify-content:center; color:#0F3557; font-weight:700;
      background:#e9f7f6;
    }
  </style>
</head>
<body>

<nav class="navbar navbar-dark px-3">
  <div class="container-fluid">
    <a class="navbar-brand text-white" href="./reservas.php"><i class="bi bi-arrow-left-circle"></i> Volver</a>
    <span class="text-white fw-bold">Pago de Reserva #<?= (int)$id_reserva ?></span>
  </div>
</nav>

<main class="container my-4">

  <?php if($mensaje): ?>
    <div class="alert alert-success"><?= $mensaje ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-12 col-lg-7">
      <div class="card card-domus p-3">
        <h5 class="mb-3"><i class="bi bi-calendar-event"></i> Detalle de la reserva</h5>
        <dl class="row mb-0">
          <dt class="col-sm-4">Área</dt>
          <dd class="col-sm-8"><?= htmlspecialchars($reserva['nombre_area']) ?></dd>

          <dt class="col-sm-4">Solicitante</dt>
          <dd class="col-sm-8"><?= htmlspecialchars($reserva['solicitante']) ?></dd>

          <dt class="col-sm-4">Inicio</dt>
          <dd class="col-sm-8"><?= fmtDT($reserva['fecha_inicio']) ?></dd>

          <dt class="col-sm-4">Fin</dt>
          <dd class="col-sm-8"><?= fmtDT($reserva['fecha_fin']) ?></dd>

          <dt class="col-sm-4">Estado</dt>
          <dd class="col-sm-8"><span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($reserva['estado'])) ?></span></dd>

          <dt class="col-sm-4">Monto</dt>
          <dd class="col-sm-8"><b>Bs <?= number_format($monto,2) ?></b></dd>
        </dl>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="card card-domus p-3">
        <h5 class="mb-3"><i class="bi bi-credit-card"></i> Formas de pago (DEMO)</h5>

        <?php if ($yaPagado): ?>
          <div class="alert alert-info">
            <i class="bi bi-check2-circle"></i> Esta reserva ya registra un pago. ¡Gracias!
          </div>
        <?php else: ?>
          <p class="text-muted">Escanea el QR (simulado) o presiona el botón <b>Pagar ahora</b> para registrar el pago con fines didácticos.</p>
          <div class="d-flex justify-content-center my-3">
            <div class="qr-box">QR DEMO</div>
          </div>
          <form method="post">
            <input type="hidden" name="pagar" value="1">
            <button class="btn btn-domus w-100">
              <i class="bi bi-cash-coin"></i> Pagar ahora (Demo)
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

</main>

</body>
</html>