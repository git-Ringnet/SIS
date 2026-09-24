import { createRouter, createWebHistory } from 'vue-router';
import DashboardView from '../views/DashboardView.vue';
import RetentionView from '../views/RetentionView.vue';
import StudentsView from '../views/StudentsView.vue';
import StudentDetailView from '../views/StudentDetailView.vue';
import LeadsView from '../views/LeadsView.vue';
import InvoicesView from '../views/InvoicesView.vue';
import CoursesView from '../views/CoursesView.vue';
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
  { path: '/attendance', name: 'attendance', component: GenericModuleView, meta: { title: 'Điểm danh' } },
  { path: '/teacher-checkin', name: 'teacher-checkin', component: GenericModuleView, meta: { title: 'Check-in ca dạy' } },
  { path: '/absences', name: 'absences', component: GenericModuleView, meta: { title: 'Vắng liên tiếp' } },

  // 2. Vận hành
  { path: '/leads', name: 'leads', component: LeadsView, meta: { title: 'Khách hàng tiềm năng' } },
  { path: '/revenue', name: 'revenue', component: GenericModuleView, meta: { title: 'Doanh thu' } },
  { path: '/invoices', name: 'invoices', component: InvoicesView, meta: { title: 'Hóa đơn & Thu phí' } },
  { path: '/inventory', name: 'inventory', component: GenericModuleView, meta: { title: 'Học cụ' } },
  { path: '/birthdays', name: 'birthdays', component: GenericModuleView, meta: { title: 'Sinh nhật' } },
  { path: '/inactive-students', name: 'inactive-students', component: GenericModuleView, meta: { title: 'Học viên đã nghỉ' } },

  // 3. Cấu hình
  { path: '/courses', name: 'courses', component: CoursesView, meta: { title: 'Khóa học' } },
  { path: '/centers', name: 'centers', component: GenericModuleView, meta: { title: 'Trung tâm' } },
  { path: '/staff', name: 'staff', component: GenericModuleView, meta: { title: 'Nhân sự' } },
  { path: '/settings', name: 'settings', component: GenericModuleView, meta: { title: 'Cài đặt' } },
  { path: '/data', name: 'data', component: GenericModuleView, meta: { title: 'Dữ liệu' } },
  { path: '/reports', name: 'reports', component: GenericModuleView, meta: { title: 'Báo cáo' } },
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

  next();
});

export default router;
