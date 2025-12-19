<?php
include 'conexion.php';

$sql = "SELECT * FROM usuario";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

echo "<h2>Usuarios registrados</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Nombre</th><th>Correo</th><th>Rol</th></tr>";

while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    echo "<tr>";
    echo "<td>{$row['id_usuario']}</td>";
    echo "<td>{$row['nombre']}</td>";
    echo "<td>{$row['correo']}</td>";
    echo "<td>{$row['rol']}</td>";
    echo "</tr>";
}

echo "</table>";
?>
