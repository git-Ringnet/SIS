<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CenterStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FreshInstallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_seeding_empty_database_clears_leftover_file_data(): void
    {
        Storage::put('staff_data.json', '{"members":[{"name":"Thầy cũ"}]}');
        Storage::put('centers_data.json', '[]');

        $this->seed();

        Storage::assertMissing('staff_data.json');
        Storage::assertMissing('centers_data.json');
    }

    public function test_reseeding_existing_install_keeps_file_data(): void
    {
        User::factory()->create();
        Storage::put('inventory_data.json', '[{"id":1}]');

        $this->seed();

        Storage::assertExists('inventory_data.json');
    }

    public function test_default_center_has_no_sample_rooms(): void
    {
        $this->assertSame([], CenterStore::all()[0]['rooms']);
        $this->assertSame([], CenterStore::roomNames());
    }

    public function test_owner_is_not_listed_as_teacher(): void
    {
        $this->seed();
        Sanctum::actingAs(User::orderBy('id')->first());

        $this->getJson('/api/students')->assertOk()->assertJsonPath('teachers', []);
    }
}
