// publicar-ruta.js - lógica de publicación de rutas (solo Conductor)

let currentUser = null;

// PUNTO 1: sin vehículo registrado por el administrador no se puede publicar.
let conductorTieneVehiculo = false;

const MENSAJE_SIN_VEHICULO = 'Debes dirigirte al plantel administrativo para registrar tu vehículo antes de poder publicar rutas.';

async function verificarVehiculoConductor() {
    const aviso = document.getElementById('aviso-vehiculo');
    const btnPublicar = document.getElementById('btn-publish-submit');

    try {
        const data = await apiCall('getMyVehicle', { userEmail: currentUser.correo });

        if (data.status === 'success' && data.tiene_vehiculo) {
            conductorTieneVehiculo = true;
            if (aviso) {
                aviso.innerHTML = `
                    <div class="alert alert-success" style="font-size:14px;">
                        Vehículo registrado: <strong>${data.vehiculo.modelo}</strong> · Placas <strong>${data.vehiculo.placas}</strong>
                    </div>
                `;
            }
            return true;
        }

        // Sin vehículo: se bloquea el botón y se avisa
        conductorTieneVehiculo = false;
        if (btnPublicar) {
            btnPublicar.style.pointerEvents = 'none';
            btnPublicar.style.opacity = '0.5';
        }
        if (aviso) {
            aviso.innerHTML = `
                <div class="alert alert-danger" style="font-size:14px;">
                    <strong>Vehículo no registrado.</strong><br>${MENSAJE_SIN_VEHICULO}
                </div>
            `;
        }
        showAlert('Vehículo no registrado', MENSAJE_SIN_VEHICULO);
        return false;

    } catch (error) {
        // Si la consulta falla, el backend sigue siendo la última palabra:
        // no bloqueamos la pantalla por un error de red (api.php rechazará
        // la publicación con el mensaje claro si de verdad no hay vehículo).
        console.error('No se pudo verificar el vehículo:', error);
        conductorTieneVehiculo = true;
        if (aviso) {
            aviso.innerHTML = `
                <div class="alert alert-warning" style="font-size:14px;">
                    No se pudo verificar tu vehículo ahora mismo. El sistema lo validará al publicar.
                </div>
            `;
        }
        return false;
    }
}

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

document.addEventListener('DOMContentLoaded', async () => {
    currentUser = requireRole('Conductor', 'menu.html');
    if (!currentUser) return;

    // PUNTO 1: antes de nada, comprobar que el conductor tiene vehículo.
    await verificarVehiculoConductor();

    cargarTodasLasRutas();

    const btnPublishSubmit = document.getElementById('btn-publish-submit');
    if (btnPublishSubmit) {
        btnPublishSubmit.addEventListener('click', async (e) => {
            e.preventDefault();

            // PUNTO 1: se revalida aquí para no hacer perder tiempo con la
            // predicción de IA si de todos modos no puede publicar.
            if (!conductorTieneVehiculo) {
                const ok = await verificarVehiculoConductor();
                if (!ok && !conductorTieneVehiculo) {
                    showAlert('Vehículo no registrado', MENSAJE_SIN_VEHICULO);
                    return;
                }
            }

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

            // --- Paso 1: preguntarle al modelo de IA si conviene publicar ---
            let prediccion = null;
            try {
                const pred = await apiCall('predecirPublicacion', { horario: horario });

                if (pred.status === 'success') {
                    prediccion = pred;

                    const quierePublicar = confirm(
                        `🤖 El modelo dice:\n\n${pred.mensaje}\n\n` +
                        `Recomendación del modelo: ${pred.recomendacion === 'publicar' ? 'PUBLICAR' : 'CANCELAR'}\n\n` +
                        `Presiona "Aceptar" para PUBLICAR el viaje de todos modos,\n` +
                        `o "Cancelar" para NO publicarlo.`
                    );

                    if (!quierePublicar) {
                        return; // El conductor decidió no publicar
                    }
                } else {
                    // El servicio de IA no respondió: se le avisa al conductor
                    // pero no se le bloquea la publicación por eso.
                    const continuarSinModelo = confirm(
                        `⚠️ No se pudo consultar al modelo de IA (${pred.message || 'sin detalle'}).\n\n` +
                        `¿Deseas publicar el viaje de todos modos, sin la predicción?`
                    );
                    if (!continuarSinModelo) {
                        return;
                    }
                }
            } catch (err) {
                console.error('Error consultando al modelo de IA:', err);
                const continuarSinModelo = confirm(
                    `⚠️ No se pudo consultar al modelo de IA.\n\n¿Deseas publicar el viaje de todos modos?`
                );
                if (!continuarSinModelo) {
                    return;
                }
            }

            // --- Paso 2: publicar (guardando también lo que dijo el modelo) ---
            try {
                const result = await apiCall('addRoute', {
                    origen: origen,
                    destino: destino,
                    horario: horario,
                    lugares: lugares,
                    precio: precio,
                    conductor: currentUser.correo,
                    prediccion_valor: prediccion ? prediccion.prediccion_valor : null,
                    prediccion_mensaje: prediccion ? prediccion.mensaje : null,
                    prediccion_recom: prediccion ? prediccion.recomendacion : null
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
