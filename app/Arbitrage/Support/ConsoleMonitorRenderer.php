<?php

declare(strict_types=1);

namespace App\Arbitrage\Support;

use App\Arbitrage\Realtime\ConsoleMonitorPublisher;

/**
 * Renderiza el estado del engine como un panel de consola (TUI) que se redibuja
 * en cada tick. Solo formatea texto; no toca el engine.
 */
final class ConsoleMonitorRenderer
{
    private const RESET = "\033[0m";

    private const BOLD = "\033[1m";

    private const DIM = "\033[2m";

    private const GREEN = "\033[32m";

    private const RED = "\033[31m";

    private const YELLOW = "\033[33m";

    private const CYAN = "\033[36m";

    private const GRAY = "\033[90m";

    /**
     * @param  array<string, mixed>  $configSummary
     */
    public function __construct(
        private readonly array $configSummary,
        private readonly float $startedAt,
    ) {
    }

    /**
     * @param  array<string, array<string, float>>  $wallets
     * @param  array<string, mixed>  $metrics
     */
    public function render(array $wallets, array $metrics, ConsoleMonitorPublisher $publisher): string
    {
        $out = "\033[H\033[2J"; // cursor home + clear screen
        $out .= $this->header();
        $out .= $this->metricsBlock($metrics, $publisher->totalEvents());
        $out .= $this->walletsBlock($wallets);
        $out .= $this->decisionsBlock($publisher->decisions());
        $out .= $this->tradesBlock($publisher->trades());
        $out .= self::DIM."\n Ctrl+C para salir.".self::RESET."\n";

        return $out;
    }

    private function header(): string
    {
        $title = self::BOLD.self::CYAN.' ARBITRAGE ENGINE MONITOR '.self::RESET;
        $symbols = implode(', ', (array) ($this->configSummary['symbols'] ?? []));
        $fee = (float) ($this->configSummary['fee'] ?? 0);
        $minProfit = (float) ($this->configSummary['min_net_profit'] ?? 0);
        $refresh = (int) ($this->configSummary['refresh_ms'] ?? 250);

        $line = self::GRAY.str_repeat('═', 80).self::RESET."\n";

        return $line.$title."\n".$line.sprintf(
            " %sSímbolos:%s %-22s %sFees:%s %-8s %sMin profit:%s %-10s %sRefresh:%s %dms\n %sUptime:%s %s   %sActualizado:%s %s\n",
            self::BOLD, self::RESET, $symbols !== '' ? $symbols : '-',
            self::BOLD, self::RESET, $fee > 0 ? number_format($fee * 100, 3).'%' : '0%',
            self::BOLD, self::RESET, number_format($minProfit, 2),
            self::BOLD, self::RESET, $refresh,
            self::BOLD, self::RESET, $this->uptime(),
            self::BOLD, self::RESET, date('H:i:s'),
        );
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function metricsBlock(array $metrics, int $totalEvents): string
    {
        $decisions = (array) ($metrics['decisions'] ?? []);
        $pnl = (float) ($metrics['realized_pnl'] ?? 0);
        $pnlColor = $pnl >= 0 ? self::GREEN : self::RED;

        return $this->sectionTitle('MÉTRICAS').sprintf(
            " Snapshots: %s%d%s   Candidatos: %s%d%s   %sExecute: %d%s   %sReject: %d%s   %sIgnore: %d%s   Ejec: %d   PnL: %s%+0.2f%s\n",
            self::BOLD, (int) ($metrics['snapshots_processed'] ?? 0), self::RESET,
            self::BOLD, (int) ($metrics['candidates_detected'] ?? 0), self::RESET,
            self::GREEN, (int) ($decisions['execute'] ?? 0), self::RESET,
            self::RED, (int) ($decisions['reject'] ?? 0), self::RESET,
            self::YELLOW, (int) ($decisions['ignore'] ?? 0), self::RESET,
            (int) ($metrics['executions'] ?? 0),
            $pnlColor, $pnl, self::RESET,
        );
    }

    /**
     * @param  array<string, array<string, float>>  $wallets
     */
    private function walletsBlock(array $wallets): string
    {
        $out = $this->sectionTitle('WALLETS');
        if ($wallets === []) {
            return $out.self::DIM." (sin saldos)\n".self::RESET;
        }

        ksort($wallets);
        foreach ($wallets as $exchange => $assets) {
            $cells = [];
            ksort($assets);
            foreach ($assets as $asset => $amount) {
                $cells[] = sprintf('%s=%s', $asset, $this->fmtAmount((float) $amount));
            }
            $out .= sprintf(" %s%-10s%s %s\n", self::BOLD, $exchange, self::RESET, implode('   ', $cells));
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $decisions
     */
    private function decisionsBlock(array $decisions): string
    {
        $out = $this->sectionTitle('ÚLTIMAS DECISIONES');
        if ($decisions === []) {
            return $out.self::DIM." Esperando datos de mercado…\n".self::RESET;
        }

        $out .= self::GRAY.sprintf(" %-8s %-9s %-18s %8s %9s %11s  %-9s %s\n",
            'hora', 'símbolo', 'ruta', 'spread', 'vol', 'net', 'decisión', 'motivo').self::RESET;

        foreach ($decisions as $d) {
            $color = match ($d['decision']) {
                'execute' => self::GREEN,
                'reject' => self::RED,
                default => self::YELLOW,
            };
            $route = sprintf('%s→%s', $d['buy_exchange'], $d['sell_exchange']);
            $reason = $d['reasons'][0] ?? '';
            $netColor = $d['net_profit'] >= 0 ? self::GREEN : self::RED;

            $out .= sprintf(
                " %-8s %-9s %-18s %7.1f %9.4f %s%11.2f%s  %s%-9s%s %s%s%s\n",
                date('H:i:s', (int) $d['at']),
                $d['symbol'],
                mb_substr($route, 0, 18),
                $d['gross_spread_bps'],
                $d['base_volume'],
                $netColor, $d['net_profit'], self::RESET,
                $color, $d['decision'], self::RESET,
                self::DIM, mb_substr((string) $reason, 0, 28), self::RESET,
            );
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $trades
     */
    private function tradesBlock(array $trades): string
    {
        $out = $this->sectionTitle('ÚLTIMOS TRADES SIMULADOS');
        if ($trades === []) {
            return $out.self::DIM." (sin ejecuciones todavía)\n".self::RESET;
        }

        foreach ($trades as $t) {
            $pnlColor = $t['realized_pnl'] >= 0 ? self::GREEN : self::RED;
            $out .= sprintf(
                " %-8s %-9s %-18s vol %9.4f   PnL %s%+0.2f%s\n",
                date('H:i:s', (int) $t['at']),
                $t['symbol'],
                mb_substr(sprintf('%s→%s', $t['buy_exchange'], $t['sell_exchange']), 0, 18),
                $t['base_volume'],
                $pnlColor, $t['realized_pnl'], self::RESET,
            );
        }

        return $out;
    }

    private function sectionTitle(string $title): string
    {
        return "\n".self::BOLD.self::CYAN.'── '.$title.' '.self::RESET
            .self::GRAY.str_repeat('─', max(0, 76 - mb_strlen($title))).self::RESET."\n";
    }

    private function fmtAmount(float $amount): string
    {
        if ($amount >= 1000) {
            return number_format($amount, 2);
        }

        return rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function uptime(): string
    {
        $seconds = (int) max(0, microtime(true) - $this->startedAt);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
