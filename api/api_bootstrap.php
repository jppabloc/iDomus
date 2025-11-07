<?php
// app/api/api_bootstrap.php
declare(strict_types=1);

// ---- CORS + JSON ----
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *'); // en prod: tu dominio
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../app/models/conexion.php'; // ajusta si tu ruta es otra

// Modo excepción en PDO (si no está)
try { $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Throwable $e) {}

// Helpers
function out(bool $ok, string $msg = '', array $extra = []) : void {
  echo json_encode(array_merge(['success'=>$ok, 'message'=>$msg], $extra));
  exit;
}

/** Lee JSON del body si viene con content-type application/json */
function read_json(): array {
  $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ctype, 'application/json') === false) return [];
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') return [];
  $js = json_decode($raw, true);
  return is_array($js) ? $js : [];
}