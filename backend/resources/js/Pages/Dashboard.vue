<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import StateButtons from '@/Components/StateButtons.vue';
import MessageTemplateModal from '@/Components/MessageTemplateModal.vue';
import RetentionNoteModal from '@/Components/RetentionNoteModal.vue';
import RenewModal from '@/Components/RenewModal.vue';
import AttendanceButton from '@/Components/AttendanceButton.vue';
import {
    Users,
    UserX,
    DollarSign,
    TrendingUp,
    AlertTriangle,
    Flame,
    PhoneCall,
    CheckCircle2,
    Clock,
    ArrowUpRight,
    MessageSquare,
    ExternalLink,
    ChevronRight,
    Calendar,
    Sparkles,
} from 'lucide-vue-next';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    expiringStudents: {
        type: Array,
        default: () => [],
    },
    absenceAlerts: {
        type: Array,
        default: () => [],
    },
    recentAttendances: {
        type: Array,
        default: () => [],
    },
    courses: {
        type: Array,
        default: () => [],
    },
});

// Modal states
const showTemplateModal = ref(false);
const showNoteModal = ref(false);
const showRenewModal = ref(false);
const selectedStudent = ref(null);

const openTemplateModal = (student) => {
    selectedStudent.value = student;
    showTemplateModal.value = true;
};

const openNoteModal = (student) => {
    selectedStudent.value = student;
    showNoteModal.value = true;
};

const openRenewModal = (student) => {
    selectedStudent.value = student;
    showRenewModal.value = true;
};

const formatVND = (val) => {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const formatDate = (val) => {
    if (!val) return '—';
    const d = new Date(val);
    return d.toLocaleDateString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
};

const getZaloLink = (phone) => {
    if (!phone) return '#';
    return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};
</script>

<template>
    <AppLayout>
        <Head title="Bảng Điều Khiển - EduCRM SIS" />

        <div class="space-y-6">
            <!-- Header greeting -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                        Bảng Điều Khiển Trung Tâm
                    </h1>
                    <p class="text-xs text-slate-500 mt-1">
                        Theo dõi vận hành, số buổi học viên, dòng tiền và chu kỳ tái đăng ký tự động.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        href="/retention"
                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-rose-600 to-amber-600 px-4 py-2 text-xs font-bold text-white shadow-md shadow-rose-500/20 hover:from-rose-700 hover:to-amber-700 transition-all"
                    >
                        <Flame class="h-4 w-4 animate-bounce" />
                        <span>Xử lý Tái Đăng Ký ({{ stats.expiringCount }})</span>
                    </Link>
                </div>
            </div>

            <!-- Top 4 Statistics Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- 1. Active Students -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs transition-all hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-500">Học viên đang học</span>
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                            <Users class="h-5 w-5" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-3xl font-extrabold text-slate-900">{{ stats.activeStudents }}</span>
                        <span class="text-xs font-medium text-slate-400">/ {{ stats.totalStudents }} tổng số</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                        <span>Đang tạm dừng: <strong class="text-amber-600 font-semibold">{{ stats.pausedStudents }}</strong></span>
                        <Link href="/students" class="text-indigo-600 hover:underline font-medium">Chi tiết</Link>
                    </div>
                </div>

                <!-- 2. Retention Alert (<6 sessions) -->
                <div class="rounded-2xl border border-rose-200/80 bg-rose-50/40 p-5 shadow-xs transition-all hover:shadow-md relative overflow-hidden">
                    <div class="absolute -right-3 -top-3 h-16 w-16 rounded-full bg-rose-100/60 blur-xs"></div>
                    <div class="flex items-center justify-between relative">
                        <span class="text-xs font-bold text-rose-700">Sắp hết buổi (&le; 5 buổi)</span>
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                            <Flame class="h-5 w-5 animate-pulse" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2 relative">
                        <span class="text-3xl font-extrabold text-rose-600">{{ stats.expiringCount }}</span>
                        <span class="text-xs font-medium text-rose-500">học viên cần chăm sóc</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-[11px] text-rose-600 relative">
                        <span>Cần nhắc học phí giữ chân</span>
                        <Link href="/retention" class="font-bold underline hover:text-rose-800">Checklist &rarr;</Link>
                    </div>
                </div>

                <!-- 3. Today Revenue -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs transition-all hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-500">Doanh thu trong ngày</span>
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <DollarSign class="h-5 w-5" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-2xl font-extrabold text-slate-900">{{ formatVND(stats.todayRevenue) }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                        <span class="flex items-center text-emerald-600 font-medium">
                            <CheckCircle2 class="h-3 w-3 mr-1" /> Tiền thực thu hôm nay
                        </span>
                        <Link href="/invoices" class="text-indigo-600 hover:underline font-medium">Hóa đơn</Link>
                    </div>
                </div>

                <!-- 4. Month Revenue -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs transition-all hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-500">Doanh thu tháng này</span>
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                            <TrendingUp class="h-5 w-5" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-2xl font-extrabold text-indigo-700">{{ formatVND(stats.monthRevenue) }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                        <span>{{ stats.totalInvoicesCount }} phiếu thu hoàn tất</span>
                        <span class="text-xs text-indigo-600 font-medium font-mono">Tháng {{ new Date().getMonth() + 1 }}</span>
                    </div>
                </div>
            </div>

            <!-- TWO CORE URGENT SECTIONS (Retention & Absence Alerts) -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- SECTION 1: CẢNH BÁO SẮP HẾT BUỔI - RETENTION CHECKLIST (Span 2) -->
                <div class="lg:col-span-2 rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
                    <div class="flex items-center justify-between border-b border-slate-100 p-5 bg-gradient-to-r from-rose-50/50 to-white">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100 text-rose-600">
                                <Flame class="h-4 w-4" />
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-slate-900">
                                    Cảnh Báo Khẩn Cấp: Học Viên Sắp Hết Buổi (&le; 5 buổi)
                                </h2>
                                <p class="text-[11px] text-slate-500">
                                    Thực hiện quy trình giữ chân: Gửi hóa đơn &rarr; Chăm sóc &rarr; Đóng phí gia hạn.
                                </p>
                            </div>
                        </div>

                        <Link
                            href="/retention"
                            class="inline-flex items-center gap-1 text-xs font-semibold text-rose-600 hover:text-rose-700"
                        >
                            <span>Toàn bộ checklist</span>
                            <ArrowUpRight class="h-3.5 w-3.5" />
                        </Link>
                    </div>

                    <!-- Table of Expiring Students with State Buttons -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50/75 text-slate-500 border-b border-slate-100">
                                <tr>
                                    <th class="py-3 px-4 font-semibold">Học viên / Phụ huynh</th>
                                    <th class="py-3 px-4 font-semibold">Khóa học</th>
                                    <th class="py-3 px-4 font-semibold text-center">Tiến độ buổi</th>
                                    <th class="py-3 px-4 font-semibold">Quy trình giữ chân</th>
                                    <th class="py-3 px-4 font-semibold text-right">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <tr
                                    v-for="s in expiringStudents"
                                    :key="s.id"
                                    class="hover:bg-slate-50/80 transition-colors"
                                >
                                    <td class="py-3.5 px-4">
                                        <div class="flex items-center gap-2.5">
                                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 font-bold text-slate-700 text-xs shrink-0">
                                                {{ s.name.charAt(0) }}
                                            </div>
                                            <div>
                                                <Link :href="`/students/${s.id}`" class="font-bold text-slate-900 hover:text-indigo-600">
                                                    {{ s.name }}
                                                </Link>
                                                <p class="text-[11px] text-slate-500">
                                                    PH: {{ s.parent_name }} - <a :href="getZaloLink(s.parent_phone)" target="_blank" class="text-blue-600 hover:underline">{{ s.parent_phone }}</a>
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="py-3.5 px-4">
                                        <span class="font-medium text-slate-800">{{ s.course?.name }}</span>
                                    </td>

                                    <!-- Progress & Remaining Sessions -->
                                    <td class="py-3.5 px-4 text-center">
                                        <div class="inline-flex flex-col items-center">
                                            <span
                                                class="rounded-md px-2 py-0.5 font-bold text-xs shadow-xs"
                                                :class="s.remaining_sessions <= 2 ? 'bg-rose-100 text-rose-700 ring-1 ring-rose-300' : 'bg-amber-100 text-amber-800 ring-1 ring-amber-300'"
                                            >
                                                Còn {{ s.remaining_sessions }} buổi
                                            </span>
                                            <span class="text-[10px] text-slate-400 mt-0.5">
                                                Đã học {{ s.total_sessions - s.remaining_sessions }}/{{ s.total_sessions }}
                                            </span>
                                        </div>
                                    </td>

                                    <!-- State Buttons directly in row! -->
                                    <td class="py-3.5 px-4">
                                        <StateButtons
                                            :student="s"
                                            :compact="true"
                                            @open-template="openTemplateModal"
                                            @open-note="openNoteModal"
                                            @open-renew="openRenewModal"
                                        />
                                    </td>

                                    <!-- 1-Click Attendance button -->
                                    <td class="py-3.5 px-4 text-right">
                                        <AttendanceButton :student="s" :compact="true" />
                                    </td>
                                </tr>

                                <tr v-if="expiringStudents.length === 0">
                                    <td colspan="5" class="py-8 text-center text-xs text-slate-400">
                                        🎉 Tuyệt vời! Hiện không có học viên nào dưới 6 buổi cần xử lý.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SECTION 2: CẢNH BÁO VẮNG HỌC LIÊN TIẾP (Span 1) -->
                <div class="rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden flex flex-col">
                    <div class="flex items-center justify-between border-b border-slate-100 p-5 bg-gradient-to-r from-amber-50/50 to-white">
                        <div class="flex items-center gap-2">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                                <AlertTriangle class="h-4 w-4" />
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-slate-900">
                                    Vắng Học Liên Tiếp (&ge; 2 buổi)
                                </h2>
                                <p class="text-[11px] text-slate-500">Cần liên hệ phụ huynh hỏi thăm</p>
                            </div>
                        </div>
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">
                            {{ stats.absenceCount }}
                        </span>
                    </div>

                    <div class="p-4 flex-1 divide-y divide-slate-100 overflow-y-auto max-h-[380px] space-y-3">
                        <div
                            v-for="abs in absenceAlerts"
                            :key="abs.id"
                            class="pt-3 first:pt-0"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <Link :href="`/students/${abs.id}`" class="font-bold text-slate-900 hover:text-indigo-600 text-xs">
                                        {{ abs.name }}
                                    </Link>
                                    <p class="text-[11px] text-slate-500">
                                        {{ abs.course?.name }} &bull; PH: {{ abs.parent_name }}
                                    </p>
                                </div>
                                <span class="rounded-md bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-700">
                                    Vắng {{ abs.consecutive_absences }} buổi
                                </span>
                            </div>

                            <div class="mt-2 flex items-center justify-between gap-2">
                                <p class="text-[11px] text-slate-600 italic truncate max-w-[180px]">
                                    {{ abs.notes || 'Chưa có ghi chú lý do vắng' }}
                                </p>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <button
                                        @click="openTemplateModal(abs)"
                                        class="rounded-md bg-indigo-50 px-2 py-1 text-[11px] font-semibold text-indigo-700 hover:bg-indigo-100"
                                        title="Gửi mẫu tin nhắn hỏi thăm vắng học"
                                    >
                                        Mẫu tin
                                    </button>
                                    <a
                                        :href="getZaloLink(abs.parent_phone)"
                                        target="_blank"
                                        class="rounded-md bg-blue-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-blue-700"
                                    >
                                        Zalo
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div v-if="absenceAlerts.length === 0" class="py-8 text-center text-xs text-slate-400">
                            ✅ Tất cả học viên đều đi học đầy đủ, không có trường hợp vắng liên tiếp.
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: RECENT ATTENDANCE ACTIVITY & QUICK COURSE STATS -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Recent Attendance List -->
                <div class="lg:col-span-2 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
                    <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                        <div class="flex items-center gap-2">
                            <Clock class="h-4 w-4 text-indigo-600" />
                            <h3 class="text-sm font-bold text-slate-900">Hoạt Động Điểm Danh Hôm Nay</h3>
                        </div>
                        <Link href="/students" class="text-xs font-semibold text-indigo-600 hover:underline">
                            Xem tất cả học viên
                        </Link>
                    </div>

                    <div class="divide-y divide-slate-100 text-xs">
                        <div
                            v-for="att in recentAttendances"
                            :key="att.id"
                            class="py-2.5 flex items-center justify-between"
                        >
                            <div class="flex items-center gap-3">
                                <span class="flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                <div>
                                    <span class="font-bold text-slate-900">{{ att.student?.name }}</span>
                                    <span class="text-slate-400 ml-2">({{ att.student?.course?.name }})</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 text-slate-500">
                                <span>Buổi #{{ att.session_number }}</span>
                                <span class="font-mono text-slate-400">{{ formatDate(att.attended_at) }}</span>
                                <Badge variant="green" size="sm">Có mặt</Badge>
                            </div>
                        </div>

                        <div v-if="recentAttendances.length === 0" class="py-6 text-center text-slate-400">
                            Chưa có lượt điểm danh nào được ghi nhận hôm nay.
                        </div>
                    </div>
                </div>

                <!-- Course Summary List -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
                    <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                        <h3 class="text-sm font-bold text-slate-900">Khóa Học Đang Mở</h3>
                        <Link href="/courses" class="text-xs font-semibold text-indigo-600 hover:underline">
                            Quản lý
                        </Link>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="c in courses"
                            :key="c.id"
                            class="flex items-center justify-between rounded-xl bg-slate-50 p-2.5 text-xs border border-slate-100"
                        >
                            <div class="flex items-center gap-2">
                                <span class="h-3 w-3 rounded-full shrink-0" :style="{ backgroundColor: c.color }"></span>
                                <div>
                                    <p class="font-bold text-slate-800">{{ c.name }}</p>
                                    <p class="text-[11px] text-slate-500">{{ formatVND(c.price) }} / {{ c.default_sessions }} buổi</p>
                                </div>
                            </div>
                            <span class="rounded-md bg-white px-2 py-1 font-bold text-slate-700 shadow-xs border border-slate-200/80">
                                {{ c.active_students_count }} học viên
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <MessageTemplateModal
            :show="showTemplateModal"
            :student="selectedStudent"
            :templates="$page.props.templates || []"
            @close="showTemplateModal = false"
        />

        <RetentionNoteModal
            :show="showNoteModal"
            :student="selectedStudent"
            @close="showNoteModal = false"
        />

        <RenewModal
            :show="showRenewModal"
            :student="selectedStudent"
            @close="showRenewModal = false"
        />
    </AppLayout>
</template>
