<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData\Exchanges;

use App\Domain\MarketData\DTO\MarketTick;
use App\Domain\MarketData\DTO\OrderBookSnapshot;
use App\Domain\MarketData\DTO\StreamSubscription;
use App\Domain\MarketData\Enums\StreamType;
use App\Infrastructure\MarketData\Exchanges\Coinbase\CoinbaseConnector;
use App\Infrastructure\MarketData\Exchanges\Coinbase\CoinbaseMessageParser;
use PHPUnit\Framework\TestCase;

class CoinbaseParserTest extends TestCase
{
    public function test_parses_ticker_payload(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../Fixtures/MarketData/coinbase_ticker.json');

        $parser = new CoinbaseMessageParser();
        $items = iterator_to_array($parser->parse($raw));

        $this->assertCount(1, $items);
        $tick = $items[0];
        $this->assertInstanceOf(MarketTick::class, $tick);
        $this->assertSame('coinbase', $tick->exchange);
        $this->assertSame('BTC/USD', $tick->symbol);
        $this->assertSame('29200.00', $tick->price);
        $this->assertSame('29199.99', $tick->bid);
        $this->assertSame('29200.01', $tick->ask);
        $this->assertSame('1234.56', $tick->volume24h);
    }

    public function test_parses_level2_snapshot_separating_bids_and_asks(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../Fixtures/MarketData/coinbase_orderbook.json');

        $parser = new CoinbaseMessageParser();
        $items = iterator_to_array($parser->parse($raw));

        $this->assertCount(1, $items);
        $book = $items[0];
        $this->assertInstanceOf(OrderBookSnapshot::class, $book);
        $this->assertTrue($book->isSnapshot);
        $this->assertSame('BTC/USD', $book->symbol);
        $this->assertCount(2, $book->bids);
        $this->assertCount(2, $book->asks);
        $this->assertSame('29199.99', $book->bids[0]->price);
        $this->assertSame('29200.01', $book->asks[0]->price);
        $this->assertSame(100, $book->sequence);
    }

    public function test_subscribe_frames_split_by_channel(): void
    {
        $connector = new CoinbaseConnector();
        $frames = $connector->buildSubscribeFrames([
            new StreamSubscription(StreamType::Ticker, 'BTC/USD'),
            new StreamSubscription(StreamType::OrderBook, 'BTC/USD'),
        ]);

        $this->assertCount(2, $frames);

        $tickerFrame = json_decode($frames[0], true);
        $this->assertSame('ticker', $tickerFrame['channel']);
        $this->assertSame(['BTC-USD'], $tickerFrame['product_ids']);

        $bookFrame = json_decode($frames[1], true);
        $this->assertSame('level2', $bookFrame['channel']);
    }
}
