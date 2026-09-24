<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import StateButtons from '@/Components/StateButtons.vue';
import MessageTemplateModal from '@/Components/MessageTemplateModal.vue';
import RetentionNoteModal from '@/Components/RetentionNoteModal.vue';
import RenewModal from '@/Components/RenewModal.vue';
import AttendanceButton from '@/Components/AttendanceButton.vue';
import {
    Flame,
    Receipt,
    PhoneCall,
    CheckCircle2,
    MessageSquare,
    FileText,
    ExternalLink,
    Filter,
    ChevronDown,
    ChevronUp,
    Calendar,
    Sparkles,
    Search,
} from 'lucide-vue-next';

const props = defineProps({
    students: {
        type: Array,
        default: () => [],
    },
    templates: {
        type: Array,
        default: () => [],
    },
    courses: {
        type: Array,
        default: () => [],
    },
    counts: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ status: 'all', threshold: 5 }),
    },
});

// Filter state
const currentStatus = ref(props.filters.status || 'all');
const currentThreshold = ref(props.filters.threshold || 5);
const search = ref('');
const expandedLogsStudentId = ref(null);

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

const filterByStatus = (status) => {
    currentStatus.value = status;
    router.get(
        '/retention',
        { status: status, threshold: currentThreshold.value },
        { preserveState: true, preserveScroll: true }
    );
};

const filterByThreshold = (threshold) => {
    currentThreshold.value = threshold;
    router.get(
        '/retention',
        { status: currentStatus.value, threshold: threshold },
        { preserveState: true, preserveScroll: true }
    );
};

const toggleLogs = (id) => {
    if (expandedLogsStudentId.value === id) {
        expandedLogsStudentId.value = null;
    } else {
        expandedLogsStudentId.value = id;
    }
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
        <Head title="Tái Đăng Ký & Giữ Chân Học Viên (Retention)" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100 text-rose-600">
                            <Flame class="h-5 w-5 animate-pulse" />
                        </div>
                        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                            Checklist Tái Đăng Ký & Giữ Chân Học Viên (Retention)
                        </h1>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">
                        Hệ thống tự động lọc các học viên có số buổi còn lại &le; 5. Dùng các nút trạng thái để đánh dấu tiến độ follow-up phụ huynh.
                    </p>
                </div>

                <!-- Threshold Selector -->
                <div class="flex items-center gap-2 bg-white rounded-xl p-1.5 border border-slate-200 shadow-xs text-xs">
                    <span class="text-slate-500 px-2 font-medium">Ngưỡng cảnh báo:</span>
                    <button
                        v-for="th in [3, 5, 8]"
                        :key="th"
                        @click="filterByThreshold(th)"
                        class="rounded-lg px-2.5 py-1 font-bold transition-all"
                        :class="currentThreshold === th ? 'bg-rose-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        &le; {{ th }} buổi
                    </button>
                </div>
            </div>

            <!-- Retention Pipeline Status Tabs -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <!-- 1. All -->
                <button
                    @click="filterByStatus('all')"
                    class="rounded-xl border p-3.5 text-left transition-all relative overflow-hidden"
                    :class="currentStatus === 'all' ? 'border-indigo-600 bg-indigo-50/60 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
                >
                    <span class="text-xs font-semibold text-slate-500">Tất cả sắp hết buổi</span>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-2xl font-extrabold text-slate-900">{{ counts.total_expiring }}</span>
                        <span class="text-[11px] text-slate-400">học viên</span>
                    </div>
                </button>

                <!-- 2. Normal (Chưa chăm sóc) -->
                <button
                    @click="filterByStatus('normal')"
                    class="rounded-xl border p-3.5 text-left transition-all relative overflow-hidden"
                    :class="currentStatus === 'normal' ? 'border-slate-600 bg-slate-100 ring-2 ring-slate-400/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
                >
                    <span class="text-xs font-semibold text-slate-500">Chưa bắt đầu chăm sóc</span>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-2xl font-extrabold text-slate-700">{{ counts.normal }}</span>
                        <span class="text-[11px] text-slate-400">chờ xử lý</span>
                    </div>
                </button>

                <!-- 3. Invoice Sent [Hóa đơn - Xanh dương] -->
                <button
                    @click="filterByStatus('invoice_sent')"
                    class="rounded-xl border p-3.5 text-left transition-all relative overflow-hidden"
                    :class="currentStatus === 'invoice_sent' ? 'border-blue-600 bg-blue-50 ring-2 ring-blue-500/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
                >
                    <div class="flex items-center gap-1.5 text-xs font-bold text-blue-700">
                        <Receipt class="h-3.5 w-3.5" />
                        <span>Đã gửi hóa đơn</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-2xl font-extrabold text-blue-600">{{ counts.invoice_sent }}</span>
                        <span class="text-[11px] text-blue-500">xanh dương</span>
                    </div>
                </button>

                <!-- 4. Following Up [Chăm sóc - Cam] -->
                <button
                    @click="filterByStatus('following_up')"
                    class="rounded-xl border p-3.5 text-left transition-all relative overflow-hidden"
                    :class="currentStatus === 'following_up' ? 'border-amber-500 bg-amber-50 ring-2 ring-amber-500/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
                >
                    <div class="flex items-center gap-1.5 text-xs font-bold text-amber-800">
                        <PhoneCall class="h-3.5 w-3.5" />
                        <span>Đang chăm sóc</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-2xl font-extrabold text-amber-600">{{ counts.following_up }}</span>
                        <span class="text-[11px] text-amber-600">màu cam</span>
                    </div>
                </button>

                <!-- 5. Renewed [Đã đóng - Xanh lá] -->
                <button
                    @click="filterByStatus('renewed')"
                    class="rounded-xl border p-3.5 text-left transition-all relative overflow-hidden"
                    :class="currentStatus === 'renewed' ? 'border-emerald-600 bg-emerald-50 ring-2 ring-emerald-500/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
                >
                    <div class="flex items-center gap-1.5 text-xs font-bold text-emerald-700">
                        <CheckCircle2 class="h-3.5 w-3.5" />
                        <span>Đã đóng & Tái ký</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-2xl font-extrabold text-emerald-600">{{ counts.renewed }}</span>
                        <span class="text-[11px] text-emerald-600">thành công 🎉</span>
                    </div>
                </button>
            </div>

            <!-- Checklist Retention Table -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 p-4 bg-slate-50/50">
                    <span class="text-xs font-bold text-slate-700">
                        Danh Sách Học Viên Thuộc Chu Kỳ Giữ Chân ({{ students.length }} học viên)
                    </span>
                    <span class="text-xs text-slate-500">
                        Click trực tiếp vào các nút trạng thái để đổi màu và ghi nhận tiến độ
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50/80 text-slate-500 border-b border-slate-100">
                            <tr>
                                <th class="py-3 px-4 font-semibold">Học viên & Phụ huynh</th>
                                <th class="py-3 px-4 font-semibold">Khóa học</th>
                                <th class="py-3 px-4 font-semibold text-center">Tiến độ khóa học</th>
                                <th class="py-3 px-4 font-semibold">Dãy Nút Trạng Thái (1-Click Retention)</th>
                                <th class="py-3 px-4 font-semibold">Nhật ký trao đổi gần nhất</th>
                                <th class="py-3 px-4 font-semibold text-right">Điểm danh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <template v-for="student in students" :key="student.id">
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <!-- Student & Parent info -->
                                    <td class="py-4 px-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 font-bold text-indigo-700 text-xs shrink-0">
                                                {{ student.name.charAt(0) }}
                                            </div>
                                            <div>
                                                <Link :href="`/students/${student.id}`" class="font-bold text-slate-900 hover:text-indigo-600">
                                                    {{ student.name }}
                                                </Link>
                                                <div class="flex items-center gap-2 text-[11px] text-slate-500 mt-0.5">
                                                    <span>PH: {{ student.parent_name }}</span>
                                                    <span>&bull;</span>
                                                    <a :href="getZaloLink(student.parent_phone)" target="_blank" class="text-blue-600 font-semibold hover:underline flex items-center gap-0.5">
                                                        <span>{{ student.parent_phone }}</span>
                                                        <ExternalLink class="h-2.5 w-2.5" />
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Course -->
                                    <td class="py-4 px-4">
                                        <div class="font-semibold text-slate-800">{{ student.course?.name }}</div>
                                        <div class="text-[11px] text-slate-400">{{ formatVND(student.course?.price) }}</div>
                                    </td>

                                    <!-- Progress & Remaining Sessions Visual -->
                                    <td class="py-4 px-4 text-center">
                                        <div class="inline-flex flex-col items-center">
                                            <span
                                                class="rounded-lg px-2.5 py-1 font-extrabold text-xs shadow-xs"
                                                :class="student.remaining_sessions <= 2 ? 'bg-rose-100 text-rose-700 ring-1 ring-rose-400 animate-pulse' : 'bg-amber-100 text-amber-800 ring-1 ring-amber-400'"
                                            >
                                                Còn {{ student.remaining_sessions }} buổi
                                            </span>

                                            <!-- Progress bar -->
                                            <div class="w-24 bg-slate-100 rounded-full h-1.5 mt-1.5 overflow-hidden">
                                                <div
                                                    class="h-full rounded-full transition-all"
                                                    :class="student.remaining_sessions <= 2 ? 'bg-rose-500' : 'bg-amber-500'"
                                                    :style="{ width: `${Math.min(100, Math.round(((student.total_sessions - student.remaining_sessions) / student.total_sessions) * 100))}%` }"
                                                ></div>
                                            </div>
                                            <span class="text-[10px] text-slate-400 mt-0.5">
                                                {{ student.total_sessions - student.remaining_sessions }}/{{ student.total_sessions }} buổi
                                            </span>
                                        </div>
                                    </td>

                                    <!-- State Buttons Column (Signature Requirement) -->
                                    <td class="py-4 px-4">
                                        <StateButtons
                                            :student="student"
                                            @open-template="openTemplateModal"
                                            @open-note="openNoteModal"
                                            @open-renew="openRenewModal"
                                        />
                                    </td>

                                    <!-- Recent Retention Log / Notes -->
                                    <td class="py-4 px-4 max-w-xs">
                                        <div v-if="student.latest_retention_log" class="rounded-lg bg-slate-50 p-2 text-[11px] border border-slate-100">
                                            <div class="flex items-center justify-between text-slate-400">
                                                <span class="font-semibold text-slate-700">{{ student.latest_retention_log.created_by }}</span>
                                                <span>{{ formatDate(student.latest_retention_log.contact_date) }}</span>
                                            </div>
                                            <p class="text-slate-600 mt-1 line-clamp-2">
                                                {{ student.latest_retention_log.content }}
                                            </p>
                                        </div>
                                        <div v-else class="text-[11px] text-slate-400 italic">
                                            Chưa có nhật ký chăm sóc
                                        </div>

                                        <button
                                            v-if="student.retention_logs?.length > 1"
                                            @click="toggleLogs(student.id)"
                                            class="mt-1 text-[11px] font-medium text-indigo-600 hover:underline inline-flex items-center gap-1"
                                        >
                                            <span>Xem lịch sử ({{ student.retention_logs.length }})</span>
                                            <ChevronDown v-if="expandedLogsStudentId !== student.id" class="h-3 w-3" />
                                            <ChevronUp v-else class="h-3 w-3" />
                                        </button>
                                    </td>

                                    <!-- 1-Click Attendance -->
                                    <td class="py-4 px-4 text-right">
                                        <AttendanceButton :student="student" />
                                    </td>
                                </tr>

                                <!-- Expanded Full Logs Row -->
                                <tr v-if="expandedLogsStudentId === student.id" class="bg-slate-50/90">
                                    <td colspan="6" class="p-4 pl-12">
                                        <div class="rounded-xl bg-white p-4 border border-slate-200 space-y-3">
                                            <h4 class="font-bold text-slate-800 text-xs flex items-center gap-1.5">
                                                <FileText class="h-3.5 w-3.5 text-amber-500" />
                                                <span>Toàn bộ lịch sử chăm sóc phụ huynh: {{ student.name }}</span>
                                            </h4>

                                            <div class="space-y-2 max-h-48 overflow-y-auto">
                                                <div
                                                    v-for="log in student.retention_logs"
                                                    :key="log.id"
                                                    class="rounded-lg bg-slate-50 p-2.5 text-xs border border-slate-100 flex items-start justify-between gap-4"
                                                >
                                                    <div>
                                                        <span class="font-semibold text-slate-800">{{ log.created_by }}:</span>
                                                        <span class="text-slate-700 ml-1">{{ log.content }}</span>
                                                    </div>
                                                    <span class="text-[10px] text-slate-400 shrink-0 font-mono">{{ formatDate(log.contact_date) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-if="students.length === 0">
                                <td colspan="6" class="py-12 text-center text-xs text-slate-400">
                                    Không có học viên nào phù hợp với bộ lọc hiện tại.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <MessageTemplateModal
            :show="showTemplateModal"
            :student="selectedStudent"
            :templates="templates"
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
