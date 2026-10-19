<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CenterStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryApiController extends Controller
{
    private function getInventoryDataPath(): string
    {
        return 'inventory_data.json';
    }

    private function loadInventoryData(): array
    {
        $path = $this->getInventoryDataPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Chưa có dữ liệu thì bắt đầu với kho trống
        return [];
    }

    private function saveInventoryData(array $items): void
    {
        Storage::put($this->getInventoryDataPath(), json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->loadInventoryData();
        $search = trim($request->input('search', ''));
        $center = trim($request->input('center', 'all'));

        // Lọc theo tìm kiếm
        if (! empty($search)) {
            $searchLower = mb_strtolower($search, 'UTF-8');
            $items = array_values(array_filter($items, function ($it) use ($searchLower) {
                return str_contains(mb_strtolower($it['name'] ?? '', 'UTF-8'), $searchLower)
                    || str_contains(mb_strtolower($it['code'] ?? '', 'UTF-8'), $searchLower)
                    || str_contains(mb_strtolower($it['category'] ?? '', 'UTF-8'), $searchLower);
            }));
        }

        // Lọc theo trung tâm
        if ($center !== 'all' && ! empty($center)) {
            $items = array_values(array_filter($items, function ($it) use ($center) {
                return ($it['center'] ?? '') === $center;
            }));
        }

        $centers = CenterStore::names();

        $totalStockValue = array_reduce($items, function ($acc, $it) {
            return $acc + (($it['quantity'] ?? 0) * ($it['cost_price'] ?? 0));
        }, 0);

        return response()->json([
            'items' => $items,
            'total' => count($items),
            'centers' => $centers,
            'total_stock_value' => $totalStockValue,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'unit' => 'required|string|max:50',
            'center' => 'required|string|max:255',
            'cost_price' => 'required|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'min_threshold' => 'nullable|integer|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        $items = $this->loadInventoryData();
        $newId = count($items) > 0 ? max(array_column($items, 'id')) + 1 : 1;

        $code = ! empty($validated['code'])
            ? strtoupper(trim($validated['code']))
            : 'HC-'.strtoupper(substr(md5(uniqid()), 0, 6));

        $newItem = [
            'id' => $newId,
            'name' => $validated['name'],
            'code' => $code,
            'category' => $validated['category'] ?? 'Học cụ',
            'unit' => $validated['unit'],
            'center' => $validated['center'],
            'cost_price' => (float) $validated['cost_price'],
            'sell_price' => (float) $validated['sell_price'],
            'quantity' => (int) $validated['quantity'],
            'min_threshold' => isset($validated['min_threshold']) ? (int) $validated['min_threshold'] : 3,
            'note' => $validated['note'] ?? '',
        ];

        $items[] = $newItem;
        $this->saveInventoryData($items);

        return response()->json([
            'message' => 'Thêm học cụ thành công!',
            'item' => $newItem,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'unit' => 'required|string|max:50',
            'center' => 'required|string|max:255',
            'cost_price' => 'required|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'min_threshold' => 'nullable|integer|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        $items = $this->loadInventoryData();
        $found = false;

        foreach ($items as &$it) {
            if ($it['id'] === $id) {
                $it['name'] = $validated['name'];
                if (! empty($validated['code'])) {
                    $it['code'] = strtoupper(trim($validated['code']));
                }
                $it['category'] = $validated['category'] ?? ($it['category'] ?? 'Học cụ');
                $it['unit'] = $validated['unit'];
                $it['center'] = $validated['center'];
                $it['cost_price'] = (float) $validated['cost_price'];
                $it['sell_price'] = (float) $validated['sell_price'];
                $it['quantity'] = (int) $validated['quantity'];
                $it['min_threshold'] = isset($validated['min_threshold']) ? (int) $validated['min_threshold'] : ($it['min_threshold'] ?? 3);
                $it['note'] = $validated['note'] ?? '';
                $found = true;
                break;
            }
        }

        if (! $found) {
            return response()->json(['message' => 'Không tìm thấy học cụ!'], 404);
        }

        $this->saveInventoryData($items);

        return response()->json(['message' => 'Cập nhật học cụ thành công!']);
    }

    public function destroy(int $id): JsonResponse
    {
        $items = $this->loadInventoryData();
        $filtered = array_values(array_filter($items, fn ($it) => $it['id'] !== $id));

        if (count($items) === count($filtered)) {
            return response()->json(['message' => 'Không tìm thấy học cụ cần xóa!'], 404);
        }

        $this->saveInventoryData($filtered);

        return response()->json(['message' => 'Đã xóa học cụ thành công!']);
    }

    public function adjust(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'delta' => 'required|integer', // + hoặc - số lượng
            'reason' => 'nullable|string|max:255',
        ]);

        $items = $this->loadInventoryData();
        $found = false;

        foreach ($items as &$it) {
            if ($it['id'] === $id) {
                $newQty = max(0, ($it['quantity'] ?? 0) + (int) $validated['delta']);
                $it['quantity'] = $newQty;
                $found = true;
                break;
            }
        }

        if (! $found) {
            return response()->json(['message' => 'Không tìm thấy học cụ!'], 404);
        }

        $this->saveInventoryData($items);

        return response()->json([
            'message' => 'Cập nhật số lượng tồn kho thành công!',
            'new_quantity' => $newQty,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $items = $this->loadInventoryData();
        $center = trim($request->input('center', 'all'));

        if ($center !== 'all' && ! empty($center)) {
            $items = array_values(array_filter($items, fn ($it) => ($it['center'] ?? '') === $center));
        }

        $fileName = 'kho_hoc_cu_'.date('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($items) {
            $output = fopen('php://output', 'w');
            // Ghi UTF-8 BOM để Excel hiển thị tiếng Việt không bị lỗi font
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Mã học cụ',
                'Tên vật phẩm',
                'Phân loại',
                'Đơn vị tính',
                'Cơ sở / Trung tâm',
                'Giá nhập (VNĐ)',
                'Giá bán (VNĐ)',
                'Số lượng tồn',
                'Cảnh báo tối thiểu',
                'Ghi chú',
            ]);

            foreach ($items as $it) {
                fputcsv($output, [
                    $it['code'] ?? '',
                    $it['name'] ?? '',
                    $it['category'] ?? '',
                    $it['unit'] ?? '',
                    $it['center'] ?? '',
                    $it['cost_price'] ?? 0,
                    $it['sell_price'] ?? 0,
                    $it['quantity'] ?? 0,
                    $it['min_threshold'] ?? 3,
                    $it['note'] ?? '',
                ]);
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    public function template(): StreamedResponse
    {
        $fileName = 'mau_nhap_hoc_cu.csv';

        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Mã học cụ (Để trống nếu tự tạo)',
                'Tên vật phẩm (*)',
                'Phân loại',
                'Đơn vị tính (*)',
                'Cơ sở / Trung tâm (*)',
                'Giá nhập (VNĐ)',
                'Giá bán (VNĐ)',
                'Số lượng tồn',
                'Mức cảnh báo tối thiểu',
                'Ghi chú',
            ]);

            // Mẫu dòng ví dụ
            fputcsv($output, [
                'HC-ALFRED-01',
                'Giáo trình Piano Alfred Tập 1',
                'Giáo trình',
                'Cuốn',
                CenterStore::defaultName(),
                60000,
                120000,
                20,
                5,
                'Sách bìa cứng',
            ]);
            fputcsv($output, [
                '',
                'Capo Guitar Alice A007',
                'Phụ kiện',
                'Cái',
                CenterStore::defaultName(),
                35000,
                70000,
                15,
                3,
                'Màu đen',
            ]);

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return response()->json(['message' => 'Không thể đọc file CSV!'], 400);
        }

        // Bỏ qua BOM nếu có
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return response()->json(['message' => 'File CSV không có nội dung!'], 400);
        }

        $items = $this->loadInventoryData();
        $maxId = count($items) > 0 ? max(array_column($items, 'id')) : 0;
        $importedCount = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue;
            }

            $name = trim($row[1] ?? '');
            if (empty($name)) {
                continue;
            }

            $code = trim($row[0] ?? '');
            if (empty($code)) {
                $code = 'HC-'.strtoupper(substr(md5(uniqid()), 0, 6));
            }

            $category = trim($row[2] ?? '') ?: 'Học cụ';
            $unit = trim($row[3] ?? '') ?: 'Cái';
            $center = trim($row[4] ?? '') ?: CenterStore::defaultName();
            $costPrice = isset($row[5]) ? (float) str_replace([',', '.'], '', $row[5]) : 0;
            $sellPrice = isset($row[6]) ? (float) str_replace([',', '.'], '', $row[6]) : 0;
            $quantity = isset($row[7]) ? (int) $row[7] : 0;
            $minThreshold = isset($row[8]) ? (int) $row[8] : 3;
            $note = trim($row[9] ?? '');

            $maxId++;
            $items[] = [
                'id' => $maxId,
                'name' => $name,
                'code' => $code,
                'category' => $category,
                'unit' => $unit,
                'center' => $center,
                'cost_price' => $costPrice,
                'sell_price' => $sellPrice,
                'quantity' => $quantity,
                'min_threshold' => $minThreshold,
                'note' => $note,
            ];
            $importedCount++;
        }

        fclose($handle);

        $this->saveInventoryData($items);

        return response()->json([
            'message' => "Đã nhập thành công {$importedCount} học cụ!",
            'imported_count' => $importedCount,
        ]);
    }
}
