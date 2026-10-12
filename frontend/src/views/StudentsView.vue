<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { studentApi, courseApi } from '../services/api';
import Badge from '../components/Badge.vue';
import Modal from '../components/Modal.vue';
import AddSupplyModal from '../components/AddSupplyModal.vue';
import AttendanceButton from '../components/AttendanceButton.vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import DatePickerVN from '../components/DatePickerVN.vue';
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
} from 'lucide-vue-next';

const route = useRoute();

const students = ref([]);
const courses = ref([]);
const search = ref('');
const phoneSearch = ref('');
const courseId = ref('all');
const status = ref('all');
const alertFilter = ref('');

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showSupplyModal = ref(false);
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
  join_date: new Date().toISOString().split('T')[0],
  notes: '',
  create_invoice: true,
  invoice_amount: 3600000,
});

const fetchData = async () => {
  try {
    const res = await studentApi.getAll({
      search: search.value || phoneSearch.value,
      course_id: courseId.value,
      status: status.value,
      alert: alertFilter.value,
    });
    students.value = res.data.students || [];
    courses.value = res.data.courses || [];
    if (courses.value.length > 0 && !newStudentForm.value.course_id) {
      newStudentForm.value.course_id = courses.value[0].id;
    }
  } catch (err) {
    console.error('Error fetching students: ', err);
  }
};

onMounted(() => {
  fetchData();
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

const filterCourseOptions = computed(() => {
  return [
    { id: 'all', name: 'Tất cả khóa học' },
    ...courses.value.map((c) => ({
      id: c.id,
      name: c.name,
    })),
  ];
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
    alert('Vui lòng điền đầy đủ Tên học viên, Tên phụ huynh và Số điện thoại');
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
      join_date: new Date().toISOString().split('T')[0],
      notes: '',
      create_invoice: true,
      invoice_amount: 3600000,
    };
    fetchData();
  } catch (err) {
    alert(err.response?.data?.message || 'Có lỗi xảy ra khi tạo học viên');
  } finally {
    isSubmitting.value = false;
  }
};

const openEditStudent = (student) => {
  editingStudent.value = { ...student };
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
    alert(err.response?.data?.message || 'Có lỗi xảy ra khi cập nhật');
  } finally {
    isSubmitting.value = false;
  }
};

const deleteStudent = async (student) => {
  if (confirm(`Bạn có chắc chắn muốn xóa học viên ${student.name}?`)) {
    try {
      await studentApi.delete(student.id);
      fetchData();
    } catch (err) {
      alert(err.response?.data?.message || 'Không thể xóa');
    }
  }
};

const getZaloLink = (phone) => {
  if (!phone) return '#';
  return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};

const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};
</script>

<template>
  <div class="space-y-5 w-full">
    <!-- Header matching Senyapp style -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-2xs">
      <div>
        <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900">
          Học viên <span class="text-sm font-semibold text-slate-400">({{ students.length }})</span>
        </h1>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <button
          @click="showCreateModal = true"
          class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs shadow-indigo-600/20 hover:bg-indigo-700 transition-all active:scale-[0.98]"
        >
          <UserPlus class="h-4 w-4" />
          <span>+ Học viên mới</span>
        </button>
      </div>
    </div>

    <!-- Filters Row: Modern Indigo Style -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
      <!-- Tên học viên -->
      <div>
        <input
          type="text"
          v-model="search"
          @keyup.enter="fetchData"
          placeholder="Tên học viên..."
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
        />
      </div>

      <!-- Điện thoại -->
      <div>
        <input
          type="text"
          v-model="phoneSearch"
          @keyup.enter="fetchData"
          placeholder="Điện thoại..."
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
        />
      </div>

      <!-- Khóa học -->
      <div>
        <SearchableSelect
          v-model="courseId"
          :options="filterCourseOptions"
          @change="fetchData"
          placeholder="Tất cả khóa học"
        />
      </div>

      <!-- Trạng thái -->
      <div>
        <select
          v-model="status"
          @change="fetchData"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-normal focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
        >
          <option value="all">Tất cả trạng thái</option>
          <option value="active">Đang học</option>
          <option value="paused">Tạm dừng</option>
          <option value="dropped">Đã nghỉ</option>
        </select>
      </div>

      <!-- Cảnh báo sắp hết -->
      <div>
        <button
          @click="alertFilter = alertFilter === 'expiring' ? '' : 'expiring'; fetchData()"
          class="w-full rounded-xl px-3.5 py-2.5 text-sm font-medium border transition-all flex items-center justify-center gap-1.5"
          :class="alertFilter === 'expiring' ? 'bg-amber-50 border-amber-300 text-amber-800 ring-2 ring-amber-400/20' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100'"
        >
          <Flame class="h-4 w-4 text-amber-500" />
          <span>Sắp hết (&le; 5)</span>
        </button>
      </div>

      <!-- Vắng liên tiếp -->
      <div>
        <button
          @click="alertFilter = alertFilter === 'absence' ? '' : 'absence'; fetchData()"
          class="w-full rounded-xl px-3.5 py-2.5 text-sm font-medium border transition-all flex items-center justify-center gap-1.5"
          :class="alertFilter === 'absence' ? 'bg-rose-50 border-rose-300 text-rose-800 ring-2 ring-rose-400/20' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100'"
        >
          <AlertTriangle class="h-4 w-4 text-rose-500" />
          <span>Vắng &ge; 2</span>
        </button>
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
              <!-- Name & Course -->
              <td class="py-4 px-4">
                <div class="flex items-center gap-3">
                  <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50 font-black text-indigo-700 text-sm shrink-0">
                    {{ student.name.charAt(0) }}
                  </div>
                  <div class="min-w-0">
                    <router-link
                      :to="`/students/${student.id}`"
                      class="font-black text-slate-900 hover:text-indigo-600 block text-sm sm:text-base tracking-tight truncate"
                    >
                      {{ student.name }}
                    </router-link>
                    <span class="text-xs text-slate-400 block font-medium truncate">
                      {{ student.course?.name }}
                    </span>
                  </div>
                </div>
              </td>

              <!-- Parent -->
              <td class="py-3.5 px-4 font-semibold text-slate-800">
                {{ student.parent_name }}
              </td>

              <!-- Phone & Zalo Link -->
              <td class="py-3.5 px-4 font-mono font-medium">
                <a
                  :href="getZaloLink(student.parent_phone)"
                  target="_blank"
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
              <input
                type="number"
                v-model.number="newStudentForm.invoice_amount"
                placeholder="3600000"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-slate-900 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

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
              </select>
            </div>
          </div>

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
  </div>
</template>
