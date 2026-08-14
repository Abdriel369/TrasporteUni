// admin.js - lógica del panel de administrador

let currentAdmin = null;

// ============================================================
// USUARIOS / CONTRASEÑAS
// ============================================================

async function cargarUsuarios() {
    const tbody = document.getElementById('tbody-usuarios');
    tbody.innerHTML = `<tr><td colspan="5">Cargando usuarios...</td></tr>`;

    try {
        const data = await apiCall('getAllUsers');

        if (data.status !== 'success') {
            throw new Error(data.message || 'Error al cargar usuarios');
        }

        if (data.usuarios.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5">No hay usuarios registrados.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.usuarios.map(u => `
            <tr>
                <td>${u.nombre || 'N/A'}</td>
                <td>${u.correo}</td>
                <td>${u.num_control}</td>
                <td>${u.rol}</td>
                <td>
                    <button class="btn-mini asignar" data-cambiar-clave="${u.id_usuario}">Cambiar contraseña</button>
                </td>
            </tr>
        `).join('');

        tbody.querySelectorAll('[data-cambiar-clave]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id_usuario = btn.getAttribute('data-cambiar-clave');
                await cambiarPasswordUsuario(id_usuario);
            });
        });
    } catch (error) {
        console.error('Error cargando usuarios:', error);
        tbody.innerHTML = `<tr><td colspan="5" class="text-danger">${error.message}</td></tr>`;
    }
}

async function cambiarPasswordUsuario(id_usuario) {
    const nueva = prompt('Escribe la nueva contraseña (mínimo 6 caracteres):');
    if (nueva === null) return; // canceló
    if (nueva.length < 6) {
        showAlert('Error', 'La contraseña debe tener al menos 6 caracteres.');
        return;
    }

    try {
        const result = await apiCall('adminChangePassword', { id_usuario, nueva_clave: nueva });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message);
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

// ============================================================
// CONDUCTORES / PLACAS
// ============================================================

async function cargarConductores() {
    const select = document.getElementById('admin-select-conductor');
    const tbody = document.getElementById('tbody-conductores');

    try {
        const data = await apiCall('getConductores');

        if (data.status !== 'success') {
            throw new Error(data.message || 'Error al cargar conductores');
        }

        // Select solo con conductores SIN placas asignadas
        select.innerHTML = '<option value="">Selecciona un conductor sin placas...</option>';
        data.conductores
            .filter(c => !c.id_vehiculo)
            .forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id_usuario;
                opt.textContent = `${c.nombre || c.correo} (${c.num_control})`;
                select.appendChild(opt);
            });

        // Tabla con TODOS los conductores (con o sin placas)
        if (data.conductores.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5">No hay conductores registrados.</td></tr>`;
        } else {
            tbody.innerHTML = data.conductores.map(c => `
                <tr>
                    <td>${c.nombre || 'N/A'}</td>
                    <td>${c.correo}</td>
                    <td>${c.num_control}</td>
                    <td>${c.modelo || '<span style="color:#999;">Sin asignar</span>'}</td>
                    <td>${c.placas || '<span style="color:#999;">Sin asignar</span>'}</td>
                </tr>
            `).join('');
        }
    } catch (error) {
        console.error('Error cargando conductores:', error);
        tbody.innerHTML = `<tr><td colspan="5" class="text-danger">${error.message}</td></tr>`;
    }
}

async function asignarPlacas() {
    const select = document.getElementById('admin-select-conductor');
    const modeloInput = document.getElementById('admin-modelo');
    const placasInput = document.getElementById('admin-placas');

    const id_usuario = select.value;
    const modelo = modeloInput.value.trim();
    const placas = placasInput.value.trim();

    if (!id_usuario) {
        return showAlert('Datos incompletos', 'Selecciona un conductor.');
    }
    if (!modelo || !placas) {
        return showAlert('Datos incompletos', 'Completa el modelo y las placas.');
    }

    try {
        const result = await apiCall('assignPlate', { id_usuario, modelo, placas });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => {
                modeloInput.value = '';
                placasInput.value = '';
                cargarConductores();
            });
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        console.error('Error asignando placas:', error);
        showAlert('❌ Error', error.message);
    }
}

// ============================================================
// RUTAS
// ============================================================

async function cargarRutasAdmin() {
    const tbody = document.getElementById('tbody-rutas');
    tbody.innerHTML = `<tr><td colspan="9">Cargando rutas...</td></tr>`;

    try {
        const data = await apiCall('adminGetAllRoutes');

        if (data.status !== 'success') {
            throw new Error(data.message || 'Error al cargar rutas');
        }

        if (data.rutas.length === 0) {
            tbody.innerHTML = `<tr><td colspan="9">No hay rutas registradas.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.rutas.map(r => `
            <tr>
                <td>${r.origen}</td>
                <td>${r.destino}</td>
                <td>${r.horario}</td>
                <td>${r.fecha}</td>
                <td>${r.lugares}</td>
                <td>
                    <input type="number" step="0.01" min="0" class="precio-input" value="${r.precio}" data-ruta="${r.id_ruta}">
                </td>
                <td>${r.nombre_conductor || r.conductor}</td>
                <td>${badgeEstado(r.estado)}</td>
                <td>
                    <button class="btn-mini guardar" data-guardar-precio="${r.id_ruta}">Guardar</button>
                    <button class="btn-mini cancelar" data-cancelar-ruta="${r.id_ruta}" ${r.estado === 'cancelada' ? 'disabled' : ''}>Cancelar</button>
                </td>
            </tr>
        `).join('');

        tbody.querySelectorAll('[data-guardar-precio]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id_ruta = btn.getAttribute('data-guardar-precio');
                const input = tbody.querySelector(`.precio-input[data-ruta="${id_ruta}"]`);
                await guardarPrecioRuta(id_ruta, input.value);
            });
        });

        tbody.querySelectorAll('[data-cancelar-ruta]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id_ruta = btn.getAttribute('data-cancelar-ruta');
                await cancelarRuta(id_ruta);
            });
        });
    } catch (error) {
        console.error('Error cargando rutas:', error);
        tbody.innerHTML = `<tr><td colspan="9" class="text-danger">${error.message}</td></tr>`;
    }
}

async function guardarPrecioRuta(id_ruta, precio) {
    try {
        const result = await apiCall('adminSetRoutePrice', { id_ruta, precio });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => cargarRutasAdmin());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

async function cancelarRuta(id_ruta) {
    if (!confirm('¿Cancelar esta ruta? Ya no se podrán hacer nuevas reservas sobre ella.')) return;

    try {
        const result = await apiCall('adminCancelRoute', { id_ruta });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => cargarRutasAdmin());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

// ============================================================
// VIAJES ACTIVOS
// ============================================================

function badgeEstadoTexto(estado) {
    const etiquetas = {
        pendiente: 'Pendiente',
        en_curso: 'En curso',
        completado: 'Terminado',
        cancelado: 'Cancelado',
        activa: 'Activa',
        cancelada: 'Cancelada'
    };
    return etiquetas[estado] || estado;
}

function badgeEstado(estado) {
    const clase = 'badge-' + (estado || '').toLowerCase();
    return `<span class="badge-estado ${clase}">${badgeEstadoTexto(estado)}</span>`;
}

async function cargarViajesActivosAdmin() {
    const tbody = document.getElementById('tbody-viajes-activos');
    tbody.innerHTML = `<tr><td colspan="8">Cargando viajes...</td></tr>`;

    try {
        const data = await apiCall('adminGetActiveTrips');

        if (data.status !== 'success') {
            throw new Error(data.message || 'Error al cargar viajes');
        }

        if (data.viajes.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8">No hay viajes activos.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.viajes.map(v => `
            <tr>
                <td>${v.origen} → ${v.destino}</td>
                <td>${v.nombre_pasajero}</td>
                <td>${v.nombre_conductor}</td>
                <td>${v.fecha}</td>
                <td>${v.hora}</td>
                <td>$${v.costo}</td>
                <td>${badgeEstado(v.estado)}</td>
                <td>
                    <button class="btn-mini cancelar" data-cancelar-viaje="${v.id_viaje}">Cancelar</button>
                </td>
            </tr>
        `).join('');

        tbody.querySelectorAll('[data-cancelar-viaje]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id_viaje = btn.getAttribute('data-cancelar-viaje');
                await cancelarViajeAdmin(id_viaje);
            });
        });
    } catch (error) {
        console.error('Error cargando viajes:', error);
        tbody.innerHTML = `<tr><td colspan="8" class="text-danger">${error.message}</td></tr>`;
    }
}

async function cancelarViajeAdmin(id_viaje) {
    if (!confirm('¿Cancelar este viaje? Se liberará el lugar en la ruta.')) return;

    try {
        const result = await apiCall('cancelTrip', { id_viaje, isAdmin: true });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => {
                cargarViajesActivosAdmin();
                cargarHistorialAdmin();
                cargarRutasAdmin();
            });
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

// ============================================================
// HISTORIAL DE VIAJES (terminados y cancelados)
// ============================================================

async function cargarHistorialAdmin() {
    const tbody = document.getElementById('tbody-historial');
    tbody.innerHTML = `<tr><td colspan="8">Cargando historial...</td></tr>`;

    try {
        const data = await apiCall('adminGetTripHistory');

        if (data.status !== 'success') {
            throw new Error(data.message || 'Error al cargar el historial');
        }

        if (data.viajes.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8">El historial está vacío.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.viajes.map(v => `
            <tr>
                <td>${v.origen} → ${v.destino}</td>
                <td>${v.nombre_pasajero}</td>
                <td>${v.nombre_conductor}</td>
                <td>${v.fecha}</td>
                <td>${v.hora}</td>
                <td>$${v.costo}</td>
                <td>${badgeEstado(v.estado)}</td>
                <td>
                    <button class="btn-mini cancelar" data-borrar-historial="${v.id_viaje}">Borrar</button>
                </td>
            </tr>
        `).join('');

        tbody.querySelectorAll('[data-borrar-historial]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id_viaje = btn.getAttribute('data-borrar-historial');
                await borrarRegistroHistorial(id_viaje);
            });
        });
    } catch (error) {
        console.error('Error cargando historial:', error);
        tbody.innerHTML = `<tr><td colspan="8" class="text-danger">${error.message}</td></tr>`;
    }
}

async function borrarRegistroHistorial(id_viaje) {
    if (!confirm('¿Borrar este registro del historial? Esta acción no se puede deshacer.')) return;

    try {
        const result = await apiCall('adminDeleteTripHistory', { id_viaje });

        if (result.status === 'success') {
            cargarHistorialAdmin();
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

async function borrarTodoHistorial() {
    if (!confirm('¿Borrar TODO el historial de viajes terminados y cancelados? Esta acción no se puede deshacer.')) return;

    try {
        const result = await apiCall('adminClearTripHistory');

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => cargarHistorialAdmin());
        } else {
            showAlert('❌ Error', result.message);
        }
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}

// ============================================================
// INIT
// ============================================================

document.addEventListener('DOMContentLoaded', () => {
    currentAdmin = requireRole('Administrador', 'menu.html');
    if (!currentAdmin) return;

    cargarConductores();
    cargarUsuarios();
    cargarRutasAdmin();
    cargarViajesActivosAdmin();
    cargarHistorialAdmin();

    const btnAsignar = document.getElementById('btn-asignar-placa');
    if (btnAsignar) {
        btnAsignar.addEventListener('click', (e) => {
            e.preventDefault();
            asignarPlacas();
        });
    }

    const btnBorrarHistorial = document.getElementById('btn-borrar-historial');
    if (btnBorrarHistorial) {
        btnBorrarHistorial.addEventListener('click', (e) => {
            e.preventDefault();
            borrarTodoHistorial();
        });
    }
});
