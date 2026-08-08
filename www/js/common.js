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
        const nombreRol = role === 'Conductor' ? 'conductores' : 'pasajeros';
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
