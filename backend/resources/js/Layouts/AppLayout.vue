<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    Flame,
    Users,
    UserCheck,
    Kanban,
    Receipt,
    BookOpen,
    Bell,
    CheckCircle2,
    AlertCircle,
    X,
    ChevronDown,
    GraduationCap,
    Plus,
    Search,
    Menu,
} from 'lucide-vue-next';

const page = usePage();
const showMobileMenu = ref(false);
const showFlash = ref(true);

const flash = computed(() => page.props.flash || {});
const center = computed(() => page.props.center || {});
const authUser = computed(() => page.props.auth?.user || {});

const navigation = [
    {
        name: 'Bảng Điều Khiển',
        href: '/dashboard',
        icon: LayoutDashboard,
        route: 'dashboard',
    },
    {
        name: 'Tái Đăng Ký & Giữ Chân',
        href: '/retention',
        icon: Flame,
        badge: 'Ưu tiên',
        badgeColor: 'bg-rose-500 text-white',
        route: 'retention.index',
    },
    {
        name: 'Học Viên & Điểm Danh',
        href: '/students',
        icon: Users,
        route: 'students.index',
    },
    {
        name: 'Khách Hàng Tiềm Năng',
        href: '/leads',
        icon: Kanban,
        route: 'leads.index',
    },
    {
        name: 'Quản Lý Thu Học Phí',
        href: '/invoices',
        icon: Receipt,
        route: 'invoices.index',
    },
    {
        name: 'Khóa Học & Lớp',
        href: '/courses',
        icon: BookOpen,
        route: 'courses.index',
    },
];

const isCurrentUrl = (url) => {
    return page.url === url || page.url.startsWith(url + '?');
};
</script>

<template>
    <div class="min-h-screen bg-slate-50 flex">
        <!-- Sidebar Desktop -->
        <aside class="hidden lg:flex lg:w-64 lg:flex-col lg:fixed lg:inset-y-0 z-30 bg-slate-900 border-r border-slate-800">
            <!-- Brand Logo -->
            <div class="flex h-16 shrink-0 items-center justify-between px-6 border-b border-slate-800/80 bg-slate-950/40">
                <Link href="/" class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 text-white shadow-md shadow-indigo-500/20">
                        <GraduationCap class="h-5 w-5" />
                    </div>
                    <div>
                        <span class="text-sm font-bold tracking-tight text-white block">EduCRM <span class="text-indigo-400 font-extrabold">SIS</span></span>
                        <span class="text-[10px] text-slate-400 block -mt-0.5">{{ center.name || 'Trung Tâm Đào Tạo' }}</span>
                    </div>
                </Link>
            </div>

            <!-- Navigation Links -->
            <div class="flex flex-1 flex-col overflow-y-auto px-3 py-4 space-y-6">
                <div>
                    <p class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-500">Phân hệ quản lý</p>
                    <nav class="mt-2 space-y-1">
                        <Link
                            v-for="item in navigation"
                            :key="item.name"
                            :href="item.href"
                            class="group flex items-center justify-between rounded-xl px-3 py-2.5 text-xs font-semibold transition-all"
                            :class="[
                                isCurrentUrl(item.href)
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/25'
                                    : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                            ]"
                        >
                            <div class="flex items-center gap-3">
                                <component
                                    :is="item.icon"
                                    class="h-4 w-4 shrink-0 transition-colors"
                                    :class="isCurrentUrl(item.href) ? 'text-white' : 'text-slate-400 group-hover:text-white'"
                                />
                                <span>{{ item.name }}</span>
                            </div>

                            <span
                                v-if="item.badge"
                                class="rounded-full px-2 py-0.5 text-[10px] font-bold shadow-xs uppercase tracking-wider"
                                :class="item.badgeColor"
                            >
                                {{ item.badge }}
                            </span>
                        </Link>
                    </nav>
                </div>

                <!-- Retention Priority Callout Banner -->
                <div class="rounded-xl border border-rose-500/20 bg-gradient-to-br from-rose-950/40 to-slate-900 p-3 text-slate-300">
                    <div class="flex items-center gap-2 text-rose-400">
                        <Flame class="h-4 w-4 animate-pulse text-rose-500" />
                        <span class="text-xs font-bold uppercase tracking-wide">Quy trình Retention</span>
                    </div>
                    <p class="mt-1.5 text-[11px] text-slate-400 leading-relaxed">
                        Tự động theo dõi học viên dưới 6 buổi & kích hoạt quy trình tái đăng ký 1-click.
                    </p>
                    <Link
                        href="/retention"
                        class="mt-2.5 inline-flex items-center justify-center w-full rounded-lg bg-rose-600/90 hover:bg-rose-600 px-2.5 py-1.5 text-xs font-semibold text-white transition-colors"
                    >
                        Xem danh sách sắp hết buổi
                    </Link>
                </div>
            </div>

            <!-- User Footer in Sidebar -->
            <div class="border-t border-slate-800 p-3.5 bg-slate-950/40">
                <div class="flex items-center gap-3">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-500/20 text-indigo-400 font-bold text-xs ring-1 ring-indigo-500/30">
                        {{ authUser.name ? authUser.name.charAt(0) : 'A' }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-slate-200 truncate">{{ authUser.name }}</p>
                        <p class="text-[11px] text-slate-400 truncate">{{ authUser.email }}</p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="flex flex-1 flex-col lg:pl-64">
            <!-- Top Navbar -->
            <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white/95 backdrop-blur-md px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4">
                    <!-- Mobile Menu Button -->
                    <button
                        @click="showMobileMenu = !showMobileMenu"
                        class="rounded-lg p-1.5 text-slate-600 hover:bg-slate-100 lg:hidden"
                    >
                        <Menu class="h-6 w-6" />
                    </button>

                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10">
                            {{ center.branch || 'Cơ sở chính' }}
                        </span>
                        <span class="text-xs text-slate-400 hidden sm:inline">|</span>
                        <span class="text-xs font-medium text-slate-500 hidden sm:inline">
                            Hotline: <strong class="text-slate-800 font-semibold">{{ center.hotline }}</strong>
                        </span>
                    </div>
                </div>

                <!-- Top Right Actions -->
                <div class="flex items-center gap-3">
                    <Link
                        href="/students"
                        class="hidden sm:inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-indigo-600 shadow-xs transition-colors"
                    >
                        <Plus class="h-3.5 w-3.5 text-indigo-600" />
                        <span>Học viên mới</span>
                    </Link>

                    <Link
                        href="/retention"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 shadow-xs transition-colors"
                    >
                        <Flame class="h-3.5 w-3.5 text-rose-600" />
                        <span>Cảnh báo hết buổi</span>
                    </Link>
                </div>
            </header>

            <!-- Flash Toast Notifications -->
            <transition
                enter-active-class="transform ease-out duration-300 transition"
                enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
                enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="flash.success && showFlash"
                    class="fixed bottom-5 right-5 z-50 flex items-center gap-3 rounded-xl bg-emerald-900/95 text-white p-4 shadow-xl border border-emerald-700 backdrop-blur-md max-w-md animate-slide-in"
                >
                    <CheckCircle2 class="h-5 w-5 text-emerald-400 shrink-0" />
                    <p class="text-xs font-medium">{{ flash.success }}</p>
                    <button @click="showFlash = false" class="ml-auto text-emerald-300 hover:text-white">
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </transition>

            <transition
                enter-active-class="transform ease-out duration-300 transition"
                enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
                enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="flash.error && showFlash"
                    class="fixed bottom-5 right-5 z-50 flex items-center gap-3 rounded-xl bg-rose-900/95 text-white p-4 shadow-xl border border-rose-700 backdrop-blur-md max-w-md animate-slide-in"
                >
                    <AlertCircle class="h-5 w-5 text-rose-400 shrink-0" />
                    <p class="text-xs font-medium">{{ flash.error }}</p>
                    <button @click="showFlash = false" class="ml-auto text-rose-300 hover:text-white">
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </transition>

            <!-- Page Body -->
            <main class="flex-1 p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
