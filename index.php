<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>iDomus</title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <!-- Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet"/>

  <style>
    :root{
      --domus-bg:#0F3557;
      --domus-accent:#1BAAA6;
      --domus-light:#f7fafd;
      --domus-white:#ffffff;
    }

    /* ===== Base ===== */
    html,body{
      height:100%;
      background:#fff;
      font-family:'Segoe UI', Arial, sans-serif;
    }

    /* ===== Navbar/Header ===== */
    .navbar-domus{
      background:var(--domus-bg) !important;
      box-shadow:0 2px 12px rgba(15,53,87,.12);
    }
    .navbar-brand{
      display:flex; align-items:center; gap:.6rem;
      color:var(--domus-accent); font-weight:700; letter-spacing:.5px;
    }
    .navbar-brand img{
      width:42px; height:42px; border-radius:50%;
      background:#fff; object-fit:cover;
      border:2px solid var(--domus-accent);
      padding:3px; box-shadow:0 2px 8px rgba(27,170,166,.45);
    }
    .navbar-nav .nav-link{
      color:#fff!important; font-weight:600;
    }
    .navbar-nav .nav-link.active,
    .navbar-nav .nav-link:focus,
    .navbar-nav .nav-link:hover{
      color:var(--domus-accent)!important;
    }

    /* Toggler con barras blancas y borde visible */
    .navbar-toggler{
      border:2px solid var(--domus-accent) !important;
      border-radius:.6rem;
      padding:.35rem .5rem;
      box-shadow:none !important;
    }
    .navbar-toggler:focus{ outline:none; }
    .navbar-toggler-icon{
      width:1.5rem; height:1.5rem;
      background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3e%3crect y='6' width='32' height='4' rx='2' fill='%23fff'/%3e%3crect y='14' width='32' height='4' rx='2' fill='%23fff'/%3e%3crect y='22' width='32' height='4' rx='2' fill='%23fff'/%3e%3c/svg%3e") !important;
      background-size:100% 100%;
    }

    /* Collapse mobile fondo */
    @media (max-width: 991.98px){
      #navbarDomus{
        background:var(--domus-bg);
        border-radius:0 0 18px 18px;
        box-shadow:0 2px 12px rgba(15,53,87,.12);
        padding:.6rem .2rem 1rem;
      }
    }

    /* Botones acción */
    .btn-domus{
      background:var(--domus-bg); color:#fff; border:none;
      border-radius:.6rem; font-weight:600;
    }
    .btn-domus:hover{ background:var(--domus-accent); color:#0F3557; }
    .btn-domus-accent{
      background:var(--domus-accent); color:#fff; border:none;
      border-radius:.6rem; font-weight:700;
    }
    .btn-domus-accent:hover{ background:#16a09c; color:#fff; }

    /* ===== Hero & secciones demo ===== */
    .hero-wrap{
      background:linear-gradient(120deg, #181B3A 60%, var(--domus-accent) 100%);
      color:#fff; border-radius:1.2rem;
      box-shadow:0 8px 36px rgba(15,53,87,.18);
      margin:18px auto; padding:32px 22px;
      max-width:1100px;
    }
    .rect{
      max-width:1100px; margin:18px auto; padding:42px 22px;
      border-radius:1.2rem; box-shadow:0 6px 28px rgba(27,170,166,.08);
      background:#fff; color:var(--domus-bg);
    }
    .rect.rect-accent{
      background:linear-gradient(120deg, #181B3A 60%, var(--domus-accent) 105%);
      color:#fff;
      box-shadow:0 6px 28px rgba(27,170,166,.18);
    }

    /* ===== FAB ===== */
    .fab-container{
      position:fixed; right:2.2vw; bottom:2.2vw; z-index:1055;
      display:flex; flex-direction:column; align-items:flex-end;
    }
    .fab-main{
      width:64px; height:64px; border-radius:50%; border:none;
      display:flex; align-items:center; justify-content:center;
      background:linear-gradient(135deg, var(--domus-accent) 60%, var(--domus-bg) 100%);
      color:#fff; font-size:1.8rem; cursor:pointer;
      box-shadow:0 4px 16px rgba(27,170,166,.75), inset 0 0 12px rgba(15,53,87,.35);
      transition:transform .15s ease, box-shadow .15s ease;
    }
    .fab-main:active{ transform:scale(.96); }
    .fab-actions{
      display:none; flex-direction:column; gap:.6rem; margin-bottom:.6rem;
    }
    .fab-chip{
      display:flex; align-items:center; gap:.6rem;
      background:#fff; color:var(--domus-bg);
      border-radius:999px; padding:.35rem .6rem .35rem .4rem;
      box-shadow:0 2px 10px rgba(15,53,87,.18);
    }
    .fab-action{
      width:46px; height:46px; border-radius:50%; border:none;
      display:flex; align-items:center; justify-content:center;
      background:var(--domus-accent); color:#fff; font-size:1.2rem; cursor:pointer;
    }
    .fab-label{ font-weight:600; font-size:.95rem; white-space:nowrap; }

    /* ===== Util ===== */
    .img-card{
      max-width:360px; width:100%; border-radius:14px;
      box-shadow:0 4px 24px rgba(27,170,166,.35), inset 0 0 18px rgba(15,53,87,.35);
    }

    /* Espaciado global */
    .page-wrap{ padding:12px; }
  </style>
</head>
<body>

  <!-- NAVBAR -->
  <nav class="navbar navbar-expand-lg navbar-dark navbar-domus sticky-top">
    <div class="container-xl">
      <a class="navbar-brand" href="#">
        <img src="public/img/iDomus_logo.png" alt="iDomus">
        <span>iDomus</span>
      </a>

      <!-- Botón hamburguesa con barras blancas -->
      <button class="navbar-toggler" type="button"
              data-bs-toggle="collapse" data-bs-target="#navbarDomus"
              aria-controls="navbarDomus" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- Menú -->
      <div class="collapse navbar-collapse" id="navbarDomus">
        <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
          <li class="nav-item"><a class="nav-link active" href="#inicio">Inicio</a></li>
          <li class="nav-item"><a class="nav-link" href="#reservas">Reservas</a></li>
          <li class="nav-item"><a class="nav-link" href="#alquiler">Alquiler</a></li>
          <li class="nav-item"><a class="nav-link" href="#acerca">Acerca de</a></li>
        </ul>
        <div class="d-flex gap-2 ms-lg-3">
          <a class="btn btn-domus-accent" href="app/views/login/login.php">Login</a>
          <!-- <a class="btn btn-domus-accent" href="app/views/login/signup.php">Signup</a> -->
        </div>
      </div>
    </div>
  </nav>

  <main class="page-wrap">
    <!-- HERO -->
    <section id="inicio" class="hero-wrap">
      <div class="container-xl">
        <div class="row align-items-center g-4">
          <div class="col-12 col-lg-7">
            <h1 class="display-6 fw-bold mb-3">Descubre tus horizontes</h1>
            <p class="lead mb-4">Administra tu edificio con iDomus: reservas, consumos, finanzas y más, todo en un solo lugar.</p>
            <div class="d-flex gap-2 flex-wrap">
              <a href="#" class="btn btn-domus-accent px-4"><i class="bi bi-apple me-1"></i>App Store</a>
              <a href="#" class="btn btn-domus px-4"><i class="bi bi-google-play me-1"></i>Google Play</a>
            </div>
          </div>
          <div class="col-12 col-lg-5 text-center">
            <img src="public/img/iot.webp" alt="Smart City" class="img-card">
          </div>
        </div>
      </div>
    </section>

    <!-- Secciones demo -->
    <section id="reservas" class="rect">
      <h2 class="fw-bold mb-2">Reservas</h2>
      <p class="mb-0">Gestiona áreas comunes con cupos, reglas y calendario integrado.</p>
    </section>

    <section id="alquiler" class="rect rect-accent">
      <h2 class="fw-bold mb-2">Alquiler</h2>
      <p class="mb-0">Publica y administra alquileres internos con trazabilidad y pagos.</p>
    </section>

    <section id="acerca" class="rect">
      <h2 class="fw-bold mb-2">Acerca de</h2>
      <p class="mb-0">iDomus integra IoT, acceso, finanzas y comunicación para tu comunidad.</p>
    </section>
  </main>

  <!-- FAB -->
  <div class="fab-container">
    <div class="fab-actions" id="fabActions">
      <div class="fab-chip">
        <button class="fab-action" title="Contacto" id="fabContacto"><i class="bi bi-envelope"></i></button>
        <span class="fab-label">Contacto</span>
      </div>
      <div class="fab-chip">
        <button class="fab-action" title="Reservar" id="fabReservar"><i class="bi bi-calendar-check"></i></button>
        <span class="fab-label">Hacer reserva</span>
      </div>
      <div class="fab-chip">
        <button class="fab-action" title="Soporte" id="fabSoporte"><i class="bi bi-question-circle"></i></button>
        <span class="fab-label">Soporte</span>
      </div>
    </div>
    <button class="fab-main" id="fabMain" aria-expanded="false" aria-controls="fabActions" aria-label="Más acciones">
      <i class="bi bi-three-dots"></i>
    </button>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // ===== Cerrar collapse al clickear fuera o elegir enlace
    (function(){
      const nav = document.getElementById('navbarDomus');
      const bsCollapse = new bootstrap.Collapse(nav, {toggle:false});
      document.addEventListener('click', (e)=>{
        const insideToggle = e.target.closest('.navbar');
        if(!insideToggle && nav.classList.contains('show')){
          bsCollapse.hide();
        }
      });
      document.querySelectorAll('#navbarDomus .nav-link').forEach(a=>{
        a.addEventListener('click', ()=>{ if(nav.classList.contains('show')) bsCollapse.hide(); });
      });
    })();

    // ===== FAB
    (function(){
      const fabMain = document.getElementById('fabMain');
      const fabActions = document.getElementById('fabActions');
      let open = false;

      const setState = (v)=>{
        open = v;
        fabActions.style.display = open ? 'flex' : 'none';
        fabMain.setAttribute('aria-expanded', open ? 'true':'false');
      };

      fabMain.addEventListener('click', (e)=>{
        e.stopPropagation();
        setState(!open);
      });

      document.addEventListener('click', (e)=>{
        if(open && !fabActions.contains(e.target) && !fabMain.contains(e.target)){
          setState(false);
        }
      });

      document.addEventListener('keydown', (e)=>{
        if(e.key === 'Escape' && open) setState(false);
      });

      // Acciones demo (reemplaza con tus rutas)
      document.getElementById('fabContacto').addEventListener('click', ()=>{
        setState(false);
        window.location.href = 'app/views/contacto.php';
      });
      document.getElementById('fabReservar').addEventListener('click', ()=>{
        setState(false);
        window.location.href = '#reservas';
      });
      document.getElementById('fabSoporte').addEventListener('click', ()=>{
        setState(false);
        alert('Abrir chat/FAQ de soporte');
      });
    })();
  </script>
</body>
</html>