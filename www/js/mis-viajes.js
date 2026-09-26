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

    cerrarChat();

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

        // PUNTO 5: aunque no haya viajes reservados, hay que seguir
        // mostrando las rutas publicadas para poder iniciarlas.
        await cargarMisRutas(data.viajes || []);

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
            const pendientes = grupo.pasajeros.filter(p => p.estado === 'pendiente');
            const sinConfirmar = pendientes.filter(p => p.pasajero_listo != 1);
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

                // PUNTO 3: estado del pago de ESTE pasajero
                const etiquetaPago = etiquetaEstadoPago(p.metodo_pago, p.estado_pago);
                const pagoPendiente = p.metodo_pago === 'efectivo' && p.estado_pago === 'pendiente_confirmacion';

                const detalleEstado = enCurso
                    ? (p.pasajero_finalizado == 1 ? 'Finalizó su viaje' : 'Viaje en curso')
                    : (p.pasajero_listo == 1 ? 'Confirmó que está listo' : 'Todavía no confirma que está listo');

                return `
                <div class="list-tile" style="cursor:default; flex-wrap:wrap; gap:8px;">
                    <div class="list-tile-icon-bg">
                        <span class="material-symbols-rounded">${icono}</span>
                    </div>
                    <div class="list-tile-text">
                        <strong>${p.nombre_pasajero}</strong>
                        <div style="font-size: 14px; color: #666;">No. Control: ${p.num_control_pasajero}</div>
                        <div style="font-size: 12px; color: ${enCurso ? (p.pasajero_finalizado == 1 ? '#4CAF50' : '#FF8A00') : '#666'};">
                            ${detalleEstado}
                        </div>
                        <div style="font-size: 12px; font-weight: 600; color: ${etiquetaPago.color};">
                            ${etiquetaPago.texto}
                        </div>
                    </div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        ${pagoPendiente ? `
                            <button class="btn-mini-accion pago btn-confirmar-pago"
                                    data-viaje="${p.id_viaje}" data-nombre="${p.nombre_pasajero}">
                                Confirmar pago
                            </button>
                        ` : ''}
                        <button class="btn-mini-accion mensaje btn-chat-pasajero"
                                data-viaje="${p.id_viaje}" data-nombre="${p.nombre_pasajero}">
                            Mensaje
                        </button>
                        ${p.estado === 'pendiente' ? `
                            <button class="btn-mini-accion cancelar btn-cancelar-pasajero" data-viaje="${p.id_viaje}">
                                Cancelar
                            </button>
                        ` : ''}
                    </div>
                </div>
            `;
            }).join('');

            // PUNTO 5: el botón de iniciar ya no depende de que TODOS hayan
            // confirmado. Si alguien no confirmó, se avisa que se cancelará.
            const textoIniciar = sinConfirmar.length > 0
                ? `Iniciar Viaje (${sinConfirmar.length} sin confirmar serán cancelados)`
                : 'Iniciar Viaje';

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
                    <button class="btn-iniciar-ruta" data-ruta="${grupo.id_ruta}" data-sin-confirmar="${sinConfirmar.length}"
                            style="width:100%; padding:14px; border:none; border-radius:18px; background-color:var(--blue); color:white; font-weight:600; cursor:pointer; margin-bottom:10px;">
                        ${textoIniciar}
                    </button>
                `}
            `;

            container.appendChild(wrapper);
        });

        document.querySelectorAll('.btn-cancelar-pasajero').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();
                await cancelarPasajero(button.getAttribute('data-viaje'));
            });
        });

        document.querySelectorAll('.btn-confirmar-pago').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();
                await confirmarPagoEfectivo(
                    button.getAttribute('data-viaje'),
                    button.getAttribute('data-nombre')
                );
            });
        });

        document.querySelectorAll('.btn-chat-pasajero').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                abrirChat(button.getAttribute('data-viaje'), button.getAttribute('data-nombre'));
            });
        });

        document.querySelectorAll('.btn-iniciar-ruta').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();
                await iniciarRuta(
                    button.getAttribute('data-ruta'),
                    parseInt(button.getAttribute('data-sin-confirmar'), 10) || 0
                );
            });
        });

        document.querySelectorAll('.btn-completar-ruta').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();
                await completarRuta(button.getAttribute('data-ruta'));
            });
        });

    } catch (error) {
        console.error('Error cargando viajes del conductor:', error);
        container.innerHTML = `<p class="text-danger">${error.message}</p>`;
    }
}

// PUNTO 5: sección con las rutas publicadas que NO tienen viajes activos.
// Es la única forma de iniciar una ruta sin pasajeros.
async function cargarMisRutas(viajesActivos) {
    const cont = document.getElementById('mis-rutas-list');
    const header = document.getElementById('header-mis-rutas');
    if (!cont) return;

    const ocultar = () => {
        cont.innerHTML = '';
        if (header) header.style.display = 'none';
    };

    try {
        const data = await apiCall('getMyRoutes', { userEmail: currentUser.correo });

        if (data.status !== 'success' || !data.rutas) {
            ocultar();
            return;
        }

        // Solo las rutas que no aparecen ya arriba (sin viajes pendientes/en curso)
        const rutasSueltas = data.rutas.filter(r =>
            !viajesActivos.some(v => String(v.id_ruta) === String(r.id_ruta))
        );

        if (rutasSueltas.length === 0) {
            ocultar();
            return;
        }

        if (header) header.style.display = 'block';

        cont.innerHTML = rutasSueltas.map(r => `
            <div class="list-section">
                <div class="list-tile" style="cursor:default; flex-wrap:wrap; gap:8px;">
                    <div class="list-tile-icon-bg">
                        <span class="material-symbols-rounded">route</span>
                    </div>
                    <div class="list-tile-text">
                        <strong>${r.origen} → ${r.destino}</strong>
                        <div style="font-size: 14px; color: #666;">
                            ${r.fecha} | ${r.horario} | ${r.lugares} lugar(es) libre(s)
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            Sin pasajeros esperando en este momento
                        </div>
                    </div>
                    <button class="btn-mini-accion mensaje btn-iniciar-ruta-vacia" data-ruta="${r.id_ruta}">
                        Iniciar Viaje
                    </button>
                </div>
            </div>
        `).join('');

        cont.querySelectorAll('.btn-iniciar-ruta-vacia').forEach(button => {
            button.addEventListener('click', async (e) => {
                e.preventDefault();
                await iniciarRuta(button.getAttribute('data-ruta'), 0);
            });
        });

    } catch (error) {
        console.error('Error cargando tus rutas:', error);
        ocultar();
    }
}

async function iniciarRuta(idRuta, sinConfirmar) {
    let mensaje = '¿Iniciar el viaje para esta ruta?';
    if (sinConfirmar > 0) {
        mensaje = `Hay ${sinConfirmar} pasajero(s) que NO confirmaron que están listos.\n\n` +
                  `Si inicias ahora, su viaje se CANCELARÁ automáticamente y su lugar quedará libre.\n\n` +
                  `¿Iniciar de todos modos?`;
    } else if (!sinConfirmar) {
        mensaje = '¿Iniciar el viaje para esta ruta?\n\nSi no hay pasajeros, la ruta quedará marcada como iniciada sin pasajeros.';
    }

    if (!confirm(mensaje)) return;

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

// PUNTO 3: confirmación individual del pago en efectivo de UN pasajero.
async function confirmarPagoEfectivo(idViaje, nombrePasajero) {
    if (!confirm(`¿Confirmas que ${nombrePasajero} ya te pagó en efectivo?`)) return;

    try {
        const result = await apiCall('confirmarPagoEfectivo', {
            id_viaje: idViaje,
            userEmail: currentUser.correo
        });

        if (result.status === 'success') {
            showAlert('✅ Pago confirmado', result.message, () => cargarViajesConductor());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireRole('Conductor', 'menu.html');
    if (!currentUser) return;

    cargarViajesConductor();
});
