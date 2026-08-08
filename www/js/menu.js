// menu.js - lógica del menú principal

document.addEventListener('DOMContentLoaded', () => {
    const user = requireAuth('login.html');
    if (!user) return;

    const btnPublishRoute = document.getElementById('btn-publish-route');
    if (btnPublishRoute) {
        btnPublishRoute.addEventListener('click', (e) => {
            e.preventDefault();
            if (user.rol !== 'Conductor') {
                showAlert('Permisos', 'Solo los conductores pueden publicar rutas.');
            } else {
                window.location.href = 'publicar-ruta.html';
            }
        });
    }

    const btnConductorTrips = document.getElementById('btn-conductor-trips');
    if (btnConductorTrips) {
        btnConductorTrips.addEventListener('click', (e) => {
            e.preventDefault();
            if (user.rol !== 'Conductor') {
                showAlert('Permisos', 'Solo los conductores pueden acceder a esta función.');
            } else {
                window.location.href = 'mis-viajes.html';
            }
        });
    }
});
