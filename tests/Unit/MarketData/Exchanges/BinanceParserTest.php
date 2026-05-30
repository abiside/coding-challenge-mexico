<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Exchanges;

use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;
use App\Infrastructure\MarketData\Exchanges\Binance\BinanceConnector;
use App\Infrastructure\MarketData\Exchanges\Binance\BinanceMessageParser;
use App\Infrastructure\MarketData\Exchanges\Binance\BinanceSymbolMapper;
use PHPUnit\Framework\TestCase;

class BinanceParserTest extends TestCase
{
    public function test_normalizes_known_quote_currencies(): void
    {
        $this->assertSame('BTC/USDT', BinanceSymbolMapper::normalize('BTCUSDT'));
        $this->assertSame('ETH/BTC', BinanceSymbolMapper::normalize('ETHBTC'));
        $this->assertSame('btcusdt', BinanceSymbolMapper::toExchange('BTC/USDT'));
    }

    public function test_parses_ticker_payload(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../Fixtures/MarketData/binance_ticker.json');

        $parser = new BinanceMessageParser();
        $items = iterator_to_array($parser->parse($raw));

        $this->assertCount(1, $items);
        $this->assertInstanceOf(MarketTick::class, $items[0]);
        $this->assertSame('binance', $items[0]->exchange);
        $this->assertSame('BTC/USDT', $items[0]->symbol);
        $this->assertSame('29200.00', $items[0]->price);
        $this->assertSame('29199.99', $items[0]->bid);
        $this->assertSame('29200.01', $items[0]->ask);
        $this->assertSame('1234.567', $items[0]->volume24h);
        $this->assertSame(1701234567890, $items[0]->timestampMs);
    }

    public function test_parses_orderbook_payload(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../Fixtures/MarketData/binance_orderbook.json');

        $parser = new BinanceMessageParser();
        $items = iterator_to_array($parser->parse($raw));

        $this->assertCount(1, $items);
        $book = $items[0];
        $this->assertInstanceOf(OrderBookSnapshot::class, $book);
        $this->assertSame('binance', $book->exchange);
        $this->assertSame('BTC/USDT', $book->symbol);
        $this->assertCount(3, $book->bids);
        $this->assertCount(3, $book->asks);
        $this->assertSame('29199.99', $book->bids[0]->price);
        $this->assertSame('0.50000', $book->bids[0]->size);
        $this->assertSame(987654321, $book->sequence);
    }

    public function test_ignores_subscribe_acknowledgements(): void
    {
        $parser = new BinanceMessageParser();
        $items = iterator_to_array($parser->parse('{"result":null,"id":1}'));
        $this->assertSame([], $items);
    }

    public function test_subscribe_frame_includes_ticker_and_depth_streams(): void
    {
        $connector = new BinanceConnector();
        $frames = $connector->buildSubscribeFrames([
            new StreamSubscription(StreamType::Ticker, 'BTC/USDT'),
            new StreamSubscription(StreamType::OrderBook, 'BTC/USDT'),
        ]);

        $this->assertCount(1, $frames);
        $payload = json_decode($frames[0], true);
        $this->assertSame('SUBSCRIBE', $payload['method']);
        $this->assertContains('btcusdt@ticker', $payload['params']);
        $this->assertNotEmpty(array_filter(
            $payload['params'],
            static fn (string $stream): bool => str_starts_with($stream, 'btcusdt@depth')
        ));
    }
}
