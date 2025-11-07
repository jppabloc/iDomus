<?php
// app/api/reservas_api.php
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';

$js = read_json();
$action   = $js['action']   ?? ($_POST['action'] ?? $_GET['action'] ?? '');
$iduser   = (int)($js['iduser'] ?? ($_POST['iduser'] ?? $_GET['iduser'] ?? 0));

if ($action === 'list') {
  if ($iduser <= 0) out(false, 'iduser requerido');
  try {
    $st = $conexion->prepare("
      SELECT r.id_reserva,
             a.nombre_area,
             e.nombre AS edificio,
             TO_CHAR(r.fecha_inicio,'YYYY-MM-DD HH24:MI') AS fecha_inicio,
             TO_CHAR(r.fecha_fin,'YYYY-MM-DD HH24:MI')     AS fecha_fin,
             UPPER(r.estado) AS estado
      FROM reserva r
      JOIN area_comun a ON a.id_area = r.id_area
      JOIN edificio e   ON e.id_edificio = a.id_edificio
      WHERE r.id_usuario = :u
      ORDER BY r.fecha_inicio DESC, r.id_reserva DESC
      LIMIT 200
    ");
    $st->execute([':u'=>$iduser]);
    out(true, 'OK', ['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  } catch (Throwable $e) {
    out(false, 'Error: '.$e->getMessage());
  }
}

if ($action === 'create') {
  if ($iduser <= 0) out(false, 'iduser requerido');
  $id_area = (int)($js['id_area'] ?? ($_POST['id_area'] ?? 0));
  $ini     = trim($js['fecha_inicio'] ?? ($_POST['fecha_inicio'] ?? '')); // "YYYY-MM-DD HH:MM"
  $fin     = trim($js['fecha_fin']    ?? ($_POST['fecha_fin']    ?? ''));
  $nota    = trim($js['nota']         ?? ($_POST['nota']         ?? ''));

  if ($id_area<=0 || $ini==='' || $fin==='') out(false,'Faltan datos');
  $tIni = strtotime($ini); $tFin = strtotime($fin);
  if (!$tIni || !$tFin || $tFin <= $tIni) out(false, 'Rango de fechas inválido');

  try {
    $conexion->beginTransaction();

    // área disponible
    $stA = $conexion->prepare("SELECT 1 FROM area_comun WHERE id_area=:a AND UPPER(estado)='DISPONIBLE' LIMIT 1");
    $stA->execute([':a'=>$id_area]);
    if (!$stA->fetchColumn()) { $conexion->rollBack(); out(false,'Área no disponible'); }

    // solapamiento
    $stC = $conexion->prepare("
      SELECT 1 FROM reserva
      WHERE id_area=:a AND UPPER(estado)<>'CANCELADA'
        AND ( (fecha_inicio, fecha_fin) OVERLAPS (TIMESTAMP :ini, TIMESTAMP :fin) )
      LIMIT 1
    ");
    $stC->execute([':a'=>$id_area, ':ini'=>$ini, ':fin'=>$fin]);
    if ($stC->fetchColumn()) { $conexion->rollBack(); out(false,'Horario no disponible'); }

    // insertar
    $stI = $conexion->prepare("
      INSERT INTO reserva (id_area, id_usuario, fecha_inicio, fecha_fin, estado, nota)
      VALUES (:a,:u,:ini,:fin,'PENDIENTE',:nota)
      RETURNING id_reserva
    ");
    $stI->execute([':a'=>$id_area, ':u'=>$iduser, ':ini'=>$ini, ':fin'=>$fin, ':nota'=>$nota ?: null]);
    $idNew = (int)$stI->fetchColumn();

    $conexion->commit();
    out(true, 'Reserva creada (pendiente).', ['id_reserva'=>$idNew]);
  } catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    out(false, 'Error: '.$e->getMessage());
  }
}

out(false, 'Acción inválida');