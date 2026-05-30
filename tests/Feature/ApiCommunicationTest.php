<?php

namespace Tests\Feature;

use App\Events\FrontendMessageSent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiCommunicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_payload(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'ok',
                'service',
                'timestamp',
            ]);
    }

    public function test_message_endpoint_dispatches_broadcast_event(): void
    {
        Event::fake([FrontendMessageSent::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/messages', [
            'message' => 'Hola frontend',
            'source' => 'test-suite',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('data.message', 'Hola frontend')
            ->assertJsonPath('data.source', 'test-suite');

        Event::assertDispatched(FrontendMessageSent::class);
    }
}
