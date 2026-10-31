<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Student;
use App\Support\CenterStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadApiController extends Controller
{
    private function filteredQuery(Request $request)
    {
        $status = $request->input('status', 'all');
        $courseId = $request->input('course_id');
        $search = $request->input('search');
        $year = $request->input('year', 'all');
        $month = $request->input('month', 'all');
        $center = $request->input('center', 'all');

        $query = Lead::with(['course', 'convertedStudent']);

        if ($status !== 'all' && ! empty($status)) {
            $query->where('status', $status);
        }

        if ($courseId && $courseId !== 'all') {
            $query->where('interested_course_id', $courseId);
        }

        if ($year !== 'all' && ! empty($year)) {
            $query->whereYear('created_at', (int) $year);
        }

        if ($month !== 'all' && ! empty($month)) {
            $query->whereMonth('created_at', (int) $month);
        }

        if ($center !== 'all' && ! empty($center)) {
            $query->where('center', $center);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('parent_name', 'like', "%{$search}%")
                    ->orWhere('student_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request);
        $pagination = null;
        if ($this->wantsPagination($request)) {
            $paginator = $query->paginate($this->perPage($request));
            $leads = $paginator->items();
            $pagination = $this->paginationMeta($paginator);
        } else {
            $leads = $query->get();
        }
        $courses = Course::where('is_active', true)->get();

        $stages = [
            'new' => Lead::where('status', 'new')->count(),
            'called' => Lead::where('status', 'called')->count(),
            'quote_sent' => Lead::where('status', 'quote_sent')->count(),
            'trial_done' => Lead::where('status', 'trial_done')->count(),
            'registered' => Lead::where('status', 'registered')->count(),
            'lost' => Lead::where('status', 'lost')->count(),
        ];

        $centers = CenterStore::names();

        return response()->json([
            'leads' => $leads,
            'pagination' => $pagination,
            'courses' => $courses,
            'stages' => $stages,
            'centers' => $centers,
        ]);
    }

    private function leadRules(): array
    {
        return [
            'parent_name' => 'required|string|max:255',
            'student_name' => 'nullable|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'interested_course_id' => 'nullable|exists:courses,id',
            'source' => 'required|string|max:50',
            'status' => 'required|string|in:new,called,quote_sent,trial_done,registered,lost',
            'trial_date' => 'nullable|date',
            'expected_revenue' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'assigned_to' => 'nullable|string|max:255',
            'center' => 'nullable|string|max:255',
        ];
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate($this->leadRules());
        $lead->update([
            ...$validated,
            'expected_revenue' => $validated['expected_revenue'] ?? $lead->expected_revenue ?? 0,
            'assigned_to' => $validated['assigned_to'] ?? $lead->assigned_to,
            'center' => $validated['center'] ?? $lead->center,
        ]);

        return response()->json([
            'message' => "Đã cập nhật khách hàng {$lead->parent_name}!",
            'lead' => $lead->load('course'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->leadRules());

        $lead = Lead::create([
            'parent_name' => $validated['parent_name'],
            'student_name' => $validated['student_name'] ?? null,
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'interested_course_id' => $validated['interested_course_id'] ?? null,
            'source' => $validated['source'],
            'status' => $validated['status'],
            'trial_date' => $validated['trial_date'] ?? null,
            'expected_revenue' => $validated['expected_revenue'] ?? 0,
            'notes' => $validated['notes'] ?? null,
            'assigned_to' => $validated['assigned_to'] ?? 'Tư vấn viên',
            'center' => $validated['center'] ?? CenterStore::defaultName(),
            'image_url' => $validated['image_url'] ?? null,
        ]);

        $lead->load('course');

        return response()->json([
            'message' => "Thêm khách hàng tiềm năng {$lead->parent_name} thành công!",
            'lead' => $lead,
        ], 201);
    }

    public function updateStatus(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:new,called,quote_sent,trial_done,registered,lost',
            'notes' => 'nullable|string',
            'image_url' => 'nullable|string',
        ]);

        $lead->status = $validated['status'];
        if (! empty($validated['notes'])) {
            $lead->notes = ($lead->notes ? $lead->notes."\n" : '').'['.date('d/m/Y H:i').'] '.$validated['notes'];
        }
        if (isset($validated['image_url'])) {
            $lead->image_url = $validated['image_url'];
        }
        $lead->save();
        $lead->load('course');

        return response()->json([
            'message' => 'Cập nhật thông tin khách hàng thành công!',
            'lead' => $lead,
        ]);
    }

    public function convertToStudent(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'student_name' => 'required|string|max:255',
            'gender' => 'required|string|in:Nam,Nữ,Khác',
            'dob' => 'nullable|date',
            'total_sessions' => 'required|integer|min:1',
            'price' => 'nullable|numeric|min:0',
        ]);

        $course = Course::find($validated['course_id']);
        $code = Student::nextCode();

        $student = Student::create([
            'code' => $code,
            'name' => $validated['student_name'],
            'dob' => $validated['dob'] ?? null,
            'gender' => $validated['gender'],
            'parent_name' => $lead->parent_name,
            'parent_phone' => $lead->phone,
            'parent_zalo' => $lead->phone,
            'parent_email' => $lead->email,
            'course_id' => $course->id,
            'total_sessions' => $validated['total_sessions'],
            'remaining_sessions' => $validated['total_sessions'],
            'status' => 'active',
            'retention_status' => 'normal',
            'join_date' => Carbon::today(),
            'notes' => "Chuyển đổi từ Lead CRM: {$lead->source} - ".($lead->notes ?? ''),
            'center' => $lead->center ?: CenterStore::defaultName(),
        ]);

        $amount = $validated['price'] ?? $course->price;
        Invoice::create([
            'invoice_code' => Invoice::nextInvoiceCodeForCenter($student->center),
            'student_id' => $student->id,
            'course_id' => $student->course_id,
            'center' => $student->center,
            'amount' => $amount,
            'sessions_added' => $student->total_sessions,
            'payment_method' => 'bank_transfer',
            'status' => 'paid',
            'paid_at' => Carbon::now(),
            'note' => 'Học phí chuyển đổi từ Khách hàng tiềm năng',
            'recorded_by' => 'Admin',
        ]);

        $lead->status = 'registered';
        $lead->converted_student_id = $student->id;
        $lead->save();

        return response()->json([
            'message' => "Đã chuyển đổi thành công thành Học viên {$student->name} (Mã: {$student->code})!",
            'student' => $student,
            'lead' => $lead,
        ]);
    }

    /**
     * Tải ảnh phiếu đăng ký / bài test / ảnh học viên của khách (thay ảnh cũ). Ảnh lưu ở storage public (leads/).
     */
    public function uploadImage(Request $request, Lead $lead): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $this->deleteStoredImage($lead);
        $lead->image_url = Storage::url($request->file('image')->store('leads', 'public'));
        $lead->save();

        return response()->json([
            'message' => 'Đã tải ảnh lên!',
            'image_url' => $lead->image_url,
        ]);
    }

    public function deleteImage(Lead $lead): JsonResponse
    {
        $this->deleteStoredImage($lead);
        $lead->image_url = null;
        $lead->save();

        return response()->json(['message' => 'Đã xóa ảnh!']);
    }

    /**
     * Xóa file ảnh đã tải lên (ảnh cũ dạng đường dẫn ngoài thì không có file để xóa).
     */
    private function deleteStoredImage(Lead $lead): void
    {
        $prefix = Storage::url('leads/');
        if ($lead->image_url && str_starts_with($lead->image_url, $prefix)) {
            Storage::disk('public')->delete('leads/'.substr($lead->image_url, strlen($prefix)));
        }
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $name = $lead->parent_name;
        $this->deleteStoredImage($lead);
        $lead->delete();

        return response()->json([
            'message' => "Đã xóa khách hàng tiềm năng {$name} thành công!",
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $leads = $this->filteredQuery($request)->get();
        $fileName = 'khach_hang_tiem_nang_'.date('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($leads) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Ngày tiếp nhận',
                'Tên phụ huynh',
                'Tên học viên',
                'Số điện thoại',
                'Khóa học quan tâm',
                'Nguồn',
                'Trạng thái',
                'Cơ sở',
                'Ghi chú / Lịch hẹn',
            ]);

            foreach ($leads as $l) {
                $statusText = match ($l->status) {
                    'new' => 'Mới tiếp nhận',
                    'called' => 'Đã gọi điện',
                    'quote_sent' => 'Gửi báo giá',
                    'trial_done' => 'Đã học thử',
                    'registered' => 'Đã đăng ký',
                    'lost' => 'Đã hủy',
                    default => $l->status,
                };

                fputcsv($output, [
                    $l->created_at ? $l->created_at->format('d/m/Y') : '',
                    $l->parent_name,
                    $l->student_name ?? '',
                    $l->phone,
                    $l->course ? $l->course->name : '',
                    $l->source,
                    $statusText,
                    $l->center ?? CenterStore::defaultName(),
                    $l->notes ?? '',
                ]);
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
