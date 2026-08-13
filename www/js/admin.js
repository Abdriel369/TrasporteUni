// admin.js - lógica del panel de administrador

let currentAdmin = null;

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

function badgeEstado(estado) {
    const clase = 'badge-' + (estado || '').toLowerCase();
    return `<span class="badge-estado ${clase}">${estado || 'N/A'}</span>`;
}

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
// VIAJES
// ============================================================

async function cargarViajesAdmin() {
    const tbody = document.getElementById('tbody-viajes');
    tbody.innerHTML = `<tr><td colspan="8">Cargando viajes...</td></tr>`;

    try {
        const data = await apiCall('adminGetAllTrips');

        if (data.status !== 'success') {
            throw new Error(data.message || 'Error al cargar viajes');
        }

        if (data.viajes.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8">No hay viajes registrados.</td></tr>`;
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
                    <button class="btn-mini cancelar" data-cancelar-viaje="${v.id_viaje}" ${v.estado !== 'pendiente' ? 'disabled' : ''}>Cancelar</button>
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
                cargarViajesAdmin();
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
// INIT
// ============================================================

document.addEventListener('DOMContentLoaded', () => {
    currentAdmin = requireRole('Administrador', 'menu.html');
    if (!currentAdmin) return;

    cargarConductores();
    cargarRutasAdmin();
    cargarViajesAdmin();

    const btnAsignar = document.getElementById('btn-asignar-placa');
    if (btnAsignar) {
        btnAsignar.addEventListener('click', (e) => {
            e.preventDefault();
            asignarPlacas();
        });
    }
});
