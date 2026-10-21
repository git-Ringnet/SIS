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
     * Nhập lại kho khi thu hồi học cụ đã giao nhầm. Học cụ đã bị xoá khỏi kho thì bỏ qua (trả về false).
     *
     * @param  array{inventory_id?: int|null, name: string, quantity: int}  $item
     */
    public static function restock(array $item, ?string $center): bool
    {
        $inventory = self::read(self::INVENTORY_PATH);
        $index = self::findIndex($inventory, $item, $center);
        if ($index === null) {
            return false;
        }

        $inventory[$index]['quantity'] = (int) ($inventory[$index]['quantity'] ?? 0) + (int) $item['quantity'];
        self::write(self::INVENTORY_PATH, $inventory);

        return true;
    }

    /**
     * Bớt doanh thu học cụ khi thu hồi: trừ tiền của số lượng thu hồi khỏi dòng doanh thu lúc giao
     * (theo revenue_id; học cụ giao trước khi lưu revenue_id thì tìm theo học viên + ngày giao + tên học cụ).
     * Dòng doanh thu về 0 thì xoá. Trả về số tiền đã bớt.
     *
     * @param  array{name: string, price?: float|int|null, delivery_date?: string, revenue_id?: string|null}  $supply
     */
    public static function reverseRevenue(array $supply, int $quantity, string $studentName): float
    {
        $amount = (float) ($supply['price'] ?? 0) * $quantity;
        if ($amount <= 0) {
            return 0;
        }

        $revenues = self::read(self::REVENUE_PATH);
        foreach ($revenues as $i => $tx) {
            $matches = ! empty($supply['revenue_id'])
                ? ($tx['id'] ?? null) === $supply['revenue_id']
                : ($tx['type'] ?? null) === 'inventory'
                    && ($tx['student_name'] ?? null) === $studentName
                    && ($tx['date'] ?? null) === ($supply['delivery_date'] ?? null)
                    && str_contains($tx['inventory_name'] ?? '', $supply['name']);
            if (! $matches) {
                continue;
            }

            $remaining = max(0, (float) ($tx['inventory_fee'] ?? 0) - $amount);
            if ($remaining <= 0) {
                unset($revenues[$i]);
            } else {
                $revenues[$i]['inventory_fee'] = $remaining;
                $revenues[$i]['total_amount'] = max(0, (float) ($tx['total_amount'] ?? 0) - $amount);
                $revenues[$i]['note'] = trim(($tx['note'] ?? '')."\nThu hồi {$supply['name']} x{$quantity}");
            }
            self::write(self::REVENUE_PATH, $revenues);

            return $amount;
        }

        return 0;
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
