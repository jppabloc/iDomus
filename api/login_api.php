<?php
// app/views/login/login_api.php
declare(strict_types=1);

// ==== Encabezados (CORS + JSON) ====
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *'); // si quieres restringir, pon tu dominio/app
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../app/models/conexion.php'; // <-- ajusta la ruta si cambia

// ==== Leer body (form-urlencoded o JSON) ====
$email = ''; $pass = '';
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($ctype, 'application/json') !== false) {
  $raw = file_get_contents('php://input');
  $js  = json_decode($raw, true);
  $email = trim($js['email'] ?? '');
  $pass  = (string)($js['password'] ?? '');
} else {
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
}

if ($email === '' || $pass === '') {
  echo json_encode(['success'=>false, 'message'=>'Faltan datos']); exit;
}

try {
  // === 1) Buscar usuario por correo ===
  $st = $conexion->prepare("
    SELECT iduser, nombre, apellido, correo, contrasena, verificado
    FROM usuario
    WHERE LOWER(correo) = LOWER(:c)
    LIMIT 1
  ");
  $st->execute([':c'=>$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  if (!$u) {
    echo json_encode(['success'=>false, 'message'=>'Usuario no encontrado']); exit;
  }

  // === 2) Verificar contraseña (hash BCRYPT) ===
  $hash = (string)($u['contrasena'] ?? '');
  if (!password_verify($pass, $hash)) {
    // Si usaste contraseñas sin hash en desarrollo (no recomendado), podrías
    // permitir temporalmente un fallback:
    // if ($pass !== $hash) { ... }  // DESACONSEJADO en producción
    echo json_encode(['success'=>false, 'message'=>'Contraseña inválida']); exit;
  }

  // === 3) (Opcional) exigir verificación de cuenta ===
  // if (!(int)$u['verificado']) {
  //   echo json_encode(['success'=>false, 'message'=>'Cuenta no verificada']); exit;
  // }

  // === 4) Rol del usuario ===
  $sr = $conexion->prepare("
    SELECT r.nombre_rol
    FROM usuario_rol ur
    JOIN rol r ON r.idrol = ur.idrol
    WHERE ur.iduser = :id
    LIMIT 1
  ");
  $sr->execute([':id'=>(int)$u['iduser']]);
  $rol = $sr->fetchColumn() ?: 'usuario';

  // === 5) Respuesta ===
  echo json_encode([
    'success' => true,
    'message' => 'OK',
    'iduser'  => (int)$u['iduser'],
    'nombre'  => trim(($u['nombre'] ?? '').' '.($u['apellido'] ?? '')),
    'correo'  => $u['correo'],
    'rol'     => $rol
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false, 'message'=>'Error del servidor: '.$e->getMessage()]);
}