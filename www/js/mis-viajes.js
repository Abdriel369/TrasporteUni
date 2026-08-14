// mis-viajes.js - viajes activos del conductor, agrupados por ruta

let currentUser = null;

function agruparPorRuta(viajes) {
    const grupos = {};
    viajes.forEach(v => {
        if (!grupos[v.id_ruta]) {
            grupos[v.id_ruta] = {
                id_ruta: v.id_ruta,
                origen: v.origen,
                destino: v.destino,
                horario_ruta: v.horario_ruta,
                fecha: v.fecha,
                pasajeros: []
            };
        }
        grupos[v.id_ruta].pasajeros.push(v);
    });
    return Object.values(grupos);
}

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

        if (data.status !== "success") {
            container.innerHTML = `<p class="text-danger">Error: ${data.message}</p>`;
            return;
        }

        if (!data.viajes || data.viajes.length === 0) {
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
            return;
        }

        const grupos = agruparPorRuta(data.viajes);
        container.innerHTML = '';

        grupos.forEach(grupo => {
            const enCurso = grupo.pasajeros.some(p => p.estado === 'en_curso');
            const todosListos = grupo.pasajeros.every(p => p.pasajero_listo == 1);
            const todosFinalizados = enCurso && grupo.pasajeros.every(p => p.pasajero_finalizado == 1);

            const wrapper = document.createElement('div');
            wrapper.style.marginBottom = '18px';

            const filasPasajeros = grupo.pasajeros.map(p => {
                let icono = 'hourglass_empty';
                if (enCurso) {
                    icono = p.pasajero_finalizado == 1 ? 'flag_circle' : 'directions_car';
                } else if (p.pasajero_listo == 1) {
                    icono = 'check_circle';
                }
                return `
                <div class="list-tile">
                    <div class="list-tile-icon-bg">
                        <span class="material-symbols-rounded">${icono}</span>
                    </div>
                    <div class="list-tile-text">
                        <strong>${p.nombre_pasajero}</strong>
                        <div style="font-size: 14px; color: #666;">No. Control: ${p.num_control_pasajero}</div>
                        ${enCurso ? `<div style="font-size: 12px; color: ${p.pasajero_finalizado == 1 ? '#4CAF50' : '#FF8A00'};">${p.pasajero_finalizado == 1 ? 'Finalizó su viaje' : 'Viaje en curso'}</div>` : ''}
                    </div>
                    ${p.estado === 'pendiente' ? `
                        <button class="btn-cancelar-pasajero" data-viaje="${p.id_viaje}"
                                style="background:#E53935; color:white; border:none; padding:6px 12px; border-radius:16px; cursor:pointer; font-size:12px; white-space:nowrap;">
                            Cancelar
                        </button>
                    ` : ''}
                </div>
            `;
            }).join('');

            wrapper.innerHTML = `
                <div class="hero-card">
                    <div class="hero-card-icon-bg">
                        <span class="material-symbols-rounded">directions_car</span>
                    </div>
                    <div class="hero-card-text">
                        <h2>${grupo.origen} → ${grupo.destino}</h2>
                        <p>${grupo.fecha} | ${grupo.horario_ruta} ${enCurso ? '· <strong style="color:#2979FF;">En curso</strong>' : ''}</p>
                    </div>
                </div>

                <div class="section-header">Pasajeros que solicitan tu viaje</div>
                <div class="list-section">${filasPasajeros}</div>

                ${enCurso ? `
                    <button class="btn-completar-ruta" data-ruta="${grupo.id_ruta}"
                            style="width:100%; padding:14px; border:none; border-radius:18px; background-color:${todosFinalizados ? '#4CAF50' : '#ccc'}; color:white; font-weight:600; cursor:${todosFinalizados ? 'pointer' : 'not-allowed'}; margin-bottom:10px;"
                            ${todosFinalizados ? '' : 'disabled'}>
                        ${todosFinalizados ? 'Completar Viaje' : 'Esperando a que todos los pasajeros finalicen...'}
                    </button>
                ` : `
                    <button class="btn-iniciar-ruta" data-ruta="${grupo.id_ruta}"
                            style="width:100%; padding:14px; border:none; border-radius:18px; background-color:${todosListos ? 'var(--blue)' : '#ccc'}; color:white; font-weight:600; cursor:${todosListos ? 'pointer' : 'not-allowed'}; margin-bottom:10px;"
                            ${todosListos ? '' : 'disabled'}>
                        ${todosListos ? 'Iniciar Viaje' : 'Esperando a que todos los pasajeros confirmen...'}
                    </button>
                `}
            `;

            container.appendChild(wrapper);
        });

        document.querySelectorAll('.btn-cancelar-pasajero').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();
                const idViaje = button.getAttribute('data-viaje');
                await cancelarPasajero(idViaje);
            });
        });

        document.querySelectorAll('.btn-iniciar-ruta').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();
                const idRuta = button.getAttribute('data-ruta');
                await iniciarRuta(idRuta);
            });
        });

        document.querySelectorAll('.btn-completar-ruta').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();
                const idRuta = button.getAttribute('data-ruta');
                await completarRuta(idRuta);
            });
        });

    } catch (error) {
        console.error('Error cargando viajes del conductor:', error);
        container.innerHTML = `<p class="text-danger">${error.message}</p>`;
    }
}

async function iniciarRuta(idRuta) {
    if (!confirm('¿Iniciar el viaje para todos los pasajeros de esta ruta?')) return;

    try {
        const result = await apiCall('startRouteTrip', { id_ruta: idRuta, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => cargarViajesConductor());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

async function completarRuta(idRuta) {
    if (!confirm('¿Marcar este viaje como completado para todos los pasajeros?\n\nEsto permitirá que te califiquen.')) return;

    try {
        const result = await apiCall('completeRouteTrip', { id_ruta: idRuta, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => cargarViajesConductor());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

async function cancelarPasajero(idViaje) {
    const confirmacion = confirm('¿Cancelar el viaje de este pasajero?\n\nEl lugar se liberará de nuevo en la ruta.');
    if (!confirmacion) return;

    try {
        const result = await apiCall('cancelTrip', { id_viaje: idViaje, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => {
                cargarViajesConductor();
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
    currentUser = requireRole('Conductor', 'menu.html');
    if (!currentUser) return;

    cargarViajesConductor();
});
