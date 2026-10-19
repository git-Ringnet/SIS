<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\BrandSettings;
use App\Support\CenterStore;
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
            $invCenter = $inv->student && $inv->student->center ? $inv->student->center : CenterStore::defaultName();

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
        $totalExpense = array_sum(array_column($filteredExpenses, 'amount'));

        // 7. Lợi nhuận
        $grossProfit = $revenueMonthTotal - $totalExpense;
        $profitMargin = $revenueMonthTotal > 0 ? round(($grossProfit / $revenueMonthTotal) * 100, 1) : 0;

        // 8. Doanh thu phân bổ (Accrued vs Unearned)
        $students = Student::with('course')->where('status', 'active')->get();
        $accruedRevenue = 0;
        $deferredRevenue = 0;
        foreach ($students as $st) {
            $totalSess = $st->total_sessions ?: 12;
            $remSess = $st->remaining_sessions ?: 0;
            $usedSess = max(0, $totalSess - $remSess);
            $coursePrice = $st->course ? (float) $st->course->price : 3000000;
            $pricePerSession = $totalSess > 0 ? ($coursePrice / $totalSess) : 0;

            $accruedRevenue += ($usedSess * $pricePerSession);
            $deferredRevenue += ($remSess * $pricePerSession);
        }

        $centers = CenterStore::names();

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
            'profit' => [
                'total_revenue' => $revenueMonthTotal,
                'total_expense' => $totalExpense,
                'gross_profit' => $grossProfit,
                'profit_margin' => $profitMargin,
            ],
            'allocation' => [
                'accrued_revenue' => round($accruedRevenue),
                'deferred_revenue' => round($deferredRevenue),
            ],
            'centers' => $centers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recorded_by' => 'required|string|max:100',
            'date' => 'required|date',
            'student_name' => 'nullable|string|max:255',
            'course_name' => 'nullable|string|max:255',
            'inventory_name' => 'nullable|string|max:255',
            'payment_method' => 'required|string',
            'account_name' => 'required|string',
            'tuition_fee' => 'nullable|numeric|min:0',
            'inventory_fee' => 'nullable|numeric|min:0',
            'center' => 'required|string',
            'note' => 'nullable|string',
        ]);

        $tuition = (float) ($validated['tuition_fee'] ?? 0);
        $inventory = (float) ($validated['inventory_fee'] ?? 0);
        $total = $tuition + $inventory;

        $type = 'both';
        if ($tuition > 0 && $inventory === 0.0) {
            $type = 'course';
        } elseif ($inventory > 0 && $tuition === 0.0) {
            $type = 'inventory';
        }

        $customRevenues = $this->loadCustomRevenues();
        $newTx = [
            'id' => 'rev_custom_'.uniqid(),
            'recorded_by' => $validated['recorded_by'],
            'date' => $validated['date'],
            'student_name' => ($validated['student_name'] ?? null) ?: 'Khách hàng',
            'course_name' => ($validated['course_name'] ?? null) ?: '-',
            'inventory_name' => ($validated['inventory_name'] ?? null) ?: '-',
            'payment_method' => $validated['payment_method'],
            'account_name' => $validated['account_name'],
            'tuition_fee' => $tuition,
            'inventory_fee' => $inventory,
            'total_amount' => $total,
            'center' => $validated['center'],
            'type' => $type,
            'note' => $validated['note'] ?? '',
        ];

        $customRevenues[] = $newTx;
        Storage::put($this->getCustomRevenuesPath(), json_encode($customRevenues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->json([
            'message' => 'Thêm doanh thu mới thành công!',
            'transaction' => $newTx,
        ], 201);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'category' => 'required|string|max:100',
            'spender' => 'required|string|max:100',
            'content' => 'required|string|max:255',
            'account' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
            'center' => 'required|string|max:100',
        ]);

        $expenses = $this->loadExpenses();
        $newId = count($expenses) > 0 ? max(array_column($expenses, 'id')) + 1 : 1;

        $newExpense = [
            'id' => $newId,
            'date' => $validated['date'],
            'category' => $validated['category'],
            'spender' => $validated['spender'],
            'content' => $validated['content'],
            'account' => $validated['account'],
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
