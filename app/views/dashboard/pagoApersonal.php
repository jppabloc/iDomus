<?php
// app/views/dashboard/pagoApersonal.php
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

/* ===================== PDF HELPERS (A4 Landscape) ===================== */
/* Conversión segura a WinAnsi (Windows-1252) para Type1 Helvetica */
function pdf_ansi($s){
  $s = (string)$s;

  // Intento 1: iconv a Windows-1252 con transliteración
  $t = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);

  // Intento 2: mb_convert_encoding si iconv no está o falló
  if ($t === false || $t === null) {
    if (function_exists('mb_convert_encoding')) {
      $t = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }
  }

  // Intento 3: utf8_decode (a ISO-8859-1, muy compatible con Win-1252)
  if ($t === false || $t === null) {
    $t = utf8_decode($s);
  }

  // Remueve cualquier byte fuera de Win-1252 básico
  $t = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x80-\x9F\xA0-\xFF]/', ' ', $t);

  // Escapes PDF
  return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $t);
}
function pdf_num($n){ return rtrim(rtrim(number_format((float)$n,2,'.',''), '0'), '.'); }

function _pdf_page_content($W, $H, $title, $subtitle, $headers, $rows){
  // Márgenes y medidas
  $mL = 40; $mR = 40; $mT = 40; $mB = 40;
  $usableW = $W - $mL - $mR;
  $y = $H - $mT;

  // Definir anchos (8 columnas)
  $colW = [55,70,160,160,120,95,55,60];
  if (count($headers) !== count($colW)) {
    // ajuste simple si cambian headers
    $colW = array_fill(0, count($headers), (int) floor($usableW / max(count($headers),1)));
  }

  $line_h = 14;
  $headerH = 18;

  $c = "q\n";

  // Título
  $c .= "BT /F2 16 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (".pdf_ansi($title).") Tj ET\n";
  $y -= 22;

  // Subtítulo
  if ($subtitle){
    $c .= "BT /F1 10 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (".pdf_ansi($subtitle).") Tj ET\n";
    $y -= 16;
  }

  // Encabezado con fondo secundario
  $c .= "0.102 0.666 0.651 rg "; // #1BAAA6 aprox
  $c .= $mL." ".($y - $headerH + 4)." ".($usableW)." ".$headerH." re f\n";

  // Texto encabezados
  $cx = $mL + 4; $cy = $y - 12;
  $c .= "BT /F2 10 Tf 1 1 1 rg ";
  foreach($headers as $i=>$h){
    $c .= "1 0 0 1 ".$cx." ".$cy." Tm (".pdf_ansi($h).") Tj ";
    $cx += $colW[$i];
  }
  $c .= "ET\n";
  $y -= 26;

  // Filas
  foreach($rows as $r){
    if ($y < $mB + 30) break; // dejamos espacio para footer externo (control de corte en paginación)
    $cx = $mL + 4; $cy = $y;
    $c .= "BT /F1 9 Tf 0 0 0 rg ";
    foreach($r as $i=>$cell){
      $text = is_numeric($cell) ? pdf_num($cell) : (string)$cell;
      if (strlen($text) > 90) $text = substr($text,0,87).'...';
      $c .= "1 0 0 1 ".$cx." ".$cy." Tm (".pdf_ansi($text).") Tj ";
      $cx += $colW[$i];
    }
    $c .= "ET\n";
    $y -= $line_h;
  }

  $c .= "Q\n";
  return $c;
}

/* ===================== BOLETAS (PDF por boleta) ===================== */
// Página de boleta (A4 vertical)
function _boleta_page_content($W,$H,$empresa,$titulo,$emisor,$p,$resumen){
  $mL=40; $mR=40; $mT=40; $mB=40; $y=$H-$mT;
  $ansi = function($s){ $t=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',(string)$s); if($t===false)$t=(string)$s; return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$t); };
  $num = fn($n)=> rtrim(rtrim(number_format((float)$n,2,'.',''),'0'),'.');

  $c="q\n";
  // Encabezados
  $c.="BT /F2 16 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (".$ansi($empresa).") Tj ET\n";
  $y-=22;
  $c.="BT /F1 11 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (".$ansi($titulo).") Tj ET\n";
  $y-=16;
  $c.="BT /F1 9 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (Folio: ".$ansi($p['folio']).") Tj ET\n";
  $c.="BT /F1 9 Tf 0 0 0 rg 1 0 0 1 ".($W-220)." ".($y)." Tm (Emitido por: ".$ansi($emisor).") Tj ET\n";
  $y-=14;

  // Caja de datos del personal
  $boxH=88; $boxW=$W-$mL-$mR;
  $c.="0 0 0 RG 0.90 0.97 0.97 rg ".$mL." ".($y-$boxH)." ".$boxW." ".$boxH." re B\n";
  $c.="BT /F2 11 Tf 0 0 0 rg 1 0 0 1 ".($mL+8)." ".($y-16)." Tm (Personal) Tj ET\n";
  $c.="BT /F1 10 Tf 0 0 0 rg 1 0 0 1 ".($mL+8)." ".($y-32)." Tm (Nombre: ".$ansi($p['personal']).") Tj ET\n";
  $c.="BT /F1 10 Tf 0 0 0 rg 1 0 0 1 ".($mL+8)." ".($y-46)." Tm (Correo: ".$ansi($p['correo']).") Tj ET\n";
  $c.="BT /F1 10 Tf 0 0 0 rg 1 0 0 1 ".($mL+8)." ".($y-60)." Tm (Tel: ".$ansi($p['telefono']).") Tj ET\n";
  $y-=($boxH+14);

  // Detalle del pago
  $c.="BT /F2 11 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (Detalle del pago) Tj ET\n";
  $y-=14;
  $c.="BT /F1 10 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (Fecha: ".$ansi($p['fecha']).") Tj ET\n";
  $c.="BT /F1 10 Tf 0 0 0 rg 1 0 0 1 ".(250)." ".($y)." Tm (Concepto: ".$ansi($p['concepto']).") Tj ET\n";
  $y-=14;
  $c.="BT /F2 12 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (Monto del pago: Bs ".$num($p['monto']).") Tj ET\n";
  $y-=22;

  // Resumen mensual
  $c.="BT /F2 11 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (Resumen mensual ".$ansi($resumen['mes'])."/".$ansi($resumen['anio']).") Tj ET\n";
  $y-=16;
  $c.="BT /F1 10 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (Pagos del mes: Bs ".$num($resumen['pagos_mes']).") Tj ET\n"; $y-=12;
  $c.="BT /F1 10 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (Descuentos del mes: Bs ".$num($resumen['descuentos_mes']).") Tj ET\n"; $y-=12;
  $c.="BT /F2 12 Tf 0 0 0 rg 1 0 0 1 ".($mL)." ".($y)." Tm (Neto del mes: Bs ".$num($resumen['neto_mes']).") Tj ET\n";
  $y-=36;

  // Firmas
  $c.="0 0 0 RG ".($mL)." ".($y)." 180 0 m ".($mL+180)." ".($y)." l S\n";
  $c.="0 0 0 RG ".($W-220)." ".($y)." 180 0 m ".($W-40)." ".($y)." l S\n";
  $y-=12;
  $c.="BT /F1 9 Tf 0 0 0 rg 1 0 0 1 ".($mL+30)." ".($y)." Tm (Firma del trabajador) Tj ET\n";
  $c.="BT /F1 9 Tf 0 0 0 rg 1 0 0 1 ".($W-160)." ".($y)." Tm (Responsable/Administración) Tj ET\n";
  $c.="Q\n";
  return $c;
}

// Un PDF con N boletas (cada boleta = 1 página). A4 vertical
function build_boletas_pdf($empresa,$titulo,$emisor,$items){
  $W=595.28; $H=841.89; // A4 portrait
  $buf="%PDF-1.4\n"; 
  $objs=[];         // strings de objetos
  $page_obj_ids=[]; // ids de objetos /Page (para arreglar Parent luego)

  // 1) Fuentes
  $objs[]="<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";      // id=1 (F1)
  $objs[]="<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>"; // id=2 (F2)

  // 2) Contenidos y páginas (con marcador __PAGES_ID__ como Parent)
  foreach($items as $it){
    $cont=_boleta_page_content($W,$H,$empresa,$titulo,$emisor,$it['p'],$it['resumen']);
    // Contenido
    $objs[]="<< /Length ".strlen($cont)." >>\nstream\n".$cont."endstream";
    $cid = count($objs); // id del content
    // Página con Parent marcador
    $page_dict = "<< /Type /Page /Parent __PAGES_ID__ 0 R /MediaBox [0 0 ".$W." ".$H."] ".
                 "/Resources << /Font << /F1 1 0 R /F2 2 0 R >> >> /Contents ".$cid." 0 R >>";
    $objs[] = $page_dict;
    $page_obj_ids[] = count($objs); // guardamos id del Page
  }

  // 3) /Pages (Kids= todas las pages)
  $kids=''; foreach($page_obj_ids as $pid){ $kids.=$pid." 0 R "; }
  $objs[]="<< /Type /Pages /Kids [ ".$kids." ] /Count ".count($page_obj_ids)." >>";
  $pages_id = count($objs); // id real de /Pages

  // 4) /Catalog
  $objs[]="<< /Type /Catalog /Pages ".$pages_id." 0 R >>";
  $root_id = count($objs);

  // 5) Reemplazar marcador __PAGES_ID__ en cada /Page
  foreach($page_obj_ids as $pid){
    $objs[$pid-1] = str_replace('__PAGES_ID__', (string)$pages_id, $objs[$pid-1]);
  }

  // 6) xref
  $ofs=[]; 
  foreach($objs as $i=>$o){ 
    $ofs[$i+1]=strlen($buf); 
    $buf.=($i+1)." 0 obj\n".$o."\nendobj\n"; 
  }
  $xref=strlen($buf);
  $buf.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
  for($i=1;$i<=count($ofs);$i++){ $buf.=sprintf("%010d 00000 n \n",$ofs[$i]); }
  $buf.="trailer << /Size ".(count($objs)+1)." /Root ".$root_id." 0 R >>\nstartxref\n".$xref."\n%%EOF";

  return $buf;
}
/* ===================== FIN BOLETAS ===================== */

/* Construye PDF multi-página simple (cada página es un objeto real) */
function build_pdf_multipage($title, $subtitle, $headers, $allRows, $footer){
  $W = 841.89; $H = 595.28; // A4 Landscape
  $mL = 40; $mR = 40; $mT = 40; $mB = 40;
  $usableH = $H - $mT - $mB;

  // cálculo de filas por página aproximado (dejamos márgenes para título, subtítulo y header)
  $line_h = 14;
  $rows_per_page = max(10, (int) floor(($usableH - 60) / $line_h)); // 60px aprox (título+sub+header)

  // dividir rows en páginas
  $pages_data = [];
  for ($i=0; $i<count($allRows); $i += $rows_per_page){
    $pages_data[] = array_slice($allRows, $i, $rows_per_page);
  }
  if (empty($pages_data)) $pages_data = [[]];

  $buf = "%PDF-1.4\n";
  $objs = [];

  // Fonts
  $objs[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";       // 1 F1
  $objs[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>"; // 2 F2

  // Contents por página
  $content_ids = [];
  foreach($pages_data as $page_rows){
    $cont = _pdf_page_content($W,$H,$title,$subtitle,$headers,$page_rows);
    $objs[] = "<< /Length ".strlen($cont)." >>\nstream\n".$cont."endstream";
    $content_ids[] = count($objs); // id del objeto content
  }

  // Pages y Page objects
  $page_ids = [];
  foreach($content_ids as $cid){
    $objs[] = "<< /Type /Page /Parent ".(count($objs)+2)." 0 R /MediaBox [0 0 ".pdf_num($W)." ".pdf_num($H)."] ".
              "/Resources << /Font << /F1 1 0 R /F2 2 0 R >> >> /Contents ".$cid." 0 R >>";
    $page_ids[] = count($objs); // id del Page obj
  }

  // Pages node (Kids = lista de page_ids)
  $kids = '';
  foreach($page_ids as $pid) $kids .= $pid." 0 R ";
  $objs[] = "<< /Type /Pages /Kids [ ".$kids."] /Count ".count($page_ids)." >>";
  $pages_obj_id = count($objs);

  // Catalog
  $objs[] = "<< /Type /Catalog /Pages ".$pages_obj_id." 0 R >>";
  $root_id = count($objs);

  // XRef
  $ofs = [];
  foreach($objs as $i=>$o){
    $ofs[$i+1] = strlen($buf);
    $buf .= ($i+1)." 0 obj\n".$o."\nendobj\n";
  }
  $xref = strlen($buf);
  $buf .= "xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
  for($i=1;$i<=count($ofs);$i++){
    $buf .= sprintf("%010d 00000 n \n",$ofs[$i]);
  }
  $buf .= "trailer << /Size ".(count($objs)+1)." /Root ".$root_id." 0 R >>\nstartxref\n".$xref."\n%%EOF";
  return $buf;
}

function send_pdf_bytes($bytes, $filename){
  if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression','Off');
    @ini_set('output_buffering','Off');
    @ini_set('default_charset','');
  }
  // Limpiar cualquier buffer previo
  if (ob_get_level()) { @ob_end_clean(); while(ob_get_level()) @ob_end_clean(); }
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Content-Transfer-Encoding: binary');
  header('Accept-Ranges: bytes');
  header('Content-Length: '.strlen($bytes));
  echo $bytes;
  exit;
}
/* ===================== FIN PDF HELPERS ===================== */


/* ===================== Datos ===================== */
// Personal (usuarios con rol 'personal')
$personal = $conexion->query("
  SELECT u.iduser, u.nombre, u.apellido, u.correo, COALESCE(u.telefono,'') AS telefono
  FROM usuario u
  JOIN usuario_rol ur ON ur.iduser=u.iduser
  JOIN rol r ON r.idrol=ur.idrol
  WHERE r.nombre_rol='personal'
  ORDER BY u.apellido, u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$from = sql_date($_GET['from'] ?? date('Y-m-01')) ?: date('Y-m-01');
$to   = sql_date($_GET['to']   ?? date('Y-m-d'))  ?: date('Y-m-d');
$pid  = (int)($_GET['id_personal'] ?? 0);

$where = ["pp.fecha_pago BETWEEN :from AND :to"];
$params = [':from'=>$from, ':to'=>$to];
if ($pid>0){ $where[]="pp.id_personal=:pid"; $params[':pid']=$pid; }
$whereSQL = "WHERE ".implode(" AND ", $where);

$flash='';

// Alta pago (reflejo AUTOMÁTICO en tabla pago)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='nuevo_pago'){
  $idp   = (int)($_POST['id_personal'] ?? 0);
  $fecha = sql_date($_POST['fecha_pago'] ?? date('Y-m-d')) ?: date('Y-m-d');
  $monto = (float)($_POST['monto'] ?? 0);
  $concepto = trim($_POST['concepto'] ?? 'Sueldo');
  $medio = trim($_POST['medio'] ?? 'Efectivo');
  $obs   = trim($_POST['observacion'] ?? '');

  if ($idp>0 && $monto>0){
    try{
      $conexion->beginTransaction();

      // 1) pago_personal
      $st = $conexion->prepare("INSERT INTO pago_personal
        (id_personal, fecha_pago, monto, concepto, medio, estado, observacion, creado_por)
        VALUES (:p,:f,:m,:c,:me,'PAGADO',:o,:u)
        RETURNING id_pago_personal");
      $st->execute([
        ':p'=>$idp, ':f'=>$fecha, ':m'=>$monto, ':c'=>$concepto,
        ':me'=>$medio, ':o'=>$obs, ':u'=>($_SESSION['iduser'] ?? null)
      ]);
      $id_pp = (int)$st->fetchColumn();

      // 2) reflejo automático en pago (para KPIs globales)
      $conexion->prepare("INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
        VALUES (:u,:m,:f,:c,'PAGADO')")->execute([
          ':u'=>$idp, ':m'=>$monto, ':f'=>$fecha, ':c'=>'SUELDO: '.$concepto
        ]);

      // (opcional) marcar flag en pago_personal si tu schema lo tiene
      if ($conexion->prepare("SELECT 1 FROM information_schema.columns WHERE table_name='pago_personal' AND column_name='registrado_en_pago'")->execute()){
        @$conexion->prepare("UPDATE pago_personal SET registrado_en_pago=TRUE WHERE id_pago_personal=:id")->execute([':id'=>$id_pp]);
      }

      $conexion->commit();
      $flash = 'Pago registrado correctamente.';
    }catch(Exception $e){
      if ($conexion->inTransaction()) $conexion->rollBack();
      $flash = 'No se pudo registrar el pago.';
    }
  } else {
    $flash = 'Datos inválidos.';
  }
}

// Totales y tabla
$st = $conexion->prepare("
  SELECT COALESCE(SUM(pp.monto),0) AS total_pagado
  FROM pago_personal pp
  $whereSQL
");
$st->execute($params);
$total_pagado = (float)$st->fetchColumn();

$st = $conexion->prepare("
  SELECT pp.id_pago_personal, pp.fecha_pago, pp.monto, pp.concepto, pp.medio, pp.estado,
         (u.nombre||' '||u.apellido) AS personal, u.correo
  FROM pago_personal pp
  JOIN usuario u ON u.iduser=pp.id_personal
  $whereSQL
  ORDER BY pp.fecha_pago DESC, pp.id_pago_personal DESC
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ===================== Export CSV / PDF ===================== */
if (isset($_GET['export']) && $_GET['export']==='csv'){
  if (ob_get_level()) { @ob_end_clean(); while(ob_get_level()) @ob_end_clean(); }
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="pago_personal_'.date('Ymd_His').'.csv"');
  $out=fopen('php://output','w');
  fputcsv($out, ['ID','Fecha','Personal','Correo','Concepto','Monto','Medio','Estado']);
  foreach($rows as $r){
    fputcsv($out, [
      $r['id_pago_personal'], $r['fecha_pago'], $r['personal'], $r['correo'],
      $r['concepto'], number_format((float)$r['monto'],2,'.',''), $r['medio'], $r['estado']
    ]);
  }
  fclose($out); exit;
}

if (isset($_GET['export']) && $_GET['export']==='pdf'){
  // armar filas para PDF
  $headers = ['ID','Fecha','Personal','Correo','Concepto','Monto','Medio','Estado'];
  $pdfRows = [];
  foreach($rows as $r){
    $pdfRows[] = [
      (string)$r['id_pago_personal'],
      (string)$r['fecha_pago'],
      (string)$r['personal'],
      (string)$r['correo'],
      (string)$r['concepto'],
      number_format((float)$r['monto'],2,'.',''),
      (string)$r['medio'],
      (string)$r['estado'],
    ];
  }
  $pn = '';
  if ($pid>0){
    $stn = $conexion->prepare("SELECT (nombre||' '||apellido) FROM usuario WHERE iduser=:id");
    $stn->execute([':id'=>$pid]);
    $pn = (string)($stn->fetchColumn() ?? '');
  }
  $title    = "Pagos a Personal";
  $subtitle = "Rango: $from a $to".($pn ? " | Personal: $pn" : "");
  $footer   = "Total pagado: Bs ".number_format($total_pagado,2,'.','')."  | Emitido: ".date('Y-m-d H:i');

  $pdf = build_pdf_multipage($title,$subtitle,$headers,$pdfRows,$footer);
  $suffix = ($pn ? '_'.preg_replace('/\s+/', '_', strtolower($pn)) : '');
  send_pdf_bytes($pdf, "pago_personal{$suffix}_".date('Ymd_His').".pdf");
}
/* ===================== BOLETA INDIVIDUAL ===================== */
if (isset($_GET['boleta'])) {
  $idpp = (int)$_GET['boleta'];
  // Pago + datos personal
  $st = $conexion->prepare("
    SELECT pp.*, u.nombre, u.apellido, u.correo, COALESCE(u.telefono,'') AS telefono
    FROM pago_personal pp
    JOIN usuario u ON u.iduser=pp.id_personal
    WHERE pp.id_pago_personal=:id
  ");
  $st->execute([':id'=>$idpp]);
  $pp = $st->fetch(PDO::FETCH_ASSOC);

  if (!$pp) {
    if (ob_get_level()) { @ob_end_clean(); while(ob_get_level()) @ob_end_clean(); }
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Pago no encontrado.";
    exit;
  }

  // Mes/año del pago
  $st2 = $conexion->prepare("
    SELECT DATE_TRUNC('month', :f::date)::date AS ini, 
           (DATE_TRUNC('month', :f::date) + INTERVAL '1 month - 1 day')::date AS fin,
           EXTRACT(MONTH FROM :f::date)::int AS mes,
           EXTRACT(YEAR  FROM :f::date)::int AS anio
  ");
  $st2->execute([':f'=>$pp['fecha_pago']]);
  $tcal = $st2->fetch(PDO::FETCH_ASSOC);

  // Resumen mensual para ese personal
  $sumPag = $conexion->prepare("SELECT COALESCE(SUM(monto),0) FROM pago_personal WHERE id_personal=:p AND estado='PAGADO' AND fecha_pago BETWEEN :i AND :f");
  $sumDes = $conexion->prepare("SELECT COALESCE(SUM(monto),0) FROM pago_personal WHERE id_personal=:p AND estado='DESCUENTO' AND fecha_pago BETWEEN :i AND :f");
  $sumPag->execute([':p'=>$pp['id_personal'], ':i'=>$tcal['ini'], ':f'=>$tcal['fin']]);
  $sumDes->execute([':p'=>$pp['id_personal'], ':i'=>$tcal['ini'], ':f'=>$tcal['fin']]);
  $pagos_mes = (float)$sumPag->fetchColumn();
  $descuentos_mes = (float)$sumDes->fetchColumn();
  $neto_mes = $pagos_mes - $descuentos_mes;

  $empresa = "iDomus · Administración";
  $titulo  = "Boleta de Pago";
  $emisor  = $nombre.' ('.ucfirst($rol).')';

  $folio = 'PP-'.$pp['id_pago_personal'].'-'.date('YmdHis');
  $items = [[
    'p'=>[
      'folio'=>$folio,
      'fecha'=>$pp['fecha_pago'],
      'personal'=>$pp['nombre'].' '.$pp['apellido'],
      'correo'=>$pp['correo'],
      'telefono'=>$pp['telefono'],
      'concepto'=>$pp['concepto'].' / Medio: '.$pp['medio'],
      'monto'=>$pp['monto'],
    ],
    'resumen'=>[
      'mes'=>str_pad((string)$tcal['mes'],2,'0',STR_PAD_LEFT),
      'anio'=>$tcal['anio'],
      'pagos_mes'=>$pagos_mes,
      'descuentos_mes'=>$descuentos_mes,
      'neto_mes'=>$neto_mes
    ]
  ]];

  $pdf = build_boletas_pdf($empresa,$titulo,$emisor,$items);
  $nombre_archivo = 'boleta_PP'.$pp['id_pago_personal'].'_'.date('Ymd_His').'.pdf';
  send_pdf_bytes($pdf, $nombre_archivo);
}

/* ===================== BOLETAS DEL RANGO (UN SOLO PERSONAL) ===================== */
if (isset($_GET['boletas']) && $_GET['boletas']=='1') {
  if ($pid <= 0) {
    if (ob_get_level()) { @ob_end_clean(); while(ob_get_level()) @ob_end_clean(); }
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Seleccione primero un Personal en el filtro para generar sus boletas.";
    exit;
  }

  // Traer todos los pagos del rango para ese personal
  $stB = $conexion->prepare("
    SELECT pp.*, u.nombre, u.apellido, u.correo, COALESCE(u.telefono,'') AS telefono
    FROM pago_personal pp
    JOIN usuario u ON u.iduser=pp.id_personal
    WHERE pp.id_personal=:pid AND pp.fecha_pago BETWEEN :f AND :t
    ORDER BY pp.fecha_pago ASC, pp.id_pago_personal ASC
  ");
  $stB->execute([':pid'=>$pid, ':f'=>$from, ':t'=>$to]);
  $list = $stB->fetchAll(PDO::FETCH_ASSOC);

  if (!$list) {
    if (ob_get_level()) { @ob_end_clean(); while(ob_get_level()) @ob_end_clean(); }
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No hay pagos en el rango seleccionado para este personal.";
    exit;
  }

  $empresa = "iDomus · Administración";
  $titulo  = "Boleta de Pago";
  $emisor  = $nombre.' ('.ucfirst($rol).')';

  $items = [];
  foreach($list as $pp){
    // mes/año de cada pago
    $st2 = $conexion->prepare("
      SELECT DATE_TRUNC('month', :f::date)::date AS ini, 
             (DATE_TRUNC('month', :f::date) + INTERVAL '1 month - 1 day')::date AS fin,
             EXTRACT(MONTH FROM :f::date)::int AS mes,
             EXTRACT(YEAR  FROM :f::date)::int AS anio
    ");
    $st2->execute([':f'=>$pp['fecha_pago']]);
    $tcal = $st2->fetch(PDO::FETCH_ASSOC);

    $sumPag = $conexion->prepare("SELECT COALESCE(SUM(monto),0) FROM pago_personal WHERE id_personal=:p AND estado='PAGADO' AND fecha_pago BETWEEN :i AND :f");
    $sumDes = $conexion->prepare("SELECT COALESCE(SUM(monto),0) FROM pago_personal WHERE id_personal=:p AND estado='DESCUENTO' AND fecha_pago BETWEEN :i AND :f");
    $sumPag->execute([':p'=>$pp['id_personal'], ':i'=>$tcal['ini'], ':f'=>$tcal['fin']]);
    $sumDes->execute([':p'=>$pp['id_personal'], ':i'=>$tcal['ini'], ':f'=>$tcal['fin']]);
    $pagos_mes = (float)$sumPag->fetchColumn();
    $descuentos_mes = (float)$sumDes->fetchColumn();
    $neto_mes = $pagos_mes - $descuentos_mes;

    $items[] = [
      'p'=>[
        'folio'=>'PP-'.$pp['id_pago_personal'].'-'.date('YmdHis'),
        'fecha'=>$pp['fecha_pago'],
        'personal'=>$pp['nombre'].' '.$pp['apellido'],
        'correo'=>$pp['correo'],
        'telefono'=>$pp['telefono'],
        'concepto'=>$pp['concepto'].' / Medio: '.$pp['medio'],
        'monto'=>$pp['monto'],
      ],
      'resumen'=>[
        'mes'=>str_pad((string)$tcal['mes'],2,'0',STR_PAD_LEFT),
        'anio'=>$tcal['anio'],
        'pagos_mes'=>$pagos_mes,
        'descuentos_mes'=>$descuentos_mes,
        'neto_mes'=>$neto_mes
      ]
    ];
  }

  $pdf = build_boletas_pdf($empresa,$titulo,$emisor,$items);

  $stn = $conexion->prepare("SELECT (nombre||' '||apellido) FROM usuario WHERE iduser=:id");
  $stn->execute([':id'=>$pid]);
  $pn = (string)($stn->fetchColumn() ?? 'personal');
  $suffix = '_'.preg_replace('/\s+/', '_', strtolower($pn));
  send_pdf_bytes($pdf, 'boletas'.$suffix.'_'.date('Ymd_His').'.pdf');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Pagos a Personal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root{
      --primary:#0F3557;
      --secondary:#1BAAA6;
      --tertiary:#4D4D4D;
      --text:#333333;
    }
    body{ color:var(--text); }
    .navbar{ background:var(--primary); }
    .btn-domus{ background:var(--primary); color:#fff; border:none; }
    .btn-domus:hover{ background:var(--secondary); }
    .badge-chip{ background:#e9f7f6; color:var(--primary); border-radius:18px; padding:.25rem .55rem; font-weight:600; }
    .kpi{ border:none; border-radius:14px; box-shadow:0 6px 22px rgba(0,0,0,.08); }
    .table thead th{ background:var(--secondary); color:#fff; border:none; }
  </style>
</head>
<body>
<nav class="navbar navbar-dark sticky-top shadow-sm">
  <div class="container-fluid">
    <a href="finanzas.php" class="btn btn-outline-light me-2"><i class="bi bi-arrow-left"></i></a>
    <span class="navbar-brand">Pagos a Personal</span>
    <span class="badge-chip d-none d-sm-inline"><?=h($nombre)?> · <?=h(ucfirst($rol))?></span>
  </div>
</nav>

<div class="container-xxl my-3">
  <?php if (!empty($flash)): ?>
    <div class="alert alert-info py-2"><?=h($flash)?></div>
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
      <div class="col-12 col-md-4">
        <label class="form-label small">Personal</label>
        <select name="id_personal" class="form-select">
          <option value="0">Todos</option>
          <?php foreach($personal as $p): ?>
            <option value="<?=$p['iduser']?>" <?= $pid===(int)$p['iduser']?'selected':'' ?>>
              <?= h($p['apellido'].', '.$p['nombre'].' · '.$p['correo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-domus"><i class="bi bi-funnel"></i> Filtrar</button>
        <a class="btn btn-outline-secondary" href="pagoApersonal.php"><i class="bi bi-eraser"></i> Limpiar</a>
        <a class="btn btn-outline-success ms-auto" href="?from=<?=h($from)?>&to=<?=h($to)?>&id_personal=<?=$pid?>&export=csv">
          <i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV
        </a>
        <a class="btn btn-outline-danger" href="?from=<?=h($from)?>&to=<?=h($to)?>&id_personal=<?=$pid?>&export=pdf">
          <i class="bi bi-filetype-pdf"></i> Exportar PDF
        </a>
        <a class="btn btn-outline-primary" href="?from=<?=h($from)?>&to=<?=h($to)?>&id_personal=<?=$pid?>&boletas=1">
          <i class="bi bi-printer"></i> Boletas
        </a>
      </div>
    </form>
  </div>

  <!-- KPI -->
  <div class="row g-3">
    <div class="col-12 col-md-4">
      <div class="card p-3 kpi">
        <div class="text-muted small">Total Pagado</div>
        <div class="fs-4 fw-bold" style="color:var(--primary);">Bs <?=number_format($total_pagado,2)?></div>
      </div>
    </div>
  </div>

  <!-- Alta pago -->
  <div class="card p-3 my-3">
    <div class="d-flex justify-content-between align-items-center">
      <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Registrar pago</h6>
    </div>
    <form class="row g-2 mt-2" method="post">
      <input type="hidden" name="action" value="nuevo_pago">
      <div class="col-12 col-md-4">
        <label class="form-label small">Personal</label>
        <select class="form-select" name="id_personal" required>
          <option value="">Seleccione…</option>
          <?php foreach($personal as $p): ?>
            <option value="<?=$p['iduser']?>"><?= h($p['apellido'].', '.$p['nombre'].' · '.$p['correo']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Fecha</label>
        <input type="date" name="fecha_pago" class="form-control" value="<?=date('Y-m-d')?>" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Monto (Bs)</label>
        <input type="number" name="monto" step="0.01" min="0.1" class="form-control" required>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small">Concepto</label>
        <input type="text" name="concepto" class="form-control" placeholder="Sueldo, Bono, Anticipo…" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Medio</label>
        <select class="form-select" name="medio">
          <option>Efectivo</option><option>Transferencia</option><option>Tarjeta</option><option>Otro</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label small">Observación</label>
        <input type="text" name="observacion" class="form-control" placeholder="Opcional">
      </div>
      <div class="col-12">
        <button class="btn btn-domus"><i class="bi bi-save"></i> Guardar</button>
      </div>
    </form>
  </div>

  <!-- Tabla -->
<div class="card p-3 my-3">
  <h6>Pagos registrados</h6>
  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
      <thead>
        <tr>
          <th>#</th><th>Fecha</th><th>Personal</th><th>Correo</th>
          <th>Concepto</th><th class="text-end">Monto (Bs)</th><th>Medio</th><th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted">Sin registros.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id_pago_personal'] ?></td>
          <td><?= h($r['fecha_pago']) ?></td>
          <td><?= h($r['personal']) ?></td>
          <td><?= h($r['correo']) ?></td>
          <td><?= h($r['concepto']) ?></td>
          <td class="text-end"><?= number_format((float)$r['monto'],2) ?></td>
          <td><?= h($r['medio']) ?></td>
          <td><?= h($r['estado']) ?></td>

          <!-- Botón de Boleta -->
          <td>
            <a class="btn btn-sm btn-outline-primary"
               href="?boleta=<?= (int)$r['id_pago_personal'] ?>&from=<?=h($from)?>&to=<?=h($to)?>">
              <i class="bi bi-printer"></i> Boleta
            </a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>