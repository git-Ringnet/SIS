<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CenterStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PagePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->owner = User::factory()->create();
        $this->teacher = User::factory()->create();

        Storage::put('staff_data.json', json_encode([
            'invites' => [],
            'members' => [
                ['id' => 1, 'email' => $this->owner->email, 'name' => 'Chủ', 'role' => 'Chủ trung tâm', 'blocked_pages' => [], 'is_owner' => true],
                ['id' => 2, 'email' => $this->teacher->email, 'name' => 'GV', 'role' => 'Giáo viên', 'blocked_pages' => ['Doanh thu', 'Khóa học'], 'is_owner' => false],
            ],
        ]));
    }

    public function test_blocked_page_api_returns_forbidden_for_staff(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/revenue')->assertForbidden();
        $this->postJson('/api/courses', ['name' => 'X'])->assertForbidden();
    }

    public function test_shared_read_api_stays_available_for_staff(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/courses')->assertOk();
        $this->getJson('/api/students')->assertOk();
    }

    public function test_dashboard_hides_revenue_when_revenue_page_is_blocked(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('stats.monthRevenue', null);
    }

    public function test_owner_is_never_blocked(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/revenue')->assertOk();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('stats.monthRevenue', 0);
    }

    public function test_staff_cannot_change_their_own_permissions(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/staff/members/2/permissions', ['blocked_pages' => []])->assertForbidden();
    }

    public function test_owner_can_promote_staff_to_co_owner_with_full_access(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/staff/members/2/role', ['role' => 'Chủ trung tâm'])->assertOk();

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/revenue')->assertOk();
        $this->putJson('/api/staff/members/2/permissions', ['blocked_pages' => []])->assertOk();
    }

    public function test_co_owner_cannot_demote_original_owner(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/staff/members/2/role', ['role' => 'Chủ trung tâm'])->assertOk();

        Sanctum::actingAs($this->teacher);
        $this->putJson('/api/staff/members/1/role', ['role' => 'Giáo viên'])->assertUnprocessable();
        $this->deleteJson('/api/staff/members/1')->assertUnprocessable();
    }

    public function test_staff_cannot_create_owner_account(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/staff/invites', [
            'email' => 'new@example.com',
            'role' => 'Chủ trung tâm',
            'center' => 'Tất cả trung tâm',
        ])->assertForbidden();
    }

    public function test_owner_can_change_member_center_but_staff_cannot(): void
    {
        $center = CenterStore::names()[0];

        Sanctum::actingAs($this->teacher);
        $this->putJson('/api/staff/members/2/center', ['center' => $center])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/staff/members/2/center', ['center' => 'Cơ sở không tồn tại'])->assertUnprocessable();
        $this->putJson('/api/staff/members/2/center', ['center' => $center])
            ->assertOk()
            ->assertJsonPath('members.1.center', $center);
    }

    public function test_owner_can_reset_staff_password(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->putJson('/api/staff/members/2/password', ['password' => 'newpass1'])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/staff/members/2/password', ['password' => 'newpass1'])->assertOk();
        $this->putJson('/api/staff/members/1/password', ['password' => 'newpass1'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $this->teacher->email, 'password' => 'newpass1'])->assertOk();
    }

    public function test_seeder_creates_admin_with_default_password_and_system_admin(): void
    {
        config(['app.system_admin.password' => 'sys-secret-123']);
        $this->seed();

        $this->postJson('/api/auth/login', ['email' => 'admin@khoaanhmusic.com', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.is_owner', false); // tài khoản đầu tiên là owner/teacher của setUp
        $this->postJson('/api/auth/login', ['email' => 'system_admin@mail.com', 'password' => 'sys-secret-123'])
            ->assertOk()->assertJsonPath('user.is_owner', true);
    }

    public function test_system_admin_is_hidden_from_staff_and_cannot_be_reset_by_customer(): void
    {
        $system = User::factory()->create(['email' => config('app.system_admin.email')]);

        Sanctum::actingAs($system);
        $this->getJson('/api/revenue')->assertOk();

        Sanctum::actingAs($this->owner);
        $emails = collect($this->getJson('/api/staff')->json('members'))->pluck('email');
        $this->assertNotContains($system->email, $emails);
    }

    public function test_reset_password_command(): void
    {
        $this->artisan('sis:reset-password', ['email' => $this->teacher->email, '--password' => 'cmdpass1'])->assertSuccessful();
        $this->artisan('sis:reset-password', ['email' => 'khong-co@example.com'])->assertFailed();

        $this->postJson('/api/auth/login', ['email' => $this->teacher->email, 'password' => 'cmdpass1'])->assertOk();
    }

    public function test_seeding_does_not_write_activity_logs(): void
    {
        $this->seed();

        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('message_templates', 4);
    }

    public function test_public_registration_is_closed_once_an_account_exists(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
        ])->assertForbidden();
    }
}
