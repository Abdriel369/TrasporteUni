// menu.js - lógica del menú principal

document.addEventListener('DOMContentLoaded', () => {
    const user = requireAuth('login.html');
    if (!user) return;

    // Los administradores no usan este menú, tienen su propio panel
    if (user.rol === 'Administrador') {
        window.location.href = 'admin.html';
        return;
    }

    const btnPublishRoute = document.getElementById('btn-publish-route');
    const btnConductorTrips = document.getElementById('btn-conductor-trips');
    const tileMiViaje = document.getElementById('tile-mi-viaje');
    const tileCalificar = document.getElementById('tile-calificar');

    // "Publicar Ruta" y "Mis Viajes Activos" son exclusivos de Conductor:
    // para Pasajero ni siquiera deben ser visibles en el menú.
    if (user.rol !== 'Conductor') {
        if (btnPublishRoute) btnPublishRoute.style.display = 'none';
        if (btnConductorTrips) btnConductorTrips.style.display = 'none';
    }

    // "Mi Viaje Actual" y "Evaluación de viaje" son exclusivos de Pasajero:
    // solo el pasajero puede calificar al conductor.
    if (user.rol !== 'Pasajero') {
        if (tileMiViaje) tileMiViaje.style.display = 'none';
        if (tileCalificar) tileCalificar.style.display = 'none';
    }

    if (btnPublishRoute) {
        btnPublishRoute.addEventListener('click', (e) => {
            e.preventDefault();
            window.location.href = 'publicar-ruta.html';
        });
    }

    if (btnConductorTrips) {
        btnConductorTrips.addEventListener('click', (e) => {
            e.preventDefault();
            window.location.href = 'mis-viajes.html';
        });
    }
});
