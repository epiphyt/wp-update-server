<?php
require __DIR__ . '/loader.php';
$server = new Epiphyt_Server();
$server->handleRequest( $_REQUEST );
