// buscar-viaje.js - formulario de búsqueda de viajes + lista de todos los disponibles

let currentUser = null;

async function cargarTodosLosDisponibles() {
    const container = document.getElementById('all-available-trips');
    if (!container) return;

    container.innerHTML = `
        <div class="list-tile">
            <span class="list-tile-text">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                Cargando viajes disponibles...
            </span>
        </div>
    `;

    try {
        const data = await apiCall('searchRoutes', { origen: '', destino: '' });

        if (data.status === "ok" && data.rutas && data.rutas.length > 0) {
            container.innerHTML = '';

            data.rutas.forEach(ruta => {
                const el = document.createElement('a');
                el.className = 'list-tile';
                el.href = '#';
                el.style.cursor = 'pointer';
                el.innerHTML = `
                    <div class="list-tile-icon-bg">
                        <span class="material-symbols-rounded">directions_car</span>
                    </div>
                    <div class="list-tile-text">
                        <strong>${ruta.origen || 'N/A'} → ${ruta.destino || 'N/A'}</strong>
                        <div style="font-size: 14px; color: #666;">
                             ${ruta.horario || 'N/A'} |  ${ruta.lugares || 0} lugares
                            ${ruta.nombre_conductor ? `<br> ${ruta.nombre_conductor}` : ''}
                            ${ruta.precio && ruta.precio > 0 ? `|  $${ruta.precio}` : '|  Gratis'}
                        </div>
                    </div>
                    <span class="material-symbols-rounded list-tile-chevron">chevron_right</span>
                `;
                el.addEventListener('click', (e) => {
                    e.preventDefault();
                    reservarDesdeListado(ruta);
                });
                container.appendChild(el);
            });
        } else {
            container.innerHTML = `
                <div class="list-tile">
                    <div class="list-tile-text">
                        <strong>No hay viajes disponibles por el momento</strong>
                    </div>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error cargando viajes disponibles:', error);
        container.innerHTML = `<p class="text-danger">Error: ${error.message}</p>`;
    }
}

async function reservarDesdeListado(ruta) {
    if (ruta.lugares <= 0) {
        showAlert('No disponible', 'Esta ruta no tiene lugares disponibles.');
        return;
    }

    const confirmacion = confirm(
        `¿Confirmar reserva?\n\n` +
        `🚗 Ruta: ${ruta.origen} → ${ruta.destino}\n` +
        `🕒 Horario: ${ruta.horario}\n` +
        `🪑 Lugares disponibles: ${ruta.lugares}\n` +
        `👤 Conductor: ${ruta.nombre_conductor || ruta.conductor || 'N/A'}\n` +
        `💰 ${ruta.precio && ruta.precio > 0 ? `Precio: $${ruta.precio}` : 'Precio: Gratis'}`
    );

    if (!confirmacion) return;

    try {
        const userResult = await apiCall('getUserByEmail', { email: currentUser.correo });

        if (userResult.status !== 'success' || !userResult.id_usuario) {
            throw new Error('No se pudo obtener el ID del usuario: ' + (userResult.message || 'Usuario no encontrado'));
        }

        const result = await apiCall('reserveRoute', {
            id_ruta: ruta.id_ruta,
            id_usuario_pasajero: userResult.id_usuario
        });

        if (result.status === 'ok') {
            showAlert('✅ Éxito', 'Reserva confirmada exitosamente', () => {
                cargarTodosLosDisponibles();
            });
        } else {
            showAlert('❌ Error', result.message || 'No se pudo completar la reserva');
        }
    } catch (error) {
        console.error('Error reservando ruta:', error);
        showAlert('❌ Error', error.message);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireAuth('login.html');
    if (!currentUser) return;

    cargarTodosLosDisponibles();

    const btnSearchSubmit = document.getElementById('btn-search-submit');
    if (btnSearchSubmit) {
        btnSearchSubmit.addEventListener('click', (e) => {
            e.preventDefault();

            const origen = document.getElementById('search-origen').value.trim();
            const destino = document.getElementById('search-destino').value.trim();

            const params = new URLSearchParams();
            if (origen) params.set('origen', origen);
            if (destino) params.set('destino', destino);

            window.location.href = `resultados.html?${params.toString()}`;
        });
    }
});
