// Phân quyền theo "trang bị chặn" cấu hình ở màn Nhân sự (backend: App\Support\PagePermissions).
// Tên trang => các đường dẫn giao diện tương ứng, theo thứ tự menu.
export const PAGE_PATHS = {
  'Bảng điều khiển': ['/'],
  'Học viên': ['/students'],
  'Điểm danh': ['/attendance'],
  'Check-in ca dạy': ['/teacher-checkin'],
  'Vắng liên tiếp': ['/absences'],
  'Khách hàng tiềm năng': ['/leads'],
  'Doanh thu': ['/revenue'],
  'Hóa đơn & Thu phí': ['/invoices'],
  'Học cụ': ['/inventory'],
  'Sinh nhật': ['/birthdays'],
  'HV Tạm dừng - Nghỉ': ['/inactive-students'],
  'Khóa học': ['/courses'],
  'Trung tâm': ['/centers'],
  'Nhân sự': ['/staff'],
  // Trang Cài đặt vẫn mở để nhân sự đổi thông tin / mật khẩu của mình, chỉ ẩn phần quản trị
  'Cài đặt': ['/settings/activity-logs', '/settings/backups'],
  'Dữ liệu': ['/data'],
  'Báo cáo': ['/reports'],
};

export const ALL_PAGES = Object.keys(PAGE_PATHS);

// '/' (Bảng điều khiển) chỉ khớp đúng trang chủ, không khớp mọi đường dẫn
const matches = (path, prefix) => (prefix === '/' ? path === '/' : path === prefix || path.startsWith(`${prefix}/`));

export const isPageBlocked = (user, page) => (user?.blocked_pages || []).includes(page);

export const isPathBlocked = (user, path) =>
  (user?.blocked_pages || []).some((page) => (PAGE_PATHS[page] || []).some((prefix) => matches(path, prefix)));

// Trang đầu tiên mà người dùng được vào (theo thứ tự menu; giáo viên ưu tiên Check-in ca dạy).
// Cài đặt luôn mở để đổi mật khẩu.
export const firstAllowedPath = (user) => {
  const order = ALL_PAGES.flatMap((page) => PAGE_PATHS[page]);
  if (user?.is_teacher) order.unshift('/teacher-checkin');
  return order.find((path) => !isPathBlocked(user, path)) || '/settings';
};
