<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\BrandSettings;
use App\Support\CenterStore;
use App\Support\StudentSchedule;
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

        $students = Student::where('status', 'active')->get();
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'course_id' => 'nullable|exists:courses,id',
            'amount' => 'required|numeric|min:0',
            'sessions_added' => 'required|integer|min:0',
            'payment_method' => 'required|string|in:bank_transfer,cash,pos,momo',
            'paid_at' => 'required|date',
            'note' => 'nullable|string',
            'invoice_pdf' => 'nullable|file|mimetypes:application/pdf|max:10240',
        ]);

        $student = Student::find($validated['student_id']);
        $invoiceCode = Invoice::nextInvoiceCodeForCenter($student?->center);

        $invoice = Invoice::create([
            'invoice_code' => $invoiceCode,
            'student_id' => $student->id,
            'course_id' => $validated['course_id'] ?? $student->course_id,
            'center' => $student?->center,
            'amount' => $validated['amount'],
            'sessions_added' => $validated['sessions_added'],
            'payment_method' => $validated['payment_method'],
            'status' => 'paid',
            'paid_at' => $validated['paid_at'],
            'note' => $validated['note'] ?? 'Thu học phí trực tiếp',
            'invoice_file_path' => $request->hasFile('invoice_pdf') ? $request->file('invoice_pdf')->store('invoices', 'public') : null,
            'recorded_by' => 'Admin',
        ]);

        if ($validated['sessions_added'] > 0) {
            // Cộng vào khóa của phiếu thu (không chỉ tổng của học viên, vì tổng được tính lại từ các khóa)
            StudentSchedule::addSessions($student, $invoice->course_id, (int) $validated['sessions_added']);
            $student->retention_status = 'renewed';
            $student->save();
        }

        $invoice->load(['student.course', 'course']);

        return response()->json([
            'message' => "Tạo phiếu thu {$invoiceCode} thành công!",
            'invoice' => $invoice,
        ], 201);
    }

    /**
     * Sửa phiếu thu. Nếu đổi số buổi cộng thêm thì điều chỉnh số buổi của học viên theo phần chênh lệch.
     */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'nullable|exists:courses,id',
            'amount' => 'required|numeric|min:0',
            'sessions_added' => 'required|integer|min:0',
            'payment_method' => 'required|string|in:bank_transfer,cash,pos,momo',
            'paid_at' => 'required|date',
            'note' => 'nullable|string',
            'invoice_pdf' => 'nullable|file|mimetypes:application/pdf|max:10240',
        ]);

        if ($invoice->status === 'cancelled') {
            return response()->json(['message' => 'Phiếu đã hủy, hãy khôi phục trước khi sửa.'], 422);
        }

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
