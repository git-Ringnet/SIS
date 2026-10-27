<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\BrandSettings;
use App\Support\CenterStore;
use App\Support\PagePermissions;
use App\Support\TeacherPayroll;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RevenueApiController extends Controller
{
    private function getCustomRevenuesPath(): string
    {
        return 'custom_revenues.json';
    }

    private function getExpensesPath(): string
    {
        return 'expenses_data.json';
    }

    private function loadCustomRevenues(): array
    {
        $path = $this->getCustomRevenuesPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function loadExpenses(): array
    {
        $path = $this->getExpensesPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    public function index(Request $request): JsonResponse
    {
        $period = $request->input('period', 'monthly'); // monthly, quarterly, yearly, all
        $year = $request->input('year', (string) Carbon::now()->year);
        $month = $request->input('month', (string) Carbon::now()->month);
        $center = $request->input('center', 'all');
        $type = $request->input('type', 'all'); // all, course, inventory

        // 1. Lấy tất cả Hóa đơn từ DB
        $invoices = Invoice::with(['student', 'course'])
            ->where('status', 'paid')
            ->orderBy('paid_at', 'desc')
            ->get();

        $transactions = [];

        $brand = BrandSettings::brand();
        $bankAccountName = trim(($brand['bank_name'] ?: 'Chuyển khoản').' - '.$brand['brand_name'], ' -');

        foreach ($invoices as $inv) {
            $paidDate = $inv->paid_at ? Carbon::parse($inv->paid_at) : Carbon::parse($inv->created_at);
            $studentName = $inv->student ? $inv->student->name : 'Khách vãng lai';
            $courseName = $inv->course ? $inv->course->name : ($inv->student && $inv->student->course ? $inv->student->course->name : 'Học phí khóa học');
            $invCenter = $inv->center ?: ($inv->student?->center ?: CenterStore::defaultName());

            $methodName = match ($inv->payment_method) {
                'bank_transfer' => 'Chuyển khoản',
                'cash' => 'Tiền mặt',
                'pos' => 'Quẹt thẻ POS',
                'momo' => 'Ví MoMo',
                default => 'Chuyển khoản',
            };

            $accountName = match ($inv->payment_method) {
                'bank_transfer' => $bankAccountName,
                'cash' => 'Tiền mặt tại quầy',
                'pos' => 'Máy POS quẹt thẻ',
                'momo' => 'Ví MoMo Doanh Nghiệp',
                default => 'Tài khoản trung tâm',
            };

            $transactions[] = [
                'id' => 'inv_'.$inv->id,
                'invoice_id' => $inv->id,
                'invoice_code' => $inv->invoice_code,
                'recorded_by' => $inv->recorded_by ?: 'Admin Thu Ngân',
                'date' => $paidDate->format('Y-m-d'),
                'date_formatted' => $paidDate->format('d/m/Y'),
                'day' => (int) $paidDate->format('j'),
                'month' => (int) $paidDate->format('n'),
                'year' => (int) $paidDate->format('Y'),
                'student_name' => $studentName,
                'course_name' => $courseName,
                'inventory_name' => '-',
                'payment_method' => $methodName,
                'account_name' => $accountName,
                'tuition_fee' => (float) $inv->amount,
                'inventory_fee' => 0,
                'total_amount' => (float) $inv->amount,
                'center' => $invCenter,
                'type' => 'course',
                'note' => $inv->note ?: '',
            ];
        }

        // 2. Thêm các giao dịch học cụ / doanh thu tùy chỉnh
        $customRevenues = $this->loadCustomRevenues();
        foreach ($customRevenues as $cr) {
            $cDate = Carbon::parse($cr['date']);
            $transactions[] = [
                'id' => $cr['id'],
                'recorded_by' => $cr['recorded_by'] ?? 'Khoa Anh (Admin)',
                'date' => $cDate->format('Y-m-d'),
                'date_formatted' => $cDate->format('d/m/Y'),
                'day' => (int) $cDate->format('j'),
                'month' => (int) $cDate->format('n'),
                'year' => (int) $cDate->format('Y'),
                'student_name' => $cr['student_name'] ?? 'Khách mua học cụ',
                'course_name' => $cr['course_name'] ?? '-',
                'inventory_name' => $cr['inventory_name'] ?? 'Học cụ',
                'payment_method' => $cr['payment_method'] ?? 'Tiền mặt',
                'account_name' => $cr['account_name'] ?? 'Tiền mặt tại quầy',
                'tuition_fee' => (float) ($cr['tuition_fee'] ?? 0),
                'inventory_fee' => (float) ($cr['inventory_fee'] ?? 0),
                'total_amount' => (float) ($cr['total_amount'] ?? 0),
                'center' => $cr['center'] ?? CenterStore::defaultName(),
                'type' => $cr['type'] ?? 'inventory',
                'note' => $cr['note'] ?? '',
            ];
        }

        $totalTransactionsCount = count($transactions);

        // 3. Lọc theo Bộ lọc người dùng chọn
        $filteredTransactions = array_filter($transactions, function ($tx) use ($year, $month, $center, $type) {
            if ($year !== 'all' && (string) $tx['year'] !== (string) $year) {
                return false;
            }
            if ($month !== 'all' && (string) $tx['month'] !== (string) $month) {
                return false;
            }
            if ($center !== 'all' && $tx['center'] !== $center) {
                return false;
            }
            if ($type !== 'all' && $tx['type'] !== $type) {
                return false;
            }

            return true;
        });

        // Sắp xếp ngày giảm dần
        usort($filteredTransactions, fn ($a, $b) => strcmp($b['date'], $a['date']));
        $filteredTransactions = array_values($filteredTransactions);

        // 4. Tính toán Metrics tháng này
        $activeMonth = $month !== 'all' ? (int) $month : (int) Carbon::now()->month;
        $activeYear = $year !== 'all' ? (int) $year : (int) Carbon::now()->year;

        $monthTransactions = array_filter($transactions, function ($tx) use ($activeMonth, $activeYear, $center) {
            if ($tx['year'] !== $activeYear || $tx['month'] !== $activeMonth) {
                return false;
            }
            if ($center !== 'all' && $tx['center'] !== $center) {
                return false;
            }

            return true;
        });

        $monthCourseTotal = 0;
        $monthInventoryTotal = 0;
        foreach ($monthTransactions as $mtx) {
            $monthCourseTotal += $mtx['tuition_fee'];
            $monthInventoryTotal += $mtx['inventory_fee'];
        }
        $revenueMonthTotal = $monthCourseTotal + $monthInventoryTotal;

        // 5. Chuẩn bị dữ liệu 31 ngày cho Biểu đồ Doanh thu (Chart)
        $daysInMonth = 31;
        $chartDays = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $chartDays[$d] = [
                'day' => $d,
                'course' => 0,
                'inventory' => 0,
                'total' => 0,
                'count' => 0,
            ];
        }

        foreach ($monthTransactions as $mtx) {
            $d = $mtx['day'];
            if ($d >= 1 && $d <= 31) {
                $chartDays[$d]['course'] += $mtx['tuition_fee'];
                $chartDays[$d]['inventory'] += $mtx['inventory_fee'];
                $chartDays[$d]['total'] += $mtx['total_amount'];
                $chartDays[$d]['count']++;
            }
        }

        // 6. Dữ liệu Chi phí (Expenses)
        $expenses = $this->loadExpenses();
        $filteredExpenses = array_filter($expenses, function ($ex) use ($activeYear, $activeMonth, $center) {
            $exDate = Carbon::parse($ex['date']);
            if ($exDate->year !== $activeYear || $exDate->month !== $activeMonth) {
                return false;
            }
            if ($center !== 'all' && $ex['center'] !== 'Tất cả trung tâm' && $ex['center'] !== $center) {
                return false;
            }

            return true;
        });
        $filteredExpenses = array_values($filteredExpenses);
        $manualExpense = array_sum(array_column($filteredExpenses, 'amount'));

        // Lương giáo viên / giáo vụ trong tháng: tự tính từ các ca đã check-in (cùng số với trang Check-in ca dạy).
        // Chỉ chủ trung tâm / quản lý xem được lương từng người; người khác chỉ thấy tổng.
        $payroll = TeacherPayroll::monthly(sprintf('%04d-%02d', $activeYear, $activeMonth), $center);
        if (! PagePermissions::seesAllPay($request->user())) {
            $payroll['teachers'] = [];
        }
        $totalExpense = $manualExpense + $payroll['total'];
        // Tháng này đã có khoản "Lương giáo viên" nhập tay trước đây → có thể bị tính hai lần
        $payroll['month'] = sprintf('%02d/%04d', $activeMonth, $activeYear);
        $payroll['manual_overlap'] = collect($filteredExpenses)->contains(fn ($ex) => ($ex['category'] ?? '') === 'Lương giáo viên');

        // 7. Lợi nhuận
        $grossProfit = $revenueMonthTotal - $totalExpense;
        $profitMargin = $revenueMonthTotal > 0 ? round(($grossProfit / $revenueMonthTotal) * 100, 1) : 0;

        $centers = CenterStore::names();
        $students = Student::with('course')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'center', 'course_id']);
        $courses = Course::where('is_active', true)->orderBy('name')->get(['id', 'name', 'price', 'default_sessions']);

        $pagination = null;
        $pageTransactions = $filteredTransactions;
        if ($this->wantsPagination($request)) {
            $paginator = $this->paginateArray($filteredTransactions, $request);
            $pageTransactions = $paginator->items();
            $pagination = $this->paginationMeta($paginator);
        }

        return response()->json([
            'metrics' => [
                'revenue_month_total' => $revenueMonthTotal,
                'course_total' => $monthCourseTotal,
                'inventory_total' => $monthInventoryTotal,
                'shown_transactions_count' => count($filteredTransactions),
                'total_transactions_count' => $totalTransactionsCount,
            ],
            'chart' => array_values($chartDays),
            'transactions' => $pageTransactions,
            'pagination' => $pagination,
            'expenses' => $filteredExpenses,
            'payroll' => $payroll,
            'profit' => [
                'total_revenue' => $revenueMonthTotal,
                'total_expense' => $totalExpense,
                'manual_expense' => $manualExpense,
                'payroll_expense' => $payroll['total'],
                'gross_profit' => $grossProfit,
                'profit_margin' => $profitMargin,
            ],
            'centers' => $centers,
            'students' => $students,
            'courses' => $courses,
        ]);
    }

    private function revenueRules(): array
    {
        return [
            'recorded_by' => 'required|string|max:100',
            'date' => 'required|date',
            'student_name' => 'nullable|string|max:255',
            'course_name' => 'nullable|string|max:255',
            'inventory_name' => 'nullable|string|max:255',
            'payment_method' => 'required|string',
            'account_name' => 'nullable|string|max:255',
            'tuition_fee' => 'nullable|numeric|min:0',
            'inventory_fee' => 'nullable|numeric|min:0',
            'center' => 'required|string',
            'note' => 'nullable|string',
        ];
    }

    private function buildCustomRevenue(array $validated, string $id): array
    {
        $tuition = (float) ($validated['tuition_fee'] ?? 0);
        $inventory = (float) ($validated['inventory_fee'] ?? 0);

        $type = 'both';
        if ($tuition > 0 && $inventory === 0.0) {
            $type = 'course';
        } elseif ($inventory > 0 && $tuition === 0.0) {
            $type = 'inventory';
        }

        return [
            'id' => $id,
            'recorded_by' => $validated['recorded_by'],
            'date' => $validated['date'],
            'student_name' => ($validated['student_name'] ?? null) ?: 'Khách hàng',
            'course_name' => ($validated['course_name'] ?? null) ?: '-',
            'inventory_name' => ($validated['inventory_name'] ?? null) ?: '-',
            'payment_method' => $validated['payment_method'],
            'account_name' => $validated['account_name'] ?? '',
            'tuition_fee' => $tuition,
            'inventory_fee' => $inventory,
            'total_amount' => $tuition + $inventory,
            'center' => $validated['center'],
            'type' => $type,
            'note' => $validated['note'] ?? '',
        ];
    }

    private function saveCustomRevenues(array $revenues): void
    {
        Storage::put($this->getCustomRevenuesPath(), json_encode(array_values($revenues), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function saveExpenses(array $expenses): void
    {
        Storage::put($this->getExpensesPath(), json_encode(array_values($expenses), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Dòng doanh thu sinh từ hoá đơn thì sửa / xoá ở màn Hóa đơn để số buổi của học viên khớp theo
     */
    private function invoiceRowResponse(string $id): ?JsonResponse
    {
        if (! str_starts_with($id, 'inv_')) {
            return null;
        }
        $code = Invoice::whereKey((int) substr($id, 4))->value('invoice_code');

        return response()->json([
            'message' => 'Dòng này đến từ hóa đơn '.($code ?: '').'. Hãy sửa hoặc xóa ở màn Hóa đơn để số buổi của học viên cập nhật theo.',
        ], 422);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->revenueRules());

        $newTx = $this->buildCustomRevenue($validated, 'rev_custom_'.uniqid());
        $customRevenues = $this->loadCustomRevenues();
        $customRevenues[] = $newTx;
        $this->saveCustomRevenues($customRevenues);

        return response()->json([
            'message' => 'Thêm doanh thu mới thành công!',
            'transaction' => $newTx,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($blocked = $this->invoiceRowResponse($id)) {
            return $blocked;
        }
        $validated = $request->validate($this->revenueRules());

        $revenues = $this->loadCustomRevenues();
        $index = array_search($id, array_column($revenues, 'id'), true);
        if ($index === false) {
            return response()->json(['message' => 'Không tìm thấy khoản doanh thu!'], 404);
        }
        $revenues[$index] = $this->buildCustomRevenue($validated, $id);
        $this->saveCustomRevenues($revenues);

        return response()->json([
            'message' => 'Đã cập nhật khoản doanh thu!',
            'transaction' => $revenues[$index],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        if ($blocked = $this->invoiceRowResponse($id)) {
            return $blocked;
        }

        $revenues = $this->loadCustomRevenues();
        $remaining = array_filter($revenues, fn ($r) => ($r['id'] ?? null) !== $id);
        if (count($remaining) === count($revenues)) {
            return response()->json(['message' => 'Không tìm thấy khoản doanh thu!'], 404);
        }
        $this->saveCustomRevenues($remaining);

        return response()->json(['message' => 'Đã xóa khoản doanh thu!']);
    }

    private function expenseRules(): array
    {
        return [
            'date' => 'required|date',
            'category' => 'required|string|max:100',
            'spender' => 'required|string|max:100',
            'content' => 'required|string|max:255',
            'account' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:0',
            'center' => 'required|string|max:100',
        ];
    }

    public function updateExpense(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->expenseRules());

        $expenses = $this->loadExpenses();
        foreach ($expenses as &$ex) {
            if ((int) ($ex['id'] ?? 0) === $id) {
                $ex = [...$ex, ...$validated, 'account' => $validated['account'] ?? '', 'amount' => (float) $validated['amount']];
                $this->saveExpenses($expenses);

                return response()->json(['message' => 'Đã cập nhật khoản chi!', 'expense' => $ex]);
            }
        }

        return response()->json(['message' => 'Không tìm thấy khoản chi!'], 404);
    }

    public function destroyExpense(int $id): JsonResponse
    {
        $expenses = $this->loadExpenses();
        $remaining = array_filter($expenses, fn ($ex) => (int) ($ex['id'] ?? 0) !== $id);
        if (count($remaining) === count($expenses)) {
            return response()->json(['message' => 'Không tìm thấy khoản chi!'], 404);
        }
        $this->saveExpenses($remaining);

        return response()->json(['message' => 'Đã xóa khoản chi!']);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $validated = $request->validate($this->expenseRules());

        $expenses = $this->loadExpenses();
        $newId = count($expenses) > 0 ? max(array_column($expenses, 'id')) + 1 : 1;

        $newExpense = [
            'id' => $newId,
            'date' => $validated['date'],
            'category' => $validated['category'],
            'spender' => $validated['spender'],
            'content' => $validated['content'],
            'account' => $validated['account'] ?? '',
            'amount' => (float) $validated['amount'],
            'center' => $validated['center'],
        ];

        $expenses[] = $newExpense;
        Storage::put($this->getExpensesPath(), json_encode($expenses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->json([
            'message' => 'Thêm khoản chi phí thành công!',
            'expense' => $newExpense,
        ], 201);
    }

    public function export(Request $request): StreamedResponse
    {
        $year = $request->input('year', 'all');
        $month = $request->input('month', 'all');
        $center = $request->input('center', 'all');

        $invoices = Invoice::with(['student', 'course'])->where('status', 'paid')->get();
        $transactions = [];

        foreach ($invoices as $inv) {
            $paidDate = $inv->paid_at ? Carbon::parse($inv->paid_at) : Carbon::parse($inv->created_at);
            $transactions[] = [
                'recorded_by' => $inv->recorded_by ?: 'Admin Thu Ngân',
                'date' => $paidDate->format('d/m/Y'),
                'year' => (string) $paidDate->year,
                'month' => (string) $paidDate->month,
                'student_name' => $inv->student ? $inv->student->name : 'Khách vãng lai',
                'course_name' => $inv->course ? $inv->course->name : ($inv->student && $inv->student->course ? $inv->student->course->name : '-'),
                'inventory_name' => '-',
                'payment_method' => $inv->payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản',
                'account_name' => 'Tài khoản trung tâm',
                'tuition_fee' => (float) $inv->amount,
                'inventory_fee' => 0,
                'total_amount' => (float) $inv->amount,
                'center' => $inv->student?->center ?: CenterStore::defaultName(),
            ];
        }

        $custom = $this->loadCustomRevenues();
        foreach ($custom as $cr) {
            $cDate = Carbon::parse($cr['date']);
            $transactions[] = [
                'recorded_by' => $cr['recorded_by'] ?? 'Khoa Anh (Admin)',
                'date' => $cDate->format('d/m/Y'),
                'year' => (string) $cDate->year,
                'month' => (string) $cDate->month,
                'student_name' => $cr['student_name'] ?? 'Khách mua học cụ',
                'course_name' => $cr['course_name'] ?? '-',
                'inventory_name' => $cr['inventory_name'] ?? 'Học cụ',
                'payment_method' => $cr['payment_method'] ?? 'Tiền mặt',
                'account_name' => $cr['account_name'] ?? 'Tiền mặt tại quầy',
                'tuition_fee' => (float) ($cr['tuition_fee'] ?? 0),
                'inventory_fee' => (float) ($cr['inventory_fee'] ?? 0),
                'total_amount' => (float) ($cr['total_amount'] ?? 0),
                'center' => $cr['center'] ?? CenterStore::defaultName(),
            ];
        }

        // Filter
        $filtered = array_filter($transactions, function ($tx) use ($year, $month, $center) {
            if ($year !== 'all' && $tx['year'] !== (string) $year) {
                return false;
            }
            if ($month !== 'all' && $tx['month'] !== (string) $month) {
                return false;
            }
            if ($center !== 'all' && $tx['center'] !== $center) {
                return false;
            }

            return true;
        });

        $fileName = 'bao_cao_doanh_thu_'.date('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($filtered) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Người phụ trách',
                'Ngày thanh toán',
                'Học viên',
                'Khóa học',
                'Học cụ',
                'Phương thức',
                'Tài khoản',
                'Học phí (VNĐ)',
                'Phí học cụ (VNĐ)',
                'Tổng thanh toán (VNĐ)',
                'Cơ sở',
            ]);

            foreach ($filtered as $tx) {
                fputcsv($output, [
                    $tx['recorded_by'],
                    $tx['date'],
                    $tx['student_name'],
                    $tx['course_name'],
                    $tx['inventory_name'],
                    $tx['payment_method'],
                    $tx['account_name'],
                    $tx['tuition_fee'],
                    $tx['inventory_fee'],
                    $tx['total_amount'],
                    $tx['center'],
                ]);
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
