<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportApiController extends Controller
{
    /**
     * Xuất dữ liệu dưới dạng file CSV chuẩn UTF-8 BOM (tương thích Excel tiếng Việt)
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        $center = $request->input('center', 'all');
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

        return response()->stream(function () use ($type) {
            $handle = fopen('php://output', 'w');

            // Ghi UTF-8 BOM để Excel không bị lỗi font tiếng Việt
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            match ($type) {
                'students' => $this->exportStudents($handle),
                'courses' => $this->exportCourses($handle),
                'attendance' => $this->exportAttendance($handle),
                'payments' => $this->exportPayments($handle),
                'supplies' => $this->exportSupplies($handle),
                default => fputcsv($handle, ['Không tìm thấy loại dữ liệu']),
            };

            fclose($handle);
        }, 200, $headers);
    }

    private function exportStudents($handle): void
    {
        fputcsv($handle, [
            'Mã học viên',
            'Họ và tên',
            'Ngày sinh',
            'Giới tính',
            'Phụ huynh',
            'Số điện thoại',
            'Zalo',
            'Khóa học',
            'Tổng số buổi',
            'Số buổi còn lại',
            'Số buổi đã vắng',
            'Trạng thái',
            'Trạng thái chăm sóc',
            'Ngày nhập học',
            'Ghi chú',
        ]);

        $students = Student::with('course')->orderBy('id', 'asc')->get();

        foreach ($students as $s) {
            $statusLabel = match ($s->status) {
                'active' => 'Đang học',
                'paused' => 'Tạm dừng',
                'inactive' => 'Đã nghỉ',
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
                $s->course ? $s->course->name : '',
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

    private function exportCourses($handle): void
    {
        fputcsv($handle, [
            'Mã khóa',
            'Tên khóa học',
            'Phân loại',
            'Số buổi mặc định',
            'Học phí (VND)',
            'Trạng thái',
            'Mô tả',
        ]);

        $courses = Course::orderBy('id', 'asc')->get();

        foreach ($courses as $c) {
            fputcsv($handle, [
                $c->code,
                $c->name,
                $c->category,
                $c->default_sessions,
                number_format($c->price, 0, ',', '.'),
                $c->is_active ? 'Đang mở' : 'Đã dừng',
                $c->description,
            ]);
        }
    }

    private function exportAttendance($handle): void
    {
        fputcsv($handle, [
            'Thời gian điểm danh',
            'Mã học viên',
            'Tên học viên',
            'Khóa học',
            'Buổi số',
            'Ghi chú / Nhận xét của giáo viên',
        ]);

        $attendances = Attendance::with(['student', 'course'])->orderBy('attended_at', 'desc')->get();

        foreach ($attendances as $a) {
            fputcsv($handle, [
                $a->attended_at ? Carbon::parse($a->attended_at)->format('d/m/Y H:i') : '',
                $a->student ? $a->student->code : '',
                $a->student ? $a->student->name : '',
                $a->course ? $a->course->name : ($a->student && $a->student->course ? $a->student->course->name : ''),
                $a->session_number,
                $a->notes,
            ]);
        }
    }

    private function exportPayments($handle): void
    {
        fputcsv($handle, [
            'Mã hóa đơn',
            'Mã học viên',
            'Tên học viên',
            'Khóa học',
            'Số tiền (VND)',
            'Số buổi cộng',
            'Phương thức thanh toán',
            'Trạng thái',
            'Ngày thanh toán',
            'Ghi chú',
        ]);

        $invoices = Invoice::with(['student', 'course'])->orderBy('created_at', 'desc')->get();

        foreach ($invoices as $i) {
            $methodLabel = match ($i->payment_method) {
                'bank_transfer' => 'Chuyển khoản',
                'cash' => 'Tiền mặt',
                'pos' => 'Thẻ POS',
                'momo' => 'Ví MoMo',
                default => $i->payment_method,
            };

            $statusLabel = match ($i->status) {
                'paid' => 'Đã thanh toán',
                'unpaid' => 'Chưa thanh toán',
                'cancelled' => 'Đã hủy',
                default => $i->status,
            };

            fputcsv($handle, [
                $i->invoice_code,
                $i->student ? $i->student->code : '',
                $i->student ? $i->student->name : '',
                $i->course ? $i->course->name : '',
                number_format($i->amount, 0, ',', '.'),
                $i->sessions_added,
                $methodLabel,
                $statusLabel,
                $i->paid_at ? Carbon::parse($i->paid_at)->format('d/m/Y H:i') : '',
                $i->note,
            ]);
        }
    }

    private function exportSupplies($handle): void
    {
        fputcsv($handle, [
            'Mã học cụ',
            'Tên học cụ',
            'Đơn vị tính',
            'Giá nhập (VND)',
            'Giá bán (VND)',
            'Tồn kho',
            'Ghi chú',
        ]);

        $sampleSupplies = [
            ['HC-01', 'Giáo trình Piano Alfred Tập 1', 'Quyển', '120.000', '180.000', 35, 'Giáo trình nhập khẩu'],
            ['HC-02', 'Tập chép nhạc 5 dòng kẻ cao cấp', 'Cuốn', '25.000', '45.000', 120, 'Dành cho bé ghi bài'],
            ['HC-03', 'Capo đàn Guitar Musedo', 'Cái', '60.000', '95.000', 28, 'Hợp kim nhôm'],
            ['HC-04', 'Bộ dây đàn Guitar D\'Addario EJ13', 'Bộ', '130.000', '190.000', 15, 'Dây Phosphor Bronze'],
            ['HC-05', 'Bộ cọ vẽ màu nước chuyên nghiệp (10 cây)', 'Bộ', '150.000', '220.000', 18, 'Lông mềm'],
            ['HC-06', 'Giấy vẽ màu nước A4 Baohong 300gsm', 'Xấp (20 tờ)', '85.000', '130.000', 40, '100% Cotton'],
            ['HC-07', 'Cặp dù đựng tài liệu & sách nhạc trung tâm', 'Cái', '70.000', '110.000', 65, 'In logo trung tâm'],
        ];

        foreach ($sampleSupplies as $sup) {
            fputcsv($handle, $sup);
        }
    }
}
