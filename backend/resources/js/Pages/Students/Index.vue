<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import AttendanceButton from '@/Components/AttendanceButton.vue';
import {
    Users,
    UserPlus,
    Search,
    Filter,
    Flame,
    AlertTriangle,
    Eye,
    Edit3,
    Trash2,
    Calendar,
    Phone,
    ExternalLink,
    CheckCircle2,
    X,
} from 'lucide-vue-next';

const props = defineProps({
    students: {
        type: Array,
        default: () => [],
    },
    courses: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({ search: '', course_id: 'all', status: 'all', alert: null }),
    },
});

const search = ref(props.filters.search || '');
const courseId = ref(props.filters.course_id || 'all');
const status = ref(props.filters.status || 'all');
const alertFilter = ref(props.filters.alert || '');
const attendanceStatus = ref(props.filters.attendance_status || 'all');

// Create / Edit student modal
const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingStudent = ref(null);
const isSubmitting = ref(false);

const newStudentForm = ref({
    name: '',
    dob: '',
    gender: 'Nam',
    parent_name: '',
    parent_phone: '',
    parent_zalo: '',
    parent_email: '',
    course_id: props.courses[0]?.id || '',
    total_sessions: 12,
    status: 'active',
    join_date: new Date().toISOString().split('T')[0],
    notes: '',
    create_invoice: true,
    invoice_amount: 3600000,
});

const applyFilters = () => {
    router.get(
        '/students',
        {
            search: search.value,
            course_id: courseId.value,
            status: status.value,
            alert: alertFilter.value,
            attendance_status: attendanceStatus.value,
        },
        { preserveState: true, preserveScroll: true }
    );
};

// Auto update invoice amount when course changes in create modal
watch(
    () => newStudentForm.value.course_id,
    (cId) => {
        const c = props.courses.find(item => item.id == cId);
        if (c) {
            newStudentForm.value.total_sessions = c.default_sessions;
            newStudentForm.value.invoice_amount = Number(c.price);
        }
    }
);

const submitCreateStudent = () => {
    isSubmitting.value = true;
    router.post('/students', newStudentForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            isSubmitting.value = false;
            showCreateModal.value = false;
            newStudentForm.value = {
                name: '',
                dob: '',
                gender: 'Nam',
                parent_name: '',
                parent_phone: '',
                parent_zalo: '',
                parent_email: '',
                course_id: props.courses[0]?.id || '',
                total_sessions: 12,
                status: 'active',
                join_date: new Date().toISOString().split('T')[0],
                notes: '',
                create_invoice: true,
                invoice_amount: 3600000,
            };
        },
        onError: () => {
            isSubmitting.value = false;
        },
    });
};

const openEditStudent = (student) => {
    editingStudent.value = { ...student };
    showEditModal.value = true;
};

const submitEditStudent = () => {
    if (!editingStudent.value) return;
    isSubmitting.value = true;
    router.put(`/students/${editingStudent.value.id}`, editingStudent.value, {
        preserveScroll: true,
        onSuccess: () => {
            isSubmitting.value = false;
            showEditModal.value = false;
        },
        onError: () => {
            isSubmitting.value = false;
        },
    });
};

const deleteStudent = (student) => {
    if (confirm(`Bạn có chắc chắn muốn xóa học viên ${student.name}?`)) {
        router.delete(`/students/${student.id}`);
    }
};

const getZaloLink = (phone) => {
    if (!phone) return '#';
    return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};
</script>

<template>
    <AppLayout>
        <Head title="Quản Lý Học Viên & Điểm Danh" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                        Quản Lý Học Viên & Điểm Danh
                    </h1>
                    <p class="text-xs text-slate-500 mt-1">
                        Danh sách học viên toàn trung tâm. Nhấp nút "Điểm danh" 1-click để tự động trừ 1 buổi và ghi log.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        @click="showCreateModal = true"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-md shadow-indigo-600/20 hover:bg-indigo-700 transition-all"
                    >
                        <UserPlus class="h-4 w-4" />
                        <span>Thêm Học Viên Mới</span>
                    </button>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                <!-- Search -->
                <div class="relative">
                    <Search class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                    <input
                        type="text"
                        v-model="search"
                        @keyup.enter="applyFilters"
                        placeholder="Tìm theo tên, mã, SĐT phụ huynh..."
                        class="w-full rounded-xl border border-slate-200 pl-9 pr-3 py-2 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                </div>

                <!-- Course Select -->
                <div>
                    <select
                        v-model="courseId"
                        @change="applyFilters"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    >
                        <option value="all">Tất cả khóa học</option>
                        <option v-for="c in courses" :key="c.id" :value="c.id">
                            {{ c.name }}
                        </option>
                    </select>
                </div>

                <!-- Status Select -->
                <div>
                    <select
                        v-model="status"
                        @change="applyFilters"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    >
                        <option value="all">Tất cả trạng thái</option>
                        <option value="active">Đang học</option>
                        <option value="paused">Tạm dừng</option>
                        <option value="dropped">Đã nghỉ</option>
                    </select>
                </div>

                <!-- Attendance Status (SenyApp Streak) -->
                <div>
                    <select
                        v-model="attendanceStatus"
                        @change="applyFilters"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                    >
                        <option value="all">Tất cả chuyên cần</option>
                        <option value="excellent">🔥 Chuyên cần xuất sắc</option>
                        <option value="good">😊 Chuyên cần tốt</option>
                        <option value="attention">⚠️ Cần quan tâm</option>
                        <option value="at_risk">🚨 Nguy cơ nghỉ học</option>
                        <option value="returned">💪 Đã quay lại học đều</option>
                    </select>
                </div>

                <!-- Alert Fast Filter -->
                <div class="flex items-center gap-1.5">
                    <button
                        @click="alertFilter = alertFilter === 'expiring' ? '' : 'expiring'; applyFilters()"
                        class="flex-1 rounded-xl px-2.5 py-2 text-xs font-semibold border transition-all flex items-center justify-center gap-1"
                        :class="alertFilter === 'expiring' ? 'bg-rose-50 border-rose-300 text-rose-700 ring-1 ring-rose-400' : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'"
                    >
                        <Flame class="h-3.5 w-3.5 text-rose-500" />
                        <span>Sắp hết buổi</span>
                    </button>
                    <button
                        @click="alertFilter = alertFilter === 'absence' ? '' : 'absence'; applyFilters()"
                        class="flex-1 rounded-xl px-2.5 py-2 text-xs font-semibold border transition-all flex items-center justify-center gap-1"
                        :class="alertFilter === 'absence' ? 'bg-amber-50 border-amber-300 text-amber-800 ring-1 ring-amber-400' : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'"
                    >
                        <AlertTriangle class="h-3.5 w-3.5 text-amber-600" />
                        <span>Vắng học</span>
                    </button>
                </div>
            </div>

            <!-- Students List Table -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 text-slate-500 border-b border-slate-100">
                            <tr>
                                <th class="py-3.5 px-4 font-semibold">Mã & Học viên</th>
                                <th class="py-3.5 px-4 font-semibold">Phụ huynh & Liên hệ</th>
                                <th class="py-3.5 px-4 font-semibold">Khóa học</th>
                                <th class="py-3.5 px-4 font-semibold text-center">Số buổi (Tổng / Còn)</th>
                                <th class="py-3.5 px-4 font-semibold text-center">Trạng thái</th>
                                <th class="py-3.5 px-4 font-semibold text-right">Điểm danh (1-Click)</th>
                                <th class="py-3.5 px-4 font-semibold text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr
                                v-for="student in students"
                                :key="student.id"
                                class="hover:bg-slate-50/80 transition-colors"
                            >
                                <!-- Code & Student Name -->
                                <td class="py-3.5 px-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 font-extrabold text-indigo-700 text-xs shrink-0">
                                            {{ student.name.charAt(0) }}
                                        </div>
                                        <div>
                                            <Link :href="`/students/${student.id}`" class="font-bold text-slate-900 hover:text-indigo-600">
                                                {{ student.name }}
                                            </Link>
                                            <span class="block text-[10px] font-mono text-slate-400">{{ student.code }}</span>

                                            <!-- Dãy 5 chấm điểm danh gần nhất & Biểu tượng Chuyên cần (Kích thước lớn rõ ràng theo chuẩn) -->
                                            <div class="flex items-center gap-2 mt-1">
                                                <div class="flex items-center gap-1.5 bg-slate-100/90 px-2.5 py-1 rounded-full border border-slate-200/80 shadow-2xs">
                                                    <span
                                                        v-for="(dot, idx) in (student.attendance_dots || [])"
                                                        :key="idx"
                                                        class="inline-block h-3.5 w-3.5 rounded-full transition-all hover:scale-125 cursor-help"
                                                        :class="[
                                                            dot.status === 'present' ? 'bg-emerald-500 shadow-2xs shadow-emerald-500/50' :
                                                            dot.status === 'absent' ? 'bg-rose-500 shadow-2xs shadow-rose-500/50' :
                                                            dot.status === 'excused' ? 'bg-amber-400 shadow-2xs shadow-amber-400/50' :
                                                            'bg-white border-2 border-slate-300'
                                                        ]"
                                                    ></span>
                                                </div>
                                                <span v-if="student.attendance_status === 'excellent'" class="text-lg select-none cursor-help inline-block leading-none" title="Chuyên cần xuất sắc">🔥</span>
                                                <span v-else-if="student.attendance_status === 'good'" class="text-base select-none cursor-help inline-block leading-none" title="Chuyên cần tốt">😊</span>
                                                <span v-else-if="student.attendance_status === 'attention'" class="text-base select-none cursor-help inline-block leading-none" title="Cần quan tâm">⚠️</span>
                                                <span v-else-if="student.attendance_status === 'at_risk'" class="text-base select-none cursor-help inline-block leading-none" title="Nguy cơ nghỉ học">🚨</span>
                                                <span v-else-if="student.attendance_status === 'returned'" class="text-base select-none cursor-help inline-block leading-none" title="Đã quay lại học đều">💪</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Parent info -->
                                <td class="py-3.5 px-4">
                                    <div class="font-medium text-slate-800">{{ student.parent_name }}</div>
                                    <div class="flex items-center gap-1 text-[11px] text-slate-500 mt-0.5">
                                        <Phone class="h-3 w-3 text-slate-400" />
                                        <a :href="getZaloLink(student.parent_phone)" target="_blank" class="text-blue-600 hover:underline">
                                            {{ student.parent_phone }}
                                        </a>
                                    </div>
                                </td>

                                <!-- Course -->
                                <td class="py-3.5 px-4">
                                    <span class="inline-flex items-center gap-1.5 font-semibold text-slate-800">
                                        <span class="h-2 w-2 rounded-full shrink-0" :style="{ backgroundColor: student.course?.color || '#3b82f6' }"></span>
                                        <span>{{ student.course?.name }}</span>
                                    </span>
                                </td>

                                <!-- Session Progress -->
                                <td class="py-3.5 px-4 text-center">
                                    <div class="inline-flex flex-col items-center">
                                        <span
                                            class="rounded-md px-2.5 py-0.5 text-xs font-extrabold shadow-xs"
                                            :class="[
                                                student.remaining_sessions <= 2
                                                    ? 'bg-rose-100 text-rose-700 ring-1 ring-rose-400'
                                                    : student.remaining_sessions <= 5
                                                        ? 'bg-amber-100 text-amber-800 ring-1 ring-amber-400'
                                                        : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-300'
                                            ]"
                                        >
                                            Còn {{ student.remaining_sessions }} / {{ student.total_sessions }} buổi
                                        </span>

                                        <div class="w-20 bg-slate-100 rounded-full h-1 mt-1.5 overflow-hidden">
                                            <div
                                                class="h-full bg-indigo-500 rounded-full"
                                                :style="{ width: `${Math.min(100, Math.round(((student.total_sessions - student.remaining_sessions) / student.total_sessions) * 100))}%` }"
                                            ></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td class="py-3.5 px-4 text-center">
                                    <Badge v-if="student.status === 'active'" variant="green" size="sm">Đang học</Badge>
                                    <Badge v-else-if="student.status === 'paused'" variant="orange" size="sm">Tạm dừng</Badge>
                                    <Badge v-else variant="gray" size="sm">Đã nghỉ</Badge>

                                    <div v-if="student.consecutive_absences >= 2" class="mt-1">
                                        <Badge variant="red" size="sm">Vắng {{ student.consecutive_absences }} buổi</Badge>
                                    </div>
                                </td>

                                <!-- 1-Click Attendance Button -->
                                <td class="py-3.5 px-4 text-right">
                                    <AttendanceButton :student="student" />
                                </td>

                                <!-- Action Buttons -->
                                <td class="py-3.5 px-4 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <Link
                                            :href="`/students/${student.id}`"
                                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-indigo-600 transition-colors"
                                            title="Xem hồ sơ & Lịch sử điểm danh"
                                        >
                                            <Eye class="h-4 w-4" />
                                        </Link>
                                        <button
                                            @click="openEditStudent(student)"
                                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-amber-600 transition-colors"
                                            title="Sửa thông tin"
                                        >
                                            <Edit3 class="h-4 w-4" />
                                        </button>
                                        <button
                                            @click="deleteStudent(student)"
                                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-rose-600 transition-colors"
                                            title="Xóa học viên"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="students.length === 0">
                                <td colspan="7" class="py-12 text-center text-xs text-slate-400">
                                    Không tìm thấy học viên nào phù hợp.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create Student Modal -->
        <Modal :show="showCreateModal" max-width="2xl" @close="showCreateModal = false">
            <div class="p-6">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <h3 class="text-base font-bold text-slate-900">Thêm Học Viên Mới</h3>
                    <button @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <form @submit.prevent="submitCreateStudent" class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Tên học viên <span class="text-rose-500">*</span></label>
                            <input type="text" v-model="newStudentForm.name" required placeholder="Ví dụ: Nguyễn Minh Khang" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:ring-1 focus:ring-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Giới tính</label>
                            <select v-model="newStudentForm.gender" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:ring-1 focus:ring-indigo-500">
                                <option value="Nam">Nam</option>
                                <option value="Nữ">Nữ</option>
                                <option value="Khác">Khác</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Tên phụ huynh <span class="text-rose-500">*</span></label>
                            <input type="text" v-model="newStudentForm.parent_name" required placeholder="Ví dụ: Chị Phương Thảo" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:ring-1 focus:ring-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Số điện thoại phụ huynh <span class="text-rose-500">*</span></label>
                            <input type="text" v-model="newStudentForm.parent_phone" required placeholder="0912345678" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:ring-1 focus:ring-indigo-500" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Khóa học đăng ký <span class="text-rose-500">*</span></label>
                            <select v-model="newStudentForm.course_id" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:ring-1 focus:ring-indigo-500">
                                <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Tổng số buổi đăng ký</label>
                            <input type="number" min="1" v-model.number="newStudentForm.total_sessions" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold focus:ring-1 focus:ring-indigo-500" />
                        </div>
                    </div>

                    <div class="rounded-xl bg-slate-50 p-3.5 border border-slate-200/80 space-y-3">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-800">
                            <input type="checkbox" v-model="newStudentForm.create_invoice" class="rounded text-indigo-600 focus:ring-indigo-500" />
                            <span>Tạo hóa đơn thu tiền ngay (Cộng vào doanh thu)</span>
                        </label>
                        <div v-if="newStudentForm.create_invoice" class="grid grid-cols-2 gap-3 pt-1">
                            <div>
                                <label class="block text-[11px] font-medium text-slate-600">Số tiền thu (VNĐ)</label>
                                <input type="number" v-model.number="newStudentForm.invoice_amount" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-emerald-700" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-slate-600">Hình thức</label>
                                <select class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-1.5 text-xs">
                                    <option value="bank_transfer">Chuyển khoản</option>
                                    <option value="cash">Tiền mặt</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" @click="showCreateModal = false" class="rounded-lg border px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">Hủy</button>
                        <button type="submit" :disabled="isSubmitting" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700 disabled:opacity-50">Lưu Học Viên</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Edit Student Modal -->
        <Modal :show="showEditModal" max-width="2xl" @close="showEditModal = false">
            <div v-if="editingStudent" class="p-6">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <h3 class="text-base font-bold text-slate-900">Sửa Thông Tin: {{ editingStudent.name }}</h3>
                    <button @click="showEditModal = false" class="text-slate-400 hover:text-slate-600">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <form @submit.prevent="submitEditStudent" class="mt-4 space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Tên học viên</label>
                            <input type="text" v-model="editingStudent.name" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Phụ huynh</label>
                            <input type="text" v-model="editingStudent.parent_name" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">SĐT</label>
                            <input type="text" v-model="editingStudent.parent_phone" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Khóa học</label>
                            <select v-model="editingStudent.course_id" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                                <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Tổng số buổi</label>
                            <input type="number" min="1" v-model.number="editingStudent.total_sessions" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Số buổi còn lại</label>
                            <input type="number" min="0" v-model.number="editingStudent.remaining_sessions" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-rose-600" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Trạng thái</label>
                            <select v-model="editingStudent.status" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                                <option value="active">Đang học</option>
                                <option value="paused">Tạm dừng</option>
                                <option value="dropped">Đã nghỉ</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Trạng thái Retention</label>
                        <select v-model="editingStudent.retention_status" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                            <option value="normal">Bình thường</option>
                            <option value="invoice_sent">Đã gửi hóa đơn (Xanh dương)</option>
                            <option value="following_up">Đang chăm sóc (Cam)</option>
                            <option value="renewed">Đã đóng (Xanh lá)</option>
                        </select>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" @click="showEditModal = false" class="rounded-lg border px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">Hủy</button>
                        <button type="submit" :disabled="isSubmitting" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700 disabled:opacity-50">Cập Nhật</button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>
