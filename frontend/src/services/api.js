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

export default api;
