// mi-viaje.js - viaje activo actual del pasajero

let currentUser = null;

async function cargarMiViaje() {
    const container = document.getElementById('mi-viaje-content');
    if (!container) return;

    // Si había un chat abierto, se cierra al recargar la vista
    cerrarChat();

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

        // PUNTO 5: aviso de cancelación automática ("El viaje se ha cancelado.")
        // El backend lo entrega una sola vez.
        if (data.mensaje_cancelacion) {
            setTimeout(() => showAlert('Viaje cancelado', data.mensaje_cancelacion), 150);
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

        // PUNTO 3 + 6: estado del pago de este viaje
        const estadoPago = v.estado_pago || null;
        const pagado = pagoEstaPagado(estadoPago);
        const pendienteConfirmacion = estadoPago === 'pendiente_confirmacion';
        const etiquetaPago = etiquetaEstadoPago(v.metodo_pago, estadoPago);

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

        // --- Bloque de pago: "Ya pagué" solo para efectivo y sin pago aún ---
        let botonesPago = '';
        if (!pagado && !pendienteConfirmacion) {
            botonesPago = `
                <a href="#" id="btn-ya-pague" class="cta-button orange">
                    <span class="material-symbols-rounded">paid</span>
                    <span>Ya pagué (efectivo)</span>
                </a>
                <div style="height: 10px;"></div>
                <a href="pagos.html" class="cta-button">
                    <span class="material-symbols-rounded">credit_card</span>
                    <span>Pagar con tarjeta</span>
                </a>
                <div style="height: 10px;"></div>
            `;
        } else if (pendienteConfirmacion) {
            botonesPago = `
                <div class="alert alert-warning" style="font-size: 14px;">
                    Tu pago en efectivo está <strong>pendiente de confirmación del conductor</strong>.
                    Podrás finalizar el viaje en cuanto lo confirme.
                </div>
            `;
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

            <div class="section-header">Costo y pago</div>
            <div class="list-section">
                <div class="list-tile">
                    <div class="list-tile-icon-bg"><span class="material-symbols-rounded">payments</span></div>
                    <div class="list-tile-text">
                        <strong>$${v.costo}</strong>
                        <div style="font-size: 14px; color: ${etiquetaPago.color}; font-weight: 600;">
                            ${etiquetaPago.texto}
                        </div>
                    </div>
                </div>
            </div>

            <div style="height: 10px;"></div>

            ${botonesPago}

            ${!enCurso && !listo ? `
                <a href="#" id="btn-empezar-viaje" class="cta-button">
                    <span class="material-symbols-rounded">play_arrow</span>
                    <span>Empezar Viaje</span>
                </a>
                <div style="height: 10px;"></div>
            ` : ''}

            ${enCurso && !finalizado && pagado ? `
                <a href="#" id="btn-finalizar-viaje" class="cta-button">
                    <span class="material-symbols-rounded">flag_circle</span>
                    <span>Finalizar Viaje</span>
                </a>
                <div style="height: 10px;"></div>
            ` : ''}

            ${enCurso && !finalizado && !pagado ? `
                <button class="cta-button" disabled
                        style="background:#CCCCCC; box-shadow:none; cursor:not-allowed;">
                    <span class="material-symbols-rounded">lock</span>
                    <span>Finalizar Viaje (requiere pago confirmado)</span>
                </button>
                <div style="height: 10px;"></div>
            ` : ''}

            <a href="#" id="btn-chat-viaje" class="cta-button">
                <span class="material-symbols-rounded">chat</span>
                <span>Mensajes con el conductor</span>
            </a>
            <div style="height: 10px;"></div>

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

        const btnYaPague = document.getElementById('btn-ya-pague');
        if (btnYaPague) {
            btnYaPague.addEventListener('click', async (e) => {
                e.preventDefault();
                await declararPagoEfectivo(v.id_viaje, v.costo);
            });
        }

        const btnFinalizar = document.getElementById('btn-finalizar-viaje');
        if (btnFinalizar) {
            btnFinalizar.addEventListener('click', async (e) => {
                e.preventDefault();
                await finalizarViaje(v.id_viaje);
            });
        }

        const btnChat = document.getElementById('btn-chat-viaje');
        if (btnChat) {
            btnChat.addEventListener('click', (e) => {
                e.preventDefault();
                abrirChat(v.id_viaje, v.nombre_conductor);
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

// PUNTO 3: el pasajero avisa que ya pagó en efectivo. El pago queda en
// 'pendiente_confirmacion' hasta que el conductor lo confirme.
async function declararPagoEfectivo(idViaje, monto) {
    if (!confirm('¿Confirmas que ya pagaste en efectivo al conductor?\n\nEl conductor deberá confirmar que lo recibió antes de que puedas finalizar el viaje.')) {
        return;
    }

    try {
        const result = await apiCall('processPayment', {
            metodo: 'Efectivo',
            userEmail: currentUser.correo,
            id_viaje: idViaje,
            monto: monto
        });

        if (result.status === 'success') {
            showAlert('✅ Aviso enviado', result.message, () => cargarMiViaje());
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
            // PUNTO 6: aquí llega el rechazo si el pago no está confirmado
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
