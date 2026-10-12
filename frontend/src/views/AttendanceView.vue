<script setup>
import { ref, onMounted, computed } from 'vue';
import { dailyAttendanceApi, studentApi } from '../services/api';
import SearchableSelect from '../components/SearchableSelect.vue';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import {
  Calendar,
  CheckCircle2,
  XCircle,
  Clock,
  RotateCcw,
  Search,
  Plus,
  Users,
  MessageSquare,
  AlertTriangle,
  FileEdit,
  X,
  Phone,
} from 'lucide-vue-next';

// Bộ lọc
const selectedDate = ref('2026-10-11');
const selectedCenter = ref('all');
const selectedClass = ref('all');
const selectedRoom = ref('all');
const selectedTeacher = ref('all');
const selectedShift = ref('all');
const searchQuery = ref('');

// Dữ liệu API
const loading = ref(true);
const dayOfWeek = ref('CN');
const dayOfWeekEn = ref('Sunday');
const metrics = ref({
  total_today: 0,
  actual_present: 0,
  paused: 0,
  absent: 0,
  not_checked: 0,
});
const students = ref([]);
const centers = ref([]);
const classes = ref([]);
const rooms = ref([]);
const teachers = ref([]);

// Modals
const showSingleLessonModal = ref(false);
const showNoteModal = ref(false);
const showMessageModal = ref(false);
const allStudentsList = ref([]);
const selectedStudent = ref(null);

// Form đặt buổi học lẻ
const singleLessonForm = ref({
  student_id: '',
  date: '2026-10-11',
  room: 'PIANO 01',
  teacher: 'Thầy Trương Bảo Đi',
  time: '18:00 - 19:00',
  note: 'Buổi học bù / tăng cường',
});

// Form nhận xét
const noteForm = ref({
  content: '',
});

// Mẫu tin nhắn
const attendanceTemplates = ref([
  {
    id: 1,
    title: 'Báo cáo buổi học hôm nay gửi phụ huynh',
    template_content: 'Chào phụ huynh bé {ten_hoc_vien}! Hôm nay con đã hoàn thành tốt buổi học bộ môn {khoa_hoc}. Thầy/cô nhận xét con tiến bộ rõ rệt và tiếp thu nhanh. Ba mẹ nhớ nhắc con luyện tập thêm 15 phút mỗi ngày nhé ạ! ❤️',
  },
  {
    id: 2,
    title: 'Thông báo học viên vắng học',
    template_content: 'Dạ trung tâm xin chào phụ huynh {ten_hoc_vien}! Hôm nay không thấy bé đến lớp học môn {khoa_hoc}, trung tâm nhắn tin hỏi thăm tình hình sức khỏe của con ạ. Ba mẹ phản hồi giúp trung tâm để xếp lịch học bù cho bé nhé!',
  },
]);

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await dailyAttendanceApi.getAttendance({
      date: selectedDate.value,
      center: selectedCenter.value,
      class: selectedClass.value,
      room: selectedRoom.value,
      teacher: selectedTeacher.value,
      shift: selectedShift.value,
      search: searchQuery.value,
    });
    dayOfWeek.value = res.data.day_of_week || 'CN';
    dayOfWeekEn.value = res.data.day_of_week_en || 'Sunday';
    metrics.value = res.data.metrics || {};
    students.value = res.data.students || [];
    centers.value = res.data.centers || [];
    classes.value = res.data.classes || [];
    rooms.value = res.data.rooms || [];
    teachers.value = res.data.teachers || [];
  } catch (err) {
    console.error('Lỗi tải dữ liệu điểm danh:', err);
  } finally {
    loading.value = false;
  }
};

const loadAllStudents = async () => {
  try {
    const res = await studentApi.getAll({ per_page: 200 });
    allStudentsList.value = (res.data.data || []).map((s) => ({
      id: s.id,
      name: `${s.name} (${s.code}) - ${s.course?.name || 'Khóa học'}`,
    }));
  } catch (err) {
    console.error('Lỗi tải danh sách học viên:', err);
  }
};

onMounted(() => {
  fetchData();
  loadAllStudents();
});

// Điểm danh (Có mặt / Vắng / Hoàn tác)
const markStudent = async (student, status, note = '') => {
  try {
    await dailyAttendanceApi.mark(student.id, {
      date: selectedDate.value,
      status,
      note,
    });
    await fetchData();
  } catch (err) {
    alert('Lỗi điểm danh: ' + (err.response?.data?.message || err.message));
  }
};

// Mở modal Đặt buổi học lẻ
const openSingleLessonModal = () => {
  singleLessonForm.value = {
    student_id: allStudentsList.value[0]?.id || '',
    date: selectedDate.value,
    room: rooms.value[0] || 'PIANO 01',
    teacher: teachers.value[0] || 'Thầy Trương Bảo Đi',
    time: '18:00 - 19:00',
    note: 'Buổi học bù / tăng cường',
  };
  showSingleLessonModal.value = true;
};

const submitSingleLesson = async () => {
  if (!singleLessonForm.value.student_id) {
    alert('Vui lòng chọn học viên!');
    return;
  }
  try {
    await dailyAttendanceApi.scheduleSingleLesson(singleLessonForm.value);
    showSingleLessonModal.value = false;
    alert('Đã đặt buổi học lẻ thành công!');
    await fetchData();
  } catch (err) {
    alert('Lỗi: ' + (err.response?.data?.message || err.message));
  }
};

// Mở modal Nhận xét buổi học
const openNoteModal = (student) => {
  selectedStudent.value = student;
  noteForm.value.content = student.checkin_note || '';
  showNoteModal.value = true;
};

const submitNote = async () => {
  if (!selectedStudent.value) return;
  await markStudent(selectedStudent.value, selectedStudent.value.status === 'not_checked' ? 'present' : selectedStudent.value.status, noteForm.value.content);
  showNoteModal.value = false;
};

// Mở modal nhắn Zalo
const openMessageModal = (student) => {
  selectedStudent.value = student;
  showMessageModal.value = true;
};

const getZaloLink = (phone) => {
  if (!phone) return '#';
  return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};
</script>

<template>
  <div class="space-y-6 pb-12 max-w-7xl">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Điểm danh</h1>
    </div>

    <!-- MAIN CARD: ĐIỂM DANH — [CN] (Khớp 100% hình tham khảo Seny) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <!-- Header hàng 1: Tiêu đề + Phụ đề + Ô tìm kiếm + Nút Đặt buổi học lẻ -->
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">
            Điểm danh &mdash; {{ dayOfWeek }}
          </h2>
          <p class="text-xs text-slate-500 mt-0.5">
            Hiển thị học viên có lịch hôm nay và các buổi học bù. Giờ theo múi giờ Việt Nam.
          </p>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
          <!-- Ô Tìm học viên... -->
          <div class="relative w-full sm:w-56">
            <input
              v-model="searchQuery"
              @keyup.enter="fetchData"
              type="text"
              placeholder="Tìm học viên..."
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 placeholder:text-slate-400 focus:border-rose-500 focus:outline-none"
            />
          </div>

          <!-- Nút Đặt buổi học lẻ (Đỏ / Rose - Khớp 100% hình tham khảo) -->
          <button
            type="button"
            @click="openSingleLessonModal"
            class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 px-3.5 py-1.5 text-xs sm:text-sm font-semibold text-white shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
          >
            <Calendar class="h-3.5 w-3.5" />
            <span>Đặt buổi học lẻ</span>
          </button>
        </div>
      </div>

      <!-- Filter Bar: Ngày | Trung tâm | Lớp học | Phòng học | Giáo viên | Ca học (Khớp 100% hình tham khảo) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 pt-1 border-t border-slate-100">
        <!-- Ngày -->
        <div>
          <input
            v-model="selectedDate"
            type="date"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          />
        </div>

        <!-- Trung tâm -->
        <div>
          <select
            v-model="selectedCenter"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <!-- Lớp học -->
        <div>
          <select
            v-model="selectedClass"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả lớp học</option>
            <option v-for="cl in classes" :key="cl" :value="cl">{{ cl }}</option>
          </select>
        </div>

        <!-- Phòng học -->
        <div>
          <select
            v-model="selectedRoom"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả phòng học</option>
            <option v-for="r in rooms" :key="r" :value="r">{{ r }}</option>
          </select>
        </div>

        <!-- Giáo viên -->
        <div>
          <select
            v-model="selectedTeacher"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả giáo viên</option>
            <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>

        <!-- Ca học -->
        <div>
          <select
            v-model="selectedShift"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả ca học</option>
            <option value="morning">Sáng (08:00 - 11:30)</option>
            <option value="afternoon">Chiều (14:00 - 17:30)</option>
            <option value="evening">Tối (17:30 - 21:00)</option>
          </select>
        </div>
      </div>

      <!-- 5 Metric Cards (Khớp 100% màu sắc và viền trong hình tham khảo) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 pt-2">
        <!-- 1. HỌC VIÊN HÔM NAY (Viền tím) -->
        <div class="rounded-xl border border-indigo-300 bg-indigo-50/30 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-indigo-900">
            <span class="h-2 w-2 rounded-full bg-indigo-600"></span>
            <span>HỌC VIÊN HÔM NAY</span>
          </div>
          <div class="text-2xl font-black text-indigo-700">
            {{ metrics.total_today || 0 }}
          </div>
        </div>

        <!-- 2. ĐI HỌC THỰC TẾ (Viền xanh dương) -->
        <div class="rounded-xl border border-blue-300 bg-blue-50/30 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-blue-900">
            <span class="h-2 w-2 rounded-full bg-blue-600"></span>
            <span>ĐI HỌC THỰC TẾ</span>
          </div>
          <div class="text-2xl font-black text-blue-700">
            {{ metrics.actual_present || 0 }}
          </div>
        </div>

        <!-- 3. ĐANG TẠM DỪNG (Viền vàng / cam) -->
        <div class="rounded-xl border border-amber-300 bg-amber-50/30 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-amber-900">
            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
            <span>ĐANG TẠM DỪNG</span>
          </div>
          <div class="text-2xl font-black text-amber-700">
            {{ metrics.paused || 0 }}
          </div>
        </div>

        <!-- 4. HỌC SINH VẮNG (Viền đỏ / rose) -->
        <div class="rounded-xl border border-rose-300 bg-rose-50/30 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-rose-900">
            <span class="h-2 w-2 rounded-full bg-rose-600"></span>
            <span>HỌC SINH VẮNG</span>
          </div>
          <div class="text-2xl font-black text-rose-700">
            {{ metrics.absent || 0 }}
          </div>
        </div>

        <!-- 5. CHƯA ĐIỂM DANH (Viền xanh teal / ngọc) -->
        <div class="rounded-xl border border-teal-300 bg-teal-50/30 p-3 space-y-1">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-teal-900">
            <span class="h-2 w-2 rounded-full bg-teal-600"></span>
            <span>CHƯA ĐIỂM DANH</span>
          </div>
          <div class="text-2xl font-black text-teal-700">
            {{ metrics.not_checked || 0 }}
          </div>
        </div>
      </div>

      <!-- State: Khi không có học viên nào theo lịch hôm nay (Khớp 100% hình tham khảo Seny) -->
      <div
        v-if="students.length === 0"
        class="py-10 text-xs sm:text-sm text-slate-500 text-left"
      >
        Không có học viên nào theo lịch {{ dayOfWeekEn }}.
      </div>

      <!-- State: Danh sách học viên cần điểm danh hôm nay -->
      <div v-else class="divide-y divide-slate-100 pt-2 space-y-4">
        <div
          v-for="st in students"
          :key="st.id"
          class="pt-4 first:pt-0 space-y-2.5"
        >
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <!-- Tên học viên, Khóa học, Phòng học, Ca học -->
            <div class="space-y-1">
              <div class="flex items-center gap-2 flex-wrap">
                <router-link
                  :to="`/students/${st.id}`"
                  class="font-bold text-slate-900 hover:text-blue-600 text-sm sm:text-base uppercase"
                >
                  {{ st.name }}
                </router-link>
                <span class="text-xs font-mono text-slate-400">({{ st.code }})</span>

                <!-- Trạng thái điểm danh -->
                <span
                  v-if="st.status === 'present'"
                  class="rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1"
                >
                  <CheckCircle2 class="h-3 w-3" /> Đi học thực tế
                </span>
                <span
                  v-else-if="st.status === 'absent_with_leave'"
                  class="rounded-full bg-amber-100 text-amber-800 border border-amber-200 px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1"
                >
                  <AlertTriangle class="h-3 w-3" /> Vắng có phép
                </span>
                <span
                  v-else-if="st.status === 'absent_without_leave'"
                  class="rounded-full bg-rose-100 text-rose-800 border border-rose-200 px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1"
                >
                  <XCircle class="h-3 w-3" /> Vắng không phép
                </span>
                <span
                  v-else-if="st.status === 'paused'"
                  class="rounded-full bg-slate-200 text-slate-700 px-2.5 py-0.5 text-xs font-bold"
                >
                  Đang tạm dừng
                </span>
                <span
                  v-else
                  class="rounded-full bg-teal-100 text-teal-800 border border-teal-200 px-2.5 py-0.5 text-xs font-semibold"
                >
                  Chưa điểm danh
                </span>
              </div>

              <div class="text-xs text-slate-600 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                <span v-if="st.course" class="font-semibold text-blue-700">
                  [{{ st.course.name }}]
                </span>
                <span>Phòng: <strong class="text-slate-800">{{ st.room }}</strong></span>
                <span>Giờ: <strong class="text-slate-800">{{ st.shift_time }}</strong></span>
                <span>GV: {{ st.teacher }}</span>
                <span>
                  Tiến độ: <strong class="text-indigo-700">Buổi {{ st.current_session }}/{{ st.total_sessions }}</strong> (Còn {{ st.remaining_sessions }} buổi)
                </span>
              </div>

              <!-- Ghi chú nhận xét bài học -->
              <div v-if="st.checkin_note" class="text-xs text-slate-500 italic pt-0.5">
                📝 <strong>Nhận xét:</strong> {{ st.checkin_note }}
              </div>
            </div>

            <!-- Nút Hành Động 1-Click Điểm Danh -->
            <div class="flex items-center gap-1.5 flex-wrap shrink-0">
              <!-- Nút [ Đi học ] -->
              <button
                type="button"
                @click="markStudent(st, 'present')"
                :class="[
                  'rounded-xl px-3 py-1.5 text-xs font-bold shadow-2xs transition-all cursor-pointer',
                  st.status === 'present'
                    ? 'bg-emerald-600 text-white'
                    : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100'
                ]"
              >
                ✔ Đi học
              </button>

              <!-- Nút [ Vắng phép ] -->
              <button
                type="button"
                @click="markStudent(st, 'absent_with_leave', 'Nghỉ có phép')"
                :class="[
                  'rounded-xl px-2.5 py-1.5 text-xs font-medium transition-all cursor-pointer',
                  st.status === 'absent_with_leave'
                    ? 'bg-amber-500 text-white font-bold'
                    : 'border border-amber-200 bg-amber-50/70 text-amber-800 hover:bg-amber-100'
                ]"
              >
                Vắng có phép
              </button>

              <!-- Nút [ Vắng không phép ] -->
              <button
                type="button"
                @click="markStudent(st, 'absent_without_leave', 'Vắng không phép')"
                :class="[
                  'rounded-xl px-2.5 py-1.5 text-xs font-medium transition-all cursor-pointer',
                  st.status === 'absent_without_leave'
                    ? 'bg-rose-600 text-white font-bold'
                    : 'border border-rose-200 bg-rose-50/70 text-rose-700 hover:bg-rose-100'
                ]"
              >
                Vắng K.phép
              </button>

              <!-- Nút [ Hoàn tác ] -->
              <button
                v-if="st.status !== 'not_checked'"
                type="button"
                @click="markStudent(st, 'not_checked')"
                title="Hoàn tác trạng thái chưa điểm danh"
                class="rounded-xl border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50 cursor-pointer"
              >
                <RotateCcw class="h-3.5 w-3.5" />
              </button>

              <!-- Nút [ Nhận xét bài học ] -->
              <button
                type="button"
                @click="openNoteModal(st)"
                title="Ghi nhận xét bài học hôm nay"
                class="rounded-xl border border-slate-200 p-1.5 text-slate-600 hover:bg-slate-50 cursor-pointer"
              >
                <FileEdit class="h-3.5 w-3.5" />
              </button>

              <!-- Nút [ Zalo ] -->
              <button
                type="button"
                @click="openMessageModal(st)"
                title="Gửi báo cáo qua Zalo"
                class="rounded-xl border border-blue-200 bg-blue-50/50 p-1.5 text-blue-600 hover:bg-blue-100 cursor-pointer"
              >
                <MessageSquare class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL 1: ĐẶT BUỔI HỌC LẺ (Buổi bù / Tăng cường) -->
    <div
      v-if="showSingleLessonModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <div class="flex items-center gap-2">
            <Calendar class="h-5 w-5 text-rose-600" />
            <h3 class="text-base font-bold text-slate-900">Đặt Buổi Học Lẻ / Học Bù</h3>
          </div>
          <button
            type="button"
            @click="showSingleLessonModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 cursor-pointer"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitSingleLesson" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Chọn Học Viên (*)</label>
            <SearchableSelect
              v-model="singleLessonForm.student_id"
              :options="allStudentsList"
              placeholder="Tìm kiếm và chọn học viên..."
            />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Ngày học (*)</label>
              <input
                v-model="singleLessonForm.date"
                type="date"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Khung giờ (*)</label>
              <input
                v-model="singleLessonForm.time"
                type="text"
                placeholder="VD: 18:00 - 19:00"
                required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Phòng học</label>
              <select
                v-model="singleLessonForm.room"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              >
                <option v-for="r in rooms" :key="r" :value="r">{{ r }}</option>
              </select>
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Giáo viên phụ trách</label>
              <select
                v-model="singleLessonForm.teacher"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
              >
                <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Lý do / Ghi chú</label>
            <input
              v-model="singleLessonForm.note"
              type="text"
              placeholder="VD: Học bù buổi vắng tuần trước..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            />
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showSingleLessonModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 cursor-pointer"
            >
              Hủy
            </button>
            <button
              type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs cursor-pointer"
            >
              Lưu buổi học
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 2: GHI NHẬN XÉT BÀI HỌC -->
    <div
      v-if="showNoteModal && selectedStudent"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">Nhận xét buổi học: {{ selectedStudent.name }}</h3>
          <button
            type="button"
            @click="showNoteModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 cursor-pointer"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitNote" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Nội dung nhận xét & dặn dò</label>
            <textarea
              v-model="noteForm.content"
              rows="3"
              placeholder="VD: Bé giữ nhịp rất tốt, ngón tay thả lỏng hơn. Về nhà tập thêm trang 15 sách Alfred..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"
            ></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button
              type="button"
              @click="showNoteModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 cursor-pointer"
            >
              Hủy
            </button>
            <button
              type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs cursor-pointer"
            >
              Lưu nhận xét
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 3: MẪU TIN NHẮN ZALO -->
    <MessageTemplateModal
      :show="showMessageModal"
      :student="selectedStudent"
      :templates="attendanceTemplates"
      @close="showMessageModal = false"
    />
  </div>
</template>
