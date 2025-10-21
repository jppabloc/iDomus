<?php
// app/views/usuario/pagar_reserva.php
declare(strict_types=1);
$token = $_GET['token'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>iDomus · Pago de reserva</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --dark:#0F3557; --acc:#1BAAA6; }
    body{ background:#f5f7fb; font-family:'Segoe UI', Arial, sans-serif; }
    .card-domus{ border:0; border-radius:14px; box-shadow:0 6px 18px rgba(15,53,87,.08); }
    .btn-domus{ background:var(--dark); color:#fff; border:none; }
    .btn-domus:hover{ background:var(--acc); }
  </style>
</head>
<body class="p-3">
<div class="container" style="max-width:720px;">
  <div class="card card-domus p-3">
    <h5 class="mb-3">Pago de reserva</h5>
    <div id="info" class="mb-3 text-muted">Cargando…</div>

    <div class="row g-3 align-items-center">
      <div class="col-md-6">
        <!-- QR didáctico: apunta de nuevo a esta misma URL (puede ser a un link de pago real) -->
        <img id="qr" class="img-fluid border rounded" alt="QR" src="">
        <div class="small text-muted mt-2">Escanéalo para abrir este vínculo desde otro dispositivo.</div>
      </div>
      <div class="col-md-6">
        <div class="alert alert-info">
          <strong>Simulación:</strong><br>
          Este entorno registra el pago como <b>PAGADO</b> al presionar el botón.
        </div>
        <button class="btn btn-domus w-100" id="btnPay">Simular pago</button>
      </div>
    </div>
  </div>
</div>

<script>
const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
const API   = 'pagos_api.php';

async function loadInfo(){
  const res = await fetch(API+'?action=info&token='+encodeURIComponent(token));
  const js  = await res.json().catch(()=>({success:false,message:'JSON inválido'}));
  const inf = document.getElementById('info');
  if(!js.success){ inf.textContent = js.message || 'Error'; return; }
  const r = js.row;
  inf.innerHTML = `
    <div><b>Área:</b> ${r.nombre_area}</div>
    <div><b>Usuario:</b> ${r.usuario}</div>
    <div><b>Inicio:</b> ${r.fecha_inicio}</div>
    <div><b>Fin:</b> ${r.fecha_fin}</div>
    <div><b>Monto:</b> Bs ${Number(r.monto_pago||0).toFixed(2)}</div>
    <div><b>Estado:</b> ${r.estado} · <span class="badge ${r.estado_pago==='PAGADO'?'bg-success':'bg-warning text-dark'}">${r.estado_pago}</span></div>
  `;
}
loadInfo();

// QR a esta misma página
const url = window.location.href;
document.getElementById('qr').src = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data='+encodeURIComponent(url);

document.getElementById('btnPay').addEventListener('click', async ()=>{
  const fd = new FormData();
  fd.set('action','marcar_pagado');
  fd.set('token', token);
  const res = await fetch(API, { method:'POST', body:fd });
  const js  = await res.json().catch(()=>({success:false,message:'JSON inválido'}));
  if(js.success){
    alert('Pago registrado correctamente.');
    loadInfo();
  }else{
    alert(js.message||'Error');
  }
});
</script>
</body>
</html>