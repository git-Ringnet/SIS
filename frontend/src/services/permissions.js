// Phân quyền theo "trang bị chặn" cấu hình ở màn Nhân sự (backend: App\Support\PagePermissions).
// Tên trang => các đường dẫn giao diện tương ứng.
const PAGE_PATHS = {
  'Doanh thu': ['/revenue'],
  'Hóa đơn & Thu phí': ['/invoices'],
  'Khách hàng tiềm năng': ['/leads'],
  // Trang Cài đặt vẫn mở để nhân sự đổi thông tin / mật khẩu của mình, chỉ ẩn phần quản trị
  'Cài đặt': ['/settings/activity-logs', '/settings/backups'],
  'Dữ liệu': ['/data'],
  'Báo cáo': ['/reports'],
  'Học cụ': ['/inventory'],
  'Khóa học': ['/courses'],
  'Nhân sự': ['/staff'],
  'Trung tâm': ['/centers'],
};

const matches = (path, prefix) => path === prefix || path.startsWith(`${prefix}/`);

export const isPageBlocked = (user, page) => (user?.blocked_pages || []).includes(page);

export const isPathBlocked = (user, path) =>
  (user?.blocked_pages || []).some((page) => (PAGE_PATHS[page] || []).some((prefix) => matches(path, prefix)));
