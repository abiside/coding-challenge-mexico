<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Arbitrage\Optimization\ProposedStrategy;
use App\Arbitrage\Optimization\StrategyAdvisor;
use App\Models\ArbitrageStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Tests\TestCase;

class StrategyAdvisorTest extends TestCase
{
    use RefreshDatabase;

    private function champion(): ArbitrageStrategy
    {
        $user = User::factory()->create();

        return ArbitrageStrategy::create([
            'user_id' => $user->id,
            'name' => 'champion',
            'status' => ArbitrageStrategy::STATUS_CHAMPION,
            'origin' => ArbitrageStrategy::ORIGIN_BASELINE,
            'parent_id' => null,
            'generation' => 0,
            'config' => [
                'symbols' => ['BTC/USDT'],
                'thresholds' => [
                    'min_net_profit' => 1.0,
                    'min_net_margin' => 0.0005,
                    'min_base_volume' => 0.0001,
                    'max_base_volume' => 1.0,
                ],
                'freshness_ms' => 2000,
                'latency' => ['max_ms' => 1500],
            ],
            'config_hash' => 'a'.str_repeat('0', 63),
            'promoted_at' => now(),
        ]);
    }

    /**
     * @return array<int, ProposedStrategy>
     */
    private function proposals(int $userId): array
    {
        return [
            new ProposedStrategy(
                userId: $userId,
                name: 'cand-1',
                config: [
                    'symbols' => ['BTC/USDT'],
                    'thresholds' => [
                        'min_net_profit' => 2.0,
                        'min_net_margin' => 0.001,
                        'min_base_volume' => 0.0001,
                        'max_base_volume' => 1.0,
                    ],
                    'freshness_ms' => 1800,
                    'latency' => ['max_ms' => 1500],
                ],
                configHash: 'b'.str_repeat('0', 63),
                parentId: 1,
                generation: 1,
                rationale: 'optimizer baseline',
                params: ['min_net_profit' => 2.0, 'min_net_margin' => 0.001],
            ),
        ];
    }

    public function test_advisor_degrades_to_optimizer_when_disabled(): void
    {
        config()->set('ai.autopilot.enabled', false);
        config()->set('ai.autopilot.api_key', '');

        $advisor = new StrategyAdvisor(new NullLogger);
        $champion = $this->champion();
        $proposals = $this->proposals((int) $champion->user_id);

        $reviewed = $advisor->review($champion, $proposals);

        $this->assertCount(1, $reviewed);
        $this->assertStringContainsString('advisor: deshabilitado', $reviewed[0]->rationale);
        // Sin LLM no debe mutar params ni hash.
        $this->assertSame($proposals[0]->configHash, $reviewed[0]->configHash);
    }

    public function test_advisor_clamps_llm_proposed_params(): void
    {
        config()->set('ai.autopilot.enabled', true);
        config()->set('ai.autopilot.api_key', 'fake');
        config()->set('ai.autopilot.base_url', 'https://api.fake/v1');

        Http::fake([
            'api.fake/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        // El LLM propone valores fuera de rango: el clamp los debe meter en bounds.
                        'content' => json_encode([
                            'proposals' => [[
                                'name' => 'cand-1',
                                'approve' => true,
                                'rationale' => 'aumentar profit por baja exec rate',
                                'params' => [
                                    'min_net_profit' => 99999.0,
                                    'min_net_margin' => -1.0,
                                ],
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $advisor = new StrategyAdvisor(new NullLogger);
        $champion = $this->champion();
        $reviewed = $advisor->review($champion, $this->proposals((int) $champion->user_id));

        $this->assertCount(1, $reviewed);
        $this->assertLessThanOrEqual(1000.0, $reviewed[0]->params['min_net_profit']);
        $this->assertGreaterThanOrEqual(0.0, $reviewed[0]->params['min_net_margin']);
        $this->assertStringContainsString('aumentar profit', $reviewed[0]->rationale);
    }

    public function test_advisor_drops_proposals_rejected_by_llm(): void
    {
        config()->set('ai.autopilot.enabled', true);
        config()->set('ai.autopilot.api_key', 'fake');
        config()->set('ai.autopilot.base_url', 'https://api.fake/v1');

        Http::fake([
            'api.fake/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'proposals' => [[
                                'name' => 'cand-1',
                                'approve' => false,
                                'rationale' => 'demasiado riesgoso',
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $advisor = new StrategyAdvisor(new NullLogger);
        $champion = $this->champion();
        $reviewed = $advisor->review($champion, $this->proposals((int) $champion->user_id));

        $this->assertEmpty($reviewed);
    }

    public function test_advisor_falls_back_on_http_failure(): void
    {
        config()->set('ai.autopilot.enabled', true);
        config()->set('ai.autopilot.api_key', 'fake');
        config()->set('ai.autopilot.base_url', 'https://api.fake/v1');
        config()->set('ai.autopilot.fallback_to_optimizer', true);

        Http::fake([
            'api.fake/v1/chat/completions' => Http::response('boom', 500),
        ]);

        $advisor = new StrategyAdvisor(new NullLogger);
        $champion = $this->champion();
        $reviewed = $advisor->review($champion, $this->proposals((int) $champion->user_id));

        $this->assertCount(1, $reviewed);
        $this->assertStringContainsString('fallback por error LLM', $reviewed[0]->rationale);
    }
}
