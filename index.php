<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>iDomus</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    body {
      min-height: 100vh;
      margin: 0;
      padding: 0;
      background: #fff;
      font-family: 'Segoe UI', Arial, sans-serif;
    }
    .topbar {
      width: 100vw;
      min-height: 60px;
      background: #0F3557;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 18px 0 10px;
      box-shadow: 0 2px 12px rgba(15,53,87,0.10);
      position: sticky;
      top: 0;
      z-index: 1020;
    }
    .topbar-logo {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: #fff;
      object-fit: cover;
      box-shadow: 0 2px 8px #1BAAA6;
      border: 2px solid #1BAAA6;
      margin-right: 12px;
      padding: 3px;
      display: block;
    }
    .topbar-title {
      color: #1BAAA6;
      font-size: 2rem;
      font-weight: 700;
      letter-spacing: 1px;
      margin: 0;
      font-family: 'Segoe UI', Arial, sans-serif;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .navbar {
      background: #0F3557 !important;
      border-radius: 0 0 18px 18px;
      box-shadow: 0 2px 12px rgba(15,53,87,0.10);
      margin-bottom: 0;
    }
    .navbar-nav .nav-link {
      color: #fff !important;
      font-weight: 600;
      font-size: 1.1rem;
      margin-right: 12px;
    }
    .navbar-nav .nav-link.active {
      color: #1BAAA6 !important;
    }
    .btn-domus {
      background-color: #0F3557;
      color: #fff;
      border-radius: 8px;
      font-weight: 600;
      border: none;
      transition: background 0.2s, color 0.2s;
      margin-left: 8px;
    }
    .btn-domus-accent {
      background-color: #1BAAA6;
      color: #fff;
      border-radius: 8px;
      font-weight: 600;
      border: none;
      margin-left: 8px;
    }
    .fab-container {
      position: fixed;
      right: 2.5vw;
      bottom: 2.5vw;
      z-index: 1050;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      pointer-events: none;
    }
    .fab-main, .fab-actions, .fab-action {
      pointer-events: auto;
    }
    .fab-main {
      background: linear-gradient(135deg, #1BAAA6 60%, #0F3557 100%);
      color: #fff;
      border: none;
      border-radius: 50%;
      width: 62px;
      height: 62px;
      box-shadow: 0 4px 16px #1BAAA6, 0 0 12px #0F3557 inset;
      font-size: 2.2rem;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: box-shadow 0.2s, background 0.2s;
      margin-bottom: 10px;
      animation: fab-pop 0.7s cubic-bezier(.68,-0.55,.27,1.55);
    }
    .fab-main:active {
      box-shadow: 0 2px 8px #1BAAA6, 0 0 8px #0F3557 inset;
    }
    .fab-actions {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-bottom: 8px;
      animation: fab-pop 0.7s cubic-bezier(.68,-0.55,.27,1.55);
    }
    .fab-action {
      background: #fff;
      color: #1BAAA6;
      border: none;
      border-radius: 50%;
      width: 48px;
      height: 48px;
      box-shadow: 0 2px 8px #1BAAA6, 0 0 8px #0F3557 inset;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: box-shadow 0.2s, background 0.2s, color 0.2s;
    }
    .fab-action:active {
      background: #1BAAA6;
      color: #fff;
    }
    @keyframes fab-pop {
      0% { transform: scale(0.7); opacity: 0.2; }
      80% { transform: scale(1.1); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }
    @media (max-width: 991.98px) {
      .navbar-collapse {
        background: #0F3557;
        border-radius: 0 0 18px 18px;
        box-shadow: 0 2px 12px rgba(15,53,87,0.10);
      }
      .navbar-nav .nav-link {
        margin-right: 0;
        margin-bottom: 8px;
      }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="d-flex align-items-center">
      <img src="public/img/iDomus_logo.png" alt="iDomus Logo" class="topbar-logo">
      <span class="topbar-title">iDomus</span>
      <nav class="navbar navbar-expand-lg navbar-dark px-2 sticky-top">
    <div class="container-fluid p-0 flex-column align-items-stretch">
      <div class="collapse navbar-collapse" id="navbarDomus">
        <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-2">
          <li class="nav-item"><a class="nav-link active" href="#">Inicio</a></li>
          <li class="nav-item"><a class="nav-link" href="#">Reservas</a></li>
          <li class="nav-item"><a class="nav-link" href="#">Alquiler</a></li>
          <li class="nav-item"><a class="nav-link" href="#">Acerca de</a></li>
        </ul>
        <div class="d-flex gap-2 ms-lg-3">
          <a class="btn btn-domus" href="app/views/login.php">Login</a>
          <a class="btn btn-domus-accent" href="app/views/signup.php">Signup</a>
        </div>
      </div>
    </div>
  </nav>
    </div>
    <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarDomus" aria-controls="navbarDomus" aria-expanded="false" aria-label="Toggle navigation" style="border:none; background:transparent; color:#fff; font-size:2rem; margin-left:10px; box-shadow:none; padding:6px 10px; display:flex; align-items:center; justify-content:center;">
      <span class="navbar-toggler-icon" style="background-image:url('data:image/svg+xml;utf8,<svg viewBox=\'0 0 32 32\' xmlns=\'http://www.w3.org/2000/svg\'><rect y=\'6\' width=\'32\' height=\'4\' rx=\'2\' fill=\'%230F3557\'/><rect y=\'14\' width=\'32\' height=\'4\' rx=\'2\' fill=\'%230F3557\'/><rect y=\'22\' width=\'32\' height=\'4\' rx=\'2\' fill=\'%230F3557\'/></svg>'); width:1.5em; height:1.5em;"></span>
    </button>
  </div>
  <!-- Floating Action Button -->
  <div class="fab-container">
    <div class="fab-actions" id="fabActions" style="display:none;">
      <button class="fab-action" title="Soporte"><i class="bi bi-question-circle"></i></button>
      <button class="fab-action" title="Contacto"><i class="bi bi-envelope"></i></button>
      <button class="fab-action" title="Ayuda"><i class="bi bi-info-circle"></i></button>
    </div>
    <button class="fab-main" id="fabMain"><i class="bi bi-three-dots"></i></button>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const fabMain = document.getElementById('fabMain');
    const fabActions = document.getElementById('fabActions');
    let fabOpen = false;
    fabMain.addEventListener('click', function(e) {
      e.stopPropagation();
      fabOpen = !fabOpen;
      fabActions.style.display = fabOpen ? 'flex' : 'none';
    });
    document.addEventListener('click', function(e) {
      if (!fabMain.contains(e.target) && !fabActions.contains(e.target)) {
        fabActions.style.display = 'none';
        fabOpen = false;
      }
    });
  </script>
</body>
</html>
  </style>
  <style>
    /* Responsive: Hamburger only on mobile */
    .navbar-toggler.d-lg-none { display: flex !important; }
    @media (min-width: 992px) {
      .navbar-toggler.d-lg-none { display: none !important; }
    }
    /* FAB always bottom right, modern style */
    .fab-container {
      position: fixed;
      right: 2.5vw;
      bottom: 2.5vw;
      z-index: 999;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      pointer-events: none;
    }
    .fab-main {
      background: linear-gradient(135deg, #1BAAA6 60%, #0F3557 100%);
      color: #fff;
      border: none;
      border-radius: 50%;
      width: 62px;
      height: 62px;
      box-shadow: 0 4px 16px #1BAAA6, 0 0 12px #0F3557 inset;
      font-size: 2.2rem;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: box-shadow 0.2s, background 0.2s;
      margin-bottom: 10px;
      pointer-events: auto;
      animation: fab-pop 0.7s cubic-bezier(.68,-0.55,.27,1.55);
    }
    .fab-main:active {
      box-shadow: 0 2px 8px #1BAAA6, 0 0 8px #0F3557 inset;
    }
    .fab-actions {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-bottom: 8px;
      pointer-events: auto;
      animation: fab-pop 0.7s cubic-bezier(.68,-0.55,.27,1.55);
    }
    .fab-action {
      background: #fff;
      color: #1BAAA6;
      border: none;
      border-radius: 50%;
      width: 48px;
      height: 48px;
      box-shadow: 0 2px 8px #1BAAA6, 0 0 8px #0F3557 inset;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: box-shadow 0.2s, background 0.2s, color 0.2s;
      pointer-events: auto;
    }
    .fab-action:active {
      background: #1BAAA6;
      color: #fff;
    }
    @keyframes fab-pop {
      0% { transform: scale(0.7); opacity: 0.2; }
      80% { transform: scale(1.1); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }
  </style>
</head>
<body>
  
  <div class="futuristic-container">
    <!-- Hero Section -->
    <section class="section-hero rectangle-section mb-5">
  <div class="container-xxl d-flex flex-column flex-lg-row align-items-center justify-content-between py-5">
        <div class="hero-content text-center text-lg-start mb-4 mb-lg-0">
          <h1 class="display-5 fw-bold mb-3">Descubre tus horizontes</h1>
          <p class="lead mb-4">Texto de ejemplo para el hero. Aquí puedes poner un slogan o llamada a la acción.</p>
          <div class="d-flex gap-3 justify-content-center justify-content-lg-start">
            <a href="#" class="btn btn-domus-accent px-4">App Store</a>
            <a href="#" class="btn btn-domus px-4">Google Play</a>
          </div>
        </div>
        <div class="hero-img text-center">
          <img src="public/img/iot.webp" alt="Smart City" style="max-width:320px; width:100%; border-radius:18px; box-shadow:0 4px 24px #1BAAA6, 0 0 24px #0F3557 inset;">
        </div>
      </div>
    </section>
    <!-- Reinventando Section -->
  <section class="rectangle-section rectangle-alt py-5">
      <div class="container-xxl">
        <h2 class="fw-bold mb-3">Reinventando la tecnología móvil</h2>
        <p class="mb-0">Texto de ejemplo para la sección de reinvención. Puedes personalizarlo más adelante.</p>
      </div>
    </section>
    <!-- Funciones Section -->
  <section class="rectangle-section py-5 rectangle-accent text-white">
      <div class="container-xxl">
        <h2 class="fw-bold mb-3">Funciones</h2>
        <p class="mb-0">Aquí puedes listar las funciones principales de tu sistema.</p>
      </div>
    </section>
    <!-- Cómo funciona Section -->
  <section class="rectangle-section rectangle-alt py-5">
      <div class="container-xxl">
        <h2 class="fw-bold mb-3">Cómo funciona</h2>
        <p class="mb-0">Explica brevemente cómo funciona tu sistema o app.</p>
      </div>
    </section>
    <!-- Nosotros Section -->
  <section class="rectangle-section py-5 rectangle-accent text-white">
      <div class="container-xxl">
        <h2 class="fw-bold mb-3">Nosotros</h2>
        <p class="mb-0">Presenta a tu equipo o la historia de tu empresa aquí.</p>
      </div>
    </section>
    <!-- Clientes Section -->
  <section class="rectangle-section rectangle-alt py-5">
      <div class="container-xxl">
        <h2 class="fw-bold mb-3">Clientes</h2>
        <p class="mb-0">Muestra testimonios o logos de clientes aquí.</p>
      </div>
    </section>
  </div>
    
  <!-- Floating Action Button -->
  <div class="fab-container">
    <div class="fab-actions" id="fabActions" style="display:none;">
      <button class="fab-action" title="Soporte" onclick="alert('Soporte')"><i class="bi bi-question-circle"></i></button>
      <button class="fab-action" title="Contacto" onclick="alert('Contacto')"><i class="bi bi-envelope"></i></button>
      <button class="fab-action" title="Ayuda" onclick="alert('Ayuda')"><i class="bi bi-info-circle"></i></button>
    </div>
    <button class="fab-main" id="fabMain"><i class="bi bi-three-dots"></i></button>
  </div>
  <!-- Bootstrap 5 JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <script>
    const fabMain = document.getElementById('fabMain');
    const fabActions = document.getElementById('fabActions');
    let fabOpen = false;
    fabMain.addEventListener('click', function(e) {
      e.stopPropagation();
      fabOpen = !fabOpen;
      fabActions.style.display = fabOpen ? 'flex' : 'none';
    });
    document.addEventListener('click', function(e) {
      if (!fabMain.contains(e.target) && !fabActions.contains(e.target)) {
        fabActions.style.display = 'none';
        fabOpen = false;
      }
    });
  </script>
</body>
</html>