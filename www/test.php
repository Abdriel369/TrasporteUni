<?php
include 'conexion.php';
if($conn){
    echo "Conexión exitosa a SQL Server";
} else {
    echo "Error en la conexión";
}
?>

