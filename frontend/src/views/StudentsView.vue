<script setup>
import { toLocalDateString } from '../services/date';
import { confirmDialog } from '../services/useConfirm';
import { notify } from '../services/notify';
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { studentApi, courseApi } from '../services/api';
import Badge from '../components/Badge.vue';
import Modal from '../components/Modal.vue';
import AddSupplyModal from '../components/AddSupplyModal.vue';
import AttendanceButton from '../components/AttendanceButton.vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import DatePickerVN from '../components/DatePickerVN.vue';
import Pagination from '../components/Pagination.vue';
import ScheduleFields from '../components/ScheduleFields.vue';
import ImportStudentsModal from '../components/ImportStudentsModal.vue';
import { useCenters } from '../services/useCenters';
import {
  UserPlus,
  Search,
  Flame,
  AlertTriangle,
  Eye,
  ExternalLink,
  Edit3,
  Trash2,
  Phone,
  X,
  Package,
  CheckCircle2,
  Filter,
  Download,
  UploadCloud,
} from 'lucide-vue-next';
import MoneyInput from '../components/MoneyInput.vue';

const route = useRoute();

const students = ref([]);
const courses = ref([]);
const search = ref('');
const phoneSearch = ref('');
const courseId = ref('all');
const status = ref('all');
const centerFilter = ref('all');
const sessionsFilter = ref('all');
const tuitionFilter = ref('all');
const assignedClassFilter = ref('all');
const teacherFilter = ref('all');
const staffFilter = ref('all');
const sortFilter = ref('newest');
const alertFilter = ref('');
const attendanceStatusFilter = ref('all');
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);

const availableCenters = ref([]);
const availableTeachers = ref([]);
const availableStaff = ref([]);
const availableClasses = ref([]);

const filterCourseOptions = computed(() => [
  { id: 'all', name: 'Tất cả khóa học' },
  ...courses.value.map(c => ({ id: c.id, name: c.name }))
]);

const filterCenterOptions = computed(() => [
  { id: 'all', name: 'Tất cả trung tâm' },
  ...(availableCenters.value.length ? availableCenters.value : centerNames.value).map(c => ({ id: c, name: c }))
]);

const filterClassOptions = computed(() => [
  { id: 'all', name: 'Tất cả lớp học' },
  ...availableClasses.value.map(cls => ({ id: cls, name: cls }))
]);

const filterTeacherOptions = computed(() => [
  { id: 'all', name: 'Tất cả giáo viên' },
  ...availableTeachers.value.map(t => ({ id: t, name: t }))
]);

const filterStaffOptions = computed(() => [
  { id: 'all', name: 'Tất cả người phụ trách' },
  ...(availableStaff.value.length ? availableStaff.value : availableTeachers.value).map(s => ({ id: s, name: s }))
]);

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showSupplyModal = ref(false);
const showImportModal = ref(false);
const showInfoModal = ref(false);
const selectedSupplyStudent = ref(null);
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
  course_id: '',
  total_sessions: 12,
  status: 'active',
  join_date: toLocalDateString(),
  notes: '',
  create_invoice: true,
  invoice_amount: 3600000,
  center: '',
  class_days: [],
  class_start_time: '',
  class_end_time: '',
  teacher: '',
  room: '',
});
const { centerNames, defaultCenter, loadCenters } = useCenters();

const fetchData = async () => {
  try {
    const res = await studentApi.getAll({
      search: search.value,
      phone: phoneSearch.value,
      center: centerFilter.value,
      course_id: courseId.value,
      status: status.value,
      sessions_filter: sessionsFilter.value,
      tuition_status: tuitionFilter.value,
      alert: alertFilter.value,
      attendance_status: attendanceStatusFilter.value,
      assigned_class: assignedClassFilter.value,
      teacher: teacherFilter.value,
      staff: staffFilter.value,
      sort_by: sortFilter.value,
      page: page.value,
      per_page: perPage.value,
    });
    pagination.value = res.data.pagination || null;
    // Trang hiện tại không còn dữ liệu (ví dụ vừa xoá dòng cuối) thì lùi về trang cuối
    if (pagination.value && page.value > pagination.value.last_page && pagination.value.last_page >= 1) {
      page.value = pagination.value.last_page;
      return fetchData();
    }
    students.value = res.data.students || [];
    courses.value = res.data.courses || [];
    if (res.data.centers) availableCenters.value = res.data.centers;
    if (res.data.teachers) availableTeachers.value = res.data.teachers;
    if (res.data.staff_members) availableStaff.value = res.data.staff_members;
    if (res.data.classes) availableClasses.value = res.data.classes;
    if (courses.value.length > 0 && !newStudentForm.value.course_id) {
      newStudentForm.value.course_id = courses.value[0].id;
    }
  } catch (err) {
    console.error('Error fetching students: ', err);
  }
};

const handleDownloadTemplate = async () => {
  try {
    const res = await studentApi.downloadTemplate();
    const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', 'students_template.xlsx');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
    notify.success('Đã tải file mẫu students_template.xlsx!');
  } catch (err) {
    console.error('Error downloading template:', err);
    notify.error('Không thể tải file mẫu. Vui lòng thử lại!');
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
  loadCenters().then(() => {
    newStudentForm.value.center ||= defaultCenter.value;
  });
  if (route.query.create === 'true') {
    showCreateModal.value = true;
  }
});

watch(
  () => route.query.create,
  (val) => {
    if (val === 'true') {
      showCreateModal.value = true;
    }
  }
);

const courseOptions = computed(() => {
  return courses.value.map((c) => ({
    id: c.id,
    name: `${c.name} (${formatVND(c.price)})`,
    rawName: c.name,
    price: c.price,
    default_sessions: c.default_sessions,
  }));
});

const onCourseChange = (courseOpt) => {
  const cId = courseOpt?.id || Number(newStudentForm.value.course_id);
  const selectedCourse = courses.value.find((c) => c.id === cId);
  if (selectedCourse) {
    newStudentForm.value.total_sessions = selectedCourse.default_sessions || 12;
    newStudentForm.value.invoice_amount = selectedCourse.price || 3600000;
  }
};

const openSupplyModal = (student) => {
  selectedSupplyStudent.value = student;
  showSupplyModal.value = true;
};

const submitCreateStudent = async () => {
  if (!newStudentForm.value.name || !newStudentForm.value.parent_name || !newStudentForm.value.parent_phone) {
    notify.warning('Vui lòng điền đầy đủ Tên học viên, Tên phụ huynh và Số điện thoại');
    return;
  }

  try {
    isSubmitting.value = true;
    await studentApi.create(newStudentForm.value);
    showCreateModal.value = false;
    newStudentForm.value = {
      name: '',
      dob: '',
      gender: 'Nam',
      parent_name: '',
      parent_phone: '',
      parent_zalo: '',
      parent_email: '',
      course_id: courses.value[0]?.id || '',
      total_sessions: 12,
      status: 'active',
      join_date: toLocalDateString(),
      notes: '',
      create_invoice: true,
      invoice_amount: 3600000,
      center: defaultCenter.value,
      class_days: [],
      class_start_time: '',
      class_end_time: '',
      teacher: '',
      room: '',
    };
    applyFilters();
  } catch (err) {
    notify.apiError(err, 'Có lỗi xảy ra khi tạo học viên');
  } finally {
    isSubmitting.value = false;
  }
};

const openEditStudent = (student) => {
  editingStudent.value = { ...student, class_days: student.class_days || [] };
  showEditModal.value = true;
};

const submitEditStudent = async () => {
  if (!editingStudent.value) return;
  try {
    isSubmitting.value = true;
    await studentApi.update(editingStudent.value.id, editingStudent.value);
    showEditModal.value = false;
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Có lỗi xảy ra khi cập nhật');
  } finally {
    isSubmitting.value = false;
  }
};

const deleteStudent = async (student) => {
  if (await confirmDialog({
    title: 'Xoá học viên?',
    message: `Học viên "${student.name}" sẽ bị xoá khỏi hệ thống.`,
    confirmText: 'Xoá học viên',
  })) {
    try {
      await studentApi.delete(student.id);
      fetchData();
    } catch (err) {
      notify.apiError(err, 'Không thể xóa');
    }
  }
};


const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const getDotTooltip = (dot, idx) => {
  if (dot.status === 'present') {
    return `Buổi ${idx + 1}: Có mặt ${dot.date ? '(' + dot.date + ')' : ''}`;
  }
  if (dot.status === 'absent') {
    return `Buổi ${idx + 1}: Vắng mặt ${dot.date ? '(' + dot.date + ')' : ''}`;
  }
  if (dot.status === 'excused') {
    return `Buổi ${idx + 1}: Nghỉ có phép ${dot.date ? '(' + dot.date + ')' : ''}`;
  }
  return `Buổi ${idx + 1}: Chưa diễn ra`;
};

const getAttendanceSummaryTooltip = (student) => {
  const titles = {
    excellent: 'Chuyên cần xuất sắc (Chuỗi đi học đầy đủ)',
    good: 'Chuyên cần tốt',
    attention: 'Cần quan tâm (Vắng 1 buổi gần đây)',
    at_risk: 'Nguy cơ nghỉ học (Vắng 2 buổi liên tiếp trở lên)',
    returned: 'Đã quay lại học đều',
  };
  return titles[student.attendance_status] || 'Lịch sử 5 buổi gần nhất';
};
</script>

<template>
  <div class="space-y-5 w-full">
    <!-- Header matching Senyapp style -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-2xs">
      <div>
        <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900 flex items-center gap-2">
          Học viên <span class="text-sm font-semibold text-slate-400">({{ pagination?.total ?? students.length }})</span>
        </h1>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <!-- Mẫu Excel button -->
        <button
          type="button"
          @click="handleDownloadTemplate"
          class="inline-flex items-center gap-1.5 h-10 px-3.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-semibold hover:bg-slate-50 transition-colors shadow-2xs active:scale-[0.98]"
        >
          <Download class="h-4 w-4 text-slate-500" />
          <span>Mẫu Excel</span>
        </button>

        <!-- Import Excel/CSV button -->
        <button
          type="button"
          @click="showImportModal = true"
          class="inline-flex items-center gap-1.5 h-10 px-3.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-sm font-semibold hover:bg-slate-50 transition-colors shadow-2xs active:scale-[0.98]"
        >
          <UploadCloud class="h-4 w-4 text-slate-500" />
          <span>Import Excel</span>
        </button>

        <!-- + Học viên mới button -->
        <button
          type="button"
          @click="showCreateModal = true"
          class="inline-flex items-center gap-2 h-10 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white shadow-xs shadow-rose-600/20 hover:bg-rose-700 transition-all active:scale-[0.98]"
        >
          <UserPlus class="h-4 w-4" />
          <span>+ Học viên mới</span>
        </button>
      </div>
    </div>

    <!-- Filters: 2 Clean Rows (Matching SenyApp) -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs space-y-3">
      <!-- Row 1: Tên học viên, Điện thoại, Tất cả trung tâm, Tất cả trạng thái, Tất cả khóa học, Tất cả số buổi khoá học -->
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <!-- Tên học viên -->
        <div class="relative">
          <input
            type="text"
            v-model="search"
            @keyup.enter="applyFilters"
            @change="applyFilters"
            placeholder="Tên học viên"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
          />
        </div>

        <!-- Điện thoại -->
        <div class="relative">
          <input
            type="text"
            v-model="phoneSearch"
            @keyup.enter="applyFilters"
            @change="applyFilters"
            placeholder="Điện thoại"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
          />
        </div>

        <!-- Tất cả trung tâm (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="centerFilter"
            :options="filterCenterOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả trung tâm"
            @change="applyFilters"
          />
        </div>

        <!-- Tất cả trạng thái -->
        <div>
          <select
            v-model="status"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả trạng thái</option>
            <option value="active">Đang học</option>
            <option value="paused">Tạm dừng</option>
            <option value="dropped">Đã nghỉ</option>
          </select>
        </div>

        <!-- Tất cả khóa học (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="courseId"
            :options="filterCourseOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả khóa học"
            @change="applyFilters"
          />
        </div>

        <!-- Tất cả số buổi khoá học -->
        <div>
          <select
            v-model="sessionsFilter"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả số buổi khoá học</option>
            <option value="expiring">🔥 Sắp hết (&le; 5 buổi)</option>
            <option value="plenty">Còn nhiều (&gt; 5 buổi)</option>
          </select>
        </div>
      </div>

      <!-- Row 2: Tất cả thanh toán học cụ, Tất cả chuyên cần, Tất cả người phụ trách, Tất cả lớp học, Tất cả giáo viên, Sắp xếp theo tên -->
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 pt-1">
        <!-- Tất cả thanh toán học cụ -->
        <div>
          <select
            v-model="tuitionFilter"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả thanh toán học cụ</option>
            <option value="paid">Đã thanh toán đủ</option>
            <option value="unpaid">Chưa thanh toán</option>
          </select>
        </div>

        <!-- Tất cả chuyên cần -->
        <div>
          <select
            v-model="attendanceStatusFilter"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="all">Tất cả chuyên cần</option>
            <option value="excellent">🔥 Chuyên cần xuất sắc</option>
            <option value="good">😊 Chuyên cần tốt</option>
            <option value="attention">⚠️ Cần quan tâm</option>
            <option value="at_risk">🚨 Nguy cơ nghỉ học</option>
            <option value="returned">💪 Đã quay lại học đều</option>
          </select>
        </div>

        <!-- Tất cả người phụ trách (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="staffFilter"
            :options="filterStaffOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả người phụ trách"
            @change="applyFilters"
          />
        </div>

        <!-- Tất cả lớp học (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="assignedClassFilter"
            :options="filterClassOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả lớp học"
            @change="applyFilters"
          />
        </div>

        <!-- Tất cả giáo viên (Tìm kiếm & Chọn từ dropdown) -->
        <div>
          <SearchableSelect
            v-model="teacherFilter"
            :options="filterTeacherOptions"
            label-key="name"
            value-key="id"
            placeholder="Tất cả giáo viên"
            @change="applyFilters"
          />
        </div>

        <!-- Sắp xếp theo tên -->
        <div>
          <select
            v-model="sortFilter"
            @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all cursor-pointer"
          >
            <option value="newest">Mới thêm gần nhất</option>
            <option value="name_asc">Sắp xếp theo tên (A-Z)</option>
            <option value="name_desc">Sắp xếp theo tên (Z-A)</option>
            <option value="sessions_asc">Buổi học còn ít nhất</option>
            <option value="sessions_desc">Buổi học còn nhiều nhất</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Student Table Card -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-2xs overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm min-w-[950px]">
          <thead class="bg-slate-50/90 text-slate-500 border-b border-slate-200/80 font-bold uppercase tracking-wider text-xs">
            <tr>
              <th class="py-4 px-4">Tên</th>
              <th class="py-4 px-4">Phụ huynh</th>
              <th class="py-4 px-4">Điện thoại</th>
              <th class="py-4 px-4 text-center">Số buổi</th>
              <th class="py-4 px-4 text-center">Vắng</th>
              <th class="py-4 px-4 text-center">Trạng thái</th>
              <th class="py-4 px-4 text-center">Điểm danh</th>
              <th class="py-4 px-4 text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <tr
              v-for="student in students"
              :key="student.id"
              class="hover:bg-slate-50/80 transition-colors"
            >
              <!-- Name, Course & Attendance Dots (SenyApp style) -->
              <td class="py-4 px-4">
                <div class="flex items-center gap-3">
                  <div class="min-w-0">
                    <router-link
                      :to="`/students/${student.id}`"
                      class="font-black text-slate-900 hover:text-indigo-600 block text-sm sm:text-base tracking-tight truncate"
                    >
                      {{ student.name }}
                    </router-link>
                    <span class="text-xs text-slate-400 block font-medium truncate mb-1">
                      {{ student.course?.name }}
                    </span>

                    <!-- Dãy 5 chấm điểm danh gần nhất & Biểu tượng Chuyên cần (Kích thước lớn rõ ràng theo chuẩn) -->
                    <div class="flex items-center gap-2 mt-1" :title="getAttendanceSummaryTooltip(student)">
                      <!-- Dãy 5 chấm -->
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
                          :title="getDotTooltip(dot, idx)"
                        ></span>
                      </div>

                      <!-- Biểu tượng ngọn lửa / cảm xúc theo mức độ chuyên cần -->
                      <span
                        v-if="student.attendance_status === 'excellent'"
                        class="text-lg select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Chuyên cần xuất sắc"
                      >
                        🔥
                      </span>
                      <span
                        v-else-if="student.attendance_status === 'good'"
                        class="text-base select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Chuyên cần tốt"
                      >
                        😊
                      </span>
                      <span
                        v-else-if="student.attendance_status === 'attention'"
                        class="text-base select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Cần quan tâm (Vắng 1 buổi gần đây)"
                      >
                        ⚠️
                      </span>
                      <span
                        v-else-if="student.attendance_status === 'at_risk'"
                        class="text-base select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Nguy cơ nghỉ học (Vắng 2 buổi liên tiếp trở lên)"
                      >
                        🚨
                      </span>
                      <span
                        v-else-if="student.attendance_status === 'returned'"
                        class="text-base select-none cursor-help transition-transform hover:scale-125 inline-block leading-none"
                        title="Đã quay lại học đều"
                      >
                        💪
                      </span>
                    </div>
                  </div>
                </div>
              </td>

              <!-- Parent -->
              <td class="py-3.5 px-4 font-semibold text-slate-800">
                {{ student.parent_name }}
              </td>

              <!-- Số điện thoại phụ huynh (bấm để gọi) -->
              <td class="py-3.5 px-4 font-mono font-medium">
                <a
                  :href="`tel:${student.parent_phone}`"
                  class="text-blue-600 font-bold hover:underline"
                >
                  {{ student.parent_phone }}
                </a>
              </td>

              <!-- Sessions Progress Pill -->
              <td class="py-3.5 px-4 text-center whitespace-nowrap">
                <span
                  class="inline-block rounded-lg px-2.5 py-1 font-bold text-xs shadow-2xs"
                  :class="[
                    student.remaining_sessions <= 2
                      ? 'bg-red-100 text-red-700'
                      : student.remaining_sessions <= 5
                      ? 'bg-amber-100 text-amber-800'
                      : 'bg-emerald-100 text-emerald-800'
                  ]"
                >
                  {{ student.total_sessions - student.remaining_sessions }}/{{ student.total_sessions }} ({{ student.remaining_sessions }} còn lại)
                </span>
              </td>

              <!-- Absences -->
              <td class="py-3.5 px-4 text-center">
                <span v-if="student.consecutive_absences > 0" class="font-bold text-red-600">
                  {{ student.consecutive_absences }} (Bù {{ student.consecutive_absences }})
                </span>
                <span v-else class="text-slate-400 font-mono">0</span>
              </td>

              <!-- Status -->
              <td class="py-3.5 px-4 text-center">
                <Badge :variant="student.status === 'active' ? 'green' : student.status === 'paused' ? 'amber' : 'red'">
                  {{ student.status === 'active' ? 'Đang học' : student.status === 'paused' ? 'Tạm dừng' : 'Đã nghỉ' }}
                </Badge>
              </td>

              <!-- Quick Attendance Button -->
              <td class="py-3.5 px-4 text-center">
                <AttendanceButton :student="student" :compact="true" @attended="fetchData" @undone="fetchData" />
              </td>

              <!-- Actions matching Screenshot 1 exactly -->
              <td class="py-3.5 px-4 text-right">
                <div class="flex items-center justify-end gap-1">
                  <!-- Eye: Xem nhanh -->
                  <router-link
                    :to="`/students/${student.id}`"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-800 transition-colors"
                    title="Xem hồ sơ học viên"
                  >
                    <Eye class="h-4 w-4" />
                  </router-link>

                  <!-- ExternalLink: Mở trang riêng chi tiết thông tin học viên -->
                  <router-link
                    :to="`/students/${student.id}`"
                    target="_blank"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-blue-600 transition-colors"
                    title="Mở trang chi tiết học viên ở tab mới"
                  >
                    <ExternalLink class="h-4 w-4" />
                  </router-link>

                  <!-- Edit3: Sửa thông tin -->
                  <button
                    @click="openEditStudent(student)"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600 transition-colors"
                    title="Chỉnh sửa thông tin"
                  >
                    <Edit3 class="h-4 w-4" />
                  </button>

                  <!-- Package / Box: Thêm học cụ (Red box in Screenshot 1) -->
                  <button
                    @click="openSupplyModal(student)"
                    class="rounded-lg p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600 transition-colors"
                    title="Thêm học cụ cho học viên"
                  >
                    <Package class="h-4 w-4 text-slate-600 hover:text-red-600" />
                  </button>

                  <!-- Trash2: Xóa -->
                  <button
                    @click="deleteStudent(student)"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600 transition-colors"
                    title="Xóa học viên"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="students.length === 0">
              <td colspan="8" class="py-12 text-center text-xs text-slate-400">
                Không tìm thấy học viên nào phù hợp.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :meta="pagination" @change-page="changePage" @change-per-page="changePerPage" />
    </div>

    <!-- Create Student Modal -->
    <Modal :show="showCreateModal" max-width="2xl" @close="showCreateModal = false">
      <div class="p-6 sm:p-8">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-red-50 text-red-600">
              <UserPlus class="h-5 w-5" />
            </div>
            <div>
              <h3 class="text-base sm:text-lg font-black text-slate-900">Thêm Học Viên Mới</h3>
              <p class="text-xs text-slate-500 mt-0.5">Điền thông tin học viên để bắt đầu quản lý lộ trình học & nhắc phí</p>
            </div>
          </div>
          <button @click="showCreateModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitCreateStudent" class="mt-6 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
            <div class="sm:col-span-2">
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Tên học viên <span class="text-red-600">*</span>
              </label>
              <input
                type="text"
                v-model="newStudentForm.name"
                required
                placeholder="Ví dụ: Nguyễn Minh Khang"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Giới tính</label>
              <select
                v-model="newStudentForm.gender"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              >
                <option value="Nam">Nam</option>
                <option value="Nữ">Nữ</option>
                <option value="Khác">Khác</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Tên phụ huynh <span class="text-red-600">*</span>
              </label>
              <input
                type="text"
                v-model="newStudentForm.parent_name"
                required
                placeholder="Ví dụ: Chị Phương Thảo"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Số điện thoại phụ huynh <span class="text-red-600">*</span>
              </label>
              <input
                type="text"
                v-model="newStudentForm.parent_phone"
                required
                placeholder="Ví dụ: 0912345678"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
            <div class="sm:col-span-2">
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Khóa học đăng ký <span class="text-red-600">*</span>
              </label>
              <SearchableSelect
                v-model="newStudentForm.course_id"
                :options="courseOptions"
                placeholder="Chọn khóa học..."
                @change="onCourseChange"
                required
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">
                Số buổi học <span class="text-red-600">*</span>
              </label>
              <input
                type="number"
                min="1"
                v-model.number="newStudentForm.total_sessions"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-red-600 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Ngày bắt đầu học</label>
              <DatePickerVN
                v-model="newStudentForm.join_date"
                :default-today="true"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Học phí thu (VNĐ)</label>
              <MoneyInput
                v-model="newStudentForm.invoice_amount" placeholder="3.600.000"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-slate-900 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Trung tâm</label>
            <select
              v-model="newStudentForm.center"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            >
              <option v-for="c in centerNames" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>

          <ScheduleFields v-model="newStudentForm" :center="newStudentForm.center" />

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Ghi chú ban đầu (nếu có)</label>
            <textarea
              v-model="newStudentForm.notes"
              rows="2"
              placeholder="Ví dụ: Bé học kèm 1-1 tối thứ 3 và thứ 5..."
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            ></textarea>
          </div>

          <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
            <button
              type="button"
              @click="showCreateModal = false"
              class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-xs sm:text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"
            >
              Hủy bỏ
            </button>
            <button
              type="submit"
              :disabled="isSubmitting"
              class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 shadow-xs shadow-indigo-600/20 transition-colors disabled:opacity-50 flex items-center gap-2"
            >
              <CheckCircle2 class="h-4 w-4" />
              <span>{{ isSubmitting ? 'Đang lưu...' : 'Lưu Học Viên' }}</span>
            </button>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Edit Student Modal -->
    <Modal :show="showEditModal" max-width="2xl" @close="showEditModal = false">
      <div v-if="editingStudent" class="p-6 sm:p-8">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-red-50 text-red-600">
              <Edit3 class="h-5 w-5" />
            </div>
            <div>
              <h3 class="text-base sm:text-lg font-black text-slate-900">Sửa Thông Tin Học Viên</h3>
              <p class="text-xs text-slate-500 mt-0.5">{{ editingStudent.name }} &bull; {{ editingStudent.student_code }}</p>
            </div>
          </div>
          <button @click="showEditModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitEditStudent" class="mt-6 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Tên học viên</label>
              <input
                type="text"
                v-model="editingStudent.name"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Phụ huynh</label>
              <input
                type="text"
                v-model="editingStudent.parent_name"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Số điện thoại</label>
              <input
                type="text"
                v-model="editingStudent.parent_phone"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Khóa học</label>
              <SearchableSelect
                v-model="editingStudent.course_id"
                :options="courseOptions"
                placeholder="Chọn khóa học..."
              />
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3.5">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Tổng số buổi</label>
              <input
                type="number"
                min="1"
                v-model.number="editingStudent.total_sessions"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-slate-800"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Số buổi còn lại</label>
              <input
                type="number"
                min="0"
                v-model.number="editingStudent.remaining_sessions"
                required
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-red-600"
              />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1">Trạng thái</label>
              <select
                v-model="editingStudent.status"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium"
              >
                <option value="active">Đang học</option>
                <option value="paused">Tạm dừng</option>
                <option value="dropped">Đã nghỉ</option>
                <option value="inactive">Đã nghỉ (kết thúc khóa học)</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Trung tâm</label>
            <select
              v-model="editingStudent.center"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-medium focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
            >
              <option v-for="c in centerNames" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>

          <ScheduleFields v-model="editingStudent" :center="editingStudent.center" />

          <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
            <button
              type="button"
              @click="showEditModal = false"
              class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-xs sm:text-sm font-bold text-slate-700 hover:bg-slate-50"
            >
              Hủy
            </button>
            <button
              type="submit"
              :disabled="isSubmitting"
              class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 shadow-xs shadow-indigo-600/20 disabled:opacity-50"
            >
              Cập Nhật
            </button>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Add Supply Modal -->
    <AddSupplyModal
      :show="showSupplyModal"
      :student="selectedSupplyStudent"
      @close="showSupplyModal = false"
      @added="fetchData"
    />

    <!-- Import Students Excel/CSV Modal -->
    <ImportStudentsModal
      :show="showImportModal"
      @close="showImportModal = false"
      @imported="fetchData"
    />

    <!-- Info / Guide Modal -->
    <Modal :show="showInfoModal" max-width="lg" @close="showInfoModal = false">
      <div class="p-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
          <div class="flex items-center gap-2.5">
            <span class="text-xl">ℹ️</span>
            <h3 class="text-base font-bold text-slate-900">Hướng dẫn & Tiêu chuẩn Chuyên cần</h3>
          </div>
          <button
            @click="showInfoModal = false"
            class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100"
          >
            <X class="h-4 w-4" />
          </button>
        </div>

        <div class="mt-4 space-y-3.5 text-xs text-slate-600 leading-relaxed">
          <div class="p-3 bg-slate-50 rounded-xl space-y-2">
            <h4 class="font-bold text-slate-800 text-sm">Ý nghĩa 5 chấm điểm danh:</h4>
            <div class="grid grid-cols-2 gap-2 text-xs">
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-emerald-500"></span>
                <span>Đi học đầy đủ</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-rose-500"></span>
                <span>Vắng không phép</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-amber-400"></span>
                <span>Nghỉ có phép</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-white border border-slate-300"></span>
                <span>Chưa diễn ra</span>
              </div>
            </div>
          </div>

          <div class="p-3 bg-indigo-50/50 rounded-xl space-y-2">
            <h4 class="font-bold text-slate-800 text-sm">Huy hiệu Chuyên cần:</h4>
            <ul class="space-y-1.5 text-xs">
              <li>🔥 <strong class="text-slate-800">Chuyên cần xuất sắc:</strong> Không vắng buổi nào trong 5 buổi gần nhất.</li>
              <li>😊 <strong class="text-slate-800">Chuyên cần tốt:</strong> Không có buổi vắng liên tiếp.</li>
              <li>⚠️ <strong class="text-slate-800">Cần quan tâm:</strong> Đang vắng 1 buổi gần đây.</li>
              <li>🚨 <strong class="text-slate-800">Nguy cơ nghỉ học:</strong> Vắng liên tiếp từ 2 buổi trở lên.</li>
              <li>💪 <strong class="text-slate-800">Đã quay lại học đều:</strong> Đã đi học lại sau chuỗi vắng.</li>
            </ul>
          </div>
        </div>

        <div class="mt-5 pt-3 border-t border-slate-100 flex justify-end">
          <button
            type="button"
            @click="showInfoModal = false"
            class="px-4 py-2 text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl"
          >
            Đã hiểu
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
