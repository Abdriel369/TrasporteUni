// pagos.js - lógica de la pantalla de pagos

let currentUser = null;

// PUNTO 3/6: si el pasajero tiene un viaje activo, el pago se registra
// contra ESE viaje (id_viaje), que es lo que luego permite al conductor
// confirmar el efectivo y al pasajero finalizar.
let viajeActivo = null;

// Muestra el contexto del viaje que se está pagando (si existe)
async function cargarViajeParaPago() {
    const info = document.getElementById('pago-viaje-info');
    try {
        const data = await apiCall('getMyCurrentTrip', { userEmail: currentUser.correo });

        if (data.status === 'success' && data.tiene_viaje) {
            viajeActivo = data.viaje;
            const etiqueta = etiquetaEstadoPago(viajeActivo.metodo_pago, viajeActivo.estado_pago);

            if (info) {
                info.innerHTML = `
                    <div class="alert alert-info" style="font-size: 14px;">
                        Estás pagando el viaje <strong>${viajeActivo.origen} → ${viajeActivo.destino}</strong><br>
                        Monto: <strong>$${viajeActivo.costo}</strong><br>
                        Estado: <strong style="color:${etiqueta.color};">${etiqueta.texto}</strong>
                    </div>
                `;
            }
        } else if (info) {
            info.innerHTML = `
                <div class="alert alert-warning" style="font-size: 14px;">
                    No tienes un viaje activo: este pago se registrará como un pago suelto.
                </div>
            `;
        }
    } catch (error) {
        console.error('No se pudo cargar el viaje a pagar:', error);
    }
}

function destinoTrasPagar() {
    return viajeActivo ? 'mi-viaje.html' : 'menu.html';
}

document.addEventListener('DOMContentLoaded', async () => {
    currentUser = requireAuth('login.html');
    if (!currentUser) return;

    await cargarViajeParaPago();

    const paymentRadios = document.querySelectorAll('input[name="metodo"]');
    paymentRadios.forEach(radio => {
        radio.addEventListener('change', (e) => {
            const esTarjeta = e.target.value === 'Tarjeta';
            document.getElementById('payment-fields-tarjeta').style.display = esTarjeta ? 'block' : 'none';
            document.getElementById('payment-fields-efectivo').style.display = esTarjeta ? 'none' : 'block';
        });
    });

    const payNumero = document.getElementById('pay-numero');
    if (payNumero) {
        payNumero.addEventListener('input', (e) => {
            let v = e.target.value.replace(/\D/g, '').substring(0, 16);
            let formatted = v.replace(/(\d{4})(?=\d)/g, '$1-');
            e.target.value = formatted;
        });
    }

    const payExp = document.getElementById('pay-exp');
    if (payExp) {
        payExp.addEventListener('input', (e) => {
            let v = e.target.value.replace(/\D/g, '').substring(0, 4);
            if (v.length >= 3) {
                v = `${v.substring(0, 2)}/${v.substring(2)}`;
            }
            e.target.value = v;
        });
    }

    const btnPaymentSubmit = document.getElementById('btn-payment-submit');
    if (btnPaymentSubmit) {
        btnPaymentSubmit.addEventListener('click', async (e) => {
            e.preventDefault();

            let metodo = '';
            document.querySelectorAll('input[name="metodo"]').forEach(radio => {
                if (radio.checked) metodo = radio.value;
            });

            if (!metodo) {
                showAlert('Error', 'Selecciona un método de pago.');
                return;
            }

            if (viajeActivo && pagoEstaPagado(viajeActivo.estado_pago)) {
                showAlert('Pago ya registrado', 'Este viaje ya tiene un pago confirmado.');
                return;
            }

            const paymentData = {
                metodo: metodo,
                userEmail: currentUser.correo,
                monto: viajeActivo ? viajeActivo.costo : 25.00,
                id_viaje: viajeActivo ? viajeActivo.id_viaje : null
            };

            if (metodo === 'Efectivo') {
                try {
                    const result = await apiCall('processPayment', paymentData);

                    if (result.status === 'success') {
                        showAlert('✅ Pago registrado', result.message, () => {
                            window.location.href = destinoTrasPagar();
                        });
                    } else {
                        showAlert('❌ Error', result.message || 'Error al procesar el pago en efectivo');
                    }

                } catch (error) {
                    console.error('Error procesando pago:', error);
                    showAlert('❌ Error', error.message);
                }

            } else if (metodo === 'Tarjeta') {
                const nameInput = document.getElementById('pay-titular');
                const rawInput = document.getElementById('pay-numero');
                const exInput = document.getElementById('pay-exp');
                const cInput = document.getElementById('pay-cvv');

                const name = nameInput.value.trim();
                const raw = rawInput.value.replace(/-/g, '');
                const ex = exInput.value;
                const c = cInput.value;

                if (!name || !isOnlyLettersSpaces(name) || name.length < 3) {
                    return showAlert('Titular', 'Escribe el nombre del titular usando solo letras y espacios (mín. 3).');
                }
                if (!/^\d{13,16}$/.test(raw)) {
                    return showAlert('Número de tarjeta', 'Debe contener entre 13 y 16 dígitos.');
                }
                if (!luhnValid(raw)) {
                    return showAlert('Número de tarjeta', 'El número no es válido (Luhn).');
                }
                if (!isValidExpiry(ex)) {
                    return showAlert('Expiración', 'Formato MM/AA y no puede estar vencida.');
                }
                if (!/^\d{3,4}$/.test(c)) {
                    return showAlert('CVV', 'Debe contener 3 o 4 dígitos.');
                }

                paymentData.titular = name;
                paymentData.numero = raw;
                paymentData.expiracion = ex;
                paymentData.cvv = c;

                try {
                    const result = await apiCall('processPayment', paymentData);

                    if (result.status === 'success') {
                        showAlert('✅ Pago exitoso',
                            `Pago procesado correctamente.\n` +
                            `Monto: $${result.monto}\n` +
                            `Referencia: ${result.referencia}\n` +
                            `Tarjeta: ${result.tarjeta_enmascarada}`,
                            () => {
                            nameInput.value = '';
                            rawInput.value = '';
                            exInput.value = '';
                            cInput.value = '';
                            window.location.href = destinoTrasPagar();
                        });
                    } else {
                        showAlert('❌ Pago rechazado', result.message || 'El pago fue rechazado');
                    }

                } catch (error) {
                    console.error('Error procesando pago con tarjeta:', error);
                    showAlert('❌ Error', error.message);
                }
            }
        });
    }
});
