<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mẫu tin nhắn theo từng trang: mỗi trang có nhóm mẫu riêng, thêm / sửa / xóa / khôi phục gốc không đụng mẫu trang khác.
 */
class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function group(string $group): array
    {
        return $this->getJson("/api/message-templates?group={$group}")->assertOk()->json();
    }

    public function test_each_page_gets_its_own_default_templates(): void
    {
        $attendance = $this->group('attendance');
        $this->assertSame(['Báo bài buổi học gửi phụ huynh', 'Thông báo học viên vắng học'], array_column($attendance['templates'], 'title'));
        $this->assertSame([['id' => 'attendance_report', 'label' => 'Báo bài / Điểm danh']], $attendance['categories']);

        $this->assertCount(4, $this->group('renewal')['templates']);
        $this->assertCount(3, $this->group('lead')['templates']);
        // Mở lại không tạo thêm mẫu mặc định
        $this->assertCount(2, $this->group('attendance')['templates']);
        $this->assertSame(9, MessageTemplate::count());
    }

    public function test_create_edit_delete_and_reset_stay_within_the_page(): void
    {
        $renewal = collect($this->group('renewal')['templates'])->keyBy('category');
        $report = $this->group('attendance')['templates'][0];

        // Thêm mẫu mới cho trang Khách hàng tiềm năng: chỉ hiện ở trang đó
        $id = $this->postJson('/api/message-templates', ['title' => 'Mời học thử cuối tuần', 'category' => 'lead_care',
            'template_content' => 'Chào {ten_phu_huynh}'])->assertCreated()->json('template.id');
        $this->assertContains($id, array_column($this->group('lead')['templates'], 'id'));
        $this->assertNotContains($id, array_column($this->group('renewal')['templates'], 'id'));
        $this->postJson('/api/message-templates', ['title' => 'x', 'category' => 'khong_co', 'template_content' => 'x'])->assertUnprocessable();

        // Sửa mẫu báo bài không đụng mẫu nhắc học phí (trước đây cùng id 1)
        $this->putJson("/api/message-templates/{$report['id']}", ['title' => 'Báo bài mới', 'template_content' => '{nhan_xet}', 'category' => 'tuition_reminder'])
            ->assertOk();
        $this->assertSame(['attendance_report', 'Báo bài mới'], [MessageTemplate::find($report['id'])->category, MessageTemplate::find($report['id'])->title]);
        $this->assertSame('Nhắc học phí khóa tiếp theo', MessageTemplate::find($renewal['tuition_reminder']['id'])->title);

        // Khôi phục gốc: cả tiêu đề lẫn nội dung
        $this->postJson("/api/message-templates/{$report['id']}/reset")->assertOk()
            ->assertJsonPath('template.title', 'Báo bài buổi học gửi phụ huynh');
        $this->assertStringContainsString('{nhan_xet}', MessageTemplate::find($report['id'])->template_content);
        $this->postJson("/api/message-templates/{$id}/reset")->assertUnprocessable();

        // Xóa: mẫu tự tạo xóa được, mẫu mặc định thì không
        $this->deleteJson("/api/message-templates/{$report['id']}")->assertUnprocessable();
        $this->deleteJson("/api/message-templates/{$id}")->assertOk();
        $this->assertNull(MessageTemplate::find($id));
    }

    public function test_migration_moves_template_overwritten_by_another_page_back_to_its_page(): void
    {
        // Dữ liệu như máy khách: mẫu id 1 (nhắc học phí) bị trang Điểm danh ghi đè thành "Báo bài buổi học gửi phụ huynh"
        $migration = require database_path('migrations/2026_10_31_180000_add_default_key_to_message_templates_table.php');
        $migration->down();
        DB::table('message_templates')->insert([
            ['id' => 1, 'category' => 'tuition_reminder', 'title' => 'Báo bài buổi học gửi phụ huynh', 'template_content' => 'Báo bài bé A', 'is_default' => true],
            ['id' => 2, 'category' => 'absence_alert', 'title' => 'Cảnh báo học viên vắng học 2 buổi liên tiếp', 'template_content' => 'Vắng', 'is_default' => true],
            ['id' => 4, 'category' => 'general_care', 'title' => 'Lời khen', 'template_content' => 'Khen', 'is_default' => false],
        ]);
        $migration->up();

        $rows = MessageTemplate::orderBy('id')->get()->keyBy('id');
        $this->assertSame(['attendance_report', 'attendance_report', 'Báo bài bé A'], [$rows[1]->default_key, $rows[1]->category, $rows[1]->template_content]);
        $this->assertSame('absence_alert', $rows[2]->default_key);
        $this->assertSame('general_care', $rows[4]->default_key);

        // Mở trang Tái đăng ký: mẫu nhắc học phí bị mất được tạo lại; trang Điểm danh giữ mẫu báo bài đã sửa
        $renewal = collect($this->group('renewal')['templates']);
        $this->assertSame(['absence_alert', 'general_care', 'payment_confirmation', 'tuition_reminder'], $renewal->pluck('category')->sort()->values()->all());
        $this->assertSame(['Báo bài bé A'], array_column($this->group('attendance')['templates'], 'template_content'));
    }
}
