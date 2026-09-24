<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->input('status', 'all');
        $month = $request->input('month', Carbon::now()->format('Y-m'));

        $query = Invoice::with(['student.course', 'course'])
            ->where('paid_at', 'like', "{$month}%");

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $invoices = $query->orderBy('paid_at', 'desc')->get();

        $monthlyTotal = (float) Invoice::where('status', 'paid')
            ->where('paid_at', 'like', "{$month}%")
            ->sum('amount');

        $students = Student::where('status', 'active')->get();
        $courses = Course::where('is_active', true)->get();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'monthlyTotal' => $monthlyTotal,
            'students' => $students,
            'courses' => $courses,
            'filters' => [
                'status' => $status,
                'month' => $month,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'course_id' => 'nullable|exists:courses,id',
            'amount' => 'required|numeric|min:0',
            'sessions_added' => 'required|integer|min:0',
            'payment_method' => 'required|string|in:bank_transfer,cash,pos,momo',
            'paid_at' => 'required|date',
            'note' => 'nullable|string',
        ]);

        $student = Student::find($validated['student_id']);
        $invoiceCode = 'HD-'.date('Ym').'-'.str_pad($student->id, 3, '0', STR_PAD_LEFT).'-'.rand(100, 999);

        Invoice::create([
            'invoice_code' => $invoiceCode,
            'student_id' => $student->id,
            'course_id' => $validated['course_id'] ?? $student->course_id,
            'amount' => $validated['amount'],
            'sessions_added' => $validated['sessions_added'],
            'payment_method' => $validated['payment_method'],
            'status' => 'paid',
            'paid_at' => $validated['paid_at'],
            'note' => $validated['note'] ?? 'Thu học phí trực tiếp',
            'recorded_by' => auth()->user()->name ?? 'Admin',
        ]);

        // If sessions_added > 0, increment student remaining sessions
        if ($validated['sessions_added'] > 0) {
            $student->increment('remaining_sessions', $validated['sessions_added']);
            $student->increment('total_sessions', $validated['sessions_added']);
            $student->retention_status = 'renewed';
            $student->save();
        }

        return redirect()->back()->with('success', "Tạo phiếu thu {$invoiceCode} thành công!");
    }
}
