@php
    $student = $invoice->student;
    $methods = ['bank_transfer' => 'Chuyển khoản', 'cash' => 'Tiền mặt', 'pos' => 'Quẹt POS', 'momo' => 'MoMo / QR'];
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invoice_code }}</title>
    <style>
        @page { margin: 36px 40px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .light { color: #94a3b8; }
        .brand { font-size: 18px; font-weight: bold; color: #312e81; text-transform: uppercase; margin: 0; }
        .badge { display: inline-block; padding: 4px 10px; background: #ecfdf5; color: #047857; font-weight: bold; font-size: 10px; text-transform: uppercase; border-radius: 6px; }
        .header { border-bottom: 2px solid #4f46e5; padding-bottom: 12px; margin-bottom: 18px; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 18px; }
        .label { font-size: 9px; text-transform: uppercase; color: #94a3b8; font-weight: bold; }
        .name { font-size: 14px; font-weight: bold; color: #0f172a; margin: 3px 0; }
        .items th { background: #eef2ff; text-align: left; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        .items td { padding: 10px; border-bottom: 1px solid #f1f5f9; }
        .items tfoot td { background: #f8fafc; font-weight: bold; border-bottom: none; }
        .right, .items th.right { text-align: right; }
        .center, .items th.center { text-align: center; }
        .total { font-size: 15px; color: #047857; }
        .code { color: #4338ca; font-weight: bold; }
        .paid { margin-top: 18px; border: 1px solid #a7f3d0; background: #ecfdf5; border-radius: 8px; padding: 12px; }
        .paid td { padding: 3px 0; }
        .footer { margin-top: 22px; border-top: 1px solid #f1f5f9; padding-top: 12px; }
    </style>
</head>
<body>
@php
    // Logo tải lên là đường dẫn /storage/... mà DomPDF không mở được: nhúng thẳng ảnh vào PDF
    $logoUrl = \App\Support\PdfImage::src(! empty($center['logo_url']) ? $center['logo_url'] : ($brand['logo_url'] ?? ''));
    $centerName = ! empty($center['name']) ? $center['name'] : ($brand['brand_name'] ?? 'Trung tâm');
    $centerAddress = ! empty($center['address']) ? $center['address'] : ($brand['address'] ?? '');
    $centerPhone = ! empty($center['phone']) ? $center['phone'] : ($brand['phone'] ?? '');
    // Khoản tiền này là cho khóa bao nhiêu buổi (không phải "cộng thêm"): phiếu có cấp buổi (nhập học / gia hạn)
    // thì là số buổi của gói; phiếu chỉ thu tiền (không cấp buổi) thì là tổng số buổi của khóa đang học
    $courseItem = collect($student?->registered_courses_list ?? [])->first(fn ($i) => (int) ($i['course_id'] ?? 0) === (int) $invoice->course_id);
    // Hóa đơn thanh toán (có dòng khóa học / học cụ) và số còn phải thu của các khoản đó sau phiếu này
    $lines = $invoice->lines ?: [];
    $remainingAfter = max(0, array_sum(array_map(fn ($l) => ($l['debt_before'] ?? 0) - ($l['amount'] ?? 0) - ($l['discount'] ?? 0), $lines)));
    $courseSessions = (int) $invoice->sessions_added
        ?: (int) ($courseItem['total_sessions'] ?? $invoice->course?->default_sessions ?? 0);
@endphp
    <table class="header">
        <tr>
            <td>
                @if (! empty($logoUrl))
                    <img src="{{ $logoUrl }}" alt="" style="height: 40px; margin-bottom: 6px;">
                @endif
                <p class="brand">{{ $centerName }}</p>
                @if (! empty($brand['category']))
                    <div class="muted">Trung tâm đào tạo {{ mb_strtolower($brand['category']) }}</div>
                @endif
                <div class="muted">
                    @if (! empty($centerAddress))Địa chỉ: {{ $centerAddress }}@endif
                    @if (! empty($centerAddress) && ! empty($centerPhone)) • @endif
                    @if (! empty($centerPhone))Hotline: {{ $centerPhone }}@endif
                </div>
            </td>
            <td class="right" style="vertical-align: top;">
                <span class="badge">Phiếu thu học phí</span>
                <div class="muted" style="margin-top: 4px;">Mã: {{ $invoice->invoice_code }}</div>
                <div class="light">Ngày thu: {{ $invoice->paid_at?->format('d/m/Y') ?? '—' }}</div>
            </td>
        </tr>
    </table>

    <div class="box">
        <table>
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <div class="label">Thông tin học viên</div>
                    <div class="name">{{ $student?->name ?? '—' }}</div>
                    <div>Mã học viên: <span class="code">{{ $student?->code ?? ($student ? 'HV-' . $student->id : '—') }}</span></div>
                    <div>Số buổi còn lại: <strong style="color: #e11d48;">{{ $student?->remaining_sessions ?? 0 }} buổi</strong></div>
                    @php($dates = $student?->courseDates($invoice->course_id) ?? ['start' => null, 'end' => null])
                    <div>Ngày bắt đầu học: <strong>{{ $dates['start'] ?? '—' }}</strong></div>
                    <div>Ngày kết thúc: <strong>{{ $dates['end'] ?? '—' }}</strong>@if ($dates['end_estimated'] ?? false) <span style="color: #94a3b8;">(dự kiến)</span>@endif</div>
                </td>
                <td style="width: 50%; vertical-align: top;">
                    <div class="label">Người nộp tiền / Phụ huynh</div>
                    <div class="name">{{ $student?->parent_name ?: 'Phụ huynh' }}</div>
                    <div>Số điện thoại: <strong>{{ $student?->parent_phone ?: '—' }}</strong></div>
                </td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Nội dung</th>
                <th class="center">{{ $lines ? 'Học phí / Thành tiền' : 'Số buổi khóa học' }}</th>
                <th class="right">Số tiền thu</th>
            </tr>
        </thead>
        <tbody>
            @if ($lines)
                {{-- Hóa đơn thanh toán: từng khoản (khóa học / học cụ), số tiền thu lần này --}}
                @foreach ($lines as $line)
                    <tr>
                        <td>
                            <strong>{{ $line['name'] }}</strong>
                            <div class="light" style="font-size: 9px;">
                                @if ($line['type'] === 'course')
                                    Khóa {{ $line['total_sessions'] ?? '' }} buổi
                                    @if (! empty($line['discount']))
                                        · Giảm giá {{ number_format($line['discount'], 0, ',', '.') }} ₫
                                    @endif
                                @else
                                    Học cụ · {{ $line['quantity'] ?? 1 }} x {{ number_format($line['price'] ?? 0, 0, ',', '.') }} ₫
                                @endif
                            </div>
                        </td>
                        <td class="center">{{ number_format($line['type'] === 'course' ? ($line['fee'] ?? 0) : ($line['price'] ?? 0) * ($line['quantity'] ?? 1), 0, ',', '.') }} ₫</td>
                        <td class="right"><strong style="color: #312e81;">{{ number_format($line['amount'], 0, ',', '.') }} ₫</strong></td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td>
                        <strong>{{ $invoice->course?->name ?? $student?->course?->name ?? 'Học phí' }}</strong>
                        @if ($invoice->note)
                            <div class="light" style="font-size: 9px;">{{ $invoice->note }}</div>
                        @endif
                    </td>
                    <td class="center"><strong>{{ $courseSessions ? $courseSessions.' buổi' : '—' }}</strong></td>
                    <td class="right"><strong style="color: #312e81;">{{ number_format($invoice->amount, 0, ',', '.') }} ₫</strong></td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="right muted">Tổng đã thu:</td>
                <td class="right total">{{ number_format($invoice->amount, 0, ',', '.') }} ₫</td>
            </tr>
            @if ($lines)
                <tr>
                    <td colspan="2" class="right muted">Còn phải thanh toán (các khoản trên):</td>
                    <td class="right" style="font-weight: bold; color: {{ $remainingAfter > 0 ? '#e11d48' : '#047857' }};">{{ number_format($remainingAfter, 0, ',', '.') }} ₫</td>
                </tr>
            @endif
        </tfoot>
    </table>

    <div class="paid">
        <table>
            <tr>
                <td style="width: 50%;">Hình thức: <strong>{{ $methods[$invoice->payment_method] ?? $invoice->payment_method }}</strong></td>
                <td>Thời gian thanh toán: <strong>{{ $invoice->paid_at?->format('H:i d/m/Y') ?? '—' }}</strong></td>
            </tr>
            <tr>
                <td colspan="2">Trạng thái: <strong style="color: #047857;">ĐÃ THANH TOÁN</strong></td>
            </tr>
        </table>
    </div>

    <table class="footer">
        <tr>
            <td style="width: 50%; vertical-align: top;" class="center">
                <strong>Người nộp tiền</strong>
                <div class="light" style="margin-top: 40px; font-size: 9px;">(Ký và ghi rõ họ tên)</div>
            </td>
            <td style="width: 50%; vertical-align: top;" class="center">
                <strong>Bộ phận Kế toán / Thu ngân</strong>
                <div class="light" style="margin-top: 40px; font-size: 9px;">(Ký và ghi rõ họ tên)</div>
            </td>
        </tr>
    </table>
</body>
</html>
