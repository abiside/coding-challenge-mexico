<?php

declare(strict_types=1);

namespace App\Arbitrage\Optimization;

/**
 * Estrategia candidata propuesta por el optimizador (y validable por el LLM).
 * Aún no se ha persistido; el comando arbitrage:optimize la materializa
 * después del clamp y la aprobación del advisor.
 */
final class ProposedStrategy
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, float>  $params  parámetros mutables perturbados
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly array $config,
        public readonly string $configHash,
        public readonly int $parentId,
        public readonly int $generation,
        public readonly string $rationale,
        public readonly array $params,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'parent_id' => $this->parentId,
            'generation' => $this->generation,
            'params' => $this->params,
            'rationale' => $this->rationale,
        ];
    }
}
