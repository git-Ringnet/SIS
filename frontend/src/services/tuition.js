// Học viên còn nhiều hơn số buổi này thì chưa cần thu học phí gia hạn (khớp mốc "sắp hết buổi" ở backend)
export const RENEW_THRESHOLD = 5;

// Phiếu thu đã thanh toán gần nhất (danh sách học viên trả latest_paid_invoice, trang chi tiết trả invoices)
export const latestPaidInvoice = (student) =>
  student?.latest_paid_invoice || (student?.invoices || []).find((inv) => inv.status === 'paid') || null;

// Phiếu chờ thu kiểu cũ (phụ huynh chưa đóng tiền)
export const pendingInvoice = (student) =>
  student?.pending_invoice || (student?.invoices || []).find((inv) => inv.status === 'pending') || null;

// Còn nợ học phí / học cụ (backend tính theo từng khóa, cùng cách với trang Hóa đơn và Cổng phụ huynh)
export const tuitionDebt = (student) => Number(student?.tuition?.debt || 0);

// Trạng thái nút Học phí: 'pending' (còn nợ) | 'paid' (đã đóng đủ, còn nhiều buổi) | 'due' (sắp hết buổi, cần gia hạn)
export const tuitionState = (student) => {
  if (student?.tuition) {
    if (tuitionDebt(student) > 0) return 'pending';
    return Number(student?.remaining_sessions ?? 0) > RENEW_THRESHOLD ? 'paid' : 'due';
  }
  // Dữ liệu chưa có công nợ (trang khác): theo phiếu thu như trước
  if (pendingInvoice(student)) return 'pending';
  return tuitionPaid(student) ? 'paid' : 'due';
};

const formatVND = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(Number(v) || 0));

export const TUITION_BUTTON = {
  pending: { label: 'Chưa đóng HP', class: 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100', icon: 'text-amber-600' },
  paid: { label: 'Đã đóng HP', class: 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100', icon: 'text-emerald-600' },
  due: { label: 'Học phí', class: 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50', icon: 'text-slate-500' },
};

// Nhãn nút Học phí: còn nợ thì hiện số tiền còn nợ
export const tuitionLabel = (student) => {
  const state = tuitionState(student);
  return state === 'pending' && tuitionDebt(student) > 0 ? `Còn nợ ${formatVND(tuitionDebt(student))}đ` : TUITION_BUTTON[state].label;
};

// Đã đóng học phí và còn nhiều buổi: nút Học phí hiện "Đã đóng" để tránh thu trùng
export const tuitionPaid = (student) => (student?.tuition
  ? tuitionDebt(student) <= 0 && Number(student?.remaining_sessions ?? 0) > RENEW_THRESHOLD
  : !!latestPaidInvoice(student) && Number(student?.remaining_sessions ?? 0) > RENEW_THRESHOLD);

// Được thu học phí / lập hóa đơn: không bị chặn trang "Hóa đơn & Thu phí" ở màn Nhân sự (giáo vụ mặc định bị chặn).
// Không có quyền thì ẩn ô "Đã thu học phí" và nút "Lập hóa đơn"; khóa mới hiện "còn nợ" để chủ trung tâm / quản lý lập hóa đơn.
export const canRecordPayment = (user) => !(user?.blocked_pages || []).includes('Hóa đơn & Thu phí');
