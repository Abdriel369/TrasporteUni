// historial.js - historial de viajes del usuario

let currentUser = null;

async function cargarHistorialViajes() {
    const container = document.getElementById('trip-history-list');
    if (!container) return;

    container.innerHTML = `
        <div class="list-tile">
            <span class="list-tile-text">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                Cargando historial de viajes...
            </span>
        </div>
    `;

    try {
        const data = await apiCall('getTripHistory', { userEmail: currentUser.correo });

        if (data.status === "success") {
            if (data.historial && data.historial.length > 0) {
                let html = '';
                data.historial.forEach(viaje => {
                    const fecha = new Date(viaje.fecha_viaje).toLocaleDateString();
                    const tipo = viaje.tipo_usuario === 'pasajero' ? 'Como pasajero' : 'Como conductor';

                    let calificacionHtml = '';
                    if (viaje.calificacion_conductor) {
                        calificacionHtml = `<div>⭐ ${viaje.calificacion_conductor}/5${viaje.comentario_conductor ? ` - "${viaje.comentario_conductor}"` : ''}</div>`;
                    } else if (viaje.tipo_usuario === 'pasajero') {
                        calificacionHtml = `<div style="color: #888;">Pendiente de calificar</div>`;
                    }

                    html += `
                        <div class="list-tile">
                            <div class="list-tile-text">
                                <strong>${viaje.origen} → ${viaje.destino}</strong>
                                <div style="font-size: 14px; color: #666;">
                                    ${fecha} | ${viaje.horario}<br>
                                    ${tipo} | Conductor: ${viaje.nombre_conductor}<br>
                                    ${calificacionHtml}
                                </div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="list-tile">
                        <div class="list-tile-text">
                            <strong>No hay viajes en el historial</strong>
                            <div style="font-size: 14px; color: #666;">
                                Tus viajes aparecerán aquí una vez que los completes
                            </div>
                        </div>
                    </div>
                `;
            }
        } else {
            container.innerHTML = `<p class="text-danger">Error: ${data.message}</p>`;
        }
    } catch (error) {
        console.error('Error cargando historial:', error);
        container.innerHTML = `<p class="text-danger">${error.message}</p>`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireAuth('login.html');
    if (!currentUser) return;

    cargarHistorialViajes();
});
