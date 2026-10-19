<script setup>
import { useBrand } from '../services/useBrand';
import { notify } from '../services/notify';
import { ref, computed, onMounted, watch } from 'vue';
import { dashboardApi, retentionApi, centerApi, studentApi } from '../services/api';
import { useAuth } from '../services/useAuth';
import { isPathBlocked } from '../services/permissions';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import RetentionNoteModal from '../components/RetentionNoteModal.vue';
import RenewModal from '../components/RenewModal.vue';
import RetentionLogsModal from '../components/RetentionLogsModal.vue';
import AttendanceButton from '../components/AttendanceButton.vue';
import {
  Users,
  PauseCircle,
  Ban,
  AlertTriangle,
  Receipt,
  DollarSign,
  BookOpen,
  FileX,
  CheckCircle2,
  Sparkles,
  FileText,
  FileDown,
  PhoneCall,
  Check,
  MessageSquare,
  ChevronDown,
  Building2,
  Clock,
  ArrowUpRight,
} from 'lucide-vue-next';

const { brandName } = useBrand();

const stats = ref({});
const { user } = useAuth();
const expiringStudents = ref([]);
const absenceAlerts = ref([]);
const expiringEndDateStudents = ref([]);
const recentAttendances = ref([]);
const courses = ref([]);
const templates = ref([]);
const centers = ref([]);
const loading = ref(true);

const selectedCenter = ref('all');
const showTemplateModal = ref(false);
const showNoteModal = ref(false);
const showRenewModal = ref(false);
const showLogsModal = ref(false);
const selectedStudent = ref(null);
const expiringFilter = ref('0-2'); // '0-2' or 'all'

const fetchCenters = async () => {
  try {
    const res = await centerApi.getCenters();
    centers.value = res.data?.centers || [];
  } catch (err) {
    console.error('Error fetching centers: ', err);
  }
};

const fetchData = async () => {
  try {
    loading.value = true;
    const params = {};
    if (selectedCenter.value && selectedCenter.value !== 'all') {
      params.center = selectedCenter.value;
    }
    const res = await dashboardApi.getStats(params);
    stats.value = res.data.stats || {};
    expiringStudents.value = res.data.expiringStudents || [];
    absenceAlerts.value = res.data.absenceAlerts || [];
    expiringEndDateStudents.value = res.data.expiringEndDateStudents || [];
    recentAttendances.value = res.data.recentAttendances || [];
    courses.value = res.data.courses || [];
    templates.value = res.data.templates || [];
  } catch (err) {
    console.error('Error fetching dashboard data: ', err);
  } finally {
    loading.value = false;
  }
};

watch(selectedCenter, () => {
  fetchData();
});

onMounted(async () => {
  await Promise.all([fetchCenters(), fetchData()]);
});

const displayedExpiringStudents = computed(() => {
  if (expiringFilter.value === '0-2') {
    return expiringStudents.value.filter((s) => (s.remaining_sessions ?? 0) <= 2);
  }
  return expiringStudents.value;
});

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

const openInvoicePDF = async (student) => {
  // Mở tab ngay trong sự kiện click để không bị trình duyệt chặn popup
  const pdfTab = window.open('', '_blank');
  try {
    const res = await studentApi.tuitionInvoicePdf(student.id);
    const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
    if (pdfTab) {
      pdfTab.location.href = url;
    } else {
      window.open(url, '_blank');
    }
  } catch (err) {
    pdfTab?.close();
    console.error('Error generating invoice PDF: ', err);
    notify.error('Không thể tạo hóa đơn PDF. Vui lòng thử lại.');
  }
};

const openLogsModal = (student) => {
  selectedStudent.value = student;
  showLogsModal.value = true;
};

const isStatusActive = (student, statusKey) => {
  if (!student?.retention_status || student.retention_status === 'normal') return false;
  return student.retention_status.split(',').map((s) => s.trim()).includes(statusKey);
};

const toggleRetentionStatus = async (student, targetStatus) => {
  const currentList = student.retention_status && student.retention_status !== 'normal'
    ? student.retention_status.split(',').map((s) => s.trim()).filter(Boolean)
    : [];

  let nextList;
  if (currentList.includes(targetStatus)) {
    // Tắt trạng thái này đi (độc lập, không ảnh hưởng các trạng thái khác)
    nextList = currentList.filter((s) => s !== targetStatus);
  } else {
    // Bật thêm trạng thái này lên, đồng thời GIỮ NGUYÊN các trạng thái khác đã bật
    nextList = [...currentList, targetStatus];
  }

  const newStatusString = nextList.length > 0 ? nextList.join(',') : 'normal';
  const oldStatus = student.retention_status;
  student.retention_status = newStatusString;

  try {
    const res = await retentionApi.updateStatus(student.id, {
      status: newStatusString,
      is_toggle: true,
    });
    if (res.data?.student) {
      student.retention_status = res.data.student.retention_status;
      if (res.data.student.latest_retention_log) {
        student.latest_retention_log = res.data.student.latest_retention_log;
      }
    }
  } catch (err) {
    student.retention_status = oldStatus;
    console.error('Error updating retention status: ', err);
  }
};

const onStudentRenewed = (updatedStudent) => {
  if (updatedStudent) {
    const idx = expiringStudents.value.findIndex((s) => s.id === updatedStudent.id);
    if (idx !== -1) {
      expiringStudents.value.splice(idx, 1);
    }
  }
  fetchData();
};

const onStudentLogSaved = (updatedStudent) => {
  if (updatedStudent) {
    const idx = expiringStudents.value.findIndex((s) => s.id === updatedStudent.id);
    if (idx !== -1) {
      expiringStudents.value[idx] = updatedStudent;
    }
  }
  fetchData();
};

const formatDateShort = (val) => {
  if (!val) return '';
  const d = new Date(val);
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
};

const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const formatDate = (val) => {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleDateString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
};


const todoTasks = computed(() => [
  {
    id: 'debt',
    count: stats.value.todoDebtInvoices ?? 0,
    textBefore: 'Có',
    textAfter: 'hóa đơn còn công nợ chưa thu',
    actionText: 'Nhắc PH ngay',
    to: '/invoices',
    // Nhóm 1: Đỏ hồng - Khẩn cấp / Thu phí
    cardBg: 'bg-rose-50/75 hover:bg-rose-100/60',
    cardBorder: 'border-rose-200/90',
    dotColor: 'bg-rose-500',
    countColor: 'text-rose-600',
    actionColor: 'text-rose-600 hover:text-rose-700',
  },
  {
    id: 'tuition_due',
    count: stats.value.todoTuitionDue ?? 0,
    textBefore: 'Có',
    textAfter: 'học viên hôm nay đến hạn đóng học phí',
    actionText: 'Nhắc nhở ngay',
    to: '/students',
    // Nhóm 2: Cam hổ phách - Vận hành trong ngày
    cardBg: 'bg-amber-50/75 hover:bg-amber-100/60',
    cardBorder: 'border-amber-200/90',
    dotColor: 'bg-amber-500',
    countColor: 'text-amber-700',
    actionColor: 'text-amber-700 hover:text-amber-800',
  },
  {
    id: 'unattended',
    count: stats.value.todoNotAttendedToday ?? 0,
    textBefore: 'Có',
    textAfter: 'học viên chưa điểm danh hôm nay',
    actionText: 'Kiểm tra ngay',
    to: '/attendance',
    // Nhóm 2: Cam hổ phách - Vận hành trong ngày
    cardBg: 'bg-amber-50/75 hover:bg-amber-100/60',
    cardBorder: 'border-amber-200/90',
    dotColor: 'bg-amber-500',
    countColor: 'text-amber-700',
    actionColor: 'text-amber-700 hover:text-amber-800',
  },
  {
    id: 'consecutive_absent',
    count: stats.value.todoConsecutiveAbsences ?? 0,
    textBefore: 'Có',
    textAfter: 'học viên vắng 2 buổi liên tiếp',
    actionText: 'Liên hệ PH ngay',
    to: '/absences',
    // Nhóm 1: Đỏ hồng - Khẩn cấp / Nghỉ học
    cardBg: 'bg-rose-50/75 hover:bg-rose-100/60',
    cardBorder: 'border-rose-200/90',
    dotColor: 'bg-rose-500',
    countColor: 'text-rose-600',
    actionColor: 'text-rose-600 hover:text-rose-700',
  },
  {
    id: 'birthday',
    count: stats.value.todoBirthdaysThisMonth ?? 0,
    textBefore: 'Có',
    textAfter: 'học viên sinh nhật tháng này chưa được tặng quà',
    actionText: 'Kiểm tra ngay',
    to: '/birthdays',
    // Nhóm 3: Xanh chàm Indigo - Chăm sóc & Gắn kết
    cardBg: 'bg-indigo-50/75 hover:bg-indigo-100/60',
    cardBorder: 'border-indigo-200/90',
    dotColor: 'bg-indigo-500',
    countColor: 'text-indigo-700',
    actionColor: 'text-indigo-700 hover:text-indigo-800',
  },
  {
    id: 'expiring_low',
    count: stats.value.todoExpiringFewSessions ?? 0,
    textBefore: 'Có',
    textAfter: 'học viên chỉ còn 0–2 buổi',
    actionText: 'Liên hệ PH ngay',
    to: '/students',
    // Nhóm 1: Đỏ hồng - Khẩn cấp / Sắp hết buổi
    cardBg: 'bg-rose-50/75 hover:bg-rose-100/60',
    cardBorder: 'border-rose-200/90',
    dotColor: 'bg-rose-500',
    countColor: 'text-rose-600',
    actionColor: 'text-rose-600 hover:text-rose-700',
  },
  {
    id: 'paused_return',
    count: stats.value.todoPausedReturning ?? 0,
    textBefore: 'Có',
    textAfter: 'học viên tạm dừng sắp đi học lại',
    actionText: 'Kiểm tra ngay',
    to: '/inactive-students',
    // Nhóm 3: Xanh chàm Indigo - Chăm sóc & Tái nhập học
    cardBg: 'bg-indigo-50/75 hover:bg-indigo-100/60',
    cardBorder: 'border-indigo-200/90',
    dotColor: 'bg-indigo-500',
    countColor: 'text-indigo-700',
    actionColor: 'text-indigo-700 hover:text-indigo-800',
  },
]);

// Chỉ hiện những việc thực sự cần xử lý (count > 0)
const visibleTodoTasks = computed(() =>
  todoTasks.value.filter((task) => task.count > 0 && !isPathBlocked(user.value, task.to)),
);
</script>

<template>
  <div class="space-y-6 w-full">
    <!-- Header & Filter Bar: Modern Indigo Theme -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200/90 shadow-2xs">
      <div>
        <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2.5">
          <span class="inline-block w-2.5 h-6 bg-gradient-to-b from-indigo-600 to-violet-600 rounded-full"></span>
          Tổng quan Vận hành & Học viên
        </h1>
        <p class="text-sm text-slate-500 mt-1">
          Báo cáo học viên, chất lượng đào tạo và dòng tiền theo thời gian thực tại {{ brandName }}.
        </p>
      </div>

      <div class="flex items-center gap-3 shrink-0">
        <label class="text-sm font-bold text-slate-700 shrink-0 flex items-center gap-1.5">
          <Building2 class="h-4 w-4 text-indigo-600" />
          <span>Cơ sở:</span>
        </label>
        <div class="relative w-full sm:w-64">
          <select
            v-model="selectedCenter"
            class="w-full appearance-none rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-2.5 pr-9 text-sm font-medium text-slate-800 hover:border-indigo-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c.id" :value="c.name">
              {{ c.name }}
            </option>
          </select>
          <ChevronDown class="pointer-events-none absolute right-3.5 top-3.5 h-4.5 w-4.5 text-slate-400" />
        </div>
      </div>
    </div>

    <!-- SKELETON LOADING STATE (Hiệu ứng tải dữ liệu mượt mà, chống chớp giật khi vào trang) -->
    <div v-if="loading" class="space-y-6 animate-pulse">
      <!-- 4 KPI Cards Skeleton -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div v-for="i in 4" :key="'kpi-skel-' + i" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs space-y-3">
          <div class="flex items-center justify-between">
            <div class="h-4 w-28 bg-slate-200 rounded-md"></div>
            <div class="h-10 w-10 bg-slate-100 rounded-xl"></div>
          </div>
          <div class="h-8 w-24 bg-slate-200 rounded-lg"></div>
          <div class="pt-3 border-t border-slate-100 h-4 w-36 bg-slate-100 rounded"></div>
        </div>
      </div>

      <!-- Việc cần xử lý hôm nay Skeleton -->
      <div class="rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6 shadow-2xs space-y-4">
        <div class="h-5 w-48 bg-slate-200 rounded-md"></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div v-for="i in 7" :key="'todo-skel-' + i" class="rounded-xl border border-slate-100 bg-slate-50/70 p-4.5 space-y-3 min-h-[116px]">
            <div class="flex items-center gap-2">
              <div class="h-2.5 w-2.5 rounded-full bg-slate-300"></div>
              <div class="h-4 w-44 bg-slate-200 rounded"></div>
            </div>
            <div class="h-4 w-24 bg-slate-200 rounded mt-4"></div>
          </div>
        </div>
      </div>

      <!-- Alert Rows Skeleton -->
      <div class="rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6 shadow-2xs space-y-4">
        <div class="h-5 w-56 bg-slate-200 rounded-md"></div>
        <div class="space-y-3 pt-2">
          <div v-for="i in 4" :key="'alert-skel-' + i" class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 py-3 border-b border-slate-100 last:border-0">
            <div class="space-y-1.5">
              <div class="h-5 w-48 bg-slate-200 rounded"></div>
              <div class="h-4 w-36 bg-slate-100 rounded"></div>
            </div>
            <div class="flex gap-2">
              <div class="h-8 w-16 bg-slate-200 rounded-lg"></div>
              <div class="h-8 w-16 bg-slate-200 rounded-lg"></div>
              <div class="h-8 w-16 bg-slate-200 rounded-lg"></div>
              <div class="h-8 w-24 bg-slate-200 rounded-lg"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MAIN DASHBOARD CONTENT (Hiển thị mượt mà sau khi đã có dữ liệu) -->
    <div v-else class="space-y-6 transition-opacity duration-300">
      <!-- KHU VỰC 1: DẢI CHỈ SỐ KPI TỔNG QUAN -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1. HỌC VIÊN -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs hover:shadow-xs transition-all flex flex-col justify-between">
          <div class="flex items-start justify-between">
            <div>
              <span class="text-xs font-bold uppercase tracking-wider text-slate-400">
                Quy mô học viên
              </span>
              <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-black text-slate-900">
                  {{ stats.activeStudents || 0 }}
                </span>
                <span class="text-sm font-semibold text-emerald-600">Đang học</span>
              </div>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
              <Users class="h-6 w-6 stroke-[1.75]" />
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-sm text-slate-600">
            <span>Tạm dừng: <strong class="text-slate-800">{{ stats.pausedStudents || 0 }}</strong></span>
            <span class="text-slate-300">&bull;</span>
            <span>Đã nghỉ: <strong class="text-slate-800">{{ stats.leftStudents || 0 }}</strong></span>
          </div>
        </div>

        <!-- 2. CHẤT LƯỢNG ĐI HỌC -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs hover:shadow-xs transition-all flex flex-col justify-between">
          <div class="flex items-start justify-between">
            <div>
              <span class="text-xs font-bold uppercase tracking-wider text-slate-400">
                Tỷ lệ đi học (Tháng)
              </span>
              <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-black text-emerald-600">
                  {{ stats.attendanceRate ?? 100 }}%
                </span>
                <span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md">Ổn định</span>
              </div>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
              <CheckCircle2 class="h-6 w-6 stroke-[1.75]" />
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-sm text-slate-600">
            <span>Có mặt: <strong class="text-emerald-700">{{ stats.monthPresentCount || 0 }}</strong> buổi</span>
            <span class="text-slate-300">&bull;</span>
            <span>Vắng: <strong class="text-rose-600">{{ stats.monthAbsentCount || 0 }}</strong></span>
          </div>
        </div>

        <!-- 3. DOANH THU THỰC THU -->
        <div v-if="stats.monthRevenue !== null" class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs hover:shadow-xs transition-all flex flex-col justify-between">
          <div class="flex items-start justify-between">
            <div>
              <span class="text-xs font-bold uppercase tracking-wider text-slate-400">
                Doanh thu tháng này
              </span>
              <div class="mt-1">
                <span class="text-2xl sm:text-3xl font-black text-indigo-900 block truncate">
                  {{ formatVND(stats.monthRevenue) }}
                </span>
              </div>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
              <DollarSign class="h-6 w-6 stroke-[1.75]" />
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-sm text-slate-600">
            <span>Năm nay: <strong class="text-indigo-950 font-bold">{{ formatVND(stats.yearRevenue || stats.monthRevenue) }}</strong></span>
          </div>
        </div>

        <!-- 4. HÓA ĐƠN & CÔNG NỢ -->
        <div v-if="stats.debtTotalAmount !== null" class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs hover:shadow-xs transition-all flex flex-col justify-between">
          <div class="flex items-start justify-between">
            <div>
              <span class="text-xs font-bold uppercase tracking-wider text-slate-400">
                Công nợ cần thu
              </span>
              <div class="mt-1">
                <span class="text-2xl sm:text-3xl font-black text-rose-600 block truncate">
                  {{ formatVND(stats.debtTotalAmount) }}
                </span>
              </div>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-50 text-rose-600">
              <Receipt class="h-6 w-6 stroke-[1.75]" />
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-sm text-slate-600">
            <span>Chưa thanh toán: <strong class="text-rose-600 font-bold">{{ stats.debtInvoicesCount || 0 }}</strong> phiếu</span>
            <router-link to="/invoices" class="text-indigo-600 hover:text-indigo-800 font-semibold hover:underline">
              Chi tiết &rarr;
            </router-link>
          </div>
        </div>
      </div>

      <!-- KHỐI VIỆC CẦN XỬ LÝ HÔM NAY -->
      <div v-if="visibleTodoTasks.length > 0" class="rounded-2xl border border-slate-200/90 bg-white p-5 sm:p-6 shadow-2xs">
        <div class="flex items-center gap-2.5 mb-4">
          <AlertTriangle class="h-5 w-5 text-red-500 shrink-0 stroke-[2.2]" />
          <h2 class="text-base sm:text-lg font-black tracking-wide text-slate-800 uppercase">
            Việc cần xử lý hôm nay
          </h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div
            v-for="task in visibleTodoTasks"
            :key="task.id"
            :class="[
              task.cardBg,
              task.cardBorder,
              'rounded-xl border p-4 transition-all flex flex-col justify-between min-h-[114px] shadow-2xs hover:shadow-xs'
            ]"
          >
            <div class="flex items-start gap-2.5">
              <span :class="[task.dotColor, 'h-2.5 w-2.5 rounded-full mt-1.5 shrink-0']"></span>
              <p class="text-slate-800 text-sm font-medium leading-snug">
                {{ task.textBefore }}
                <strong :class="[task.countColor, 'text-base sm:text-lg font-black mx-0.5']">
                  {{ task.count }}
                </strong>
                {{ task.textAfter }}
              </p>
            </div>

            <div class="mt-3.5 pt-1.5 border-t border-black/5 flex items-center justify-between">
              <router-link
                :to="task.to"
                :class="[
                  task.actionColor,
                  'inline-flex items-center gap-1.5 font-bold text-xs sm:text-sm hover:translate-x-1 transition-all'
                ]"
              >
                <span>➔</span>
                <span>{{ task.actionText }}</span>
              </router-link>
            </div>
          </div>
        </div>
      </div>

      <!-- KHU VỰC 2: KHỐI VIỆC GẤP CẦN XỬ LÝ (ACTION REQUIRED) -->
      <div class="space-y-6">
        <!-- 1. CẢNH BÁO SẮP HẾT BUỔI (Giao diện chuẩn theo mẫu tham khảo) -->
        <div class="rounded-2xl border border-rose-200/90 bg-white shadow-2xs p-5 space-y-4">
          <!-- Header & Title -->
          <div>
            <div class="flex items-center gap-2">
              <AlertTriangle class="h-5 w-5 text-rose-500 shrink-0" />
              <h2 class="text-sm sm:text-base font-bold text-slate-900">
                Cảnh báo sắp hết buổi
              </h2>
            </div>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">
              Học viên có tổng buổi còn lại ít hơn 6
            </p>
          </div>

          <!-- Filter Pills: [ Đang lọc: còn 0-2 buổi ] | [ Xem tất cả ] -->
          <div class="flex items-center gap-3 pt-1">
            <button
              type="button"
              @click="expiringFilter = '0-2'"
              :class="[
                expiringFilter === '0-2'
                  ? 'bg-blue-600 text-white font-semibold shadow-xs'
                  : 'bg-slate-100 text-slate-700 hover:bg-slate-200 font-medium',
                'rounded-full px-3.5 py-1 text-xs transition-all active:scale-[0.98]'
              ]"
            >
              {{ expiringFilter === '0-2' ? 'Đang lọc: còn 0–2 buổi' : 'Còn 0–2 buổi' }}
            </button>
            <button
              type="button"
              @click="expiringFilter = 'all'"
              :class="[
                expiringFilter === 'all'
                  ? 'bg-blue-600 text-white font-semibold shadow-xs rounded-full px-3.5 py-1'
                  : 'text-slate-600 hover:text-blue-600 font-medium',
                'text-xs transition-all active:scale-[0.98]'
              ]"
            >
              Xem tất cả
            </button>
          </div>

          <!-- Students List -->
          <div class="divide-y divide-slate-100 pt-2 space-y-3.5">
            <div
              v-for="s in displayedExpiringStudents"
              :key="s.id"
              class="pt-3.5 first:pt-0 flex flex-col xl:flex-row xl:items-center justify-between gap-3"
            >
              <!-- Left: Name, Parent, Course & Latest Care Note -->
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5 flex-wrap">
                  <router-link
                    :to="`/students/${s.id}`"
                    class="font-bold text-slate-900 hover:text-blue-600 text-xs sm:text-[14px] uppercase tracking-tight"
                  >
                    {{ s.name }} &mdash; <span class="text-slate-500 font-normal text-xs normal-case">(PH: {{ s.parent_name || 'N/A' }})</span>
                  </router-link>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                  [{{ s.course?.category || 'HSSV' }}] {{ s.course?.name || 'Khóa học' }}
                </p>

                <!-- Hiển thị ghi chú chăm sóc gần nhất trực tiếp tại đây để người dùng thấy ngay -->
                <div
                  v-if="s.latest_retention_log"
                  class="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-amber-50 border border-amber-200/90 px-2 py-0.5 text-[11px] text-amber-800 font-medium max-w-full truncate"
                  :title="s.latest_retention_log.content"
                >
                  <span class="font-bold shrink-0">📝 Ghi chú:</span>
                  <span class="truncate">{{ s.latest_retention_log.content }}</span>
                  <span class="text-slate-400 shrink-0 text-[10px]">({{ formatDateShort(s.latest_retention_log.created_at) }})</span>
                </div>
              </div>

              <!-- Right: Buttons Toolbar (giống chuẩn tham khảo) -->
              <div class="flex flex-wrap items-center gap-2 shrink-0">
                <!-- 1. [ Hóa đơn ] (Click vào sáng lên / toggle status 'invoice_sent') -->
                <button
                  type="button"
                  @click="toggleRetentionStatus(s, 'invoice_sent')"
                  :class="[
                    isStatusActive(s, 'invoice_sent')
                      ? 'bg-blue-600 text-white shadow-xs font-semibold'
                      : 'border border-blue-500 bg-white hover:bg-blue-50 text-blue-600 font-medium',
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs sm:text-sm transition-all active:scale-[0.98]'
                  ]"
                  title="Đánh dấu đã gửi thông báo hóa đơn (sáng lên)"
                >
                  <FileText class="h-3.5 w-3.5" />
                  <span>Hóa đơn</span>
                  <Check v-if="isStatusActive(s, 'invoice_sent')" class="h-3 w-3" />
                </button>

                <!-- 2. [ 📄↓ ] Xuất PDF hóa đơn: chỉ hiện khi đã tạo hóa đơn (nút Hóa đơn đang sáng) -->
                <button
                  v-if="isStatusActive(s, 'invoice_sent')"
                  type="button"
                  @click="openInvoicePDF(s)"
                  class="inline-flex items-center justify-center rounded-lg border border-blue-400 bg-white hover:bg-blue-50 px-2 py-1.5 text-xs sm:text-sm text-blue-600 shadow-2xs transition-all active:scale-[0.98]"
                  title="Xem hóa đơn PDF"
                >
                  <FileDown class="h-3.5 w-3.5" />
                </button>

                <!-- 3. [ 📞 Chăm sóc ] (Click vào sáng lên / toggle status 'following_up') -->
                <button
                  type="button"
                  @click="toggleRetentionStatus(s, 'following_up')"
                  :class="[
                    isStatusActive(s, 'following_up')
                      ? 'bg-amber-500 text-white shadow-xs font-semibold'
                      : 'border border-amber-400 bg-white hover:bg-amber-50 text-amber-600 font-medium',
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs sm:text-sm transition-all active:scale-[0.98]'
                  ]"
                  title="Đánh dấu đang chăm sóc / gọi điện (sáng lên)"
                >
                  <PhoneCall class="h-3.5 w-3.5" />
                  <span>Chăm sóc</span>
                  <Check v-if="isStatusActive(s, 'following_up')" class="h-3 w-3" />
                </button>

                <!-- 4. [ 🗹 Đã đóng ] (Click vào sáng lên / toggle status 'renewed') -->
                <button
                  type="button"
                  @click="toggleRetentionStatus(s, 'renewed')"
                  :class="[
                    isStatusActive(s, 'renewed')
                      ? 'bg-emerald-600 text-white shadow-xs font-semibold'
                      : 'border border-emerald-500 bg-white hover:bg-emerald-50 text-emerald-600 font-medium',
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs sm:text-sm transition-all active:scale-[0.98]'
                  ]"
                  title="Đánh dấu đã đóng học phí"
                >
                  <CheckCircle2 class="h-3.5 w-3.5" />
                  <span>Đã đóng</span>
                  <Check v-if="isStatusActive(s, 'renewed')" class="h-3 w-3" />
                </button>

                <!-- 5. [ 📄 ] (Xem lịch sử chăm sóc & thêm ghi chú) -->
                <button
                  type="button"
                  @click="openLogsModal(s)"
                  class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white hover:bg-slate-100 px-2 py-1.5 text-xs sm:text-sm text-slate-700 shadow-2xs transition-all active:scale-[0.98]"
                  title="Xem lịch sử chăm sóc & ghi chú"
                >
                  <FileText class="h-3.5 w-3.5" />
                </button>

                <!-- 6. [ X còn lại ] (Badge xanh dương nền đặc) -->
                <span class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3 py-1.5 text-xs sm:text-sm font-bold text-white shadow-2xs">
                  {{ s.remaining_sessions }} còn lại
                </span>

                <!-- 7. [ 💬 Mẫu tin nhắn ] -->
                <button
                  type="button"
                  @click="openTemplateModal(s)"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 px-3 py-1.5 text-xs sm:text-sm font-normal text-slate-700 shadow-2xs transition-all active:scale-[0.98]"
                >
                  <MessageSquare class="h-3.5 w-3.5 text-slate-500" />
                  <span>Mẫu tin nhắn</span>
                </button>
              </div>
            </div>

            <div v-if="displayedExpiringStudents.length === 0" class="py-6 text-center text-xs sm:text-sm text-slate-400">
              Không có học viên nào phù hợp bộ lọc này.
            </div>
          </div>
        </div>

        <!-- 2. CẢNH BÁO ĐẾN HẠN ĐÓNG HỌC PHÍ -->
        <div class="rounded-2xl border border-amber-200/80 bg-white shadow-2xs overflow-hidden">
          <div class="p-4 sm:p-5 border-b border-amber-100 bg-amber-50/30 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
              <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500 text-white shadow-xs">
                <AlertTriangle class="h-4.5 w-4.5" />
              </div>
              <div>
                <h2 class="text-sm sm:text-base font-bold text-slate-900">
                  Cảnh báo đến hạn đóng học phí
                </h2>
                <p class="text-xs sm:text-sm text-slate-500">
                  Học viên còn ≤ 1 buổi hoặc khoá học kết thúc trong vòng 5 ngày tới
                </p>
              </div>
            </div>
            <span v-if="expiringEndDateStudents.length > 0" class="inline-flex items-center rounded-full bg-amber-100 px-3 py-0.5 text-xs font-bold text-amber-800">
              {{ expiringEndDateStudents.length }} học viên
            </span>
          </div>

          <div class="p-4 sm:p-5">
            <div v-if="expiringEndDateStudents.length === 0" class="py-2 text-xs sm:text-sm font-medium text-slate-600 flex items-center gap-1.5">
              <span>Tất cả ổn</span>
              <span>🎉</span>
            </div>

            <div v-else class="divide-y divide-slate-100 space-y-3">
              <div
                v-for="s in expiringEndDateStudents"
                :key="s.id"
                class="pt-3 first:pt-0 flex items-center justify-between gap-4"
              >
                <div>
                  <span class="font-bold text-slate-900 text-xs sm:text-[15px]">{{ s.name }}</span>
                  <span class="text-slate-500 text-xs sm:text-sm ml-2">({{ s.course?.name }})</span>
                  <p class="text-xs sm:text-sm text-amber-700 mt-0.5">
                    Còn {{ s.remaining_sessions ?? 0 }} buổi
                    <template v-if="s.end_date"> &bull; Hạn kết thúc: {{ s.end_date }}</template>
                  </p>
                </div>
                <button
                  @click="openRenewModal(s)"
                  class="rounded-lg bg-indigo-600 hover:bg-indigo-700 px-3.5 py-1.5 text-xs sm:text-sm font-medium text-white shadow-xs shadow-indigo-600/20 transition-all active:scale-[0.98]"
                  title="Thu học phí, tạo phiếu thu và cộng buổi học"
                >
                  Thu học phí
                </button>
              </div>
            </div>
          </div>
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
      @saved="fetchData"
    />

    <RenewModal
      :show="showRenewModal"
      :student="selectedStudent"
      @close="showRenewModal = false"
      @renewed="fetchData"
    />

    <RetentionLogsModal
      :show="showLogsModal"
      :student="selectedStudent"
      @close="showLogsModal = false"
      @saved="onStudentLogSaved"
    />
  </div>
</template>
