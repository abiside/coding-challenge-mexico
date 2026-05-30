<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Factory de canal de log con rotación HORARIA y retención acotada.
 *
 * Monolog escribe en un archivo por hora (`<name>-YYYY-MM-DD_HH.log`): el de la
 * hora en curso es el "activo", los anteriores quedan archivados y, al superar
 * `hours` archivos, los más viejos se podan automáticamente. Pensado para los
 * workers que loguean en caliente (arbitraje, meanrev) y que de otro modo
 * inflan `laravel.log` sin límite.
 *
 * Uso en config/logging.php:
 *   'meanrev' => [
 *       'driver' => 'custom',
 *       'via' => App\Logging\HourlyRotatingLogger::class,
 *       'path' => storage_path('logs/meanrev.log'),
 *       'level' => 'debug',
 *       'hours' => 6,
 *   ]
 */
final class HourlyRotatingLogger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $path = (string) ($config['path'] ?? storage_path('logs/rotating.log'));
        $maxFiles = max(1, (int) ($config['hours'] ?? 24));
        $level = $config['level'] ?? 'debug';
        $name = (string) ($config['name'] ?? 'hourly');

        // RotatingFileHandler de Monolog solo rota por día; usamos un handler
        // propio con sufijo horario (-Y_m_d_H) y retención de `maxFiles`.
        $handler = new HourlyRotatingFileHandler($path, $maxFiles, $level);
        $handler->setFormatter(new LineFormatter(null, null, true, true));

        $logger = new Logger($name);
        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor());

        return $logger;
    }
}
