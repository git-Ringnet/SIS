<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoiceCode }}</title>
    <style>
        @page { margin: 36px 40px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .light { color: #94a3b8; }
        .brand { font-size: 18px; font-weight: bold; color: #312e81; text-transform: uppercase; margin: 0; }
        .badge { display: inline-block; padding: 4px 10px; background: #eef2ff; color: #4338ca; font-weight: bold; font-size: 10px; text-transform: uppercase; border-radius: 6px; }
        .header { border-bottom: 2px solid #4f46e5; padding-bottom: 12px; margin-bottom: 18px; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 18px; }
        .label { font-size: 9px; text-transform: uppercase; color: #94a3b8; font-weight: bold; }
        .name { font-size: 14px; font-weight: bold; color: #0f172a; margin: 3px 0; }
        .items th { background: #eef2ff; text-align: left; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        .items td { padding: 10px; border-bottom: 1px solid #f1f5f9; }
        .items tfoot td { background: #f8fafc; font-weight: bold; border-bottom: none; }
        .right, .items th.right { text-align: right; }
        .center, .items th.center { text-align: center; }
        .total { font-size: 15px; color: #e11d48; }
        .bank { border: 1px solid #e0e7ff; border-radius: 8px; padding: 12px; margin-top: 18px; }
        .bank h4 { margin: 0 0 8px; font-size: 10px; color: #312e81; text-transform: uppercase; }
        .bank td { padding: 3px 0; }
        .code { color: #4338ca; font-weight: bold; }
        .footer { margin-top: 22px; border-top: 1px solid #f1f5f9; padding-top: 12px; }
    </style>
</head>
<body>
@php
    $logoUrl = ! empty($center['logo_url']) ? $center['logo_url'] : ($brand['logo_url'] ?? '');
    $centerName = ! empty($center['name']) ? $center['name'] : ($brand['brand_name'] ?? 'Trung tâm');
    $centerAddress = ! empty($center['address']) ? $center['address'] : ($brand['address'] ?? '');
    $centerPhone = ! empty($center['phone']) ? $center['phone'] : ($brand['phone'] ?? '');
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
                <span class="badge">Phiếu báo học phí</span>
                <div class="muted" style="margin-top: 4px;">Mã: {{ $invoiceCode }}</div>
                <div class="light">Ngày lập: {{ $issuedAt }}</div>
            </td>
        </tr>
    </table>

    <div class="box">
        <table>
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <div class="label">Thông tin học viên</div>
                    <div class="name">{{ $student->name }}</div>
                    <div>Mã học viên: <span class="code">{{ $student->code ?? 'HV-' . $student->id }}</span></div>
                    <div>Số buổi còn lại: <strong style="color: #e11d48;">{{ $student->remaining_sessions ?? 0 }} buổi</strong></div>
                </td>
                <td style="width: 50%; vertical-align: top;">
                    <div class="label">Người đại diện / Phụ huynh</div>
                    <div class="name">{{ $student->parent_name ?: 'Phụ huynh' }}</div>
                    <div>Số điện thoại: <strong>{{ $student->parent_phone ?: '—' }}</strong></div>
                    <div>Zalo: {{ $student->parent_zalo ?: ($student->parent_phone ?: '—') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Nội dung</th>
                <th class="center">Số buổi</th>
                <th class="right">Đơn giá</th>
                <th class="right">Thành tiền</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>{{ $student->course?->name ?? 'Khóa học Âm nhạc' }}</strong>
                    <div class="light" style="font-size: 9px;">Học phí tái đăng ký khóa tiếp theo</div>
                </td>
                <td class="center"><strong>{{ $student->course?->default_sessions ?? 12 }} buổi</strong></td>
                <td class="right muted">{{ number_format($amount, 0, ',', '.') }} ₫</td>
                <td class="right"><strong style="color: #312e81;">{{ number_format($amount, 0, ',', '.') }} ₫</strong></td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="right muted">Tổng thanh toán:</td>
                <td class="right total">{{ number_format($amount, 0, ',', '.') }} ₫</td>
            </tr>
        </tfoot>
    </table>

    @if (! empty($center['bank_info']) || ! empty($center['qr_url']))
    <div class="bank">
        <h4>Thông tin chuyển khoản học phí</h4>
        <table>
            <tr>
                <td style="vertical-align: top;">
                    @if (! empty($center['bank_info']))
                        <div style="font-size: 11px; line-height: 1.6; white-space: pre-line; color: #1e293b;">{!! nl2br(e($center['bank_info'])) !!}</div>
                    @endif
                    <div style="margin-top: 6px; font-size: 10px;">
                        Cú pháp: <span class="code">HP {{ $student->name }} {{ $student->parent_phone }}</span>
                    </div>
                </td>
                @if (! empty($center['qr_url']))
                    <td class="right" style="width: 100px; vertical-align: top; text-align: right;">
                        <img src="{{ $center['qr_url'] }}" alt="QR" style="max-height: 90px; max-width: 90px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 2px; background: #fff;" />
                        <div class="light" style="font-size: 8px; text-align: center; margin-top: 2px;">Quét QR</div>
                    </td>
                @endif
            </tr>
        </table>
    </div>
    @endif

    <table class="footer">
        <tr>
            <td class="muted" style="font-style: italic; vertical-align: top;">
                * Xin vui lòng hoàn tất học phí để trung tâm bảo lưu lịch học và xếp giờ học viên.
            </td>
            <td class="right" style="width: 40%; vertical-align: top;">
                <strong>Bộ phận Kế toán / Thu ngân</strong>
                <div class="light" style="margin-top: 40px; font-size: 9px;">(Ký và ghi rõ họ tên)</div>
            </td>
        </tr>
    </table>
</body>
</html>
