// ============================================================
// common.js - Utilidades compartidas por todas las páginas
// UniTransporte Web
//
// Toda la comunicación con el backend pasa por UN SOLO archivo:
// api.php (que a su vez usa database.php para la conexión a MySQL).
// ============================================================

// Como el frontend vive en el mismo servidor Apache/PHP (mismo
// puerto 8080 que expone docker-compose), usamos una ruta relativa.
const API_ENDPOINT = 'api.php';

// --- Manejo de usuario (persistido en localStorage) ---
function getCurrentUser() {
    const saved = localStorage.getItem('usuario');
    if (!saved) return null;
    try {
        return JSON.parse(saved);
    } catch (e) {
        console.error('Error parsing saved user:', e);
        localStorage.removeItem('usuario');
        return null;
    }
}

function setCurrentUser(user) {
    localStorage.setItem('usuario', JSON.stringify(user));
}

function logout() {
    localStorage.removeItem('usuario');
    window.location.href = 'index.html';
}

// --- Alertas ---
function showAlert(title, message, onOk) {
    alert(`[${title}]\n\n${message}`);
    if (onOk) onOk();
}

// --- Fetch genérico con manejo de errores ---
async function safeFetch(url, options = {}) {
    try {
        console.log(`🔄 Haciendo petición a: ${url}`);
        const response = await fetch(url, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...options.headers
            }
        });

        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status} ${response.statusText}`);
        }

        const text = await response.text();
        console.log('📄 Respuesta cruda:', text);

        if (!text.trim()) {
            throw new Error('El servidor respondió con una respuesta vacía');
        }

        let data;
        try {
            data = JSON.parse(text);
        } catch (parseError) {
            console.error('❌ Error parseando JSON:', parseError);
            throw new Error('El servidor respondió con datos no válidos');
        }

        return data;

    } catch (error) {
        console.error(`❌ Error en petición a ${url}:`, error);

        if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
            throw new Error('No se pudo conectar con el servidor. Verifica que:\n\n1. El contenedor Docker (php-apache) esté ejecutándose\n2. La URL sea correcta\n3. No haya problemas de CORS');
        }

        throw error;
    }
}

// --- Llamada estándar a api.php: siempre POST con { action, ...data } ---
async function apiCall(action, data = {}) {
    return await safeFetch(API_ENDPOINT, {
        method: 'POST',
        body: JSON.stringify({ action, ...data })
    });
}

// --- Guardas de acceso ---
function requireAuth(redirectTo = 'login.html') {
    const user = getCurrentUser();
    if (!user) {
        showAlert('Sesión', 'Debes iniciar sesión primero.');
        window.location.href = redirectTo;
        return null;
    }
    return user;
}

function requireRole(role, redirectTo = 'menu.html') {
    const user = requireAuth();
    if (!user) return null;
    if (user.rol !== role) {
        const nombres = { Conductor: 'conductores', Pasajero: 'pasajeros', Administrador: 'administradores' };
        const nombreRol = nombres[role] || role;
        showAlert('Permisos', `Solo los ${nombreRol} pueden acceder a esta función.`);
        window.location.href = redirectTo;
        return null;
    }
    return user;
}

// --- Validaciones compartidas ---
function validateRegistration(data) {
    const emailRegex = /@.*\.(edu|mx|com)$/;
    if (!emailRegex.test(data.correo)) {
        showAlert('Validación', 'Correo no válido. Debe terminar en .edu, .mx o .com');
        return false;
    }
    if (data.control.length < 4) {
        showAlert('Validación', 'La matrícula debe tener al menos 4 caracteres.');
        return false;
    }
    if (data.clave.length < 6) {
        showAlert('Seguridad', 'La contraseña debe tener mínimo 6 caracteres.');
        return false;
    }
    if (data.clave !== data.confirmar) {
        showAlert('Seguridad', 'Las contraseñas no coinciden.');
        return false;
    }
    return true;
}

function isOnlyLettersSpaces(s) {
    return /^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+$/.test(s);
}

function isValidExpiry(s) {
    if (!/^\d{2}\/\d{2}$/.test(s)) return false;
    const mm = parseInt(s.substring(0, 2), 10);
    const yy = parseInt(s.substring(3, 5), 10);
    if (mm < 1 || mm > 12) return false;
    const now = new Date();
    const nowYY = now.getFullYear() % 100;
    const nowMM = now.getMonth() + 1;
    if (yy < nowYY) return false;
    if (yy == nowYY && mm < nowMM) return false;
    return true;
}

function luhnValid(number16) {
    let sum = 0;
    for (let i = 0; i < number16.length; i++) {
        let n = parseInt(number16[number16.length - 1 - i], 10);
        if (i % 2 === 1) {
            n *= 2;
            if (n > 9) n -= 9;
        }
        sum += n;
    }
    return sum % 10 === 0;
}

// --- Botón atrás: navega al historial del navegador, o a menu.html si no hay historial propio ---
function initBackButton(fallback = 'menu.html') {
    document.querySelectorAll('.back-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = fallback;
            }
        });
    });
}

// --- Botones de cerrar sesión ---
function initLogoutButtons() {
    document.querySelectorAll('.logout-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            logout();
        });
    });
}

// --- Helpers de querystring (para pasar datos entre páginas, ej: resultados.html) ---
function getQueryParam(name) {
    const params = new URLSearchParams(window.location.search);
    return params.get(name) || '';
}

// Inicialización común en cada página
document.addEventListener('DOMContentLoaded', () => {
    initBackButton();
    initLogoutButtons();
});

// Manejo de errores global
window.addEventListener('error', (e) => {
    console.error('Error global:', e.error);
});
window.addEventListener('unhandledrejection', (e) => {
    console.error('Promise rechazada no manejada:', e.reason);
});

// ============================================================
// HELPERS DE PAGO (punto 3)
// ============================================================

// ¿El estado de pago ya cuenta como "pagado"?
function pagoEstaPagado(estado) {
    return estado === 'completado' || estado === 'confirmado';
}

// Texto + color para mostrar el estado de pago de un pasajero.
function etiquetaEstadoPago(metodo, estado) {
    if (!estado) {
        return { texto: 'Sin pago registrado', color: '#E53935' };
    }
    const esTarjeta = (metodo || '').toLowerCase() === 'tarjeta';

    switch (estado) {
        case 'completado':
            return { texto: esTarjeta ? 'Pagado con tarjeta' : 'Pagado', color: '#4CAF50' };
        case 'confirmado':
            return { texto: 'Pago en efectivo confirmado', color: '#4CAF50' };
        case 'pendiente_confirmacion':
            return { texto: 'Efectivo: esperando que el conductor confirme', color: '#FF8A00' };
        case 'rechazado':
            return { texto: 'Pago rechazado', color: '#E53935' };
        case 'cancelado':
            return { texto: 'Pago cancelado', color: '#E53935' };
        default:
            return { texto: estado, color: '#666666' };
    }
}

// ============================================================
// MENSAJERÍA PRIVADA PASAJERO <-> CONDUCTOR (punto 9)
//
// Widget compartido: se usa desde mi-viaje.html (pasajero) y
// desde mis-viajes.html (conductor). Se apoya en las acciones
// 'enviarMensaje' y 'getMensajesViaje' de api.php.
// ============================================================

let _chatIdViaje = null;
let _chatTimer = null;

// Evita inyección de HTML al pintar lo que escribió el otro usuario.
function escapeHtml(texto) {
    const div = document.createElement('div');
    div.textContent = (texto === null || texto === undefined) ? '' : String(texto);
    return div.innerHTML;
}

function cerrarChat() {
    if (_chatTimer) {
        clearInterval(_chatTimer);
        _chatTimer = null;
    }
    _chatIdViaje = null;
    const panel = document.getElementById('chat-panel');
    if (panel) panel.remove();
}

function abrirChat(idViaje, nombreOtra) {
    cerrarChat();
    _chatIdViaje = idViaje;

    const panel = document.createElement('div');
    panel.id = 'chat-panel';
    panel.innerHTML = `
        <div class="chat-header">
            <span class="material-symbols-rounded">chat</span>
            <strong>${escapeHtml(nombreOtra || 'Mensajes')}</strong>
            <button type="button" id="chat-cerrar" class="chat-cerrar" title="Cerrar">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div id="chat-mensajes" class="chat-mensajes">
            <div class="chat-vacio">Cargando mensajes...</div>
        </div>
        <div class="chat-envio">
            <input type="text" id="chat-input" maxlength="500" placeholder="Escribe un mensaje...">
            <button type="button" id="chat-enviar" title="Enviar">
                <span class="material-symbols-rounded">send</span>
            </button>
        </div>
    `;
    document.body.appendChild(panel);

    document.getElementById('chat-cerrar').addEventListener('click', cerrarChat);
    document.getElementById('chat-enviar').addEventListener('click', () => enviarMensajeChat(idViaje));
    document.getElementById('chat-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            enviarMensajeChat(idViaje);
        }
    });

    cargarMensajesChat(idViaje);
    // Refresco ligero para ver los mensajes nuevos de la contraparte
    _chatTimer = setInterval(() => cargarMensajesChat(idViaje), 5000);
}

async function cargarMensajesChat(idViaje) {
    const cont = document.getElementById('chat-mensajes');
    if (!cont) return;

    const user = getCurrentUser();
    if (!user) return;

    try {
        const data = await apiCall('getMensajesViaje', { id_viaje: idViaje, userEmail: user.correo });

        if (data.status !== 'success') {
            cont.innerHTML = `<div class="chat-error">${escapeHtml(data.message || 'Error al cargar los mensajes')}</div>`;
            return;
        }

        if (data.puede_enviar === false) {
            const input = document.getElementById('chat-input');
            const boton = document.getElementById('chat-enviar');
            if (input) { input.disabled = true; input.placeholder = 'El viaje ya no está activo'; }
            if (boton) boton.disabled = true;
        }

        if (!data.mensajes || data.mensajes.length === 0) {
            cont.innerHTML = `<div class="chat-vacio">Todavía no hay mensajes. Escribe el primero.</div>`;
            return;
        }

        // Solo auto-scroll si el usuario ya estaba viendo el final
        const alFinal = (cont.scrollHeight - cont.scrollTop - cont.clientHeight) < 40;

        cont.innerHTML = data.mensajes.map(m => {
            const mio = Number(m.id_remitente) === Number(data.mi_id_usuario);
            const hora = (m.fecha_hora || '').substring(11, 16);
            return `
                <div class="chat-burbuja ${mio ? 'mio' : 'otro'}">
                    ${mio ? '' : `<div class="chat-remitente">${escapeHtml(m.nombre_remitente)}</div>`}
                    <div class="chat-texto">${escapeHtml(m.contenido)}</div>
                    <div class="chat-hora">${hora}</div>
                </div>
            `;
        }).join('');

        if (alFinal) cont.scrollTop = cont.scrollHeight;
    } catch (error) {
        cont.innerHTML = `<div class="chat-error">${escapeHtml(error.message)}</div>`;
    }
}

async function enviarMensajeChat(idViaje) {
    const input = document.getElementById('chat-input');
    if (!input) return;

    const texto = input.value.trim();
    if (!texto) return;

    const user = getCurrentUser();
    if (!user) return;

    try {
        const data = await apiCall('enviarMensaje', {
            id_viaje: idViaje,
            userEmail: user.correo,
            contenido: texto
        });

        if (data.status !== 'success') {
            showAlert('❌ Error', data.message || 'No se pudo enviar el mensaje');
            return;
        }

        input.value = '';
        await cargarMensajesChat(idViaje);

        const cont = document.getElementById('chat-mensajes');
        if (cont) cont.scrollTop = cont.scrollHeight;
    } catch (error) {
        showAlert('❌ Error', error.message);
    }
}
