<script setup>
import { ref, onMounted } from 'vue';
import { absenceApi } from '../services/api';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import {
  CalendarX,
  Phone,
  MessageSquare,
  CalendarCheck,
  RotateCcw,
  Info,
  X,
  CheckCircle2,
  AlertTriangle,
} from 'lucide-vue-next';

const loading = ref(true);
const threshold = ref(2); // Vắng 2 buổi liên tiếp
const selectedCenter = ref('all');
const students = ref([]);
const centers = ref([]);
const templates = ref([]);
const totalCount = ref(0);

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
  room: 'PIANO 01',
  teacher: 'Thầy Đức',
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
    alert('Vui lòng nhập nội dung chăm sóc!');
    return;
  }
  try {
    await absenceApi.recordCare(selectedStudent.value.id, careForm.value);
    showCareModal.value = false;
    alert(`Đã ghi nhận chăm sóc học viên ${selectedStudent.value.name}!`);
    await fetchData();
  } catch (err) {
    alert('Lỗi: ' + (err.response?.data?.message || err.message));
  }
};

// Mở modal Xếp lịch học bù
const openMakeupModal = (st) => {
  selectedStudent.value = st;
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const dateStr = tomorrow.toISOString().split('T')[0];
  makeupForm.value = {
    date: dateStr,
    time: '18:00',
    room: 'PIANO 01',
    teacher: 'Thầy Đức',
    note: '',
  };
  showMakeupModal.value = true;
};

const submitMakeup = async () => {
  if (!makeupForm.value.date || !selectedStudent.value) return;
  try {
    await absenceApi.scheduleMakeup(selectedStudent.value.id, makeupForm.value);
    showMakeupModal.value = false;
    alert(`Đã lên lịch học bù cho học viên ${selectedStudent.value.name}!`);
    await fetchData();
  } catch (err) {
    alert('Lỗi: ' + (err.response?.data?.message || err.message));
  }
};

// Xác nhận học viên đã đi học lại (Reset vắng liên tiếp về 0)
const confirmReturned = async (st) => {
  if (!confirm(`Xác nhận học viên "${st.name}" đã quay lại lớp và đặt lại số buổi vắng liên tiếp về 0?`)) return;
  try {
    await absenceApi.resetAbsences(st.id);
    await fetchData();
  } catch (err) {
    alert('Lỗi: ' + (err.response?.data?.message || err.message));
  }
};

// Mở modal tin nhắn Zalo
const openMessageModal = (st) => {
  selectedStudent.value = st;
  showMessageModal.value = true;
};

const getZaloLink = (phone) => {
  if (!phone) return '#';
  return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};
</script>

<template>
  <div class="space-y-6 pb-12 max-w-6xl">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Vắng liên tiếp</h1>
    </div>

    <!-- MAIN CARD: VẮNG 2 BUỔI LIÊN TIẾP (Khớp 100% hình tham khảo Seny) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <!-- Title & Subtitle with Info tooltip -->
      <div>
        <div class="flex items-center gap-1.5">
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Vắng 2 buổi liên tiếp</h2>
          <!-- Info icon -->
          <div class="relative">
            <button
              type="button"
              @mouseenter="showInfoTooltip = true"
              @mouseleave="showInfoTooltip = false"
              class="text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer"
            >
              <Info class="h-4 w-4" />
            </button>
            <div
              v-if="showInfoTooltip"
              class="absolute left-6 -top-2 z-30 w-72 rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-600 shadow-xl"
            >
              Học viên vắng từ 2 buổi liên tiếp trở lên có nguy cơ cao bị hổng kiến thức và ngắt quãng lộ trình học. Trung tâm cần liên hệ sớm để thăm hỏi lý do và sắp xếp lịch học bù kịp thời.
            </div>
          </div>
        </div>
        <p class="text-xs text-slate-500 mt-0.5">Liên hệ phụ huynh để hỗ trợ học viên quay lại lớp</p>
      </div>

      <!-- Filter Row: Trung tâm | Tổng: X (Khớp chính xác hình tham khảo) -->
      <div class="flex items-center justify-between gap-3 pt-1 border-t border-slate-100">
        <div class="flex items-center gap-2">
          <span class="text-xs font-semibold text-slate-700">Trung tâm</span>
          <select
            v-model="selectedCenter"
            @change="fetchData"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <div class="text-xs text-slate-500 font-medium">
          Tổng: <strong class="text-slate-800">{{ totalCount }}</strong>
        </div>
      </div>

      <!-- State: Khi không có học viên vắng liên tiếp (Khớp chính xác hình tham khảo) -->
      <div
        v-if="students.length === 0"
        class="py-10 text-xs sm:text-sm text-slate-500"
      >
        Không có học viên vắng liên tiếp 🎉
      </div>

      <!-- State: Danh sách học viên vắng liên tiếp -->
      <div v-else class="divide-y divide-slate-100 space-y-4 pt-2">
        <div
          v-for="st in students"
          :key="st.id"
          class="pt-4 first:pt-0 space-y-2.5"
        >
          <!-- Hàng 1: Tên học viên, Mã số, Huy hiệu vắng liên tiếp -->
          <div class="flex items-start justify-between gap-2 flex-wrap">
            <div class="flex items-center gap-2 flex-wrap">
              <router-link
                :to="`/students/${st.id}`"
                class="font-bold text-slate-900 hover:text-blue-600 text-sm sm:text-base uppercase"
              >
                {{ st.name }}
              </router-link>
              <span class="text-xs font-mono text-slate-400">({{ st.code }})</span>

              <!-- Badge cảnh báo vắng liên tiếp -->
              <span
                :class="[
                  'rounded-full px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1',
                  st.consecutive_absences >= 3
                    ? 'bg-rose-100 text-rose-700 border border-rose-200'
                    : 'bg-amber-100 text-amber-800 border border-amber-200'
                ]"
              >
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
              Số buổi còn lại: <strong class="text-slate-900">{{ st.remaining_sessions }}/{{ st.total_sessions }} buổi</strong>
            </span>
            <span>
              PH: <strong>{{ st.parent_name || 'N/A' }}</strong> (<a :href="`tel:${st.parent_phone}`" class="font-mono text-blue-600 hover:underline">{{ st.parent_phone }}</a>)
            </span>
            <span v-if="st.latest_attendance_date" class="text-slate-400">
              Buổi vắng gần nhất: {{ st.latest_attendance_date }}
            </span>
          </div>

          <!-- Hàng 3: Ghi chú chăm sóc trước đó (nếu có) -->
          <div v-if="st.notes || st.latest_log" class="text-xs text-slate-500 bg-slate-50 p-2.5 rounded-xl border border-slate-100 italic space-y-0.5">
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
            <button
              type="button"
              @click="openCareModal(st)"
              class="inline-flex items-center gap-1.5 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer"
            >
              <Phone class="h-3.5 w-3.5 text-amber-600" />
              <span>Chăm sóc</span>
            </button>

            <!-- Nút Nhắn Zalo -->
            <a
              :href="getZaloLink(st.parent_phone)"
              target="_blank"
              class="inline-flex items-center gap-1.5 rounded-xl bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer"
            >
              <MessageSquare class="h-3.5 w-3.5" />
              <span>Nhắn Zalo</span>
            </a>

            <!-- Nút Mẫu tin nhắn -->
            <button
              type="button"
              @click="openMessageModal(st)"
              class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-3 py-1.5 text-xs font-medium transition-all cursor-pointer"
            >
              <span>Mẫu tin nhắn</span>
            </button>

            <!-- Nút Hẹn học bù -->
            <button
              type="button"
              @click="openMakeupModal(st)"
              class="inline-flex items-center gap-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer"
            >
              <CalendarCheck class="h-3.5 w-3.5" />
              <span>Hẹn học bù</span>
            </button>

            <!-- Nút Đã đi học lại (Reset về 0) -->
            <button
              type="button"
              @click="confirmReturned(st)"
              title="Xác nhận học viên đã trở lại lớp học"
              class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer ml-auto"
            >
              <CheckCircle2 class="h-3.5 w-3.5" />
              <span>Đã đi học lại</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL 1: CHĂM SÓC HỌC VIÊN VẮNG -->
    <div
      v-if="showCareModal && selectedStudent"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <div>
            <h3 class="text-base font-bold text-slate-900">Chăm sóc học viên vắng học</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ selectedStudent.name }} - Vắng {{ selectedStudent.consecutive_absences }} buổi</p>
          </div>
          <button
            type="button"
            @click="showCareModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitCare" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Nội dung trao đổi với phụ huynh (*)</label>
            <textarea
              v-model="careForm.content"
              rows="3"
              required
              placeholder="VD: Đã liên hệ mẹ, bé bị ốm phải nằm viện tuần qua, mẹ hẹn thứ 2 tuần sau bé đi học lại..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            ></textarea>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Người liên hệ</label>
              <input
                v-model="careForm.created_by"
                type="text"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Hẹn liên hệ lại</label>
              <input
                v-model="careForm.next_followup_date"
                type="date"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showCareModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Hủy
            </button>
            <button
              type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]"
            >
              Lưu chăm sóc
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 2: HẸN LỊCH HỌC BÙ -->
    <div
      v-if="showMakeupModal && selectedStudent"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <div>
            <h3 class="text-base font-bold text-slate-900">Xếp lịch học bù</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ selectedStudent.name }} - Khóa: {{ selectedStudent.course?.name }}</p>
          </div>
          <button
            type="button"
            @click="showMakeupModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitMakeup" class="space-y-3.5 text-xs sm:text-sm">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Ngày học bù (*)</label>
              <input
                v-model="makeupForm.date"
                type="date"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Khung giờ (*)</label>
              <input
                v-model="makeupForm.time"
                type="text"
                required
                placeholder="VD: 18:00 - 19:00"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Phòng học</label>
              <input
                v-model="makeupForm.room"
                type="text"
                placeholder="VD: PIANO 01"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Giáo viên phụ trách</label>
              <input
                v-model="makeupForm.teacher"
                type="text"
                placeholder="VD: Thầy Đức"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Ghi chú</label>
            <input
              v-model="makeupForm.note"
              type="text"
              placeholder="VD: Ôn tập lại bài tập số 3..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            />
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showMakeupModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Hủy
            </button>
            <button
              type="submit"
              class="rounded-xl bg-purple-600 hover:bg-purple-700 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]"
            >
              Xác nhận lịch học bù
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 3: MẪU TIN NHẮN ZALO -->
    <MessageTemplateModal
      :show="showMessageModal"
      :student="selectedStudent"
      :templates="templates"
      @close="showMessageModal = false"
    />
  </div>
</template>
