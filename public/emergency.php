<?php
require_once __DIR__ . '/../includes/db.php';

$token = filter_input(INPUT_GET, 'token', FILTER_SANITIZE_STRING);
if (!$token || strlen($token) < 10) {
    die("<p class=\"error\">Token inválido</p>");
}

$stmt = $conn->prepare("
    SELECT id, nombre, pm_nombre, pm_telefono, lat, lng, localidad
    FROM grupos
    WHERE token = ? AND activo = 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$grupo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$grupo) {
    die("<p class=\"error\">Grupo no encontrado o inactivo</p>");
}

$pmTelefonoMostrar = trim($grupo['pm_telefono'] ?? '');
$pmTelefonoLink = preg_replace('/[^0-9+]/', '', $pmTelefonoMostrar);
if ($pmTelefonoLink === '') {
  $pmTelefonoLink = null;
}

$hazards = [
    [
        'id' => 'caida',
        'titulo' => 'Caída de alturas',
        'icono' => 'icon_caida.png',
        'flyer' => 'caida.jpg'
    ],
    [
        'id' => 'temperatura',
        'titulo' => 'Golpe de calor',
        'icono' => 'icon_temperatura.png',
        'flyer' => 'temperatura.jpg'
    ],
    [
        'id' => 'incendio',
        'titulo' => 'Incendio',
        'icono' => 'icon_incendio.png',
        'flyer' => 'incendio.jpg'
    ],
    [
        'id' => 'sismo',
        'titulo' => 'Sismo',
        'icono' => 'icon_sismo.png',
        'flyer' => 'sismo2.jpg'
    ],
    [
        'id' => 'electrocucion',
        'titulo' => 'Electrocución',
        'icono' => 'icon_electrocucion.png',
        'flyer' => 'electrocucion.jpg'
    ],
    [
        'id' => 'accidente',
        'titulo' => 'Accidente vehicular',
        'icono' => 'icon_choque.png',
        'flyer' => 'choque.jpg'
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Emergencias – <?= htmlspecialchars($grupo['nombre']); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --brand-primary: #ff6b00;
      --brand-primary-dark: #e65e00;
      --brand-secondary: #ffefe2;
      --neutral-900: #1f2937;
      --neutral-600: #4b5563;
      --neutral-500: #6b7280;
      --neutral-200: #e5e7eb;
      --neutral-100: #f5f5f5;
      --white: #ffffff;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: var(--brand-primary);
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      color: var(--neutral-900);
      display: flex;
      justify-content: center;
      min-height: 100vh;
    }

    .page {
      width: 100%;
      max-width: 460px;
      min-height: 100vh;
      background: var(--white);
      box-shadow: 0 14px 40px rgba(0,0,0,0.18);
      display: flex;
      flex-direction: column;
      position: relative;
    }

    .safety-header {
      background: linear-gradient(135deg, #ff6b00 0%, #ff7a1a 100%);
      color: var(--white);
      padding: 28px 24px 32px;
      border-bottom-left-radius: 32px;
      border-bottom-right-radius: 32px;
    }

    .header-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .menu-btn {
      width: 36px;
      height: 36px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid rgba(255,255,255,0.4);
      border-radius: 10px;
      background: rgba(255,255,255,0.12);
      backdrop-filter: blur(4px);
    }

    .menu-bars {
      width: 18px;
      height: 2px;
      background: var(--white);
      position: relative;
      display: inline-block;
    }

    .menu-bars::before,
    .menu-bars::after {
      content: '';
      position: absolute;
      left: 0;
      width: 18px;
      height: 2px;
      background: var(--white);
    }

    .menu-bars::before {
      top: -6px;
    }

    .menu-bars::after {
      top: 6px;
    }

    .brand-logo {
      height: 40px;
      object-fit: contain;
    }

    .header-title {
      margin-top: 24px;
    }

    .header-title h1 {
      margin: 0;
      font-size: 24px;
      font-weight: 700;
      letter-spacing: 0.5px;
      line-height: 1.2;
    }

    .contact-section {
      margin-top: -12px;
      padding: 0 24px 28px;
    }

    .contact-stack {
      display: flex;
      flex-direction: column;
      gap: 16px;
      margin: 0 auto;
      max-width: 360px;
    }

    .contact-card {
      background: var(--white);
      border-radius: 18px;
      box-shadow: 0 12px 30px rgba(255, 107, 0, 0.18);
      padding: 20px 22px;
      display: flex;
      align-items: center;
      gap: 18px;
      border: 1px solid rgba(255, 107, 0, 0.1);
      cursor: pointer;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .contact-card.emergency {
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(255, 239, 226, 0.9));
      border: 1px solid rgba(255, 107, 0, 0.25);
      box-shadow: 0 18px 38px rgba(255, 107, 0, 0.18);
    }

    .contact-card:active {
      transform: scale(0.99);
    }

    .contact-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: rgba(255,107,0,0.12);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .contact-card.emergency .contact-icon {
      background: rgba(255,107,0,0.18);
    }

    .contact-icon img {
      width: 30px;
      height: 30px;
      object-fit: contain;
    }

    .contact-info span {
      display: block;
      font-size: 13px;
      color: var(--neutral-500);
    }

    .contact-info strong {
      font-size: 18px;
      font-weight: 700;
      color: var(--neutral-900);
    }

    .contact-card.emergency .contact-info strong {
      color: var(--brand-primary-dark);
      font-size: 20px;
    }

    .contact-note {
      font-size: 12px;
      color: var(--neutral-500);
      margin-top: 6px;
    }

    .hazards-section {
      padding: 0 24px 24px;
    }

    .hazards-section h2 {
      font-size: 18px;
      font-weight: 700;
      color: var(--neutral-900);
      margin: 0 0 16px;
    }

    .hazard-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
    }

    .hazard-card {
      border: 2px dashed rgba(255, 107, 0, 0.4);
      border-radius: 18px;
      background: rgba(255, 239, 226, 0.5);
      padding: 16px 12px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 12px;
      cursor: pointer;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .hazard-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 28px rgba(255, 107, 0, 0.16);
      border-color: rgba(255, 107, 0, 0.7);
    }

    .hazard-card img {
      width: 52px;
      height: 52px;
      object-fit: contain;
    }

    .hazard-card span {
      text-align: center;
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      color: var(--neutral-900);
    }

    .services-section {
      padding: 8px 24px 24px;
    }

    .services-section h2 {
      margin: 0 0 14px;
      font-size: 18px;
      font-weight: 700;
      color: var(--neutral-900);
    }

    .service-buttons {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    .service-btn {
      flex: 1;
      min-width: 180px;
      border: none;
      border-radius: 14px;
      padding: 12px 18px;
      background: linear-gradient(135deg, #ff6b00, #ff8533);
      color: var(--white);
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 12px 24px rgba(255, 107, 0, 0.18);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .service-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    .service-btn:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 0 18px 32px rgba(255, 107, 0, 0.2);
    }

    .services-list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      gap: 10px;
    }

    .services-list li {
      background: var(--neutral-100);
      padding: 12px 14px;
      border-radius: 16px;
      font-size: 13px;
      color: var(--neutral-600);
      border: 1px solid rgba(0,0,0,0.04);
    }

    .services-list li strong {
      color: var(--neutral-900);
    }

    .service-header {
      background: rgba(255, 107, 0, 0.08);
      border-left: 4px solid var(--brand-primary);
      font-weight: 700;
      color: var(--brand-primary-dark);
    }

    .service-link {
      color: var(--brand-primary-dark);
      text-decoration: none;
      font-weight: 600;
    }

    .service-link:hover {
      text-decoration: underline;
    }

    .flyer-modal {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.75);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      z-index: 1200;
    }

    .flyer-modal.active {
      display: flex;
    }

    .flyer-content {
      background: var(--white);
      border-radius: 24px;
      overflow: hidden;
      max-width: 90vw;
      width: 380px;
      box-shadow: 0 28px 60px rgba(0,0,0,0.35);
      position: relative;
      animation: zoomIn 0.25s ease;
    }

    .flyer-content img {
      width: 100%;
      display: block;
      height: auto;
    }

    .flyer-caption {
      padding: 12px 18px 18px;
      text-align: center;
      font-weight: 600;
      font-size: 15px;
      color: var(--neutral-900);
    }

    .flyer-close {
      position: absolute;
      top: 12px;
      right: 12px;
      background: rgba(0,0,0,0.55);
      border: none;
      color: var(--white);
      width: 36px;
      height: 36px;
      border-radius: 50%;
      font-size: 22px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    @keyframes zoomIn {
      from {
        transform: scale(0.9);
        opacity: 0;
      }
      to {
        transform: scale(1);
        opacity: 1;
      }
    }

    p.error {
      margin: 24px;
      padding: 18px;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.95);
      color: #c53030;
      text-align: center;
      font-weight: 600;
    }

    @media (max-width: 520px) {
      .page {
        max-width: 100%;
      }

      .hazard-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .service-buttons {
        flex-direction: column;
      }

      .service-btn {
        min-width: unset;
      }
    }

    @media (max-width: 360px) {
      .hazard-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main class="page">
    <header class="safety-header">
      <div class="header-top">
        
        </div>
        <img src="../recursos/logo.png" alt="ErgoSafety" class="brand-logo" loading="lazy">
      </div>
      <div class="header-title">
        <h1><?= htmlspecialchars($grupo['nombre']); ?></h1>
      </div>
    </header>

    <section class="contact-section">
      <div class="contact-stack">
        <div class="contact-card emergency" onclick="callTel('911')">
          <div class="contact-icon">
            <img src="../recursos/telefono.png" alt="Teléfono" loading="lazy">
          </div>
          <div class="contact-info">
            <span>Llamar ahora al</span>
            <strong>911</strong>
            <div class="contact-note">Atención de emergencias</div>
          </div>
        </div>

        <div class="contact-card" <?= $pmTelefonoLink ? "onclick=\"callTel('" . $pmTelefonoLink . "')\"" : '' ?>>
          <div class="contact-icon">
            <img src="../recursos/icon_jefe.png" alt="Jefe de Proyecto" loading="lazy">
          </div>
          <div class="contact-info">
            <span>Jefe de Proyecto</span>
            <strong><?= htmlspecialchars($grupo['pm_nombre']); ?></strong>
            <?php if ($pmTelefonoMostrar): ?>
              <div class="contact-note">Tel: <?= htmlspecialchars($pmTelefonoMostrar); ?></div>
            <?php else: ?>
              <div class="contact-note">Teléfono no disponible</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </section>

    <section class="hazards-section">
      <h2>Protocolos de seguridad</h2>
      <div class="hazard-grid">
        <?php foreach ($hazards as $hazard): ?>
          <button class="hazard-card" data-flyer="../recursos/<?= htmlspecialchars($hazard['flyer']); ?>" data-title="<?= htmlspecialchars($hazard['titulo']); ?>">
            <img src="../recursos/<?= htmlspecialchars($hazard['icono']); ?>" alt="<?= htmlspecialchars($hazard['titulo']); ?>" loading="lazy">
            <span><?= htmlspecialchars($hazard['titulo']); ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="services-section">
      <h2>Servicios de emergencia cercanos</h2>
      <div class="service-buttons">
        <button class="service-btn" type="button" onclick="buscarServicios('proyecto')">Usar ubicación del proyecto</button>

      </div>
      <ul id="emergency-list" class="services-list"></ul>
    </section>

  </main>

  <div class="flyer-modal" id="flyer-modal">
    <div class="flyer-content">
      <button class="flyer-close" type="button" onclick="closeFlyer()">&times;</button>
      <img src="" alt="Protocolo" id="flyer-image">
      <div class="flyer-caption" id="flyer-caption"></div>
    </div>
  </div>

  <script>
    const PROYECTO_LAT = <?= $grupo['lat'] ? $grupo['lat'] : 'null'; ?>;
    const PROYECTO_LNG = <?= $grupo['lng'] ? $grupo['lng'] : 'null'; ?>;
    const PROYECTO_LOCALIDAD = '<?= htmlspecialchars($grupo['localidad'] ?? ''); ?>';

    function callTel(number) {
      if (!number) return;
      window.location.href = 'tel:' + number;
    }

    const flyerModal = document.getElementById('flyer-modal');
    const flyerImage = document.getElementById('flyer-image');
    const flyerCaption = document.getElementById('flyer-caption');

    document.querySelectorAll('.hazard-card').forEach(card => {
      card.addEventListener('click', () => {
        flyerImage.src = card.dataset.flyer;
        flyerImage.alt = card.dataset.title;
        flyerCaption.textContent = card.dataset.title;
        flyerModal.classList.add('active');
        document.body.style.overflow = 'hidden';
      });
    });

    function closeFlyer() {
      flyerModal.classList.remove('active');
      flyerImage.src = '';
      document.body.style.overflow = '';
    }

    flyerModal.addEventListener('click', (event) => {
      if (event.target === flyerModal) {
        closeFlyer();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeFlyer();
      }
    });

    function calculateDistance(lat1, lon1, lat2, lon2) {
      const R = 6371;
      const dLat = (lat2 - lat1) * Math.PI / 180;
      const dLon = (lon2 - lon1) * Math.PI / 180;
      const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
      const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
      return R * c;
    }

    async function buscarServicios(tipo) {
      const lista = document.getElementById('emergency-list');
      const botones = document.querySelectorAll('.service-btn');
      botones.forEach(btn => btn.disabled = true);

      try {
        let lat, lon, ubicacionTipo;
        if (tipo === 'gps') {
          lista.innerHTML = '<li>🔍 Obteniendo tu ubicación...</li>';
          if (!navigator.geolocation) throw new Error('Tu dispositivo no soporta GPS.');
          const position = await new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(resolve, reject, {
              enableHighAccuracy: true,
              timeout: 12000,
              maximumAge: 300000
            });
          });
          lat = position.coords.latitude;
          lon = position.coords.longitude;
          ubicacionTipo = 'Tu ubicación actual';
        } else {
          if (!PROYECTO_LAT || !PROYECTO_LNG) throw new Error('No hay coordenadas registradas para el proyecto.');
          lat = PROYECTO_LAT;
          lon = PROYECTO_LNG;
          ubicacionTipo = PROYECTO_LOCALIDAD ? `Proyecto en ${PROYECTO_LOCALIDAD}` : 'Ubicación del proyecto';
        }

        lista.innerHTML = `<li>🔍 Buscando servicios cerca de ${ubicacionTipo}...</li>`;
        const radius = 10000;
        const query = `[out:json][timeout:25];
        (
          node[\"amenity\"=\"hospital\"](around:${radius},${lat},${lon});
          node[\"amenity\"=\"pharmacy\"](around:${radius},${lat},${lon});
          node[\"amenity\"=\"clinic\"](around:${radius},${lat},${lon});
          node[\"emergency\"=\"ambulance_station\"](around:${radius},${lat},${lon});
          node[\"amenity\"=\"fire_station\"](around:${radius},${lat},${lon});
          node[\"amenity\"=\"police\"](around:${radius},${lat},${lon});
        );
        out body;`;

        const resp = await fetch('overpass.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'query=' + encodeURIComponent(query)
        });
        if (!resp.ok) throw new Error(`Servidor (${resp.status})`);

        const text = await resp.text();
        const data = JSON.parse(text);
        if (!data.elements || data.elements.length === 0) {
          lista.innerHTML = `<li>No hay servicios en ${radius / 1000} km.</li>
            <li>Ubicación: ${ubicacionTipo}</li>
            <li>Coordenadas: ${lat.toFixed(4)}, ${lon.toFixed(4)}</li>`;
        } else {
          mostrarServicios(data.elements, lat, lon, ubicacionTipo);
        }
      } catch (err) {
        lista.innerHTML = `<li style="color:#c53030;">❌ ${err.message}</li>`;
      } finally {
        botones.forEach(btn => btn.disabled = false);
      }
    }

    function mostrarServicios(elements, lat, lon, ubicacionTipo) {
      const lista = document.getElementById('emergency-list');
      lista.innerHTML = '';
      const tipos = {
        hospital: { emoji: '🏥', nombre: 'Hospitales cercanos', items: [] },
        pharmacy: { emoji: '💊', nombre: 'Farmacias', items: [] },
        clinic: { emoji: '🏥', nombre: 'Clínicas', items: [] },
        ambulance_station: { emoji: '🚑', nombre: 'Ambulancias', items: [] },
        fire_station: { emoji: '🚒', nombre: 'Bomberos', items: [] },
        police: { emoji: '👮', nombre: 'Policía', items: [] }
      };

      elements.forEach(el => {
        const tipo = el.tags.emergency || el.tags.amenity;
        if (tipos[tipo]) {
          const dist = calculateDistance(lat, lon, el.lat, el.lon);
          tipos[tipo].items.push({
            nombre: el.tags.name || 'Sin nombre',
            lat: el.lat,
            lon: el.lon,
            distancia: dist,
            direccion: el.tags['addr:street'] || '',
            telefono: el.tags.phone || ''
          });
        }
      });

      Object.values(tipos).forEach(t => t.items.sort((a, b) => a.distancia - b.distancia));

      const info = document.createElement('li');
      info.innerHTML = `📍 Servicios cerca de: ${ubicacionTipo}`;
      lista.appendChild(info);

      let total = 0;
      Object.values(tipos).forEach(t => {
        if (t.items.length) {
          total += t.items.length;
          const header = document.createElement('li');
          header.className = 'service-header';
          header.textContent = `${t.emoji} ${t.nombre} (${t.items.length})`;
          lista.appendChild(header);

          t.items.slice(0, 3).forEach(item => {
            const li = document.createElement('li');
            li.innerHTML = `
              <strong>${item.nombre}</strong>
              <br><small>${item.distancia.toFixed(1)} km</small>
              ${item.direccion ? `<br><small>📍 ${item.direccion}</small>` : ''}
              ${item.telefono ? `<br><small>📞 ${item.telefono}</small>` : ''}
              <br><small>
                <a class="service-link" target="_blank" href="https://maps.google.com/?q=${item.lat},${item.lon}">Ver en Maps</a>
                ${item.telefono ? ` | <a class="service-link" href="tel:${item.telefono}">Llamar</a>` : ''}
              </small>`;
            lista.appendChild(li);
          });
        }
      });

      if (!total) {
        const li = document.createElement('li');
        li.textContent = 'No se encontraron servicios cercanos.';
        lista.appendChild(li);
      } else {
        const li = document.createElement('li');
        li.innerHTML = `
          <a class="service-link" target="_blank" href="https://maps.google.com/?q=hospital+near+${lat},${lon}">
            🗺️ Ver todos en Google Maps
          </a>`;
        lista.appendChild(li);
      }
    }
  </script>
</body>
</html>
