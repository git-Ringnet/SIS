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
import Modal from '@/Components/Modal.vue';
import {
    GraduationCap,
    ArrowLeft,
    Phone,
    Mail,
    Calendar,
    BookOpen,
    DollarSign,
    UserCheck,
    UserX,
    RotateCcw,
    Receipt,
    FileText,
    Flame,
    Sparkles,
    ExternalLink,
    CheckCircle2,
    Clock,
    Plus,
} from 'lucide-vue-next';

const props = defineProps({
    student: {
        type: Object,
        required: true,
    },
    courses: {
        type: Array,
        default: () => [],
    },
});

const activeTab = ref('attendance'); // attendance, retention, invoices

// Modal states
const showTemplateModal = ref(false);
const showNoteModal = ref(false);
const showRenewModal = ref(false);
const showAbsenceModal = ref(false);

const absenceWithLeave = ref(true);
const absenceNote = ref('');
const isSubmittingAbsence = ref(false);

const submitAbsence = () => {
    isSubmittingAbsence.value = true;
    router.post(
        `/students/${props.student.id}/absence`,
        {
            with_leave: absenceWithLeave.value,
            note: absenceNote.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                isSubmittingAbsence.value = false;
                showAbsenceModal.value = false;
                absenceNote.value = '';
            },
            onError: () => {
                isSubmittingAbsence.value = false;
            },
        }
    );
};

const formatVND = (val) => {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const formatDate = (val) => {
    if (!val) return '—';
    const d = new Date(val);
    return d.toLocaleDateString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
};

const getZaloLink = (phone) => {
    if (!phone) return '#';
    return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};
</script>

<template>
    <AppLayout>
        <Head :title="`Hồ sơ học viên: ${student.name}`" />

        <div class="space-y-6">
            <!-- Back navigation & Actions Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        href="/students"
                        class="rounded-xl border border-slate-200 bg-white p-2 text-slate-600 hover:bg-slate-50 transition-colors"
                    >
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                                {{ student.name }}
                            </h1>
                            <Badge v-if="student.status === 'active'" variant="green">Đang học</Badge>
                            <Badge v-else-if="student.status === 'paused'" variant="orange">Tạm dừng</Badge>
                            <Badge v-else variant="gray">Đã nghỉ</Badge>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Mã: <span class="font-mono font-bold text-slate-700">{{ student.code }}</span> &bull; Ngày nhập học: {{ formatDate(student.join_date) }}
                        </p>
                    </div>
                </div>

                <!-- 1-Click Action Hub -->
                <div class="flex items-center gap-2 flex-wrap">
                    <button
                        @click="showAbsenceModal = true"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100 shadow-xs transition-colors"
                    >
                        <UserX class="h-3.5 w-3.5" />
                        <span>Báo vắng học</span>
                    </button>

                    <AttendanceButton :student="student" />

                    <button
                        @click="showRenewModal = true"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-md shadow-emerald-600/20 hover:bg-emerald-700 transition-all"
                    >
                        <Sparkles class="h-3.5 w-3.5" />
                        <span>Gia hạn gói mới</span>
                    </button>
                </div>
            </div>

            <!-- Profile Summary Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Card 1: Course & Progress -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Khóa học & Tiến độ</span>
                    <div class="mt-3 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 font-bold">
                            <BookOpen class="h-5 w-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">{{ student.course?.name }}</h3>
                            <p class="text-xs text-slate-500">{{ formatVND(student.course?.price) }} / {{ student.total_sessions }} buổi</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl bg-slate-50 p-3.5 border border-slate-100 space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-500">Số buổi còn lại:</span>
                            <span
                                class="font-extrabold text-sm"
                                :class="student.remaining_sessions <= 2 ? 'text-rose-600' : 'text-indigo-600'"
                            >
                                {{ student.remaining_sessions }} buổi
                            </span>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-2 overflow-hidden">
                            <div
                                class="h-full bg-indigo-600 rounded-full transition-all"
                                :style="{ width: `${Math.min(100, Math.round(((student.total_sessions - student.remaining_sessions) / student.total_sessions) * 100))}%` }"
                            ></div>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-400">
                            <span>Đã học: {{ student.total_sessions - student.remaining_sessions }} buổi</span>
                            <span>Tổng gói: {{ student.total_sessions }} buổi</span>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Parent Contacts -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Thông tin Phụ huynh</span>
                    <div class="mt-3 space-y-2.5 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Họ tên PH:</span>
                            <span class="font-bold text-slate-900">{{ student.parent_name }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Số điện thoại:</span>
                            <span class="font-bold text-slate-800">{{ student.parent_phone }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Email:</span>
                            <span class="text-slate-700">{{ student.parent_email || '—' }}</span>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        <a
                            :href="`tel:${student.parent_phone}`"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                        >
                            <Phone class="h-3.5 w-3.5 text-indigo-600" />
                            <span>Gọi điện</span>
                        </a>
                        <a
                            :href="getZaloLink(student.parent_phone)"
                            target="_blank"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 py-2 text-xs font-semibold text-white hover:bg-blue-700 shadow-xs"
                        >
                            <span>Mở Zalo</span>
                            <ExternalLink class="h-3.5 w-3.5" />
                        </a>
                    </div>
                </div>

                <!-- Card 3: Retention Status & Quick Care -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Tiến trình Giữ Chân</span>
                        <Flame v-if="student.remaining_sessions <= 5" class="h-4 w-4 text-rose-500 animate-pulse" />
                    </div>

                    <div class="mt-3">
                        <StateButtons
                            :student="student"
                            @open-template="showTemplateModal = true"
                            @open-note="showNoteModal = true"
                            @open-renew="showRenewModal = true"
                        />
                    </div>

                    <div class="mt-4 rounded-xl bg-slate-50 p-3 text-xs border border-slate-100">
                        <span class="text-slate-500 font-medium">Ghi chú chung:</span>
                        <p class="mt-1 text-slate-700 italic leading-relaxed">
                            {{ student.notes || 'Chưa có ghi chú đặc biệt cho học viên này.' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Tabs Section: Attendance History / Retention Logs / Invoices -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                <!-- Tab Buttons -->
                <div class="flex border-b border-slate-100 bg-slate-50/70 px-4">
                    <button
                        @click="activeTab = 'attendance'"
                        class="border-b-2 py-3.5 px-4 text-xs font-bold transition-all"
                        :class="activeTab === 'attendance' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    >
                        Lịch Sử Điểm Danh ({{ student.attendances?.length || 0 }})
                    </button>
                    <button
                        @click="activeTab = 'retention'"
                        class="border-b-2 py-3.5 px-4 text-xs font-bold transition-all"
                        :class="activeTab === 'retention' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    >
                        Nhật Ký Chăm Sóc Phụ Huynh ({{ student.retention_logs?.length || 0 }})
                    </button>
                    <button
                        @click="activeTab = 'invoices'"
                        class="border-b-2 py-3.5 px-4 text-xs font-bold transition-all"
                        :class="activeTab === 'invoices' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    >
                        Lịch Sử Đóng Học Phí ({{ student.invoices?.length || 0 }})
                    </button>
                </div>

                <!-- Tab 1: Attendance History -->
                <div v-if="activeTab === 'attendance'" class="p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50/80 text-slate-500 border-b border-slate-100">
                                <tr>
                                    <th class="py-3 px-4 font-semibold">Buổi số</th>
                                    <th class="py-3 px-4 font-semibold">Thời gian điểm danh</th>
                                    <th class="py-3 px-4 font-semibold text-center">Trạng thái</th>
                                    <th class="py-3 px-4 font-semibold">Giáo viên / Người ghi</th>
                                    <th class="py-3 px-4 font-semibold">Nhận xét bài học</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <tr v-for="att in student.attendances" :key="att.id" class="hover:bg-slate-50/60">
                                    <td class="py-3 px-4 font-bold text-slate-900">#{{ att.session_number }}</td>
                                    <td class="py-3 px-4 font-mono text-slate-600">{{ formatDate(att.attended_at) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <Badge v-if="att.status === 'present'" variant="green" size="sm">Có mặt</Badge>
                                        <Badge v-else-if="att.status === 'absent_with_leave'" variant="orange" size="sm">Nghỉ có phép</Badge>
                                        <Badge v-else variant="red" size="sm">Nghỉ không phép</Badge>
                                    </td>
                                    <td class="py-3 px-4 text-slate-600">{{ att.marked_by }}</td>
                                    <td class="py-3 px-4 text-slate-700 italic">{{ att.note || '—' }}</td>
                                </tr>
                                <tr v-if="!student.attendances || student.attendances.length === 0">
                                    <td colspan="5" class="py-8 text-center text-slate-400">Chưa có dữ liệu điểm danh nào.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab 2: Retention Logs -->
                <div v-if="activeTab === 'retention'" class="p-4 space-y-3">
                    <div class="flex justify-end">
                        <button
                            @click="showNoteModal = true"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                        >
                            <Plus class="h-3.5 w-3.5" />
                            <span>Thêm ghi chú tương tác</span>
                        </button>
                    </div>

                    <div class="space-y-2">
                        <div
                            v-for="log in student.retention_logs"
                            :key="log.id"
                            class="rounded-xl border border-slate-100 bg-slate-50 p-3.5 text-xs flex items-start justify-between gap-4"
                        >
                            <div>
                                <div class="flex items-center gap-2">
                                    <Badge variant="indigo" size="sm">{{ log.action_type }}</Badge>
                                    <span class="font-bold text-slate-800">{{ log.created_by }}</span>
                                </div>
                                <p class="mt-1.5 text-slate-700 leading-relaxed">{{ log.content }}</p>
                                <p v-if="log.next_followup_date" class="mt-1 text-[11px] text-amber-700 font-semibold">
                                    Hẹn gọi lại: {{ formatDate(log.next_followup_date) }}
                                </p>
                            </div>
                            <span class="font-mono text-slate-400 text-[11px] shrink-0">{{ formatDate(log.contact_date) }}</span>
                        </div>

                        <div v-if="!student.retention_logs || student.retention_logs.length === 0" class="py-8 text-center text-slate-400 text-xs">
                            Chưa có lịch sử trao đổi nào với phụ huynh.
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Invoices History -->
                <div v-if="activeTab === 'invoices'" class="p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50/80 text-slate-500 border-b border-slate-100">
                                <tr>
                                    <th class="py-3 px-4 font-semibold">Mã hóa đơn</th>
                                    <th class="py-3 px-4 font-semibold">Số tiền</th>
                                    <th class="py-3 px-4 font-semibold text-center">Số buổi cộng</th>
                                    <th class="py-3 px-4 font-semibold">Hình thức</th>
                                    <th class="py-3 px-4 font-semibold">Ngày thanh toán</th>
                                    <th class="py-3 px-4 font-semibold">Ghi chú</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <tr v-for="inv in student.invoices" :key="inv.id" class="hover:bg-slate-50/60">
                                    <td class="py-3 px-4 font-mono font-bold text-slate-900">{{ inv.invoice_code }}</td>
                                    <td class="py-3 px-4 font-bold text-emerald-700">{{ formatVND(inv.amount) }}</td>
                                    <td class="py-3 px-4 text-center font-bold text-indigo-600">+{{ inv.sessions_added }} buổi</td>
                                    <td class="py-3 px-4 text-slate-600">{{ inv.payment_method }}</td>
                                    <td class="py-3 px-4 font-mono text-slate-500">{{ formatDate(inv.paid_at) }}</td>
                                    <td class="py-3 px-4 text-slate-600">{{ inv.note || '—' }}</td>
                                </tr>
                                <tr v-if="!student.invoices || student.invoices.length === 0">
                                    <td colspan="6" class="py-8 text-center text-slate-400">Chưa có lịch sử thu học phí.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Absence Modal -->
        <Modal :show="showAbsenceModal" max-width="md" @close="showAbsenceModal = false">
            <div class="p-6">
                <h3 class="text-base font-bold text-slate-900">Báo Vắng Học Cho {{ student.name }}</h3>
                <form @submit.prevent="submitAbsence" class="mt-4 space-y-4">
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-xs font-medium text-slate-700">
                            <input type="radio" :value="true" v-model="absenceWithLeave" class="text-indigo-600" />
                            <span>Nghỉ có phép (Phụ huynh có báo trước)</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs font-medium text-slate-700">
                            <input type="radio" :value="false" v-model="absenceWithLeave" class="text-indigo-600" />
                            <span>Nghỉ không phép (Không báo trước)</span>
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Lý do nghỉ</label>
                        <textarea v-model="absenceNote" rows="3" placeholder="Ví dụ: Bé bị ốm sốt / Gia đình bận việc..." class="mt-1 block w-full rounded-lg border border-slate-200 p-2 text-xs focus:ring-1 focus:ring-indigo-500"></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" @click="showAbsenceModal = false" class="rounded-lg border px-4 py-2 text-xs font-medium">Hủy</button>
                        <button type="submit" :disabled="isSubmittingAbsence" class="rounded-lg bg-rose-600 px-4 py-2 text-xs font-bold text-white hover:bg-rose-700 disabled:opacity-50">Lưu Báo Vắng</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Other Modals -->
        <MessageTemplateModal :show="showTemplateModal" :student="student" :templates="$page.props.templates || []" @close="showTemplateModal = false" />
        <RetentionNoteModal :show="showNoteModal" :student="student" @close="showNoteModal = false" />
        <RenewModal :show="showRenewModal" :student="student" @close="showRenewModal = false" />
    </AppLayout>
</template>
