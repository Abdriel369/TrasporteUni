// mis-viajes.js - viajes activos del conductor

let currentUser = null;

async function cargarViajesConductor() {
    const container = document.getElementById('conductor-trips-list');
    if (!container) return;

    container.innerHTML = `
        <div class="list-tile">
            <span class="list-tile-text">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                Cargando tus viajes activos...
            </span>
        </div>
    `;

    try {
        const data = await apiCall('getActiveDriverTrips', { userEmail: currentUser.correo });

        if (data.status === "success") {
            if (data.viajes && data.viajes.length > 0) {
                let html = '';
                data.viajes.forEach(viaje => {
                    html += `
                        <div class="list-tile">
                            <div class="list-tile-icon-bg">
                                <span class="material-symbols-rounded">directions_car</span>
                            </div>
                            <div class="list-tile-text">
                                <strong>${viaje.origen} → ${viaje.destino}</strong>
                                <div style="font-size: 14px; color: #666;">
                                    <strong>Pasajero:</strong> ${viaje.nombre_pasajero}<br>
                                    <strong>Fecha:</strong> ${new Date(viaje.fecha).toLocaleDateString()}<br>
                                    <strong>Hora:</strong> ${viaje.hora}<br>
                                    <strong>Costo:</strong> $${viaje.costo}
                                </div>
                            </div>
                            <button class="btn-completar-viaje" data-viaje="${viaje.id_viaje}"
                                    style="background: #4CAF50; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px;">
                                <span class="material-symbols-rounded" style="font-size: 16px; margin-right: 5px;">check_circle</span>
                                Completar
                            </button>
                        </div>
                    `;
                });
                container.innerHTML = html;

                document.querySelectorAll('.btn-completar-viaje').forEach(button => {
                    button.addEventListener('click', async (e) => {
                        e.preventDefault();
                        const idViaje = button.getAttribute('data-viaje');
                        await completarViaje(idViaje);
                    });
                });
            } else {
                container.innerHTML = `
                    <div class="list-tile">
                        <div class="list-tile-text">
                            <strong>No tienes viajes activos</strong>
                            <div style="font-size: 14px; color: #666;">
                                Los viajes que tengas programados aparecerán aquí
                            </div>
                        </div>
                    </div>
                `;
            }
        } else {
            container.innerHTML = `<p class="text-danger">Error: ${data.message}</p>`;
        }
    } catch (error) {
        console.error('Error cargando viajes del conductor:', error);
        container.innerHTML = `<p class="text-danger">${error.message}</p>`;
    }
}

async function completarViaje(idViaje) {
    const confirmacion = confirm(
        '¿Marcar este viaje como completado?\n\n' +
        'Esta acción no se puede deshacer y permitirá al pasajero calificar el viaje.'
    );

    if (!confirmacion) return;

    try {
        const result = await apiCall('completeTrip', { id_viaje: idViaje, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => {
                cargarViajesConductor();
            });
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        console.error('Error completando viaje:', error);
        showAlert('❌ Error', error.message);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireRole('Conductor', 'menu.html');
    if (!currentUser) return;

    cargarViajesConductor();
});
