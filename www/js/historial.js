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
                    } else if (viaje.tipo_usuario === 'pasajero' && viaje.estado === 'completado') {
                        calificacionHtml = `<div style="color: #888;">Pendiente de calificar</div>`;
                    }

                    const estadoColores = {
                        pendiente: '#FF8A00',
                        completado: '#4CAF50',
                        cancelado: '#E53935'
                    };
                    const estadoColor = estadoColores[viaje.estado] || '#666';
                    const estadoTexto = viaje.estado ? viaje.estado.charAt(0).toUpperCase() + viaje.estado.slice(1) : 'N/A';

                    const puedeCancelar = viaje.estado === 'pendiente';

                    html += `
                        <div class="list-tile">
                            <div class="list-tile-text">
                                <strong>${viaje.origen} → ${viaje.destino}</strong>
                                <div style="font-size: 14px; color: #666;">
                                    ${fecha} | ${viaje.horario}<br>
                                    ${tipo} | Conductor: ${viaje.nombre_conductor}<br>
                                    Estado: <span style="color:${estadoColor}; font-weight:600;">${estadoTexto}</span><br>
                                    ${calificacionHtml}
                                </div>
                            </div>
                            ${puedeCancelar ? `
                                <button class="btn-cancelar-historial" data-viaje="${viaje.id_viaje}"
                                        style="background: #E53935; color: white; border: none; padding: 8px 14px; border-radius: 20px; cursor: pointer; font-size: 13px; white-space: nowrap;">
                                    Cancelar
                                </button>
                            ` : ''}
                        </div>
                    `;
                });
                container.innerHTML = html;

                document.querySelectorAll('.btn-cancelar-historial').forEach(button => {
                    button.addEventListener('click', async (e) => {
                        e.preventDefault();
                        const idViaje = button.getAttribute('data-viaje');
                        await cancelarViajeDesdeHistorial(idViaje);
                    });
                });
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

async function cancelarViajeDesdeHistorial(idViaje) {
    const confirmacion = confirm('¿Cancelar este viaje?');
    if (!confirmacion) return;

    try {
        const result = await apiCall('cancelTrip', { id_viaje: idViaje, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => {
                cargarHistorialViajes();
            });
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        console.error('Error cancelando viaje:', error);
        showAlert('❌ Error', error.message);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireAuth('login.html');
    if (!currentUser) return;

    cargarHistorialViajes();
});
