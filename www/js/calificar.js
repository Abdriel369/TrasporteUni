// calificar.js - calificación del último viaje completado sin evaluar

let currentUser = null;

async function cargarPantallaCalificacion() {
    const container = document.getElementById('rate-trip-content');
    if (!container) return;

    container.innerHTML = `
        <div class="list-tile">
            <span class="list-tile-text">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                Buscando viajes pendientes por calificar...
            </span>
        </div>
    `;

    try {
        const data = await apiCall('getLastDriverToRate', { userEmail: currentUser.correo });

        if (data.status === "success") {
            if (data.tiene_viajes && data.viaje) {
                const viaje = data.viaje;

                container.innerHTML = `
                    <div class="hero-card">
                        <div class="hero-card-icon-bg">
                            <span class="material-symbols-rounded">star_rate</span>
                        </div>
                        <div class="hero-card-text">
                            <h2>Calificar Viaje</h2>
                            <p>¿Cómo calificarías tu viaje con ${viaje.nombre_conductor}?</p>
                        </div>
                    </div>

                    <div class="list-section">
                        <div class="list-tile">
                            <div class="list-tile-text">
                                <strong>Ruta:</strong> ${viaje.origen} → ${viaje.destino}<br>
                                <strong>Horario:</strong> ${viaje.horario}<br>
                                <strong>Fecha:</strong> ${new Date(viaje.fecha_viaje).toLocaleDateString()}
                            </div>
                        </div>
                    </div>

                    <div class="section-header">Calificación</div>
                    <div style="text-align: center; margin: 20px 0;">
                        <div id="stars-container" style="font-size: 2rem; color: #FF8A00;">
                            <span class="star" data-rating="1" style="cursor: pointer; margin: 0 5px;">☆</span>
                            <span class="star" data-rating="2" style="cursor: pointer; margin: 0 5px;">☆</span>
                            <span class="star" data-rating="3" style="cursor: pointer; margin: 0 5px;">☆</span>
                            <span class="star" data-rating="4" style="cursor: pointer; margin: 0 5px;">☆</span>
                            <span class="star" data-rating="5" style="cursor: pointer; margin: 0 5px;">☆</span>
                        </div>
                        <div id="rating-text" style="margin-top: 10px; color: #666;">Selecciona una calificación</div>
                    </div>

                    <div class="field-container">
                        <span class="material-symbols-rounded">chat</span>
                        <textarea id="comentario-calificacion" class="capsule-field capsule-field-with-icon"
                                  placeholder="Comentario opcional (máx. 200 caracteres)"
                                  rows="3" maxlength="200"></textarea>
                    </div>

                    <div style="height: 20px;"></div>

                    <a href="#" id="btn-enviar-calificacion" class="cta-button">
                        <span class="material-symbols-rounded">send</span>
                        <span>Enviar Calificación</span>
                    </a>
                `;

                let ratingSeleccionado = 0;
                const stars = container.querySelectorAll('.star');
                const ratingText = document.getElementById('rating-text');

                stars.forEach(star => {
                    star.addEventListener('click', () => {
                        ratingSeleccionado = parseInt(star.getAttribute('data-rating'));

                        stars.forEach(s => {
                            const rating = parseInt(s.getAttribute('data-rating'));
                            s.textContent = rating <= ratingSeleccionado ? '★' : '☆';
                        });

                        const textos = ["Muy malo", "Malo", "Regular", "Bueno", "Excelente"];
                        ratingText.textContent = textos[ratingSeleccionado - 1];
                    });
                });

                const btnEnviar = document.getElementById('btn-enviar-calificacion');
                btnEnviar.addEventListener('click', async (e) => {
                    e.preventDefault();

                    if (ratingSeleccionado === 0) {
                        showAlert('Error', 'Por favor selecciona una calificación');
                        return;
                    }

                    await enviarCalificacion({
                        id_viaje: viaje.id_viaje,
                        calificacion: ratingSeleccionado,
                        comentario: document.getElementById('comentario-calificacion').value
                    });
                });

            } else {
                container.innerHTML = `
                    <div class="hero-card">
                        <div class="hero-card-icon-bg">
                            <span class="material-symbols-rounded">sentiment_satisfied</span>
                        </div>
                        <div class="hero-card-text">
                            <h2>No hay viajes por calificar</h2>
                            <p>${data.message}</p>
                            <p style="font-size: 14px; color: #666; margin-top: 10px;">
                                Completa un viaje como pasajero para poder calificar al conductor.
                            </p>
                        </div>
                    </div>
                `;
            }
        } else {
            container.innerHTML = `<p class="text-danger">Error: ${data.message}</p>`;
        }
    } catch (error) {
        console.error('Error cargando calificación:', error);
        container.innerHTML = `
            <div class="alert alert-danger">
                <strong>Error:</strong> ${error.message}
                <br><br>
                <small>Verifica que haya viajes completados en la base de datos.</small>
            </div>
        `;
    }
}

async function enviarCalificacion(datos) {
    try {
        const result = await apiCall('submitRating', { ...datos, userEmail: currentUser.correo });

        if (result.status === 'success') {
            showAlert('✅ Éxito', result.message, () => {
                window.location.href = 'menu.html';
            });
        } else {
            showAlert('❌ Error', result.message || 'Error al enviar la calificación');
        }
    } catch (error) {
        console.error('Error enviando calificación:', error);
        showAlert('❌ Error', error.message);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    currentUser = requireAuth('login.html');
    if (!currentUser) return;

    cargarPantallaCalificacion();
});
