<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Contracts;

use App\Domain\MarketData\DTO\StreamSubscription;

interface ExchangeConnector
{
    public function name(): string;

    public function endpoint(): string;

    /**
     * Devuelve los frames de suscripción que deben enviarse después de
     * conectar para iniciar los streams solicitados.
     *
     * @param  array<int, StreamSubscription>  $subscriptions
     * @return array<int, string>
     */
    public function buildSubscribeFrames(array $subscriptions): array;

    public function parser(): ExchangeMessageParser;
}
