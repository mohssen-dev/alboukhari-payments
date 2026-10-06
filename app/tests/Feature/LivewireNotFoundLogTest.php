<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/** A 404 inside a Livewire request is logged with what caused it (Laravel does not log 404s). */
class LivewireNotFoundLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_livewire_404_is_written_to_the_log(): void
    {
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@nf.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'is_active' => true]));
        Log::spy();

        $this->withHeaders(['X-Livewire' => '1'])
            ->postJson(Livewire::getUpdateUri(), ['components' => []])
            ->assertNotFound();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'Livewire request ended in 404')->once();
    }

    public function test_an_ordinary_404_is_not_logged(): void
    {
        Log::spy();
        $this->get('/no-such-page')->assertNotFound();
        Log::shouldNotHaveReceived('warning');
    }
}
