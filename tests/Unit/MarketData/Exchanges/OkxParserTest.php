<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Exchanges;

use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;
use App\Infrastructure\MarketData\Exchanges\Okx\OkxConnector;
use App\Infrastructure\MarketData\Exchanges\Okx\OkxMessageParser;
use PHPUnit\Framework\TestCase;

class OkxParserTest extends TestCase
{
    public function test_parses_ticker_payload(): void
    {
        $raw = json_encode([
            'arg' => ['channel' => 'tickers', 'instId' => 'BTC-USDT'],
            'data' => [[
                'instId' => 'BTC-USDT',
                'last' => '65000.10',
                'bidPx' => '65000.00',
                'askPx' => '65000.20',
                'vol24h' => '1000.50',
                'ts' => '1701234567890',
            ]],
        ], JSON_THROW_ON_ERROR);

        $items = iterator_to_array((new OkxMessageParser())->parse($raw));
        $this->assertCount(1, $items);
        $this->assertInstanceOf(MarketTick::class, $items[0]);
        $this->assertSame('BTC/USDT', $items[0]->symbol);
        $this->assertSame('65000.10', $items[0]->price);
    }

    public function test_parses_orderbook_payload(): void
    {
        $raw = json_encode([
            'arg' => ['channel' => 'books', 'instId' => 'BTC-USDT'],
            'action' => 'snapshot',
            'data' => [[
                'instId' => 'BTC-USDT',
                'bids' => [
                    ['65000.00', '1.0', '0', '1'],
                ],
                'asks' => [
                    ['65000.20', '1.5', '0', '1'],
                ],
                'ts' => '1701234567891',
                'seqId' => 9876,
            ]],
        ], JSON_THROW_ON_ERROR);

        $items = iterator_to_array((new OkxMessageParser())->parse($raw));
        $this->assertCount(1, $items);
        $this->assertInstanceOf(OrderBookSnapshot::class, $items[0]);
        $this->assertSame('BTC/USDT', $items[0]->symbol);
        $this->assertSame(9876, $items[0]->sequence);
    }

    public function test_builds_subscribe_frame_for_ticker_and_books(): void
    {
        $frames = (new OkxConnector())->buildSubscribeFrames([
            new StreamSubscription(StreamType::Ticker, 'BTC/USDT'),
            new StreamSubscription(StreamType::OrderBook, 'BTC/USDT'),
        ]);

        $this->assertCount(1, $frames);
        $payload = json_decode($frames[0], true);
        $this->assertSame('subscribe', $payload['op']);
        $this->assertCount(2, $payload['args']);
        $this->assertSame('tickers', $payload['args'][0]['channel']);
        $this->assertSame('books5', $payload['args'][1]['channel']);
    }
}
