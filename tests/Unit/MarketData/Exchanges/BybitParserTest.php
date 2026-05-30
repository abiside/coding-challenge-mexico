<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Exchanges;

use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;
use App\Infrastructure\MarketData\Exchanges\Bybit\BybitConnector;
use App\Infrastructure\MarketData\Exchanges\Bybit\BybitMessageParser;
use PHPUnit\Framework\TestCase;

class BybitParserTest extends TestCase
{
    public function test_parses_ticker_payload(): void
    {
        $raw = json_encode([
            'topic' => 'tickers.BTCUSDT',
            'type' => 'snapshot',
            'ts' => 1701234567890,
            'data' => [
                'symbol' => 'BTCUSDT',
                'lastPrice' => '65000.10',
                'bid1Price' => '65000.00',
                'ask1Price' => '65000.20',
                'volume24h' => '999.99',
            ],
        ], JSON_THROW_ON_ERROR);

        $items = iterator_to_array((new BybitMessageParser())->parse($raw));
        $this->assertCount(1, $items);
        $this->assertInstanceOf(MarketTick::class, $items[0]);
        $this->assertSame('BTC/USDT', $items[0]->symbol);
        $this->assertSame('65000.10', $items[0]->price);
    }

    public function test_parses_orderbook_payload(): void
    {
        $raw = json_encode([
            'topic' => 'orderbook.50.BTCUSDT',
            'type' => 'snapshot',
            'ts' => 1701234567891,
            'data' => [
                's' => 'BTCUSDT',
                'u' => 12345,
                'b' => [
                    ['65000.00', '1.0'],
                    ['64999.90', '2.0'],
                ],
                'a' => [
                    ['65000.20', '1.5'],
                    ['65000.30', '3.0'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $items = iterator_to_array((new BybitMessageParser())->parse($raw));
        $this->assertCount(1, $items);
        $this->assertInstanceOf(OrderBookSnapshot::class, $items[0]);
        $this->assertSame('BTC/USDT', $items[0]->symbol);
        $this->assertSame(12345, $items[0]->sequence);
    }

    public function test_builds_subscribe_frame_for_ticker_and_orderbook(): void
    {
        $frames = (new BybitConnector())->buildSubscribeFrames([
            new StreamSubscription(StreamType::Ticker, 'BTC/USDT'),
            new StreamSubscription(StreamType::OrderBook, 'BTC/USDT'),
        ]);

        $this->assertCount(1, $frames);
        $payload = json_decode($frames[0], true);
        $this->assertSame('subscribe', $payload['op']);
        $this->assertContains('tickers.BTCUSDT', $payload['args']);
        $this->assertContains('orderbook.50.BTCUSDT', $payload['args']);
    }
}
