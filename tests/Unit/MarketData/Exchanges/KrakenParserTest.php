<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Exchanges;

use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;
use App\Infrastructure\MarketData\Exchanges\Kraken\KrakenConnector;
use App\Infrastructure\MarketData\Exchanges\Kraken\KrakenMessageParser;
use PHPUnit\Framework\TestCase;

class KrakenParserTest extends TestCase
{
    public function test_parses_ticker_payload(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../Fixtures/MarketData/kraken_ticker.json');

        $parser = new KrakenMessageParser();
        $items = iterator_to_array($parser->parse($raw));

        $this->assertCount(1, $items);
        $tick = $items[0];
        $this->assertInstanceOf(MarketTick::class, $tick);
        $this->assertSame('kraken', $tick->exchange);
        $this->assertSame('BTC/USD', $tick->symbol);
        $this->assertSame('29200', $tick->price);
        $this->assertSame('29199.99', $tick->bid);
        $this->assertSame('29200.01', $tick->ask);
    }

    public function test_parses_orderbook_snapshot(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../Fixtures/MarketData/kraken_orderbook.json');

        $parser = new KrakenMessageParser();
        $items = iterator_to_array($parser->parse($raw));

        $this->assertCount(1, $items);
        $book = $items[0];
        $this->assertInstanceOf(OrderBookSnapshot::class, $book);
        $this->assertTrue($book->isSnapshot);
        $this->assertSame('BTC/USD', $book->symbol);
        $this->assertCount(3, $book->bids);
        $this->assertCount(3, $book->asks);
        $this->assertSame('29199.99', $book->bids[0]->price);
        $this->assertSame('0.5', $book->bids[0]->size);
        $this->assertSame(1234567890, $book->sequence);
    }

    public function test_subscribe_frames_split_by_channel(): void
    {
        $connector = new KrakenConnector();
        $frames = $connector->buildSubscribeFrames([
            new StreamSubscription(StreamType::Ticker, 'BTC/USD'),
            new StreamSubscription(StreamType::OrderBook, 'BTC/USD'),
        ]);

        $this->assertCount(2, $frames);

        $tickerFrame = json_decode($frames[0], true);
        $this->assertSame('subscribe', $tickerFrame['method']);
        $this->assertSame('ticker', $tickerFrame['params']['channel']);
        $this->assertSame(['BTC/USD'], $tickerFrame['params']['symbol']);

        $bookFrame = json_decode($frames[1], true);
        $this->assertSame('book', $bookFrame['params']['channel']);
        $this->assertArrayHasKey('depth', $bookFrame['params']);
    }
}
