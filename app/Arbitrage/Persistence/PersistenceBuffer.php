<?php

declare(strict_types=1);

namespace App\Arbitrage\Persistence;

use App\Models\BotEvent;
use App\Models\Opportunity;
use App\Models\Trade;
use App\Models\TradeFill;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Buffer en memoria que acumula eventos del engine y los vacía a DB por lote,
 * fuera del camino crítico. Se vacía por tamaño (flushSize) o por tiempo
 * (lo dispara el runner periódicamente).
 */
final class PersistenceBuffer
{
    /**
     * @var array<int, array{opportunity: array<string, mixed>, trade: array<string, mixed>|null, fills: array<int, array<string, mixed>>}>
     */
    private array $items = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $events = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $flushSize = 50,
    ) {
    }

    /**
     * @param  array<string, mixed>  $opportunity
     * @param  array<string, mixed>|null  $trade
     * @param  array<int, array<string, mixed>>  $fills
     */
    public function push(array $opportunity, ?array $trade = null, array $fills = []): void
    {
        $this->items[] = [
            'opportunity' => $opportunity,
            'trade' => $trade,
            'fills' => $fills,
        ];

        if ($this->size() >= $this->flushSize) {
            $this->flush();
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function pushEvent(array $event): void
    {
        $this->events[] = $event;

        if ($this->size() >= $this->flushSize) {
            $this->flush();
        }
    }

    public function size(): int
    {
        return count($this->items) + count($this->events);
    }

    public function flush(): void
    {
        if ($this->items === [] && $this->events === []) {
            return;
        }

        $items = $this->items;
        $events = $this->events;
        $this->items = [];
        $this->events = [];

        try {
            DB::transaction(function () use ($items, $events): void {
                foreach ($items as $item) {
                    $opportunity = Opportunity::create($item['opportunity']);

                    if ($item['trade'] !== null) {
                        $tradeData = $item['trade'];
                        $tradeData['opportunity_id'] = $opportunity->id;
                        $trade = Trade::create($tradeData);

                        foreach ($item['fills'] as $fill) {
                            $fill['trade_id'] = $trade->id;
                            TradeFill::create($fill);
                        }
                    }
                }

                if ($events !== []) {
                    BotEvent::insert($events);
                }
            });
        } catch (Throwable $e) {
            $this->logger->error('[arbitrage][persistence] flush falló', [
                'error' => $e->getMessage(),
                'items' => count($items),
                'events' => count($events),
            ]);
        }
    }
}
