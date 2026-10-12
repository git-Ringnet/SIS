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
const isSidebarCollapsed = ref(localStorage.getItem('sis_sidebar_collapsed') === 'true');

const toggleSidebar = () => {
  if (window.innerWidth < 1024) {
    showMobileMenu.value = true;
  } else {
    isSidebarCollapsed.value = !isSidebarCollapsed.value;
    localStorage.setItem('sis_sidebar_collapsed', isSidebarCollapsed.value);
  }
};

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
  { name: 'HV Tạm dừng - Nghỉ', href: '/inactive-students', icon: UserMinus },
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
    <aside
      class="hidden lg:flex lg:flex-col lg:fixed lg:inset-y-0 z-30 bg-white border-r border-slate-200/90 shadow-2xs transition-all duration-300"
      :class="isSidebarCollapsed ? 'lg:w-16' : 'lg:w-60 xl:w-64'"
    >
      <!-- Brand Logo - Khoa Anh Music & Art -->
      <div
        class="flex h-16 shrink-0 items-center border-b border-slate-100 bg-white transition-all duration-300"
        :class="isSidebarCollapsed ? 'justify-center px-2' : 'justify-between px-4'"
      >
        <router-link to="/" class="flex items-center gap-2.5" :title="isSidebarCollapsed ? 'Khoa Anh Music' : ''">
          <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-rose-500 via-rose-600 to-red-600 text-white shadow-sm shadow-rose-600/20 shrink-0">
            <Music class="h-5 w-5" />
          </div>
          <div v-if="!isSidebarCollapsed" class="overflow-hidden whitespace-nowrap">
            <span class="text-sm font-black tracking-tight text-slate-900 block leading-tight">
              Khoa Anh <span class="text-rose-600">Music</span>
            </span>
            <span class="text-[10px] font-semibold text-rose-900/40 block tracking-wider uppercase">
              EduCRM &bull; SIS
            </span>
          </div>
        </router-link>
      </div>

      <!-- Navigation Links Groups -->
      <div
        class="flex flex-1 flex-col overflow-y-auto py-3 space-y-3 transition-all duration-300"
        :class="isSidebarCollapsed ? 'px-2' : 'px-3'"
      >
        <!-- 1. Main Navigation -->
        <nav class="space-y-1">
          <router-link
            v-for="item in mainNavigation"
            :key="item.name"
            :to="item.href"
            :title="isSidebarCollapsed ? item.name : ''"
            class="group flex items-center rounded-xl transition-all"
            :class="[
              isSidebarCollapsed
                ? 'w-10 h-10 mx-auto justify-center'
                : 'gap-2.5 px-3 py-2 text-sm font-medium',
              isCurrentUrl(item.href)
                ? 'bg-rose-600 text-white shadow-sm shadow-rose-600/25 font-semibold'
                : 'text-slate-600 hover:bg-rose-50/70 hover:text-rose-900'
            ]"
          >
            <component
              :is="item.icon"
              class="shrink-0 transition-colors"
              :class="[
                isSidebarCollapsed ? 'h-5 w-5' : 'h-4 w-4',
                isCurrentUrl(item.href) ? 'text-white' : 'text-slate-500 group-hover:text-rose-600'
              ]"
            />
            <span v-if="!isSidebarCollapsed" class="truncate">{{ item.name }}</span>
          </router-link>
        </nav>

        <!-- 2. Vận hành -->
        <div>
          <div v-if="isSidebarCollapsed" class="my-2 border-t border-slate-100"></div>
          <p v-else class="px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            Vận hành
          </p>
          <nav class="space-y-1" :class="isSidebarCollapsed ? 'mt-1' : 'mt-1.5'">
            <router-link
              v-for="item in operationNavigation"
              :key="item.name"
              :to="item.href"
              :title="isSidebarCollapsed ? item.name : ''"
              class="group flex items-center rounded-xl transition-all"
              :class="[
                isSidebarCollapsed
                  ? 'w-10 h-10 mx-auto justify-center'
                  : 'gap-2.5 px-3 py-2 text-sm font-medium',
                isCurrentUrl(item.href)
                  ? 'bg-rose-600 text-white shadow-sm shadow-rose-600/25 font-semibold'
                  : 'text-slate-600 hover:bg-rose-50/70 hover:text-rose-900'
              ]"
            >
              <component
                :is="item.icon"
                class="shrink-0 transition-colors"
                :class="[
                  isSidebarCollapsed ? 'h-5 w-5' : 'h-4 w-4',
                  isCurrentUrl(item.href) ? 'text-white' : 'text-slate-500 group-hover:text-rose-600'
                ]"
              />
              <span v-if="!isSidebarCollapsed" class="truncate">{{ item.name }}</span>
            </router-link>
          </nav>
        </div>

        <!-- 3. Cấu hình -->
        <div>
          <div v-if="isSidebarCollapsed" class="my-2 border-t border-slate-100"></div>
          <p v-else class="px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            Cấu hình
          </p>
          <nav class="space-y-1" :class="isSidebarCollapsed ? 'mt-1' : 'mt-1.5'">
            <router-link
              v-for="item in configNavigation"
              :key="item.name"
              :to="item.href"
              :title="isSidebarCollapsed ? item.name : ''"
              class="group flex items-center rounded-xl transition-all"
              :class="[
                isSidebarCollapsed
                  ? 'w-10 h-10 mx-auto justify-center'
                  : 'gap-2.5 px-3 py-2 text-sm font-medium',
                isCurrentUrl(item.href)
                  ? 'bg-rose-600 text-white shadow-sm shadow-rose-600/25 font-semibold'
                  : 'text-slate-600 hover:bg-rose-50/70 hover:text-rose-900'
              ]"
            >
              <component
                :is="item.icon"
                class="shrink-0 transition-colors"
                :class="[
                  isSidebarCollapsed ? 'h-5 w-5' : 'h-4 w-4',
                  isCurrentUrl(item.href) ? 'text-white' : 'text-slate-500 group-hover:text-rose-600'
                ]"
              />
              <span v-if="!isSidebarCollapsed" class="truncate">{{ item.name }}</span>
            </router-link>
          </nav>
        </div>
      </div>

      <!-- Logout Footer -->
      <div
        class="border-t border-slate-100 bg-white transition-all duration-300"
        :class="isSidebarCollapsed ? 'p-2' : 'p-3'"
      >
        <button
          @click="handleLogout"
          :title="isSidebarCollapsed ? `Đăng xuất (${user?.name || 'Admin'})` : ''"
          class="flex items-center rounded-xl text-slate-600 hover:bg-rose-50 hover:text-rose-600 transition-colors"
          :class="[
            isSidebarCollapsed
              ? 'w-10 h-10 mx-auto justify-center'
              : 'w-full gap-2.5 px-3 py-2 text-sm font-normal'
          ]"
        >
          <LogOut class="text-rose-500 shrink-0" :class="isSidebarCollapsed ? 'h-5 w-5' : 'h-4 w-4'" />
          <span v-if="!isSidebarCollapsed" class="truncate">Đăng xuất ({{ user?.name || 'Admin' }})</span>
        </button>
      </div>
    </aside>

    <!-- Mobile Navigation Drawer -->
    <div v-if="showMobileMenu" class="fixed inset-0 z-50 flex lg:hidden">
      <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs" @click="showMobileMenu = false"></div>
      <div class="relative flex w-64 max-w-xs flex-1 flex-col bg-white p-4 overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
          <div class="flex items-center gap-2">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-tr from-rose-500 to-red-600 text-white">
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
            class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-normal"
            :class="isCurrentUrl(item.href) ? 'bg-rose-600 text-white font-semibold' : 'text-slate-700 hover:bg-rose-50/70 hover:text-rose-900'"
          >
            <component :is="item.icon" class="h-4 w-4" />
            <span>{{ item.name }}</span>
          </router-link>
        </nav>

        <div class="mt-4 pt-3 border-t border-slate-100">
          <button
            @click="handleLogout"
            class="flex items-center gap-2 w-full rounded-xl px-3 py-2 text-sm font-normal text-rose-600 hover:bg-rose-50"
          >
            <LogOut class="h-4 w-4" />
            <span>Đăng xuất</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Main Content Full Width Area -->
    <div
      class="flex flex-1 flex-col min-w-0 w-full transition-all duration-300"
      :class="isSidebarCollapsed ? 'lg:pl-16' : 'lg:pl-60 xl:pl-64'"
    >
      <!-- Top Header -->
      <header class="sticky top-0 z-20 flex h-14 sm:h-15 shrink-0 items-center justify-between border-b border-slate-200/80 bg-white/95 backdrop-blur-md px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3">
          <button
            @click="toggleSidebar"
            class="rounded-lg p-1.5 text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors"
            :title="isSidebarCollapsed ? 'Mở rộng sidebar' : 'Thu gọn sidebar'"
          >
            <Menu class="h-5 w-5" />
          </button>

          <!-- Breadcrumb / Page Title -->
          <div class="flex items-center">
            <h2 class="text-sm sm:text-base font-bold text-slate-900">
              {{ currentPageTitle }}
            </h2>
          </div>
        </div>

        <!-- Top Right Actions -->
        <div class="flex items-center gap-2.5">
          <router-link
            to="/students?create=true"
            class="hidden sm:inline-flex items-center gap-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white px-3.5 py-2 text-sm font-semibold shadow-xs shadow-rose-600/20 transition-all active:scale-[0.98]"
          >
            <Plus class="h-4 w-4 text-white" />
            <span>Học viên mới</span>
          </router-link>

          <!-- User Profile -->
          <div class="pl-3 border-l border-slate-200 text-left">
            <span class="text-sm font-bold text-slate-800 block leading-tight">
              {{ user?.name || 'Khoa Anh Music' }}
            </span>
            <span class="text-xs text-slate-400 font-medium block truncate max-w-[180px]">
              {{ user?.email || 'admin@khoaanhmusic.com' }}
            </span>
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
