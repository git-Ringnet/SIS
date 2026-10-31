<script setup>
import { toLocalDateString, addDays } from '../services/date';
import { confirmDialog } from '../services/useConfirm';
import { notify } from '../services/notify';
import { ref, computed, onMounted } from 'vue';
import { absenceApi } from '../services/api';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import {
  CalendarX,
  Phone,
  CalendarCheck,
  RotateCcw,
  Info,
  X,
  CheckCircle2,
  AlertTriangle,
  Mail,
  Calendar,
} from 'lucide-vue-next';

const loading = ref(true);
const threshold = ref(2); // Vắng 2 buổi liên tiếp
const selectedCenter = ref('all');
const activeTab = ref('consecutive'); // 'consecutive' | 'portal_requests'
const parentLeaveRequests = ref([]);
const rooms = ref([]);
const teachers = ref([]);
const students = ref([]);
const centers = ref([]);
const templates = ref([]);
const totalCount = ref(0);

const roomOptions = computed(() => rooms.value.map((r) => ({ id: r, name: r })));
const teacherOptions = computed(() => teachers.value.map((t) => ({ id: t, name: t })));
const centerOptions = computed(() => [
  { id: 'all', name: 'Tất cả trung tâm' },
  ...centers.value.map((c) => ({ id: c, name: c })),
]);

// Tooltip Info
const showInfoTooltip = ref(false);

// Modals
const showCareModal = ref(false);
const showMakeupModal = ref(false);
const showMessageModal = ref(false);
const selectedStudent = ref(null);

// Form Chăm sóc
const careForm = ref({
  content: '',
  created_by: 'Tư vấn viên Lan',
  next_followup_date: '',
});

// Form Học bù
const makeupForm = ref({
  date: '',
  time: '18:00',
  room: '',
  teacher: '',
  note: '',
});

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await absenceApi.getAbsences({
      center: selectedCenter.value,
      threshold: threshold.value,
    });
    students.value = res.data.students || [];
    totalCount.value = res.data.total || 0;
    centers.value = res.data.centers || [];
    templates.value = res.data.templates || [];
    parentLeaveRequests.value = res.data.parent_leave_requests || [];
    rooms.value = res.data.rooms || [];
    teachers.value = res.data.teachers || [];
  } catch (err) {
    console.error('Lỗi tải danh sách vắng liên tiếp:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchData();
});

// Mở modal Chăm sóc
const openCareModal = (st) => {
  selectedStudent.value = st;
  careForm.value = {
    content: '',
    created_by: 'Tư vấn viên Lan',
    next_followup_date: '',
  };
  showCareModal.value = true;
};

const submitCare = async () => {
  if (!careForm.value.content.trim() || !selectedStudent.value) {
    notify.warning('Vui lòng nhập nội dung chăm sóc!');
    return;
  }
  try {
    await absenceApi.recordCare(selectedStudent.value.id, careForm.value);
    showCareModal.value = false;
    notify.success(`Đã ghi nhận chăm sóc học viên ${selectedStudent.value.name}!`);
    await fetchData();
  } catch (err) {
    notify.apiError(err, err.message);
  }
};

// Mở modal Xếp lịch học bù
const openMakeupModal = (st) => {
  selectedStudent.value = st;
  const dateStr = toLocalDateString(addDays(1));
  makeupForm.value = {
    date: dateStr,
    time: '18:00',
    room: st.room || (rooms.value[0] || ''),
    teacher: st.teacher || (teachers.value[0] || ''),
    note: '',
  };
  showMakeupModal.value = true;
};

const submitMakeup = async () => {
  if (!makeupForm.value.date || !selectedStudent.value) return;
  const send = (extra = {}) => absenceApi.scheduleMakeup(selectedStudent.value.id, { ...makeupForm.value, ...extra });
  try {
    try {
      await send();
    } catch (err) {
      // Trùng giờ ca học cố định ngày đó (backend trả 409): hỏi lại, đồng ý thì vẫn đặt
      if (err.response?.status !== 409 || !err.response?.data?.overlap) throw err;
      if (!(await confirmDialog({
        title: 'Trùng buổi học theo lịch',
        message: `${err.response.data.message} Vẫn đặt buổi bù?`,
        confirmText: 'Vẫn đặt',
        variant: 'warning',
      }))) return;
      await send({ confirm_overlap: true });
    }
    showMakeupModal.value = false;
    notify.success(`Đã lên lịch học bù cho học viên ${selectedStudent.value.name}!`);
    await fetchData();
  } catch (err) {
    notify.apiError(err, err.message);
  }
};

// Xác nhận học viên đã đi học lại (Reset vắng liên tiếp về 0)
const confirmReturned = async (st) => {
  if (!(await confirmDialog({
    title: 'Xác nhận học viên đã đi học lại?',
    message: `Số buổi vắng liên tiếp của "${st.name}" sẽ được đặt lại về 0.`,
    confirmText: 'Xác nhận',
    variant: 'primary',
  }))) return;
  try {
    await absenceApi.resetAbsences(st.id);
    await fetchData();
  } catch (err) {
    notify.apiError(err, err.message);
  }
};

// Mở modal mẫu tin nhắn
const openMessageModal = (st) => {
  selectedStudent.value = st;
  showMessageModal.value = true;
};

</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- Header & Tab Navigation -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Quản lý nghỉ học & Vắng</h1>
        <p class="text-xs text-slate-500 mt-0.5">Theo dõi học viên vắng liên tiếp và tiếp nhận đơn xin nghỉ phép từ phụ
          huynh</p>
      </div>

      <!-- Tabs -->
      <div class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl">
        <button type="button" @click="activeTab = 'consecutive'"
          class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all"
          :class="activeTab === 'consecutive' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'">
          Vắng liên tiếp ({{ totalCount }})
        </button>
        <button type="button" @click="activeTab = 'portal_requests'"
          class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5"
          :class="activeTab === 'portal_requests' ? 'bg-amber-500 text-white shadow-xs' : 'text-slate-600 hover:text-slate-900'">
          <Mail class="h-3.5 w-3.5" />
          <span>Đơn xin nghỉ từ Cổng PH ({{ parentLeaveRequests.length }})</span>
        </button>
      </div>
    </div>

    <!-- TAB 1: VẮNG LIÊN TIẾP -->
    <div v-if="activeTab === 'consecutive'"
      class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <!-- Title & Subtitle with Info tooltip -->
      <div>
        <div class="flex items-center gap-1.5">
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Vắng 2 buổi liên tiếp</h2>
          <!-- Info icon -->
          <div class="relative">
            <button type="button" @mouseenter="showInfoTooltip = true" @mouseleave="showInfoTooltip = false"
              class="text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer">
              <Info class="h-4 w-4" />
            </button>
            <div v-if="showInfoTooltip"
              class="absolute left-6 -top-2 z-30 w-72 rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-600 shadow-xl">
              Học viên vắng từ 2 buổi liên tiếp trở lên có nguy cơ cao bị hổng kiến thức và ngắt quãng lộ trình học.
              Trung tâm cần liên hệ sớm để thăm hỏi lý do và sắp xếp lịch học bù kịp thời.
            </div>
          </div>
        </div>
        <p class="text-xs text-slate-500 mt-0.5">Liên hệ phụ huynh để hỗ trợ học viên quay lại lớp</p>
      </div>

      <!-- Filter Row: Trung tâm | Tổng: X (Khớp chính xác hình tham khảo) -->
      <div class="flex items-center justify-between gap-3 pt-1 border-t border-slate-100">
        <div class="flex items-center gap-2">
          <span class="text-xs font-semibold text-slate-700 shrink-0">Trung tâm</span>
          <div class="w-48 sm:w-56">
            <SearchableSelect v-model="selectedCenter" :options="centerOptions" placeholder="Tất cả trung tâm"
              @change="fetchData" />
          </div>
        </div>

        <div class="text-xs text-slate-500 font-medium">
          Tổng: <strong class="text-slate-800">{{ totalCount }}</strong>
        </div>
      </div>

      <!-- State: Khi không có học viên vắng liên tiếp (Khớp chính xác hình tham khảo) -->
      <div v-if="students.length === 0" class="py-10 text-xs sm:text-sm text-slate-500">
        Không có học viên vắng liên tiếp 🎉
      </div>

      <!-- State: Danh sách học viên vắng liên tiếp -->
      <div v-else class="divide-y divide-slate-100 space-y-4 pt-2">
        <div v-for="st in students" :key="st.id" class="pt-4 first:pt-0 space-y-2.5">
          <!-- Hàng 1: Tên học viên, Mã số, Huy hiệu vắng liên tiếp -->
          <div class="flex items-start justify-between gap-2 flex-wrap">
            <div class="flex items-center gap-2 flex-wrap">
              <router-link :to="`/students/${st.id}`"
                class="font-bold text-slate-900 hover:text-blue-600 text-sm sm:text-base uppercase">
                {{ st.name }}
              </router-link>
              <span class="text-xs font-mono text-slate-400">({{ st.code }})</span>

              <!-- Badge cảnh báo vắng liên tiếp -->
              <span :class="[
                'rounded-full px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1',
                st.consecutive_absences >= 3
                  ? 'bg-rose-100 text-rose-700 border border-rose-200'
                  : 'bg-amber-100 text-amber-800 border border-amber-200'
              ]">
                <AlertTriangle class="h-3 w-3" />
                Vắng {{ st.consecutive_absences }} buổi liên tiếp
              </span>
            </div>

            <span class="text-xs text-slate-400">
              {{ st.center }}
            </span>
          </div>

          <!-- Hàng 2: Khóa học, Buổi còn lại, Thông tin Phụ huynh -->
          <div class="text-xs text-slate-600 flex flex-wrap items-center gap-x-4 gap-y-1">
            <span v-if="st.course" class="font-semibold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-md">
              [{{ st.course.name }}]
            </span>
            <span>
              Số buổi còn lại: <strong class="text-slate-900">{{ st.remaining_sessions }}/{{ st.total_sessions }}
                buổi</strong>
            </span>
            <span>
              PH: <strong>{{ st.parent_name || 'N/A' }}</strong> (<a :href="`tel:${st.parent_phone}`"
                class="font-mono text-blue-600 hover:underline">{{ st.parent_phone }}</a>)
            </span>
            <span v-if="st.latest_attendance_date" class="text-slate-400">
              Buổi vắng gần nhất: {{ st.latest_attendance_date }}
            </span>
          </div>

          <!-- Hàng 3: Ghi chú chăm sóc trước đó (nếu có) -->
          <div v-if="st.notes || st.latest_log"
            class="text-xs text-slate-500 bg-slate-50 p-2.5 rounded-xl border border-slate-100 italic space-y-0.5">
            <div v-if="st.latest_log">
              <strong>Lần chăm sóc gần nhất ({{ st.latest_log.contact_date }}):</strong> {{ st.latest_log.content }}
            </div>
            <div v-else>
              <strong>Ghi chú:</strong> {{ st.notes }}
            </div>
          </div>

          <!-- Hàng 4: Các nút hành động xử lý nhanh -->
          <div class="flex items-center gap-2 flex-wrap pt-1">
            <!-- Nút Chăm sóc -->
            <button type="button" @click="openCareModal(st)"
              class="inline-flex items-center gap-1.5 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer">
              <Phone class="h-3.5 w-3.5 text-amber-600" />
              <span>Chăm sóc</span>
            </button>

            <!-- Nút Mẫu tin nhắn -->
            <button type="button" @click="openMessageModal(st)"
              class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-3 py-1.5 text-xs font-medium transition-all cursor-pointer">
              <span>Mẫu tin nhắn</span>
            </button>

            <!-- Nút Hẹn học bù -->
            <button type="button" @click="openMakeupModal(st)"
              class="inline-flex items-center gap-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer">
              <CalendarCheck class="h-3.5 w-3.5" />
              <span>Hẹn học bù</span>
            </button>

            <!-- Nút Đã đi học lại (Reset về 0) -->
            <button type="button" @click="confirmReturned(st)" title="Xác nhận học viên đã trở lại lớp học"
              class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer ml-auto">
              <CheckCircle2 class="h-3.5 w-3.5" />
              <span>Đã đi học lại</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 2: ĐƠN XIN NGHỈ PHÉP TỪ CỔNG PHỤ HUYNH -->
    <div v-else class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Danh sách đơn xin nghỉ phép từ Cổng phụ huynh</h2>
        <p class="text-xs text-slate-500 mt-0.5">Tiếp nhận và quản lý thông tin xin phép nghỉ học trực tiếp từ phụ huynh
          học viên</p>
      </div>

      <div v-if="parentLeaveRequests.length === 0" class="py-12 text-center text-xs text-slate-400 space-y-2">
        <Mail class="h-8 w-8 mx-auto text-slate-300" />
        <p>Hiện chưa có đơn xin nghỉ phép nào từ phụ huynh.</p>
      </div>

      <div v-else class="divide-y divide-slate-100">
        <div v-for="req in parentLeaveRequests" :key="req.id"
          class="py-4 first:pt-0 last:pb-0 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div class="space-y-1.5">
            <div class="flex items-center gap-2 flex-wrap">
              <router-link :to="`/students/${req.student_id}`"
                class="font-bold text-slate-900 hover:text-blue-600 text-sm uppercase">
                {{ req.student_name }}
              </router-link>
              <span class="text-xs font-mono text-slate-400">({{ req.student_code }})</span>
              <span
                class="rounded-full bg-amber-100 text-amber-900 border border-amber-200 px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1">
                <Calendar class="h-3 w-3" /> Xin nghỉ ngày: {{ req.leave_date }}
              </span>
            </div>

            <div v-if="req.reason" class="p-2.5 bg-amber-50/60 rounded-xl border border-amber-100 text-xs text-slate-700">
              <strong class="text-amber-900">Lý do:</strong> "{{ req.reason }}"
            </div>

            <p class="text-xs text-slate-500 flex flex-wrap items-center gap-x-3 gap-y-1">
              <span>Khóa học: <strong class="text-slate-700">{{ req.course_name }}</strong></span>
              <span>Phụ huynh: <strong class="text-slate-700">{{ req.parent_name }}</strong></span>
              <span v-if="req.parent_phone">
                SĐT: <a :href="`tel:${req.parent_phone}`" class="text-blue-600 font-semibold hover:underline">{{
                  req.parent_phone }}</a>
              </span>
              <span>Gửi lúc: {{ req.created_at }}</span>
            </p>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <button type="button"
              @click="openMakeupModal({ id: req.student_id, name: req.student_name, course: { name: req.course_name } })"
              class="inline-flex items-center gap-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 px-3.5 py-2 text-xs font-bold transition-all shadow-xs cursor-pointer">
              <CalendarCheck class="h-3.5 w-3.5" />
              <span>Hẹn học bù</span>
            </button>
            <router-link :to="`/attendance?date=${req.leave_date_raw}`"
              class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 px-3 py-2 text-xs font-bold transition-all">
              <span>Xem ngày học</span>
            </router-link>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL 1: CHĂM SÓC HỌC VIÊN VẮNG -->
    <div v-if="showCareModal && selectedStudent"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <div>
            <h3 class="text-base font-bold text-slate-900">Chăm sóc học viên vắng học</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ selectedStudent.name }} - Vắng {{
              selectedStudent.consecutive_absences }} buổi</p>
          </div>
          <button type="button" @click="showCareModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitCare" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Nội dung trao đổi với phụ huynh (*)</label>
            <textarea v-model="careForm.content" rows="3" required
              placeholder="VD: Đã liên hệ mẹ, bé bị ốm phải nằm viện tuần qua, mẹ hẹn thứ 2 tuần sau bé đi học lại..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"></textarea>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Người liên hệ</label>
              <input v-model="careForm.created_by" type="text"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Hẹn liên hệ lại</label>
              <input v-model="careForm.next_followup_date" type="date"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showCareModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors">
              Hủy
            </button>
            <button type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]">
              Lưu chăm sóc
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 2: HẸN LỊCH HỌC BÙ -->
    <div v-if="showMakeupModal && selectedStudent"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <div>
            <h3 class="text-base font-bold text-slate-900">Xếp lịch học bù</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ selectedStudent.name }} - Khóa: {{ selectedStudent.course?.name
              }}</p>
          </div>
          <button type="button" @click="showMakeupModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitMakeup" class="space-y-3.5 text-xs sm:text-sm">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Ngày học bù (*)</label>
              <input v-model="makeupForm.date" type="date" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Khung giờ (*)</label>
              <input v-model="makeupForm.time" type="text" required placeholder="VD: 18:00 - 19:00"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Phòng học</label>
              <SearchableSelect v-model="makeupForm.room" :options="roomOptions" placeholder="Chọn phòng học..."
                search-placeholder="Tìm kiếm phòng..." />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Giáo viên phụ trách</label>
              <SearchableSelect v-model="makeupForm.teacher" :options="teacherOptions" placeholder="Chọn giáo viên..."
                search-placeholder="Tìm kiếm giáo viên..." />
            </div>
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Ghi chú</label>
            <input v-model="makeupForm.note" type="text" placeholder="VD: Ôn tập lại bài tập số 3..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showMakeupModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors">
              Hủy
            </button>
            <button type="submit"
              class="rounded-xl bg-purple-600 hover:bg-purple-700 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]">
              Xác nhận lịch học bù
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 3: MẪU TIN NHẮN -->
    <MessageTemplateModal :show="showMessageModal" :student="selectedStudent" :templates="templates"
      @close="showMessageModal = false" />
  </div>
</template>
