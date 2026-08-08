// buscar-viaje.js - formulario de búsqueda de viajes

document.addEventListener('DOMContentLoaded', () => {
    const user = requireAuth('login.html');
    if (!user) return;

    const btnSearchSubmit = document.getElementById('btn-search-submit');
    if (btnSearchSubmit) {
        btnSearchSubmit.addEventListener('click', (e) => {
            e.preventDefault();

            const origen = document.getElementById('search-origen').value.trim();
            const destino = document.getElementById('search-destino').value.trim();

            const params = new URLSearchParams();
            if (origen) params.set('origen', origen);
            if (destino) params.set('destino', destino);

            window.location.href = `resultados.html?${params.toString()}`;
        });
    }
});
