// resultados.js - resultados de búsqueda y reserva de viajes

let currentUser = null;

async function buscarRutasEnServidor(origen, destino) {
    const container = document.getElementById('search-results-list');
    if (!container) return;

    try {
        const data = await apiCall('searchRoutes', {
            origen: origen,
            destino: destino,
            userEmail: currentUser.correo
        });

        if (data.status === "ok") {
            if (data.rutas && data.rutas.length > 0) {
                mostrarResultadosBusqueda(data.rutas);
            } else {
                container.innerHTML = `
                    <div class="list-tile">
                        <div class="list-tile-text">
                            <strong>No se encontraron rutas</strong>
                            <div style="font-size: 14px; color: #666;">
                                ${origen || destino ?
                                    `Con filtros: ${origen ? `Origen: ${origen}` : ''} ${destino ? `Destino: ${destino}` : ''}` :
                                    'No hay rutas disponibles en este momento'}
                            </div>
                        </div>
                    </div>
                `;
            }
        } else {
            throw new Error(data.message || 'Error en la búsqueda');
        }

    } catch (error) {
        console.error("Error buscando rutas:", error);
        container.innerHTML = `
            <div class="list-tile">
                <span class="list-tile-text text-danger">
                    <strong>Error al buscar rutas</strong>
                    <div style="font-size: 14px;">${error.message}</div>
                </span>
            </div>
        `;
    }
}

function mostrarResultadosBusqueda(rutas) {
    const container = document.getElementById('search-results-list');
    if (!container) return;

    container.innerHTML = '';

    rutas.forEach(ruta => {
        const routeElement = document.createElement('a');
        routeElement.className = 'list-tile';
        routeElement.href = '#';
        routeElement.style.cursor = 'pointer';

        routeElement.innerHTML = `
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

        routeElement.addEventListener('click', (e) => {
            e.preventDefault();
            confirmarReserva(ruta);
        });

        container.appendChild(routeElement);
    });
}

async function confirmarReserva(ruta) {
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
                renderSearchResults();
            });
        } else {
            showAlert('❌ Error', result.message || 'No se pudo completar la reserva');
        }
    } catch (error) {
        console.error('Error reservando ruta:', error);
        showAlert('❌ Error', error.message);
    }
}

function renderSearchResults() {
    const container = document.getElementById('search-results-list');
    if (!container) return;

    const origen = getQueryParam('origen').toLowerCase().trim();
    const destino = getQueryParam('destino').toLowerCase().trim();

    container.innerHTML = `
        <div class="list-tile">
            <span class="list-tile-text">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                Buscando rutas...
            </span>
        </div>
    `;

    setTimeout(() => {
        buscarRutasEnServidor(origen, destino);
    }, 300);
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireAuth('login.html');
    if (!currentUser) return;

    renderSearchResults();
});
