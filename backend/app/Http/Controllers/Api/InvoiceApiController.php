<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\BrandSettings;
use App\Support\CenterStore;
use App\Support\StudentSchedule;
use App\Support\Tuition;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class InvoiceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'all');
        $month = $request->input('month', Carbon::now()->format('Y-m'));

        $query = Invoice::with(['student.course', 'course'])
            ->where('paid_at', 'like', "{$month}%");

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $query->orderBy('paid_at', 'desc')->orderBy('id', 'desc');
        $pagination = null;
        if ($this->wantsPagination($request)) {
            $paginator = $query->paginate($this->perPage($request));
            $invoices = $paginator->items();
            $pagination = $this->paginationMeta($paginator);
        } else {
            $invoices = $query->get();
        }

        $monthlyTotal = (float) Invoice::where('status', 'paid')
            ->where('paid_at', 'like', "{$month}%")
            ->sum('amount');

        // Lập hóa đơn: mọi học viên (đang học trước), học viên tạm dừng / đã nghỉ vẫn có thể còn nợ và đóng tiền
        $students = Student::orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderBy('name')
            ->get(['id', 'code', 'name', 'parent_name', 'parent_phone', 'center', 'status']);
        $courses = Course::where('is_active', true)->get();

        // Phiếu chờ thu (công nợ) không thuộc tháng nào: luôn hiện để xác nhận khi phụ huynh đóng tiền
        $pendingInvoices = Invoice::with(['student.course', 'course'])->where('status', 'pending')->orderBy('created_at')->get();

        return response()->json([
            'invoices' => $invoices,
            'pending_invoices' => $pendingInvoices,
            'pagination' => $pagination,
            'monthlyTotal' => $monthlyTotal,
            'students' => $students,
            'courses' => $courses,
        ]);
    }

    /**
     * Các khoản còn phải thu của học viên (khóa học còn nợ, học cụ chưa thanh toán) cho form Tạo hóa đơn.
     * ?invoice_id=: đang sửa hóa đơn đó (số đã thu trên hóa đơn này được cộng lại vào số còn nợ).
     */
    public function payables(Request $request, Student $student): JsonResponse
    {
        $editing = $request->filled('invoice_id') ? Invoice::find($request->integer('invoice_id')) : null;
        $invoices = $student->invoices()->get()->reject(fn ($inv) => $editing && $inv->id === $editing->id);
        $summary = Tuition::summary($student, $invoices);

        $courses = array_map(function ($row) use ($student) {
            $item = collect($student->registered_courses_list)->firstWhere('id', $row['course_item_id']);

            return [...$row, ...$student->courseItemDates($item), 'sessions_per_week' => $item['sessions_per_week'] ?? null];
        }, $summary['courses']);

        return response()->json([
            'student' => ['id' => $student->id, 'name' => $student->name, 'code' => $student->code, 'center' => $student->center],
            'courses' => $courses,
            'supplies' => $summary['supplies'],
            'debt' => $summary['debt'],
            'bank_info' => trim((string) (CenterStore::findByName($student->center)['bank_info'] ?? '')),
        ]);
    }

    /**
     * Lập hóa đơn = ghi nhận phụ huynh đã thanh toán cho các khoản đang nợ (khóa học / học cụ). Không cộng buổi:
     * muốn học thêm thì đăng ký khóa mới. Thu thiếu được, phần còn lại vẫn là công nợ để thu lần sau.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayment($request);
        $student = Student::findOrFail($validated['student_id']);

        if ($error = $this->paymentError($student, $validated['lines'], $student->invoices()->get())) {
            return response()->json(['message' => $error], 422);
        }
        $lines = $this->buildLines($student, $validated['lines'], $student->invoices()->get());
        $firstCourse = collect($lines)->firstWhere('type', 'course');

        $invoice = Invoice::create([
            'invoice_code' => Invoice::nextInvoiceCodeForCenter($student->center),
            'student_id' => $student->id,
            'course_id' => $firstCourse['course_id'] ?? $student->course_id,
            'course_item_id' => $firstCourse['course_item_id'] ?? null,
            'center' => $student->center,
            'amount' => array_sum(array_column($lines, 'amount')),
            'lines' => $lines,
            'sessions_added' => 0,
            'payment_method' => $validated['payment_method'],
            'status' => 'paid',
            'paid_at' => $validated['paid_at'],
            'due_date' => $validated['due_date'] ?? null,
            'note' => $validated['note'] ?? null,
            'invoice_file_path' => $request->hasFile('invoice_pdf') ? $request->file('invoice_pdf')->store('invoices', 'public') : null,
            'recorded_by' => $request->user()?->name ?? 'Admin',
        ]);

        $invoice->load(['student.course', 'course']);

        return response()->json([
            'message' => "Đã lập hóa đơn {$invoice->invoice_code}!",
            'invoice' => $invoice,
        ], 201);
    }

    /**
     * Sửa hóa đơn. Hóa đơn mới (có dòng thanh toán): sửa số tiền từng dòng, kiểm tra không thu quá số còn nợ.
     * Phiếu cũ (nhập học / gia hạn cộng buổi): sửa như trước, đổi số buổi thì điều chỉnh số buổi của học viên.
     */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'cancelled') {
            return response()->json(['message' => 'Phiếu đã hủy, hãy khôi phục trước khi sửa.'], 422);
        }

        if (! empty($invoice->lines)) {
            $validated = $this->validatePayment($request, $invoice);
            $student = $invoice->student;
            $others = $student->invoices()->get()->reject(fn ($inv) => $inv->id === $invoice->id);
            if ($error = $this->paymentError($student, $validated['lines'], $others)) {
                return response()->json(['message' => $error], 422);
            }
            $lines = $this->buildLines($student, $validated['lines'], $others);
            $firstCourse = collect($lines)->firstWhere('type', 'course');

            $invoice->update([
                'course_id' => $firstCourse['course_id'] ?? $invoice->course_id,
                'course_item_id' => $firstCourse['course_item_id'] ?? null,
                'amount' => array_sum(array_column($lines, 'amount')),
                'lines' => $lines,
                'payment_method' => $validated['payment_method'],
                'paid_at' => $validated['paid_at'],
                'due_date' => $validated['due_date'] ?? null,
                'note' => $validated['note'] ?? null,
                'invoice_file_path' => $request->hasFile('invoice_pdf') ? $request->file('invoice_pdf')->store('invoices', 'public') : $invoice->invoice_file_path,
            ]);
            $invoice->load(['student.course', 'course']);

            return response()->json(['message' => "Đã cập nhật hóa đơn {$invoice->invoice_code}!", 'invoice' => $invoice]);
        }

        $validated = $request->validate([
            'course_id' => 'nullable|exists:courses,id',
            'amount' => 'required|numeric|min:0',
            'sessions_added' => 'required|integer|min:0',
            'payment_method' => 'required|string|in:bank_transfer,cash,pos,momo',
            'paid_at' => 'required|date',
            'note' => 'nullable|string',
            'invoice_pdf' => 'nullable|file|mimetypes:application/pdf|max:10240',
        ]);

        DB::transaction(function () use ($invoice, $request, $validated): void {
            $delta = $validated['sessions_added'] - $invoice->sessions_added;
            $student = $invoice->student;

            if ($delta !== 0 && $student) {
                StudentSchedule::addSessions($student, $validated['course_id'] ?? $invoice->course_id, $delta);
                $student->save();
            }

            $invoice->update([
                'course_id' => $validated['course_id'] ?? $invoice->course_id,
                'amount' => $validated['amount'],
                'sessions_added' => $validated['sessions_added'],
                'payment_method' => $validated['payment_method'],
                'paid_at' => $validated['paid_at'],
                'note' => $validated['note'] ?? null,
                'invoice_file_path' => $request->hasFile('invoice_pdf') ? $request->file('invoice_pdf')->store('invoices', 'public') : $invoice->invoice_file_path,
            ]);
        });

        $invoice->load(['student.course', 'course']);

        return response()->json([
            'message' => "Đã cập nhật phiếu thu {$invoice->invoice_code}!",
            'invoice' => $invoice,
        ]);
    }

    /**
     * Dữ liệu hóa đơn thanh toán. lines gửi dạng mảng hoặc chuỗi JSON (form có đính kèm file PDF).
     */
    private function validatePayment(Request $request, ?Invoice $invoice = null): array
    {
        if (is_string($request->input('lines'))) {
            $request->merge(['lines' => json_decode($request->input('lines'), true) ?: []]);
        }

        return $request->validate([
            'student_id' => $invoice ? 'nullable' : 'required|exists:students,id',
            'lines' => 'required|array|min:1',
            'lines.*.key' => 'required|string',
            'lines.*.amount' => 'required|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string|in:bank_transfer,cash,pos,momo',
            'paid_at' => 'required|date',
            'due_date' => 'nullable|date',
            'note' => 'nullable|string',
            'invoice_pdf' => 'nullable|file|mimetypes:application/pdf|max:10240',
        ], [
            'lines.required' => 'Chọn ít nhất một khoản cần thanh toán.',
        ]);
    }

    /**
     * Lỗi của các dòng thanh toán: khoản không còn nợ / không có, thu quá số còn nợ, khóa đang có phiếu chờ thu.
     */
    private function paymentError(Student $student, array $lines, $invoices): ?string
    {
        $payables = collect([...Tuition::courses($student, $invoices), ...Tuition::supplies($student, $invoices)])->keyBy('key');
        $total = 0;

        foreach ($lines as $line) {
            $row = $payables->get($line['key']);
            if (! $row) {
                return 'Khoản thanh toán không còn trong danh sách của học viên.';
            }
            if ($row['type'] === 'course' && $row['pending_invoice']) {
                return "Khóa {$row['name']} đang có phiếu chờ thu {$row['pending_invoice']['invoice_code']}: hãy xác nhận đã thu phiếu đó thay vì lập hóa đơn mới.";
            }
            $amount = (float) $line['amount'];
            $discount = $row['type'] === 'course' ? (float) ($line['discount'] ?? 0) : 0;
            if ($amount + $discount > $row['debt'] + 0.5) {
                return "{$row['name']}: số tiền thu (".number_format($amount, 0, ',', '.').'đ'
                    .($discount > 0 ? ' + giảm giá '.number_format($discount, 0, ',', '.').'đ' : '')
                    .') vượt quá số còn nợ '.number_format($row['debt'], 0, ',', '.').'đ.';
            }
            $total += $amount + $discount;
        }

        return $total > 0 ? null : 'Nhập số tiền thu cho ít nhất một khoản.';
    }

    /**
     * Dòng thanh toán lưu trên hóa đơn (kèm tên, học phí / giá và số còn nợ trước khi thu để in hóa đơn).
     */
    private function buildLines(Student $student, array $lines, $invoices): array
    {
        $payables = collect([...Tuition::courses($student, $invoices), ...Tuition::supplies($student, $invoices)])->keyBy('key');

        return array_values(array_map(function ($line) use ($payables) {
            $row = $payables->get($line['key']);
            $base = ['type' => $row['type'], 'name' => $row['name'], 'amount' => (float) $line['amount'], 'debt_before' => $row['debt']];

            return $row['type'] === 'course'
                ? [...$base, 'course_item_id' => $row['course_item_id'], 'course_id' => $row['course_id'],
                    'fee' => $row['fee'], 'discount' => (float) ($line['discount'] ?? 0), 'total_sessions' => $row['total_sessions']]
                : [...$base, 'supply_id' => $row['supply_id'], 'quantity' => $row['quantity'], 'price' => $row['price']];
        }, $lines));
    }

    /**
     * Xác nhận đã thu tiền một phiếu chờ thu. Không cộng thêm buổi: buổi đã cấp khi lập phiếu.
     */
    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'pending') {
            return response()->json(['message' => 'Phiếu này không ở trạng thái chờ thu.'], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:bank_transfer,cash,pos,momo',
            'paid_at' => 'required|date',
        ]);

        $invoice->update([...$validated, 'status' => 'paid']);
        $invoice->load(['student.course', 'course']);

        return response()->json([
            'message' => "Đã xác nhận thu tiền phiếu {$invoice->invoice_code}.",
            'invoice' => $invoice,
        ]);
    }

    /**
     * Ghi nhầm "đã thu" khi phụ huynh chưa đóng tiền: đưa phiếu về chờ thu, giữ nguyên số buổi đã cấp.
     */
    public function markUnpaid(Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'paid') {
            return response()->json(['message' => 'Chỉ chuyển được phiếu đã thanh toán về chờ thu.'], 422);
        }

        $invoice->update(['status' => 'pending', 'paid_at' => null]);

        return response()->json(['message' => "Đã chuyển phiếu {$invoice->invoice_code} về chờ thu.", 'invoice' => $invoice]);
    }

    /**
     * Hủy phiếu thu (thu / gia hạn nhầm): trừ lại số buổi đã cộng, không tính doanh thu / công nợ nữa.
     * Khác xóa: giữ lại phiếu để khôi phục. Buổi đã học thì không trừ được, chỉ trừ phần còn lại.
     */
    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'cancelled') {
            return response()->json(['message' => 'Phiếu này đã hủy.'], 422);
        }

        $validated = $request->validate(['reason' => 'nullable|string|max:255']);
        $student = $invoice->student;

        DB::transaction(function () use ($invoice, $student, $validated): void {
            $reverted = 0;
            if ($invoice->sessions_added > 0 && $student) {
                $before = (int) $student->total_sessions;
                StudentSchedule::addSessions($student, $invoice->course_id, -$invoice->sessions_added);
                $reverted = $before - (int) $student->total_sessions;
                // Hết phiếu "đã đóng" thì học viên không còn ở trạng thái Đã đóng của chăm sóc tái ký
                if ($student->retention_status === 'renewed'
                    && ! $student->invoices()->where('status', 'paid')->whereKeyNot($invoice->id)->exists()) {
                    $student->retention_status = 'normal';
                }
                $student->save();
            }

            $invoice->update([
                'status_before_cancel' => $invoice->status,
                'status' => 'cancelled',
                'sessions_reverted' => $reverted,
                'cancelled_at' => Carbon::now(),
                'cancelled_reason' => $validated['reason'] ?? null,
            ]);
        });

        $message = "Đã hủy phiếu {$invoice->invoice_code}";
        if ($invoice->sessions_added > 0) {
            $message .= $invoice->sessions_reverted < $invoice->sessions_added
                ? ", trừ lại {$invoice->sessions_reverted}/{$invoice->sessions_added} buổi (học viên đã học phần còn lại)."
                : ", trừ lại {$invoice->sessions_reverted} buổi.";
        }

        return response()->json(['message' => $message, 'invoice' => $invoice]);
    }

    /**
     * Khôi phục phiếu đã hủy nhầm: về trạng thái cũ và cộng lại đúng số buổi đã trừ lúc hủy.
     */
    public function restore(Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'cancelled') {
            return response()->json(['message' => 'Phiếu này chưa bị hủy.'], 422);
        }

        DB::transaction(function () use ($invoice): void {
            $student = $invoice->student;
            if ($invoice->sessions_reverted > 0 && $student) {
                StudentSchedule::addSessions($student, $invoice->course_id, (int) $invoice->sessions_reverted);
                $student->save();
            }

            $invoice->update([
                'status' => $invoice->status_before_cancel ?: 'paid',
                'status_before_cancel' => null,
                'sessions_reverted' => null,
                'cancelled_at' => null,
                'cancelled_reason' => null,
            ]);
        });

        return response()->json(['message' => "Đã khôi phục phiếu {$invoice->invoice_code}.", 'invoice' => $invoice]);
    }

    /**
     * Xóa phiếu thu và trừ lại số buổi đã cộng cho học viên.
     */
    public function destroy(Invoice $invoice): JsonResponse
    {
        DB::transaction(function () use ($invoice): void {
            $student = $invoice->student;

            // Phiếu đã hủy thì số buổi đã trừ lại lúc hủy
            if ($invoice->status !== 'cancelled' && $invoice->sessions_added > 0 && $student) {
                StudentSchedule::addSessions($student, $invoice->course_id, -$invoice->sessions_added);
                $student->save();
            }

            $invoice->delete();
        });

        return response()->json([
            'message' => "Đã xóa phiếu thu {$invoice->invoice_code}!",
        ]);
    }

    /**
     * Xuất phiếu thu học phí (đã thanh toán) ra file PDF.
     */
    public function pdf(Invoice $invoice): Response
    {
        $invoice->load(['student', 'course']);
        $center = CenterStore::findByName($invoice->center ?: $invoice->student?->center);

        $pdf = Pdf::loadView('pdf.payment-receipt', [
            'invoice' => $invoice,
            'center' => $center,
            'brand' => BrandSettings::brand(),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        return $pdf->download("{$invoice->invoice_code}.pdf");
    }
}
