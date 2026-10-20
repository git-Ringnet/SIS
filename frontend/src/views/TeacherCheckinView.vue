<script setup>
import { toLocalDateString, toLocalMonthString } from '../services/date';
import { notify, errorMessage } from '../services/notify';
import { ref, onMounted, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { teacherCheckinApi } from '../services/api';
import { useAuth } from '../services/useAuth';
import MoneyInput from '../components/MoneyInput.vue';
import {
  FileSpreadsheet,
  Users,
  Calendar,
  CheckCircle2,
  Clock,
  AlertTriangle,
  XCircle,
  FileText,
  DollarSign,
  X,
  Trash2,
  QrCode,
} from 'lucide-vue-next';

// Bộ lọc ngày hôm nay
const selectedDate = ref(toLocalDateString());
const selectedCenter = ref('all');
const selectedShiftType = ref('all');
const selectedShiftTime = ref('all');
const selectedTeacher = ref('all');
// Giáo viên chỉ thấy và check-in ca của mình; các thao tác quản lý (đơn giá, báo vắng, dạy thay) ẩn đi
const { user } = useAuth();
const isTeacher = computed(() => !!user.value?.is_teacher);

// Mã QR giáo viên vừa quét ở quầy (?qr=...&c=...). Giáo viên bắt buộc có mã này mới tự check-in được.
const route = useRoute();
const router = useRouter();
const qrToken = ref(typeof route.query.qr === 'string' ? route.query.qr : '');
const qrCenter = ref(typeof route.query.c === 'string' ? route.query.c : '');
const hasQr = computed(() => !!qrToken.value && !!qrCenter.value);
// Đang mở sẵn trang mà quét mã mới thì nhận mã mới
watch(
  () => route.query.qr,
  (qr) => {
    if (typeof qr === 'string' && qr) {
      qrToken.value = qr;
      qrCenter.value = typeof route.query.c === 'string' ? route.query.c : '';
    }
  },
);

// Bộ lọc tháng
const selectedMonth = ref(toLocalMonthString());

// Dữ liệu API
const loading = ref(true);
const dayOfWeek = ref('CN');
const todayStats = ref({
  total: 0,
  checked_in: 0,
  skipped: 0,
  excused_absence: 0,
  late: 0,
  substitute: 0,
});
const todayShifts = ref([]);
const monthlySummary = ref({
  total_salary: 0,
  total_shifts: 0,
  shifts: [],
});
const teachers = ref([]);
const centers = ref([]);

// Modals
const showRatesModal = ref(false);
const showAbsenceModal = ref(false);
const selectedShift = ref(null);

// Form Báo vắng / Dạy thay
const absenceForm = ref({
  shift_id: null,
  status: 'excused_absence', // 'skipped' | 'excused_absence' | 'substitute'
  substitute_teacher: '',
  reason: '',
});

// Đơn giá theo từng giáo viên: kiểu tính ca nhóm + đơn giá chung + đơn giá riêng theo khoá học được gán
const rateTeachers = ref([]);
const savingRates = ref(false);
const PAY_MODES = [
  { value: 'per_shift', label: 'Tính theo ca' },
  { value: 'per_student', label: 'Tính theo lượt HV' },
  { value: 'free', label: 'Dạy tự do' },
];

const formatCurrency = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const formatDateDisplay = (dateStr) => {
  if (!dateStr) return '';
  const [y, m, d] = dateStr.split('-');
  return `${d}/${m}/${y}`;
};

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await teacherCheckinApi.getCheckinData({
      date: selectedDate.value,
      month: selectedMonth.value,
      center: selectedCenter.value,
      shift_type: selectedShiftType.value,
      shift_time: selectedShiftTime.value,
      teacher_name: selectedTeacher.value,
    });
    dayOfWeek.value = res.data.day_of_week || 'CN';
    todayStats.value = res.data.today_stats || {};
    todayShifts.value = res.data.today_shifts || [];
    monthlySummary.value = res.data.monthly_summary || {};
    teachers.value = res.data.teachers || [];
    centers.value = res.data.centers || [];
  } catch (err) {
    console.error('Lỗi tải dữ liệu check-in ca dạy:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchData();
});

// Check-in nhanh 1 ca dạy
// Server tự so giờ check-in với giờ vào ca để biết đúng giờ hay đi trễ (và trễ bao nhiêu phút)
const performCheckin = async (shift) => {
  try {
    const payload = { shift_id: shift.id };
    if (isTeacher.value) Object.assign(payload, { qr_token: qrToken.value, qr_center: qrCenter.value });
    const res = await teacherCheckinApi.checkin(payload);
    if (isTeacher.value) {
      // Mỗi lần quét chỉ dùng cho một lần check-in
      qrToken.value = '';
      router.replace({ query: {} });
    }
    notify.success(res.data.message || 'Đã check-in ca dạy');
    await fetchData();
  } catch (err) {
    notify.error('Lỗi check-in', { description: errorMessage(err, err.message) });
  }
};

// Mở modal Báo vắng / Dạy thay
const openAbsenceModal = (shift) => {
  selectedShift.value = shift;
  absenceForm.value = {
    shift_id: shift.id,
    status: 'excused_absence',
    substitute_teacher: '',
    reason: '',
  };
  showAbsenceModal.value = true;
};

const submitAbsence = async () => {
  try {
    const res = await teacherCheckinApi.recordAbsence(absenceForm.value);
    notify.success(res.data.message || 'Đã cập nhật ca dạy');
    showAbsenceModal.value = false;
    await fetchData();
  } catch (err) {
    notify.error('Lỗi ghi nhận', { description: errorMessage(err, err.message) });
  }
};

// Mở modal Đơn giá
const openRatesModal = async () => {
  try {
    const res = await teacherCheckinApi.getRates();
    rateTeachers.value = res.data.teachers || [];
    showRatesModal.value = true;
  } catch (err) {
    notify.apiError(err, 'Không tải được đơn giá ca dạy');
  }
};

const submitRates = async () => {
  try {
    savingRates.value = true;
    const res = await teacherCheckinApi.updateRates({ teachers: rateTeachers.value });
    showRatesModal.value = false;
    notify.success(res.data.message || 'Đã lưu đơn giá ca dạy');
    await fetchData();
  } catch (err) {
    notify.error('Lỗi cập nhật', { description: errorMessage(err, err.message) });
  } finally {
    savingRates.value = false;
  }
};

const payModeLabel = (mode) => ({ per_student: 'Theo lượt HV', free: 'Dạy tự do' })[mode] || 'Theo ca';

// Đơn giá chi tiết của một ca đã chốt (hiện dưới cột Kiểu tính)
const rateDetail = (ms) => {
  if (ms.pay_mode === 'free') {
    return ms.free_threshold
      ? `>${ms.free_threshold} HV: ${formatCurrency(ms.unit_rate)}/HV, còn lại: ${formatCurrency(ms.free_per_shift)}/ca`
      : `${formatCurrency(ms.free_per_shift)} / ca`;
  }
  return `${formatCurrency(ms.unit_rate)}${ms.pay_mode === 'per_student' ? ' / HV' : ' / ca'}`;
};

const clearCourseRate = (c) => {
  c.group = null;
  c.one_on_one = null;
};

// Xuất file Excel tháng
const exportMonthly = async () => {
  try {
    await teacherCheckinApi.exportMonthly(selectedMonth.value);
  } catch (err) {
    notify.apiError(err, 'Không thể xuất file ca dạy');
  }
};
</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Check-in ca dạy</h1>
    </div>

    <!-- CARD 1: CA DẠY NGÀY [CN] (Khớp 100% hình ảnh tham khảo Seny) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <!-- Header hàng 1: Tiêu đề ngày + 3 Nút hành động góc phải -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <h2 class="text-sm sm:text-base font-bold text-slate-900">
          Ca dạy ngày {{ dayOfWeek }}
        </h2>

        <div class="flex items-center gap-2 flex-wrap">
          <!-- Nút [ ⚏ QR check-in ] → màn hình đặt ở quầy, mã đổi mỗi 30 giây -->
          <router-link v-if="!isTeacher" to="/teacher-checkin/qr"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer shadow-2xs">
            <QrCode class="h-3.5 w-3.5 text-slate-600" />
            <span>QR check-in</span>
          </router-link>

          <!-- Nút [ 📄 Đơn giá ca dạy ] -->
          <button v-if="!isTeacher" type="button" @click="openRatesModal"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer shadow-2xs">
            <FileText class="h-3.5 w-3.5 text-slate-600" />
            <span>Đơn giá ca dạy</span>
          </button>

          <!-- Nút [ 📑 Nghỉ phép / Dạy thay ] → trang riêng theo khoảng ngày -->
          <router-link v-if="!isTeacher" to="/teacher-checkin/leaves"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer shadow-2xs">
            <Users class="h-3.5 w-3.5 text-slate-600" />
            <span>Nghỉ phép / Dạy thay</span>
          </router-link>
        </div>
      </div>

      <!-- Giáo viên: nhắc quét QR tại quầy -->
      <div v-if="isTeacher" :class="[
        hasQr ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800',
        'flex items-start gap-2 rounded-xl border px-3 py-2.5 text-xs sm:text-sm',
      ]">
        <QrCode class="h-4 w-4 shrink-0 mt-0.5" />
        <span v-if="hasQr">Đã quét mã QR tại <strong>{{ qrCenter }}</strong>. Bấm <strong>Check-in</strong> ở ca của bạn
          (mã còn hạn khoảng 1 phút).</span>
        <span v-else>Để check-in, hãy dùng <strong>Camera</strong> hoặc <strong>Zalo</strong> quét <strong>mã QR đang
            hiện tại quầy</strong> của trung tâm.</span>
      </div>

      <!-- Filter Bar: Ngày | Trung tâm | Loại ca | Ca dạy | Giáo viên (Khớp 100% hình tham khảo) -->
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 pt-1 border-t border-slate-100">
        <!-- Ngày -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Ngày</label>
          <div class="relative">
            <input v-model="selectedDate" type="date" @change="fetchData"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none" />
          </div>
        </div>

        <!-- Trung tâm -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Trung tâm</label>
          <select v-model="selectedCenter" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <!-- Loại ca -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Loại ca</label>
          <select v-model="selectedShiftType" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả loại ca</option>
            <option value="Ca 1-1">Ca 1-1</option>
            <option value="Ca nhóm">Ca nhóm</option>
            <option value="Dạy thử">Dạy thử / Test</option>
          </select>
        </div>

        <!-- Ca dạy -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Ca dạy</label>
          <select v-model="selectedShiftTime" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả ca dạy</option>
            <option value="morning">Ca sáng (08:00 - 11:30)</option>
            <option value="afternoon">Ca chiều (14:00 - 17:30)</option>
            <option value="evening">Ca tối (17:30 - 21:00)</option>
          </select>
        </div>

        <!-- Giáo viên -->
        <div v-if="!isTeacher">
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Giáo viên</label>
          <select v-model="selectedTeacher" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả giáo viên</option>
            <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
      </div>

      <!-- 6 Metric Cards trong ngày (Khớp 100% màu sắc và viền trong hình tham khảo) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 pt-2">
        <!-- 1. TỔNG CA DẠY HÔM NAY (Viền đen / tím) -->
        <div class="rounded-xl border-2 border-slate-900 bg-white p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-600">
            <span class="h-2 w-2 rounded-full bg-indigo-600"></span>
            <span>TỔNG CA DẠY HÔM NAY</span>
          </div>
          <div class="text-2xl font-black text-indigo-700">
            {{ todayStats.total || 0 }}
          </div>
        </div>

        <!-- 2. TỔNG CA ĐÃ CHECK-IN (Xanh lá) -->
        <div class="rounded-xl border border-emerald-300 bg-emerald-50/40 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            <span>TỔNG CA ĐÃ CHECK-IN</span>
          </div>
          <div class="text-2xl font-black text-emerald-700">
            {{ todayStats.checked_in || 0 }}
          </div>
        </div>

        <!-- 3. TỔNG CA BỎ DẠY (Đỏ) -->
        <div class="rounded-xl border border-rose-300 bg-rose-50/40 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-rose-800">
            <span class="h-2 w-2 rounded-full bg-rose-500"></span>
            <span>TỔNG CA BỎ DẠY</span>
          </div>
          <div class="text-2xl font-black text-rose-700">
            {{ todayStats.skipped || 0 }}
          </div>
        </div>

        <!-- 4. TỔNG CA VẮNG CÓ PHÉP (Xanh lá nhạt) -->
        <div class="rounded-xl border border-emerald-300 bg-emerald-50/40 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            <span>TỔNG CA VẮNG CÓ PHÉP</span>
          </div>
          <div class="text-2xl font-black text-emerald-700">
            {{ todayStats.excused_absence || 0 }}
          </div>
        </div>

        <!-- 5. TỔNG CA ĐI TRỄ (Vàng / Cam) -->
        <div class="rounded-xl border border-amber-300 bg-amber-50/40 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-amber-800">
            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
            <span>TỔNG CA ĐI TRỄ</span>
          </div>
          <div class="text-2xl font-black text-amber-700">
            {{ todayStats.late || 0 }}
          </div>
        </div>

        <!-- 6. TỔNG CA DẠY THAY (Xanh dương) -->
        <div class="rounded-xl border border-blue-300 bg-blue-50/40 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-blue-800">
            <span class="h-2 w-2 rounded-full bg-blue-500"></span>
            <span>TỔNG CA DẠY THAY</span>
          </div>
          <div class="text-2xl font-black text-blue-700">
            {{ todayStats.substitute || 0 }}
          </div>
        </div>
      </div>

      <!-- State: Khi không có ca dạy nào trong ngày (Khớp 100% text hộp xám hình tham khảo) -->
      <div v-if="todayShifts.length === 0"
        class="rounded-xl border border-slate-200/80 bg-slate-50/60 p-4 text-xs text-slate-500 text-left">
        <template v-if="isTeacher">
          Hôm nay bạn không có ca dạy nào. Ca dạy được tạo từ lịch học cố định của học viên có
          <strong class="text-slate-700">giáo viên là "{{ user?.name }}"</strong> và học vào thứ này.
          Nếu bạn có lịch dạy mà không thấy ca, nhờ quản lý kiểm tra tên giáo viên trong lịch học của học viên
          trùng với tên tài khoản của bạn.
        </template>
        <template v-else>
          Không có ca dạy nào trong ngày này. Ca dạy được tạo từ lịch học cố định của học viên (mục Lịch học
          trong thẻ học viên) có điền giáo viên và học vào thứ này. Tên giáo viên trong lịch học phải trùng với
          tên tài khoản giáo viên ở màn Nhân sự thì giáo viên mới tự check-in được.
        </template>
      </div>

      <!-- State: Khi có ca dạy trong ngày -->
      <div v-else class="divide-y divide-slate-100 pt-1 space-y-3">
        <div v-for="s in todayShifts" :key="s.id"
          class="pt-3 first:pt-0 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs sm:text-sm">
          <div class="flex items-start gap-3">
            <div
              class="flex flex-col items-center justify-center rounded-xl bg-slate-100 px-3 py-2 text-slate-700 shrink-0 min-w-16">
              <Clock class="h-4 w-4 mb-0.5 text-slate-500" />
              <span class="font-bold text-xs">{{ s.start_time }}</span>
            </div>

            <div>
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-bold text-slate-900">{{ s.teacher_name }}</span>
                <span v-if="s.substitute_teacher" class="text-xs font-semibold text-blue-700">
                  → dạy thay: {{ s.substitute_teacher }}
                </span>
                <span class="rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">
                  {{ s.type }}
                </span>
                <span class="text-slate-400 font-mono text-xs">({{ s.room }})</span>
              </div>
              <p class="text-xs text-slate-600 mt-0.5 font-medium">
                {{ s.class_name }}<template v-if="s.period_note"> &bull; <span class="text-slate-500">{{ s.period_note
                    }}</span></template>
              </p>
              <div class="flex items-center gap-2 mt-1 flex-wrap">
                <span v-if="s.status === 'checked_in'"
                  class="rounded-full bg-emerald-100 text-emerald-800 px-2 py-0.5 text-[11px] font-bold inline-flex items-center gap-1">
                  <CheckCircle2 class="h-3 w-3" /> Đã check-in lúc {{ s.checkin_time }}
                </span>
                <span v-else-if="s.status === 'late'"
                  class="rounded-full bg-amber-100 text-amber-800 px-2 py-0.5 text-[11px] font-bold inline-flex items-center gap-1">
                  <AlertTriangle class="h-3 w-3" /> Đi trễ {{ s.minutes_late }}p ({{ s.checkin_time }})
                </span>
                <span v-else-if="s.status === 'substitute'"
                  class="rounded-full bg-blue-100 text-blue-800 px-2 py-0.5 text-[11px] font-bold">
                  {{ s.substitute_teacher }} dạy thay, check-in lúc {{ s.checkin_time }}
                  <template v-if="s.minutes_late > 0">(trễ {{ s.minutes_late }}p)</template>
                </span>
                <span v-else-if="s.status === 'skipped'"
                  class="rounded-full bg-rose-100 text-rose-800 px-2 py-0.5 text-[11px] font-bold">
                  Bỏ dạy không phép
                </span>
                <span v-else-if="s.status === 'excused_absence'"
                  class="rounded-full bg-slate-200 text-slate-700 px-2 py-0.5 text-[11px] font-bold">
                  {{ s.on_leave ? 'Nghỉ phép' : 'Vắng có phép' }}
                </span>
                <span v-else class="text-slate-400 text-xs">
                  Chưa check-in
                </span>
                <span v-if="s.checked_in_by && !isTeacher" class="text-[11px] text-slate-400">
                  bấm bởi {{ s.checked_in_by }}
                </span>
              </div>
            </div>
          </div>

          <!-- Nút Check-in nhanh -->
          <div class="flex items-center gap-2 shrink-0">
            <button v-if="s.status === 'not_checked_in' && !s.on_leave && (!isTeacher || hasQr)" type="button"
              @click="performCheckin(s)"
              class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 text-xs font-semibold shadow-2xs cursor-pointer"
              title="Ghi nhận giờ giáo viên vào ca; trễ quá 5 phút so với giờ vào ca sẽ tự tính là đi trễ">
              Check-in
            </button>
            <button
              v-if="!isTeacher && !s.on_leave && ['not_checked_in', 'skipped', 'excused_absence'].includes(s.status)"
              type="button" @click="openAbsenceModal(s)"
              class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-3 py-1.5 text-xs font-medium cursor-pointer">
              Báo vắng / Dạy thay
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- CARD 2: TỔNG CA DẠY THEO THÁNG (Khớp 100% hình tham khảo Seny) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <h2 class="text-sm sm:text-base font-bold text-slate-900">Tổng ca dạy theo tháng</h2>

      <!-- Filter Tháng & Nút Tải Excel tháng này -->
      <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div class="w-full sm:w-64">
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Tháng</label>
          <input v-model="selectedMonth" type="month" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none" />
        </div>

        <button type="button" @click="exportMonthly"
          class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-5 py-2 text-xs sm:text-sm font-semibold text-slate-800 shadow-2xs transition-all cursor-pointer">
          Tải Excel tháng này
        </button>
      </div>

      <!-- Banner đỏ: Tổng tiền trả giáo viên trong tháng (Khớp 100% hình tham khảo) -->
      <div class="rounded-xl border border-rose-100 bg-rose-50/60 p-4 flex items-center justify-between">
        <span class="text-xs sm:text-sm font-bold text-slate-800">
          Tổng tiền trả giáo viên trong tháng
        </span>
        <span class="text-base sm:text-lg font-black text-rose-600">
          {{ formatCurrency(monthlySummary.total_salary) }}
        </span>
      </div>

      <!-- Subtext thông báo -->
      <div v-if="monthlySummary.shifts.length === 0" class="space-y-4">
        <p class="text-xs text-slate-500">Chưa có ca dạy nào được check-in trong tháng này.</p>
        <div class="py-8 text-center text-xs sm:text-sm text-slate-500">
          Không có dữ liệu
        </div>
      </div>

      <!-- Bảng ca dạy tháng -->
      <div v-else class="overflow-x-auto pt-1">
        <table class="w-full text-left text-xs sm:text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-slate-500 text-[11px] sm:text-xs uppercase font-semibold">
              <th class="py-2.5 pr-2">Ngày</th>
              <th class="py-2.5 px-2">Người dạy</th>
              <th class="py-2.5 px-2">Ca dạy</th>
              <th class="py-2.5 px-2">Loại</th>
              <th class="py-2.5 px-2">Kiểu tính</th>
              <th class="py-2.5 px-2 text-right">Thu nhập</th>
              <th class="py-2.5 px-2 text-center">Giờ check-in</th>
              <th class="py-2.5 pl-2 text-center">Trễ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="ms in monthlySummary.shifts" :key="ms.id" class="hover:bg-slate-50/70 transition-colors">
              <td class="py-3 pr-2 font-mono text-xs text-slate-600">{{ formatDateDisplay(ms.date) }}</td>
              <td class="py-3 px-2">
                <span class="font-bold text-slate-900">{{ ms.earner }}</span>
                <span v-if="ms.status === 'substitute'" class="block text-[11px] text-blue-700">thay {{ ms.teacher_name
                  }}</span>
              </td>
              <td class="py-3 px-2 text-xs text-slate-700">{{ ms.shift_name }}</td>
              <td class="py-3 px-2 text-xs font-semibold text-blue-700">{{ ms.type }}</td>
              <td class="py-3 px-2 text-xs text-slate-600">
                {{ ms.unit_rate !== undefined ? payModeLabel(ms.pay_mode) : '—' }}
                <span v-if="ms.unit_rate !== undefined" class="block text-[11px] text-slate-400">
                  {{ rateDetail(ms) }}
                </span>
              </td>
              <td class="py-3 px-2 text-right font-extrabold text-emerald-700">{{ formatCurrency(ms.amount) }}</td>
              <td class="py-3 px-2 text-center font-mono text-xs">{{ ms.checkin_time || '-' }}</td>
              <td class="py-3 pl-2 text-center text-xs">
                <span v-if="ms.minutes_late > 0" class="text-amber-600 font-bold">{{ ms.minutes_late }}p</span>
                <span v-else class="text-slate-300">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- MODAL 2: ĐƠN GIÁ CA DẠY THEO GIÁO VIÊN -->
    <div v-if="showRatesModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="mx-auto my-6 w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-start justify-between gap-3">
          <h3 class="text-base sm:text-lg font-bold text-slate-900">Đơn giá ca dạy theo giáo viên</h3>
          <button type="button" @click="showRatesModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X class="h-5 w-5" />
          </button>
        </div>

        <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
          Mỗi giáo viên chọn <strong>một kiểu tính</strong> cho ca nhóm: <strong>Tính theo ca</strong> (tiền cố định mỗi
          ca),
          <strong>Tính theo lượt HV</strong> (đơn giá × số học viên có mặt theo Điểm danh), hoặc <strong>Dạy tự
            do</strong>
          (theo ngưỡng học viên). Có thể đặt đơn giá riêng cho từng khoá học; khoá nào để trống dùng đơn giá chung.
          Ca 1:1 luôn tính theo đơn giá 1:1.
        </p>
        <p class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-600">
          Lưu ý: đơn giá mới chỉ áp dụng cho ca check-in sau khi lưu. Thu nhập các ca đã check-in được giữ nguyên.
        </p>

        <p v-if="rateTeachers.length === 0" class="py-6 text-center text-xs text-slate-500">
          Chưa có giáo viên nào. Tạo tài khoản vai trò Giáo viên ở màn Nhân sự hoặc điền giáo viên trong lịch học của
          học
          viên.
        </p>

        <form v-else @submit.prevent="submitRates" class="space-y-3">
          <div v-for="t in rateTeachers" :key="t.name" class="rounded-xl border border-slate-200 p-4 space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
              <span class="font-bold text-slate-900">{{ t.name }}</span>
              <div :class="['grid gap-2', t.mode === 'free' ? 'grid-cols-1 sm:w-40' : 'grid-cols-2 sm:w-80']">
                <label v-if="t.mode !== 'free'" class="text-[11px] font-semibold text-slate-500">
                  {{ t.mode === 'per_student' ? 'Nhóm (giá / 1 HV)' : 'Nhóm (giá / ca dạy)' }}
                  <MoneyInput v-model="t.group"
                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold text-slate-900 focus:border-rose-500 focus:outline-none" />
                </label>
                <label class="text-[11px] font-semibold text-slate-500">
                  1:1 (giá / ca)
                  <MoneyInput v-model="t.one_on_one"
                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold text-slate-900 focus:border-rose-500 focus:outline-none" />
                </label>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
              <span class="text-slate-500">Kiểu tính ca nhóm:</span>
              <button v-for="m in PAY_MODES" :key="m.value" type="button" @click="t.mode = m.value" :class="[
                t.mode === m.value ? 'bg-rose-600 border-rose-600 text-white' : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50',
                'rounded-lg border px-3 py-1.5 font-semibold transition-colors',
              ]">
                {{ m.label }}
              </button>
            </div>

            <!-- Dạy tự do: đạt ngưỡng thì tính theo lượt HV, chưa đạt thì giá / ca -->
            <div v-if="t.mode === 'free'"
              class="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-3 space-y-2">
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <label class="text-[11px] font-semibold text-slate-600">
                  Ngưỡng HV
                  <input v-model.number="t.free.threshold" type="number" min="1" placeholder="≥X"
                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none" />
                </label>
                <label class="text-[11px] font-semibold text-slate-600">
                  Giá / lượt HV
                  <MoneyInput v-model="t.free.per_student" placeholder="VNĐ"
                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none" />
                </label>
                <label class="text-[11px] font-semibold text-slate-600">
                  Giá / ca dạy
                  <MoneyInput v-model="t.free.per_shift" placeholder="VNĐ"
                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none" />
                </label>
              </div>
              <p class="text-[11px] text-slate-500">
                Nếu số học viên điểm danh &gt; ngưỡng → tính theo lượt HV; nếu ≤ ngưỡng → tính theo ca dạy.
                Ô nào để trống, hệ thống dùng mức được cấu hình ở cơ sở (màn Trung tâm).
              </p>
            </div>

            <div v-else-if="t.courses.length > 0" class="border-t border-slate-100 pt-3 space-y-2">
              <p class="text-[11px] font-semibold text-slate-500">Đơn giá theo khoá học được gán (để trống = dùng đơn
                giá
                chung)</p>
              <div v-for="c in t.courses" :key="c.course_id"
                class="grid grid-cols-[1fr_7rem_7rem_auto] sm:grid-cols-[1fr_9rem_9rem_auto] items-center gap-2">
                <span class="text-xs text-slate-700">{{ c.name }}</span>
                <MoneyInput v-model="c.group" :placeholder="t.mode === 'per_student' ? 'Nhóm/HV' : 'Nhóm/ca'"
                  class="w-full rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-900 focus:border-rose-500 focus:outline-none" />
                <MoneyInput v-model="c.one_on_one" placeholder="1:1"
                  class="w-full rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-900 focus:border-rose-500 focus:outline-none" />
                <button type="button" @click="clearCourseRate(c)" class="p-1 rounded-lg text-rose-500 hover:bg-rose-50"
                  title="Bỏ đơn giá riêng của khoá này">
                  <Trash2 class="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>

          <button type="submit" :disabled="savingRates"
            class="w-full rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-5 py-2.5 text-sm font-bold text-white shadow-2xs cursor-pointer">
            {{ savingRates ? 'Đang lưu...' : 'Lưu đơn giá' }}
          </button>
        </form>
      </div>
    </div>

    <!-- MODAL 3: BÁO VẮNG / NGHỈ PHÉP / DẠY THAY -->
    <div v-if="showAbsenceModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">Ghi nhận cho ca này</h3>
          <button type="button" @click="showAbsenceModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitAbsence" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Loại ghi nhận</label>
            <select v-model="absenceForm.status"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none">
              <option value="excused_absence">Vắng có phép (Đã báo trước)</option>
              <option value="skipped">Bỏ ca dạy không phép</option>
              <option value="substitute">Giáo viên khác dạy thay ca này</option>
            </select>
          </div>

          <div v-if="absenceForm.status === 'substitute'">
            <label class="block font-medium text-slate-700 mb-1">Giáo viên nhận dạy thay</label>
            <select v-model="absenceForm.substitute_teacher" required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none">
              <option value="" disabled>Chọn giáo viên</option>
              <option v-for="t in teachers.filter((t) => t !== selectedShift?.teacher_name)" :key="t" :value="t">{{ t }}
              </option>
            </select>
            <p class="mt-1 text-[11px] text-slate-500">Người dạy thay sẽ thấy ca này và tự check-in; thù lao tính theo
              đơn
              giá của người dạy thay.</p>
          </div>

          <p class="text-[11px] text-slate-500">
            Nghỉ nhiều ngày hoặc dạy thay cả một giai đoạn: dùng nút <strong>Nghỉ phép / Dạy thay</strong> ở đầu trang.
          </p>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Lý do nghỉ / ghi chú</label>
            <textarea v-model="absenceForm.reason" rows="2" placeholder="VD: Giáo viên bị ốm hoặc bận việc gia đình..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showAbsenceModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 cursor-pointer">
              Hủy
            </button>
            <button type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs cursor-pointer">
              Xác nhận
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
