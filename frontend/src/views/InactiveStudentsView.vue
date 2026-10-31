<script setup>
import { notify } from '../services/notify';
import { confirmDialog } from '../services/useConfirm';
import { ref, computed, onMounted } from 'vue';
import { inactiveStudentApi, studentApi } from '../services/api';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import Pagination from '../components/Pagination.vue';
import {
  PauseCircle,
  UserX,
  Play,
  MessageSquare,
  PhoneCall,
  Calendar,
  Clock,
  Edit2,
  Trash2,
  Sparkles,
  X,
} from 'lucide-vue-next';

const loading = ref(true);
const students = ref([]);
const upcomingReturnStudents = ref([]);
const centers = ref([]);

// Filters
const selectedCenter = ref('all');
const selectedStatus = ref('all'); // all, paused, inactive
const upcomingDays = ref(7); // 7, 14, 30

// Phân trang danh sách chính
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);

// Modals
const showTemplateModal = ref(false);
const selectedStudent = ref(null);
const showEditModal = ref(false);
const editForm = ref({
  id: null,
  status: 'paused',
  end_date: '',
  notes: '',
});
const savingEdit = ref(false);


const showToast = (msg, type = 'success') => {
  if (type === 'success') {
    notify.success(msg);
  } else if (msg.startsWith('Vui lòng')) {
    notify.warning(msg);
  } else {
    notify.error(msg);
  }
};

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await inactiveStudentApi.getAll({
      center: selectedCenter.value,
      status: selectedStatus.value,
      upcoming_days: upcomingDays.value,
      page: page.value,
      per_page: perPage.value,
    });
    pagination.value = res.data.pagination || null;
    // Trang hiện tại không còn dữ liệu (ví dụ vừa kích hoạt/xoá dòng cuối) thì lùi về trang cuối
    if (pagination.value && page.value > pagination.value.last_page && pagination.value.last_page >= 1) {
      page.value = pagination.value.last_page;
      return fetchData();
    }
    students.value = res.data.students || [];
    upcomingReturnStudents.value = res.data.upcoming_return_students || [];
    centers.value = res.data.centers || [];
  } catch (err) {
    console.error('Error fetching inactive students:', err);
  } finally {
    loading.value = false;
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

onMounted(fetchData);

// 1. Kích hoạt học lại
const handleReactivate = async (student) => {
  try {
    await inactiveStudentApi.reactivate(student.id);
    showToast(`Đã kích hoạt học viên "${student.name}" quay trở lại học thành công!`);
    fetchData();
  } catch (err) {
    showToast('Có lỗi xảy ra khi kích hoạt học viên', 'error');
  }
};

// 2. Mở modal nhắn tin
const openTemplateModal = (student) => {
  selectedStudent.value = student;
  showTemplateModal.value = true;
};

// 3. Mở modal sửa ngày hẹn / lý do
const openEditModal = (student) => {
  editForm.value = {
    id: student.id,
    name: student.name,
    status: student.status,
    end_date: student.paused_until || '',
    notes: student.pause_note || '',
  };
  showEditModal.value = true;
};

const handleSaveEdit = async () => {
  try {
    savingEdit.value = true;
    if (editForm.value.status === 'paused') {
      await inactiveStudentApi.pause(editForm.value.id, {
        end_date: editForm.value.end_date,
        notes: editForm.value.notes,
      });
    } else {
      await inactiveStudentApi.drop(editForm.value.id, {
        notes: editForm.value.notes,
      });
    }
    showToast('Cập nhật thông tin học viên thành công!');
    showEditModal.value = false;
    fetchData();
  } catch (err) {
    showToast('Có lỗi xảy ra', 'error');
  } finally {
    savingEdit.value = false;
  }
};

// 4. Xóa học viên
const handleDeleteStudent = async (student) => {
  if (!(await confirmDialog({
    title: 'Xoá vĩnh viễn hồ sơ?',
    message: `Hồ sơ học viên "${student.name}" sẽ bị xoá vĩnh viễn và không thể khôi phục.`,
    confirmText: 'Xoá vĩnh viễn',
  }))) return;
  try {
    await studentApi.delete(student.id);
    showToast(`Đã xóa học viên "${student.name}" thành công!`);
    fetchData();
  } catch (err) {
    showToast('Có lỗi xảy ra khi xóa học viên', 'error');
  }
};

const formatDate = (val) => {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
};

</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">HV Tạm dừng - Nghỉ</h1>
    </div>


    <!-- Card: BỘ LỌC & DANH SÁCH HỌC VIÊN -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-5">
      <!-- Title & Note -->
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Học viên đã nghỉ / tạm dừng</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Liên hệ phụ huynh để mời học viên quay lại.
        </p>
        <p class="text-[11px] sm:text-xs text-slate-400 mt-1">
          <strong class="text-slate-600">Lưu ý:</strong> Tạm dừng = học viên còn buổi học trong khoá; Đã nghỉ = học viên
          đã hoàn thành các khoá học.
        </p>
      </div>

      <!-- Filters: Trung tâm & Trạng thái -->
      <div class="flex flex-wrap items-center gap-4">
        <!-- Trung tâm -->
        <div class="flex items-center gap-2">
          <label class="text-xs font-semibold text-slate-700 whitespace-nowrap">Trung tâm</label>
          <select v-model="selectedCenter" @change="applyFilters"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <!-- Trạng thái -->
        <div class="flex items-center gap-2">
          <label class="text-xs font-semibold text-slate-700 whitespace-nowrap">Trạng thái</label>
          <select v-model="selectedStatus" @change="applyFilters"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả</option>
            <option value="paused">Tạm dừng</option>
            <option value="inactive">Đã nghỉ</option>
          </select>
        </div>
      </div>

      <!-- SUB-BOX: SẮP ĐI HỌC LẠI -->
      <div class="rounded-xl border border-slate-200/80 bg-slate-50/50 p-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2.5">
          <span class="text-xs sm:text-sm font-bold text-slate-800">
            Sắp đi học lại ({{ upcomingReturnStudents.length }})
          </span>

          <select v-model="upcomingDays" @change="fetchData"
            class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-800 focus:border-rose-500 focus:outline-none">
            <option :value="7">Sắp học lại 7 ngày</option>
            <option :value="14">Sắp học lại 14 ngày</option>
            <option :value="30">Sắp học lại 30 ngày</option>
          </select>

          <span class="text-[11px] sm:text-xs text-slate-400">
            Dựa trên cột "Đến ngày" do admin điền — chỉ áp dụng cho học viên Tạm dừng.
          </span>
        </div>

        <!-- Upcoming items list or Empty notice -->
        <div v-if="upcomingReturnStudents.length === 0" class="text-xs text-slate-500 pt-1">
          Không có học viên nào sắp học lại trong {{ upcomingDays }} ngày tới.
        </div>

        <div v-else class="divide-y divide-slate-200/60 pt-1">
          <div v-for="st in upcomingReturnStudents" :key="st.id"
            class="py-2.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 text-xs">
            <div>
              <span class="font-bold text-slate-900">{{ st.name }}</span>
              <span class="ml-2 text-slate-500">({{ st.course?.name }})</span>
              <span class="ml-2 text-amber-700 font-medium">Hạn bảo lưu đến: {{ formatDate(st.paused_until) }}</span>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" @click="handleReactivate(st)"
                class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 px-2.5 py-1 text-xs font-semibold text-white shadow-2xs">
                <Play class="h-3 w-3" />
                <span>Kích hoạt học lại</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- MAIN TABLE: DANH SÁCH HỌC VIÊN TẠM DỪNG / ĐÃ NGHỈ -->
      <div v-if="students.length === 0"
        class="py-12 text-center text-xs sm:text-sm text-slate-500 flex items-center justify-center gap-2">
        <span>Không có học viên đã nghỉ</span>
        <span>🎉</span>
      </div>

      <div v-else class="overflow-x-auto pt-2">
        <table class="w-full text-left border-collapse text-xs sm:text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-[11px] sm:text-xs font-semibold text-slate-500 pb-2">
              <th class="py-2.5 px-3">Mã HV</th>
              <th class="py-2.5 px-3">Học viên</th>
              <th class="py-2.5 px-3">Phụ huynh & SĐT</th>
              <th class="py-2.5 px-3">Khóa học</th>
              <th class="py-2.5 px-3 text-center">Còn lại</th>
              <th class="py-2.5 px-3 text-center">Trạng thái</th>
              <th class="py-2.5 px-3">Hạn bảo lưu</th>
              <th class="py-2.5 px-3">Ghi chú</th>
              <th class="py-2.5 px-3 text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="s in students" :key="s.id" class="hover:bg-slate-50/60 transition-colors">
              <!-- Mã HV -->
              <td class="py-3 px-3 font-mono text-slate-500 text-xs">
                {{ s.code }}
              </td>

              <!-- Học viên -->
              <td class="py-3 px-3 font-bold text-slate-900">
                <router-link :to="`/students/${s.id}`" class="hover:text-blue-600">
                  {{ s.name }}
                </router-link>
              </td>

              <!-- Phụ huynh & SĐT -->
              <td class="py-3 px-3 text-slate-600">
                <div>{{ s.parent_name }}</div>
                <div class="text-[11px] text-slate-400 font-mono">{{ s.parent_phone }}</div>
              </td>

              <!-- Khóa học -->
              <td class="py-3 px-3 text-slate-700">
                {{ s.course?.name || '—' }}
              </td>

              <!-- Còn lại -->
              <td class="py-3 px-3 text-center font-bold text-slate-800">
                {{ s.remaining_sessions }} buổi
              </td>

              <!-- Trạng thái -->
              <td class="py-3 px-3 text-center">
                <span :class="[
                  s.status === 'paused' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800',
                  'inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold'
                ]">
                  {{ s.status === 'paused' ? 'Tạm dừng' : 'Đã nghỉ' }}
                </span>
              </td>

              <!-- Hạn bảo lưu -->
              <td class="py-3 px-3 text-slate-600 text-xs">
                {{ s.status === 'paused' && s.paused_until ? formatDate(s.paused_until) : '—' }}
              </td>

              <!-- Ghi chú -->
              <td class="py-3 px-3 text-slate-500 text-xs max-w-xs truncate" :title="s.pause_note || s.notes">
                {{ s.pause_note || s.notes || '—' }}
              </td>

              <!-- Thao tác -->
              <td class="py-3 px-3 text-right">
                <div class="inline-flex items-center gap-1.5 justify-end">
                  <!-- Mẫu tin nhắn -->
                  <button type="button" @click="openTemplateModal(s)"
                    class="p-1.5 rounded-lg text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                    title="Mẫu tin nhắn">
                    <MessageSquare class="h-4 w-4" />
                  </button>

                  <!-- Kích hoạt học lại -->
                  <button type="button" @click="handleReactivate(s)"
                    class="p-1.5 rounded-lg text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors"
                    title="Kích hoạt học lại">
                    <Play class="h-4 w-4" />
                  </button>

                  <!-- Sửa -->
                  <button type="button" @click="openEditModal(s)"
                    class="p-1.5 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors"
                    title="Sửa ngày hẹn & lý do">
                    <Edit2 class="h-4 w-4" />
                  </button>

                  <!-- Xóa -->
                  <button type="button" @click="handleDeleteStudent(s)"
                    class="p-1.5 rounded-lg text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition-colors"
                    title="Xóa hồ sơ học viên">
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :meta="pagination" @change-page="changePage" @change-per-page="changePerPage" />
    </div>

    <!-- MODAL SỬA THỜI HẠN / GHI CHÚ -->
    <div v-if="showEditModal"
      class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
          <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
            <PauseCircle class="h-4.5 w-4.5 text-amber-500" />
            <span>Cập Nhật Học Viên Tạm Dừng / Nghỉ</span>
          </div>
          <button type="button" @click="showEditModal = false"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="p-6 space-y-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Trạng thái</label>
            <select v-model="editForm.status"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
              <option value="paused">Tạm dừng (Còn bảo lưu số buổi)</option>
              <option value="inactive">Đã nghỉ (Kết thúc khóa học)</option>
            </select>
          </div>

          <div v-if="editForm.status === 'paused'">
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Hạn bảo lưu đến ngày (Dự kiến học
              lại)</label>
            <input v-model="editForm.end_date" type="date"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Lý do / Ghi chú</label>
            <textarea v-model="editForm.notes" rows="3" placeholder="Nhập lý do học viên tạm dừng hoặc nghỉ học..."
              class="w-full rounded-xl border border-slate-200 bg-white p-3 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"></textarea>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex items-center justify-end gap-2">
          <button type="button" @click="showEditModal = false"
            class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700">
            Hủy
          </button>
          <button type="button" :disabled="savingEdit" @click="handleSaveEdit"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-60 px-4 py-2 text-xs font-bold text-white shadow-xs">
            {{ savingEdit ? 'Đang lưu...' : 'Lưu cập nhật' }}
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL MẪU TIN NHẮN -->
    <MessageTemplateModal group="inactive" :show="showTemplateModal" :student="selectedStudent"
      @close="showTemplateModal = false" />
  </div>
</template>
