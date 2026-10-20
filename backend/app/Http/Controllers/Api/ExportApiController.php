<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportApiController extends Controller
{
    /**
     * Xuất dữ liệu dưới dạng file CSV chuẩn UTF-8 BOM (tương thích Excel tiếng Việt)
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        $center = (string) $request->input('center', 'all');
        $fileName = match ($type) {
            'students' => 'Danh_sach_hoc_vien_'.date('Ymd_His').'.csv',
            'courses' => 'Danh_sach_khoa_hoc_'.date('Ymd_His').'.csv',
            'attendance' => 'Lich_su_diem_danh_'.date('Ymd_His').'.csv',
            'payments' => 'Lich_su_thanh_toan_'.date('Ymd_His').'.csv',
            'supplies' => 'Danh_sach_hoc_cu_'.date('Ymd_His').'.csv',
            default => 'Du_lieu_xuat_'.date('Ymd_His').'.csv',
        };

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($type, $center) {
            $handle = fopen('php://output', 'w');

            // Ghi UTF-8 BOM để Excel không bị lỗi font tiếng Việt
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            match ($type) {
                'students' => $this->exportStudents($handle, $center),
                'courses' => $this->exportCourses($handle, $center),
                'attendance' => $this->exportAttendance($handle, $center),
                'payments' => $this->exportPayments($handle, $center),
                'supplies' => $this->exportSupplies($handle, $center),
                default => fputcsv($handle, ['Không tìm thấy loại dữ liệu']),
            };

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Có lọc theo một trung tâm cụ thể hay không ("all" / rỗng = tất cả)
     */
    private function filtersCenter(string $center): bool
    {
        return $center !== '' && $center !== 'all';
    }

    private function formatMoney($value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    private function readJson(string $path): array
    {
        if (! Storage::exists($path)) {
            return [];
        }
        $data = json_decode(Storage::get($path), true);

        return is_array($data) ? $data : [];
    }

    private function exportStudents($handle, string $center): void
    {
        fputcsv($handle, [
            'Mã học viên',
            'Họ và tên',
            'Ngày sinh',
            'Giới tính',
            'Phụ huynh',
            'Số điện thoại',
            'Zalo',
            'Email phụ huynh',
            'Địa chỉ',
            'Trung tâm',
            'Khóa học',
            'Lớp',
            'Lịch học',
            'Giáo viên',
            'Tổng số buổi',
            'Số buổi còn lại',
            'Số buổi đã vắng',
            'Trạng thái',
            'Trạng thái chăm sóc',
            'Ngày nhập học',
            'Ghi chú',
        ]);

        $students = Student::with('course')
            ->when($this->filtersCenter($center), fn ($q) => $q->where('center', $center))
            ->orderBy('id', 'asc')
            ->get();

        foreach ($students as $s) {
            $statusLabel = match ($s->status) {
                'active' => 'Đang học',
                'paused' => 'Tạm dừng',
                'inactive', 'dropped' => 'Đã nghỉ',
                default => $s->status,
            };

            $retentionLabel = match ($s->retention_status) {
                'invoice_sent' => 'Đã gửi hóa đơn',
                'following_up' => 'Đang chăm sóc',
                'renewed' => 'Đã đóng phí',
                default => 'Bình thường',
            };

            fputcsv($handle, [
                $s->code,
                $s->name,
                $s->dob ? Carbon::parse($s->dob)->format('d/m/Y') : '',
                $s->gender,
                $s->parent_name,
                $s->parent_phone,
                $s->parent_zalo,
                $s->parent_email,
                $s->address,
                $s->center,
                $s->course ? $s->course->name : '',
                $s->assigned_class,
                $s->schedule_info,
                $s->teacher,
                $s->total_sessions,
                $s->remaining_sessions,
                $s->absences_used ?? 0,
                $statusLabel,
                $retentionLabel,
                $s->join_date ? Carbon::parse($s->join_date)->format('d/m/Y') : '',
                $s->notes,
            ]);
        }
    }

    private function exportCourses($handle, string $center): void
    {
        fputcsv($handle, [
            'Mã khóa',
            'Tên khóa học',
            'Số buổi mặc định',
            'Học phí (VND)',
            'Trạng thái',
            'Số học viên đang học',
            'Tổng số buổi đã học',
            'Mô tả',
        ]);

        // Số học viên / số buổi đã học tính trong phạm vi trung tâm đang lọc
        $studentIds = $this->filtersCenter($center) ? Student::where('center', $center)->pluck('id') : null;
        $activeCounts = Student::where('status', 'active')
            ->when($studentIds !== null, fn ($q) => $q->whereIn('id', $studentIds))
            ->selectRaw('course_id, count(*) as total')->groupBy('course_id')->pluck('total', 'course_id');
        $attendedCounts = Attendance::where('status', 'present')
            ->when($studentIds !== null, fn ($q) => $q->whereIn('student_id', $studentIds))
            ->selectRaw('course_id, count(*) as total')->groupBy('course_id')->pluck('total', 'course_id');

        foreach (Course::orderBy('id', 'asc')->get() as $c) {
            fputcsv($handle, [
                $c->code,
                $c->name,
                $c->default_sessions,
                $this->formatMoney($c->price),
                $c->is_active ? 'Đang mở' : 'Đã dừng',
                (int) ($activeCounts[$c->id] ?? 0),
                (int) ($attendedCounts[$c->id] ?? 0),
                $c->description,
            ]);
        }
    }

    private function exportAttendance($handle, string $center): void
    {
        fputcsv($handle, [
            'Ngày',
            'Giờ',
            'Mã học viên',
            'Tên học viên',
            'Trung tâm',
            'Khóa học',
            'Trạng thái',
            'Buổi số',
            'Người điểm danh',
            'Ghi chú',
        ]);

        $attendances = Attendance::with(['student.course', 'course'])
            ->when($this->filtersCenter($center), fn ($q) => $q->whereHas('student', fn ($s) => $s->where('center', $center)))
            ->orderBy('attended_at', 'desc')
            ->get();

        foreach ($attendances as $a) {
            $statusLabel = match ($a->status) {
                'present' => 'Có mặt',
                'absent_with_leave' => 'Vắng có phép',
                'absent_without_leave' => 'Vắng không phép',
                default => $a->status,
            };

            fputcsv($handle, [
                $a->attended_at ? $a->attended_at->format('d/m/Y') : '',
                $a->attended_at ? $a->attended_at->format('H:i') : '',
                $a->student?->code,
                $a->student?->name,
                $a->student?->center,
                $a->course?->name ?? $a->student?->course?->name,
                $statusLabel,
                $a->session_number,
                $a->marked_by,
                $a->note,
            ]);
        }
    }

    /**
     * Lịch sử thanh toán: học phí từ hoá đơn + doanh thu nhập tay (học cụ, khoản thu khác)
     */
    private function exportPayments($handle, string $center): void
    {
        fputcsv($handle, [
            'Ngày thanh toán',
            'Mã hóa đơn',
            'Mã học viên',
            'Tên học viên',
            'Trung tâm',
            'Khóa học',
            'Học cụ',
            'Học phí (VND)',
            'Phí học cụ (VND)',
            'Tổng (VND)',
            'Số buổi cộng',
            'Phương thức thanh toán',
            'Người thu',
            'Ghi chú',
        ]);

        $rows = [];
        foreach (Invoice::with(['student', 'course'])->where('status', 'paid')->get() as $i) {
            $invCenter = $i->center ?: $i->student?->center;
            if ($this->filtersCenter($center) && $invCenter !== $center) {
                continue;
            }
            $paidAt = $i->paid_at ?: $i->created_at;
            $rows[] = [$paidAt?->timestamp ?? 0, [
                $paidAt ? $paidAt->format('d/m/Y') : '',
                $i->invoice_code,
                $i->student?->code,
                $i->student?->name,
                $invCenter,
                $i->course?->name,
                '',
                $this->formatMoney($i->amount),
                0,
                $this->formatMoney($i->amount),
                $i->sessions_added,
                match ($i->payment_method) {
                    'bank_transfer' => 'Chuyển khoản',
                    'cash' => 'Tiền mặt',
                    'pos' => 'Thẻ POS',
                    'momo' => 'Ví MoMo',
                    default => $i->payment_method,
                },
                $i->recorded_by,
                $i->note,
            ]];
        }

        foreach ($this->readJson('custom_revenues.json') as $r) {
            if ($this->filtersCenter($center) && ($r['center'] ?? '') !== $center) {
                continue;
            }
            $date = ! empty($r['date']) ? Carbon::parse($r['date']) : null;
            $course = $r['course_name'] ?? '';
            $item = $r['inventory_name'] ?? '';
            $rows[] = [$date?->timestamp ?? 0, [
                $date ? $date->format('d/m/Y') : '',
                '',
                '',
                $r['student_name'] ?? '',
                $r['center'] ?? '',
                $course === '-' ? '' : $course,
                $item === '-' ? '' : $item,
                $this->formatMoney($r['tuition_fee'] ?? 0),
                $this->formatMoney($r['inventory_fee'] ?? 0),
                $this->formatMoney($r['total_amount'] ?? 0),
                '',
                $r['payment_method'] ?? '',
                $r['recorded_by'] ?? '',
                $r['note'] ?? '',
            ]];
        }

        usort($rows, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($rows as [, $line]) {
            fputcsv($handle, $line);
        }
    }

    private function exportSupplies($handle, string $center): void
    {
        fputcsv($handle, [
            'Mã học cụ',
            'Tên học cụ',
            'Phân loại',
            'Đơn vị tính',
            'Trung tâm',
            'Giá nhập (VND)',
            'Giá bán (VND)',
            'Tồn kho',
            'Giá trị tồn (VND)',
            'Ghi chú',
        ]);

        foreach ($this->readJson('inventory_data.json') as $it) {
            if ($this->filtersCenter($center) && ($it['center'] ?? '') !== $center) {
                continue;
            }
            fputcsv($handle, [
                $it['code'] ?? '',
                $it['name'] ?? '',
                $it['category'] ?? '',
                $it['unit'] ?? '',
                $it['center'] ?? '',
                $this->formatMoney($it['cost_price'] ?? 0),
                $this->formatMoney($it['sell_price'] ?? 0),
                (int) ($it['quantity'] ?? 0),
                $this->formatMoney(($it['quantity'] ?? 0) * ($it['cost_price'] ?? 0)),
                $it['note'] ?? '',
            ]);
        }
    }
}
