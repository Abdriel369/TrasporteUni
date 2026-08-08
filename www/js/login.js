// login.js - lógica de la pantalla de inicio de sesión

document.addEventListener('DOMContentLoaded', () => {
    const btnLoginSubmit = document.getElementById('btn-login-submit');
    if (!btnLoginSubmit) return;

    btnLoginSubmit.addEventListener('click', async (e) => {
        e.preventDefault();

        const correoInput = document.getElementById('login-correo');
        const claveInput = document.getElementById('login-clave');

        const correo = correoInput.value.trim();
        const clave = claveInput.value.trim();

        if (!correo || !clave) {
            showAlert('Error', 'Por favor completa todos los campos.');
            return;
        }

        try {
            const data = await apiCall('login', { correo, clave });

            if (data.status === 'ok') {
                setCurrentUser({
                    correo: correo,
                    nombre: data.nombre,
                    rol: data.rol,
                    id: data.id_usuario
                });

                showAlert('✅ Éxito', data.message, () => {
                    window.location.href = 'menu.html';
                });
            } else {
                showAlert('❌ Error', data.message);
            }

        } catch (error) {
            console.error('Error de conexión:', error);
            showAlert('❌ Error', error.message);
        }
    });
});
