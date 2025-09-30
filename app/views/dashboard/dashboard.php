<?php
include_once '../../models/conexion.php';
// Obtener todos los usuarios, incluyendo el campo verificado
$stmt = $conexion->query('SELECT iduser, nombre, correo, verificado FROM usuario ORDER BY iduser ASC');
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>iDomus - Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {
      background: #f7fafd;
      font-family: 'Segoe UI', Arial, sans-serif;
    }
    .dashboard-container {
      max-width: 900px;
      margin: 40px auto;
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 4px 24px rgba(51,51,51,0.13);
      padding: 32px 24px;
    }
    .dashboard-title {
      font-size: 2.2rem;
      color: #0F3557;
      font-weight: 700;
      margin-bottom: 24px;
      text-align: center;
    }
    .table thead th {
      background: #1BAAA6;
      color: #fff;
      font-weight: 600;
      border: none;
    }
    .table tbody tr {
      vertical-align: middle;
    }
    .btn-crud {
      background: #0F3557;
      color: #fff;
      border-radius: 8px;
      font-weight: 600;
      border: none;
      margin-right: 6px;
      transition: background 0.2s;
    }
    .btn-crud:hover {
      background: #1BAAA6;
      color: #fff;
    }
    .form-control:focus {
      border-color: #1BAAA6;
      box-shadow: 0 0 0 0.2rem rgba(27,170,166,.25);
    }
    .icon-verified {
      color: #0d6efd; /* Azul */
      font-size: 1.2rem;
    }
    .icon-unverified {
      color: #dc3545; /* Rojo */
      font-size: 1.2rem;
    }
    .navbar {
      background-color: #023047 !important;
    }
  </style>
</head>
<body>
  <!-- Navbar con botón hamburguesa -->
  <nav class="navbar navbar-dark fixed-top shadow">
    <div class="container-fluid">
      <button class="btn btn-outline-light me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral">
        <i class="bi bi-list"></i>
      </button>
      <a class="navbar-brand fw-bold" href="#">iDomus</a>
    </div>
  </nav>

  <!-- Menú lateral -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="menuLateral">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title">Menú</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
      <ul class="list-unstyled">
        <li><a href="#seccion-dashboard" class="btn btn-outline-primary w-100 mb-2">panel administrativo</a></li>
        <li><a href="#seccion-finanzas" class="btn btn-outline-primary w-100 mb-2">Finanzas</a></li>
        <li><a href="#seccion-consumos" class="btn btn-outline-primary w-100 mb-2">Consumos</a></li>
        <li><a href="#seccion-amorosidad" class="btn btn-outline-primary w-100">Amorosidad</a></li>
      </ul>
    </div>
  </div>

  <!-- Contenido -->
  <div class="container" style="margin-top:100px;">
    <!-- Sección Dashboard -->
    <div id="seccion-dashboard" class="dashboard-container">
      <h5>Dashboard</h5>
      <div class="dashboard-title">Panel de Administración</div>
      <div class="table-responsive">
        <table class="table table-bordered align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nombre</th>
              <th>Correo</th>
              <th>Verificado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($usuarios as $usuario): ?>
              <tr>
                <td><?= htmlspecialchars($usuario['iduser']) ?></td>
                <td><?= htmlspecialchars($usuario['nombre']) ?></td>
                <td><?= htmlspecialchars($usuario['correo']) ?></td>
                <td class="text-center">
                  <?php if ($usuario['verificado'] == 1): ?>
                    <i class="bi bi-patch-check-fill icon-verified" title="Verificado"></i>
                  <?php else: ?>
                    <i class="bi bi-x-circle-fill icon-unverified" title="No verificado"></i>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="POST" action="editar_usuario.php" style="display:inline-block">
                    <input type="hidden" name="iduser" value="<?= $usuario['iduser'] ?>">
                    <button type="submit" class="btn btn-crud btn-sm">Editar</button>
                  </form>
                  <form method="POST" action="eliminar_usuario.php" style="display:inline-block">
                    <input type="hidden" name="iduser" value="<?= $usuario['iduser'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar este usuario?')">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Sección Finanzas -->
    <div id="seccion-finanzas" class="dashboard-container">
      <h5>Finanzas</h5>
      <p>Sección en construcción...</p>
    </div>

    <!-- Sección Consumos -->
    <div id="seccion-consumos" class="dashboard-container">
      <h5>Consumos</h5>
      <p>Sección en construcción...</p>
    </div>

    <!-- Sección Amorosidad -->
    <div id="seccion-morosidad" class="dashboard-container">
      <h5>Amorosidad</h5>
      <p>Sección en construcción...</p>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
