<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CenterStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class InventoryExcelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());
    }

    /**
     * Lưu file Excel tải về từ API ra file tạm để đọc / sửa lại
     */
    private function downloadXlsx(string $uri): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        file_put_contents($path, $this->get($uri)->assertOk()->streamedContent());

        return $path;
    }

    private function upload(string $path, string $name = 'hoc_cu.xlsx'): array
    {
        return $this->post('/api/inventory/import', ['file' => new UploadedFile($path, $name, null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->json();
    }

    private function items(): array
    {
        return $this->getJson('/api/inventory')->json('items');
    }

    public function test_template_has_guide_and_can_be_filled_and_imported(): void
    {
        $path = $this->downloadXlsx('/api/inventory/template');
        $book = IOFactory::load($path);
        $this->assertSame(['Hướng dẫn', 'Học cụ'], $book->getSheetNames());

        $sheet = $book->getSheetByName('Học cụ');
        $this->assertSame('Tên học cụ *', $sheet->getCell('B1')->getValue());
        $sheet->fromArray([
            ['HC-01', 'Giáo trình Alfred', 'Giáo trình', 'Cuốn', '', 60000, '120.000', 20, '', ''],
            ['', '', 'Phụ kiện', 'Cái', '', '', '', '', '', 'thiếu tên'],
            ['', 'Capo', 'Phụ kiện', '', 'Cơ sở không có', '35000', '70000', '5', '2', ''],
        ], null, 'A2');
        $sheet->setCellValueExplicit('G2', '120.000', DataType::TYPE_STRING); // ô dạng Text gõ "120.000"
        (new Xlsx($book))->save($path);

        $result = $this->upload($path);
        $this->assertSame(2, $result['imported_count']);
        $this->assertSame([['row' => 3, 'reason' => 'Thiếu Tên học cụ']], $result['skipped']);
        $this->assertStringContainsString('Cơ sở không có', implode(' ', $result['warnings']));

        $alfred = collect($this->items())->firstWhere('code', 'HC-01');
        $this->assertEquals(60000, $alfred['cost_price']);
        $this->assertEquals(120000, $alfred['sell_price']);
        $this->assertSame(20, $alfred['quantity']);
        $this->assertSame(3, $alfred['min_threshold']);
        $this->assertSame(CenterStore::defaultName(), $alfred['center']);
        $this->assertSame('Cái', collect($this->items())->firstWhere('name', 'Capo')['unit']);
    }

    public function test_exported_file_can_be_edited_and_reimported_by_code(): void
    {
        $this->postJson('/api/inventory', ['code' => 'HC-01', 'name' => 'Capo', 'unit' => 'Cái', 'center' => CenterStore::defaultName(), 'cost_price' => 5000, 'sell_price' => 10000, 'quantity' => 3])->assertCreated();

        $path = $this->downloadXlsx('/api/inventory/export');
        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        $this->assertSame('Capo', $sheet->getCell('B2')->getValue());
        $sheet->setCellValue('G2', 15000)->setCellValue('H2', 9);
        (new Xlsx($book))->save($path);

        $result = $this->upload($path);
        $this->assertSame([0, 1], [$result['imported_count'], $result['updated_count']]);
        $this->assertCount(1, $this->items());
        $this->assertEquals(15000, $this->items()[0]['sell_price']);
        $this->assertSame(9, $this->items()[0]['quantity']);
    }

    public function test_old_csv_file_is_still_accepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($path, "\xEF\xBB\xBFMã học cụ (Để trống nếu tự tạo),Tên vật phẩm (*),Phân loại,Đơn vị tính (*),Cơ sở / Trung tâm (*),Giá nhập (VNĐ),Giá bán (VNĐ),Số lượng tồn\n,Dây đàn,Phụ kiện,Bộ,,130000,190000,15\n");

        $this->assertSame(1, $this->upload($path, 'cu.csv')['imported_count']);
        $this->assertSame(15, $this->items()[0]['quantity']);
    }
}
