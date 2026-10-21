<?php
/**
 * index.php
 * ----------------------------------------------------------------
 * Punto de entrada del aplicativo.
 *
 * Antes este archivo cerraba la sesión (era un clon de logout.php),
 * de modo que entrar por la raíz expulsaba al usuario al login.
 * Ahora redirige al panel si hay sesión activa y al login si no.
 * ----------------------------------------------------------------
 */
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['id_usuario'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
