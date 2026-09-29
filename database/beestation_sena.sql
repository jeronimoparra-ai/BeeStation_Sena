-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 28-09-2026 a las 21:18:10
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `beestation_sena`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alerta`
--

CREATE TABLE `alerta` (
  `id_alerta` int(11) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `nivel` tinyint(4) NOT NULL,
  `mensaje` varchar(255) NOT NULL,
  `fecha_hora` datetime DEFAULT current_timestamp(),
  `estado` enum('activa','atendida','descartada') DEFAULT 'activa',
  `id_indicador` bigint(20) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `apiario`
--

CREATE TABLE `apiario` (
  `id_apiario` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `ubicacion` varchar(255) DEFAULT NULL,
  `municipio` varchar(100) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `id_usuario` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `apiario`
--

INSERT INTO `apiario` (`id_apiario`, `nombre`, `ubicacion`, `municipio`, `fecha_registro`, `id_usuario`) VALUES
(1, 'Apiario Norte', 'Centro Minero Ambiental SENA', 'El Bagre, Antioquia', '2026-08-25 13:54:43', 1),
(2, 'Apiario Escuela SENA', 'Centro de Formación Ambiental', 'El Bagre, Antioquia', '2026-08-28 17:40:18', 2);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `calibracion`
--

CREATE TABLE `calibracion` (
  `id_calibracion` int(11) NOT NULL,
  `fecha_calibracion` datetime DEFAULT current_timestamp(),
  `valor_referencia` float DEFAULT NULL,
  `valor_medido` float DEFAULT NULL,
  `factor_correccion` float DEFAULT 0,
  `metodo` varchar(100) DEFAULT NULL,
  `responsable` varchar(100) DEFAULT NULL,
  `resultado` varchar(50) DEFAULT NULL,
  `proxima_calibracion` date DEFAULT NULL,
  `id_sensor` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `colmena`
--

CREATE TABLE `colmena` (
  `id_colmena` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `especie` varchar(100) DEFAULT 'Apis mellifera',
  `fecha_instalacion` date DEFAULT NULL,
  `estado` enum('activa','inactiva','en_revision') DEFAULT 'activa',
  `token_vinculacion` varchar(64) NOT NULL,
  `intentos_fallidos_token` int(11) DEFAULT 0,
  `token_revocado` tinyint(1) DEFAULT 0,
  `id_apiario` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `colmena`
--

INSERT INTO `colmena` (`id_colmena`, `nombre`, `especie`, `fecha_instalacion`, `estado`, `token_vinculacion`, `intentos_fallidos_token`, `token_revocado`, `id_apiario`) VALUES
(1, 'Alpha-01', 'Apis mellifera', '2026-08-25', 'activa', 'BS-ALPHA01-1A2B3C', 0, 0, 1),
(2, 'Colmena Valle del Sur N.001', 'Apis mellifera', '2026-08-28', 'activa', 'BS-COLMENAV-729D74', 0, 0, 2);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `indicador`
--

CREATE TABLE `indicador` (
  `id_indicador` bigint(20) NOT NULL,
  `tipo` varchar(30) NOT NULL,
  `valor` float NOT NULL,
  `fecha_hora` datetime DEFAULT current_timestamp(),
  `descripcion` varchar(255) DEFAULT NULL,
  `estado_colonia` varchar(30) DEFAULT NULL,
  `id_colmena` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `indicador`
--

INSERT INTO `indicador` (`id_indicador`, `tipo`, `valor`, `fecha_hora`, `descripcion`, `estado_colonia`, `id_colmena`) VALUES
(1, 'IBB', 71, '2026-08-29 17:31:29', 'Índice de Bienestar Bioclimático calculado automáticamente', 'Bueno', 1),
(2, 'EV', 10.7, '2026-08-29 17:31:29', 'Eficiencia de Ventilación', 'Deficiente', 1),
(3, 'H_MIEL', 19.7, '2026-08-29 17:31:29', 'Humedad estimada de la miel', 'Cerca de madurar', 1),
(4, 'H_MIEL', 12, '2026-09-02 11:38:34', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(5, 'H_MIEL', 12, '2026-09-02 11:39:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(6, 'H_MIEL', 12, '2026-09-02 11:40:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(7, 'H_MIEL', 12, '2026-09-02 11:41:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(8, 'H_MIEL', 12, '2026-09-02 11:42:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(9, 'H_MIEL', 12, '2026-09-02 11:43:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(10, 'H_MIEL', 12, '2026-09-02 11:44:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(11, 'H_MIEL', 12, '2026-09-02 11:45:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(12, 'H_MIEL', 12, '2026-09-02 11:46:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(13, 'H_MIEL', 12, '2026-09-02 11:47:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(14, 'H_MIEL', 12, '2026-09-02 11:48:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(15, 'H_MIEL', 12, '2026-09-02 11:49:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(16, 'H_MIEL', 12, '2026-09-02 11:50:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(17, 'H_MIEL', 12, '2026-09-02 11:51:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(18, 'H_MIEL', 12, '2026-09-02 11:52:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(19, 'H_MIEL', 12, '2026-09-02 11:53:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(20, 'H_MIEL', 12, '2026-09-02 11:54:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(21, 'H_MIEL', 12, '2026-09-02 11:55:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(22, 'H_MIEL', 12, '2026-09-02 11:56:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(23, 'H_MIEL', 12, '2026-09-02 11:57:32', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(24, 'H_MIEL', 12, '2026-09-02 11:58:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(25, 'H_MIEL', 12, '2026-09-02 11:59:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(26, 'H_MIEL', 12, '2026-09-02 12:00:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(27, 'H_MIEL', 12, '2026-09-02 12:01:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(28, 'H_MIEL', 12, '2026-09-02 12:02:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(29, 'H_MIEL', 12, '2026-09-02 12:03:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(30, 'H_MIEL', 12, '2026-09-02 12:04:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(31, 'H_MIEL', 12, '2026-09-02 12:05:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(32, 'H_MIEL', 12, '2026-09-02 12:06:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(33, 'H_MIEL', 12, '2026-09-02 12:07:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(34, 'H_MIEL', 12, '2026-09-02 12:08:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(35, 'H_MIEL', 12, '2026-09-02 12:09:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(36, 'H_MIEL', 12, '2026-09-02 12:10:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(37, 'H_MIEL', 12, '2026-09-02 12:11:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(38, 'H_MIEL', 12, '2026-09-02 12:12:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(39, 'H_MIEL', 12, '2026-09-02 12:13:33', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(40, 'H_MIEL', 12, '2026-09-02 12:20:21', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(41, 'H_MIEL', 12, '2026-09-02 12:21:21', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(42, 'H_MIEL', 12, '2026-09-02 12:22:21', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(43, 'H_MIEL', 12, '2026-09-02 12:23:22', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(44, 'H_MIEL', 12, '2026-09-02 12:24:22', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(45, 'H_MIEL', 12, '2026-09-02 12:25:22', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(46, 'H_MIEL', 12, '2026-09-02 12:26:22', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(47, 'H_MIEL', 12, '2026-09-02 12:27:22', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(48, 'H_MIEL', 12, '2026-09-02 12:28:22', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(49, 'H_MIEL', 12, '2026-09-02 12:29:22', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(50, 'H_MIEL', 12, '2026-09-16 12:01:19', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(51, 'H_MIEL', 12, '2026-09-16 12:02:17', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(52, 'H_MIEL', 12, '2026-09-16 12:03:17', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(53, 'H_MIEL', 12, '2026-09-16 12:04:17', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(54, 'H_MIEL', 12, '2026-09-16 12:05:17', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(55, 'H_MIEL', 12, '2026-09-16 14:56:11', 'Humedad estimada de la miel', 'Lista para cosecha', 2),
(56, 'H_MIEL', 12, '2026-09-16 14:57:10', 'Humedad estimada de la miel', 'Lista para cosecha', 2);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `intento_vinculacion`
--

CREATE TABLE `intento_vinculacion` (
  `id_intento` bigint(20) NOT NULL,
  `token_recibido` varchar(50) NOT NULL,
  `ip_origen` varchar(45) DEFAULT NULL,
  `resultado` enum('token_invalido','sin_apikey','ok') NOT NULL,
  `fecha_hora` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `intento_vinculacion`
--

INSERT INTO `intento_vinculacion` (`id_intento`, `token_recibido`, `ip_origen`, `resultado`, `fecha_hora`) VALUES
(1, 'BS-ALPHA01-1A2B3C', '::1', 'ok', '2026-08-29 17:31:15'),
(2, 'BS-ALPHA01-1A2B3C', '::1', 'ok', '2026-08-29 17:31:29'),
(3, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:38:34'),
(4, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:39:32'),
(5, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:40:32'),
(6, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:41:32'),
(7, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:42:32'),
(8, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:43:32'),
(9, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:44:32'),
(10, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:45:32'),
(11, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:46:32'),
(12, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:47:32'),
(13, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:48:32'),
(14, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:49:32'),
(15, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:50:32'),
(16, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:51:32'),
(17, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:52:32'),
(18, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:53:32'),
(19, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:54:32'),
(20, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:55:32'),
(21, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:56:32'),
(22, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:57:32'),
(23, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:58:33'),
(24, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 11:59:33'),
(25, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:00:33'),
(26, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:01:33'),
(27, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:02:33'),
(28, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:03:33'),
(29, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:04:33'),
(30, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:05:33'),
(31, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:06:33'),
(32, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:07:33'),
(33, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:08:33'),
(34, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:09:33'),
(35, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:10:33'),
(36, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:11:33'),
(37, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:12:33'),
(38, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:13:33'),
(39, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:19:26'),
(40, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:20:21'),
(41, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:21:21'),
(42, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:22:21'),
(43, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:23:21'),
(44, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:24:22'),
(45, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:25:22'),
(46, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:26:22'),
(47, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:27:22'),
(48, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:28:22'),
(49, 'BS-COLMENAV-729D74', '192.168.1.35', 'ok', '2026-09-02 12:29:22'),
(50, 'BS-COLMENAV-729D74', '192.168.1.38', 'ok', '2026-09-16 12:00:29'),
(51, 'BS-COLMENAV-729D74', '192.168.1.38', 'ok', '2026-09-16 12:01:19'),
(52, 'BS-COLMENAV-729D74', '192.168.1.38', 'ok', '2026-09-16 12:02:17'),
(53, 'BS-COLMENAV-729D74', '192.168.1.38', 'ok', '2026-09-16 12:03:17'),
(54, 'BS-COLMENAV-729D74', '192.168.1.38', 'ok', '2026-09-16 12:04:17'),
(55, 'BS-COLMENAV-729D74', '192.168.1.38', 'ok', '2026-09-16 12:05:17'),
(56, 'BS-COLMENAV-729D74', '192.168.1.38', 'ok', '2026-09-16 14:56:10'),
(57, 'BS-COLMENAV-729D74', '192.168.1.38', 'ok', '2026-09-16 14:57:10');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `lectura`
--

CREATE TABLE `lectura` (
  `id_lectura` bigint(20) NOT NULL,
  `valor_bruto` float NOT NULL,
  `valor_calibrado` float NOT NULL,
  `unidad` varchar(20) DEFAULT NULL,
  `fecha_hora` datetime DEFAULT current_timestamp(),
  `es_valida` tinyint(1) DEFAULT 1,
  `id_sensor` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `lectura`
--

INSERT INTO `lectura` (`id_lectura`, `valor_bruto`, `valor_calibrado`, `unidad`, `fecha_hora`, `es_valida`, `id_sensor`) VALUES
(1, 34.5, 34.5, NULL, '2026-08-29 17:31:29', 1, 1),
(2, 62.1, 62.1, NULL, '2026-08-29 17:31:29', 1, 3),
(3, 25.3, 25.3, NULL, '2026-08-29 17:31:29', 1, 4),
(4, 0.45, 0.45, NULL, '2026-08-29 17:31:29', 1, 5),
(5, 320, 320, NULL, '2026-08-29 17:31:29', 1, 6),
(6, 0, 0, NULL, '2026-09-02 11:38:34', 1, 8),
(7, 0, 0, NULL, '2026-09-02 11:38:34', 1, 10),
(8, 0, 0, NULL, '2026-09-02 11:38:34', 1, 11),
(9, 0, 0, NULL, '2026-09-02 11:38:34', 1, 12),
(10, 0, 0, NULL, '2026-09-02 11:39:32', 1, 8),
(11, 0, 0, NULL, '2026-09-02 11:39:32', 1, 10),
(12, 0, 0, NULL, '2026-09-02 11:39:32', 1, 11),
(13, 0, 0, NULL, '2026-09-02 11:39:32', 1, 12),
(14, 0, 0, NULL, '2026-09-02 11:40:32', 1, 8),
(15, 0, 0, NULL, '2026-09-02 11:40:32', 1, 10),
(16, 0, 0, NULL, '2026-09-02 11:40:32', 1, 11),
(17, 0, 0, NULL, '2026-09-02 11:40:32', 1, 12),
(18, 0, 0, NULL, '2026-09-02 11:41:32', 1, 8),
(19, 0, 0, NULL, '2026-09-02 11:41:32', 1, 10),
(20, 0, 0, NULL, '2026-09-02 11:41:32', 1, 11),
(21, 0.02, 0.02, NULL, '2026-09-02 11:41:32', 1, 12),
(22, 0, 0, NULL, '2026-09-02 11:42:32', 1, 8),
(23, 0, 0, NULL, '2026-09-02 11:42:32', 1, 10),
(24, 0, 0, NULL, '2026-09-02 11:42:32', 1, 11),
(25, 0, 0, NULL, '2026-09-02 11:42:32', 1, 12),
(26, 0, 0, NULL, '2026-09-02 11:43:32', 1, 8),
(27, 0, 0, NULL, '2026-09-02 11:43:32', 1, 10),
(28, 0, 0, NULL, '2026-09-02 11:43:32', 1, 11),
(29, 0, 0, NULL, '2026-09-02 11:43:32', 1, 12),
(30, 0, 0, NULL, '2026-09-02 11:44:32', 1, 8),
(31, 0, 0, NULL, '2026-09-02 11:44:32', 1, 10),
(32, 0, 0, NULL, '2026-09-02 11:44:32', 1, 11),
(33, 0.03, 0.03, NULL, '2026-09-02 11:44:32', 1, 12),
(34, 0, 0, NULL, '2026-09-02 11:45:32', 1, 8),
(35, 0, 0, NULL, '2026-09-02 11:45:32', 1, 10),
(36, 0, 0, NULL, '2026-09-02 11:45:32', 1, 11),
(37, 0, 0, NULL, '2026-09-02 11:45:32', 1, 12),
(38, 0, 0, NULL, '2026-09-02 11:46:32', 1, 8),
(39, 0, 0, NULL, '2026-09-02 11:46:32', 1, 10),
(40, 0, 0, NULL, '2026-09-02 11:46:32', 1, 11),
(41, 0.08, 0.08, NULL, '2026-09-02 11:46:32', 1, 12),
(42, 0, 0, NULL, '2026-09-02 11:47:32', 1, 8),
(43, 0, 0, NULL, '2026-09-02 11:47:32', 1, 10),
(44, 0, 0, NULL, '2026-09-02 11:47:32', 1, 11),
(45, 0, 0, NULL, '2026-09-02 11:47:32', 1, 12),
(46, 0, 0, NULL, '2026-09-02 11:48:32', 1, 8),
(47, 0, 0, NULL, '2026-09-02 11:48:32', 1, 10),
(48, 0, 0, NULL, '2026-09-02 11:48:32', 1, 11),
(49, 0.01, 0.01, NULL, '2026-09-02 11:48:32', 1, 12),
(50, 0, 0, NULL, '2026-09-02 11:49:32', 1, 8),
(51, 0, 0, NULL, '2026-09-02 11:49:32', 1, 10),
(52, 0, 0, NULL, '2026-09-02 11:49:32', 1, 11),
(53, 0.02, 0.02, NULL, '2026-09-02 11:49:32', 1, 12),
(54, 0, 0, NULL, '2026-09-02 11:50:32', 1, 8),
(55, 0, 0, NULL, '2026-09-02 11:50:32', 1, 10),
(56, 0, 0, NULL, '2026-09-02 11:50:32', 1, 11),
(57, 0.02, 0.02, NULL, '2026-09-02 11:50:32', 1, 12),
(58, 0, 0, NULL, '2026-09-02 11:51:32', 1, 8),
(59, 0, 0, NULL, '2026-09-02 11:51:32', 1, 10),
(60, 0, 0, NULL, '2026-09-02 11:51:32', 1, 11),
(61, 0, 0, NULL, '2026-09-02 11:51:32', 1, 12),
(62, 0, 0, NULL, '2026-09-02 11:52:32', 1, 8),
(63, 0, 0, NULL, '2026-09-02 11:52:32', 1, 10),
(64, 0, 0, NULL, '2026-09-02 11:52:32', 1, 11),
(65, 0, 0, NULL, '2026-09-02 11:52:32', 1, 12),
(66, 0, 0, NULL, '2026-09-02 11:53:32', 1, 8),
(67, 0, 0, NULL, '2026-09-02 11:53:32', 1, 10),
(68, 0, 0, NULL, '2026-09-02 11:53:32', 1, 11),
(69, 0, 0, NULL, '2026-09-02 11:53:32', 1, 12),
(70, 0, 0, NULL, '2026-09-02 11:54:32', 1, 8),
(71, 0, 0, NULL, '2026-09-02 11:54:32', 1, 10),
(72, 0, 0, NULL, '2026-09-02 11:54:32', 1, 11),
(73, 0, 0, NULL, '2026-09-02 11:54:32', 1, 12),
(74, 0, 0, NULL, '2026-09-02 11:55:32', 1, 8),
(75, 0, 0, NULL, '2026-09-02 11:55:32', 1, 10),
(76, 0, 0, NULL, '2026-09-02 11:55:32', 1, 11),
(77, 0.01, 0.01, NULL, '2026-09-02 11:55:32', 1, 12),
(78, 0, 0, NULL, '2026-09-02 11:56:32', 1, 8),
(79, 0, 0, NULL, '2026-09-02 11:56:32', 1, 10),
(80, 0, 0, NULL, '2026-09-02 11:56:32', 1, 11),
(81, 0, 0, NULL, '2026-09-02 11:56:32', 1, 12),
(82, 0, 0, NULL, '2026-09-02 11:57:32', 1, 8),
(83, 0, 0, NULL, '2026-09-02 11:57:32', 1, 10),
(84, 0, 0, NULL, '2026-09-02 11:57:32', 1, 11),
(85, 0, 0, NULL, '2026-09-02 11:57:32', 1, 12),
(86, 0, 0, NULL, '2026-09-02 11:58:33', 1, 8),
(87, 0, 0, NULL, '2026-09-02 11:58:33', 1, 10),
(88, 0, 0, NULL, '2026-09-02 11:58:33', 1, 11),
(89, 0, 0, NULL, '2026-09-02 11:58:33', 1, 12),
(90, 0, 0, NULL, '2026-09-02 11:59:33', 1, 8),
(91, 0, 0, NULL, '2026-09-02 11:59:33', 1, 10),
(92, 0, 0, NULL, '2026-09-02 11:59:33', 1, 11),
(93, 0.03, 0.03, NULL, '2026-09-02 11:59:33', 1, 12),
(94, 0, 0, NULL, '2026-09-02 12:00:33', 1, 8),
(95, 0, 0, NULL, '2026-09-02 12:00:33', 1, 10),
(96, 0, 0, NULL, '2026-09-02 12:00:33', 1, 11),
(97, 0, 0, NULL, '2026-09-02 12:00:33', 1, 12),
(98, 0, 0, NULL, '2026-09-02 12:01:33', 1, 8),
(99, 0, 0, NULL, '2026-09-02 12:01:33', 1, 10),
(100, 0, 0, NULL, '2026-09-02 12:01:33', 1, 11),
(101, 0, 0, NULL, '2026-09-02 12:01:33', 1, 12),
(102, 0, 0, NULL, '2026-09-02 12:02:33', 1, 8),
(103, 0, 0, NULL, '2026-09-02 12:02:33', 1, 10),
(104, 0, 0, NULL, '2026-09-02 12:02:33', 1, 11),
(105, 0, 0, NULL, '2026-09-02 12:02:33', 1, 12),
(106, 0, 0, NULL, '2026-09-02 12:03:33', 1, 8),
(107, 0, 0, NULL, '2026-09-02 12:03:33', 1, 10),
(108, 0, 0, NULL, '2026-09-02 12:03:33', 1, 11),
(109, 0, 0, NULL, '2026-09-02 12:03:33', 1, 12),
(110, 0, 0, NULL, '2026-09-02 12:04:33', 1, 8),
(111, 0, 0, NULL, '2026-09-02 12:04:33', 1, 10),
(112, 0, 0, NULL, '2026-09-02 12:04:33', 1, 11),
(113, 0.02, 0.02, NULL, '2026-09-02 12:04:33', 1, 12),
(114, 0, 0, NULL, '2026-09-02 12:05:33', 1, 8),
(115, 0, 0, NULL, '2026-09-02 12:05:33', 1, 10),
(116, 0, 0, NULL, '2026-09-02 12:05:33', 1, 11),
(117, 0, 0, NULL, '2026-09-02 12:05:33', 1, 12),
(118, 0, 0, NULL, '2026-09-02 12:06:33', 1, 8),
(119, 0, 0, NULL, '2026-09-02 12:06:33', 1, 10),
(120, 0, 0, NULL, '2026-09-02 12:06:33', 1, 11),
(121, 0.03, 0.03, NULL, '2026-09-02 12:06:33', 1, 12),
(122, 0, 0, NULL, '2026-09-02 12:07:33', 1, 8),
(123, 0, 0, NULL, '2026-09-02 12:07:33', 1, 10),
(124, 0, 0, NULL, '2026-09-02 12:07:33', 1, 11),
(125, 0.02, 0.02, NULL, '2026-09-02 12:07:33', 1, 12),
(126, 0, 0, NULL, '2026-09-02 12:08:33', 1, 8),
(127, 0, 0, NULL, '2026-09-02 12:08:33', 1, 10),
(128, 0, 0, NULL, '2026-09-02 12:08:33', 1, 11),
(129, 0, 0, NULL, '2026-09-02 12:08:33', 1, 12),
(130, 0, 0, NULL, '2026-09-02 12:09:33', 1, 8),
(131, 0, 0, NULL, '2026-09-02 12:09:33', 1, 10),
(132, 0, 0, NULL, '2026-09-02 12:09:33', 1, 11),
(133, 0, 0, NULL, '2026-09-02 12:09:33', 1, 12),
(134, 0, 0, NULL, '2026-09-02 12:10:33', 1, 8),
(135, 0, 0, NULL, '2026-09-02 12:10:33', 1, 10),
(136, 0, 0, NULL, '2026-09-02 12:10:33', 1, 11),
(137, 0.04, 0.04, NULL, '2026-09-02 12:10:33', 1, 12),
(138, 0, 0, NULL, '2026-09-02 12:11:33', 1, 8),
(139, 0, 0, NULL, '2026-09-02 12:11:33', 1, 10),
(140, 0, 0, NULL, '2026-09-02 12:11:33', 1, 11),
(141, 0.02, 0.02, NULL, '2026-09-02 12:11:33', 1, 12),
(142, 0, 0, NULL, '2026-09-02 12:12:33', 1, 8),
(143, 0, 0, NULL, '2026-09-02 12:12:33', 1, 10),
(144, 0, 0, NULL, '2026-09-02 12:12:33', 1, 11),
(145, 0.04, 0.04, NULL, '2026-09-02 12:12:33', 1, 12),
(146, 0, 0, NULL, '2026-09-02 12:13:33', 1, 8),
(147, 0, 0, NULL, '2026-09-02 12:13:33', 1, 10),
(148, 0, 0, NULL, '2026-09-02 12:13:33', 1, 11),
(149, 0.01, 0.01, NULL, '2026-09-02 12:13:33', 1, 12),
(150, 0, 0, NULL, '2026-09-02 12:20:21', 1, 8),
(151, 0, 0, NULL, '2026-09-02 12:20:21', 1, 10),
(152, 0, 0, NULL, '2026-09-02 12:20:21', 1, 11),
(153, 0.13, 0.13, NULL, '2026-09-02 12:20:21', 1, 12),
(154, 0, 0, NULL, '2026-09-02 12:21:21', 1, 8),
(155, 0, 0, NULL, '2026-09-02 12:21:21', 1, 10),
(156, 0, 0, NULL, '2026-09-02 12:21:21', 1, 11),
(157, 0.07, 0.07, NULL, '2026-09-02 12:21:21', 1, 12),
(158, 0, 0, NULL, '2026-09-02 12:22:21', 1, 8),
(159, 0, 0, NULL, '2026-09-02 12:22:21', 1, 10),
(160, 0, 0, NULL, '2026-09-02 12:22:21', 1, 11),
(161, 0.04, 0.04, NULL, '2026-09-02 12:22:21', 1, 12),
(162, 0, 0, NULL, '2026-09-02 12:23:21', 1, 8),
(163, 0, 0, NULL, '2026-09-02 12:23:22', 1, 10),
(164, 0, 0, NULL, '2026-09-02 12:23:22', 1, 11),
(165, 0, 0, NULL, '2026-09-02 12:23:22', 1, 12),
(166, 0, 0, NULL, '2026-09-02 12:24:22', 1, 8),
(167, 0, 0, NULL, '2026-09-02 12:24:22', 1, 10),
(168, 0, 0, NULL, '2026-09-02 12:24:22', 1, 11),
(169, 0, 0, NULL, '2026-09-02 12:24:22', 1, 12),
(170, 0, 0, NULL, '2026-09-02 12:25:22', 1, 8),
(171, 0, 0, NULL, '2026-09-02 12:25:22', 1, 10),
(172, 0, 0, NULL, '2026-09-02 12:25:22', 1, 11),
(173, 0, 0, NULL, '2026-09-02 12:25:22', 1, 12),
(174, 0, 0, NULL, '2026-09-02 12:26:22', 1, 8),
(175, 0, 0, NULL, '2026-09-02 12:26:22', 1, 10),
(176, 0, 0, NULL, '2026-09-02 12:26:22', 1, 11),
(177, 0, 0, NULL, '2026-09-02 12:26:22', 1, 12),
(178, 0, 0, NULL, '2026-09-02 12:27:22', 1, 8),
(179, 0, 0, NULL, '2026-09-02 12:27:22', 1, 10),
(180, 0, 0, NULL, '2026-09-02 12:27:22', 1, 11),
(181, 0, 0, NULL, '2026-09-02 12:27:22', 1, 12),
(182, 0, 0, NULL, '2026-09-02 12:28:22', 1, 8),
(183, 0, 0, NULL, '2026-09-02 12:28:22', 1, 10),
(184, 0, 0, NULL, '2026-09-02 12:28:22', 1, 11),
(185, 0, 0, NULL, '2026-09-02 12:28:22', 1, 12),
(186, 0, 0, NULL, '2026-09-02 12:29:22', 1, 8),
(187, 0, 0, NULL, '2026-09-02 12:29:22', 1, 10),
(188, 0, 0, NULL, '2026-09-02 12:29:22', 1, 11),
(189, 0, 0, NULL, '2026-09-02 12:29:22', 1, 12),
(190, 0, 0, NULL, '2026-09-16 12:01:19', 1, 8),
(191, 0, 0, NULL, '2026-09-16 12:01:19', 1, 10),
(192, 0, 0, NULL, '2026-09-16 12:01:19', 1, 11),
(193, 0.19, 0.19, NULL, '2026-09-16 12:01:19', 1, 12),
(194, 0, 0, NULL, '2026-09-16 12:02:17', 1, 8),
(195, 0, 0, NULL, '2026-09-16 12:02:17', 1, 10),
(196, 0, 0, NULL, '2026-09-16 12:02:17', 1, 11),
(197, 0.19, 0.19, NULL, '2026-09-16 12:02:17', 1, 12),
(198, 0, 0, NULL, '2026-09-16 12:03:17', 1, 8),
(199, 0, 0, NULL, '2026-09-16 12:03:17', 1, 10),
(200, 0, 0, NULL, '2026-09-16 12:03:17', 1, 11),
(201, 0.2, 0.2, NULL, '2026-09-16 12:03:17', 1, 12),
(202, 0, 0, NULL, '2026-09-16 12:04:17', 1, 8),
(203, 0, 0, NULL, '2026-09-16 12:04:17', 1, 10),
(204, 0, 0, NULL, '2026-09-16 12:04:17', 1, 11),
(205, 0.2, 0.2, NULL, '2026-09-16 12:04:17', 1, 12),
(206, 0, 0, NULL, '2026-09-16 12:05:17', 1, 8),
(207, 0, 0, NULL, '2026-09-16 12:05:17', 1, 10),
(208, 0, 0, NULL, '2026-09-16 12:05:17', 1, 11),
(209, 0.26, 0.26, NULL, '2026-09-16 12:05:17', 1, 12),
(210, 0, 0, NULL, '2026-09-16 14:56:10', 1, 8),
(211, 0, 0, NULL, '2026-09-16 14:56:10', 1, 10),
(212, 0, 0, NULL, '2026-09-16 14:56:10', 1, 11),
(213, 3.24, 3.24, NULL, '2026-09-16 14:56:10', 1, 12),
(214, 0, 0, NULL, '2026-09-16 14:57:10', 1, 8),
(215, 0, 0, NULL, '2026-09-16 14:57:10', 1, 10),
(216, 0, 0, NULL, '2026-09-16 14:57:10', 1, 11),
(217, 3.26, 3.26, NULL, '2026-09-16 14:57:10', 1, 12);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sensor`
--

CREATE TABLE `sensor` (
  `id_sensor` int(11) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `modelo` varchar(50) NOT NULL,
  `rango_min` float DEFAULT NULL,
  `rango_max` float DEFAULT NULL,
  `precision_valor` float DEFAULT NULL,
  `estado` enum('en_linea','advertencia','sin_senal') DEFAULT 'sin_senal',
  `fecha_instalacion` date DEFAULT NULL,
  `id_colmena` int(11) NOT NULL,
  `id_variable` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `sensor`
--

INSERT INTO `sensor` (`id_sensor`, `tipo`, `modelo`, `rango_min`, `rango_max`, `precision_valor`, `estado`, `fecha_instalacion`, `id_colmena`, `id_variable`) VALUES
(1, 'temperatura_interna', 'DHT22', -40, 80, 0.5, 'en_linea', '2026-08-25', 1, 1),
(2, 'temperatura_externa', 'DHT22', -40, 80, 0.5, 'sin_senal', '2026-08-25', 1, 2),
(3, 'humedad_relativa', 'DHT22', 0, 100, 3, 'en_linea', '2026-08-25', 1, 3),
(4, 'peso', 'HX711', 0, 50, 0.01, 'en_linea', '2026-08-25', 1, 4),
(5, 'sonido', 'MAX9814', 20, 20000, NULL, 'en_linea', '2026-08-25', 1, 5),
(6, 'co2', 'MQ-135', 10, 300, NULL, 'en_linea', '2026-08-25', 1, 6),
(7, 'energia', 'INA219', 0, 5, 0.01, 'sin_senal', '2026-08-25', 1, 7),
(8, 'temperatura_interna', 'DHT22', -40, 80, 0.5, 'en_linea', '2026-08-28', 2, 1),
(9, 'temperatura_externa', 'DHT22', -40, 80, 0.5, 'sin_senal', '2026-08-28', 2, 2),
(10, 'humedad_relativa', 'DHT22', 0, 100, 3, 'en_linea', '2026-08-28', 2, 3),
(11, 'peso', 'HX711', 0, 50, 0.01, 'en_linea', '2026-08-28', 2, 4),
(12, 'sonido', 'MAX9814', 20, 20000, NULL, 'en_linea', '2026-08-28', 2, 5),
(13, 'co2', 'MQ-135', 10, 300, NULL, 'sin_senal', '2026-08-28', 2, 6),
(14, 'energia', 'INA219', 0, 5, 0.01, 'sin_senal', '2026-08-28', 2, 7),
(15, 'presion', 'BME280', 300, 1100, 1, 'sin_senal', '2026-08-25', 1, 8),
(16, 'luminosidad', 'BH1750', 1, 65535, 1, 'sin_senal', '2026-08-25', 1, 9),
(17, 'presion', 'BME280', 300, 1100, 1, 'sin_senal', '2026-08-25', 1, 8),
(18, 'luminosidad', 'BH1750', 1, 65535, 1, 'sin_senal', '2026-08-25', 1, 9);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario`
--

CREATE TABLE `usuario` (
  `id_usuario` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `correo` varchar(150) NOT NULL,
  `contrasena` varchar(255) NOT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuario`
--

INSERT INTO `usuario` (`id_usuario`, `nombre`, `correo`, `contrasena`, `fecha_registro`) VALUES
(1, 'Administrador', 'admin@beestation.io', '$2y$12$RuZe9KwyxXpLVJ0ox.Q6OeYg.RC9LkK.hXuQfL3wGG0PJvc/o2W2S', '2026-08-25 13:54:43'),
(2, 'Admin SENA', 'admin@sena.edu.co', '$2y$10$MacYR6fAl7y4SDU/A7rr/eH7xZpSyzqzudceGnqBoDnY.w4/4l/GW', '2026-08-25 14:07:48');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `variable_bioclimatica`
--

CREATE TABLE `variable_bioclimatica` (
  `id_variable` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `unidad_medida` varchar(20) NOT NULL,
  `optimo_min` float DEFAULT NULL,
  `optimo_max` float DEFAULT NULL,
  `alerta_min` float DEFAULT NULL,
  `alerta_max` float DEFAULT NULL,
  `critico_min` float DEFAULT NULL,
  `critico_max` float DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `variable_bioclimatica`
--

INSERT INTO `variable_bioclimatica` (`id_variable`, `nombre`, `unidad_medida`, `optimo_min`, `optimo_max`, `alerta_min`, `alerta_max`, `critico_min`, `critico_max`) VALUES
(1, 'temperatura_interna', '°C', 34, 36, 32, 38, 30, 40),
(2, 'temperatura_externa', '°C', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 'humedad_relativa', '%HR', 50, 70, 45, 80, 35, 85),
(4, 'peso', 'kg', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'sonido', 'Hz', 200, 380, 400, 600, 600, 900),
(6, 'co2', 'ppm', 2000, 4000, 1000, 6000, 500, 10000),
(7, 'energia', 'V', 3.7, 4.2, 3, 4.2, 2.8, 4.2),
(8, 'presion', 'hPa', 1000, 1025, 980, 1040, 960, 1060),
(9, 'luminosidad', 'lux', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'presion', 'hPa', 1000, 1025, 980, 1040, 960, 1060),
(11, 'luminosidad', 'lux', NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `alerta`
--
ALTER TABLE `alerta`
  ADD PRIMARY KEY (`id_alerta`),
  ADD KEY `id_indicador` (`id_indicador`),
  ADD KEY `id_usuario` (`id_usuario`);

--
-- Indices de la tabla `apiario`
--
ALTER TABLE `apiario`
  ADD PRIMARY KEY (`id_apiario`),
  ADD KEY `id_usuario` (`id_usuario`);

--
-- Indices de la tabla `calibracion`
--
ALTER TABLE `calibracion`
  ADD PRIMARY KEY (`id_calibracion`),
  ADD KEY `id_sensor` (`id_sensor`);

--
-- Indices de la tabla `colmena`
--
ALTER TABLE `colmena`
  ADD PRIMARY KEY (`id_colmena`),
  ADD UNIQUE KEY `token_vinculacion` (`token_vinculacion`),
  ADD KEY `id_apiario` (`id_apiario`);

--
-- Indices de la tabla `indicador`
--
ALTER TABLE `indicador`
  ADD PRIMARY KEY (`id_indicador`),
  ADD KEY `idx_colmena_tipo_fecha` (`id_colmena`,`tipo`,`fecha_hora`);

--
-- Indices de la tabla `intento_vinculacion`
--
ALTER TABLE `intento_vinculacion`
  ADD PRIMARY KEY (`id_intento`),
  ADD KEY `idx_fecha` (`fecha_hora`),
  ADD KEY `idx_token` (`token_recibido`);

--
-- Indices de la tabla `lectura`
--
ALTER TABLE `lectura`
  ADD PRIMARY KEY (`id_lectura`),
  ADD KEY `idx_sensor_fecha` (`id_sensor`,`fecha_hora`);

--
-- Indices de la tabla `sensor`
--
ALTER TABLE `sensor`
  ADD PRIMARY KEY (`id_sensor`),
  ADD KEY `id_colmena` (`id_colmena`),
  ADD KEY `id_variable` (`id_variable`);

--
-- Indices de la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `correo` (`correo`);

--
-- Indices de la tabla `variable_bioclimatica`
--
ALTER TABLE `variable_bioclimatica`
  ADD PRIMARY KEY (`id_variable`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `alerta`
--
ALTER TABLE `alerta`
  MODIFY `id_alerta` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `apiario`
--
ALTER TABLE `apiario`
  MODIFY `id_apiario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `calibracion`
--
ALTER TABLE `calibracion`
  MODIFY `id_calibracion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `colmena`
--
ALTER TABLE `colmena`
  MODIFY `id_colmena` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `indicador`
--
ALTER TABLE `indicador`
  MODIFY `id_indicador` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT de la tabla `intento_vinculacion`
--
ALTER TABLE `intento_vinculacion`
  MODIFY `id_intento` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT de la tabla `lectura`
--
ALTER TABLE `lectura`
  MODIFY `id_lectura` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=218;

--
-- AUTO_INCREMENT de la tabla `sensor`
--
ALTER TABLE `sensor`
  MODIFY `id_sensor` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT de la tabla `usuario`
--
ALTER TABLE `usuario`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `variable_bioclimatica`
--
ALTER TABLE `variable_bioclimatica`
  MODIFY `id_variable` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `alerta`
--
ALTER TABLE `alerta`
  ADD CONSTRAINT `alerta_ibfk_1` FOREIGN KEY (`id_indicador`) REFERENCES `indicador` (`id_indicador`),
  ADD CONSTRAINT `alerta_ibfk_2` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `apiario`
--
ALTER TABLE `apiario`
  ADD CONSTRAINT `apiario_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `calibracion`
--
ALTER TABLE `calibracion`
  ADD CONSTRAINT `calibracion_ibfk_1` FOREIGN KEY (`id_sensor`) REFERENCES `sensor` (`id_sensor`);

--
-- Filtros para la tabla `colmena`
--
ALTER TABLE `colmena`
  ADD CONSTRAINT `colmena_ibfk_1` FOREIGN KEY (`id_apiario`) REFERENCES `apiario` (`id_apiario`);

--
-- Filtros para la tabla `indicador`
--
ALTER TABLE `indicador`
  ADD CONSTRAINT `indicador_ibfk_1` FOREIGN KEY (`id_colmena`) REFERENCES `colmena` (`id_colmena`);

--
-- Filtros para la tabla `lectura`
--
ALTER TABLE `lectura`
  ADD CONSTRAINT `lectura_ibfk_1` FOREIGN KEY (`id_sensor`) REFERENCES `sensor` (`id_sensor`);

--
-- Filtros para la tabla `sensor`
--
ALTER TABLE `sensor`
  ADD CONSTRAINT `sensor_ibfk_1` FOREIGN KEY (`id_colmena`) REFERENCES `colmena` (`id_colmena`),
  ADD CONSTRAINT `sensor_ibfk_2` FOREIGN KEY (`id_variable`) REFERENCES `variable_bioclimatica` (`id_variable`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
