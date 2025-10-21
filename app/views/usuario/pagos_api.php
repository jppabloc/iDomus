<?php
// app/views/usuario/pagos_api.php
declare(strict_types=1);
session_start();
require_once '../../models/conexion.php';

header('Content-Type: application/json; charset=utf-8');

function is_admin(): bool { return (($_SESSION['rol'] ?? '') === 'admin'); }

$action = $_REQUEST['action'] ?? '';

try {

  // ============ Crear/obtener link de pago (ADMIN) ============
  if ($action === 'crear_link') {
    if (!is_admin()) throw new Exception('Solo admin.');
    $id = (int)($_POST['id_reserva'] ?? 0);
    if ($id <= 0) throw new Exception('ID inválido');

    // Debe existir y estar APROBADA
    $st = $conexion->prepare("
      SELECT r.id_reserva, r.estado, r.estado_pago, r.token_pago, r.monto_pago,
             a.nombre_area, (u.nombre||' '||u.apellido) AS usuario
        FROM reserva r
        JOIN area_comun a ON a.id_area=r.id_area
        JOIN usuario u    ON u.iduser=r.id_usuario
       WHERE r.id_reserva=:id
       LIMIT 1
    ");
    $st->execute([':id'=>$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Reserva no encontrada.');
    if ($r['estado']!=='APROBADA') throw new Exception('La reserva no está APROBADA.');
    if ($r['estado_pago']==='PAGADO') throw new Exception('La reserva ya está pagada.');

    // Genera token si no existe
    $token = $r['token_pago'];
    if (!$token) {
      $token = bin2hex(random_bytes(16));
      $up = $conexion->prepare("UPDATE reserva SET token_pago=:t, estado_pago='PENDIENTE' WHERE id_reserva=:id");
      $up->execute([':t'=>$token, ':id'=>$id]);
    }

    // Asegura un monto por defecto
    $monto = (float)($r['monto_pago'] ?? 0);
    if ($monto <= 0) {
      $monto = 50.00;
      $conexion->prepare("UPDATE reserva SET monto_pago=:m WHERE id_reserva=:id")
               ->execute([':m'=>$monto, ':id'=>$id]);
    }

    // URL pública para pagar
    // (ajusta la ruta si tu servidor difiere)
    $url = sprintf('%s://%s%s/app/views/usuario/pagar_reserva.php?token=%s',
                   (!empty($_SERVER['HTTPS'])?'https':'http'),
                   $_SERVER['HTTP_HOST'],
                   rtrim(dirname($_SERVER['PHP_SELF']),'/\\',''),
                   $token);

    echo json_encode(['success'=>true,'url'=>$url,'monto'=>$monto]); exit;
  }

  // ============ Info por token (página de pago) ============
  if ($action === 'info') {
    $token = trim($_GET['token'] ?? '');
    if ($token==='') throw new Exception('Token requerido');

    $st = $conexion->prepare("
      SELECT r.id_reserva, r.estado, r.estado_pago, r.monto_pago,
             r.fecha_inicio, r.fecha_fin,
             a.nombre_area,
             (u.nombre||' '||u.apellido) AS usuario
        FROM reserva r
        JOIN area_comun a ON a.id_area=r.id_area
        JOIN usuario u    ON u.iduser=r.id_usuario
       WHERE r.token_pago=:t
       LIMIT 1
    ");
    $st->execute([':t'=>$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Token inválido.');
    echo json_encode(['success'=>true,'row'=>$r]); exit;
  }

  // ============ Simular pago ============
  if ($action === 'marcar_pagado') {
    $token = trim($_POST['token'] ?? '');
    if ($token==='') throw new Exception('Token requerido');

    // Obtiene reserva por token
    $st = $conexion->prepare("SELECT id_reserva, id_usuario, monto_pago, estado_pago, estado FROM reserva WHERE token_pago=:t");
    $st->execute([':t'=>$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Token inválido.');
    if ($r['estado']!=='APROBADA') throw new Exception('La reserva no está APROBADA.');
    if ($r['estado_pago']==='PAGADO') throw new Exception('Ya estaba pagada.');

    // Marca PAGO
    $conexion->beginTransaction();

    $conexion->prepare("UPDATE reserva SET estado_pago='PAGADO' WHERE token_pago=:t")
             ->execute([':t'=>$token]);

    // (Didáctico) crea un registro en pago
    $conexion->prepare("
      INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
      VALUES (:u, :m, NOW(), 'Pago reserva', 'Pagado')
    ")->execute([
      ':u'=>(int)$r['id_usuario'],
      ':m'=>(float)($r['monto_pago'] ?? 0)
    ]);

    $conexion->commit();
    echo json_encode(['success'=>true,'message'=>'Pago registrado']); exit;
  }

  throw new Exception('Acción no soportada');

} catch (Throwable $e) {
  if ($conexion->inTransaction()) $conexion->rollBack();
  echo json_encode(['success'=>false, 'message'=>'Error: '.$e->getMessage()]);
}