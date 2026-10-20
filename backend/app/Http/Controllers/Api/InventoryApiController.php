<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CenterStore;
use App\Support\InventoryImport;
use App\Support\SupplySales;
use Carbon\Carbon;
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
                $oldQty = (int) ($it['quantity'] ?? 0);
                $soldItem = $it;
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

        // Xuất kho để bán → ghi doanh thu học cụ theo giá bán
        $sold = 0;
        if ((int) $validated['delta'] < 0 && str_starts_with($validated['reason'] ?? '', 'Bán')) {
            $sold = min(abs((int) $validated['delta']), $oldQty);
            SupplySales::recordRevenue([['name' => $soldItem['name'], 'quantity' => $sold, 'price' => $soldItem['sell_price'] ?? 0]], [
                'date' => Carbon::today()->toDateString(),
                'center' => $soldItem['center'] ?? null,
                'recorded_by' => $request->user()?->name ?? 'Admin',
                'note' => $validated['reason'],
            ]);
        }

        return response()->json([
            'message' => $sold > 0 ? 'Đã xuất kho và ghi doanh thu học cụ!' : 'Cập nhật số lượng tồn kho thành công!',
            'new_quantity' => $newQty,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return InventoryImport::export(trim((string) $request->input('center', 'all')));
    }

    public function template(): StreamedResponse
    {
        return InventoryImport::template();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required_without:csv_file|file|mimes:xlsx,xls,csv,txt|max:5120',
            'csv_file' => 'nullable|file|mimes:xlsx,xls,csv,txt|max:5120',
        ], [
            'file.required_without' => 'Vui lòng chọn file Excel để nhập!',
            'file.mimes' => 'File phải là Excel (.xlsx) theo mẫu của hệ thống.',
        ]);

        try {
            $result = InventoryImport::import($request->file('file') ?? $request->file('csv_file'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = "Đã thêm {$result['imported_count']} học cụ";
        if ($result['updated_count'] > 0) {
            $message .= ", cập nhật {$result['updated_count']} học cụ trùng mã";
        }
        if ($result['skipped']) {
            $message .= ', bỏ qua '.count($result['skipped']).' dòng';
        }

        return response()->json(['message' => $message.'.', ...$result]);
    }
}
