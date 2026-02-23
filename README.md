# rOSclass
A modern, lightweight PHP class optimized for the RouterOS/Mikrotik native API

[![PHP Version](https://img.shields.io/badge/php-%5E7.4%20%7C%20%5E8.0-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![MikroTik](https://img.shields.io/badge/MikroTik-RouterOS-orange.svg)](https://mikrotik.com/)

Una librería PHP ligera, rápida y optimizada para interactuar con la API de **MikroTik RouterOS**. Basada en el trabajo original de Denis Basta y mejorada para entornos modernos con tipado estricto y soporte SSL.

## ✨ Características

* **Optimización 2026:** Código refinado para PHP 7.4 y versiones superiores (PHP 8.x).
* **Seguridad:** Soporte nativo para conexiones seguras vía **SSL/TLS**.
* **Compatibilidad:** Funciona con versiones de RouterOS v6.43+ (nuevo login) y versiones anteriores.
* **Depuración:** Sistema de debug integrado para monitorear la comunicación por consola.
* **Flexibilidad:** Métodos simplificados para ejecución de comandos directos o mediante arrays de parámetros.

---

## 🚀 Instalación

Simplemente descarga el archivo `ros.class.php` e inclúyelo en tu proyecto:

```php
require_once 'ros.class.php';
