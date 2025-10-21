<?php
// app/views/usuario/pagos.php
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

// ========= Helpers =========
function tarifa_por_area(string $nombreArea): float {
  $n = mb_strtolower($nombreArea, 'UTF-8');
  if (mb_strpos($n,'salón') !== false || mb_strpos($n,'salon') !== false) return 80.00;
  if (mb_strpos($n,'parrill') !== false) return 50.00;
  if (mb_strpos($n,'gimnas') !== false) return 20.00;
  if (mb_strpos($n,'parque') !== false) return 15.00;
  if (mb_strpos($n,'jard')   !== false) return 30.00;
  return 40.00; // default
}

function mes_yyyy_mm(DateTime $dt): string {
  return $dt->format('Y-m'); // 2025-10
}

// ========= Email del usuario =========
$correoUsuario = '';
try {
  $st = $conexion->prepare("SELECT correo FROM usuario WHERE iduser=:u");
  $st->execute([':u'=>$iduser]);
  $correoUsuario = (string)($st->fetchColumn() ?: '');
} catch (\Throwable $e) {
  $correoUsuario = '';
}

// ========= POST: Pagar RESERVA (inserta en pago + manda recibo) =========
$alert = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='pay_now') {
  $id_reserva = (int)($_POST['id_reserva'] ?? 0);
  $area       = trim($_POST['area'] ?? '');
  $monto      = (float)($_POST['monto'] ?? 0);

  if ($id_reserva <= 0 || $area==='') {
    $alert = ['type'=>'danger','msg'=>'Datos incompletos para procesar el pago de reserva.'];
  } else {
    // Validar reserva del usuario
    $sqlR = "SELECT r.id_reserva, r.estado, a.nombre_area, e.nombre AS edificio, r.fecha_inicio, r.fecha_fin
               FROM reserva r
               JOIN area_comun a ON a.id_area = r.id_area
               JOIN edificio e   ON e.id_edificio = a.id_edificio
              WHERE r.id_reserva=:id AND r.id_usuario=:u";
    $st = $conexion->prepare($sqlR);
    $st->execute([':id'=>$id_reserva, ':u'=>$iduser]);
    $res = $st->fetch(PDO::FETCH_ASSOC);

    if (!$res) {
      $alert = ['type'=>'danger','msg'=>'No se encontró la reserva o no le pertenece.'];
    } else {
      // Ver si ya existe pago “PAGADO” para esta reserva
      $concepto = "RESERVA #{$id_reserva}";
      $stc = $conexion->prepare("
        SELECT COUNT(*) 
          FROM pago 
         WHERE id_usuario=:u 
           AND concepto=:c 
           AND UPPER(estado)='PAGADO'
      ");
      $stc->execute([':u'=>$iduser, ':c'=>$concepto]);
      $yaPagado = ((int)$stc->fetchColumn()) > 0;

      if ($yaPagado) {
        $alert = ['type'=>'warning','msg'=>'Esta reserva ya figura como pagada.'];
      } else {
        // Inserta pago
        $stm = $conexion->prepare("
          INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
          VALUES (:u, :m, NOW(), :c, 'Pagado')
        ");
        $stm->execute([':u'=>$iduser, ':m'=>$monto, ':c'=>$concepto]);

        // Recibo HTML
        $fechaI = !empty($res['fecha_inicio']) ? date('d/m/Y H:i', strtotime($res['fecha_inicio'])) : '—';
        $fechaF = !empty($res['fecha_fin'])    ? date('d/m/Y H:i', strtotime($res['fecha_fin']))    : '—';
        $edif   = (string)($res['edificio'] ?? '—');

        $dataQR = rawurlencode("iDomus|Reserva#{$id_reserva}|Area:{$area}|Monto:Bs ".number_format($monto,2)."|Usuario:{$nombreS}|Pago:PAGADO");
        $qrURL  = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data={$dataQR}";

        $html = '<!DOCTYPE html><html lang="es"><meta charset="utf-8"><body style="font-family:Segoe UI,Arial,sans-serif;color:#333;">
          <div style="max-width:600px;margin:10px auto;padding:16px;border:1px solid #eee;border-radius:12px;">
            <h2 style="margin:0 0 8px 0;">iDomus · Recibo de Pago</h2>
            <div style="color:#666;margin-bottom:12px;">(Este no es un comprobante bancario. Uso didáctico.)</div>
            <hr>
            <p><b>Reserva #'.$id_reserva.'</b></p>
            <p>Área: <b>'.htmlspecialchars($area).'</b><br>
               Edificio: '.htmlspecialchars($edif).'</p>
            <p>Inicio: '.$fechaI.'<br>Fin: '.$fechaF.'</p>
            <p>Monto pagado: <b>Bs '.number_format($monto,2).'</b></p>
            <div style="text-align:center;margin:16px 0;">
              <img src="'.$qrURL.'" alt="QR" style="width:220px;height:220px;border:2px dashed #1BAAA6;border-radius:12px;background:#e9f7f6;padding:8px;">
              <div style="color:#666;margin-top:6px;font-size:12px;">QR informativo de la transacción</div>
            </div>
            <hr>
            <p style="font-size:13px;color:#666">Fecha de emisión: '.date('d/m/Y H:i').'</p>
          </div>
        </body></html>';

        // Email + archivo local
        $okMail = false;
        if ($correoUsuario) {
          $to      = $correoUsuario;
          $subject = "iDomus · Recibo de Pago Reserva #{$id_reserva}";
          $headers = "MIME-Version: 1.0\r\n";
          $headers.= "Content-type: text/html; charset=UTF-8\r\n";
          $headers.= "From: iDomus <no-reply@idomus.local>\r\n";
          $okMail = @mail($to, $subject, $html, $headers);
        }
        $savedPath = null;
        try {
          $baseTmp = realpath(__DIR__.'/../../..'); // app/
          if (!$baseTmp) { $baseTmp = __DIR__.'/../../..'; }
          $dir = $baseTmp.'/tmp/recibos';
          if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
          $file = $dir.'/recibo_reserva_'.$id_reserva.'_'.date('Ymd_His').'.html';
          @file_put_contents($file, $html);
          if (is_file($file)) { $savedPath = $file; }
        } catch (\Throwable $e) { /* ignore */ }

        if ($okMail) {
          $alert = ['type'=>'success','msg'=>'✅ Pago de reserva registrado y recibo enviado a: '.htmlspecialchars($correoUsuario)];
        } else {
          $extra = $savedPath ? (' (copia local: <code>'.$savedPath.'</code>)') : '';
          $alert = ['type'=>'warning','msg'=>'Pago de reserva registrado. No se pudo enviar correo; se guardó copia local'.$extra];
        }
      }
    }
  }
}

// ========= POST: Pagar CUOTA MANTENIMIENTO =========
// Soporta DB con o sin cm.id_cuota (usa composite key como fallback)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='pay_cuota') {
  $keyType   = trim($_POST['cm_keytype'] ?? 'id');   // 'id' o 'key'
  $cm_id     = trim($_POST['cm_id'] ?? '');          // id_cuota o 'id_unidad|YYYY-MM-DD'
  $unidadLbl = trim($_POST['unidad_label'] ?? '—');  // nro_unidad
  $monto     = (float)($_POST['monto'] ?? 0);
  $fechaGen  = trim($_POST['fecha_gen'] ?? '');      // YYYY-MM-DD

  if ($cm_id==='') {
    $alert = ['type'=>'danger','msg'=>'Datos incompletos para procesar la cuota.'];
  } else {
    try {
      // Trae la cuota pendiente del usuario
      if ($keyType==='id') {
        $sqlC = "
          SELECT cm.id_unidad, cm.monto, cm.fecha_generacion, cm.fecha_vencimiento,
                 un.nro_unidad
            FROM cuota_mantenimiento cm
            JOIN unidad un ON un.id_unidad=cm.id_unidad
            JOIN residente_unidad ru ON ru.id_unidad=un.id_unidad AND ru.id_usuario=:u
           WHERE cm.id_cuota::text=:id AND UPPER(cm.estado)='PENDIENTE'
           LIMIT 1";
        $st = $conexion->prepare($sqlC);
        $st->execute([':u'=>$iduser, ':id'=>$cm_id]);
      } else {
        // cm_id = "id_unidad|YYYY-MM-DD"
        [$id_unidad_s, $fg] = explode('|', $cm_id, 2) + [null,null];
        $sqlC = "
          SELECT cm.id_unidad, cm.monto, cm.fecha_generacion, cm.fecha_vencimiento,
                 un.nro_unidad
            FROM cuota_mantenimiento cm
            JOIN unidad un ON un.id_unidad=cm.id_unidad
            JOIN residente_unidad ru ON ru.id_unidad=un.id_unidad AND ru.id_usuario=:u
           WHERE cm.id_unidad = :idu
             AND cm.fecha_generacion::date = :fg::date
             AND UPPER(cm.estado)='PENDIENTE'
           LIMIT 1";
        $st = $conexion->prepare($sqlC);
        $st->execute([':u'=>$iduser, ':idu'=>$id_unidad_s, ':fg'=>$fg]);
      }
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) {
        $alert = ['type'=>'danger','msg'=>'No se encontró la cuota pendiente o no le pertenece.'];
      } else {
        $montoReal = $row['monto'] ?? $monto;
        $dtGen     = new DateTime($row['fecha_generacion'] ?? $fechaGen ?: 'now');
        $ym        = mes_yyyy_mm($dtGen); // YYYY-MM

        $concepto = "CUOTA MANTENIMIENTO {$ym} · UNIDAD {$unidadLbl}";

        // ¿ya pagada?
        $stc = $conexion->prepare("
          SELECT COUNT(*) 
            FROM pago 
           WHERE id_usuario=:u 
             AND concepto=:c 
             AND UPPER(estado)='PAGADO'
        ");
        $stc->execute([':u'=>$iduser, ':c'=>$concepto]);
        $yaPagado = ((int)$stc->fetchColumn()) > 0;

        if ($yaPagado) {
          $alert = ['type'=>'warning','msg'=>'Esta cuota ya figura como pagada.'];
        } else {
          // Inserta pago
          $stm = $conexion->prepare("
            INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
            VALUES (:u, :m, NOW(), :c, 'Pagado')
          ");
          $stm->execute([':u'=>$iduser, ':m'=>(float)$montoReal, ':c'=>$concepto]);

          // Actualiza cuota
          if ($keyType==='id') {
            $conexion->prepare("UPDATE cuota_mantenimiento SET estado='Pagado' WHERE id_cuota::text=:id")
                     ->execute([':id'=>$cm_id]);
          } else {
            [$id_unidad_s, $fg] = explode('|', $cm_id, 2) + [null,null];
            $conexion->prepare("UPDATE cuota_mantenimiento SET estado='Pagado'
                                 WHERE id_unidad=:idu AND fecha_generacion::date=:fg::date")
                     ->execute([':idu'=>$id_unidad_s, ':fg'=>$fg]);
          }

          // Recibo HTML (simple)
          $dataQR = rawurlencode("iDomus|Cuota {$ym}|Unidad:{$unidadLbl}|Monto:Bs ".number_format($montoReal,2)."|Usuario:{$nombreS}|Pago:PAGADO");
          $qrURL  = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data={$dataQR}";
          $html = '<!DOCTYPE html><html lang="es"><meta charset="utf-8"><body style="font-family:Segoe UI,Arial,sans-serif;color:#333;">
            <div style="max-width:600px;margin:10px auto;padding:16px;border:1px solid #eee;border-radius:12px;">
              <h2 style="margin:0 0 8px 0;">iDomus · Recibo de Pago</h2>
              <div style="color:#666;margin-bottom:12px;">(Uso didáctico)</div>
              <hr>
              <p><b>Cuota de Mantenimiento '.$ym.'</b></p>
              <p>Unidad: <b>'.htmlspecialchars($unidadLbl).'</b></p>
              <p>Monto pagado: <b>Bs '.number_format((float)$montoReal,2).'</b></p>
              <div style="text-align:center;margin:16px 0;">
                <img src="'.$qrURL.'" alt="QR" style="width:220px;height:220px;border:2px dashed #1BAAA6;border-radius:12px;background:#e9f7f6;padding:8px;">
                <div style="color:#666;margin-top:6px;font-size:12px;">QR informativo</div>
              </div>
              <hr>
              <p style="font-size:13px;color:#666">Fecha de emisión: '.date('d/m/Y H:i').'</p>
            </div>
          </body></html>';

          // Email + archivo local
          $okMail = false;
          if ($correoUsuario) {
            $to      = $correoUsuario;
            $subject = "iDomus · Recibo Pago Cuota {$ym} · Unidad {$unidadLbl}";
            $headers = "MIME-Version: 1.0\r\n";
            $headers.= "Content-type: text/html; charset=UTF-8\r\n";
            $headers.= "From: iDomus <no-reply@idomus.local>\r\n";
            $okMail = @mail($to, $subject, $html, $headers);
          }
          $savedPath = null;
          try {
            $baseTmp = realpath(__DIR__.'/../../..'); // app/
            if (!$baseTmp) { $baseTmp = __DIR__.'/../../..'; }
            $dir = $baseTmp.'/tmp/recibos';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $file = $dir.'/recibo_cuota_'.$ym.'_'.preg_replace('/\W+/','',$unidadLbl).'_'.date('Ymd_His').'.html';
            @file_put_contents($file, $html);
            if (is_file($file)) { $savedPath = $file; }
          } catch (\Throwable $e) { /* ignore */ }

          if ($okMail) {
            $alert = ['type'=>'success','msg'=>"✅ Pago de cuota {$ym} registrado y recibo enviado a: ".htmlspecialchars($correoUsuario)];
          } else {
            $extra = $savedPath ? (' (copia local: <code>'.$savedPath.'</code>)') : '';
            $alert = ['type'=>'warning','msg'=>"Pago de cuota {$ym} registrado. No se pudo enviar correo; copia local guardada".$extra];
          }
        }
      }
    } catch (\Throwable $e) {
      $alert = ['type'=>'danger','msg'=>'Error al procesar el pago de cuota.'];
    }
  }
}

// ================== DATOS PARA UI ==================
// ---- RESERVAS APROBADAS (mismas que ya tenías) ----
$sql = "
  SELECT r.id_reserva, a.nombre_area, e.nombre AS edificio,
         r.fecha_inicio, r.fecha_fin, r.estado
    FROM reserva r
    JOIN area_comun a ON a.id_area = r.id_area
    JOIN edificio   e ON e.id_edificio = a.id_edificio
   WHERE r.id_usuario=:u
     AND UPPER(r.estado)='APROBADA'
   ORDER BY r.fecha_inicio DESC";
$st = $conexion->prepare($sql);
$st->execute([':u'=>$iduser]);
$reservas = $st->fetchAll(PDO::FETCH_ASSOC);

// Calcular pagado (consultando pago por concepto “RESERVA #ID”)
foreach ($reservas as &$r) {
  $concepto = "RESERVA #".$r['id_reserva'];
  $stmtP = $conexion->prepare("
    SELECT COUNT(*) 
      FROM pago 
     WHERE id_usuario=:u 
       AND concepto=:c 
       AND UPPER(estado)='PAGADO'
  ");
  $stmtP->execute([':u'=>$iduser, ':c'=>$concepto]);
  $r['pagado'] = ((int)$stmtP->fetchColumn()) > 0;
  $r['monto']  = tarifa_por_area($r['nombre_area'] ?? '');
}
unset($r);

// ---- CUOTAS PENDIENTES del usuario (maneja DB con o sin id_cuota) ----
$cuotas = [];
$cuotasKeyType = 'id'; // 'id' o 'key'
try {
  // Intento 1: con id_cuota
  $sqlC = "
    SELECT cm.id_cuota::text AS cm_id, un.nro_unidad, cm.monto, 
           cm.fecha_generacion::date AS fecha_generacion,
           cm.fecha_vencimiento::date AS fecha_vencimiento,
           cm.estado
      FROM cuota_mantenimiento cm
      JOIN unidad un ON un.id_unidad=cm.id_unidad
      JOIN residente_unidad ru ON ru.id_unidad=un.id_unidad AND ru.id_usuario=:u
     WHERE UPPER(cm.estado)='PENDIENTE'
     ORDER BY cm.fecha_generacion DESC";
  $stC = $conexion->prepare($sqlC);
  $stC->execute([':u'=>$iduser]);
  $cuotas = $stC->fetchAll(PDO::FETCH_ASSOC);
  $cuotasKeyType = 'id';
} catch (\Throwable $e) {
  // Intento 2: sin id_cuota -> composite key id_unidad|fecha_generacion
  $sqlC = "
    SELECT (cm.id_unidad::text || '|' || to_char(cm.fecha_generacion,'YYYY-MM-DD')) AS cm_id,
           un.nro_unidad, cm.monto, 
           cm.fecha_generacion::date AS fecha_generacion,
           cm.fecha_vencimiento::date AS fecha_vencimiento,
           cm.estado
      FROM cuota_mantenimiento cm
      JOIN unidad un ON un.id_unidad=cm.id_unidad
      JOIN residente_unidad ru ON ru.id_unidad=un.id_unidad AND ru.id_usuario=:u
     WHERE UPPER(cm.estado)='PENDIENTE'
     ORDER BY cm.fecha_generacion DESC";
  $stC = $conexion->prepare($sqlC);
  $stC->execute([':u'=>$iduser]);
  $cuotas = $stC->fetchAll(PDO::FETCH_ASSOC);
  $cuotasKeyType = 'key';
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Pagos (QR)</title>
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
    .qr-box{
      width:220px;height:220px;border:2px dashed #1BAAA6;border-radius:12px;background:#e9f7f6;padding:8px;
      display:flex;align-items:center;justify-content:center;
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
        <li class="nav-item"><a class="nav-link" href="./reservas.php">Reservas</a></li>
        <li class="nav-item"><a class="nav-link active" href="./pagos.php">Pagos (QR)</a></li>
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
  <?php if ($alert): ?>
    <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>"><?= $alert['msg'] ?></div>
  <?php endif; ?>

  <!-- ============ Sección 1: Reservas (QR) ============ -->
  <div class="card-domus p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="bi bi-qr-code"></i> Pagar reservas (QR)</h5>
      <div class="small text-muted">Tu correo: <b><?= htmlspecialchars($correoUsuario ?: '—') ?></b></div>
    </div>

    <div class="table-responsive mt-2">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>#Reserva</th>
            <th>Área</th>
            <th>Edificio</th>
            <th>Inicio</th>
            <th>Fin</th>
            <th>Monto</th>
            <th>Pago</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($reservas)): ?>
          <tr><td colspan="8" class="text-center text-muted">No tienes reservas aprobadas.</td></tr>
        <?php else: foreach ($reservas as $r): 
          $pagado = !empty($r['pagado']);
          $monto  = (float)$r['monto'];
        ?>
          <tr>
            <td><?= (int)$r['id_reserva'] ?></td>
            <td><?= htmlspecialchars($r['nombre_area'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['edificio'] ?? '') ?></td>
            <td><?= !empty($r['fecha_inicio'])? date('d/m/Y H:i', strtotime($r['fecha_inicio'])):'—' ?></td>
            <td><?= !empty($r['fecha_fin'])   ? date('d/m/Y H:i', strtotime($r['fecha_fin']))   :'—' ?></td>
            <td>Bs <?= number_format($monto,2) ?></td>
            <td>
              <?php if ($pagado): ?>
                <span class="badge bg-success">PAGADO</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">PENDIENTE</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-primary"
                      data-id="<?= (int)$r['id_reserva'] ?>"
                      data-area="<?= htmlspecialchars($r['nombre_area'] ?? '', ENT_QUOTES) ?>"
                      data-monto="<?= number_format($monto,2,'.','') ?>"
                      data-pagado="<?= $pagado ? '1':'0' ?>"
                      onclick="abrirQRReserva(this)">
                <i class="bi bi-qr-code"></i> Ver QR
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ============ Sección 2: Cuotas de mantenimiento pendientes ============ -->
  <div class="card-domus p-3">
    <h5 class="mb-2"><i class="bi bi-cash-coin"></i> Pagar cuotas de mantenimiento (agua/luz/cuota)</h5>
    <div class="table-responsive mt-2">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Unidad</th>
            <th>Mes</th>
            <th>Vencimiento</th>
            <th>Monto</th>
            <th>Estado</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($cuotas)): ?>
          <tr><td colspan="6" class="text-center text-muted">No tienes cuotas pendientes.</td></tr>
        <?php else: foreach ($cuotas as $c): 
          $montoC = (float)($c['monto'] ?? 0);
          $ym     = mes_yyyy_mm(new DateTime($c['fecha_generacion']));
        ?>
          <tr>
            <td><?= htmlspecialchars($c['nro_unidad'] ?? '—') ?></td>
            <td><?= htmlspecialchars($ym) ?></td>
            <td><?= !empty($c['fecha_vencimiento'])? date('d/m/Y', strtotime($c['fecha_vencimiento'])):'—' ?></td>
            <td>Bs <?= number_format($montoC,2) ?></td>
            <td><span class="badge bg-warning text-dark"><?= htmlspecialchars(strtoupper($c['estado'] ?? 'PENDIENTE')) ?></span></td>
            <td>
              <button class="btn btn-sm btn-outline-primary"
                      data-cmkeytype="<?= htmlspecialchars($cuotasKeyType) ?>"
                      data-cmid="<?= htmlspecialchars($c['cm_id']) ?>"
                      data-unidad="<?= htmlspecialchars($c['nro_unidad'] ?? '—', ENT_QUOTES) ?>"
                      data-monto="<?= number_format($montoC,2,'.','') ?>"
                      data-fechagen="<?= htmlspecialchars($c['fecha_generacion']) ?>"
                      onclick="abrirQRCuota(this)">
                <i class="bi bi-qr-code"></i> Ver QR
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Modal QR Reserva -->
<div class="modal fade" id="modalQRReserva" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="">
        <input type="hidden" name="action" value="pay_now">
        <input type="hidden" name="id_reserva" id="payres_id">
        <input type="hidden" name="area" id="payres_area">
        <input type="hidden" name="monto" id="payres_monto">

        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-qr-code"></i> Pago de Reserva</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <div><b>Reserva #<span id="qrres_reserva_id">—</span></b></div>
            <div>Área: <span id="qrres_area">—</span></div>
            <div>Monto: <b>Bs <span id="qrres_monto">0.00</span></b></div>
            <div class="small text-muted">Escanee el QR con su celular o presione “Pagar ahora”.</div>
          </div>
          <div class="d-flex justify-content-center my-3">
            <div class="qr-box"><img id="qrres_img" src="" alt="QR" style="width:100%;height:100%;object-fit:contain;"></div>
          </div>
          <div id="qrres_pagado" class="text-center small text-success d-none">
            ✅ Esta reserva ya está pagada.
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cerrar</button>
          <button class="btn btn-domus" id="btnPagarReserva" type="submit">
            <i class="bi bi-cash-coin"></i> Pagar ahora
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal QR Cuota -->
<div class="modal fade" id="modalQRCuota" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="">
        <input type="hidden" name="action" value="pay_cuota">
        <input type="hidden" name="cm_keytype" id="paycu_keytype">
        <input type="hidden" name="cm_id" id="paycu_id">
        <input type="hidden" name="unidad_label" id="paycu_unidad">
        <input type="hidden" name="monto" id="paycu_monto">
        <input type="hidden" name="fecha_gen" id="paycu_fgen">

        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-qr-code"></i> Pago de Cuota</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <div><b>Unidad:</b> <span id="qrcu_unidad">—</span></div>
            <div><b>Mes:</b> <span id="qrcu_mes">—</span></div>
            <div>Monto: <b>Bs <span id="qrcu_monto">0.00</span></b></div>
            <div class="small text-muted">Escanee el QR con su celular o presione “Pagar ahora”.</div>
          </div>
          <div class="d-flex justify-content-center my-3">
            <div class="qr-box"><img id="qrcu_img" src="" alt="QR" style="width:100%;height:100%;object-fit:contain;"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cerrar</button>
          <button class="btn btn-domus" id="btnPagarCuota" type="submit">
            <i class="bi bi-cash-coin"></i> Pagar ahora
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ====== QR RESERVA ======
function abrirQRReserva(btn){
  const id     = btn.getAttribute('data-id') || '0';
  const area   = btn.getAttribute('data-area') || '—';
  const monto  = btn.getAttribute('data-monto') || '0.00';
  const pagado = (btn.getAttribute('data-pagado') === '1');

  // Fill
  document.getElementById('qrres_reserva_id').textContent = id;
  document.getElementById('qrres_area').textContent       = area;
  document.getElementById('qrres_monto').textContent      = parseFloat(monto).toFixed(2);
  document.getElementById('payres_id').value              = id;
  document.getElementById('payres_area').value            = area;
  document.getElementById('payres_monto').value           = monto;

  const data = encodeURIComponent(`iDomus|Reserva#${id}|Area:${area}|Monto:Bs ${monto}`);
  const url  = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${data}`;
  document.getElementById('qrres_img').src = url;

  const info = document.getElementById('qrres_pagado');
  const btnP = document.getElementById('btnPagarReserva');
  if (pagado) { info.classList.remove('d-none'); btnP.disabled = true; }
  else        { info.classList.add('d-none');    btnP.disabled = false; }

  new bootstrap.Modal(document.getElementById('modalQRReserva')).show();
}

// ====== QR CUOTA ======
function abrirQRCuota(btn){
  const keytype = btn.getAttribute('data-cmkeytype') || 'id';
  const cm_id   = btn.getAttribute('data-cmid') || '';
  const unidad  = btn.getAttribute('data-unidad') || '—';
  const monto   = btn.getAttribute('data-monto') || '0.00';
  const fgen    = btn.getAttribute('data-fechagen') || ''; // YYYY-MM-DD

  // Fill
  document.getElementById('qrcu_unidad').textContent = unidad;
  document.getElementById('qrcu_monto').textContent  = parseFloat(monto).toFixed(2);
  document.getElementById('qrcu_mes').textContent    = fgen ? (new Date(fgen)).toISOString().slice(0,7) : '—';

  document.getElementById('paycu_keytype').value = keytype;
  document.getElementById('paycu_id').value      = cm_id;
  document.getElementById('paycu_unidad').value  = unidad;
  document.getElementById('paycu_monto').value   = monto;
  document.getElementById('paycu_fgen').value    = fgen;

  const ym = fgen ? (new Date(fgen)).toISOString().slice(0,7) : '';
  const data = encodeURIComponent(`iDomus|Cuota ${ym}|Unidad:${unidad}|Monto:Bs ${monto}`);
  const url  = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${data}`;
  document.getElementById('qrcu_img').src = url;

  new bootstrap.Modal(document.getElementById('modalQRCuota')).show();
}
</script>
</body>
</html>