<script setup>
import { toLocalDateString } from '../services/date';
import { notify, errorMessage } from '../services/notify';
import { ref, onMounted, computed } from 'vue';
import { dailyAttendanceApi, studentApi } from '../services/api';
import SearchableSelect from '../components/SearchableSelect.vue';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import Pagination from '../components/Pagination.vue';
import ExpandableText from '../components/ExpandableText.vue';
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
  Mail,
  Info,
  CalendarOff,
  Trash2,
} from 'lucide-vue-next';

// Bộ lọc
const selectedDate = ref(toLocalDateString());
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
// Phân trang danh sách điểm danh (metrics vẫn tính trên toàn bộ học viên trong ngày)
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);
const centers = ref([]);
const classes = ref([]);
const rooms = ref([]);
const teachers = ref([]);
const parentLeaveRequests = ref([]);
// Giáo viên chỉ xem + ghi báo bài; giáo vụ / quản lý / chủ trung tâm mới điểm danh
const canMark = ref(true);
const showLeaveRequests = ref(false);

// Modals
const showSingleLessonModal = ref(false);
const showNoteModal = ref(false);
const showMessageModal = ref(false);
const allStudentsList = ref([]);
const allStudentsRaw = ref([]);
const selectedStudent = ref(null);
const availableGroupSlots = ref([]);
const loadingGroupSlots = ref(false);
const showMergeInfoTooltip = ref(false);

// Form đặt buổi học lẻ
const singleLessonForm = ref({
  center: 'all',
  student_id: '',
  course_id: '',
  date: toLocalDateString(),
  room: '',
  teacher: '',
  time: '18:00',
  merged_slot_id: '',
  merged_class_id: null,
  note: '',
  is_makeup: false,
});

// Form nhận xét
const noteForm = ref({
  content: '',
});

// Mẫu tin nhắn
const attendanceTemplates = ref([
  {
    id: 1,
    title: 'Báo bài buổi học gửi phụ huynh',
    template_content: 'Chào phụ huynh bé {ten_hoc_vien}! Trung tâm gửi báo bài buổi học {khoa_hoc} ngày {ngay_hoc}:\n\n{nhan_xet}\n\nCon còn {so_buoi_con_lai} buổi học. Ba mẹ nhớ nhắc con luyện tập ở nhà nhé ạ! ❤️',
  },
  {
    id: 2,
    title: 'Thông báo học viên vắng học',
    template_content: 'Dạ trung tâm xin chào phụ huynh {ten_hoc_vien}! Hôm nay không thấy bé đến lớp học môn {khoa_hoc}, trung tâm nhắn tin hỏi thăm tình hình sức khỏe của con ạ. Ba mẹ phản hồi giúp trung tâm để xếp lịch học bù cho bé nhé!',
  },
]);

// Ngày của danh sách đang hiển thị: điểm danh luôn gửi ngày này (không phải ngày vừa chọn mà danh sách chưa tải xong),
// tránh ghi buổi của ngày mới vào ca của ngày cũ khi đổi ngày rồi bấm quá nhanh
const loadedDate = ref('');
let fetchSeq = 0;

const fetchData = async () => {
  const seq = ++fetchSeq;
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
      page: page.value,
      per_page: perPage.value,
    });
    // Đổi ngày liên tục: bỏ kết quả của lần tải cũ về muộn
    if (seq !== fetchSeq) return;
    loadedDate.value = res.data.date;
    pagination.value = res.data.pagination || null;
    dayOfWeek.value = res.data.day_of_week || 'CN';
    dayOfWeekEn.value = res.data.day_of_week_en || 'Sunday';
    metrics.value = res.data.metrics || {};
    students.value = res.data.students || [];
    centers.value = res.data.centers || [];
    classes.value = res.data.classes || [];
    rooms.value = res.data.rooms || [];
    teachers.value = res.data.teachers || [];
    parentLeaveRequests.value = res.data.parent_leave_requests || [];
    canMark.value = res.data.can_mark !== false;
  } catch (err) {
    console.error('Lỗi tải dữ liệu điểm danh:', err);
  } finally {
    if (seq === fetchSeq) loading.value = false;
  }
};

// Danh sách đang hiển thị chưa phải của ngày đang chọn (vừa đổi ngày, đang tải)
const listOutdated = () => {
  if (loading.value || loadedDate.value !== selectedDate.value) {
    notify.warning('Đang tải danh sách của ngày vừa chọn, vui lòng bấm lại sau giây lát.');
    return true;
  }
  return false;
};

const jumpToDate = (rawDate) => {
  if (rawDate) {
    selectedDate.value = rawDate;
    fetchData();
  }
};

const getStudentCourses = (st) => {
  if (!st) return [];
  const list = st.registered_courses_list || st.registered_courses;
  if (Array.isArray(list) && list.length > 0) {
    return list.map((c) => ({
      id: c.course_id || c.id,
      name: `${c.course_name || 'Khóa học'} (${c.attended_sessions ?? Math.max(0, (c.total_sessions || 12) - (c.remaining_sessions || 12))}/${c.total_sessions || 12} đã điểm danh)`,
    }));
  }
  const total = st.total_sessions || 12;
  const rem = st.remaining_sessions ?? 12;
  const attended = Math.max(0, total - rem);
  return [
    {
      id: st.course_id || 1,
      name: `${st.course?.name || 'Khóa học'} (${attended}/${total} đã điểm danh)`,
    },
  ];
};

const filteredStudentsForModal = computed(() => {
  if (!singleLessonForm.value.center || singleLessonForm.value.center === 'all') {
    return allStudentsRaw.value;
  }
  return allStudentsRaw.value.filter((s) => s.center === singleLessonForm.value.center);
});

const coursesForSelectedStudent = computed(() => {
  const st = allStudentsRaw.value.find((s) => s.id === Number(singleLessonForm.value.student_id));
  return getStudentCourses(st);
});

const fetchAvailableGroupSlots = async () => {
  try {
    loadingGroupSlots.value = true;
    const res = await dailyAttendanceApi.getAvailableGroupSlots({
      date: singleLessonForm.value.date,
      teacher: singleLessonForm.value.teacher,
      center: singleLessonForm.value.center || 'all',
    });
    availableGroupSlots.value = res.data.slots || [];
    if (singleLessonForm.value.merged_slot_id && !availableGroupSlots.value.some((s) => s.id === singleLessonForm.value.merged_slot_id)) {
      singleLessonForm.value.merged_slot_id = '';
      singleLessonForm.value.merged_class_id = null;
    }
  } catch (err) {
    console.error('Lỗi tải ca dạy nhóm:', err);
    availableGroupSlots.value = [];
  } finally {
    loadingGroupSlots.value = false;
  }
};

const onStudentChange = () => {
  const st = allStudentsRaw.value.find((s) => s.id === Number(singleLessonForm.value.student_id));
  if (st) {
    const list = getStudentCourses(st);
    singleLessonForm.value.course_id = list[0]?.id || st.course_id || '';
    if (st.center && (!singleLessonForm.value.center || singleLessonForm.value.center === 'all')) {
      singleLessonForm.value.center = st.center;
    }
    if (st.room) {
      singleLessonForm.value.room = String(st.room).split(',')[0].trim();
    }
    if (st.teacher) {
      singleLessonForm.value.teacher = String(st.teacher).split(',')[0].trim();
    }
    fetchAvailableGroupSlots();
  }
};

const onMergedSlotChange = () => {
  if (!singleLessonForm.value.merged_slot_id) {
    singleLessonForm.value.merged_class_id = null;
    return;
  }
  const slot = availableGroupSlots.value.find((s) => s.id === singleLessonForm.value.merged_slot_id);
  if (slot) {
    singleLessonForm.value.merged_class_id = slot.class_id;
    if (slot.start) {
      singleLessonForm.value.time = slot.start + (slot.end ? ` - ${slot.end}` : '');
    }
    if (slot.teacher) {
      singleLessonForm.value.teacher = slot.teacher;
    }
    if (slot.room) {
      singleLessonForm.value.room = slot.room;
    }
  }
};

const loadAllStudents = async () => {
  try {
    const res = await studentApi.getAll({ status: 'active' });
    allStudentsRaw.value = res.data.students || [];
    allStudentsList.value = (res.data.students || []).map((s) => ({
      id: s.id,
      name: `${s.name} (${s.code}) - ${s.course?.name || 'Khóa học'}`,
    }));
  } catch (err) {
    console.error('Lỗi tải danh sách học viên:', err);
  }
};

// Đổi bộ lọc thì quay về trang 1
const applyFilters = () => {
  page.value = 1;
  fetchData();
};

const changePage = (p) => {
  page.value = p;
  fetchData();
};

const changePerPage = (n) => {
  perPage.value = n;
  applyFilters();
};

onMounted(() => {
  fetchData();
  loadAllStudents();
});

// Điểm danh (Có mặt / Vắng / Hoàn tác)
const markStudent = async (student, status, note = '') => {
  if (listOutdated()) return;
  try {
    // Điểm danh theo từng ca (một học viên có thể nhiều ca trong ngày)
    const res = await dailyAttendanceApi.mark(student.id, {
      date: loadedDate.value,
      status,
      note,
      slot_id: student.slot_id ?? null,
    });
    // Nghỉ có phép khi đã hết số buổi được nghỉ → bị trừ buổi như vắng không phép
    if (status === 'absent_with_leave' && res.data.deducts_session) {
      notify.warning(res.data.message);
    }
    await fetchData();
  } catch (err) {
    notify.error('Lỗi điểm danh', { description: errorMessage(err, err.message) });
  }
};

// Mở modal Đặt buổi học lẻ
const openSingleLessonModal = () => {
  const targetCenter = selectedCenter.value || 'all';
  const availableStudents = targetCenter === 'all'
    ? allStudentsRaw.value
    : allStudentsRaw.value.filter((s) => s.center === targetCenter);
  const defaultStudent = availableStudents[0] || allStudentsRaw.value[0] || null;
  const defaultCourses = defaultStudent ? getStudentCourses(defaultStudent) : [];
  const defaultTeacher = defaultStudent?.teacher
    ? String(defaultStudent.teacher).split(',')[0].trim()
    : ((teachers.value[0] && teachers.value[0] !== 'all') ? teachers.value[0] : '');
  const defaultRoom = defaultStudent?.room
    ? String(defaultStudent.room).split(',')[0].trim()
    : (rooms.value[0] || '');

  singleLessonForm.value = {
    center: targetCenter !== 'all' ? targetCenter : (defaultStudent?.center || 'all'),
    student_id: defaultStudent?.id || '',
    course_id: defaultCourses[0]?.id || defaultStudent?.course_id || '',
    date: selectedDate.value || toLocalDateString(),
    room: defaultRoom,
    teacher: defaultTeacher,
    time: '18:00',
    merged_slot_id: '',
    merged_class_id: null,
    note: '',
    is_makeup: false,
  };
  showMergeInfoTooltip.value = false;
  showSingleLessonModal.value = true;
  fetchAvailableGroupSlots();
};

const submitSingleLesson = async () => {
  if (!singleLessonForm.value.student_id) {
    notify.warning('Vui lòng chọn học viên!');
    return;
  }
  const payload = {
    student_id: singleLessonForm.value.student_id,
    course_id: singleLessonForm.value.course_id,
    center: singleLessonForm.value.center,
    date: singleLessonForm.value.date,
    room: singleLessonForm.value.room,
    time: singleLessonForm.value.time,
    teacher: singleLessonForm.value.teacher,
    merged_slot_id: singleLessonForm.value.merged_slot_id || null,
    merged_class_id: singleLessonForm.value.merged_class_id || null,
    note: singleLessonForm.value.note,
    is_makeup: singleLessonForm.value.is_makeup,
  };
  try {
    const res = await dailyAttendanceApi.scheduleSingleLesson(payload);
    showSingleLessonModal.value = false;
    // Trùng giờ ca học cố định: buổi bù thay cho ca đó, báo rõ cho giáo vụ
    if (res.data.replaces_regular) notify.warning(res.data.message);
    else notify.success(singleLessonForm.value.is_makeup ? 'Đã đặt buổi học bù thành công!' : 'Đã đặt buổi học lẻ thành công!');
    await fetchData();
  } catch (err) {
    notify.apiError(err, err.message);
  }
};

// Xóa buổi học lẻ / học bù đặt nhầm (slot_id của dòng là id buổi)
const deleteSingleLesson = async (st) => {
  const label = st.is_makeup ? 'buổi học bù' : 'buổi học lẻ';
  if (!confirm(`Xóa ${label} ${st.shift_time || ''} của học viên ${st.name}?`)) return;
  try {
    const res = await dailyAttendanceApi.deleteSingleLesson(st.slot_id);
    notify.success(res.data.message);
    await fetchData();
  } catch (err) {
    notify.apiError(err, `Không xóa được ${label}`);
  }
};

// Mở modal Nhận xét buổi học
const openNoteModal = (student) => {
  selectedStudent.value = student;
  // Chưa báo bài thì để trống (không hiện ghi chú tự điền như "Điểm danh buổi #3")
  noteForm.value.content = student.has_report ? student.checkin_note : '';
  showNoteModal.value = true;
};

const submitNote = async () => {
  if (!selectedStudent.value) return;
  if (!canMark.value) {
    // Giáo viên: chỉ lưu báo bài cho buổi đã được điểm danh
    try {
      if (listOutdated()) return;
      const res = await dailyAttendanceApi.saveNote(selectedStudent.value.id, {
        date: loadedDate.value,
        slot_id: selectedStudent.value.slot_id ?? null,
        note: noteForm.value.content,
      });
      notify.success(res.data.message);
      showNoteModal.value = false;
      await fetchData();
    } catch (err) {
      notify.apiError(err, 'Không lưu được báo bài');
    }
    return;
  }
  await markStudent(selectedStudent.value, selectedStudent.value.status === 'not_checked' ? 'present' : selectedStudent.value.status, noteForm.value.content);
  showNoteModal.value = false;
};

// Mở modal mẫu tin nhắn
const openMessageModal = (student) => {
  // Kèm ngày học đang xem để mẫu tin nhắn điền {ngay_hoc}
  const [y, m, d] = selectedDate.value.split('-');
  selectedStudent.value = { ...student, lesson_date: `${d}/${m}/${y}` };
  showMessageModal.value = true;
};

</script>

<template>
  <div class="space-y-6 pb-12">
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
            <input v-model="searchQuery" @keyup.enter="applyFilters" type="text" placeholder="Tìm học viên..."
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 placeholder:text-slate-400 focus:border-rose-500 focus:outline-none" />
          </div>

          <!-- Nút Đặt buổi học lẻ (Đỏ / Rose - Khớp 100% hình tham khảo) -->
          <button v-if="canMark" type="button" @click="openSingleLessonModal"
            class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 px-3.5 py-1.5 text-xs sm:text-sm font-semibold text-white shadow-2xs transition-all active:scale-[0.98] cursor-pointer">
            <Calendar class="h-3.5 w-3.5" />
            <span>Đặt buổi học lẻ</span>
          </button>
        </div>
      </div>

      <!-- Filter Bar: Ngày | Trung tâm | Lớp học | Phòng học | Giáo viên | Ca học (Khớp 100% hình tham khảo) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 pt-1 border-t border-slate-100">
        <!-- Ngày -->
        <div>
          <input v-model="selectedDate" type="date" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none" />
        </div>

        <!-- Trung tâm -->
        <div>
          <select v-model="selectedCenter" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <!-- Lớp học -->
        <div>
          <select v-model="selectedClass" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả lớp học</option>
            <option v-for="cl in classes" :key="cl" :value="cl">{{ cl }}</option>
          </select>
        </div>

        <!-- Phòng học -->
        <div>
          <select v-model="selectedRoom" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả phòng học</option>
            <option v-for="r in rooms" :key="r" :value="r">{{ r }}</option>
          </select>
        </div>

        <!-- Giáo viên -->
        <div>
          <select v-model="selectedTeacher" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả giáo viên</option>
            <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>

        <!-- Ca học -->
        <div>
          <select v-model="selectedShift" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
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
        <div class="rounded-xl border border-indigo-300 bg-indigo-50/30 p-3 space-y-1 dark:bg-slate-900/80 dark:border-indigo-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300">
            <span class="h-2 w-2 rounded-full bg-indigo-600"></span>
            <span>HỌC VIÊN HÔM NAY</span>
          </div>
          <div class="text-2xl font-black text-indigo-700 dark:text-indigo-400">
            {{ metrics.total_today || 0 }}
          </div>
        </div>

        <!-- 2. ĐI HỌC THỰC TẾ (Viền xanh dương) -->
        <div class="rounded-xl border border-blue-300 bg-blue-50/30 p-3 space-y-1 dark:bg-slate-900/80 dark:border-blue-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-blue-900 dark:text-blue-300">
            <span class="h-2 w-2 rounded-full bg-blue-600"></span>
            <span>ĐI HỌC THỰC TẾ</span>
          </div>
          <div class="text-2xl font-black text-blue-700 dark:text-blue-400">
            {{ metrics.actual_present || 0 }}
          </div>
        </div>

        <!-- 3. ĐANG TẠM DỪNG (Viền vàng / cam) -->
        <div class="rounded-xl border border-amber-300 bg-amber-50/30 p-3 space-y-1 dark:bg-slate-900/80 dark:border-amber-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-amber-900 dark:text-amber-300">
            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
            <span>ĐANG TẠM DỪNG</span>
          </div>
          <div class="text-2xl font-black text-amber-700 dark:text-amber-400">
            {{ metrics.paused || 0 }}
          </div>
        </div>

        <!-- 4. HỌC SINH VẮNG (Viền đỏ / rose) -->
        <div class="rounded-xl border border-rose-300 bg-rose-50/30 p-3 space-y-1 dark:bg-slate-900/80 dark:border-rose-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-rose-900 dark:text-rose-300">
            <span class="h-2 w-2 rounded-full bg-rose-600"></span>
            <span>HỌC SINH VẮNG</span>
          </div>
          <div class="text-2xl font-black text-rose-700 dark:text-rose-400">
            {{ metrics.absent || 0 }}
          </div>
        </div>

        <!-- 5. CHƯA ĐIỂM DANH (Viền xanh teal / ngọc) -->
        <div class="rounded-xl border border-teal-300 bg-teal-50/30 p-3 space-y-1 dark:bg-slate-900/80 dark:border-teal-500/40">
          <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-teal-900 dark:text-teal-300">
            <span class="h-2 w-2 rounded-full bg-teal-600"></span>
            <span>CHƯA ĐIỂM DANH</span>
          </div>
          <div class="text-2xl font-black text-teal-700 dark:text-teal-400">
            {{ metrics.not_checked || 0 }}
          </div>
        </div>
      </div>

      <!-- 6. Banner Đơn xin nghỉ phép gần đây từ Cổng phụ huynh -->
      <div v-if="parentLeaveRequests && parentLeaveRequests.length > 0"
        class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 space-y-3">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="flex h-2.5 w-2.5 relative">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
            </span>
            <h3 class="text-xs sm:text-sm font-bold text-amber-950 flex items-center gap-1.5">
              <Mail class="h-4 w-4 text-amber-600" />
              <span>Đơn xin nghỉ phép gần đây từ Cổng phụ huynh ({{ parentLeaveRequests.length }} đơn)</span>
            </h3>
          </div>
          <button type="button" @click="showLeaveRequests = !showLeaveRequests"
            class="text-xs font-bold text-amber-800 hover:text-amber-950 underline cursor-pointer">
            {{ showLeaveRequests ? 'Thu gọn ▲' : 'Xem chi tiết đơn ▼' }}
          </button>
        </div>

        <div v-if="showLeaveRequests"
          class="divide-y divide-amber-100/80 bg-white rounded-xl border border-amber-200/60 overflow-hidden text-xs">
          <div v-for="req in parentLeaveRequests" :key="req.id"
            class="p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 hover:bg-amber-50/30 transition-colors">
            <div class="space-y-0.5">
              <div class="flex items-center gap-2 flex-wrap">
                <strong class="text-slate-900 font-bold uppercase">{{ req.student_name }}</strong>
                <span class="text-slate-400 font-mono text-[11px]">({{ req.student_code }})</span>
                <span
                  class="bg-amber-100 text-amber-900 px-2 py-0.5 rounded-md font-bold text-[11px] border border-amber-200">
                  Nghỉ ngày: {{ req.leave_date }}
                </span>
                <span v-if="req.leave_date_raw === selectedDate"
                  class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-md font-bold text-[10px]">
                  Hôm nay
                </span>
              </div>
              <p v-if="req.reason" class="text-slate-700 italic">
                Lý do: "{{ req.reason }}"
              </p>
              <p class="text-[11px] text-slate-400">
                Khóa: {{ req.course_name }} &bull; Phụ huynh: {{ req.parent_name }} ({{ req.parent_phone }}) &bull; Gửi
                lúc: {{ req.created_at }}
              </p>
            </div>
            <button type="button" @click="jumpToDate(req.leave_date_raw)"
              class="shrink-0 px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs transition-colors flex items-center gap-1 shadow-xs cursor-pointer">
              <Calendar class="h-3.5 w-3.5" />
              <span>Xem ngày điểm danh</span>
            </button>
          </div>
        </div>
      </div>

      <!-- State: Khi không có học viên nào theo lịch hôm nay (Khớp 100% hình tham khảo Seny) -->
      <div v-if="students.length === 0" class="py-10 text-xs sm:text-sm text-slate-500 text-left">
        Không có học viên nào có lịch học {{ dayOfWeek }}.
      </div>

      <!-- State: Danh sách học viên cần điểm danh hôm nay -->
      <div v-else class="divide-y divide-slate-100 pt-2 space-y-4">
        <div v-for="st in students" :key="st.row_key || st.id" class="pt-4 first:pt-0 space-y-2.5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <!-- Tên học viên, Khóa học, Phòng học, Ca học -->
            <div class="space-y-1">
              <div class="flex items-center gap-2 flex-wrap">
                <router-link :to="`/students/${st.id}`"
                  class="font-bold text-slate-900 hover:text-blue-600 text-sm sm:text-base uppercase dark:text-white dark:hover:text-blue-400">
                  {{ st.name }}
                </router-link>
                <span class="text-xs font-mono text-slate-400 dark:text-white/60">({{ st.code }})</span>
                <span v-if="st.is_makeup"
                  class="rounded-full bg-sky-100 text-sky-800 border border-sky-200 px-2 py-0.5 text-[11px] font-bold dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-700/60">
                  Buổi bù
                </span>

                <!-- Trạng thái điểm danh -->
                <span v-if="st.is_parent_request"
                  class="rounded-full bg-amber-500 text-white px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1 shadow-xs">
                  <Mail class="h-3 w-3" /> PH xin nghỉ qua Cổng
                </span>
                <span v-else-if="st.status === 'present'"
                  class="rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-700/60">
                  <CheckCircle2 class="h-3 w-3" /> Đi học thực tế
                </span>
                <span v-else-if="st.status === 'absent_with_leave'"
                  class="rounded-full bg-amber-100 text-amber-800 border border-amber-200 px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-700/60">
                  <AlertTriangle class="h-3 w-3" /> Vắng có phép
                </span>
                <span v-else-if="st.status === 'absent_without_leave'"
                  class="rounded-full bg-rose-100 text-rose-800 border border-rose-200 px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-700/60">
                  <XCircle class="h-3 w-3" /> Vắng không phép
                </span>
                <span v-else-if="st.status === 'paused'"
                  class="rounded-full bg-slate-200 text-slate-700 px-2.5 py-0.5 text-xs font-bold dark:bg-slate-800 dark:text-white/80">
                  Đang tạm dừng
                </span>
                <span v-else-if="st.status === 'off'"
                  class="rounded-full bg-violet-100 text-violet-800 border border-violet-200 px-2.5 py-0.5 text-xs font-bold inline-flex items-center gap-1 dark:bg-violet-950/60 dark:text-violet-300 dark:border-violet-700/60">
                  <CalendarOff class="h-3 w-3" /> {{ st.off_label }}
                </span>
                <span v-else
                  class="rounded-full bg-teal-100 text-teal-800 border border-teal-200 px-2.5 py-0.5 text-xs font-semibold dark:bg-teal-950/60 dark:text-teal-300 dark:border-teal-700/60">
                  Chưa điểm danh
                </span>
              </div>

              <div class="text-xs text-slate-600 flex flex-wrap items-center gap-x-3 gap-y-0.5 dark:text-white/70">
                <span v-if="st.course" class="font-semibold text-blue-700 dark:text-blue-400">
                  [{{ st.course.name }}]
                </span>
                <span>Phòng: <strong class="text-slate-800 dark:text-white">{{ st.room }}</strong></span>
                <span>Giờ: <strong class="text-slate-800 dark:text-white">{{ st.shift_time }}</strong></span>
                <span>GV: <span class="dark:text-white/90">{{ st.teacher }}</span></span>
                <span>
                  <!-- Buổi đã trừ buổi (đi học / vắng bị trừ, kể cả học bù / học lẻ): "Buổi N" như Cổng phụ huynh.
                       Chưa điểm danh, nghỉ có phép không trừ buổi, buổi off: chỉ hiện số đã học -->
                  <template v-if="st.deducts_session && st.current_session">
                    Tiến độ: <strong class="text-indigo-700 dark:text-indigo-400">Buổi {{ st.current_session }}/{{ st.total_sessions }}</strong>
                  </template>
                  <template v-else>
                    Tiến độ: <strong class="text-indigo-700 dark:text-indigo-400">Đã học {{ st.attended_sessions }}/{{ st.total_sessions }}</strong>
                  </template>
                  (Còn {{ st.remaining_sessions }} buổi)
                </span>
                <!-- Nghỉ có phép trong số buổi được nghỉ thì không trừ buổi; vắng không phép / hết lượt nghỉ thì trừ 1 buổi -->
                <span v-if="st.status === 'absent_with_leave' && !st.deducts_session" class="font-semibold text-amber-700 dark:text-amber-400">
                  Không trừ buổi · còn được nghỉ {{ st.leave_left }}/{{ st.allowed_absences }}
                </span>
                <span v-else-if="st.status.startsWith('absent') && st.deducts_session" class="font-semibold text-rose-700 dark:text-rose-400">
                  Đã trừ 1 buổi{{ st.status === 'absent_with_leave' ? ' (hết lượt nghỉ có phép)' : '' }}
                </span>
                <span v-else-if="st.status === 'off'" class="font-semibold text-violet-700 dark:text-violet-400">Không trừ buổi</span>
                <span v-else class="text-slate-500 dark:text-white/60">
                  Được nghỉ có phép: còn {{ st.leave_left }}/{{ st.allowed_absences }}
                </span>
              </div>

              <!-- Lý do phụ huynh gõ khi xin nghỉ (giữ nguyên dù giáo vụ đổi trạng thái); không có đơn thì không hiện -->
              <div v-if="st.parent_leave_reason"
                class="text-xs pt-0.5 text-amber-900 bg-amber-50 px-2.5 py-1.5 rounded-lg border border-amber-200 inline-block dark:bg-amber-950/40 dark:text-amber-200 dark:border-amber-700/60">
                <span class="font-bold">📩 Lý do phụ huynh xin nghỉ:</span>
                <ExpandableText :text="st.parent_leave_reason" class="mt-0.5" />
              </div>
              <!-- Nhận xét / báo bài giáo viên gõ (ghi chú tự điền khi bấm trạng thái không hiện) -->
              <div v-if="st.checkin_note" class="text-xs pt-0.5 text-slate-500 italic dark:text-white/60">
                <span class="font-bold">📝 Nhận xét:</span>
                <!-- Giữ xuống dòng của nhận xét / báo bài; dài thì thu gọn, có Xem thêm -->
                <ExpandableText :text="st.checkin_note" class="mt-0.5" />
              </div>
            </div>

            <!-- Buổi trung tâm nghỉ / giáo viên nghỉ: không điểm danh -->
            <p v-if="st.status === 'off'" class="text-xs text-violet-700 italic shrink-0 dark:text-violet-300">
              Không có buổi học, không cần điểm danh
            </p>

            <!-- Nút Hành Động 1-Click Điểm Danh -->
            <div v-else class="flex items-center gap-1.5 flex-wrap shrink-0">
              <!-- Giáo viên không điểm danh: chỉ ghi báo bài -->
              <template v-if="canMark">
              <!-- Nút [ Đi học ] -->
              <button type="button" @click="markStudent(st, 'present')" :class="[
                'rounded-xl px-3 py-1.5 text-xs font-bold shadow-2xs transition-all cursor-pointer',
                st.status === 'present'
                  ? 'bg-emerald-600 text-white'
                  : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:border-emerald-700/60 dark:text-emerald-300 dark:hover:bg-emerald-900/60'
              ]">
                ✔ Đi học
              </button>

              <!-- Nút [ Vắng phép ] -->
              <button type="button" @click="markStudent(st, 'absent_with_leave', 'Nghỉ có phép')" :class="[
                'rounded-xl px-2.5 py-1.5 text-xs font-medium transition-all cursor-pointer',
                st.status === 'absent_with_leave'
                  ? 'bg-amber-500 text-white font-bold'
                  : 'border border-amber-200 bg-amber-50/70 text-amber-800 hover:bg-amber-100 dark:bg-amber-950/40 dark:border-amber-700/60 dark:text-amber-300 dark:hover:bg-amber-900/60'
              ]">
                Vắng có phép
              </button>

              <!-- Nút [ Vắng không phép ] -->
              <button type="button" @click="markStudent(st, 'absent_without_leave', 'Vắng không phép')" :class="[
                'rounded-xl px-2.5 py-1.5 text-xs font-medium transition-all cursor-pointer',
                st.status === 'absent_without_leave'
                  ? 'bg-rose-600 text-white font-bold'
                  : 'border border-rose-200 bg-rose-50/70 text-rose-700 hover:bg-rose-100 dark:bg-rose-950/40 dark:border-rose-700/60 dark:text-rose-300 dark:hover:bg-rose-900/60'
              ]">
                Vắng K.phép
              </button>

              <!-- Nút [ Hoàn tác ] -->
              <button v-if="st.status !== 'not_checked'" type="button" @click="markStudent(st, 'not_checked')"
                title="Hoàn tác trạng thái chưa điểm danh"
                class="rounded-xl border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50 cursor-pointer dark:border-slate-700 dark:text-white/70 dark:hover:bg-slate-800 dark:hover:text-white">
                <RotateCcw class="h-3.5 w-3.5" />
              </button>

              <!-- Nút [ Xóa buổi học lẻ / học bù ] đặt nhầm -->
              <button v-if="st.is_single_lesson" type="button" @click="deleteSingleLesson(st)"
                :disabled="st.status !== 'not_checked'"
                :title="st.status !== 'not_checked' ? 'Hoàn tác điểm danh trước khi xóa buổi' : (st.is_makeup ? 'Xóa buổi học bù' : 'Xóa buổi học lẻ')"
                class="rounded-xl border border-rose-200 p-1.5 text-rose-600 hover:bg-rose-50 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-400 dark:hover:bg-rose-900/50">
                <Trash2 class="h-3.5 w-3.5" />
              </button>
              </template>
              <span v-else-if="st.status === 'not_checked'" class="text-[11px] text-slate-400 italic dark:text-white/60">Chờ giáo vụ điểm danh</span>

              <!-- Nút [ Nhận xét bài học ] -->
              <!-- Đã báo bài thì nút màu xanh -->
              <button type="button" @click="openNoteModal(st)"
                :disabled="!canMark && st.status === 'not_checked'"
                :title="!canMark && st.status === 'not_checked' ? 'Báo bài sau khi giáo vụ đã điểm danh' : st.has_report ? 'Đã báo bài, bấm để sửa nhận xét' : 'Chưa báo bài, bấm để ghi nhận xét bài học'"
                :class="[
                  'rounded-xl border p-1.5 cursor-pointer transition-colors disabled:opacity-40 disabled:cursor-not-allowed',
                  st.has_report
                    ? 'border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700'
                    : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-white/70 dark:hover:bg-slate-800 dark:hover:text-white',
                ]">
                <FileEdit class="h-3.5 w-3.5" />
              </button>

              <!-- Nút [ Mẫu tin nhắn ] -->
              <button type="button" @click="openMessageModal(st)" title="Mẫu tin nhắn báo cáo buổi học"
                class="rounded-xl border border-blue-200 bg-blue-50/50 p-1.5 text-blue-600 hover:bg-blue-100 cursor-pointer dark:border-blue-700/60 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-900/60">
                <MessageSquare class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>
        </div>
      </div>
      <Pagination :meta="pagination" class="mt-4" @change-page="changePage" @change-per-page="changePerPage" />
    </div>

    <!-- MODAL 1: ĐẶT BUỔI HỌC LẺ (Khớp 100% hình tham khảo) -->
    <div v-if="showSingleLessonModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4 max-h-[90vh] overflow-y-auto">
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">Đặt buổi học lẻ</h3>
          <button type="button" @click="showSingleLessonModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 cursor-pointer">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitSingleLesson" class="space-y-3.5 text-xs sm:text-sm">
          <!-- Trung tâm -->
          <div>
            <label class="block font-medium text-slate-700 mb-1">Trung tâm</label>
            <select v-model="singleLessonForm.center" @change="fetchAvailableGroupSlots"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 focus:border-rose-500 focus:outline-none cursor-pointer">
              <option value="all">Tất cả trung tâm</option>
              <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>

          <!-- Học viên -->
          <div>
            <label class="block font-medium text-slate-700 mb-1">Học viên</label>
            <!-- Tìm theo tên / mã học viên, không phân biệt dấu -->
            <SearchableSelect v-model="singleLessonForm.student_id" :options="filteredStudentsForModal"
              sublabel-key="code" placeholder="-- Chọn học viên --" search-placeholder="Tìm tên hoặc mã học viên..."
              required @change="onStudentChange" />
          </div>

          <!-- Khoá học -->
          <div>
            <label class="block font-medium text-slate-700 mb-1">Khoá học</label>
            <select v-model="singleLessonForm.course_id"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 focus:border-rose-500 focus:outline-none cursor-pointer">
              <option v-for="c in coursesForSelectedStudent" :key="c.id" :value="c.id">
                {{ c.name }}
              </option>
            </select>
          </div>

          <!-- Ngày & Giờ (VN) -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Ngày</label>
              <input v-model="singleLessonForm.date" type="date" @change="fetchAvailableGroupSlots" required
                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Giờ (VN)</label>
              <div class="relative">
                <input v-model="singleLessonForm.time" type="text" placeholder="VD: 18:00 - 19:00" required
                  class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 focus:border-rose-500 focus:outline-none pr-8" />
                <Clock class="absolute right-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none" />
              </div>
            </div>
          </div>

          <!-- Giáo viên (tuỳ chọn) -->
          <div>
            <label class="block font-medium text-slate-700 mb-1">Giáo viên (tuỳ chọn)</label>
            <select v-model="singleLessonForm.teacher" @change="fetchAvailableGroupSlots"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 focus:border-rose-500 focus:outline-none cursor-pointer">
              <option value="">— Chưa chọn giáo viên —</option>
              <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>

          <!-- Ghép vào ca dạy có sẵn (tuỳ chọn) -->
          <div>
            <!-- Banner / Tooltip thông báo đỏ (Chỉ hiện khi nhấn vào icon (i)) -->
            <transition enter-active-class="transition duration-150 ease-out"
              enter-from-class="opacity-0 -translate-y-1 scale-98" enter-to-class="opacity-100 translate-y-0 scale-100"
              leave-active-class="transition duration-100 ease-in"
              leave-from-class="opacity-100 translate-y-0 scale-100" leave-to-class="opacity-0 -translate-y-1 scale-98">
              <div v-if="showMergeInfoTooltip"
                class="mb-2 rounded-xl bg-[#dc2626] text-white p-3 text-xs leading-relaxed shadow-md relative">
                <div class="flex items-start justify-between gap-2">
                  <span>
                    Nếu học viên học ghép trong lớp nhóm đang có của giáo viên, hãy chọn ca dạy đó. Giáo viên sẽ không
                    được tính thêm 1 ca dạy riêng. Bỏ trống nếu đây là ca dạy riêng cho học viên này.
                  </span>
                  <button type="button" @click="showMergeInfoTooltip = false"
                    class="text-white/80 hover:text-white shrink-0 p-0.5 cursor-pointer" title="Đóng thông báo">
                    <X class="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </transition>

            <div class="flex items-center gap-1 mb-1">
              <label class="font-medium text-slate-700">Ghép vào ca dạy có sẵn (tuỳ chọn)</label>
              <button type="button" @click="showMergeInfoTooltip = !showMergeInfoTooltip"
                class="text-slate-400 hover:text-rose-600 transition-colors p-0.5 rounded cursor-pointer"
                title="Nhấn vào icon để xem hướng dẫn ghép ca">
                <Info class="h-3.5 w-3.5 inline" />
              </button>
            </div>
            <select v-model="singleLessonForm.merged_slot_id" @change="onMergedSlotChange"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 focus:border-rose-500 focus:outline-none cursor-pointer">
              <option value="">— Không ghép —</option>
              <option v-for="slot in availableGroupSlots" :key="slot.id" :value="slot.id">
                {{ slot.label }}
              </option>
            </select>
            <p v-if="availableGroupSlots.length === 0" class="text-xs text-slate-500 mt-1">
              Giáo viên này không có ca dạy nhóm còn trống vào ngày đã chọn.
            </p>
            <p v-else class="text-xs text-emerald-600 mt-1">
              Tìm thấy {{ availableGroupSlots.length }} ca dạy nhóm của giáo viên vào ngày đã chọn.
            </p>
          </div>

          <!-- Ghi chú (tuỳ chọn) -->
          <div>
            <div class="flex items-center gap-1 mb-1">
              <label class="font-medium text-slate-700">Ghi chú</label>
              <span title="Ghi chú về buổi học bù hoặc lý do" class="text-slate-400 hover:text-slate-600 cursor-help">
                <Info class="h-3.5 w-3.5 inline" />
              </span>
            </div>
            <input v-model="singleLessonForm.note" type="text" placeholder="Tùy chọn"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 placeholder:text-slate-400 focus:border-rose-500 focus:outline-none" />
          </div>

          <!-- Buổi học bù: cổng phụ huynh hiển thị là "Buổi bù" -->
          <label class="flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50/60 px-3 py-2 cursor-pointer">
            <input v-model="singleLessonForm.is_makeup" type="checkbox" class="mt-0.5 h-4 w-4 accent-sky-600" />
            <span>
              <span class="font-semibold text-slate-800">Đây là buổi học bù</span>
              <span class="block text-[11px] text-slate-500">Bù cho buổi nghỉ hoặc ngày trung tâm nghỉ. Phụ huynh sẽ thấy
                "Buổi bù" trên cổng thông tin.</span>
            </span>
          </label>

          <!-- Nút Lưu lịch & Lưu ý chân trang -->
          <div class="pt-2">
            <button type="submit"
              class="w-full rounded-xl bg-[#dc2626] hover:bg-[#b91c1c] px-5 py-2.5 font-bold text-white shadow-2xs transition-all active:scale-[0.99] cursor-pointer text-sm">
              Lưu lịch
            </button>
            <p class="text-center text-xs text-slate-400 mt-2">
              Lịch học hàng tuần gốc giữ nguyên.
            </p>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 2: GHI NHẬN XÉT BÀI HỌC -->
    <div v-if="showNoteModal && selectedStudent"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">Nhận xét buổi học: {{ selectedStudent.name }}</h3>
          <button type="button" @click="showNoteModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 cursor-pointer">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitNote" class="space-y-3.5 text-xs sm:text-sm">
          <div>
            <label class="block font-medium text-slate-700 mb-1">Nội dung nhận xét & dặn dò</label>
            <textarea v-model="noteForm.content" rows="3"
              placeholder="VD: Bé giữ nhịp rất tốt, ngón tay thả lỏng hơn. Về nhà tập thêm trang 15 sách Alfred..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"></textarea>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showNoteModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 cursor-pointer">
              Hủy
            </button>
            <button type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs cursor-pointer">
              Lưu nhận xét
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 3: MẪU TIN NHẮN -->
    <MessageTemplateModal :show="showMessageModal" :student="selectedStudent" :templates="attendanceTemplates"
      @close="showMessageModal = false" />
  </div>
</template>
