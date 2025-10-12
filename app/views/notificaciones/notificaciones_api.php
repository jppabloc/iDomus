<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=UTF-8');
require_once '../../models/conexion.php';

if (empty($_SESSION['iduser'])) {
  echo json_encode(['success'=>false,'message'=>'No autorizado']); exit;
}
$uid = (int)$_SESSION['iduser'];
$action = $_REQUEST['action'] ?? '';

try {
  switch ($action) {

    case 'count':
      $st = $conexion->prepare("SELECT COUNT(*) FROM notificacion WHERE id_usuario=:u AND leido='N'");
      $st->execute([':u'=>$uid]);
      echo json_encode(['success'=>true,'count'=>(int)$st->fetchColumn()]);
      break;

    case 'list':
      $st = $conexion->prepare("
        SELECT id_notificacion, mensaje, fecha_envio, icono, url, leido
        FROM notificacion
        WHERE id_usuario=:u
        ORDER BY fecha_envio DESC
        LIMIT 20
      ");
      $st->execute([':u'=>$uid]);
      echo json_encode(['success'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
      break;

    case 'read_one':
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) { echo json_encode(['success'=>false,'message'=>'ID inválido']); break; }
      $st = $conexion->prepare("UPDATE notificacion SET leido='S' WHERE id_notificacion=:id AND id_usuario=:u");
      $st->execute([':id'=>$id, ':u'=>$uid]);
      echo json_encode(['success'=>true]);
      break;

    case 'read_all':
      $st = $conexion->prepare("UPDATE notificacion SET leido='S' WHERE id_usuario=:u AND leido='N'");
      $st->execute([':u'=>$uid]);
      echo json_encode(['success'=>true]);
      break;

    default:
      echo json_encode(['success'=>false,'message'=>'Acción no válida']);
  }

} catch (Throwable $e) {
  echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
}