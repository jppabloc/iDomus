<?php
// app/views/usuario/reservas_api.php
declare(strict_types=1);
session_start();
require_once '../../models/conexion.php';

header('Content-Type: application/json; charset=utf-8');
// Evita que algún BOM/espacio previo rompa el JSON
if (ob_get_length()) { ob_end_clean(); }

$rsp = ['success'=>false, 'message'=>'', 'rows'=>[]];

try {
  if (empty($_SESSION['iduser'])) {
    throw new RuntimeException('Sesión expirada.');
  }
  $iduser = (int)$_SESSION['iduser'];
  $esAdmin = (strtolower($_SESSION['rol'] ?? '') === 'admin');

  // Evita que NOTICES de triggers aparezcan en la salida
  $conexion->exec("SET client_min_messages TO WARNING");

  // Helpers
  $action = strtolower($_REQUEST['action'] ?? 'list');

  $normDate = function(?string $d): ?string {
    $d = trim((string)$d);
    if ($d === '') return null;
    // dd/mm/aaaa → yyyy-mm-dd
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $d, $m)) {
      return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    // yyyy-mm-dd ok
    if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $d)) return $d;
    // datetime-local (yyyy-mm-ddThh:mm)
    if (preg_match('#^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}#', $d)) {
      return str_replace('T',' ',substr($d,0,16));
    }
    return $d; // último recurso
  };

  /* ===================== LIST ===================== */
  if ($action === 'list') {
    $id_area = (int)($_GET['id_area'] ?? 0);
    $desde   = $normDate($_GET['desde'] ?? '');
    $hasta   = $normDate($_GET['hasta'] ?? '');
    $verTodas= ($esAdmin && ($_GET['all'] ?? '0') === '1');

    $sql = "
      SELECT r.id_reserva,
             a.nombre_area,
             e.nombre AS edificio,
             r.fecha_inicio,
             r.fecha_fin,
             r.estado,
             (u.nombre||' '||u.apellido) AS solicitante
      FROM reserva r
      JOIN area_comun a ON a.id_area = r.id_area
      JOIN edificio e   ON e.id_edificio = a.id_edificio
      JOIN usuario u    ON u.iduser = r.id_usuario
      WHERE 1=1
    ";
    $p = [];

    if (!$verTodas) {
      $sql .= " AND r.id_usuario = :me";
      $p[':me'] = $iduser;
    }
    if ($id_area > 0) {
      $sql .= " AND r.id_area = :area";
      $p[':area'] = $id_area;
    }
    if ($desde) {
      // desde 00:00
      $sql .= " AND r.fecha_inicio >= :d";
      $p[':d'] = (strlen($desde)===10? $desde.' 00:00' : $desde);
    }
    if ($hasta) {
      // hasta 23:59
      $sql .= " AND r.fecha_fin <= :h";
      $p[':h'] = (strlen($hasta)===10? $hasta.' 23:59' : $hasta);
    }
    $sql .= " ORDER BY r.fecha_inicio DESC";

    $st = $conexion->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $rsp['success'] = true;
    $rsp['rows']    = $rows;
    echo json_encode($rsp);
    exit;
  }

  /* ===================== CREATE ===================== */
  if ($action === 'create') {
    $id_area  = (int)($_POST['id_area'] ?? 0);
    $inicio   = $normDate($_POST['fecha_inicio'] ?? '');
    $fin      = $normDate($_POST['fecha_fin'] ?? '');
    $nota     = trim($_POST['nota'] ?? '');

    if (!$id_area || !$inicio || !$fin) {
      throw new InvalidArgumentException('Datos incompletos.');
    }

    // Validar solapamiento (PENDIENTE o APROBADA)
    $sqlOv = "SELECT 1
              FROM reserva
              WHERE id_area = :a
                AND estado IN ('PENDIENTE','APROBADA')
                AND tstzrange(fecha_inicio, fecha_fin, '[)') &&
                    tstzrange(:i::timestamp, :f::timestamp, '[)')
              LIMIT 1";
    $stOv = $conexion->prepare($sqlOv);
    $stOv->execute([':a'=>$id_area, ':i'=>$inicio, ':f'=>$fin]);
    if ($stOv->fetchColumn()) {
      throw new RuntimeException('El rango se solapa con otra reserva.');
    }

    $sql = "INSERT INTO reserva (id_area,id_usuario,fecha_inicio,fecha_fin,estado,nota)
            VALUES (:a,:u,:i,:f,'PENDIENTE',:n)
            RETURNING id_reserva";
    $st = $conexion->prepare($sql);
    $st->execute([':a'=>$id_area, ':u'=>$iduser, ':i'=>$inicio, ':f'=>$fin, ':n'=>$nota]);
    $idNew = (int)$st->fetchColumn();

    $rsp['success'] = true;
    $rsp['message'] = "Reserva creada (#$idNew)";
    $rsp['id'] = $idNew;
    echo json_encode($rsp);
    exit;
  }

  /* ===================== APPROVE ===================== */
  if ($action === 'approve') {
    if (!$esAdmin) throw new RuntimeException('No autorizado.');
    $id = (int)($_POST['id_reserva'] ?? 0);
    if ($id <= 0) throw new InvalidArgumentException('ID inválido.');

    $conexion->prepare("
      UPDATE reserva
         SET estado='APROBADA', aprobado_por=:adm, fecha_aprobacion=NOW()
       WHERE id_reserva=:id
    ")->execute([':adm'=>$iduser, ':id'=>$id]);

    $rsp['success'] = true;
    $rsp['message'] = "Reserva #$id aprobada.";
    echo json_encode($rsp);
    exit;
  }

  /* ===================== CANCEL ===================== */
  if ($action === 'cancel') {
    $id = (int)($_POST['id_reserva'] ?? 0);
    if ($id <= 0) throw new InvalidArgumentException('ID inválido.');
    $motivo = trim($_POST['motivo'] ?? '');

    // Si es admin, deja traza; si es usuario, cancela simple
    if ($esAdmin) {
      $conexion->prepare("
        UPDATE reserva
           SET estado='CANCELADA', cancelado_por=:adm, fecha_cancelacion=NOW(), motivo_cancelacion=:mot
         WHERE id_reserva=:id
      ")->execute([':adm'=>$iduser, ':mot'=>$motivo, ':id'=>$id]);
    } else {
      $conexion->prepare("UPDATE reserva SET estado='CANCELADA' WHERE id_reserva=:id")
               ->execute([':id'=>$id]);
    }

    $rsp['success'] = true;
    $rsp['message'] = "Reserva #$id cancelada.";
    echo json_encode($rsp);
    exit;
  }

  /* ===================== EXPORT CSV ===================== */
  if ($action === 'export_csv') {
    // devolvemos CSV – no JSON
    $id_area = (int)($_GET['id_area'] ?? 0);
    $desde   = $normDate($_GET['desde'] ?? '');
    $hasta   = $normDate($_GET['hasta'] ?? '');
    $verTodas= ($esAdmin && ($_GET['all'] ?? '0') === '1');

    $sql = "
      SELECT r.id_reserva, a.nombre_area, e.nombre AS edificio,
             r.fecha_inicio, r.fecha_fin, r.estado,
             (u.nombre||' '||u.apellido) AS solicitante
        FROM reserva r
        JOIN area_comun a ON a.id_area=r.id_area
        JOIN edificio e   ON e.id_edificio=a.id_edificio
        JOIN usuario u    ON u.iduser=r.id_usuario
       WHERE 1=1
    ";
    $p = [];
    if (!$verTodas) { $sql.=" AND r.id_usuario=:me"; $p[':me']=$iduser; }
    if ($id_area>0) { $sql.=" AND r.id_area=:area"; $p[':area']=$id_area; }
    if ($desde)     { $sql.=" AND r.fecha_inicio >= :d"; $p[':d']= (strlen($desde)===10?$desde.' 00:00':$desde); }
    if ($hasta)     { $sql.=" AND r.fecha_fin   <= :h"; $p[':h']= (strlen($hasta)===10?$hasta.' 23:59':$hasta); }
    $sql.=" ORDER BY r.fecha_inicio DESC";

    $st=$conexion->prepare($sql); $st->execute($p); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reservas.csv');
    $out = fopen('php://output','w');
    fputcsv($out, array_keys($rows[0] ?? [
      'id_reserva'=>'','nombre_area'=>'','edificio'=>'','fecha_inicio'=>'',
      'fecha_fin'=>'','estado'=>'','solicitante'=>''
    ]));
    foreach($rows as $r){ fputcsv($out, $r); }
    fclose($out);
    exit;
  }

  // Acción no soportada
  throw new RuntimeException('Acción no soportada.');

} catch(Throwable $e) {
  // Devuelve JSON válido aunque haya error
  $rsp['success'] = false;
  $rsp['message'] = 'Error: '.$e->getMessage();
  echo json_encode($rsp);
}