<script setup>
import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from './services/useAuth';
import {
  LayoutDashboard,
  Users,
  CheckSquare,
  Clock,
  UserX,
  UserPlus,
  DollarSign,
  FileText,
  Package,
  Cake,
  UserMinus,
  GraduationCap,
  Building2,
  Briefcase,
  Settings,
  Database,
  BarChart3,
  LogOut,
  Menu,
  X,
  Plus,
  Flame,
  Music,
} from 'lucide-vue-next';

const route = useRoute();
const router = useRouter();
const { user, isAuthenticated, logout } = useAuth();
const showMobileMenu = ref(false);

const isStandalonePage = computed(() => {
  return route.meta?.public === true || route.meta?.standalone === true || ['login', 'register', 'student-detail'].includes(route.name);
});

const mainNavigation = [
  { name: 'Bảng điều khiển', href: '/', icon: LayoutDashboard },
  { name: 'Học viên', href: '/students', icon: Users },
  { name: 'Điểm danh', href: '/attendance', icon: CheckSquare },
  { name: 'Check-in ca dạy', href: '/teacher-checkin', icon: Clock },
  { name: 'Vắng liên tiếp', href: '/absences', icon: UserX },
];

const operationNavigation = [
  { name: 'Khách hàng tiềm năng', href: '/leads', icon: UserPlus },
  { name: 'Doanh thu', href: '/revenue', icon: DollarSign },
  { name: 'Hóa đơn', href: '/invoices', icon: FileText },
  { name: 'Học cụ', href: '/inventory', icon: Package },
  { name: 'Sinh nhật', href: '/birthdays', icon: Cake },
  { name: 'Học viên đã nghỉ', href: '/inactive-students', icon: UserMinus },
];

const configNavigation = [
  { name: 'Khóa học', href: '/courses', icon: GraduationCap },
  { name: 'Trung tâm', href: '/centers', icon: Building2 },
  { name: 'Nhân sự', href: '/staff', icon: Briefcase },
  { name: 'Cài đặt', href: '/settings', icon: Settings },
  { name: 'Dữ liệu', href: '/data', icon: Database },
  { name: 'Báo cáo', href: '/reports', icon: BarChart3 },
];

const isCurrentUrl = (path) => {
  if (path === '/') return route.path === '/';
  return route.path.startsWith(path);
};

const currentPageTitle = computed(() => {
  return route.meta?.title || 'Bảng điều khiển';
});

const userInitials = computed(() => {
  if (!user.value?.name) return 'KA';
  const parts = user.value.name.trim().split(' ');
  if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});

const handleLogout = async () => {
  if (confirm('Bạn có chắc chắn muốn đăng xuất khỏi hệ thống?')) {
    await logout();
  }
};
</script>

<template>
  <!-- If on Login, Register, or Standalone Student Detail page, show clean standalone view without sidebar -->
  <div v-if="isStandalonePage" class="min-h-screen w-full bg-[#f8fafc]">
    <router-view />
  </div>

  <!-- Authenticated Full-Width Dashboard Layout -->
  <div v-else class="min-h-screen bg-[#f8fafc] flex antialiased w-full text-slate-800">
    <!-- Desktop Sidebar -->
    <aside class="hidden lg:flex lg:w-60 xl:w-64 lg:flex-col lg:fixed lg:inset-y-0 z-30 bg-white border-r border-slate-200/90 shadow-2xs">
      <!-- Brand Logo - Khoa Anh Music & Art -->
      <div class="flex h-16 shrink-0 items-center justify-between px-4 border-b border-slate-100 bg-white">
        <router-link to="/" class="flex items-center gap-2.5">
          <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-red-600 text-white shadow-xs">
            <Music class="h-5 w-5" />
          </div>
          <div>
            <span class="text-sm font-black tracking-tight text-slate-900 block leading-tight">
              Khoa Anh <span class="text-red-600">Music</span>
            </span>
            <span class="text-[10px] font-semibold text-slate-400 block tracking-wider uppercase">
              EduCRM &bull; SIS
            </span>
          </div>
        </router-link>
      </div>

      <!-- Navigation Links Groups -->
      <div class="flex flex-1 flex-col overflow-y-auto px-3 py-3 space-y-4">
        <!-- 1. Main Navigation -->
        <nav class="space-y-1">
          <router-link
            v-for="item in mainNavigation"
            :key="item.name"
            :to="item.href"
            class="group flex items-center gap-2.5 rounded-xl px-3 py-2 text-xs font-semibold transition-all"
            :class="[
              isCurrentUrl(item.href)
                ? 'bg-red-600 text-white shadow-xs font-bold'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
            ]"
          >
            <component
              :is="item.icon"
              class="h-4 w-4 shrink-0 transition-colors"
              :class="isCurrentUrl(item.href) ? 'text-white' : 'text-slate-500 group-hover:text-slate-900'"
            />
            <span class="truncate">{{ item.name }}</span>
          </router-link>
        </nav>

        <!-- 2. Vận hành -->
        <div>
          <p class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">
            Vận hành
          </p>
          <nav class="mt-1.5 space-y-1">
            <router-link
              v-for="item in operationNavigation"
              :key="item.name"
              :to="item.href"
              class="group flex items-center gap-2.5 rounded-xl px-3 py-2 text-xs font-semibold transition-all"
              :class="[
                isCurrentUrl(item.href)
                  ? 'bg-red-600 text-white shadow-xs font-bold'
                  : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
              ]"
            >
              <component
                :is="item.icon"
                class="h-4 w-4 shrink-0 transition-colors"
                :class="isCurrentUrl(item.href) ? 'text-white' : 'text-slate-500 group-hover:text-slate-900'"
              />
              <span class="truncate">{{ item.name }}</span>
            </router-link>
          </nav>
        </div>

        <!-- 3. Cấu hình -->
        <div>
          <p class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">
            Cấu hình
          </p>
          <nav class="mt-1.5 space-y-1">
            <router-link
              v-for="item in configNavigation"
              :key="item.name"
              :to="item.href"
              class="group flex items-center gap-2.5 rounded-xl px-3 py-2 text-xs font-semibold transition-all"
              :class="[
                isCurrentUrl(item.href)
                  ? 'bg-red-600 text-white shadow-xs font-bold'
                  : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
              ]"
            >
              <component
                :is="item.icon"
                class="h-4 w-4 shrink-0 transition-colors"
                :class="isCurrentUrl(item.href) ? 'text-white' : 'text-slate-500 group-hover:text-slate-900'"
              />
              <span class="truncate">{{ item.name }}</span>
            </router-link>
          </nav>
        </div>

        <!-- Retention Quick Banner -->
        <div class="rounded-xl border border-red-200/80 bg-red-50/60 p-3 text-slate-700">
          <div class="flex items-center gap-1.5 text-red-600">
            <Flame class="h-4 w-4 animate-pulse shrink-0" />
            <span class="text-[11px] font-bold uppercase tracking-wide">Quy trình Retention</span>
          </div>
          <p class="mt-1 text-[11px] text-slate-500 leading-relaxed">
            Học viên &le; 5 buổi & tự động hóa gia hạn 1-click.
          </p>
          <router-link
            to="/retention"
            class="mt-2 inline-flex items-center justify-center w-full rounded-lg bg-red-600 hover:bg-red-700 px-2 py-1.5 text-xs font-bold text-white transition-colors shadow-2xs"
          >
            Checklist sắp hết buổi &rarr;
          </router-link>
        </div>
      </div>

      <!-- Logout Footer -->
      <div class="border-t border-slate-100 p-3 bg-white">
        <button
          @click="handleLogout"
          class="flex items-center gap-2.5 w-full rounded-xl px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition-colors"
        >
          <LogOut class="h-4 w-4 text-red-600" />
          <span>Đăng xuất ({{ user?.name || 'Admin' }})</span>
        </button>
      </div>
    </aside>

    <!-- Mobile Navigation Drawer -->
    <div v-if="showMobileMenu" class="fixed inset-0 z-50 flex lg:hidden">
      <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs" @click="showMobileMenu = false"></div>
      <div class="relative flex w-64 max-w-xs flex-1 flex-col bg-white p-4 overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
          <div class="flex items-center gap-2">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-red-600 text-white">
              <Music class="h-4 w-4" />
            </div>
            <span class="text-sm font-black text-slate-900">Khoa Anh Music</span>
          </div>
          <button @click="showMobileMenu = false" class="text-slate-500 hover:text-slate-800 p-1">
            <X class="h-5 w-5" />
          </button>
        </div>

        <nav class="mt-3 space-y-1">
          <router-link
            v-for="item in [...mainNavigation, ...operationNavigation, ...configNavigation]"
            :key="item.name"
            :to="item.href"
            @click="showMobileMenu = false"
            class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-xs font-semibold"
            :class="isCurrentUrl(item.href) ? 'bg-red-600 text-white' : 'text-slate-700 hover:bg-slate-100'"
          >
            <component :is="item.icon" class="h-4 w-4" />
            <span>{{ item.name }}</span>
          </router-link>
        </nav>

        <div class="mt-4 pt-3 border-t border-slate-100">
          <button
            @click="handleLogout"
            class="flex items-center gap-2 w-full rounded-xl px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50"
          >
            <LogOut class="h-4 w-4" />
            <span>Đăng xuất</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Main Content Full Width Area -->
    <div class="flex flex-1 flex-col lg:pl-60 xl:pl-64 min-w-0 w-full">
      <!-- Top Header -->
      <header class="sticky top-0 z-20 flex h-14 sm:h-15 shrink-0 items-center justify-between border-b border-slate-200/80 bg-white/95 backdrop-blur-md px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3">
          <button
            @click="showMobileMenu = true"
            class="rounded-lg p-1.5 text-slate-600 hover:bg-slate-100 lg:hidden"
          >
            <Menu class="h-5 w-5" />
          </button>

          <!-- Breadcrumb / Page Title -->
          <div class="flex items-center gap-2">
            <LayoutDashboard class="h-4 w-4 text-slate-400 hidden sm:inline" />
            <h2 class="text-sm sm:text-base font-bold text-slate-900">
              {{ currentPageTitle }}
            </h2>
          </div>
        </div>

        <!-- Top Right Actions -->
        <div class="flex items-center gap-2.5">
          <router-link
            to="/students?create=true"
            class="hidden sm:inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 hover:text-red-600 shadow-2xs transition-colors"
          >
            <Plus class="h-3.5 w-3.5 text-red-600" />
            <span>Học viên mới</span>
          </router-link>

          <router-link
            to="/retention"
            class="inline-flex items-center gap-1.5 rounded-lg bg-red-50 border border-red-200 px-3 py-1.5 text-xs font-bold text-red-700 hover:bg-red-100 shadow-2xs transition-colors"
          >
            <Flame class="h-3.5 w-3.5 text-red-600" />
            <span>Xử lý Tái ký</span>
          </router-link>

          <!-- User Profile -->
          <div class="flex items-center gap-2 pl-2 border-l border-slate-200">
            <div class="flex h-7.5 w-7.5 items-center justify-center rounded-full bg-red-600 text-white font-black text-xs shadow-2xs">
              {{ userInitials }}
            </div>
            <div class="hidden md:block text-left">
              <span class="text-xs font-bold text-slate-800 block leading-tight">
                {{ user?.name || 'Khoa Anh Music' }}
              </span>
              <span class="text-[10px] text-slate-400 font-medium block truncate max-w-[150px]">
                {{ user?.email || 'admin@khoaanhmusic.com' }}
              </span>
            </div>
          </div>
        </div>
      </header>

      <!-- View Content: FULL WIDTH -->
      <main class="flex-1 w-full p-4 sm:p-6 lg:p-8 overflow-x-hidden">
        <router-view />
      </main>
    </div>
  </div>
</template>
