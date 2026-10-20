<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { parentPortalApi } from '../services/api';
import { notify } from '../services/notify';
import {
  ShieldCheck,
  Lock,
  Calendar,
  Clock,
  BookOpen,
  Award,
  AlertCircle,
  CheckCircle2,
  XCircle,
  HelpCircle,
  Phone,
  MapPin,
  LogOut,
  Send,
  User,
  GraduationCap,
  Sparkles,
  ChevronRight,
  Package,
  Receipt,
  Flame,
  Smile,
  AlertTriangle,
  Info
} from 'lucide-vue-next';

const route = useRoute();
const studentId = computed(() => route.params.id);

// Trạng thái chung
const isLoading = ref(true);
const publicInfo = ref(null);
const loadError = ref(null);

// Trạng thái xác thực 4 số cuối SĐT
const isVerified = ref(false);
const isVerifying = ref(false);
const verifyError = ref('');
const digits = ref(['', '', '', '']);
const digitInputs = ref([]);

// Dữ liệu học tập sau khi xác thực
const portalData = ref(null);
const activeTab = ref('sessions'); // 'sessions' | 'supplies' | 'tuition'
const sessionFilter = ref('all'); // 'all' | 'completed' | 'absent' | 'upcoming'

// Modal Xin nghỉ phép
const showLeaveModal = ref(false);
const leaveForm = ref({
  date: new Date().toISOString().split('T')[0],
  reason: '',
});
const isSubmittingLeave = ref(false);

// Tải thông tin sơ bộ công khai
const fetchPublicInfo = async () => {
  try {
    isLoading.value = true;
    loadError.value = null;
    const res = await parentPortalApi.getInfo(studentId.value);
    publicInfo.value = res.data;

    // Kiểm tra xem phụ huynh đã xác thực trong phiên làm việc này chưa
    const savedPin = sessionStorage.getItem(`portal_pin_${studentId.value}`);
    if (savedPin && savedPin.length === 4) {
      // Tự động khôi phục xác thực
      await submitVerify(savedPin, true);
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || 'Không tìm thấy thông tin học viên hoặc đường dẫn không hợp lệ.';
  } finally {
    isLoading.value = false;
    nextTick(() => {
      focusDigit(0);
    });
  }
};

// Quản lý nhập 4 ô PIN
const focusDigit = (index) => {
  if (digitInputs.value[index]) {
    digitInputs.value[index].focus();
  }
};

const handleDigitInput = (index, event) => {
  const val = event.target.value.replace(/[^0-9]/g, '');
  if (val.length > 1) {
    // Trường hợp dán chuỗi 4 số (paste)
    const chars = val.slice(0, 4).split('');
    chars.forEach((c, i) => {
      if (i < 4) digits.value[i] = c;
    });
    if (chars.length === 4) {
      submitVerify(digits.value.join(''));
    } else {
      focusDigit(Math.min(chars.length, 3));
    }
    return;
  }

  digits.value[index] = val;
  verifyError.value = '';

  if (val && index < 3) {
    focusDigit(index + 1);
  }

  // Tự động gửi khi đã nhập đủ 4 số
  const fullPin = digits.value.join('');
  if (fullPin.length === 4 && !digits.value.includes('')) {
    submitVerify(fullPin);
  }
};

const handleDigitKeydown = (index, event) => {
  if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
    focusDigit(index - 1);
  }
};

const handlePaste = (event) => {
  event.preventDefault();
  const text = (event.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
  if (!text) return;
  const chars = text.slice(0, 4).split('');
  chars.forEach((c, i) => {
    if (i < 4) digits.value[i] = c;
  });
  if (chars.length === 4) {
    submitVerify(digits.value.join(''));
  } else {
    focusDigit(Math.min(chars.length, 3));
  }
};

// Gửi xác thực 4 số cuối
const submitVerify = async (pin, isAuto = false) => {
  const code = pin || digits.value.join('');
  if (code.length !== 4) {
    verifyError.value = 'Vui lòng nhập đủ 4 số cuối số điện thoại.';
    return;
  }

  try {
    isVerifying.value = true;
    verifyError.value = '';
    const res = await parentPortalApi.verify(studentId.value, code);
    portalData.value = res.data;
    isVerified.value = true;
    sessionStorage.setItem(`portal_pin_${studentId.value}`, code);
    if (!isAuto) {
      notify.success(`Chào mừng phụ huynh học viên ${res.data.student?.name}!`);
    }
  } catch (err) {
    sessionStorage.removeItem(`portal_pin_${studentId.value}`);
    isVerified.value = false;
    portalData.value = null;
    verifyError.value = err.response?.data?.message || '4 số cuối số điện thoại không chính xác. Vui lòng kiểm tra lại.';
    digits.value = ['', '', '', ''];
    nextTick(() => focusDigit(0));
  } finally {
    isVerifying.value = false;
  }
};

// Khóa lại phiên làm việc
const lockPortal = () => {
  sessionStorage.removeItem(`portal_pin_${studentId.value}`);
  isVerified.value = false;
  portalData.value = null;
  digits.value = ['', '', '', ''];
  nextTick(() => focusDigit(0));
};

// Lọc danh sách buổi học
const filteredSessions = computed(() => {
  if (!portalData.value?.sessions) return [];
  const list = portalData.value.sessions;
  if (sessionFilter.value === 'completed') {
    return list.filter((s) => s.is_completed);
  }
  if (sessionFilter.value === 'absent') {
    return list.filter((s) => s.is_absent);
  }
  if (sessionFilter.value === 'upcoming') {
    return list.filter((s) => s.status === 'upcoming');
  }
  return list;
});

// Gửi đơn xin nghỉ phép
const submitLeaveRequest = async () => {
  const pin = sessionStorage.getItem(`portal_pin_${studentId.value}`) || digits.value.join('');
  if (!leaveForm.value.reason.trim()) {
    notify.error('Vui lòng nhập lý do xin nghỉ phép');
    return;
  }

  try {
    isSubmittingLeave.value = true;
    const res = await parentPortalApi.requestLeave(studentId.value, {
      phone_last4: pin,
      date: leaveForm.value.date,
      reason: leaveForm.value.reason,
    });
    notify.success(res.data.message || 'Đã gửi đơn xin nghỉ phép thành công!');
    showLeaveModal.value = false;
    leaveForm.value.reason = '';
    // Tải lại dữ liệu portal
    await submitVerify(pin, true);
  } catch (err) {
    notify.apiError(err, 'Lỗi khi gửi đơn xin nghỉ');
  } finally {
    isSubmittingLeave.value = false;
  }
};

onMounted(() => {
  fetchPublicInfo();
});
</script>

<template>
  <div class="min-h-screen bg-gradient-to-b from-slate-50 via-white to-slate-100 text-slate-800 font-sans antialiased flex flex-col justify-between">
    <!-- Top Header Navigation -->
    <header class="bg-white/95 backdrop-blur-md border-b border-slate-200/80 sticky top-0 z-40 shadow-xs">
      <div class="max-w-5xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-600 flex items-center justify-center text-white font-bold shadow-md shadow-indigo-100 shrink-0">
            <GraduationCap class="h-6 w-6" />
          </div>
          <div>
            <h1 class="text-sm sm:text-base font-extrabold text-slate-900 tracking-tight leading-tight">
              {{ publicInfo?.brand?.brand_name || 'EduCRM SIS' }}
            </h1>
            <p class="text-[11px] text-slate-500 font-medium flex items-center gap-1.5">
              <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
              Cổng Thông Tin Học Viên & Phụ Huynh
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <!-- Hotline hỗ trợ -->
          <a
            v-if="publicInfo?.brand?.phone"
            :href="`tel:${publicInfo.brand.phone}`"
            class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition-colors"
          >
            <Phone class="h-3.5 w-3.5 text-indigo-600" />
            <span>Hotline: {{ publicInfo.brand.phone }}</span>
          </a>

          <!-- Nút khóa lại khi đã xác thực -->
          <button
            v-if="isVerified"
            @click="lockPortal"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 hover:bg-rose-50 hover:text-rose-600 text-slate-600 text-xs font-semibold transition-colors"
            title="Khóa lại để bảo mật"
          >
            <LogOut class="h-3.5 w-3.5" />
            <span class="hidden sm:inline">Khóa lại</span>
          </button>
        </div>
      </div>
    </header>

    <!-- Main Content Area -->
    <main class="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 py-6 sm:py-10">
      <!-- Loading Skeleton -->
      <div v-if="isLoading" class="flex flex-col items-center justify-center py-20 space-y-4">
        <div class="h-12 w-12 rounded-full border-4 border-indigo-200 border-t-indigo-600 animate-spin"></div>
        <p class="text-sm font-medium text-slate-500 animate-pulse">Đang kết nối cổng thông tin học viên...</p>
      </div>

      <!-- Lỗi không tìm thấy học viên -->
      <div v-else-if="loadError" class="max-w-md mx-auto my-12 bg-white rounded-3xl p-8 border border-rose-100 shadow-xl shadow-rose-50/50 text-center space-y-4">
        <div class="h-16 w-16 mx-auto rounded-2xl bg-rose-50 text-rose-500 flex items-center justify-center">
          <AlertCircle class="h-8 w-8" />
        </div>
        <h2 class="text-lg font-bold text-slate-900">Không tìm thấy thông tin</h2>
        <p class="text-xs text-slate-600 leading-relaxed">{{ loadError }}</p>
        <p class="text-xs text-slate-400">Vui lòng kiểm tra lại liên kết hoặc liên hệ trung tâm để nhận lại link chính xác.</p>
      </div>

      <!-- MÀN HÌNH 1: XÁC THỰC 4 SỐ CUỐI SĐT (KHI CHƯA XÁC THỰC) -->
      <div v-else-if="!isVerified" class="max-w-md mx-auto my-4 sm:my-8">
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/90 shadow-xl shadow-slate-100 space-y-6">
          <!-- Icon & Tiêu đề -->
          <div class="text-center space-y-2">
            <div class="h-14 w-14 mx-auto rounded-2xl bg-gradient-to-tr from-indigo-50 to-violet-50 text-indigo-600 flex items-center justify-center border border-indigo-100 shadow-inner">
              <ShieldCheck class="h-7 w-7" />
            </div>
            <h2 class="text-lg sm:text-xl font-extrabold text-slate-900 tracking-tight">
              Xác thực thông tin phụ huynh
            </h2>
            <p class="text-xs text-slate-500 leading-relaxed">
              Để bảo mật lịch sử học tập của con, vui lòng nhập <strong class="text-indigo-600 font-semibold">4 số cuối</strong> số điện thoại phụ huynh đã đăng ký.
            </p>
          </div>

          <!-- Thông tin sơ bộ học viên -->
          <div class="bg-gradient-to-br from-slate-50 to-indigo-50/30 rounded-2xl p-4 border border-indigo-100/60 space-y-2 text-xs">
            <div class="flex items-center justify-between">
              <span class="text-slate-500">Học viên:</span>
              <span class="font-bold text-slate-900 text-sm">{{ publicInfo?.student_name }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-slate-500">Mã học viên:</span>
              <span class="font-mono font-semibold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded-md">{{ publicInfo?.student_code }}</span>
            </div>
            <div v-if="publicInfo?.course_name" class="flex items-center justify-between">
              <span class="text-slate-500">Khóa học:</span>
              <span class="font-medium text-slate-800">{{ publicInfo?.course_name }}</span>
            </div>
            <div v-if="publicInfo?.center" class="flex items-center justify-between">
              <span class="text-slate-500">Cơ sở:</span>
              <span class="text-slate-700">{{ publicInfo?.center }}</span>
            </div>
          </div>

          <!-- Ô nhập 4 số PIN -->
          <div class="space-y-4">
            <label class="block text-center text-xs font-bold text-slate-700 uppercase tracking-wider">
              Nhập 4 số cuối số điện thoại
            </label>

            <div class="flex items-center justify-center gap-3" @paste="handlePaste">
              <input
                v-for="(_, index) in digits"
                :key="index"
                ref="digitInputs"
                v-model="digits[index]"
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="1"
                @input="handleDigitInput(index, $event)"
                @keydown="handleDigitKeydown(index, $event)"
                class="w-13 h-14 text-center text-2xl font-extrabold text-slate-900 bg-slate-50 border-2 rounded-2xl focus:bg-white focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 outline-none transition-all duration-200 select-all"
                :class="verifyError ? 'border-rose-400 bg-rose-50/30' : 'border-slate-200'"
              />
            </div>

            <!-- Báo lỗi nếu sai -->
            <div v-if="verifyError" class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-start gap-2 animate-shake">
              <AlertCircle class="h-4 w-4 shrink-0 mt-0.5" />
              <span>{{ verifyError }}</span>
            </div>

            <!-- Nút gửi xác thực -->
            <button
              type="button"
              @click="submitVerify()"
              :disabled="isVerifying"
              class="w-full py-3.5 px-4 rounded-2xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-700 hover:to-violet-700 text-white font-bold text-sm shadow-lg shadow-indigo-200 transition-all active:scale-[0.99] disabled:opacity-50 flex items-center justify-center gap-2"
            >
              <div v-if="isVerifying" class="h-4 w-4 rounded-full border-2 border-white/30 border-t-white animate-spin"></div>
              <ShieldCheck v-else class="h-4 w-4" />
              <span>{{ isVerifying ? 'Đang xác thực...' : 'Tra cứu thông tin học tập' }}</span>
            </button>
          </div>

          <!-- Trợ giúp nếu quên số -->
          <div class="border-t border-slate-100 pt-4 text-center">
            <p class="text-[11px] text-slate-400">
              Quên số điện thoại hoặc cần hỗ trợ? Vui lòng liên hệ trung tâm qua hotline:
              <a v-if="publicInfo?.brand?.phone" :href="`tel:${publicInfo.brand.phone}`" class="text-indigo-600 font-bold hover:underline ml-1">
                {{ publicInfo.brand.phone }}
              </a>
            </p>
          </div>
        </div>
      </div>

      <!-- MÀN HÌNH 2: CỔNG THÔNG TIN PHỤ HUYNH (SAU KHI XÁC THỰC THÀNH CÔNG) -->
      <div v-else class="space-y-6">
        <!-- 1. Hồ sơ học viên (Profile Banner) -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/90 shadow-xs relative overflow-hidden">
          <div class="absolute -right-8 -bottom-8 w-44 h-44 rounded-full bg-gradient-to-br from-indigo-100/40 to-violet-100/30 blur-2xl pointer-events-none"></div>

          <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 relative z-10">
            <!-- Avatar & Thông tin cơ bản -->
            <div class="flex items-center gap-4 sm:gap-6">
              <!-- Avatar -->
              <div class="relative shrink-0">
                <img
                  v-if="portalData.student?.avatar"
                  :src="portalData.student.avatar"
                  alt="Ảnh học viên"
                  class="h-20 w-20 sm:h-24 sm:w-24 rounded-2xl object-cover border-2 border-indigo-100 shadow-md"
                />
                <div
                  v-else
                  class="h-20 w-20 sm:h-24 sm:w-24 rounded-2xl bg-gradient-to-tr from-indigo-500 to-violet-600 text-white flex items-center justify-center font-extrabold text-2xl sm:text-3xl shadow-md uppercase"
                >
                  {{ portalData.student?.name?.charAt(0) || 'H' }}
                </div>

                <!-- Chuyên cần badge -->
                <div
                  v-if="portalData.student?.attendance_status === 'excellent'"
                  class="absolute -bottom-1 -right-1 bg-amber-400 text-white p-1 rounded-full shadow-md text-xs"
                  title="Chuyên cần xuất sắc"
                >
                  🔥
                </div>
              </div>

              <!-- Name & Details -->
              <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                  <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                    {{ portalData.student?.name }}
                  </h2>
                  <span class="font-mono text-xs font-bold text-indigo-700 bg-indigo-50 px-2.5 py-0.5 rounded-lg border border-indigo-100">
                    {{ portalData.student?.code }}
                  </span>
                  <span
                    class="text-xs px-2.5 py-0.5 rounded-full font-bold"
                    :class="portalData.student?.status === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600'"
                  >
                    {{ portalData.student?.status === 'active' ? 'Đang học' : 'Tạm dừng' }}
                  </span>
                </div>

                <p class="text-xs text-slate-600 font-medium">
                  Khóa học: <strong class="text-indigo-600">{{ portalData.student?.course?.name || 'Chưa phân khóa' }}</strong>
                  <span v-if="portalData.student?.assigned_class"> &bull; Lớp: {{ portalData.student.assigned_class }}</span>
                </p>

                <p class="text-xs text-slate-500 flex flex-wrap items-center gap-x-3 gap-y-1">
                  <span>Phụ huynh: <strong class="text-slate-700">{{ portalData.student?.parent_name }}</strong></span>
                  <span>SĐT: <strong class="text-slate-700">{{ portalData.student?.masked_phone }}</strong></span>
                  <span v-if="portalData.student?.teacher">GV: <strong class="text-slate-700">{{ portalData.student.teacher }}</strong></span>
                </p>

                <!-- Dãy 5 chấm chuyên cần gần nhất -->
                <div class="flex items-center gap-2 pt-1 text-xs">
                  <span class="text-slate-400 text-[11px]">5 buổi gần nhất:</span>
                  <div class="flex items-center gap-1.5">
                    <span
                      v-for="(dot, idx) in portalData.student?.attendance_dots || []"
                      :key="idx"
                      class="h-3 w-3 rounded-full border border-black/10 transition-transform"
                      :class="{
                        'bg-emerald-500': dot.color === 'green',
                        'bg-rose-500': dot.color === 'red',
                        'bg-amber-400': dot.color === 'yellow',
                        'bg-slate-200': dot.color === 'gray'
                      }"
                      :title="dot.tooltip"
                    ></span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Action: Nút Xin nghỉ phép -->
            <div class="w-full sm:w-auto shrink-0 flex flex-col gap-2">
              <button
                type="button"
                @click="showLeaveModal = true"
                class="w-full sm:w-auto px-4 py-2.5 rounded-2xl bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-200 text-xs font-bold transition-all shadow-xs flex items-center justify-center gap-2"
              >
                <Calendar class="h-4 w-4 text-amber-600" />
                <span>Xin nghỉ học có phép</span>
              </button>
            </div>
          </div>
        </div>

        <!-- 2. Thống kê tiến độ học tập (Progress & KPI Strip) -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
          <!-- Tổng số buổi -->
          <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-xs space-y-1">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Tổng số buổi</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl sm:text-3xl font-black text-slate-900">{{ portalData.stats?.total_sessions }}</span>
              <span class="text-xs text-slate-500 font-semibold">buổi</span>
            </div>
            <p class="text-[11px] text-slate-400">Thời lượng khóa học</p>
          </div>

          <!-- Đã học (Có mặt) -->
          <div class="bg-white rounded-2xl p-4 sm:p-5 border border-emerald-100 bg-emerald-50/20 shadow-xs space-y-1">
            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-600">Đã học (Có mặt)</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl sm:text-3xl font-black text-emerald-600">{{ portalData.stats?.completed_sessions }}</span>
              <span class="text-xs text-emerald-600 font-semibold">buổi</span>
            </div>
            <p class="text-[11px] text-emerald-600/70 font-medium">Đã điểm danh có mặt</p>
          </div>

          <!-- Số buổi còn lại -->
          <div class="bg-white rounded-2xl p-4 sm:p-5 border border-indigo-100 bg-indigo-50/20 shadow-xs space-y-1">
            <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-600">Số buổi còn lại</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl sm:text-3xl font-black text-indigo-600">{{ portalData.stats?.remaining_sessions }}</span>
              <span class="text-xs text-indigo-600 font-semibold">buổi</span>
            </div>
            <p class="text-[11px] text-indigo-600/70 font-medium">Buổi học chưa sử dụng</p>
          </div>

          <!-- Số buổi vắng -->
          <div class="bg-white rounded-2xl p-4 sm:p-5 border border-amber-100 bg-amber-50/20 shadow-xs space-y-1">
            <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Số buổi vắng</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl sm:text-3xl font-black text-amber-700">{{ portalData.stats?.total_absences }}</span>
              <span class="text-xs text-amber-700 font-semibold">buổi</span>
            </div>
            <p class="text-[11px] text-amber-700/70 font-medium">
              Có phép: {{ portalData.stats?.absent_with_leave }} &bull; K.phép: {{ portalData.stats?.absent_without_leave }}
            </p>
          </div>
        </div>

        <!-- Thanh tiến độ tổng quan lớn -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-xs space-y-3">
          <div class="flex items-center justify-between text-xs">
            <span class="font-bold text-slate-700">Tiến độ hoàn thành khóa học</span>
            <span class="font-extrabold text-indigo-600 text-sm">
              {{ portalData.stats?.completed_sessions }} / {{ portalData.stats?.total_sessions }} buổi ({{ portalData.stats?.progress_percent }}%)
            </span>
          </div>

          <!-- Progress Bar -->
          <div class="h-3.5 w-full bg-slate-100 rounded-full overflow-hidden p-0.5 border border-slate-200/60 flex">
            <div
              class="h-full bg-gradient-to-r from-emerald-500 via-teal-500 to-indigo-600 rounded-full transition-all duration-700 shadow-xs"
              :style="{ width: `${Math.min(portalData.stats?.progress_percent || 0, 100)}%` }"
            ></div>
          </div>

          <div class="flex items-center justify-between text-[11px] text-slate-400">
            <span>Bắt đầu</span>
            <span v-if="portalData.stats?.remaining_sessions <= 3" class="text-rose-600 font-bold animate-pulse">
              ⚠️ Khóa học sắp kết thúc (còn {{ portalData.stats?.remaining_sessions }} buổi)
            </span>
            <span v-else>Còn {{ portalData.stats?.remaining_sessions }} buổi học</span>
            <span>Hoàn thành khóa</span>
          </div>
        </div>

        <!-- 3. Navigation Tabs: Buổi học / Học cụ / Học phí -->
        <div class="flex items-center gap-2 border-b border-slate-200 pb-2">
          <button
            type="button"
            @click="activeTab = 'sessions'"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2"
            :class="activeTab === 'sessions' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-100' : 'text-slate-600 hover:bg-slate-100'"
          >
            <BookOpen class="h-4 w-4" />
            <span>Lịch sử & Kế hoạch buổi học ({{ portalData.sessions?.length || 0 }})</span>
          </button>

          <button
            type="button"
            @click="activeTab = 'supplies'"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2"
            :class="activeTab === 'supplies' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-100' : 'text-slate-600 hover:bg-slate-100'"
          >
            <Package class="h-4 w-4" />
            <span>Học cụ đã nhận ({{ portalData.supplies?.length || 0 }})</span>
          </button>

          <button
            type="button"
            @click="activeTab = 'tuition'"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2"
            :class="activeTab === 'tuition' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-100' : 'text-slate-600 hover:bg-slate-100'"
          >
            <Receipt class="h-4 w-4" />
            <span>Học phí & Hóa đơn</span>
          </button>
        </div>

        <!-- TAB 1: DANH SÁCH TOÀN BỘ CÁC BUỔI HỌC (ĐÃ HỌC, VẮNG, CHƯA HỌC) -->
        <div v-if="activeTab === 'sessions'" class="space-y-4">
          <!-- Bộ lọc trạng thái buổi học -->
          <div class="flex flex-wrap items-center justify-between gap-3 bg-white p-3 rounded-2xl border border-slate-200/80">
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
              <span class="text-slate-400 text-[11px] font-semibold mr-1">Bộ lọc:</span>
              <button
                type="button"
                @click="sessionFilter = 'all'"
                class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                :class="sessionFilter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
              >
                Tất cả ({{ portalData.sessions?.length }})
              </button>
              <button
                type="button"
                @click="sessionFilter = 'completed'"
                class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                :class="sessionFilter === 'completed' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'"
              >
                Đã học ({{ portalData.stats?.completed_sessions }})
              </button>
              <button
                type="button"
                @click="sessionFilter = 'absent'"
                class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                :class="sessionFilter === 'absent' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-800 hover:bg-amber-100'"
              >
                Vắng ({{ portalData.stats?.total_absences }})
              </button>
              <button
                type="button"
                @click="sessionFilter = 'upcoming'"
                class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                :class="sessionFilter === 'upcoming' ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
              >
                Chưa học ({{ portalData.stats?.remaining_sessions }})
              </button>
            </div>

            <p class="text-[11px] text-slate-400 italic">
              * Hệ thống tự động cập nhật sau mỗi buổi điểm danh của giáo viên.
            </p>
          </div>

          <!-- Danh sách card các buổi học -->
          <div class="space-y-3">
            <div
              v-for="s in filteredSessions"
              :key="s.session_number"
              class="bg-white rounded-2xl p-4 sm:p-5 border transition-all duration-200"
              :class="{
                'border-emerald-200 bg-emerald-50/10 shadow-xs': s.status === 'present',
                'border-amber-200 bg-amber-50/10 shadow-xs': s.status === 'absent_with_leave',
                'border-rose-200 bg-rose-50/10 shadow-xs': s.status === 'absent_without_leave',
                'border-slate-200/80 bg-white opacity-85': s.status === 'upcoming',
              }"
            >
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <!-- Session Badge & Details -->
                <div class="flex items-start gap-3.5">
                  <!-- Số thứ tự buổi -->
                  <div
                    class="h-10 w-10 sm:h-11 sm:w-11 rounded-2xl flex items-center justify-center font-black text-sm shrink-0 border"
                    :class="{
                      'bg-emerald-500 text-white border-emerald-600': s.status === 'present',
                      'bg-amber-500 text-white border-amber-600': s.status === 'absent_with_leave',
                      'bg-rose-500 text-white border-rose-600': s.status === 'absent_without_leave',
                      'bg-slate-100 text-slate-400 border-slate-200': s.status === 'upcoming',
                    }"
                  >
                    #{{ s.session_number }}
                  </div>

                  <div class="space-y-1">
                    <div class="flex items-center gap-2">
                      <h4 class="text-sm font-extrabold text-slate-900">
                        Buổi học thứ {{ s.session_number }}
                      </h4>

                      <!-- Status Badge -->
                      <span
                        class="px-2.5 py-0.5 rounded-full text-xs font-bold flex items-center gap-1"
                        :class="{
                          'bg-emerald-100 text-emerald-800': s.status === 'present',
                          'bg-amber-100 text-amber-800': s.status === 'absent_with_leave',
                          'bg-rose-100 text-rose-800': s.status === 'absent_without_leave',
                          'bg-slate-100 text-slate-500': s.status === 'upcoming',
                        }"
                      >
                        <CheckCircle2 v-if="s.status === 'present'" class="h-3 w-3" />
                        <AlertTriangle v-else-if="s.is_absent" class="h-3 w-3" />
                        <Clock v-else class="h-3 w-3" />
                        <span>{{ s.status_label }}</span>
                      </span>
                    </div>

                    <!-- Ngày giờ điểm danh -->
                    <div class="text-xs text-slate-500 flex flex-wrap items-center gap-x-3 gap-y-1">
                      <span v-if="s.date" class="flex items-center gap-1">
                        <Calendar class="h-3.5 w-3.5 text-slate-400" />
                        <span>{{ s.full_datetime || s.date }}</span>
                      </span>
                      <!-- Buổi chưa học: ngày dự kiến theo lịch học hằng tuần -->
                      <span v-else-if="s.planned_date" class="flex items-center gap-1 text-indigo-600 font-medium" title="Ngày dự kiến theo lịch học, có thể thay đổi khi nghỉ / học bù">
                        <Calendar class="h-3.5 w-3.5 text-indigo-400" />
                        <span>Dự kiến: {{ s.planned_weekday }}, {{ s.planned_date }} · {{ s.planned_time }}</span>
                      </span>
                      <span v-else class="text-slate-400 italic">
                        Chưa diễn ra
                      </span>
                      <span v-if="s.planned_teacher" class="flex items-center gap-1">
                        <User class="h-3.5 w-3.5 text-slate-400" />
                        <span>GV: {{ s.planned_teacher }}</span>
                      </span>

                      <span v-if="s.marked_by" class="flex items-center gap-1">
                        <User class="h-3.5 w-3.5 text-slate-400" />
                        <span>Ghi nhận: {{ s.marked_by }}</span>
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Nhận xét của giáo viên -->
                <div class="sm:max-w-md w-full text-xs bg-slate-50 p-3 rounded-xl border border-slate-100">
                  <span class="font-bold text-slate-700 block mb-0.5">Nhận xét buổi học:</span>
                  <p class="text-slate-600 italic leading-relaxed">
                    "{{ s.note || 'Hoàn thành buổi học theo giáo trình.' }}"
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- TAB 2: HỌC CỤ ĐÃ NHẬN -->
        <div v-else-if="activeTab === 'supplies'" class="bg-white rounded-2xl p-6 border border-slate-200/90 shadow-xs space-y-4">
          <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
            <Package class="h-4 w-4 text-indigo-600" />
            <span>Danh sách học cụ và giáo trình con đã nhận</span>
          </h3>

          <div v-if="portalData.supplies && portalData.supplies.length > 0" class="divide-y divide-slate-100">
            <div
              v-for="(item, idx) in portalData.supplies"
              :key="idx"
              class="py-3 flex items-center justify-between text-xs"
            >
              <div class="flex items-center gap-3">
                <span class="h-8 w-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                  {{ idx + 1 }}
                </span>
                <div>
                  <h5 class="font-bold text-slate-900">{{ item.name }}</h5>
                  <p class="text-slate-400 text-[11px]">Đã bàn giao cho học viên</p>
                </div>
              </div>

              <div class="text-right">
                <span class="font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg">
                  Số lượng: {{ item.quantity || 1 }}
                </span>
              </div>
            </div>
          </div>

          <div v-else class="text-center py-8 text-xs text-slate-400 space-y-2">
            <Package class="h-8 w-8 mx-auto text-slate-300" />
            <p>Chưa có học cụ nào được ghi nhận giao cho học viên.</p>
          </div>
        </div>

        <!-- TAB 3: HỌC PHÍ & HÓA ĐƠN -->
        <div v-else-if="activeTab === 'tuition'" class="bg-white rounded-2xl p-6 border border-slate-200/90 shadow-xs space-y-4">
          <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
            <Receipt class="h-4 w-4 text-indigo-600" />
            <span>Lịch sử học phí & Hóa đơn thu</span>
          </h3>

          <div v-if="portalData.invoices && portalData.invoices.length > 0" class="divide-y divide-slate-100">
            <div
              v-for="inv in portalData.invoices"
              :key="inv.id"
              class="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs"
            >
              <div>
                <div class="flex items-center gap-2">
                  <span class="font-mono font-bold text-slate-900">{{ inv.code }}</span>
                  <span
                    class="px-2 py-0.5 rounded-full font-bold text-[11px]"
                    :class="inv.status === 'paid' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200'"
                  >
                    {{ inv.status === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán' }}
                  </span>
                </div>
                <p class="text-slate-400 text-[11px] mt-0.5">
                  Ngày lập: {{ inv.created_at }} &bull; Hình thức: {{ inv.payment_method || 'Chuyển khoản' }}
                </p>
              </div>

              <div class="text-right">
                <span class="text-base font-black text-slate-900">
                  {{ Number(inv.amount || 0).toLocaleString('vi-VN') }} đ
                </span>
              </div>
            </div>
          </div>

          <div v-else class="text-center py-8 text-xs text-slate-400 space-y-2">
            <Receipt class="h-8 w-8 mx-auto text-slate-300" />
            <p>Chưa có hóa đơn học phí nào được lưu trữ.</p>
          </div>
        </div>

        <!-- Footer / Hỗ trợ trung tâm -->
        <div class="bg-gradient-to-br from-indigo-50 to-violet-50 rounded-2xl p-5 border border-indigo-100 text-xs text-slate-600 space-y-2">
          <div class="flex items-center gap-2 text-indigo-900 font-bold">
            <Info class="h-4 w-4 text-indigo-600" />
            <span>Thông tin liên hệ & Hỗ trợ học viên:</span>
          </div>
          <p>
            Cơ sở: <strong>{{ portalData.student?.center || publicInfo?.center }}</strong>
          </p>
          <p v-if="portalData.brand?.address">
            Địa chỉ: {{ portalData.brand.address }}
          </p>
          <p v-if="portalData.brand?.phone">
            Hotline: <a :href="`tel:${portalData.brand.phone}`" class="text-indigo-600 font-bold hover:underline">{{ portalData.brand.phone }}</a>
          </p>
        </div>
      </div>
    </main>

    <!-- Modal Xin nghỉ phép cho con -->
    <div
      v-if="showLeaveModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs"
    >
      <div class="bg-white rounded-3xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-slate-100 animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <div class="flex items-center gap-2">
            <div class="h-8 w-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
              <Calendar class="h-4 w-4" />
            </div>
            <h3 class="text-sm font-bold text-slate-900">Xin nghỉ học có phép</h3>
          </div>
          <button
            @click="showLeaveModal = false"
            class="text-slate-400 hover:text-slate-600 p-1 rounded-lg"
          >
            <XCircle class="h-5 w-5" />
          </button>
        </div>

        <p class="text-xs text-slate-500 leading-relaxed">
          Gửi thông báo xin phép nghỉ học cho học viên <strong>{{ portalData?.student?.name }}</strong>. Giáo viên và trung tâm sẽ nhận được thông tin để sắp xếp lịch học bù thích hợp.
        </p>

        <form @submit.prevent="submitLeaveRequest" class="space-y-3.5 text-xs">
          <div>
            <label class="block font-bold text-slate-700 mb-1">Ngày xin nghỉ:</label>
            <input
              v-model="leaveForm.date"
              type="date"
              required
              class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-indigo-600 focus:ring-2 focus:ring-indigo-100 outline-none text-slate-800"
            />
          </div>

          <div>
            <label class="block font-bold text-slate-700 mb-1">Lý do xin nghỉ:</label>
            <textarea
              v-model="leaveForm.reason"
              rows="3"
              required
              placeholder="Ví dụ: Bé bị ốm sốt / Gia đình có việc bận về quê..."
              class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:border-indigo-600 focus:ring-2 focus:ring-indigo-100 outline-none text-slate-800"
            ></textarea>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button
              type="button"
              @click="showLeaveModal = false"
              class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold"
            >
              Hủy
            </button>
            <button
              type="submit"
              :disabled="isSubmittingLeave"
              class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold transition-colors disabled:opacity-50 flex items-center gap-1.5"
            >
              <Send class="h-3.5 w-3.5" />
              <span>{{ isSubmittingLeave ? 'Đang gửi...' : 'Gửi đơn xin nghỉ' }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Bottom Footer -->
    <footer class="border-t border-slate-200/80 bg-white py-4 text-center text-xs text-slate-400">
      <p>&copy; {{ new Date().getFullYear() }} {{ publicInfo?.brand?.brand_name || 'EduCRM SIS' }}. Hệ thống quản trị và cổng phụ huynh chuyên nghiệp.</p>
    </footer>
  </div>
</template>

<style scoped>
@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20%, 60% { transform: translateX(-4px); }
  40%, 80% { transform: translateX(4px); }
}
.animate-shake {
  animation: shake 0.3s ease-in-out;
}
</style>
