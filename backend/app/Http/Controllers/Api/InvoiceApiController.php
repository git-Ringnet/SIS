<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\BrandSettings;
use App\Support\CenterStore;
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

        return response()->json([
            'invoices' => $invoices,
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
            $student->increment('remaining_sessions', $validated['sessions_added']);
            $student->increment('total_sessions', $validated['sessions_added']);
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

        DB::transaction(function () use ($invoice, $request, $validated): void {
            $delta = $validated['sessions_added'] - $invoice->sessions_added;
            $student = $invoice->student;

            if ($delta !== 0 && $student) {
                $student->remaining_sessions = max(0, $student->remaining_sessions + $delta);
                $student->total_sessions = max(0, $student->total_sessions + $delta);
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
     * Xóa phiếu thu và trừ lại số buổi đã cộng cho học viên.
     */
    public function destroy(Invoice $invoice): JsonResponse
    {
        DB::transaction(function () use ($invoice): void {
            $student = $invoice->student;

            if ($invoice->sessions_added > 0 && $student) {
                $student->remaining_sessions = max(0, $student->remaining_sessions - $invoice->sessions_added);
                $student->total_sessions = max(0, $student->total_sessions - $invoice->sessions_added);
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
