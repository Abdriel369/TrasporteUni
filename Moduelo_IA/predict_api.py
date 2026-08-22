# ============================================================
# predict_api.py
# Microservicio de predicción de demanda para UniTransporte.
#
# Carga el pipeline entrenado (modelo_gradient_boosting.joblib) y
# expone un endpoint HTTP para que api.php le pregunte, al publicar
# una ruta, qué tan probable es que esa ruta tenga demanda.
#
# IMPORTANTE (heredado de Moduelo_IA/Filtrado_Dataset.ipynb):
# Este modelo se entrenó con un dataset de PRÁCTICA (proxy: viajes
# de Uber en Nueva York 2014), no con datos reales de UniTransporte.
# Se usa aquí como demostración funcional del flujo "IA -> mensaje ->
# decisión del conductor", tal como pidió el equipo. Cuando existan
# datos reales de la tabla `ruta`/`viaje`, hay que re-entrenar el
# modelo con esos datos (ver notas finales del notebook).
# ============================================================

import os
import warnings
from datetime import datetime

import joblib
import numpy as np
import pandas as pd
import holidays
from flask import Flask, jsonify, request
from sklearn.exceptions import InconsistentVersionWarning

# El .joblib se entrenó con una versión de scikit-learn distinta a la
# instalada aquí. Sigue funcionando (se probó y las predicciones
# coinciden), así que solo silenciamos el aviso para no ensuciar los
# logs del contenedor en cada arranque.
warnings.filterwarnings("ignore", category=InconsistentVersionWarning)

app = Flask(__name__)

MODEL_PATH = os.environ.get("MODEL_PATH", "modelo_gradient_boosting.joblib")
modelo = joblib.load(MODEL_PATH)

# --------------------------------------------------------------
# Umbrales de demanda (percentiles P25 / P50 / P75).
#
# En el notebook original estos umbrales salen de y_train (datos
# reales de entrenamiento), pero ese CSV no se distribuye con el
# proyecto (se descargaba desde Google Drive / Kaggle). Aquí se
# generaron simulando el rango de combinaciones posibles de
# hora/día/mes y tomando los percentiles de las predicciones del
# modelo, para tener un punto de referencia razonable.
#
# Se pueden sobreescribir con variables de entorno sin tocar código,
# por ejemplo cuando se re-entrene el modelo con datos reales.
# --------------------------------------------------------------
P25 = float(os.environ.get("DEMANDA_P25", 18.0))
P50 = float(os.environ.get("DEMANDA_P50", 41.0))
P75 = float(os.environ.get("DEMANDA_P75", 68.0))

# Festivos de México (la app es para una universidad en Morelos, MX)
mx_holidays = holidays.Mexico()

FEATURE_ORDER = [
    "hora", "dia_semana", "mes", "es_fin_de_semana",
    "hora_sin", "hora_cos", "es_festivo", "tipo_zona_encoded",
]


def construir_features(fecha_str: str, hora_str: str) -> pd.DataFrame:
    """Convierte fecha (YYYY-MM-DD) y hora (HH:MM) en las columnas
    que el pipeline espera, exactamente en el mismo orden con el
    que se entrenó (ver Estudio_de_los_datos.ipynb)."""

    fecha_dt = datetime.strptime(fecha_str, "%Y-%m-%d")
    hh, mm = (hora_str.split(":") + ["0"])[:2]
    hora = int(hh)

    dia_semana = fecha_dt.weekday()          # 0 = lunes ... 6 = domingo
    mes = fecha_dt.month
    es_fin_de_semana = 1 if dia_semana >= 5 else 0
    hora_sin = np.sin(2 * np.pi * hora / 24)
    hora_cos = np.cos(2 * np.pi * hora / 24)
    es_festivo = 1 if fecha_dt.date() in mx_holidays else 0

    # 'tipo_zona' en el dataset de entrenamiento es una columna
    # CONSTANTE ("Universidad, zona de práctica"), así que su
    # versión codificada siempre vale 0 y el modelo nunca aprendió
    # variación real por zona. Se deja fija en 0 para ser
    # consistentes con cómo se entrenó.
    tipo_zona_encoded = 0

    fila = {
        "hora": hora,
        "dia_semana": dia_semana,
        "mes": mes,
        "es_fin_de_semana": es_fin_de_semana,
        "hora_sin": hora_sin,
        "hora_cos": hora_cos,
        "es_festivo": es_festivo,
        "tipo_zona_encoded": tipo_zona_encoded,
    }
    return pd.DataFrame([fila], columns=FEATURE_ORDER)


def interpretar(valor: float) -> tuple[str, str]:
    """Traduce el número de demanda predicho a un mensaje para el
    conductor y a una recomendación binaria publicar/cancelar."""
    if valor >= P75:
        return "Muy probable que el viaje se realice (demanda alta prevista).", "publicar"
    elif valor >= P50:
        return "Probable que el viaje se realice (demanda media-alta prevista).", "publicar"
    elif valor >= P25:
        return "Poco probable que el viaje se realice (demanda media-baja prevista).", "publicar"
    else:
        return "Improbable que el viaje se realice (demanda baja prevista).", "cancelar"


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok"})


@app.route("/predecir", methods=["POST"])
def predecir():
    data = request.get_json(silent=True) or {}
    fecha = data.get("fecha")
    horario = data.get("horario")

    if not fecha or not horario:
        return jsonify({"status": "error", "message": "Faltan 'fecha' y/o 'horario'"}), 400

    try:
        X = construir_features(fecha, horario)
        valor = float(modelo.predict(X)[0])
        mensaje, recomendacion = interpretar(valor)
    except Exception as e:
        return jsonify({"status": "error", "message": f"Error al predecir: {e}"}), 500

    return jsonify({
        "status": "ok",
        "prediccion_valor": round(valor, 2),
        "mensaje": mensaje,
        "recomendacion": recomendacion,  # 'publicar' | 'cancelar'
        "umbrales": {"p25": P25, "p50": P50, "p75": P75},
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
