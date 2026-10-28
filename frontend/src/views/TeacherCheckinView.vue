<script setup>
import { toLocalDateString, toLocalMonthString } from '../services/date';
import { notify, errorMessage } from '../services/notify';
import { confirmDialog } from '../services/useConfirm';
import { ref, onMounted, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { teacherCheckinApi } from '../services/api';
import { useAuth } from '../services/useAuth';
import MoneyInput from '../components/MoneyInput.vue';
import QrScannerModal from '../components/QrScannerModal.vue';
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
  RotateCcw,
  Pencil,
  ArchiveRestore,
  ChevronDown,
  Download,
  Wallet,
  ScanLine,
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
// Thù lao / đơn giá: chủ trung tâm và quản lý xem tất cả, người khác chỉ xem của mình
const seesAllPay = computed(() => !!user.value?.sees_all_pay);
// Xoá ca đã tính thù lao (chấm sai): chỉ chủ trung tâm
const isOwner = computed(() => !!user.value?.is_owner);

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

// Quét mã QR ở quầy bằng camera ngay trong trang (không cần mở app Camera / Zalo)
const showScanner = ref(false);
const onQrScanned = (text) => {
  showScanner.value = false;
  let url;
  try {
    url = new URL(text, window.location.origin);
  } catch {
    url = null;
  }
  const qr = url?.searchParams.get('qr');
  const c = url?.searchParams.get('c');
  if (!url || !url.pathname.startsWith('/teacher-checkin') || !qr || !c) {
    notify.error('Đây không phải mã QR check-in của trung tâm', { description: 'Hãy quét mã QR đang hiện trên màn hình ở quầy.' });
    return;
  }
  qrToken.value = qr;
  qrCenter.value = c;
  notify.success(`Đã quét mã QR tại ${c}`, { description: 'Bấm Check-in ở ca dạy của bạn (mã còn hạn khoảng 1 phút).' });
};

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
  deleted_shifts: [],
});
// Mở danh sách ca đã xoá nháp (để khôi phục)
const showDeletedShifts = ref(false);
const teachers = ref([]);
const centers = ref([]);

// Modals
const showAbsenceModal = ref(false);
const selectedShift = ref(null);

// Form Báo vắng / Dạy thay
const absenceForm = ref({
  shift_id: null,
  status: 'excused_absence', // 'skipped' | 'excused_absence' | 'substitute'
  substitute_teacher: '',
  reason: '',
});

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
    // Báo lỗi rõ ràng, tránh hiện "0 ca dạy" như thể hôm nay không có lịch
    notify.apiError(err, 'Không tải được ca dạy');
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

// Khôi phục / Sửa / Xoá ca dạy (chỉ quản lý)
const PAID_STATUSES = ['checked_in', 'late', 'substitute'];
const showEditModal = ref(false);
const editForm = ref({ id: null, checkin_time: '', unit_rate: null, note: '' });
const savingEdit = ref(false);

const resetShift = async (shift) => {
  const ok = await confirmDialog({
    title: 'Khôi phục ca dạy?',
    message: `Ca ${shift.start_time} của ${shift.substitute_teacher || shift.teacher_name} sẽ về "Chưa check-in"; giờ check-in và đơn giá đã chốt bị bỏ.`,
    confirmText: 'Khôi phục',
    variant: 'warning',
  });
  if (!ok) return;
  try {
    const res = await teacherCheckinApi.resetShift(shift.id);
    notify.success(res.data.message || 'Đã khôi phục ca dạy');
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không khôi phục được ca dạy');
  }
};

// Bỏ người dạy thay đã phân công riêng cho ca chưa check-in (ca về giáo viên gốc)
const clearSubstitute = async (shift) => {
  const ok = await confirmDialog({
    title: 'Bỏ dạy thay?',
    message: `Ca ${shift.start_time} sẽ trở về ${shift.teacher_name}, ${shift.substitute_teacher} không dạy thay ca này nữa.`,
    confirmText: 'Bỏ dạy thay',
    variant: 'warning',
  });
  if (!ok) return;
  try {
    const res = await teacherCheckinApi.resetShift(shift.id);
    notify.success(res.data.message || 'Đã bỏ dạy thay');
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không bỏ được dạy thay');
  }
};

const openEditModal = (shift) => {
  selectedShift.value = shift;
  editForm.value = {
    id: shift.id,
    checkin_time: shift.checkin_time || shift.start_time || '',
    unit_rate: shift.unit_rate ?? shift.rate ?? null,
    note: shift.note || '',
  };
  showEditModal.value = true;
};

const submitEdit = async () => {
  try {
    savingEdit.value = true;
    const { id, ...data } = editForm.value;
    const res = await teacherCheckinApi.updateShift(id, data);
    notify.success(res.data.message || 'Đã cập nhật ca dạy');
    showEditModal.value = false;
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không cập nhật được ca dạy');
  } finally {
    savingEdit.value = false;
  }
};

const deleteShift = async (shift) => {
  const ok = await confirmDialog({
    title: 'Xoá ca dạy?',
    message: `Xoá ca ${shift.start_time} ngày ${formatDateDisplay(shift.date)} của ${shift.earner || shift.teacher_name}. Ca không được tính thù lao nữa và không tự tạo lại từ lịch học. Chủ trung tâm có thể khôi phục ở mục "Ca đã xoá".`,
    confirmText: 'Xoá',
  });
  if (!ok) return;
  try {
    const res = await teacherCheckinApi.deleteShift(shift.id);
    notify.success(res.data.message || 'Đã xoá ca dạy');
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không xoá được ca dạy');
  }
};

const restoreShift = async (shift) => {
  try {
    const res = await teacherCheckinApi.restoreShift(shift.id);
    notify.success(res.data.message || 'Đã khôi phục ca dạy');
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không khôi phục được ca dạy');
  }
};

const WEEKDAY_SHORT = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
const weekdayOf = (dateStr) => (dateStr ? WEEKDAY_SHORT[new Date(`${dateStr}T00:00:00`).getDay()] : '');

// Ca check-in trước khi đặt lương riêng ở Nhân sự có thể còn kiểu tính cũ (theo lượt HV / dạy tự do)
const payModeLabel = (ms) => ({ per_student: 'Theo lượt HV (cũ)', free: 'Dạy tự do (cũ)' })[ms.pay_mode] || (ms.type === 'Ca 1-1' ? 'Lương ca 1:1' : 'Lương ca nhóm');

// Đơn giá chi tiết của một ca đã chốt (hiện dưới cột Kiểu tính)
const rateDetail = (ms) => {
  if (ms.pay_mode === 'free') {
    return ms.free_threshold
      ? `>${ms.free_threshold} HV: ${formatCurrency(ms.unit_rate)}/HV, còn lại: ${formatCurrency(ms.free_per_shift)}/ca`
      : `${formatCurrency(ms.free_per_shift)} / ca`;
  }
  return `${formatCurrency(ms.unit_rate)}${ms.pay_mode === 'per_student' ? ' / HV' : ' / ca'}`;
};

// Tổng thù lao trong tháng theo từng giáo viên (người thực dạy), nhiều tiền nhất lên đầu
const monthlyTeacher = ref('');
const payByTeacher = computed(() => {
  const map = new Map();
  for (const ms of monthlySummary.value.shifts || []) {
    const row = map.get(ms.earner) || { name: ms.earner, shifts: 0, substitute: 0, late: 0, total: 0 };
    row.shifts += 1;
    row.total += Number(ms.amount || 0);
    if (ms.status === 'substitute') row.substitute += 1;
    if (ms.minutes_late > 0) row.late += 1;
    map.set(ms.earner, row);
  }
  return [...map.values()].sort((a, b) => b.total - a.total);
});
// Chi tiết ca trong tháng, mới nhất lên đầu
const visibleMonthlyShifts = computed(() =>
  (monthlySummary.value.shifts || [])
    .filter((ms) => !monthlyTeacher.value || ms.earner === monthlyTeacher.value)
    .sort((a, b) => `${b.date} ${b.start_time || ''}`.localeCompare(`${a.date} ${a.start_time || ''}`)),
);
const visibleDeletedShifts = computed(() =>
  (monthlySummary.value.deleted_shifts || []).filter((ms) => !monthlyTeacher.value || ms.earner === monthlyTeacher.value),
);
const visibleMonthlyTotal = computed(() =>
  monthlyTeacher.value
    ? visibleMonthlyShifts.value.reduce((sum, ms) => sum + Number(ms.amount || 0), 0)
    : monthlySummary.value.total_salary,
);
// Bấm vào giáo viên trong bảng tổng: lọc chi tiết theo giáo viên đó (bấm lại để bỏ lọc)
const toggleMonthlyTeacher = (name) => {
  monthlyTeacher.value = monthlyTeacher.value === name ? '' : name;
};

// Xuất file Excel tháng (đang lọc giáo viên thì chỉ xuất giáo viên đó)
const exportMonthly = async () => {
  try {
    await teacherCheckinApi.exportMonthly(selectedMonth.value, monthlyTeacher.value);
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

          <!-- Lương ca đặt riêng từng giáo viên / giáo vụ ở màn Nhân sự -->
          <router-link v-if="seesAllPay" to="/staff"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer shadow-2xs">
            <FileText class="h-3.5 w-3.5 text-slate-600" />
            <span>Lương ca (Nhân sự)</span>
          </router-link>

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
        <span v-else class="flex-1">Để check-in, bấm <strong>Quét QR</strong> (hoặc dùng Camera / Zalo) quét
          <strong>mã QR đang hiện tại quầy</strong> của trung tâm.</span>
        <button type="button" @click="showScanner = true"
          class="ml-auto shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 px-3 py-1.5 text-xs font-bold text-white shadow-xs">
          <ScanLine class="h-4 w-4" />
          {{ hasQr ? 'Quét lại' : 'Quét QR' }}
        </button>
      </div>
      <QrScannerModal :show="showScanner" @close="showScanner = false" @scanned="onQrScanned" />

      <!-- Filter Bar: Ngày | Trung tâm | Loại ca | Ca dạy | Giáo viên (Khớp 100% hình tham khảo) -->
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 pt-1 border-t border-slate-100 dark:border-slate-800">
        <!-- Ngày -->
        <div>
          <label class="block text-[11px] text-slate-500 dark:text-white/70 mb-1 font-medium">Ngày</label>
          <div class="relative">
            <input v-model="selectedDate" type="date" @change="fetchData"
              class="w-full rounded-xl border border-slate-200 bg-white dark:bg-slate-800 dark:border-slate-700 dark:text-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none" />
          </div>
        </div>

        <!-- Trung tâm -->
        <div>
          <label class="block text-[11px] text-slate-500 dark:text-white/70 mb-1 font-medium">Trung tâm</label>
          <select v-model="selectedCenter" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white dark:bg-slate-800 dark:border-slate-700 dark:text-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <!-- Loại ca -->
        <div>
          <label class="block text-[11px] text-slate-500 dark:text-white/70 mb-1 font-medium">Loại ca</label>
          <select v-model="selectedShiftType" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white dark:bg-slate-800 dark:border-slate-700 dark:text-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả loại ca</option>
            <option value="Ca 1-1">Ca 1-1</option>
            <option value="Ca nhóm">Ca nhóm</option>
            <option value="Dạy thử">Dạy thử / Test</option>
          </select>
        </div>

        <!-- Ca dạy -->
        <div>
          <label class="block text-[11px] text-slate-500 dark:text-white/70 mb-1 font-medium">Ca dạy</label>
          <select v-model="selectedShiftTime" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white dark:bg-slate-800 dark:border-slate-700 dark:text-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả ca dạy</option>
            <option value="morning">Ca sáng (08:00 - 11:30)</option>
            <option value="afternoon">Ca chiều (14:00 - 17:30)</option>
            <option value="evening">Ca tối (17:30 - 21:00)</option>
          </select>
        </div>

        <!-- Giáo viên -->
        <div v-if="!isTeacher">
          <label class="block text-[11px] text-slate-500 dark:text-white/70 mb-1 font-medium">Giáo viên</label>
          <select v-model="selectedTeacher" @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white dark:bg-slate-800 dark:border-slate-700 dark:text-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả giáo viên</option>
            <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
      </div>

      <!-- 6 Metric Cards trong ngày (Khớp 100% màu sắc và viền trong hình tham khảo) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 pt-2">
        <!-- 1. TỔNG CA DẠY HÔM NAY (Viền đen / tím) -->
        <div class="rounded-xl border-2 border-slate-900 bg-white p-3 space-y-1 dark:bg-slate-900/80 dark:border-indigo-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-600 dark:text-indigo-300">
            <span class="h-2 w-2 rounded-full bg-indigo-600"></span>
            <span>TỔNG CA DẠY HÔM NAY</span>
          </div>
          <div class="text-2xl font-black text-indigo-700 dark:text-indigo-400">
            {{ todayStats.total || 0 }}
          </div>
        </div>

        <!-- 2. TỔNG CA ĐÃ CHECK-IN (Xanh lá) -->
        <div class="rounded-xl border border-emerald-300 bg-emerald-50/40 p-3 space-y-1 dark:bg-slate-900/80 dark:border-emerald-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-300">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            <span>TỔNG CA ĐÃ CHECK-IN</span>
          </div>
          <div class="text-2xl font-black text-emerald-700 dark:text-emerald-400">
            {{ todayStats.checked_in || 0 }}
          </div>
        </div>

        <!-- 3. TỔNG CA BỎ DẠY (Đỏ) -->
        <div class="rounded-xl border border-rose-300 bg-rose-50/40 p-3 space-y-1 dark:bg-slate-900/80 dark:border-rose-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-rose-800 dark:text-rose-300">
            <span class="h-2 w-2 rounded-full bg-rose-500"></span>
            <span>TỔNG CA BỎ DẠY</span>
          </div>
          <div class="text-2xl font-black text-rose-700 dark:text-rose-400">
            {{ todayStats.skipped || 0 }}
          </div>
        </div>

        <!-- 4. TỔNG CA VẮNG CÓ PHÉP (Xanh lá nhạt) -->
        <div class="rounded-xl border border-emerald-300 bg-emerald-50/40 p-3 space-y-1 dark:bg-slate-900/80 dark:border-emerald-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-300">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            <span>TỔNG CA VẮNG CÓ PHÉP</span>
          </div>
          <div class="text-2xl font-black text-emerald-700 dark:text-emerald-400">
            {{ todayStats.excused_absence || 0 }}
          </div>
        </div>

        <!-- 5. TỔNG CA ĐI TRỄ (Vàng / Cam) -->
        <div class="rounded-xl border border-amber-300 bg-amber-50/40 p-3 space-y-1 dark:bg-slate-900/80 dark:border-amber-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">
            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
            <span>TỔNG CA ĐI TRỄ</span>
          </div>
          <div class="text-2xl font-black text-amber-700 dark:text-amber-400">
            {{ todayStats.late || 0 }}
          </div>
        </div>

        <!-- 6. TỔNG CA DẠY THAY (Xanh dương) -->
        <div class="rounded-xl border border-blue-300 bg-blue-50/40 p-3 space-y-1 dark:bg-slate-900/80 dark:border-blue-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-blue-800 dark:text-blue-300">
            <span class="h-2 w-2 rounded-full bg-blue-500"></span>
            <span>TỔNG CA DẠY THAY</span>
          </div>
          <div class="text-2xl font-black text-blue-700 dark:text-blue-400">
            {{ todayStats.substitute || 0 }}
          </div>
        </div>
      </div>

      <!-- State: Khi không có ca dạy nào trong ngày (Khớp 100% text hộp xám hình tham khảo) -->
      <div v-if="todayShifts.length === 0"
        class="rounded-xl border border-slate-200/80 bg-slate-50/60 p-4 text-xs text-slate-500 text-left dark:bg-slate-900/60 dark:border-slate-800 dark:text-white/80">
        <template v-if="isTeacher">
          Hôm nay bạn không có ca dạy nào. Ca dạy được tạo từ lịch học cố định của học viên có
          <strong class="text-slate-700 dark:text-white">giáo viên là "{{ user?.name }}"</strong> và học vào thứ này.
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
                <span v-if="s.room" class="text-slate-400 font-mono text-xs">({{ s.room }})</span>
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
            <template v-if="!isTeacher">
              <button v-if="seesAllPay && PAID_STATUSES.includes(s.status)" type="button" @click="openEditModal(s)"
                class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 cursor-pointer"
                title="Sửa giờ check-in / đơn giá">
                <Pencil class="h-4 w-4" />
              </button>
              <button v-if="s.status === 'not_checked_in' && s.substitute_teacher && !s.substitute_from_period" type="button"
                @click="clearSubstitute(s)"
                class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-3 py-1.5 text-xs font-medium cursor-pointer"
                title="Ca trở về giáo viên gốc">
                Bỏ dạy thay
              </button>
              <button v-if="s.status !== 'not_checked_in' && !s.on_leave" type="button" @click="resetShift(s)"
                class="rounded-lg p-1.5 text-amber-600 hover:bg-amber-50 cursor-pointer"
                title="Khôi phục về chưa check-in">
                <RotateCcw class="h-4 w-4" />
              </button>
              <button v-if="isOwner || !PAID_STATUSES.includes(s.status)" type="button" @click="deleteShift(s)"
                class="rounded-lg p-1.5 text-rose-500 hover:bg-rose-50 cursor-pointer" title="Xoá ca dạy">
                <Trash2 class="h-4 w-4" />
              </button>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- CARD 2: TỔNG CA DẠY THEO THÁNG -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-5">
      <!-- Tiêu đề + bộ lọc + xuất Excel -->
      <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-3">
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Tổng ca dạy theo tháng</h2>
          <p class="text-[11px] text-slate-500 mt-0.5">Các ca đã check-in, thù lao tính cho người thực dạy.</p>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-end gap-2">
          <label class="text-[11px] text-slate-500 font-medium sm:w-44">
            Tháng
            <input v-model="selectedMonth" type="month" @change="monthlyTeacher = ''; fetchData()"
              class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none" />
          </label>
          <label v-if="seesAllPay" class="text-[11px] text-slate-500 font-medium sm:w-52">
            Giáo viên
            <select v-model="monthlyTeacher"
              class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
              <option value="">Tất cả giáo viên</option>
              <option v-for="t in payByTeacher" :key="t.name" :value="t.name">{{ t.name }}</option>
            </select>
          </label>
          <button type="button" @click="exportMonthly"
            class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-800 shadow-2xs cursor-pointer dark:bg-slate-800 dark:border-slate-700 dark:text-white dark:hover:bg-slate-700">
            <Download class="h-4 w-4" /> Tải Excel
          </button>
        </div>
      </div>

      <!-- Tổng tiền + tổng theo từng giáo viên -->
      <div class="grid grid-cols-1 lg:grid-cols-[16rem_1fr] gap-3">
        <div class="rounded-xl border border-rose-100 bg-rose-50/60 p-4 flex flex-col justify-between gap-2 dark:bg-slate-900/80 dark:border-rose-500/30">
          <span class="flex items-center gap-1.5 text-xs font-bold text-slate-700 dark:text-white/80">
            <Wallet class="h-4 w-4 text-rose-500" />
            <template v-if="!seesAllPay">Thu nhập của bạn trong tháng</template>
            <template v-else-if="monthlyTeacher">Tiền trả {{ monthlyTeacher }}</template>
            <template v-else>Tổng tiền trả giáo viên</template>
          </span>
          <span class="text-2xl font-black text-rose-600 dark:text-rose-400">{{ formatCurrency(visibleMonthlyTotal) }}</span>
          <span class="text-[11px] text-slate-500 dark:text-white/60">{{ visibleMonthlyShifts.length }} ca đã tính công</span>
        </div>

        <!-- Chủ trung tâm / quản lý: thẻ từng giáo viên, bấm để lọc -->
        <div v-if="seesAllPay && payByTeacher.length > 0" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2">
          <button v-for="t in payByTeacher" :key="t.name" type="button" @click="toggleMonthlyTeacher(t.name)"
            class="text-left rounded-xl border px-3 py-2.5 transition-colors cursor-pointer dark:bg-slate-800 dark:border-slate-700"
            :class="monthlyTeacher === t.name ? 'border-rose-300 bg-rose-50 ring-1 ring-rose-200 dark:border-rose-500 dark:bg-rose-950/40' : 'border-slate-200 bg-white hover:bg-slate-50 dark:hover:bg-slate-700'"
            :title="monthlyTeacher === t.name ? 'Bấm để xem tất cả giáo viên' : 'Bấm để xem chi tiết ca của giáo viên này'">
            <div class="flex items-baseline justify-between gap-2">
              <span class="font-bold text-sm text-slate-900 dark:text-white truncate">{{ t.name }}</span>
              <span class="font-extrabold text-sm text-emerald-700 dark:text-emerald-400 shrink-0">{{ formatCurrency(t.total) }}</span>
            </div>
            <div class="mt-1 flex flex-wrap gap-x-2.5 text-[11px] text-slate-500 dark:text-white/60">
              <span>{{ t.shifts }} ca</span>
              <span v-if="t.substitute" class="text-blue-700 dark:text-blue-400">{{ t.substitute }} dạy thay</span>
              <span v-if="t.late" class="text-amber-600 dark:text-amber-400 font-semibold">{{ t.late }} lần trễ</span>
            </div>
          </button>
        </div>
      </div>

      <!-- Chi tiết từng ca -->
      <div>
        <div class="flex items-center justify-between gap-2 mb-2">
          <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wide dark:text-white/80">
            Chi tiết ca dạy<template v-if="monthlyTeacher"> · {{ monthlyTeacher }}</template>
          </h3>
          <button v-if="monthlyTeacher" type="button" @click="monthlyTeacher = ''"
            class="text-[11px] font-semibold text-rose-600 hover:underline cursor-pointer dark:text-rose-400">Xem tất cả giáo viên</button>
        </div>

        <p v-if="visibleMonthlyShifts.length === 0"
          class="rounded-xl border border-dashed border-slate-200 dark:border-slate-800 py-8 text-center text-xs sm:text-sm text-slate-500 dark:text-white/60">
          Chưa có ca dạy nào được check-in trong tháng này.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border border-slate-200">
          <table class="w-full text-left text-xs sm:text-sm">
            <thead class="bg-slate-50">
              <tr class="text-slate-500 text-[11px] uppercase font-semibold">
                <th class="py-2.5 px-3">Ngày</th>
                <th class="py-2.5 px-3">Người dạy</th>
                <th class="py-2.5 px-3">Ca dạy</th>
                <th class="py-2.5 px-3">Check-in</th>
                <th class="py-2.5 px-3 text-right">Thu nhập</th>
                <th v-if="isOwner" class="py-2.5 px-3 w-10"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="ms in visibleMonthlyShifts" :key="ms.id" class="hover:bg-slate-50/70 transition-colors">
                <td class="py-2.5 px-3 whitespace-nowrap">
                  <span class="font-semibold text-slate-800">{{ formatDateDisplay(ms.date) }}</span>
                  <span class="block text-[11px] text-slate-400">{{ weekdayOf(ms.date) }}</span>
                </td>
                <td class="py-2.5 px-3">
                  <span class="font-bold text-slate-900">{{ ms.earner }}</span>
                  <span v-if="ms.status === 'substitute'" class="block text-[11px] text-blue-700">dạy thay {{ ms.teacher_name }}</span>
                </td>
                <td class="py-2.5 px-3">
                  <span class="text-slate-800">{{ ms.shift_name }}</span>
                  <span class="block text-[11px]">
                    <span class="font-semibold text-blue-700">{{ ms.type }}</span>
                    <template v-if="ms.unit_rate !== undefined">
                      <span class="text-slate-400"> · {{ payModeLabel(ms) }} · {{ rateDetail(ms) }}</span>
                    </template>
                  </span>
                </td>
                <td class="py-2.5 px-3 whitespace-nowrap">
                  <span class="font-mono text-slate-700">{{ ms.checkin_time || '-' }}</span>
                  <span v-if="ms.minutes_late > 0"
                    class="ml-1.5 rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-bold text-amber-700">trễ {{ ms.minutes_late }}p</span>
                </td>
                <td class="py-2.5 px-3 text-right font-extrabold text-emerald-700 whitespace-nowrap">
                  {{ formatCurrency(ms.amount) }}
                  <!-- Ca bị giảm lương theo điểm danh học viên (nhóm vắng ≥ 50%, 1:1 vắng) -->
                  <span v-if="ms.pay_note" class="block text-[11px] font-semibold text-amber-700">{{ ms.pay_note }}</span>
                </td>
                <td v-if="isOwner" class="py-2.5 px-3 text-right">
                  <button type="button" @click="deleteShift(ms)"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 cursor-pointer"
                    title="Xoá ca (bỏ khỏi thu nhập, khôi phục được)">
                    <Trash2 class="h-4 w-4" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Ca đã xoá nháp: chủ trung tâm khôi phục -->
      <div v-if="seesAllPay && visibleDeletedShifts.length > 0" class="rounded-xl border border-slate-200">
        <button type="button" @click="showDeletedShifts = !showDeletedShifts"
          class="w-full flex items-center justify-between gap-2 px-3 py-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 cursor-pointer">
          <span>Ca đã xoá ({{ visibleDeletedShifts.length }}) · không tính vào thu nhập</span>
          <ChevronDown class="h-4 w-4 transition-transform" :class="showDeletedShifts ? 'rotate-180' : ''" />
        </button>
        <div v-if="showDeletedShifts" class="divide-y divide-slate-100 border-t border-slate-200">
          <div v-for="ds in visibleDeletedShifts" :key="ds.id"
            class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 px-3 py-2.5 text-xs">
            <div class="text-slate-500">
              <span class="font-semibold text-slate-700 line-through">{{ formatDateDisplay(ds.date) }} · {{ ds.earner }} · {{ ds.shift_name }}</span>
              <span v-if="ds.amount" class="ml-1.5 text-slate-400 line-through">{{ formatCurrency(ds.amount) }}</span>
              <span class="block text-[11px]">Xoá lúc {{ ds.deleted_at || '—' }}<template v-if="ds.deleted_by"> bởi {{ ds.deleted_by }}</template></span>
            </div>
            <button v-if="isOwner && ds.restorable" type="button" @click="restoreShift(ds)"
              class="self-start sm:self-auto inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 font-semibold text-emerald-700 hover:bg-emerald-100 cursor-pointer">
              <ArchiveRestore class="h-3.5 w-3.5" /> Khôi phục
            </button>
            <span v-else-if="!ds.restorable" class="text-[11px] text-slate-400 italic">Xoá trước khi có khôi phục</span>
          </div>
        </div>
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

    <!-- MODAL 4: SỬA CA ĐÃ CHECK-IN -->
    <div v-if="showEditModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">
            Sửa ca {{ selectedShift?.start_time }} – {{ selectedShift?.substitute_teacher || selectedShift?.teacher_name }}
          </h3>
          <button type="button" @click="showEditModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitEdit" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Giờ check-in</label>
            <input v-model="editForm.checkin_time" type="time" required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            <p class="mt-1 text-[11px] text-slate-500">Trễ quá 5 phút so với giờ vào ca ({{ selectedShift?.start_time }})
              sẽ tự tính là đi trễ.</p>
          </div>

          <div v-if="selectedShift?.pay_mode !== 'free'">
            <label class="block font-medium text-slate-700 mb-1">
              Đơn giá đã chốt {{ selectedShift?.pay_mode === 'per_student' ? '(VNĐ / HV)' : '(VNĐ / ca)' }}
            </label>
            <MoneyInput v-model="editForm.unit_rate"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Ghi chú</label>
            <textarea v-model="editForm.note" rows="2"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showEditModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 cursor-pointer">
              Hủy
            </button>
            <button type="submit" :disabled="savingEdit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-5 py-2 font-semibold text-white shadow-2xs cursor-pointer">
              {{ savingEdit ? 'Đang lưu...' : 'Lưu' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
