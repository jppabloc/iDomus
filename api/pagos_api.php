<?php
// /idomus/api/pagos_api.php
declare(strict_types=1);

// ===== Encabezados CORS/JSON =====
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Bootstrap común (ajusta la ruta si difiere en tu proyecto)
require_once __DIR__ . '/api_bootstrap.php'; // este incluye conexion.php y helpers mínimos

// ===== Helpers =====
function body_json(): array {
  $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $js  = json_decode($raw, true);
    return is_array($js) ? $js : [];
  }
  return [];
}

function param($k, $default=null) {
  $js = $GLOBALS['__JSON__'] ?? [];
  if (isset($_GET[$k]))  return $_GET[$k];
  if (isset($_POST[$k])) return $_POST[$k];
  if (isset($js[$k]))    return $js[$k];
  return $default;
}

// Tarifa por área (misma lógica que tu pagos.php web)
function tarifa_por_area(string $nombreArea): float {
  $n = mb_strtolower($nombreArea, 'UTF-8');
  if (mb_strpos($n,'salón') !== false || mb_strpos($n,'salon') !== false) return 80.00;
  if (mb_strpos($n,'parrill') !== false) return 50.00;
  if (mb_strpos($n,'gimnas') !== false)  return 20.00;
  if (mb_strpos($n,'parque') !== false)  return 15.00;
  if (mb_strpos($n,'jard')   !== false)  return 30.00;
  return 40.00; // default
}

// yyyy-mm
function ym_from_datetime($dtString): string {
  if (empty($dtString)) return date('Y-m');
  $ts = strtotime((string)$dtString);
  return $ts ? date('Y-m', $ts) : date('Y-m');
}

$__JSON__ = body_json();

// Normaliza id user: acepta iduser o id_usuario
$iduser = (int) (param('iduser') ?? param('id_usuario') ?? 0);
$action = trim((string) (param('action','list')));

if ($iduser <= 0) {
  echo json_encode(['success'=>false, 'message'=>'iduser requerido']); exit;
}

try {

  if ($action === 'list') {
    // --- RESERVAS APROBADAS DEL USUARIO + flag pagado + monto sugerido ---
    $sqlR = "
      SELECT r.id_reserva, r.estado, r.fecha_inicio, r.fecha_fin,
             a.nombre_area, e.nombre AS edificio
        FROM reserva r
        JOIN area_comun a ON a.id_area = r.id_area
        JOIN edificio   e ON e.id_edificio = a.id_edificio
       WHERE r.id_usuario = :u
         AND UPPER(r.estado) = 'APROBADA'
       ORDER BY r.fecha_inicio DESC";
    $stR = $conexion->prepare($sqlR);
    $stR->execute([':u'=>$iduser]);
    $reservas = $stR->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // marca pagado y calcula monto
    foreach ($reservas as &$r) {
      $concepto = 'RESERVA #'.$r['id_reserva'];
      $stP = $conexion->prepare("
          SELECT COUNT(*) FROM pago
           WHERE id_usuario = :u
             AND concepto   = :c
             AND UPPER(estado) = 'PAGADO'");
      $stP->execute([':u'=>$iduser, ':c'=>$concepto]);
      $r['pagado'] = ((int)$stP->fetchColumn()) > 0 ? true : false;
      $r['monto']  = tarifa_por_area((string)($r['nombre_area'] ?? ''));
    }
    unset($r);

    // --- CUOTAS PENDIENTES DEL USUARIO ---
    // Intento con id_cuota (si existe)
    $cuotas = [];
    $keytype = 'id';
    try {
      $sqlC = "
        SELECT cm.id_cuota::text AS cm_id, cm.monto, cm.fecha_generacion, cm.fecha_vencimiento, cm.estado,
               un.nro_unidad
          FROM cuota_mantenimiento cm
          JOIN unidad un ON un.id_unidad = cm.id_unidad
          JOIN residente_unidad ru ON ru.id_unidad = un.id_unidad AND ru.id_usuario = :u
         WHERE UPPER(cm.estado)='PENDIENTE'
         ORDER BY cm.fecha_generacion DESC";
      $stC = $conexion->prepare($sqlC);
      $stC->execute([':u'=>$iduser]);
      $cuotas = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $keytype = 'id';
    } catch (\Throwable $e) {
      // Fallback sin id_cuota -> composite key id_unidad|fecha_generacion
      $sqlC = "
        SELECT (cm.id_unidad::text || '|' || to_char(cm.fecha_generacion,'YYYY-MM-DD')) AS cm_id, 
               cm.monto, cm.fecha_generacion, cm.fecha_vencimiento, cm.estado,
               un.nro_unidad
          FROM cuota_mantenimiento cm
          JOIN unidad un ON un.id_unidad = cm.id_unidad
          JOIN residente_unidad ru ON ru.id_unidad = un.id_unidad AND ru.id_usuario = :u
         WHERE UPPER(cm.estado)='PENDIENTE'
         ORDER BY cm.fecha_generacion DESC";
      $stC = $conexion->prepare($sqlC);
      $stC->execute([':u'=>$iduser]);
      $cuotas = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $keytype = 'key';
    }

    echo json_encode([
      'success' => true,
      'message' => 'OK',
      'reservas_aprobadas' => $reservas,
      'cuotas_pendientes'  => [
        'keytype' => $keytype,
        'rows'    => $cuotas
      ]
    ]);
    exit;
  }

  if ($action === 'pay_reserva') {
    $id_reserva = (int) param('id_reserva', 0);
    $monto_in   = (float) param('monto', 0);

    if ($id_reserva <= 0) {
      echo json_encode(['success'=>false,'message'=>'id_reserva requerido']); exit;
    }

    // Verifica pertenencia + datos
    $sql = "
      SELECT r.id_reserva, r.estado, r.fecha_inicio, r.fecha_fin,
             a.nombre_area
        FROM reserva r
        JOIN area_comun a ON a.id_area = r.id_area
       WHERE r.id_reserva = :id
         AND r.id_usuario = :u
       LIMIT 1";
    $st = $conexion->prepare($sql);
    $st->execute([':id'=>$id_reserva, ':u'=>$iduser]);
    $res = $st->fetch(PDO::FETCH_ASSOC);

    if (!$res) {
      echo json_encode(['success'=>false,'message'=>'Reserva no encontrada o no pertenece al usuario']); exit;
    }

    $concepto = 'RESERVA #'.$id_reserva;

    // ¿Ya pagado?
    $stc = $conexion->prepare("
      SELECT COUNT(*) FROM pago
       WHERE id_usuario=:u AND concepto=:c AND UPPER(estado)='PAGADO'");
    $stc->execute([':u'=>$iduser, ':c'=>$concepto]);
    $ya = ((int)$stc->fetchColumn()) > 0;
    if ($ya) {
      echo json_encode(['success'=>true,'message'=>'Reserva ya pagada']); exit;
    }

    // Monto
    $monto = $monto_in > 0 ? $monto_in : tarifa_por_area((string)$res['nombre_area']);

    // Inserta pago
    $stm = $conexion->prepare("
      INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
      VALUES (:u, :m, NOW(), :c, 'PAGADO')
    ");
    $stm->execute([':u'=>$iduser, ':m'=>$monto, ':c'=>$concepto]);

    echo json_encode(['success'=>true,'message'=>'Pago de reserva registrado','id_reserva'=>$id_reserva,'monto'=>$monto]);
    exit;
  }

  if ($action === 'pay_cuota') {
    // Admite keytype=id con cm_id=id_cuota  o  keytype=key con "id_unidad|YYYY-MM-DD"
    $keytype = strtolower((string) param('cm_keytype','id'));
    $cm_id   = (string) param('cm_id','');

    if ($cm_id === '') {
      echo json_encode(['success'=>false,'message'=>'cm_id requerido']); exit;
    }

    if ($keytype === 'id') {
      $sqlC = "
        SELECT cm.id_cuota::text AS cm_id, cm.monto, cm.fecha_generacion, cm.fecha_vencimiento,
               un.nro_unidad
          FROM cuota_mantenimiento cm
          JOIN unidad un ON un.id_unidad = cm.id_unidad
          JOIN residente_unidad ru ON ru.id_unidad = un.id_unidad AND ru.id_usuario = :u
         WHERE cm.id_cuota::text = :cid
           AND UPPER(cm.estado) = 'PENDIENTE'
         LIMIT 1";
      $st = $conexion->prepare($sqlC);
      $st->execute([':u'=>$iduser, ':cid'=>$cm_id]);
    } else {
      // cm_id = "id_unidad|YYYY-MM-DD"
      [$id_unidad_s, $fg] = explode('|', $cm_id, 2) + [null,null];
      $sqlC = "
        SELECT (cm.id_unidad::text || '|' || to_char(cm.fecha_generacion,'YYYY-MM-DD')) AS cm_id,
               cm.monto, cm.fecha_generacion, cm.fecha_vencimiento,
               un.nro_unidad
          FROM cuota_mantenimiento cm
          JOIN unidad un ON un.id_unidad = cm.id_unidad
          JOIN residente_unidad ru ON ru.id_unidad = un.id_unidad AND ru.id_usuario = :u
         WHERE cm.id_unidad = :idu
           AND cm.fecha_generacion::date = :fg::date
           AND UPPER(cm.estado) = 'PENDIENTE'
         LIMIT 1";
      $st = $conexion->prepare($sqlC);
      $st->execute([':u'=>$iduser, ':idu'=>$id_unidad_s, ':fg'=>$fg]);
    }
    $c = $st->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
      echo json_encode(['success'=>false,'message'=>'Cuota no encontrada o no pendiente']); exit;
    }

    $nro_unidad = (string)($c['nro_unidad'] ?? '—');
    $ym         = ym_from_datetime($c['fecha_generacion'] ?? '');
    $concepto   = "CUOTA MANTENIMIENTO {$ym} · UNIDAD {$nro_unidad}";
    $monto      = (float)($c['monto'] ?? 0);

    // ¿ya pagada?
    $stc = $conexion->prepare("
      SELECT COUNT(*) FROM pago
       WHERE id_usuario=:u AND concepto=:c AND UPPER(estado)='PAGADO'");
    $stc->execute([':u'=>$iduser, ':c'=>$concepto]);
    $ya = ((int)$stc->fetchColumn()) > 0;
    if ($ya) {
      echo json_encode(['success'=>true,'message'=>'Cuota ya pagada']); exit;
    }

    // Inserta pago
    $conexion->beginTransaction();
    $stm = $conexion->prepare("
      INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
      VALUES (:u, :m, NOW(), :c, 'PAGADO')
    ");
    $stm->execute([':u'=>$iduser, ':m'=>$monto, ':c'=>$concepto]);

    // Actualiza estado cuota
    if ($keytype === 'id') {
      $conexion->prepare("UPDATE cuota_mantenimiento SET estado='PAGADO' WHERE id_cuota::text = :cid")
               ->execute([':cid'=>$cm_id]);
    } else {
      [$id_unidad_s, $fg] = explode('|', $cm_id, 2) + [null,null];
      $conexion->prepare("UPDATE cuota_mantenimiento SET estado='PAGADO'
                           WHERE id_unidad = :idu AND fecha_generacion::date = :fg::date")
               ->execute([':idu'=>$id_unidad_s, ':fg'=>$fg]);
    }
    $conexion->commit();

    echo json_encode(['success'=>true,'message'=>'Pago de cuota registrado','mes'=>$ym,'unidad'=>$nro_unidad,'monto'=>$monto]);
    exit;
  }

  echo json_encode(['success'=>false,'message'=>'action inválida']);

} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Error del servidor: '.$e->getMessage()]);
}