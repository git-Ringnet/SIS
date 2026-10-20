<script setup>
import { useBrand } from './services/useBrand';
import BrandLogo from './components/BrandLogo.vue';
import { ref, computed, watch, watchEffect, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from './services/useAuth';
import { isPathBlocked, firstAllowedPath } from './services/permissions';
import { Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';
import ConfirmDialog from './components/ConfirmDialog.vue';
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
} from 'lucide-vue-next';

// Thương hiệu: tải một lần (API công khai, dùng được cả ở màn đăng nhập) và gắn vào tiêu đề tab
const { brandName, nameParts, loadBrand } = useBrand();
loadBrand();

const route = useRoute();
const router = useRouter();
const { user, isAuthenticated, logout, checkAuth } = useAuth();
const showMobileMenu = ref(false);
const isSidebarCollapsed = ref(localStorage.getItem('sis_sidebar_collapsed') === 'true');

// Auto close mobile drawer on route change
watch(() => route.path, () => {
  showMobileMenu.value = false;
});

// Prevent body scroll when mobile menu is open
watch(showMobileMenu, (isOpen) => {
  if (isOpen) {
    document.body.style.overflow = 'hidden';
  } else {
    document.body.style.overflow = '';
  }
});

const handleKeyDown = (e) => {
  if (e.key === 'Escape' && showMobileMenu.value) {
    showMobileMenu.value = false;
  }
};

onMounted(() => {
  window.addEventListener('keydown', handleKeyDown);
  // Lấy lại quyền mới nhất từ server (chủ trung tâm có thể vừa đổi phân quyền)
  if (isAuthenticated.value) {
    checkAuth().then((freshUser) => {
      // Quyền vừa bị đổi mà đang đứng ở trang bị chặn thì chuyển sang trang được phép
      if (freshUser && isPathBlocked(freshUser, route.path)) router.replace(firstAllowedPath(freshUser));
    });
  }
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown);
  document.body.style.overflow = '';
});

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

const allMainNavigation = [
  { name: 'Bảng điều khiển', href: '/', icon: LayoutDashboard },
  { name: 'Học viên', href: '/students', icon: Users },
  { name: 'Điểm danh', href: '/attendance', icon: CheckSquare },
  { name: 'Check-in ca dạy', href: '/teacher-checkin', icon: Clock },
  { name: 'Vắng liên tiếp', href: '/absences', icon: UserX },
];

const allOperationNavigation = [
  { name: 'Khách hàng tiềm năng', href: '/leads', icon: UserPlus },
  { name: 'Doanh thu', href: '/revenue', icon: DollarSign },
  { name: 'Hóa đơn', href: '/invoices', icon: FileText },
  { name: 'Học cụ', href: '/inventory', icon: Package },
  { name: 'Sinh nhật', href: '/birthdays', icon: Cake },
  { name: 'HV Tạm dừng - Nghỉ', href: '/inactive-students', icon: UserMinus },
];

const allConfigNavigation = [
  { name: 'Khóa học', href: '/courses', icon: GraduationCap },
  { name: 'Trung tâm', href: '/centers', icon: Building2 },
  { name: 'Nhân sự', href: '/staff', icon: Briefcase },
  { name: 'Cài đặt', href: '/settings', icon: Settings },
  { name: 'Dữ liệu', href: '/data', icon: Database },
  { name: 'Báo cáo', href: '/reports', icon: BarChart3 },
];

// Ẩn các mục mà nhân sự bị chặn quyền
const visibleNav = (items) => computed(() => items.filter((item) => !isPathBlocked(user.value, item.href)));
const mainNavigation = visibleNav(allMainNavigation);
const operationNavigation = visibleNav(allOperationNavigation);
const configNavigation = visibleNav(allConfigNavigation);

const isCurrentUrl = (path) => {
  if (path === '/') return route.path === '/';
  return route.path.startsWith(path);
};

const currentPageTitle = computed(() => {
  return route.meta?.title || 'Bảng điều khiển';
});

watchEffect(() => {
  document.title = `${currentPageTitle.value} - ${brandName.value}`;
});

const handleLogout = () => {
  logout();
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
      <!-- Brand Logo (lấy từ Cài đặt > Thương hiệu) -->
      <div
        class="flex h-16 shrink-0 items-center border-b border-slate-100 bg-white transition-all duration-300"
        :class="isSidebarCollapsed ? 'justify-center px-2' : 'justify-between px-4'"
      >
        <router-link to="/" class="flex items-center gap-2.5" :title="isSidebarCollapsed ? brandName : ''">
          <BrandLogo box-class="h-9 w-9 rounded-xl" icon-class="h-5 w-5" />
          <div v-if="!isSidebarCollapsed" class="overflow-hidden whitespace-nowrap">
            <span class="text-sm font-black tracking-tight text-slate-900 block leading-tight">
              {{ nameParts.head }} <span class="text-rose-600">{{ nameParts.tail }}</span>
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
        <div v-if="operationNavigation.length > 0">
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
        <div v-if="configNavigation.length > 0">
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

    <!-- Mobile Navigation Drawer with Smooth Slide Transition -->
    <teleport to="body">
      <div
        class="fixed inset-0 z-50 flex lg:hidden"
        :class="showMobileMenu ? 'pointer-events-auto' : 'pointer-events-none'"
        role="dialog"
        aria-modal="true"
      >
        <!-- Backdrop Fade Transition -->
        <transition
          enter-active-class="transition-opacity ease-linear duration-300"
          enter-from-class="opacity-0"
          enter-to-class="opacity-100"
          leave-active-class="transition-opacity ease-linear duration-200"
          leave-from-class="opacity-100"
          leave-to-class="opacity-0"
        >
          <div
            v-show="showMobileMenu"
            class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs pointer-events-auto"
            @click="showMobileMenu = false"
          />
        </transition>

        <!-- Sidebar Slide In/Out Transition -->
        <transition
          enter-active-class="transition ease-out duration-300 transform"
          enter-from-class="-translate-x-full"
          enter-to-class="translate-x-0"
          leave-active-class="transition ease-in duration-200 transform"
          leave-from-class="translate-x-0"
          leave-to-class="-translate-x-full"
        >
          <div
            v-show="showMobileMenu"
            class="relative flex w-72 max-w-[80vw] flex-1 flex-col bg-white p-4 shadow-2xl overflow-y-auto pointer-events-auto overscroll-contain"
          >
            <!-- Drawer Brand & Close Button -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 shrink-0">
              <router-link to="/" @click="showMobileMenu = false" class="flex items-center gap-2">
                <BrandLogo box-class="h-8 w-8 rounded-xl" icon-class="h-4 w-4" />
                <div>
                  <span class="text-sm font-black tracking-tight text-slate-900 block leading-tight">
                    {{ nameParts.head }} <span class="text-rose-600">{{ nameParts.tail }}</span>
                  </span>
                  <span class="text-[9px] font-semibold text-rose-900/50 block tracking-wider uppercase">
                    EduCRM &bull; SIS
                  </span>
                </div>
              </router-link>
              <button
                @click="showMobileMenu = false"
                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors"
                title="Đóng menu"
              >
                <X class="h-5 w-5" />
              </button>
            </div>

            <!-- Navigation Links -->
            <div class="flex-1 overflow-y-auto py-2 space-y-3">
              <!-- 1. Main Navigation -->
              <nav class="space-y-1">
                <router-link
                  v-for="item in mainNavigation"
                  :key="item.name"
                  :to="item.href"
                  @click="showMobileMenu = false"
                  class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition-colors"
                  :class="isCurrentUrl(item.href) ? 'bg-rose-600 text-white font-semibold shadow-xs' : 'text-slate-700 hover:bg-rose-50/70 hover:text-rose-900'"
                >
                  <component :is="item.icon" class="h-4 w-4 shrink-0" />
                  <span class="truncate">{{ item.name }}</span>
                </router-link>
              </nav>

              <!-- 2. Vận hành -->
              <div v-if="operationNavigation.length > 0">
                <p class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                  Vận hành
                </p>
                <nav class="mt-1 space-y-1">
                  <router-link
                    v-for="item in operationNavigation"
                    :key="item.name"
                    :to="item.href"
                    @click="showMobileMenu = false"
                    class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition-colors"
                    :class="isCurrentUrl(item.href) ? 'bg-rose-600 text-white font-semibold shadow-xs' : 'text-slate-700 hover:bg-rose-50/70 hover:text-rose-900'"
                  >
                    <component :is="item.icon" class="h-4 w-4 shrink-0" />
                    <span class="truncate">{{ item.name }}</span>
                  </router-link>
                </nav>
              </div>

              <!-- 3. Cấu hình -->
              <div v-if="configNavigation.length > 0">
                <p class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                  Cấu hình
                </p>
                <nav class="mt-1 space-y-1">
                  <router-link
                    v-for="item in configNavigation"
                    :key="item.name"
                    :to="item.href"
                    @click="showMobileMenu = false"
                    class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition-colors"
                    :class="isCurrentUrl(item.href) ? 'bg-rose-600 text-white font-semibold shadow-xs' : 'text-slate-700 hover:bg-rose-50/70 hover:text-rose-900'"
                  >
                    <component :is="item.icon" class="h-4 w-4 shrink-0" />
                    <span class="truncate">{{ item.name }}</span>
                  </router-link>
                </nav>
              </div>
            </div>

            <!-- Logout Footer -->
            <div class="pt-3 border-t border-slate-100 shrink-0">
              <button
                @click="handleLogout"
                class="flex items-center gap-2.5 w-full rounded-xl px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50 transition-colors"
              >
                <LogOut class="h-4 w-4 shrink-0 text-rose-500" />
                <span class="truncate">Đăng xuất ({{ user?.name || 'Admin' }})</span>
              </button>
            </div>
          </div>
        </transition>
      </div>
    </teleport>

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
            v-if="!isPathBlocked(user, '/students')"
            to="/students?create=true"
            class="hidden sm:inline-flex items-center gap-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white px-3.5 py-2 text-sm font-semibold shadow-xs shadow-rose-600/20 transition-all active:scale-[0.98]"
          >
            <Plus class="h-4 w-4 text-white" />
            <span>Học viên mới</span>
          </router-link>

          <!-- User Profile -->
          <div class="pl-3 border-l border-slate-200 text-left">
            <span class="text-sm font-bold text-slate-800 block leading-tight">
              {{ user?.name || brandName }}
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

  <Toaster position="top-right" rich-colors close-button :duration="4000" />
  <ConfirmDialog />
</template>
