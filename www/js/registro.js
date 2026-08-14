// registro.js - lógica de la pantalla de registro

document.addEventListener('DOMContentLoaded', () => {
    const btnRegisterSubmit = document.getElementById('btn-register-submit');
    if (!btnRegisterSubmit) return;

    btnRegisterSubmit.addEventListener('click', async (e) => {
        e.preventDefault();

        const nombreInput = document.getElementById('reg-nombre');
        const correoInput = document.getElementById('reg-correo');
        const controlInput = document.getElementById('reg-control');
        const claveInput = document.getElementById('reg-clave');
        const confirmarInput = document.getElementById('reg-confirmar');
        const rolInputs = document.querySelectorAll('input[name="rol"]');

        const data = {
            nombre: nombreInput.value.trim(),
            correo: correoInput.value.trim(),
            control: controlInput.value.trim(),
            clave: claveInput.value.trim(),
            confirmar: confirmarInput.value.trim(),
            rol: 'Pasajero'
        };

        if (!data.nombre) {
            showAlert('Datos incompletos', 'Escribe tu nombre completo.');
            return;
        }

        let rolSeleccionado = false;
        rolInputs.forEach(input => {
            if (input.checked) {
                data.rol = input.value;
                rolSeleccionado = true;
            }
        });

        if (!rolSeleccionado) {
            showAlert('Error', 'Por favor selecciona un rol (Pasajero o Conductor).');
            return;
        }

        if (!validateRegistration(data)) return;

        try {
            const result = await apiCall('register', {
                nombre: data.nombre,
                correo: data.correo,
                numControl: data.control,
                clave: data.clave,
                rol: data.rol
            });

            if (result.status === 'success') {
                setCurrentUser({
                    correo: data.correo,
                    nombre: data.nombre,
                    rol: data.rol
                });

                showAlert('✅ Éxito', result.message, () => {
                    window.location.href = 'menu.html';
                });
            } else {
                showAlert('❌ Error', result.message);
            }

        } catch (err) {
            console.error('Error al conectar con el servidor:', err);
            showAlert('❌ Error', err.message);
        }
    });
});
