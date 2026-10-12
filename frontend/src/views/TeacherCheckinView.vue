<script setup>
import { ref, onMounted, computed } from 'vue';
import { teacherCheckinApi } from '../services/api';
import {
  QrCode,
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
  Copy,
  Check,
} from 'lucide-vue-next';

// Bộ lọc ngày hôm nay
const selectedDate = ref('2026-10-11');
const selectedCenter = ref('all');
const selectedShiftType = ref('all');
const selectedShiftTime = ref('all');
const selectedTeacher = ref('all');

// Bộ lọc tháng
const selectedMonth = ref('2026-10');

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
const rates = ref({
  one_on_one: 150000,
  group: 220000,
  trial: 100000,
});
const teachers = ref([]);
const centers = ref([]);

// Modals
const showQrModal = ref(false);
const showRatesModal = ref(false);
const showAbsenceModal = ref(false);
const selectedShift = ref(null);
const copyLinkSuccess = ref(false);

// Form Báo vắng / Dạy thay
const absenceForm = ref({
  shift_id: null,
  status: 'excused_absence', // 'skipped' | 'excused_absence' | 'substitute'
  substitute_teacher: '',
  reason: '',
});

// Form Đơn giá ca dạy
const ratesForm = ref({
  one_on_one: 150000,
  group: 220000,
  trial: 100000,
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
    rates.value = res.data.rates || {};
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
const performCheckin = async (shift, isLate = false) => {
  try {
    const now = new Date();
    const timeStr = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
    await teacherCheckinApi.checkin({
      shift_id: shift.id,
      checkin_time: timeStr,
      is_late: isLate,
      minutes_late: isLate ? 15 : 0,
    });
    await fetchData();
  } catch (err) {
    alert('Lỗi check-in: ' + (err.response?.data?.message || err.message));
  }
};

// Mở modal Báo vắng / Dạy thay
const openAbsenceModal = (shift) => {
  selectedShift.value = shift;
  absenceForm.value = {
    shift_id: shift.id,
    status: 'excused_absence',
    substitute_teacher: teachers.value[0] || 'Thầy Minh',
    reason: '',
  };
  showAbsenceModal.value = true;
};

const submitAbsence = async () => {
  try {
    await teacherCheckinApi.recordAbsence(absenceForm.value);
    showAbsenceModal.value = false;
    await fetchData();
  } catch (err) {
    alert('Lỗi ghi nhận: ' + (err.response?.data?.message || err.message));
  }
};

// Mở modal Đơn giá
const openRatesModal = () => {
  ratesForm.value = { ...rates.value };
  showRatesModal.value = true;
};

const submitRates = async () => {
  try {
    await teacherCheckinApi.updateRates(ratesForm.value);
    rates.value = { ...ratesForm.value };
    showRatesModal.value = false;
    alert('Đã cập nhật đơn giá ca dạy thành công!');
    await fetchData();
  } catch (err) {
    alert('Lỗi cập nhật: ' + (err.response?.data?.message || err.message));
  }
};

// Xuất file Excel tháng
const exportMonthly = () => {
  const url = teacherCheckinApi.getExportUrl(selectedMonth.value);
  window.open(url, '_blank');
};

// Copy link QR
const copyQrLink = () => {
  const link = window.location.origin + '/teacher-checkin?date=' + selectedDate.value;
  navigator.clipboard.writeText(link);
  copyLinkSuccess.value = true;
  setTimeout(() => {
    copyLinkSuccess.value = false;
  }, 2000);
};
</script>

<template>
  <div class="space-y-6 pb-12 max-w-7xl">
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
          <!-- Nút [ ⚏ QR check-in ] -->
          <button
            type="button"
            @click="showQrModal = true"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer shadow-2xs"
          >
            <QrCode class="h-3.5 w-3.5 text-slate-600" />
            <span>QR check-in</span>
          </button>

          <!-- Nút [ 📄 Đơn giá ca dạy ] -->
          <button
            type="button"
            @click="openRatesModal"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer shadow-2xs"
          >
            <FileText class="h-3.5 w-3.5 text-slate-600" />
            <span>Đơn giá ca dạy</span>
          </button>

          <!-- Nút [ 📑 Nghỉ phép / Dạy thay ] -->
          <button
            type="button"
            @click="openAbsenceModal(todayShifts[0] || { id: 1 })"
            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer shadow-2xs"
          >
            <Users class="h-3.5 w-3.5 text-slate-600" />
            <span>Nghỉ phép / Dạy thay</span>
          </button>
        </div>
      </div>

      <!-- Filter Bar: Ngày | Trung tâm | Loại ca | Ca dạy | Giáo viên (Khớp 100% hình tham khảo) -->
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 pt-1 border-t border-slate-100">
        <!-- Ngày -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Ngày</label>
          <div class="relative">
            <input
              v-model="selectedDate"
              type="date"
              @change="fetchData"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
            />
          </div>
        </div>

        <!-- Trung tâm -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Trung tâm</label>
          <select
            v-model="selectedCenter"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <!-- Loại ca -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Loại ca</label>
          <select
            v-model="selectedShiftType"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả loại ca</option>
            <option value="Ca 1-1">Ca 1-1</option>
            <option value="Ca nhóm">Ca nhóm</option>
            <option value="Dạy thử">Dạy thử / Test</option>
          </select>
        </div>

        <!-- Ca dạy -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Ca dạy</label>
          <select
            v-model="selectedShiftTime"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả ca dạy</option>
            <option value="morning">Ca sáng (08:00 - 11:30)</option>
            <option value="afternoon">Ca chiều (14:00 - 17:30)</option>
            <option value="evening">Ca tối (17:30 - 21:00)</option>
          </select>
        </div>

        <!-- Giáo viên -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1 font-medium">Giáo viên</label>
          <select
            v-model="selectedTeacher"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
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
      <div
        v-if="todayShifts.length === 0"
        class="rounded-xl border border-slate-200/80 bg-slate-50/60 p-4 text-xs text-slate-500 text-left"
      >
        Không có ca dạy nào trong ngày này. Ca dạy được tạo từ khung giờ của Lớp học (trang Khoá học) hoặc lịch 1:1 có gắn giáo viên trong thẻ học viên.
      </div>

      <!-- State: Khi có ca dạy trong ngày -->
      <div v-else class="divide-y divide-slate-100 pt-1 space-y-3">
        <div
          v-for="s in todayShifts"
          :key="s.id"
          class="pt-3 first:pt-0 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs sm:text-sm"
        >
          <div class="flex items-start gap-3">
            <div class="flex flex-col items-center justify-center rounded-xl bg-slate-100 px-3 py-2 text-slate-700 shrink-0 min-w-16">
              <Clock class="h-4 w-4 mb-0.5 text-slate-500" />
              <span class="font-bold text-xs">{{ s.start_time }}</span>
            </div>

            <div>
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-bold text-slate-900">{{ s.teacher_name }}</span>
                <span class="rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">
                  {{ s.type }}
                </span>
                <span class="text-slate-400 font-mono text-xs">({{ s.room }})</span>
              </div>
              <p class="text-xs text-slate-600 mt-0.5 font-medium">
                {{ s.class_name }} &bull; Thù lao: <strong class="text-emerald-700">{{ formatCurrency(s.rate) }}</strong>
              </p>
              <div class="flex items-center gap-2 mt-1">
                <span
                  v-if="s.status === 'checked_in'"
                  class="rounded-full bg-emerald-100 text-emerald-800 px-2 py-0.5 text-[11px] font-bold inline-flex items-center gap-1"
                >
                  <CheckCircle2 class="h-3 w-3" /> Đã check-in lúc {{ s.checkin_time }}
                </span>
                <span
                  v-else-if="s.status === 'late'"
                  class="rounded-full bg-amber-100 text-amber-800 px-2 py-0.5 text-[11px] font-bold inline-flex items-center gap-1"
                >
                  <AlertTriangle class="h-3 w-3" /> Đi trễ {{ s.minutes_late }}p ({{ s.checkin_time }})
                </span>
                <span
                  v-else-if="s.status === 'skipped'"
                  class="rounded-full bg-rose-100 text-rose-800 px-2 py-0.5 text-[11px] font-bold"
                >
                  Bỏ dạy không phép
                </span>
                <span
                  v-else-if="s.status === 'excused_absence'"
                  class="rounded-full bg-slate-200 text-slate-700 px-2 py-0.5 text-[11px] font-bold"
                >
                  Vắng có phép
                </span>
                <span
                  v-else-if="s.status === 'substitute'"
                  class="rounded-full bg-blue-100 text-blue-800 px-2 py-0.5 text-[11px] font-bold"
                >
                  Dạy thay bởi {{ s.substitute_teacher }}
                </span>
                <span v-else class="text-slate-400 text-xs">
                  Chưa check-in
                </span>
              </div>
            </div>
          </div>

          <!-- Nút Check-in nhanh -->
          <div class="flex items-center gap-2 shrink-0">
            <button
              v-if="s.status === 'not_checked_in'"
              type="button"
              @click="performCheckin(s, false)"
              class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 text-xs font-semibold shadow-2xs cursor-pointer"
            >
              Check-in đúng giờ
            </button>
            <button
              v-if="s.status === 'not_checked_in'"
              type="button"
              @click="performCheckin(s, true)"
              class="rounded-xl bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 text-xs font-semibold shadow-2xs cursor-pointer"
            >
              Check-in đi trễ
            </button>
            <button
              type="button"
              @click="openAbsenceModal(s)"
              class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-3 py-1.5 text-xs font-medium cursor-pointer"
            >
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
          <input
            v-model="selectedMonth"
            type="month"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          />
        </div>

        <button
          type="button"
          @click="exportMonthly"
          class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-5 py-2 text-xs sm:text-sm font-semibold text-slate-800 shadow-2xs transition-all cursor-pointer"
        >
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
              <th class="py-2.5 px-2">Giáo viên</th>
              <th class="py-2.5 px-2">Ca dạy</th>
              <th class="py-2.5 px-2">Loại</th>
              <th class="py-2.5 px-2 text-right">Thu nhập</th>
              <th class="py-2.5 px-2 text-center">Giờ check-in</th>
              <th class="py-2.5 px-2 text-center">Trễ</th>
              <th class="py-2.5 px-2 text-center">Bỏ ca dạy</th>
              <th class="py-2.5 pl-2 text-center">Vắng có phép</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="ms in monthlySummary.shifts"
              :key="ms.id"
              class="hover:bg-slate-50/70 transition-colors"
            >
              <td class="py-3 pr-2 font-mono text-xs text-slate-600">{{ formatDateDisplay(ms.date) }}</td>
              <td class="py-3 px-2 font-bold text-slate-900">{{ ms.teacher_name }}</td>
              <td class="py-3 px-2 text-xs text-slate-700">{{ ms.shift_name }}</td>
              <td class="py-3 px-2 text-xs font-semibold text-blue-700">{{ ms.type }}</td>
              <td class="py-3 px-2 text-right font-extrabold text-emerald-700">{{ formatCurrency(ms.rate) }}</td>
              <td class="py-3 px-2 text-center font-mono text-xs">{{ ms.checkin_time || '-' }}</td>
              <td class="py-3 px-2 text-center text-xs">
                <span v-if="ms.minutes_late > 0" class="text-amber-600 font-bold">{{ ms.minutes_late }}p</span>
                <span v-else class="text-slate-300">-</span>
              </td>
              <td class="py-3 px-2 text-center text-xs">
                <span v-if="ms.status === 'skipped'" class="text-rose-600 font-bold">Có</span>
                <span v-else class="text-slate-300">-</span>
              </td>
              <td class="py-3 pl-2 text-center text-xs">
                <span v-if="ms.status === 'excused_absence'" class="text-emerald-600 font-bold">Có</span>
                <span v-else class="text-slate-300">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- MODAL 1: QR CHECK-IN CHO GIÁO VIÊN -->
    <div
      v-if="showQrModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl space-y-4 text-center">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3 text-left">
          <div class="flex items-center gap-2">
            <QrCode class="h-5 w-5 text-indigo-600" />
            <h3 class="text-base font-bold text-slate-900">Mã QR Check-in Ca Dạy</h3>
          </div>
          <button
            type="button"
            @click="showQrModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <p class="text-xs text-slate-600 text-left">
          Giáo viên quét mã QR này bằng Camera điện thoại hoặc Zalo khi đến trung tâm để ghi nhận giờ vào ca dạy.
        </p>

        <!-- QR Code Visual Container -->
        <div class="rounded-2xl border-2 border-dashed border-slate-200 p-6 flex flex-col items-center justify-center bg-slate-50">
          <div class="bg-white p-4 rounded-xl shadow-xs border border-slate-100">
            <!-- Simulated QR SVG -->
            <svg class="h-44 w-44 text-slate-900" viewBox="0 0 100 100" fill="currentColor">
              <path d="M0,0 h30 v30 h-30 z M10,10 h10 v10 h-10 z M70,0 h30 v30 h-30 z M80,10 h10 v10 h-10 z M0,70 h30 v30 h-30 z M10,80 h10 v10 h-10 z M35,10 h10 v10 h-10 z M50,0 h10 v15 h-10 z M40,35 h20 v10 h-20 z M0,40 h15 v15 h-15 z M70,40 h10 v20 h-10 z M85,45 h15 v10 h-15 z M45,55 h10 v20 h-10 z M35,80 h15 v15 h-15 z M60,70 h15 v10 h-15 z M75,80 h25 v20 h-25 z" />
            </svg>
          </div>
          <span class="text-xs font-bold text-indigo-700 mt-3">KHOA ANH MUSIC &bull; HỆ THỐNG CHECK-IN</span>
          <span class="text-[11px] text-slate-400 font-mono mt-0.5">Ngày: {{ formatDateDisplay(selectedDate) }}</span>
        </div>

        <div class="flex items-center justify-between gap-2 pt-2">
          <button
            type="button"
            @click="copyQrLink"
            class="w-full inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-200 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer"
          >
            <Check v-if="copyLinkSuccess" class="h-4 w-4 text-emerald-600" />
            <Copy v-else class="h-4 w-4 text-slate-500" />
            <span>{{ copyLinkSuccess ? 'Đã chép link!' : 'Sao chép liên kết' }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL 2: ĐƠN GIÁ CA DẠY -->
    <div
      v-if="showRatesModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">Cấu hình đơn giá thù lao ca dạy</h3>
          <button
            type="button"
            @click="showRatesModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitRates" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Ca dạy 1 kèm 1 (60 phút)</label>
            <input
              v-model.number="ratesForm.one_on_one"
              type="number"
              min="0"
              step="5000"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 font-bold focus:border-rose-500 focus:outline-none"
            />
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Ca dạy nhóm (90 phút)</label>
            <input
              v-model.number="ratesForm.group"
              type="number"
              min="0"
              step="5000"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 font-bold focus:border-rose-500 focus:outline-none"
            />
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Ca dạy thử / Test năng khiếu</label>
            <input
              v-model.number="ratesForm.trial"
              type="number"
              min="0"
              step="5000"
              required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 font-bold focus:border-rose-500 focus:outline-none"
            />
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showRatesModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 cursor-pointer"
            >
              Hủy
            </button>
            <button
              type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs cursor-pointer"
            >
              Lưu đơn giá
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 3: BÁO VẮNG / NGHỈ PHÉP / DẠY THAY -->
    <div
      v-if="showAbsenceModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">Báo Vắng / Nghỉ Phép / Dạy Thay</h3>
          <button
            type="button"
            @click="showAbsenceModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitAbsence" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Loại ghi nhận</label>
            <select
              v-model="absenceForm.status"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            >
              <option value="excused_absence">Vắng có phép (Đã báo trước)</option>
              <option value="skipped">Bỏ ca dạy không phép</option>
              <option value="substitute">Dạy thay bởi giáo viên khác</option>
            </select>
          </div>

          <div v-if="absenceForm.status === 'substitute'">
            <label class="block font-medium text-slate-700 mb-1">Giáo viên nhận dạy thay</label>
            <select
              v-model="absenceForm.substitute_teacher"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            >
              <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Lý do nghỉ / ghi chú</label>
            <textarea
              v-model="absenceForm.reason"
              rows="2"
              placeholder="VD: Giáo viên bị ốm hoặc bận việc gia đình..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            ></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showAbsenceModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 cursor-pointer"
            >
              Hủy
            </button>
            <button
              type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs cursor-pointer"
            >
              Xác nhận
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
