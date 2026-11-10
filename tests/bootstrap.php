<?php

/**
 * Preparación de las pruebas: carga la aplicación y genera un par de claves
 * RSA temporales para firmar los tokens de prueba.
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/Support/TestCase.php';

putenv('APP_NOMBRE=pruebas');
