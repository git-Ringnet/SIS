<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Bán / giao học cụ: trừ tồn kho (inventory_data.json) và ghi doanh thu học cụ (custom_revenues.json)
 * để màn Doanh thu loại "Học cụ" và tồn kho luôn khớp với thực tế.
 */
class SupplySales
{
    private const INVENTORY_PATH = 'inventory_data.json';

    private const REVENUE_PATH = 'custom_revenues.json';

    private static function read(string $path): array
    {
        $data = Storage::exists($path) ? json_decode(Storage::get($path), true) : [];

        return is_array($data) ? $data : [];
    }

    private static function write(string $path, array $data): void
    {
        Storage::put($path, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Vị trí học cụ trong kho: theo id, không có id thì theo tên trong trung tâm
     */
    private static function findIndex(array $inventory, array $item, ?string $center): ?int
    {
        foreach ($inventory as $i => $row) {
            $matches = ! empty($item['inventory_id'])
                ? (int) ($row['id'] ?? 0) === (int) $item['inventory_id']
                : mb_strtolower(trim($row['name'] ?? '')) === mb_strtolower(trim($item['name']))
                    && (! $center || ($row['center'] ?? '') === $center);
            if ($matches) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Lỗi tồn kho của danh sách giao: học cụ không có trong kho của trung tâm, hoặc giao nhiều hơn số còn lại.
     * Cùng một học cụ chọn nhiều dòng thì cộng dồn số lượng.
     *
     * @param  list<array{inventory_id?: int|null, name: string, quantity: int}>  $items
     * @return list<string>
     */
    public static function stockErrors(array $items, ?string $center): array
    {
        $inventory = self::read(self::INVENTORY_PATH);
        $wanted = [];
        $errors = [];

        foreach ($items as $item) {
            $index = self::findIndex($inventory, $item, $center);
            if ($index === null) {
                $errors[] = "\"{$item['name']}\" không có trong kho".($center ? " của {$center}" : '').'.';

                continue;
            }
            $wanted[$index] = ($wanted[$index] ?? 0) + (int) $item['quantity'];
        }

        foreach ($wanted as $index => $qty) {
            $row = $inventory[$index];
            $stock = (int) ($row['quantity'] ?? 0);
            if ($center && ($row['center'] ?? '') !== $center) {
                $errors[] = "\"{$row['name']}\" thuộc kho {$row['center']}, không phải {$center}.";
            } elseif ($qty > $stock) {
                $errors[] = "\"{$row['name']}\" chỉ còn {$stock} ".($row['unit'] ?? '')." trong kho, không giao được {$qty}.";
            }
        }

        return array_map(fn ($e) => preg_replace('/\s+/u', ' ', $e), $errors);
    }

    /**
     * Trừ tồn kho (gọi sau khi stockErrors() không còn lỗi)
     *
     * @param  list<array{inventory_id?: int|null, name: string, quantity: int}>  $items
     */
    public static function deductStock(array $items, ?string $center): void
    {
        $inventory = self::read(self::INVENTORY_PATH);

        foreach ($items as $item) {
            $index = self::findIndex($inventory, $item, $center);
            if ($index !== null) {
                $inventory[$index]['quantity'] = max(0, (int) ($inventory[$index]['quantity'] ?? 0) - (int) $item['quantity']);
            }
        }

        self::write(self::INVENTORY_PATH, $inventory);
    }

    /**
     * Ghi một dòng doanh thu học cụ. Bỏ qua khi tổng tiền bằng 0 (học cụ tặng kèm).
     *
     * @param  list<array{name: string, quantity: int, price?: float|int|null}>  $items
     */
    public static function recordRevenue(array $items, array $meta): ?array
    {
        $total = array_sum(array_map(fn ($it) => (float) ($it['price'] ?? 0) * (int) $it['quantity'], $items));
        if ($total <= 0) {
            return null;
        }

        $tx = [
            'id' => 'rev_custom_'.uniqid(),
            'recorded_by' => $meta['recorded_by'] ?? 'Admin',
            'date' => $meta['date'],
            'student_name' => ($meta['student_name'] ?? null) ?: 'Khách mua học cụ',
            'course_name' => '-',
            'inventory_name' => implode(', ', array_map(fn ($it) => $it['name'].' x'.$it['quantity'], $items)),
            'payment_method' => $meta['payment_method'] ?? 'Tiền mặt',
            'account_name' => $meta['account_name'] ?? 'Tiền mặt tại quầy',
            'tuition_fee' => 0,
            'inventory_fee' => $total,
            'total_amount' => $total,
            'center' => ($meta['center'] ?? null) ?: CenterStore::defaultName(),
            'type' => 'inventory',
            'note' => $meta['note'] ?? '',
        ];

        $revenues = self::read(self::REVENUE_PATH);
        $revenues[] = $tx;
        self::write(self::REVENUE_PATH, $revenues);

        return $tx;
    }
}
