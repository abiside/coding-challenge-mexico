<?php

namespace App\Services\Parallel;

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use RuntimeException;

class ReactParallelRunner
{
    /**
     * @param  array<int, int>  $tasksDurations
     * @param  callable(array<string, mixed>):void|null  $onProgress
     * @return array<int, array<string, mixed>>
     */
    public function run(array $tasksDurations, int $maxConcurrency = 3, ?callable $onProgress = null): array
    {
        if ($maxConcurrency < 1) {
            throw new RuntimeException('maxConcurrency must be greater than 0.');
        }

        $loop = Loop::get();
        $pending = $tasksDurations;
        $running = 0;
        $results = [];
        $artisanPath = base_path('artisan');

        $startNext = function () use (
            &$pending,
            &$running,
            &$results,
            $maxConcurrency,
            $loop,
            $artisanPath,
            $onProgress,
            &$startNext
        ): void {
            while ($running < $maxConcurrency && $pending !== []) {
                $taskId = (int) array_key_first($pending);
                $seconds = (int) $pending[$taskId];
                unset($pending[$taskId]);

                $command = sprintf(
                    '%s %s react:task-worker %d %d',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg($artisanPath),
                    $taskId,
                    $seconds
                );

                $state = (object) ['stdout' => '', 'stderr' => ''];
                $running++;

                $onProgress?->__invoke([
                    'type' => 'started',
                    'task_id' => $taskId,
                    'seconds' => $seconds,
                ]);

                $process = new Process($command);
                $process->start($loop);

                $process->stdout->on('data', function (string $chunk) use ($state): void {
                    $state->stdout .= $chunk;
                });

                $process->stderr->on('data', function (string $chunk) use ($state): void {
                    $state->stderr .= $chunk;
                });

                $process->on('exit', function (?int $exitCode) use (
                    &$running,
                    &$results,
                    &$pending,
                    $loop,
                    $taskId,
                    $seconds,
                    $state,
                    $onProgress,
                    &$startNext
                ): void {
                    $running--;

                    $decoded = null;
                    $stdoutLines = preg_split('/\R/', trim($state->stdout)) ?: [];

                    for ($i = count($stdoutLines) - 1; $i >= 0; $i--) {
                        $candidate = json_decode((string) $stdoutLines[$i], true);
                        if (is_array($candidate) && isset($candidate['task_id'])) {
                            $decoded = $candidate;
                            break;
                        }
                    }

                    $result = [
                        'task_id' => $taskId,
                        'expected_seconds' => $seconds,
                        'exit_code' => $exitCode,
                        'stdout' => trim($state->stdout),
                        'stderr' => trim($state->stderr),
                    ];

                    if (is_array($decoded)) {
                        $result = array_merge($result, $decoded);
                    }

                    $results[] = $result;

                    $onProgress?->__invoke([
                        'type' => 'finished',
                        'task_id' => $taskId,
                        'exit_code' => $exitCode,
                        'reported_seconds' => $result['ran_seconds'] ?? null,
                    ]);

                    if ($pending !== []) {
                        $startNext();
                    }

                    if ($pending === [] && $running === 0) {
                        $loop->stop();
                    }
                });
            }
        };

        $startNext();
        $loop->run();

        usort($results, fn (array $a, array $b): int => $a['task_id'] <=> $b['task_id']);

        return $results;
    }
}
