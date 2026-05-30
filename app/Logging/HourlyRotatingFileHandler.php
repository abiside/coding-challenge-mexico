<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Handler de Monolog que rota el archivo de log POR HORA y poda los antiguos.
 *
 * El `RotatingFileHandler` nativo de Monolog solo soporta granularidad diaria
 * (su validación de formato no admite la hora), así que replicamos su patrón
 * (extender StreamHandler y reapuntar `$this->url` al cambiar de periodo) pero
 * con sufijo horario `-Y_m_d_H` y retención de los últimos `maxFiles` archivos.
 */
final class HourlyRotatingFileHandler extends StreamHandler
{
    private readonly string $baseFilename;

    private readonly int $maxFiles;

    private string $currentHour;

    public function __construct(
        string $filename,
        int $maxFiles = 24,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?int $filePermission = null,
        bool $useLocking = false,
    ) {
        $this->baseFilename = $filename;
        $this->maxFiles = max(1, $maxFiles);
        $this->currentHour = date('Y_m_d_H');

        parent::__construct($this->filenameFor($this->currentHour), $level, $bubble, $filePermission, $useLocking);
    }

    protected function write(LogRecord $record): void
    {
        $hour = $record->datetime->format('Y_m_d_H');
        if ($hour !== $this->currentHour) {
            $this->currentHour = $hour;
            // Cierra el stream actual y reapunta al archivo de la nueva hora.
            $this->close();
            $this->url = $this->filenameFor($hour);
            $this->prune();
        }

        parent::write($record);
    }

    private function filenameFor(string $hour): string
    {
        $info = pathinfo($this->baseFilename);
        $dir = $info['dirname'] ?? '.';
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.'.$info['extension'] : '';

        return $dir.'/'.$info['filename'].'-'.$hour.$ext;
    }

    /**
     * Borra los archivos rotados más antiguos que excedan la retención. El
     * sufijo `Y_m_d_H` ordena cronológicamente por nombre.
     */
    private function prune(): void
    {
        $info = pathinfo($this->baseFilename);
        $dir = $info['dirname'] ?? '.';
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.'.$info['extension'] : '';

        $files = glob($dir.'/'.$info['filename'].'-*'.$ext);
        if ($files === false || count($files) <= $this->maxFiles) {
            return;
        }

        sort($files);
        foreach (array_slice($files, 0, count($files) - $this->maxFiles) as $old) {
            @unlink($old);
        }
    }
}
