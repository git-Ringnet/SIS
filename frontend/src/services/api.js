import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 10000,
});

// Attach Authorization Bearer token if available
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Token hết hạn / bị thu hồi: xoá phiên đăng nhập và đưa về trang đăng nhập
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const isAuthRequest = error.config?.url?.startsWith('/auth/');
    if (error.response?.status === 401 && !isAuthRequest && localStorage.getItem('auth_token')) {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('auth_user');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  },
);

/**
 * Tải file (CSV, Excel...) qua axios để request có kèm token đăng nhập,
 * thay cho việc mở thẳng URL (không gửi được header Authorization).
 */
export const downloadFile = async (url, params = {}, fallbackName = 'download') => {
  const res = await api.get(url, { params, responseType: 'blob', timeout: 60000 });
  const disposition = res.headers['content-disposition'] || '';
  const match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
  const fileName = match ? decodeURIComponent(match[1]) : fallbackName;

  const href = URL.createObjectURL(res.data);
  const link = document.createElement('a');
  link.href = href;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(href), 1000);
};

export const brandApi = {
  get: () => api.get('/brand'),
};

export const authApi = {
  login: (data) => api.post('/auth/login', data),
  register: (data) => api.post('/auth/register', data),
  // Token truyền vào trực tiếp vì lúc gọi, phiên đăng nhập đã được xoá khỏi localStorage
  logout: (token) => api.post('/auth/logout', {}, { headers: { Authorization: `Bearer ${token}` } }),
  me: () => api.get('/auth/me'),
};

export const dashboardApi = {
  getStats: (params) => api.get('/dashboard', { params }),
};

export const studentApi = {
  getAll: (params) => api.get('/students', { params }),
  get: (id) => api.get(`/students/${id}`),
  create: (data) => api.post('/students', data),
  update: (id, data) => api.put(`/students/${id}`, data),
  delete: (id) => api.delete(`/students/${id}`),
  recordAttendance: (id, data) => api.post(`/students/${id}/attendance`, data),
  undoAttendance: (id) => api.post(`/students/${id}/attendance/undo`),
  recordAbsence: (id, data) => api.post(`/students/${id}/absence`, data),
  tuitionInvoicePdf: (id) => api.get(`/students/${id}/tuition-invoice.pdf`, { responseType: 'blob', timeout: 30000 }),
  addSupplies: (id, data) => api.post(`/students/${id}/supplies`, data),
  updateProgress: (id, data) => api.put(`/students/${id}/progress`, data),
  downloadTemplate: () => api.get('/students/template', { responseType: 'blob' }),
  importCsv: (formData) => api.post('/students/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }),
  uploadAvatar: (id, formData) => api.post(`/students/${id}/avatar`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }),
  addCourse: (id, data) => api.post(`/students/${id}/courses`, data),
  updateCourseItem: (id, courseIndex, data) => api.put(`/students/${id}/courses/${courseIndex}`, data),
  deleteCourseItem: (id, courseIndex) => api.delete(`/students/${id}/courses/${courseIndex}`),
};

export const retentionApi = {
  getChecklist: (params) => api.get('/retention', { params }),
  updateStatus: (id, data) => api.post(`/retention/${id}/status`, data),
  addLog: (id, data) => api.post(`/retention/${id}/logs`, data),
};

export const messageTemplateApi = {
  getAll: (params) => api.get('/message-templates', { params }),
  create: (data) => api.post('/message-templates', data),
  update: (id, data) => api.put(`/message-templates/${id}`, data),
  reset: (id) => api.post(`/message-templates/${id}/reset`),
};

export const leadApi = {
  getAll: (params) => api.get('/leads', { params }),
  create: (data) => api.post('/leads', data),
  update: (id, data) => api.put(`/leads/${id}`, data),
  updateStatus: (id, data) => api.post(`/leads/${id}/status`, data),
  convertToStudent: (id, data) => api.post(`/leads/${id}/convert`, data),
  delete: (id) => api.delete(`/leads/${id}`),
  exportCsv: (params = {}) => downloadFile('/leads/export', params, 'khach-hang-tiem-nang.csv'),
};

export const courseApi = {
  getAll: () => api.get('/courses'),
  create: (data) => api.post('/courses', data),
  update: (id, data) => api.put(`/courses/${id}`, data),
  delete: (id) => api.delete(`/courses/${id}`),
  // Lớp học trong khóa
  classRooms: () => api.get('/courses/class-rooms'),
  createClass: (courseId, data) => api.post(`/courses/${courseId}/classes`, data),
  updateClass: (id, data) => api.put(`/courses/classes/${id}`, data),
  deleteClass: (id) => api.delete(`/courses/classes/${id}`),
};

export const invoiceApi = {
  getAll: (params) => api.get('/invoices', { params }),
  create: (data) => api.post('/invoices', data),
  update: (id, data) => api.put(`/invoices/${id}`, data),
  delete: (id) => api.delete(`/invoices/${id}`),
  downloadPdf: (inv) => downloadFile(`/invoices/${inv.id}/pdf`, {}, `${inv.invoice_code}.pdf`),
};

export const reportApi = {
  getReports: (params) => api.get('/reports', { params }),
};

export const exportApi = {
  exportCsv: (type, center = 'all') => downloadFile(`/export/${type}`, { center }, `${type}.csv`),
};

export const settingsApi = {
  getSettings: () => api.get('/settings'),
  updateProfile: (data) => api.put('/settings/profile', data),
  updatePassword: (data) => api.put('/settings/password', data),
  updateBrand: (data) => api.put('/settings/brand', data),
  updateVat: (data) => api.put('/settings/vat', data),
  getActivityLogs: (params) => api.get('/settings/activity-logs', { params }),
};

// Sao lưu & khôi phục dữ liệu (chỉ chủ trung tâm). Các thao tác có thể chạy lâu nên tăng timeout
export const backupApi = {
  getAll: () => api.get('/settings/backups'),
  create: () => api.post('/settings/backups', {}, { timeout: 0 }),
  upload: (file) => api.post(`/settings/backups/${file}/upload`, {}, { timeout: 0 }),
  restore: (file, password) => api.post(`/settings/backups/${file}/restore`, { password }, { timeout: 0 }),
  delete: (file) => api.delete(`/settings/backups/${file}`),
  download: (file) => downloadFile(`/settings/backups/${file}/download`, {}, file),
  import: (formData) => api.post('/settings/backups/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 0,
  }),
};

export const staffApi = {
  getStaff: () => api.get('/staff'),
  createInvite: (data) => api.post('/staff/invites', data),
  updateRole: (id, data) => api.put(`/staff/members/${id}/role`, data),
  updateCenter: (id, data) => api.put(`/staff/members/${id}/center`, data),
  resetPassword: (id, data) => api.put(`/staff/members/${id}/password`, data),
  updatePermissions: (id, data) => api.put(`/staff/members/${id}/permissions`, data),
  deleteMember: (id) => api.delete(`/staff/members/${id}`),
};

export const centerApi = {
  getCenters: () => api.get('/centers'),
  createCenter: (data) => api.post('/centers', data),
  updateCenter: (id, data) => api.put(`/centers/${id}`, data),
  deleteCenter: (id) => api.delete(`/centers/${id}`),
  uploadImage: (file) => {
    const formData = new FormData();
    formData.append('file', file);
    return api.post('/centers/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  },
  addRoom: (id, data) => api.post(`/centers/${id}/rooms`, data),
  deleteRoom: (centerId, roomId) => api.delete(`/centers/${centerId}/rooms/${roomId}`),
};

export const inactiveStudentApi = {
  getAll: (params) => api.get('/inactive-students', { params }),
  reactivate: (id) => api.post(`/inactive-students/${id}/reactivate`),
  pause: (id, data) => api.post(`/inactive-students/${id}/pause`, data),
  drop: (id, data) => api.post(`/inactive-students/${id}/drop`, data),
};

export const birthdayApi = {
  getBirthdays: (params) => api.get('/birthdays', { params }),
  toggleGift: (studentId, data = {}) => api.post(`/birthdays/${studentId}/toggle-gift`, data),
};

export const inventoryApi = {
  getAll: (params) => api.get('/inventory', { params }),
  create: (data) => api.post('/inventory', data),
  update: (id, data) => api.put(`/inventory/${id}`, data),
  delete: (id) => api.delete(`/inventory/${id}`),
  adjust: (id, data) => api.post(`/inventory/${id}/adjust`, data),
  importExcel: (formData) => api.post('/inventory/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }),
  exportExcel: (center = 'all') => downloadFile('/inventory/export', { center }, 'kho-hoc-cu.xlsx'),
  downloadTemplate: () => downloadFile('/inventory/template', {}, 'mau-nhap-hoc-cu.xlsx'),
};

export const revenueApi = {
  getRevenue: (params) => api.get('/revenue', { params }),
  createRevenue: (data) => api.post('/revenue', data),
  updateRevenue: (id, data) => api.put(`/revenue/${id}`, data),
  deleteRevenue: (id) => api.delete(`/revenue/${id}`),
  createExpense: (data) => api.post('/revenue/expenses', data),
  updateExpense: (id, data) => api.put(`/revenue/expenses/${id}`, data),
  deleteExpense: (id) => api.delete(`/revenue/expenses/${id}`),
  exportCsv: (params = {}) => downloadFile('/revenue/export', params, 'doanh-thu.csv'),
};

export const absenceApi = {
  getAbsences: (params) => api.get('/absences', { params }),
  recordCare: (id, data) => api.post(`/absences/${id}/care`, data),
  resetAbsences: (id) => api.post(`/absences/${id}/reset`),
  scheduleMakeup: (id, data) => api.post(`/absences/${id}/makeup`, data),
};

export const teacherCheckinApi = {
  getCheckinData: (params) => api.get('/teacher-checkin', { params }),
  checkin: (data) => api.post('/teacher-checkin/checkin', data),
  recordAbsence: (data) => api.post('/teacher-checkin/absence', data),
  resetShift: (id) => api.post(`/teacher-checkin/${id}/reset`),
  updateShift: (id, data) => api.put(`/teacher-checkin/${id}`, data),
  deleteShift: (id) => api.delete(`/teacher-checkin/${id}`),
  getRates: () => api.get('/teacher-checkin/rates'),
  getQrToken: (center) => api.get('/teacher-checkin/qr-token', { params: { center } }),
  updateRates: (data) => api.post('/teacher-checkin/rates', data),
  exportMonthly: (month) => downloadFile('/teacher-checkin/export', { month }, `ca-day-${month}.csv`),
  // Nghỉ phép / dạy thay theo khoảng ngày
  getLeaves: (params) => api.get('/teacher-checkin/leaves', { params }),
  createLeave: (data) => api.post('/teacher-checkin/leaves', data),
  createSubstitution: (data) => api.post('/teacher-checkin/substitutions', data),
  deletePeriod: (type, id) => api.delete(`/teacher-checkin/${type}/${id}`),
};

export const dailyAttendanceApi = {
  getAttendance: (params) => api.get('/attendance', { params }),
  getAvailableGroupSlots: (params) => api.get('/attendance/available-group-slots', { params }),
  mark: (studentId, data) => api.post(`/attendance/${studentId}/mark`, data),
  scheduleSingleLesson: (data) => api.post('/attendance/single-lesson', data),
};

export const parentPortalApi = {
  getInfo: (studentId) => api.get(`/portal/students/${studentId}/info`),
  verify: (studentId, phoneLast4) => api.post(`/portal/students/${studentId}/verify`, { phone_last4: phoneLast4 }),
  requestLeave: (studentId, data) => api.post(`/portal/students/${studentId}/leave-request`, data),
};

export default api;




