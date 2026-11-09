<?php

namespace App\Support;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Công nợ học phí / học cụ của học viên (dùng chung cho Hóa đơn, trang Học viên, Cổng phụ huynh, Doanh thu).
 *
 * Mỗi khóa học viên đăng ký là một khoản phải thu:
 *  - Học phí = số đã chỉnh riêng cho khóa của học viên này (tuition_fee trong registered_courses), chưa chỉnh thì theo giá khóa
 *    ở màn Khóa học. Phiếu thu kiểu cũ (nhập học tự động khi thêm học viên, gia hạn cộng buổi; không có "lines") vừa là học phí
 *    đã báo vừa là tiền đã thu: khóa có phiếu cũ mà chưa chỉnh riêng thì học phí = tổng các phiếu cũ đó.
 *  - Đã thu = phiếu cũ đã thanh toán + các dòng thanh toán trên hóa đơn mới (đã thanh toán, chưa hủy).
 *  - Còn nợ = học phí - giảm giá - đã thu.
 * Học cụ đã giao cho học viên: phải thu = giá x số lượng; học cụ giao trước đây đã ghi doanh thu ngay (revenue_id) coi như đã thu.
 */
class Tuition
{
    /** @var array<int, float>|null giá khóa theo id */
    private static ?array $prices = null;

    public static function coursePrice(?int $courseId): float
    {
        self::$prices ??= Course::pluck('price', 'id')->map(fn ($p) => (float) $p)->all();

        return (float) (self::$prices[(int) $courseId] ?? 0);
    }

    /** Xóa bộ nhớ giá khóa (sau khi sửa giá / trong test). */
    public static function flush(): void
    {
        self::$prices = null;
    }

    /**
     * Khóa của học viên mà phiếu thu kiểu cũ thuộc về: theo course_item_id, không có thì khóa cùng course_id, không có thì khóa đầu tiên.
     */
    public static function legacyItemId(Student $student, Invoice $invoice): ?int
    {
        if ($invoice->course_item_id) {
            return (int) $invoice->course_item_id;
        }
        $items = $student->registered_courses_list;
        foreach ($items as $item) {
            if ((int) ($item['course_id'] ?? 0) === (int) $invoice->course_id) {
                return (int) $item['id'];
            }
        }

        return isset($items[0]['id']) ? (int) $items[0]['id'] : null;
    }

    private static function invoicesOf(Student $student, ?Collection $invoices): Collection
    {
        $invoices ??= $student->relationLoaded('invoices') ? $student->invoices : $student->invoices()->get();

        return $invoices->where('status', '!=', 'cancelled')->values();
    }

    private static function legacyInvoices(Student $student, Collection $invoices, int $itemId): Collection
    {
        return $invoices->filter(fn ($inv) => empty($inv->lines) && self::legacyItemId($student, $inv) === $itemId);
    }

    /** Học phí của khóa khi chưa chỉnh riêng: tổng phiếu cũ, không có thì giá khóa. */
    private static function standardFee(array $item, Collection $legacy): float
    {
        return $legacy->isNotEmpty() ? (float) $legacy->sum('amount') : self::coursePrice($item['course_id'] ?? null);
    }

    /**
     * Sắp đổi giá khóa: khóa học viên đã đóng tiền (hoặc đã được giảm giá) đang tính theo giá khóa thì chốt lại giá cũ,
     * để khóa đã đóng đủ không tự phát sinh nợ. Học viên chưa đóng đồng nào theo giá mới.
     */
    public static function keepPaidFees(int $courseId, float $oldPrice): void
    {
        DB::transaction(function () use ($courseId, $oldPrice): void {
            foreach (Student::with('invoices')->get() as $student) {
                $list = $student->registered_courses_list;
                $invoices = self::invoicesOf($student, $student->invoices);
                $rows = collect(self::courses($student, $invoices))->keyBy('course_item_id');
                $changed = false;

                foreach ($list as $key => $item) {
                    $itemId = (int) $item['id'];
                    $followsPrice = (int) ($item['course_id'] ?? 0) === $courseId && ! isset($item['tuition_fee'])
                        && self::legacyInvoices($student, $invoices, $itemId)->isEmpty();

                    if ($followsPrice && $rows[$itemId]['paid'] + $rows[$itemId]['discount'] > 0) {
                        $list[$key]['tuition_fee'] = $oldPrice;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $student->registered_courses = $list;
                    $student->save();
                }
            }
        });
    }

    /**
     * Lý do không đổi được học phí của khóa ($fee = null: bỏ số đã chỉnh, về học phí mặc định), hợp lệ thì null.
     */
    public static function feeChangeError(Student $student, array $item, ?float $fee): ?string
    {
        $itemId = (int) $item['id'];
        $row = collect(self::courses($student))->firstWhere('course_item_id', $itemId);

        if ($row['pending_invoice']) {
            return "Khóa {$row['name']} đang có phiếu chờ thu {$row['pending_invoice']['invoice_code']}: hãy sửa số tiền của phiếu đó ở trang Hóa đơn.";
        }

        $newFee = $fee ?? self::standardFee($item, self::legacyInvoices($student, self::invoicesOf($student, null), $itemId));
        $settled = $row['paid'] + $row['discount'];
        if ($newFee < $settled - 0.5) {
            return "Khóa {$row['name']} đã thu".($row['discount'] > 0 ? ' và giảm giá' : '').' '.number_format($settled, 0, ',', '.')
                .'đ nên học phí không thể thấp hơn số này. Nếu số đã thu bị sai, hãy sửa hóa đơn trước.';
        }

        return null;
    }

    /**
     * Các khoản học phí theo khóa của học viên.
     *
     * @return list<array{key: string, type: string, course_item_id: int, course_id: ?int, name: string, fee: float, discount: float,
     *     paid: float, debt: float, total_sessions: int, remaining_sessions: int, completed: bool, pending_invoice: ?array}>
     */
    public static function courses(Student $student, ?Collection $invoices = null): array
    {
        $invoices = self::invoicesOf($student, $invoices);
        $result = [];

        foreach ($student->registered_courses_list as $item) {
            $itemId = (int) $item['id'];
            $legacy = self::legacyInvoices($student, $invoices, $itemId);
            $fee = isset($item['tuition_fee']) ? (float) $item['tuition_fee'] : self::standardFee($item, $legacy);
            $paid = (float) $legacy->where('status', 'paid')->sum('amount');
            $discount = 0.0;

            foreach ($invoices as $inv) {
                foreach ($inv->lines ?? [] as $line) {
                    if (($line['type'] ?? '') === 'course' && (int) ($line['course_item_id'] ?? 0) === $itemId) {
                        $discount += (float) ($line['discount'] ?? 0);
                        if ($inv->status === 'paid') {
                            $paid += (float) ($line['amount'] ?? 0);
                        }
                    }
                }
            }

            $pending = $legacy->firstWhere('status', 'pending');
            $result[] = [
                'key' => 'course:'.$itemId,
                'type' => 'course',
                'course_item_id' => $itemId,
                'course_id' => isset($item['course_id']) ? (int) $item['course_id'] : null,
                'name' => $item['course_name'] ?? 'Khóa học',
                'fee' => $fee,
                'discount' => $discount,
                'paid' => $paid,
                'debt' => max(0, $fee - $discount - $paid),
                'total_sessions' => (int) ($item['total_sessions'] ?? 0),
                'remaining_sessions' => (int) ($item['remaining_sessions'] ?? 0),
                'completed' => ($item['status'] ?? 'active') === 'completed',
                // Phiếu chờ thu kiểu cũ (nhập học "chờ thu"): phụ huynh đóng thì xác nhận phiếu này, không lập thêm hóa đơn
                'pending_invoice' => $pending ? ['id' => $pending->id, 'invoice_code' => $pending->invoice_code, 'amount' => (float) $pending->amount] : null,
            ];
        }

        return $result;
    }

    /**
     * Học cụ đã giao cho học viên và số tiền còn phải thu.
     *
     * @return list<array{key: string, type: string, supply_id: string, name: string, quantity: int, price: float, total: float, paid: float, debt: float, delivery_date: ?string}>
     */
    public static function supplies(Student $student, ?Collection $invoices = null): array
    {
        $invoices = self::invoicesOf($student, $invoices);
        $result = [];

        foreach ($student->supplies ?? [] as $supply) {
            $id = (string) ($supply['id'] ?? '');
            $total = (float) ($supply['price'] ?? 0) * (int) ($supply['quantity'] ?? 0);
            // Giao trước đây đã ghi doanh thu ngay lúc giao: coi như đã thu
            $paid = ! empty($supply['revenue_id']) ? $total : 0.0;
            foreach ($invoices->where('status', 'paid') as $inv) {
                foreach ($inv->lines ?? [] as $line) {
                    if (($line['type'] ?? '') === 'supply' && (string) ($line['supply_id'] ?? '') === $id) {
                        $paid += (float) ($line['amount'] ?? 0);
                    }
                }
            }

            $result[] = [
                'key' => 'supply:'.$id,
                'type' => 'supply',
                'supply_id' => $id,
                'name' => $supply['name'] ?? 'Học cụ',
                'quantity' => (int) ($supply['quantity'] ?? 0),
                'price' => (float) ($supply['price'] ?? 0),
                'total' => $total,
                'paid' => $paid,
                'debt' => max(0, $total - $paid),
                'delivery_date' => $supply['delivery_date'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Tổng hợp công nợ của học viên.
     *
     * @return array{fee: float, paid: float, debt: float, courses: array, supplies: array}
     */
    public static function summary(Student $student, ?Collection $invoices = null): array
    {
        $courses = self::courses($student, $invoices);
        $supplies = self::supplies($student, $invoices);
        $all = [...$courses, ...$supplies];

        return [
            'fee' => array_sum(array_map(fn ($r) => $r['type'] === 'course' ? $r['fee'] - $r['discount'] : $r['total'], $all)),
            'paid' => array_sum(array_column($all, 'paid')),
            'debt' => array_sum(array_column($all, 'debt')),
            'courses' => $courses,
            'supplies' => $supplies,
        ];
    }

    /**
     * Id các học viên còn nợ học phí / học cụ (lọc "Chưa thanh toán" ở danh sách học viên).
     *
     * @return list<int>
     */
    public static function studentIdsWithDebt(): array
    {
        return Student::with('invoices')->get()
            ->filter(fn ($st) => self::summary($st, $st->invoices)['debt'] > 0)
            ->pluck('id')->all();
    }

    /**
     * Tổng công nợ (doanh thu dự kiến còn phải thu) của học viên đang học / tạm dừng, theo trung tâm.
     */
    public static function receivable(string $center = 'all'): float
    {
        return (float) Student::with('invoices')->whereNotIn('status', ['inactive', 'dropped'])
            ->when($center !== 'all', fn ($q) => $q->where('center', $center))->get()
            ->sum(fn ($st) => self::summary($st, $st->invoices)['debt']);
    }

    /**
     * Số tiền của hóa đơn theo loại (để màn Doanh thu tách học phí / học cụ). Phiếu cũ: toàn bộ là học phí.
     *
     * @return array{tuition: float, supply: float}
     */
    public static function split(Invoice $invoice): array
    {
        if (empty($invoice->lines)) {
            return ['tuition' => (float) $invoice->amount, 'supply' => 0.0];
        }
        $supply = 0.0;
        foreach ($invoice->lines as $line) {
            if (($line['type'] ?? '') === 'supply') {
                $supply += (float) ($line['amount'] ?? 0);
            }
        }

        return ['tuition' => (float) $invoice->amount - $supply, 'supply' => $supply];
    }
}
