<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ảnh của khách hàng tiềm năng (phiếu đăng ký, bài test...): tải ảnh lên thay vì dán đường dẫn.
 */
class LeadImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_replace_and_delete_lead_image(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());
        $lead = Lead::create(['parent_name' => 'Chị Hồng', 'phone' => '0123456789', 'source' => 'Facebook', 'status' => 'new']);

        // Tải ảnh lên → lưu file, khách có đường dẫn ảnh
        $first = $this->postJson("/api/leads/{$lead->id}/image", ['image' => UploadedFile::fake()->image('phieu.jpg')])
            ->assertOk()->json('image_url');
        $firstFile = 'leads/'.basename($first);
        Storage::disk('public')->assertExists($firstFile);
        $this->assertSame($first, $lead->fresh()->image_url);

        // Không phải ảnh → báo lỗi
        $this->postJson("/api/leads/{$lead->id}/image", ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertUnprocessable();

        // Đổi ảnh khác → file cũ bị xóa
        $second = $this->postJson("/api/leads/{$lead->id}/image", ['image' => UploadedFile::fake()->image('moi.png')])->json('image_url');
        Storage::disk('public')->assertMissing($firstFile);
        Storage::disk('public')->assertExists('leads/'.basename($second));

        // Sửa thông tin khách không làm mất ảnh
        $this->putJson("/api/leads/{$lead->id}", ['parent_name' => 'Chị Hồng', 'phone' => '0123456789', 'source' => 'Facebook',
            'status' => 'called', 'image_url' => ''])->assertOk();
        $this->assertSame($second, $lead->fresh()->image_url);

        // Xóa ảnh
        $this->deleteJson("/api/leads/{$lead->id}/image")->assertOk();
        $this->assertNull($lead->fresh()->image_url);
        Storage::disk('public')->assertMissing('leads/'.basename($second));
    }
}
