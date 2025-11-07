<?php
// app/api/login_api.php
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';

$js = read_json();
$email = trim($js['email'] ?? ($_POST['email'] ?? ''));
$pass  = (string)($js['password'] ?? ($_POST['password'] ?? ''));

if ($email === '' || $pass === '') out(false, 'Faltan datos');

try {
  // 1) usuario
  $st = $conexion->prepare("
    SELECT iduser, nombre, apellido, correo, contrasena, verificado
    FROM usuario
    WHERE LOWER(correo)=LOWER(:c)
    LIMIT 1
  ");
  $st->execute([':c'=>$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) out(false, 'Usuario no encontrado');

  // 2) password
  $hash = (string)($u['contrasena'] ?? '');
  if (!password_verify($pass, $hash)) out(false, 'Contraseña inválida');

  // 3) rol (opcional)
  $sr = $conexion->prepare("
    SELECT r.nombre_rol
    FROM usuario_rol ur
    JOIN rol r ON r.idrol = ur.idrol
    WHERE ur.iduser = :id
    LIMIT 1
  ");
  $sr->execute([':id'=>(int)$u['iduser']]);
  $rol = $sr->fetchColumn() ?: 'usuario';

  out(true, 'OK', [
    'iduser' => (int)$u['iduser'],
    'nombre' => trim(($u['nombre'] ?? '').' '.($u['apellido'] ?? '')),
    'correo' => (string)$u['correo'],
    'rol'    => (string)$rol,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  out(false, 'Error: '.$e->getMessage());
}