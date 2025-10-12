<?php
// app/views/usuario/home.php
declare(strict_types=1);
session_start();
require_once '../../models/conexion.php';

// Solo usuarios logueados
if (!isset($_SESSION['iduser'])) {
  header('Location: ../login/login.php');
  exit;
}
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol    = $_SESSION['rol'] ?? 'usuario';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Inicio</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --dark:#0F3557; --acc:#1BAAA6; }
    body{font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;}
    .navbar{background:var(--dark);}
    .navbar-brand{color:#fff;font-weight:800;letter-spacing:.5px;display:flex;align-items:center;gap:.5rem;}
    .brand-logo{width:40px;height:40px;border-radius:50%;background:#fff;border:2px solid var(--acc);box-shadow:0 0 10px rgba(27,170,166,.55);}
    .nav-link{color:#fff;font-weight:600;}
    .nav-link:hover,.nav-link.active{color:var(--acc);}
    .navbar-toggler{border:none;box-shadow:none;}
    .navbar-toggler-icon{
      background-image:url("data:image/svg+xml;utf8,<svg viewBox='0 0 32 32' xmlns='http://www.w3.org/2000/svg'><rect y='6' width='32' height='4' rx='2' fill='%23ffffff'/><rect y='14' width='32' height='4' rx='2' fill='%23ffffff'/><rect y='22' width='32' height='4' rx='2' fill='%23ffffff'/></svg>");
    }
    /* Avatar por ícono */
    .user-chip{display:flex;align-items:center;gap:.6rem;color:#fff;font-weight:700;text-decoration:none;}
    .avatar-icon{
      width:36px;height:36px;border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      background:linear-gradient(135deg,var(--acc),#18a19c);
      color:#fff; box-shadow:0 0 0 2px rgba(255,255,255,.25);
      font-size:1.2rem;
    }
    .card-domus{border:0;border-radius:14px;box-shadow:0 6px 18px rgba(15,53,87,.08);}
    .btn-domus{background:var(--dark);color:#fff;border:none;}
    .btn-domus:hover{background:var(--acc);}
    
    /* Resalta el link activo en el navbar */
    .navbar .nav-link {
      border-radius: 999px;                  /* Siempre redondeado */
      padding: .35rem .9rem;                 /* Mantiene la forma ovalada */
      transition: all .25s ease-in-out;      /* Transición suave para color/fondo */
    }

    /* Enlace activo */
    .navbar .nav-link.active {
      color: #fff !important;
      background: var(--acc);
      font-weight: 800;
      box-shadow: 0 0 12px rgba(27,170,166,.8);
    }

    /* Hover: conserva forma y añade brillo */
    .navbar .nav-link:hover {
      color: #fff !important;
      background: rgba(27,170,166,.35);
      box-shadow: 0 0 10px rgba(27,170,166,.5);
    }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top">
  <div class="container-xxl">
    <a class="navbar-brand" href="../../public/index.php">
      <img src="../../../public/img/iDomus_logo.png" class="brand-logo" alt="iDomus">
      <span>iDomus</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link active" href="#">Inicio</a></li>
        <li class="nav-item"><a class="nav-link" href="#">Reservas</a></li>
        <li class="nav-item"><a class="nav-link" href="#">Alquiler</a></li>
        <li class="nav-item"><a class="nav-link" href="#">Acerca de</a></li>
        <li class="nav-item"><a class="nav-link" href="http://127.0.0.1:8000/lista">votacion</a></li>
      </ul>

      <!-- Dropdown de usuario con icono -->
      <div class="dropdown">
        <a class="user-chip dropdown-toggle" href="#" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="avatar-icon"><i class="bi bi-person-fill"></i></span>
          <span><?= htmlspecialchars($nombre) ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userMenu">
          <li class="dropdown-header small text-muted">Rol: <?= htmlspecialchars($rol) ?></li>
          <li><a class="dropdown-item" href="../login/cambiar_contrasenia.php"><i class="bi bi-key me-2"></i>Cambiar contraseña</a></li>
          <li><a class="dropdown-item" href="./perfil.php"><i class="bi bi-person-badge me-2"></i>Mi perfil</a></li>
          <li><a class="dropdown-item" href="./notificaciones.php"><i class="bi bi-bell me-2"></i>Notificaciones</a></li>
          <?php if ($rol === 'admin'): ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="../dashboard/dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li>
            <form action="../login/login.php" method="get" class="px-3 m-0">
              <input type="hidden" name="logout" value="1">
              <button class="btn btn-sm btn-outline-danger w-100" type="submit">
                <i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión
              </button>
            </form>
          </li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<main class="container-xxl my-4">
  <div class="card card-domus p-4 mb-4">
    <h1 class="h4 mb-1">Hola, <?= htmlspecialchars($nombre) ?> 👋</h1>
    <p class="text-muted mb-0">Bienvenido a tu panel de usuario. Aquí tendrás accesos rápidos a tus módulos.</p>
  </div>

  <div class="row g-4">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card card-domus h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Mi perfil</h5>
            <i class="bi bi-person-gear fs-4 text-secondary"></i>
          </div>
          <p class="text-muted mt-2 mb-3">Actualiza tu info personal y de contacto.</p>
          <a href="./perfil.php" class="btn btn-sm btn-domus">Editar perfil</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card card-domus h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Reservas</h5>
            <i class="bi bi-calendar2-check fs-4 text-secondary"></i>
          </div>
          <p class="text-muted mt-2 mb-3">Crea o revisa tus reservas.</p>
          <a href="./reservas.php" class="btn btn-sm btn-domus">Ver reservas</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card card-domus h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Pagos</h5>
            <i class="bi bi-cash-coin fs-4 text-secondary"></i>
          </div>
          <p class="text-muted mt-2 mb-3">Historial y estado de pagos.</p>
          <a href="./pagos.php" class="btn btn-sm btn-domus">Ir a pagos</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card card-domus h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Soporte</h5>
            <i class="bi bi-life-preserver fs-4 text-secondary"></i>
          </div>
          <p class="text-muted mt-2 mb-3">¿Necesitas ayuda? Crea un ticket.</p>
          <a href="./soporte.php" class="btn btn-sm btn-domus">Abrir ticket</a>
        </div>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>