<?php

declare(strict_types=1);

// Offizielle Symcon-Stubs (Submodul tests/stubs): Kernel, Instanzen, Variablen und IPSModuleStrict ohne laufendes Symcon.
include_once __DIR__ . '/stubs/autoload.php';

// Die Stubs kennen die Konstanten für den Kachel-Typ nicht, der Kernel definiert sie (Werte laut Symcon-Doku)
defined('INSTANCE_VISUALIZATION_TYPE_NONE') || define('INSTANCE_VISUALIZATION_TYPE_NONE', 0);
defined('INSTANCE_VISUALIZATION_TYPE_HTML') || define('INSTANCE_VISUALIZATION_TYPE_HTML', 1);

date_default_timezone_set('Europe/Berlin');
