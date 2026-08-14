// publicar-ruta.js - lógica de publicación de rutas (solo Conductor)

let currentUser = null;

async function cargarTodasLasRutas() {
    const contenedor = document.getElementById('my-routes-list');
    if (!contenedor) return;

    contenedor.innerHTML = "<p>Cargando rutas...</p>";

    try {
        const data = await apiCall('getAllRoutes');

        if (data.status === "ok" && data.rutas && data.rutas.length > 0) {
            const tabla = document.createElement('table');
            tabla.className = 'table table-custom';
            tabla.innerHTML = `
                <thead>
                    <tr>
                        <th>Origen</th>
                        <th>Destino</th>
                        <th>Horario</th>
                        <th>Lugares</th>
                        <th>Conductor</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody></tbody>
            `;

            const tbody = tabla.querySelector('tbody');
            data.rutas.forEach(ruta => {
                const fila = document.createElement('tr');
                fila.innerHTML = `
                    <td>${ruta.origen || 'N/A'}</td>
                    <td>${ruta.destino || 'N/A'}</td>
                    <td>${ruta.horario || 'N/A'}</td>
                    <td>${ruta.lugares || 0}</td>
                    <td>${ruta.nombre_conductor || ruta.conductor || 'N/A'}</td>
                    <td class="${ruta.lugares > 0 ? 'text-success' : 'text-danger'}">
                        ${ruta.lugares > 0 ? 'Disponible' : 'Lleno'}
                    </td>
                `;
                tbody.appendChild(fila);
            });

            contenedor.innerHTML = '';
            contenedor.appendChild(tabla);
        } else {
            contenedor.innerHTML = "<p>No hay rutas registradas en el sistema.</p>";
        }
    } catch (error) {
        console.error("Error cargando rutas:", error);
        contenedor.innerHTML = `<p class='text-danger'>Error: ${error.message}</p>`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireRole('Conductor', 'menu.html');
    if (!currentUser) return;

    cargarTodasLasRutas();

    const btnPublishSubmit = document.getElementById('btn-publish-submit');
    if (btnPublishSubmit) {
        btnPublishSubmit.addEventListener('click', async (e) => {
            e.preventDefault();

            const origenInput = document.getElementById('route-origen');
            const destinoInput = document.getElementById('route-destino');
            const horarioInput = document.getElementById('route-horario');
            const lugaresInput = document.getElementById('route-lugares');
            const precioInput = document.getElementById('route-precio');

            const origen = origenInput.value.trim();
            const destino = destinoInput.value.trim();
            const horario = horarioInput.value;
            const lugares = parseInt(lugaresInput.value, 10) || 0;
            const precio = parseFloat(precioInput.value) || 0;

            if (!origen || !destino || !horario || lugares <= 0) {
                return showAlert('Datos incompletos', 'Completa todos los campos con valores válidos.');
            }
            if (precio < 0) {
                return showAlert('Costo inválido', 'El costo no puede ser negativo.');
            }

            // Aviso local (la validación real y definitiva la hace el servidor)
            const ahora = new Date();
            const [hh, mm] = horario.split(':').map(Number);
            const horarioElegido = new Date();
            horarioElegido.setHours(hh, mm, 0, 0);
            if (horarioElegido <= ahora) {
                return showAlert('Horario inválido', 'No puedes publicar una ruta con un horario que ya pasó. Elige una hora futura.');
            }

            try {
                const result = await apiCall('addRoute', {
                    origen: origen,
                    destino: destino,
                    horario: horario,
                    lugares: lugares,
                    precio: precio,
                    conductor: currentUser.correo
                });

                if (result.status === 'success') {
                    showAlert('✅ Éxito', 'Ruta publicada correctamente', () => {
                        origenInput.value = '';
                        destinoInput.value = '';
                        horarioInput.value = '';
                        lugaresInput.value = '4';
                        precioInput.value = '0';
                        cargarTodasLasRutas();
                    });
                } else {
                    showAlert('❌ Error', result.message);
                }
            } catch (err) {
                showAlert('❌ Error', err.message);
                console.error(err);
            }
        });
    }
});
