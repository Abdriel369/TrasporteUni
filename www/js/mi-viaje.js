// mi-viaje.js - viaje activo actual del pasajero

let currentUser = null;

async function cargarMiViaje() {
    const container = document.getElementById('mi-viaje-content');
    if (!container) return;

    container.innerHTML = `
        <div class="list-tile">
            <span class="list-tile-text">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                Buscando tu viaje activo...
            </span>
        </div>
    `;

    try {
        const data = await apiCall('getMyCurrentTrip', { userEmail: currentUser.correo });

        if (data.status !== 'success') {
            throw new Error(data.message || 'Error al cargar tu viaje');
        }

        if (!data.tiene_viaje) {
            container.innerHTML = `
                <div class="hero-card">
                    <div class="hero-card-icon-bg">
                        <span class="material-symbols-rounded">sentiment_calm</span>
                    </div>
                    <div class="hero-card-text">
                        <h2>No tienes un viaje activo</h2>
                        <p>Busca un viaje disponible para reservarlo.</p>
                    </div>
                </div>
                <a href="buscar-viaje.html" class="cta-button">
                    <span class="material-symbols-rounded">search</span>
                    <span>Buscar Viaje</span>
                </a>
            `;
            return;
        }

        const v = data.viaje;
        const enCurso = v.estado === 'en_curso';
        const listo = v.pasajero_listo == 1;
        const finalizado = v.pasajero_finalizado == 1;

        let estadoTexto = 'Pendiente';
        let estadoColor = '#FF8A00';
        if (enCurso && finalizado) {
            estadoTexto = 'Esperando a que los demás pasajeros finalicen';
            estadoColor = '#2979FF';
        } else if (enCurso) {
            estadoTexto = 'En curso';
            estadoColor = '#2979FF';
        } else if (listo) {
            estadoTexto = 'Esperando a los demás pasajeros y al conductor';
            estadoColor = '#FF8A00';
        }

        container.innerHTML = `
            <div class="hero-card">
                <div class="hero-card-icon-bg">
                    <span class="material-symbols-rounded">directions_car</span>
                </div>
                <div class="hero-card-text">
                    <h2>${v.origen} → ${v.destino}</h2>
                    <p>${v.fecha} | ${v.hora}</p>
                </div>
            </div>

            <div class="section-header">Estado del viaje</div>
            <div class="list-section">
                <div class="list-tile">
                    <div class="list-tile-text">
                        <strong style="color:${estadoColor};">${estadoTexto}</strong>
                    </div>
                </div>
            </div>

            <div class="section-header">Vehículo</div>
            <div class="list-section">
                <div class="list-tile">
                    <div class="list-tile-icon-bg"><span class="material-symbols-rounded">directions_car_filled</span></div>
                    <div class="list-tile-text">
                        <strong>${v.modelo}</strong>
                        <div style="font-size: 14px; color: #666;">Placas: ${v.placas}</div>
                    </div>
                </div>
            </div>

            <div class="section-header">Conductor</div>
            <div class="list-section">
                <div class="list-tile">
                    <div class="list-tile-icon-bg"><span class="material-symbols-rounded">person</span></div>
                    <div class="list-tile-text">
                        <strong>${v.nombre_conductor}</strong>
                        <div style="font-size: 14px; color: #666;">No. Control: ${v.num_control_conductor}</div>
                    </div>
                </div>
            </div>

            <div class="section-header">Costo</div>
            <div class="list-section">
                <div class="list-tile">
                    <div class="list-tile-text">$${v.costo}</div>
                </div>
            </div>

            <div style="height: 10px;"></div>

            ${!enCurso && !listo ? `
                <a href="#" id="btn-empezar-viaje" class="cta-button">
                    <span class="material-symbols-rounded">play_arrow</span>
                    <span>Empezar Viaje</span>
                </a>
                <div style="height: 10px;"></div>
            ` : ''}

            ${enCurso && !finalizado ? `
                <a href="#" id="btn-finalizar-viaje" class="cta-button">
                    <span class="material-symbols-rounded">flag_circle</span>
                    <span>Finalizar Viaje</span>
                </a>
                <div style="height: 10px;"></div>
            ` : ''}

            ${!enCurso ? `
                <a href="#" id="btn-cancelar-viaje" class="cta-button orange">
                    <span class="material-symbols-rounded">cancel</span>
                    <span>Cancelar Viaje</span>
                </a>
            ` : ''}
        `;

        const btnEmpezar = document.getElementById('btn-empezar-viaje');
        if (btnEmpezar) {
            btnEmpezar.addEventListener('click', async (e) => {
                e.preventDefault();
                await empezarViaje(v.id_viaje);
            });
        }

        const btnFinalizar = document.getElementById('btn-finalizar-viaje');
        if (btnFinalizar) {
            btnFinalizar.addEventListener('click', async (e) => {
                e.preventDefault();
                await finalizarViaje(v.id_viaje);
            });
        }

        const btnCancelar = document.getElementById('btn-cancelar-viaje');
        if (btnCancelar) {
            btnCancelar.addEventListener('click', async (e) => {
                e.preventDefault();
                await cancelarMiViaje(v.id_viaje);
            });
        }

    } catch (error) {
        console.error('Error cargando mi viaje:', error);
        container.innerHTML = `<p class="text-danger">Error: ${error.message}</p>`;
    }
}

async function empezarViaje(idViaje) {
    try {
        const result = await apiCall('passengerReady', { id_viaje: idViaje, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Listo', result.message, () => cargarMiViaje());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

async function finalizarViaje(idViaje) {
    if (!confirm('¿Confirmar que ya terminaste este viaje?')) return;

    try {
        const result = await apiCall('passengerFinish', { id_viaje: idViaje, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Listo', result.message, () => cargarMiViaje());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

async function cancelarMiViaje(idViaje) {
    if (!confirm('¿Cancelar este viaje?')) return;

    try {
        const result = await apiCall('cancelTrip', { id_viaje: idViaje, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => cargarMiViaje());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireAuth('login.html');
    if (!currentUser) return;

    cargarMiViaje();
});
