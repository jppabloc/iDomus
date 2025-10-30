<?php
// app/views/dashboard/ingresosEgresos.php
session_start();
require_once '../../models/conexion.php';

// Guardia admin
if (empty($_SESSION['iduser']) || (($_SESSION['rol'] ?? '') !== 'admin')) {
  header('Location: ../login/login.php');
  exit;
}
$nombre = $_SESSION['nombre'] ?? 'Administrador';
$rol    = $_SESSION['rol'] ?? 'admin';

function sql_date($s){ return preg_match('/^\d{4}\-\d{2}\-\d{2}$/',$s)?$s:null; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ====== Datos auxiliares ======
$personal = $conexion->query("
  SELECT u.iduser, (u.apellido||', '||u.nombre) AS nombre, u.correo
  FROM usuario u
  JOIN usuario_rol ur ON ur.iduser=u.iduser
  JOIN rol r ON r.idrol=ur.idrol
  WHERE r.nombre_rol='personal'
  ORDER BY u.apellido,u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$hoy    = new DateTimeImmutable('today');
$desdeD = $hoy->modify('first day of -2 month')->format('Y-m-d');
$hastaD = $hoy->format('Y-m-d');

$from = sql_date($_GET['from'] ?? $desdeD) ?: $desdeD;
$to   = sql_date($_GET['to']   ?? $hastaD) ?: $hastaD;
$tipo = trim($_GET['tipo'] ?? ''); // '', INGRESO, EGRESO

$flash = ''; $flash_ok = true;

// ====== HANDLERS: Carga masiva ======
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Normaliza arrays
  $arr = function($k){ return array_values(array_filter($_POST[$k] ?? [], fn($v)=>trim((string)$v) !== '')); };

  // ---------- INGRESO MASIVO ----------
  if (($_POST['action'] ?? '') === 'batch_ingreso') {
    $fecha  = sql_date($_POST['fecha'] ?? date('Y-m-d')) ?: date('Y-m-d');
    $medio  = trim($_POST['medio'] ?? 'Efectivo');
    $conceptos = $arr('concepto');
    $montos    = array_map('floatval', $_POST['monto'] ?? []);

    // Depura por pares válidos
    $items = [];
    foreach ($conceptos as $i=>$c) {
      $m = (float)($montos[$i] ?? 0);
      if ($c !== '' && $m > 0) $items[] = [$c,$m];
    }

    if (!$items) { $flash="Debe ingresar al menos un ítem válido."; $flash_ok=false; }
    else {
      try {
        $conexion->beginTransaction();
        $st = $conexion->prepare("INSERT INTO movimiento_financiero
          (tipo, fecha, concepto, monto, medio, observacion, creado_por)
          VALUES ('INGRESO', :f, :c, :m, :me, :o, :u)");
        foreach($items as [$c,$m]){
          $st->execute([
            ':f'=>$fecha, ':c'=>$c, ':m'=>$m, ':me'=>$medio,
            ':o'=>trim($_POST['obs'] ?? ''), ':u'=>($_SESSION['iduser'] ?? null)
          ]);
        }
        $conexion->commit();
        $flash = 'Ingresos registrados (' . count($items) . ').';
        $flash_ok = true;
      } catch(Exception $e){
        $conexion->rollBack();
        $flash = 'No se pudo registrar ingresos: '.$e->getMessage();
        $flash_ok=false;
      }
    }
  }

  // ---------- EGRESO MASIVO ----------
  if (($_POST['action'] ?? '') === 'batch_egreso') {
    $fecha  = sql_date($_POST['fecha_e'] ?? date('Y-m-d')) ?: date('Y-m-d');
    $medio  = trim($_POST['medio_e'] ?? 'Efectivo');
    $obs    = trim($_POST['obs_e'] ?? '');

    $conceptos = $arr('concepto_e');
    $montos    = array_map('floatval', $_POST['monto_e'] ?? []);
    $items = [];
    foreach ($conceptos as $i=>$c) {
      $m = (float)($montos[$i] ?? 0);
      if ($c !== '' && $m > 0) $items[] = [$c,$m];
    }

    $ap_desc = !empty($_POST['aplicar_descuento']);
    $idp     = (int)($_POST['id_personal'] ?? 0);
    $m_desc  = (float)($_POST['monto_descuento'] ?? 0);

    if (!$items && !($ap_desc && $idp>0 && $m_desc>0)) { 
      $flash="Debe ingresar al menos un egreso o un descuento válido."; 
      $flash_ok=false; 
    } else {
      try{
        $conexion->beginTransaction();

        // egresos
        if ($items) {
          $st = $conexion->prepare("INSERT INTO movimiento_financiero
            (tipo, fecha, concepto, monto, medio, observacion, creado_por)
            VALUES ('EGRESO', :f, :c, :m, :me, :o, :u)");
          foreach($items as [$c,$m]){
            $st->execute([
              ':f'=>$fecha, ':c'=>$c, ':m'=>$m, ':me'=>$medio, ':o'=>$obs, ':u'=>($_SESSION['iduser'] ?? null)
            ]);
          }
        }

        // descuento a personal (se registra en pago_personal con estado 'DESCUENTO')
        if ($ap_desc && $idp>0 && $m_desc>0){
          $conexion->prepare("INSERT INTO pago_personal
            (id_personal, fecha_pago, monto, concepto, medio, estado, observacion, creado_por)
            VALUES (:p,:f,:m,:c,'Interno','DESCUENTO',:o,:u)")
          ->execute([
            ':p'=>$idp, ':f'=>$fecha, ':m'=>$m_desc,
            ':c'=>trim($_POST['concepto_descuento'] ?? 'Descuento por incumplimiento'),
            ':o'=>$obs, ':u'=>($_SESSION['iduser'] ?? null)
          ]);
        }

        $conexion->commit();
        $msg = [];
        if ($items) $msg[] = "Egresos: ".count($items);
        if ($ap_desc && $idp>0 && $m_desc>0) $msg[] = "Descuento aplicado";
        $flash = implode(" · ", $msg).".";
        $flash_ok = true;
      } catch(Exception $e){
        $conexion->rollBack();
        $flash = 'No se pudo registrar: '.$e->getMessage();
        $flash_ok=false;
      }
    }
  }
}

// ====== filtros de listado ======
$where = ["fecha BETWEEN :from AND :to"];
$params = [':from'=>$from, ':to'=>$to];
if ($tipo!==''){
  $where[] = "UPPER(tipo)=:tipo";
  $params[':tipo'] = strtoupper($tipo);
}
$whereSQL = "WHERE ".implode(" AND ", $where);

// totales
$st = $conexion->prepare("SELECT
  COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto END),0) AS tot_ing,
  COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto END),0) AS tot_egr
  FROM movimiento_financiero $whereSQL");
$st->execute($params);
$tot = $st->fetch(PDO::FETCH_ASSOC);
$tot_ing = (float)($tot['tot_ing'] ?? 0);
$tot_egr = (float)($tot['tot_egr'] ?? 0);
$neto    = $tot_ing - $tot_egr;

// tabla
$st = $conexion->prepare("
  SELECT id_mov, fecha, tipo, concepto, monto, medio, observacion, creado_en
  FROM movimiento_financiero
  $whereSQL
  ORDER BY fecha DESC, id_mov DESC
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ========== EXPORTS ==========
function _pdf_escape($s){
  $s = str_replace("\\", "\\\\", (string)$s);
  $s = str_replace(["(",")"], ["\\(", "\\)"], $s);
  return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $s);
}
function build_simple_pdf($title, $kpis, $headers, $rows){
  // A4 landscape simple (idéntico a tu versión funcional)
  $objects=[]; $offsets=[]; $pdf="%PDF-1.4\n";
  $objects[]=['n'=>1,'data'=>"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\n"];
  $W=841.89; $H=595.28; $top=$H-40; $line_h=14;

  $kids=[]; $content_ids=[]; $bufs=[];
  $make_page=function() use (&$objects,&$kids,&$content_ids,$W,$H){
    $cid = count($objects)+2; $pid = $cid+1;
    $content_ids[]=$cid;
    $objects[]=['n'=>$pid,'data'=>"<< /Type /Page /Parent 0 0 R /MediaBox [0 0 $W $H] /Resources << /Font << /F1 1 0 R >> >> /Contents $cid 0 R >>\n"];
    $kids[]=$pid;
  };
  $y=$top;
  $start=function() use (&$make_page,&$bufs,&$y,$top){ $make_page(); $bufs[]="BT\n/F1 11 Tf\n"; $y=$top; };
  $end=function() use (&$bufs){ $bufs[count($bufs)-1].="ET\n"; };
  $start();
  $bufs[count($bufs)-1].=sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n",40.0,$y,_pdf_escape($title)); $y-=18;
  foreach($kpis as $k){ if($y<50){$end();$start();} $bufs[count($bufs)-1].=sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n",40.0,$y,_pdf_escape($k)); $y-=$line_h; }
  $y-=4; if($y<60){$end();$start();}
  $x=40.0; foreach($headers as $h){ $bufs[count($bufs)-1].=sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n",$x,$y,_pdf_escape($h)); $x+=120.0; } $y-=$line_h;
  foreach($rows as $row){
    if($y<40){ $end(); $start(); $x=40.0; foreach($headers as $h){ $bufs[count($bufs)-1].=sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n",$x,$y,_pdf_escape($h)); $x+=100.0; } $y-=$line_h; }
    $x=40.0; foreach($row as $cell){ $bufs[count($bufs)-1].=sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n",$x,$y,_pdf_escape((string)$cell)); $x+=100.0; } $y-=$line_h;
  }
  $end();
  foreach($bufs as $i=>$s){ $objects[]=['n'=>$content_ids[$i],'data'=>"<< /Length ".strlen($s)." >>\nstream\n$s\nendstream\n"]; }
  $pages_id = count($objects)+2;
  $kids_ref = implode(' ', array_map(fn($k)=>"$k 0 R",$kids)).' ';
  $objects[]=['n'=>$pages_id,'data'=>"<< /Type /Pages /Count ".count($kids)." /Kids [ $kids_ref ] >>\n"];
  // Fix parents
  $fixed=[]; foreach($objects as $o){ if($o['n']>1 && strpos($o['data'],'/Type /Page')!==false){ $fixed[]=['n'=>$o['n'],'data'=>preg_replace('/\/Parent 0 0 R/',"/Parent $pages_id 0 R",$o['data'])]; } }
  $objects=array_values(array_filter($objects,fn($o)=>strpos($o['data'],'/Type /Page')===false));
  foreach($fixed as $fp){ $objects[]=$fp; }
  $catalog_id = count($objects)+2;
  $objects[]=['n'=>$catalog_id,'data'=>"<< /Type /Catalog /Pages $pages_id 0 R >>\n"];
  usort($objects,fn($a,$b)=>$a['n']<=>$b['n']);
  $pdf="%PDF-1.4\n"; $offsets[0]=0; foreach($objects as $o){ $offsets[$o['n']]=strlen($pdf); $pdf.=$o['n']." 0 obj\n".$o['data']."endobj\n"; }
  $xref=strlen($pdf); $max=max(array_column($objects,'n'));
  $pdf.="xref\n0 ".($max+1)."\n"; $pdf.=sprintf("%010d %05d f \n",0,65535);
  for($i=1;$i<=$max;$i++){ $off=$offsets[$i] ?? 0; $pdf.=sprintf("%010d %05d n \n",$off,0); }
  $pdf.="trailer\n<< /Size ".($max+1)." /Root $catalog_id 0 R >>\nstartxref\n$xref\n%%EOF";
  return $pdf;
}

// CSV
if (isset($_GET['export']) && $_GET['export']==='csv'){
  $fn = "movimientos_".date('Ymd_His').".csv";
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$fn.'"');
  $out=fopen('php://output','w');
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
  fputcsv($out, ['ID','Fecha','Tipo','Concepto','Monto','Medio','Observación','Creado']);
  foreach($rows as $r){
    fputcsv($out, [
      $r['id_mov'], $r['fecha'], $r['tipo'], $r['concepto'],
      number_format((float)$r['monto'],2,'.',''),
      $r['medio'], $r['observacion'], $r['creado_en']
    ]);
  }
  fclose($out); exit;
}

// PDF
if (isset($_GET['export']) && $_GET['export']==='pdf'){
  $title = "Ingresos/Egresos ($from a $to)";
  $kpis = [
    "Generado: ".date('Y-m-d H:i')." · Usuario: ".$nombre." (".$rol.")",
    "Total Ingresos: Bs ".number_format($tot_ing,2),
    "Total Egresos:  Bs ".number_format($tot_egr,2),
    "Neto:           Bs ".number_format($neto,2)
  ];
  $headers = ['#','Fecha','Tipo','Concepto','Monto (Bs)','Medio','Observación'];
  $data = [];
  foreach($rows as $r){
    $data[] = [
      (int)$r['id_mov'],
      (string)$r['fecha'],
      (string)$r['tipo'],
      (string)$r['concepto'],
      number_format((float)$r['monto'],2,'.',''),
      (string)$r['medio'],
      (string)$r['observacion']
    ];
  }
  $pdf = build_simple_pdf($title, $kpis, $headers, $data);
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="movimientos_'.date('Ymd_His').'.pdf"');
  header('Content-Length: '.strlen($pdf));
  echo $pdf; exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Ingresos/Egresos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root{
      --primary:#0F3557; --secondary:#1BAAA6; --tertiary:#4D4D4D; --text:#333333;
    }
    body{ background:#f7fafd; color:var(--text); }
    .navbar{ background:var(--primary); }
    .brand{ color:#fff; font-weight:700; }
    .btn-idomus{ background:var(--primary); color:#fff; border:none; }
    .btn-idomus:hover{ background:var(--secondary); }
    .card{ border:none; border-radius:14px; box-shadow:0 6px 24px rgba(0,0,0,.06); }
    .table thead th { background:var(--secondary); color:#fff; border:none; }
    .kpi .title{ color:#6b7a8a; font-size:.9rem; }
    .kpi .value{ font-size:1.5rem; font-weight:800; color:var(--primary); }
    .kpi .ing{ color:#0a7b68; }
    .kpi .egr{ color:#b00020; }
    .badge-chip{ background:#e9f7f6; color:var(--primary); border-radius:16px; padding:.25rem .55rem; font-weight:600; }
    .modal-header{ background:#0F3557; color:#fff; }
    .link-template{ color:var(--secondary); font-weight:600; cursor:pointer; text-decoration:underline; }
  </style>
</head>
<body>
<nav class="navbar sticky-top shadow-sm">
  <div class="container-fluid">
    <a href="finanzas.php" class="btn btn-outline-light me-2"><i class="bi bi-arrow-left"></i></a>
    <span class="brand">Ingresos / Egresos</span>
    <span class="badge-chip"><?=h($nombre)?> · <?=h(ucfirst($rol))?></span>
  </div>
</nav>

<div class="container-xxl my-3">

  <?php if ($flash): ?>
    <div class="alert <?= $flash_ok?'alert-success':'alert-danger' ?> py-2"><?=h($flash)?></div>
  <?php endif; ?>

  <!-- Filtros -->
  <div class="card p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-6 col-md-3">
        <label class="form-label small">Desde</label>
        <input type="date" name="from" class="form-control" value="<?=h($from)?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small">Hasta</label>
        <input type="date" name="to" class="form-control" value="<?=h($to)?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small">Tipo</label>
        <select name="tipo" class="form-select">
          <option value="" <?= $tipo===''?'selected':''?>>Todos</option>
          <option value="INGRESO" <?= $tipo==='INGRESO'?'selected':''?>>INGRESO</option>
          <option value="EGRESO"  <?= $tipo==='EGRESO'?'selected':''?>>EGRESO</option>
        </select>
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-idomus"><i class="bi bi-funnel"></i> Filtrar</button>
        <a class="btn btn-outline-secondary" href="ingresosEgresos.php"><i class="bi bi-eraser"></i> Limpiar</a>
        <a class="btn btn-outline-success ms-auto" href="?from=<?=h($from)?>&to=<?=h($to)?>&tipo=<?=h($tipo)?>&export=csv">
          <i class="bi bi-file-earmark-spreadsheet"></i> CSV
        </a>
        <a class="btn btn-outline-danger" href="?from=<?=h($from)?>&to=<?=h($to)?>&tipo=<?=h($tipo)?>&export=pdf">
          <i class="bi bi-filetype-pdf"></i> PDF
        </a>
      </div>
    </form>
  </div>

  <!-- Acciones rápidas -->
  <div class="card p-3 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalIngreso">
        <i class="bi bi-plus-circle"></i> Registrar Ingreso(s)
      </button>
      <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalEgreso">
        <i class="bi bi-dash-circle"></i> Registrar Egreso(s)
      </button>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3">
    <div class="col-12 col-md-4">
      <div class="card p-3 kpi"><div class="title">Total Ingresos</div><div class="value ing">Bs <?=number_format($tot_ing,2)?></div></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card p-3 kpi"><div class="title">Total Egresos</div><div class="value egr">Bs <?=number_format($tot_egr,2)?></div></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card p-3 kpi"><div class="title">Neto</div><div class="value">Bs <?=number_format($neto,2)?></div></div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card p-3 my-3">
    <h6>Movimientos</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle">
        <thead>
          <tr>
            <th>#</th><th>Fecha</th><th>Tipo</th><th>Concepto</th>
            <th class="text-end">Monto (Bs)</th><th>Medio</th><th>Observación</th><th>Creado</th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted">Sin registros.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id_mov'] ?></td>
            <td><?= h($r['fecha']) ?></td>
            <td><?= h($r['tipo']) ?></td>
            <td><?= h($r['concepto']) ?></td>
            <td class="text-end"><?= number_format((float)$r['monto'],2) ?></td>
            <td><?= h($r['medio']) ?></td>
            <td><?= h($r['observacion']) ?></td>
            <td><?= h($r['creado_en']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ============== MODAL INGRESO ============== -->
<div class="modal fade" id="modalIngreso" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post">
      <div class="modal-header">
        <h5 class="modal-title">Registrar Ingreso(s)</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="batch_ingreso">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label small">Fecha</label>
            <input type="date" class="form-control" name="fecha" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="col-6">
            <label class="form-label small">Medio</label>
            <select class="form-select" name="medio">
              <option>Efectivo</option><option>Transferencia</option><option>Tarjeta</option><option>Otro</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small">Observación</label>
            <input type="text" class="form-control" name="obs" placeholder="Opcional">
          </div>
        </div>

        <hr>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="small">
            Puedes usar la <span id="btnPlantilla165" class="link-template">Plantilla paquete Bs 165</span>
          </div>
          <div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addIngreso"><i class="bi bi-plus"></i> Ítem</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle" id="tblIngreso">
            <thead>
              <tr><th>Concepto</th><th style="width:140px" class="text-end">Monto (Bs)</th><th style="width:40px"></th></tr>
            </thead>
            <tbody></tbody>
            <tfoot>
              <tr>
                <th class="text-end">Total</th>
                <th class="text-end"><span id="totIng">0.00</span></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-success"><i class="bi bi-save"></i> Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- ============== MODAL EGRESO ============== -->
<div class="modal fade" id="modalEgreso" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post">
      <div class="modal-header">
        <h5 class="modal-title">Registrar Egreso(s)</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="batch_egreso">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label small">Fecha</label>
            <input type="date" class="form-control" name="fecha_e" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="col-6">
            <label class="form-label small">Medio</label>
            <select class="form-select" name="medio_e">
              <option>Efectivo</option><option>Transferencia</option><option>Tarjeta</option><option>Otro</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small">Observación</label>
            <input type="text" class="form-control" name="obs_e" placeholder="Opcional">
          </div>
        </div>

        <hr>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div></div>
          <div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addEgreso"><i class="bi bi-plus"></i> Ítem</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle" id="tblEgreso">
            <thead>
              <tr><th>Concepto</th><th style="width:140px" class="text-end">Monto (Bs)</th><th style="width:40px"></th></tr>
            </thead>
            <tbody></tbody>
            <tfoot>
              <tr>
                <th class="text-end">Total</th>
                <th class="text-end"><span id="totEgr">0.00</span></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>

        <hr>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="apDesc" name="aplicar_descuento">
          <label for="apDesc" class="form-check-label">Aplicar descuento a personal</label>
        </div>
        <div class="row g-2 align-items-end" id="descWrap" style="display:none;">
          <div class="col-12 col-md-5">
            <label class="form-label small">Personal</label>
            <select class="form-select" name="id_personal">
              <option value="">Seleccione…</option>
              <?php foreach($personal as $p): ?>
                <option value="<?=$p['iduser']?>"><?=h($p['nombre'].' · '.$p['correo'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label small">Monto descuento (Bs)</label>
            <input type="number" step="0.01" min="0.01" class="form-control" name="monto_descuento" placeholder="0.00">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small">Concepto descuento</label>
            <input type="text" class="form-control" name="concepto_descuento" placeholder="Descuento por incumplimiento">
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-warning"><i class="bi bi-save"></i> Guardar</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const fmt = n => (Number(n||0)).toFixed(2);

  // ===== Ingreso =====
  const tblIng = document.querySelector('#tblIngreso tbody');
  const totIng = document.querySelector('#totIng');
  document.getElementById('addIngreso').addEventListener('click', ()=> addRow(tblIng,'concepto','monto', totIng));
  function recalc(tbody, totalSpan){
    let sum=0;
    tbody.querySelectorAll('input[name$="[monto]"], input[name="monto[]"], input[name="monto_e[]"]').forEach(i=> sum += Number(i.value||0));
    totalSpan.textContent = fmt(sum);
  }
  function addRow(tbody, nameC, nameM, totalSpan){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="text" name="${nameC}[]" class="form-control form-control-sm" placeholder="Concepto"></td>
      <td><input type="number" step="0.01" min="0.01" name="${nameM}[]" class="form-control form-control-sm text-end" placeholder="0.00"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delrow"><i class="bi bi-x"></i></button></td>
    `;
    tbody.appendChild(tr);
    tr.querySelector('input[name="'+nameM+'[]"]').addEventListener('input', ()=> recalc(tbody, totalSpan));
    tr.querySelector('.delrow').addEventListener('click', ()=>{ tr.remove(); recalc(tbody, totalSpan); });
  }
  // plantilla 165
  document.getElementById('btnPlantilla165').addEventListener('click', ()=>{
    tblIng.innerHTML = '';
    const items = [
      ['Jardín frente del bloque',16],
      ['Jardín general',34],
      ['Recojo de basura',15],
      ['Limpieza',18],
      ['Luz gradas',8],
      ['Cera, detergente y lavandina',2],
      ['Ahorro',20],
      ['Administración',30],
      ['Agua (1 personas)',22],
    ];
    items.forEach(()=> addRow(tblIng,'concepto','monto', totIng));
    const rows = tblIng.querySelectorAll('tr');
    items.forEach((it,idx)=> {
      rows[idx].querySelector('input[name="concepto[]"]').value = it[0];
      rows[idx].querySelector('input[name="monto[]"]').value    = it[1];
    });
    // recalcular
    let s = items.reduce((a,b)=>a+Number(b[1]),0);
    totIng.textContent = fmt(s); // 165.00
  });

  // ===== Egreso =====
  const tblEgr = document.querySelector('#tblEgreso tbody');
  const totEgr = document.querySelector('#totEgr');
  document.getElementById('addEgreso').addEventListener('click', ()=> addRow(tblEgr,'concepto_e','monto_e', totEgr));

  // toggle descuento
  const apDesc = document.getElementById('apDesc');
  const descWrap = document.getElementById('descWrap');
  apDesc.addEventListener('change', ()=> { descWrap.style.display = apDesc.checked ? '' : 'none'; });

  // recalcular totales al teclear
  ['tblIngreso','tblEgreso'].forEach(id=>{
    const t = document.getElementById(id);
    t?.addEventListener('input', (e)=>{
      if (id==='tblIngreso') recalc(tblIng, totIng);
      else recalc(tblEgr, totEgr);
    });
  });
})();
</script>
</body>
</html>