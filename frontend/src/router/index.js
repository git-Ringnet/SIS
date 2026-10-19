import { createRouter, createWebHistory } from 'vue-router';
import { isPathBlocked } from '../services/permissions';
import DashboardView from '../views/DashboardView.vue';
import RetentionView from '../views/RetentionView.vue';
import StudentsView from '../views/StudentsView.vue';
import StudentDetailView from '../views/StudentDetailView.vue';
import LeadsView from '../views/LeadsView.vue';
import InvoicesView from '../views/InvoicesView.vue';
import CoursesView from '../views/CoursesView.vue';
import CentersView from '../views/CentersView.vue';
import ReportsView from '../views/ReportsView.vue';
import DataExportView from '../views/DataExportView.vue';
import SettingsView from '../views/SettingsView.vue';
import ActivityLogsView from '../views/ActivityLogsView.vue';
import BackupsView from '../views/BackupsView.vue';
import StaffView from '../views/StaffView.vue';
import InactiveStudentsView from '../views/InactiveStudentsView.vue';
import BirthdaysView from '../views/BirthdaysView.vue';
import InventoryView from '../views/InventoryView.vue';
import RevenueView from '../views/RevenueView.vue';
import AbsencesView from '../views/AbsencesView.vue';
import TeacherCheckinView from '../views/TeacherCheckinView.vue';
import AttendanceView from '../views/AttendanceView.vue';
import GenericModuleView from '../views/GenericModuleView.vue';
import LoginView from '../views/LoginView.vue';
import RegisterView from '../views/RegisterView.vue';

const routes = [
  // Auth
  { path: '/login', name: 'login', component: LoginView, meta: { public: true, title: 'Đăng nhập' } },
  { path: '/register', name: 'register', component: RegisterView, meta: { public: true, title: 'Đăng ký tài khoản' } },

  // 1. Chính
  { path: '/', name: 'dashboard', component: DashboardView, meta: { title: 'Bảng điều khiển' } },
  { path: '/retention', name: 'retention', component: RetentionView, meta: { title: 'Tái đăng ký & Giữ chân' } },
  { path: '/students', name: 'students', component: StudentsView, meta: { title: 'Học viên & Điểm danh' } },
  { path: '/students/:id', name: 'student-detail', component: StudentDetailView, props: true, meta: { standalone: true, title: 'Hồ sơ học viên' } },
  { path: '/attendance', name: 'attendance', component: AttendanceView, meta: { title: 'Điểm danh' } },
  { path: '/teacher-checkin', name: 'teacher-checkin', component: TeacherCheckinView, meta: { title: 'Check-in ca dạy' } },
  { path: '/absences', name: 'absences', component: AbsencesView, meta: { title: 'Vắng liên tiếp' } },

  // 2. Vận hành
  { path: '/leads', name: 'leads', component: LeadsView, meta: { title: 'Khách hàng tiềm năng' } },
  { path: '/revenue', name: 'revenue', component: RevenueView, meta: { title: 'Doanh thu' } },
  { path: '/invoices', name: 'invoices', component: InvoicesView, meta: { title: 'Hóa đơn & Thu phí' } },
  { path: '/inventory', name: 'inventory', component: InventoryView, meta: { title: 'Học cụ' } },
  { path: '/birthdays', name: 'birthdays', component: BirthdaysView, meta: { title: 'Sinh nhật' } },
  { path: '/inactive-students', name: 'inactive-students', component: InactiveStudentsView, meta: { title: 'HV Tạm dừng - Nghỉ' } },

  // 3. Cấu hình
  { path: '/courses', name: 'courses', component: CoursesView, meta: { title: 'Khóa học' } },
  { path: '/centers', name: 'centers', component: CentersView, meta: { title: 'Trung tâm' } },
  { path: '/staff', name: 'staff', component: StaffView, meta: { title: 'Nhân sự' } },
  { path: '/settings', name: 'settings', component: SettingsView, meta: { title: 'Cài đặt' } },
  { path: '/settings/activity-logs', name: 'activity-logs', component: ActivityLogsView, meta: { title: 'Nhật ký hoạt động' } },
  { path: '/settings/backups', name: 'backups', component: BackupsView, meta: { title: 'Sao lưu dữ liệu' } },
  { path: '/data', name: 'data', component: DataExportView, meta: { title: 'Dữ liệu' } },
  { path: '/reports', name: 'reports', component: ReportsView, meta: { title: 'Báo cáo' } },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 };
  },
});

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('auth_token');
  const isPublic = to.meta.public;

  if (!token && !isPublic) {
    return next('/login');
  }

  if (token && isPublic) {
    return next('/');
  }

  // Nhân sự bị chặn trang này (cấu hình ở màn Nhân sự) thì quay về Bảng điều khiển
  let user = null;
  try {
    user = JSON.parse(localStorage.getItem('auth_user') || 'null');
  } catch {
    user = null;
  }
  if (token && to.path !== '/' && isPathBlocked(user, to.path)) {
    return next('/');
  }

  next();
});

export default router;
