import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
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

export const authApi = {
  login: (data) => api.post('/auth/login', data),
  register: (data) => api.post('/auth/register', data),
  logout: () => api.post('/auth/logout'),
  me: () => api.get('/auth/me'),
};

export const dashboardApi = {
  getStats: () => api.get('/dashboard'),
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
  addSupplies: (id, data) => api.post(`/students/${id}/supplies`, data),
  updateProgress: (id, data) => api.put(`/students/${id}/progress`, data),
};

export const retentionApi = {
  getChecklist: (params) => api.get('/retention', { params }),
  updateStatus: (id, data) => api.post(`/retention/${id}/status`, data),
  addLog: (id, data) => api.post(`/retention/${id}/logs`, data),
};

export const leadApi = {
  getAll: (params) => api.get('/leads', { params }),
  create: (data) => api.post('/leads', data),
  updateStatus: (id, data) => api.post(`/leads/${id}/status`, data),
  convertToStudent: (id, data) => api.post(`/leads/${id}/convert`, data),
  delete: (id) => api.delete(`/leads/${id}`),
  getExportUrl: () => '/api/leads/export',
};

export const courseApi = {
  getAll: () => api.get('/courses'),
  create: (data) => api.post('/courses', data),
  update: (id, data) => api.put(`/courses/${id}`, data),
  delete: (id) => api.delete(`/courses/${id}`),
};

export const invoiceApi = {
  getAll: (params) => api.get('/invoices', { params }),
  create: (data) => api.post('/invoices', data),
};

export const reportApi = {
  getReports: (params) => api.get('/reports', { params }),
};

export const exportApi = {
  getExportUrl: (type, center = 'all') => {
    const base = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api';
    return `${base}/export/${type}?center=${encodeURIComponent(center)}`;
  },
};

export const settingsApi = {
  getSettings: () => api.get('/settings'),
  updateProfile: (data) => api.put('/settings/profile', data),
  updatePassword: (data) => api.put('/settings/password', data),
  updateBrand: (data) => api.put('/settings/brand', data),
  updateVat: (data) => api.put('/settings/vat', data),
};

export const staffApi = {
  getStaff: () => api.get('/staff'),
  createInvite: (data) => api.post('/staff/invites', data),
  updateRole: (id, data) => api.put(`/staff/members/${id}/role`, data),
  updatePermissions: (id, data) => api.put(`/staff/members/${id}/permissions`, data),
  deleteMember: (id) => api.delete(`/staff/members/${id}`),
};

export const centerApi = {
  getCenters: () => api.get('/centers'),
  createCenter: (data) => api.post('/centers', data),
  updateCenter: (id, data) => api.put(`/centers/${id}`, data),
  deleteCenter: (id) => api.delete(`/centers/${id}`),
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
};

export const inventoryApi = {
  getAll: (params) => api.get('/inventory', { params }),
  create: (data) => api.post('/inventory', data),
  update: (id, data) => api.put(`/inventory/${id}`, data),
  delete: (id) => api.delete(`/inventory/${id}`),
  adjust: (id, data) => api.post(`/inventory/${id}/adjust`, data),
  importCsv: (formData) => api.post('/inventory/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }),
  getExportUrl: (center = 'all') => `/api/inventory/export?center=${encodeURIComponent(center)}`,
  getTemplateUrl: () => '/api/inventory/template',
};

export const revenueApi = {
  getRevenue: (params) => api.get('/revenue', { params }),
  createRevenue: (data) => api.post('/revenue', data),
  createExpense: (data) => api.post('/revenue/expenses', data),
  getExportUrl: (params = {}) => {
    const q = new URLSearchParams(params).toString();
    return `/api/revenue/export?${q}`;
  },
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
  updateRates: (data) => api.post('/teacher-checkin/rates', data),
  getExportUrl: (month = '2026-10') => `/api/teacher-checkin/export?month=${encodeURIComponent(month)}`,
};

export const dailyAttendanceApi = {
  getAttendance: (params) => api.get('/attendance', { params }),
  mark: (studentId, data) => api.post(`/attendance/${studentId}/mark`, data),
  scheduleSingleLesson: (data) => api.post('/attendance/single-lesson', data),
};

export default api;




