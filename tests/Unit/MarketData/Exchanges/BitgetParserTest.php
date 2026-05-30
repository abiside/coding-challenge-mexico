<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Exchanges;

use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;
use App\Infrastructure\MarketData\Exchanges\Bitget\BitgetConnector;
use App\Infrastructure\MarketData\Exchanges\Bitget\BitgetMessageParser;
use PHPUnit\Framework\TestCase;

class BitgetParserTest extends TestCase
{
    public function test_parses_ticker_payload(): void
    {
        $raw = json_encode([
            'arg' => ['instType' => 'SPOT', 'channel' => 'ticker', 'instId' => 'BTCUSDT'],
            'data' => [[
                'instId' => 'BTCUSDT',
                'lastPr' => '65000.10',
                'bidPr' => '65000.00',
                'askPr' => '65000.20',
                'baseVolume' => '1234.56',
                'ts' => '1701234567890',
            ]],
        ], JSON_THROW_ON_ERROR);

        $items = iterator_to_array((new BitgetMessageParser())->parse($raw));
        $this->assertCount(1, $items);
        $this->assertInstanceOf(MarketTick::class, $items[0]);
        $this->assertSame('BTC/USDT', $items[0]->symbol);
        $this->assertSame('65000.10', $items[0]->price);
    }

    public function test_parses_books_payload(): void
    {
        $raw = json_encode([
            'arg' => ['instType' => 'SPOT', 'channel' => 'books', 'instId' => 'BTCUSDT'],
            'action' => 'snapshot',
            'data' => [[
                'bids' => [
                    ['65000.00', '1.0'],
                ],
                'asks' => [
                    ['65000.20', '1.5'],
                ],
                'ts' => '1701234567891',
                'seq' => 111,
            ]],
        ], JSON_THROW_ON_ERROR);

        $items = iterator_to_array((new BitgetMessageParser())->parse($raw));
        $this->assertCount(1, $items);
        $this->assertInstanceOf(OrderBookSnapshot::class, $items[0]);
        $this->assertSame('BTC/USDT', $items[0]->symbol);
        $this->assertSame(111, $items[0]->sequence);
    }

    public function test_builds_subscribe_frame_for_ticker_and_books(): void
    {
        $frames = (new BitgetConnector())->buildSubscribeFrames([
            new StreamSubscription(StreamType::Ticker, 'BTC/USDT'),
            new StreamSubscription(StreamType::OrderBook, 'BTC/USDT'),
        ]);

        $this->assertCount(1, $frames);
        $payload = json_decode($frames[0], true);
        $this->assertSame('subscribe', $payload['op']);
        $this->assertCount(2, $payload['args']);
        $this->assertSame('ticker', $payload['args'][0]['channel']);
        $this->assertSame('books', $payload['args'][1]['channel']);
    }
}
