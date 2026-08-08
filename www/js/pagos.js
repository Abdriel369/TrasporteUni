// pagos.js - lógica de la pantalla de pagos

let currentUser = null;

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireAuth('login.html');
    if (!currentUser) return;

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

            const paymentData = {
                metodo: metodo,
                userEmail: currentUser.correo,
                monto: 25.00
            };

            if (metodo === 'Efectivo') {
                try {
                    const result = await apiCall('processPayment', paymentData);

                    if (result.status === 'success') {
                        showAlert('✅ Pago registrado', result.message, () => {
                            window.location.href = 'menu.html';
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
                            window.location.href = 'menu.html';
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
