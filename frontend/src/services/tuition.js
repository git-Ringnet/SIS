// Học viên còn nhiều hơn số buổi này thì chưa cần thu học phí gia hạn (khớp mốc "sắp hết buổi" ở backend)
export const RENEW_THRESHOLD = 5;

// Phiếu thu đã thanh toán gần nhất (danh sách học viên trả latest_paid_invoice, trang chi tiết trả invoices)
export const latestPaidInvoice = (student) =>
  student?.latest_paid_invoice || (student?.invoices || []).find((inv) => inv.status === 'paid') || null;

// Phiếu chờ thu (phụ huynh chưa đóng tiền)
export const pendingInvoice = (student) =>
  student?.pending_invoice || (student?.invoices || []).find((inv) => inv.status === 'pending') || null;

// Trạng thái nút Học phí: 'pending' (chưa đóng) | 'paid' (đã đóng, còn nhiều buổi) | 'due' (cần thu / gia hạn)
export const tuitionState = (student) => {
  if (pendingInvoice(student)) return 'pending';
  return tuitionPaid(student) ? 'paid' : 'due';
};

export const TUITION_BUTTON = {
  pending: { label: 'Chưa đóng HP', class: 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100', icon: 'text-amber-600' },
  paid: { label: 'Đã đóng HP', class: 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100', icon: 'text-emerald-600' },
  due: { label: 'Học phí', class: 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50', icon: 'text-slate-500' },
};

// Đã đóng học phí và còn nhiều buổi: nút Học phí hiện "Đã đóng" để tránh thu trùng
export const tuitionPaid = (student) =>
  !!latestPaidInvoice(student) && Number(student?.remaining_sessions ?? 0) > RENEW_THRESHOLD;
