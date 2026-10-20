<script setup>
import { useCenters } from '../services/useCenters';
import { confirmDialog } from '../services/useConfirm';
import { notify, errorMessage } from '../services/notify';
import { ref, computed, onMounted, watch } from 'vue';
import { leadApi, courseApi } from '../services/api';
import Modal from '../components/Modal.vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import Pagination from '../components/Pagination.vue';
import {
  UserPlus,
  Users,
  Download,
  Phone,
  FileText,
  CheckCircle2,
  Copy,
  MessageSquare,
  Image,
  Trash2,
  Calendar,
  Kanban,
  List,
  Sparkles,
  X,
  ExternalLink,
  Plus,
  Check,
  Pencil,
} from 'lucide-vue-next';
import MoneyInput from '../components/MoneyInput.vue';

// Dữ liệu chính
const leads = ref([]);
const courses = ref([]);
const stages = ref({});
const centers = ref([]);
const { defaultCenter, loadCenters } = useCenters();
const loading = ref(true);

// Chế độ xem: 'list' (Danh sách giống ảnh tham khảo - Mặc định) hoặc 'kanban'
const viewMode = ref('list');

// Bộ lọc
const filterCenter = ref('all');
const filterYear = ref('all');
const filterMonth = ref('all');
// Năm hiện tại và 4 năm trước
const yearOptions = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i);
const searchQuery = ref('');

// Phân trang (chỉ áp dụng cho chế độ Danh sách, Kanban cần đủ dữ liệu để chia cột)
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);

// Modals
const showCreateModal = ref(false);
const showConvertModal = ref(false);
const showCareModal = ref(false);
const showImageModal = ref(false);
const showMessageModal = ref(false);
const selectedLead = ref(null);
const isSubmitting = ref(false);
const copySuccessId = ref(null);

// Form thêm khách hàng mới
const newLeadForm = ref({
  parent_name: '',
  student_name: '',
  phone: '',
  email: '',
  interested_course_id: '',
  source: 'Facebook',
  status: 'new',
  center: '',
  trial_date: '',
  notes: '',
  image_url: '',
});

// Form chuyển thành học viên
const convertForm = ref({
  course_id: '',
  student_name: '',
  gender: 'Nam',
  dob: '',
  total_sessions: 12,
  price: 3600000,
});

// Form chăm sóc
const careNote = ref('');

// Form hình ảnh
const imageUrlInput = ref('');

// Mẫu tin nhắn chăm sóc khách hàng tiềm năng
const leadTemplates = ref([
  {
    id: 1,
    title: 'Xác nhận lịch hẹn học thử / Test năng khiếu âm nhạc',
    template_content: 'Dạ trung tâm xin chào phụ huynh {ten_hoc_vien} ạ! Trung tâm đã ghi nhận lịch hẹn buổi test năng khiếu & tư vấn lộ trình học cho bé vào ngày {ngay_hoc_thu}. Thầy cô rất mong được đón tiếp gia đình mình tại trung tâm ạ! ❤️',
  },
  {
    id: 2,
    title: 'Gửi bảng học phí & Ưu đãi khai giảng khóa mới',
    template_content: 'Chào anh/chị, em gửi anh/chị thông tin học phí khóa {khoa_hoc} của trung tâm: Khóa 12 buổi (1-kèm-1), học 1-2 buổi/tuần, học phí ưu đãi tặng kèm 1 buổi test và giáo trình. Anh/chị cần em hỗ trợ thêm giờ học nào tiện cho bé không ạ?',
  },
  {
    id: 3,
    title: 'Hỏi thăm sau buổi học thử đầu tiên',
    template_content: 'Dạ em chào anh/chị, hôm nay bé {ten_hoc_vien} học thử buổi đầu tiên về bé có chia sẻ cảm nhận gì với gia đình không ạ? Thầy/cô nhận xét bé có cảm thụ nhịp rất tốt đó ạ!',
  },
]);

const pipelineColumns = [
  { key: 'new', label: '1. Mới tiếp nhận', badgeClass: 'bg-blue-500 text-white' },
  { key: 'called', label: '2. Đã gọi điện', badgeClass: 'bg-amber-500 text-white' },
  { key: 'quote_sent', label: '3. Gửi báo giá', badgeClass: 'bg-purple-500 text-white' },
  { key: 'trial_done', label: '4. Đã học thử', badgeClass: 'bg-indigo-500 text-white' },
  { key: 'registered', label: '5. Đã đăng ký 🎉', badgeClass: 'bg-emerald-500 text-white' },
];

const statusOptions = [
  { value: 'new', label: 'Mới', color: 'bg-blue-600 text-white' },
  { value: 'called', label: 'Đã gọi', color: 'bg-amber-500 text-white' },
  { value: 'quote_sent', label: 'Báo giá', color: 'bg-purple-600 text-white' },
  { value: 'trial_done', label: 'Học thử', color: 'bg-indigo-600 text-white' },
  { value: 'registered', label: 'Đã đăng ký', color: 'bg-emerald-600 text-white' },
  { value: 'lost', label: 'Đã hủy', color: 'bg-slate-500 text-white' },
];

// Bộ lọc đang chọn: dùng cho danh sách và file "Tải khách hàng về"
const filterParams = () => ({
  center: filterCenter.value,
  year: filterYear.value,
  month: filterMonth.value,
  search: searchQuery.value,
});

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await leadApi.getAll({
      ...filterParams(),
      ...(viewMode.value === 'list' ? { page: page.value, per_page: perPage.value } : {}),
    });
    pagination.value = res.data.pagination || null;
    // Trang hiện tại không còn dữ liệu (ví dụ vừa xoá dòng cuối) thì lùi về trang cuối
    if (pagination.value && page.value > pagination.value.last_page && pagination.value.last_page >= 1) {
      page.value = pagination.value.last_page;
      return fetchData();
    }
    leads.value = res.data.leads || [];
    courses.value = res.data.courses || [];
    stages.value = res.data.stages || {};
    if (res.data.centers && res.data.centers.length > 0) {
      centers.value = res.data.centers;
    }
    if (courses.value.length > 0 && !newLeadForm.value.interested_course_id) {
      newLeadForm.value.interested_course_id = courses.value[0].id;
    }
  } catch (err) {
    console.error('Lỗi khi tải leads:', err);
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

watch(viewMode, applyFilters);

onMounted(() => {
  fetchData();
  loadCenters();
});

const courseOptions = computed(() => {
  return courses.value.map((c) => ({
    id: c.id,
    name: c.name,
  }));
});

// Format ngày dd/mm/yyyy
const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  const day = String(d.getDate()).padStart(2, '0');
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const year = d.getFullYear();
  return `${day}/${month}/${year}`;
};

// Đổi trạng thái nhanh
const changeStatus = async (lead, newStatus) => {
  try {
    await leadApi.updateStatus(lead.id, { status: newStatus });
    lead.status = newStatus;
  } catch (err) {
    notify.error('Lỗi cập nhật trạng thái', { description: errorMessage(err, err.message) });
  }
};

// Mở modal chăm sóc
const openCareModal = (lead) => {
  selectedLead.value = lead;
  careNote.value = '';
  showCareModal.value = true;
};

const submitCareNote = async () => {
  if (!careNote.value.trim() || !selectedLead.value) return;
  try {
    await leadApi.updateStatus(selectedLead.value.id, {
      status: selectedLead.value.status,
      notes: careNote.value,
    });
    showCareModal.value = false;
    await fetchData();
  } catch (err) {
    notify.error('Lỗi lưu chăm sóc', { description: errorMessage(err, err.message) });
  }
};

// Mở modal chuyển thành học viên
const openConvertModal = (lead) => {
  selectedLead.value = lead;
  convertForm.value = {
    course_id: lead.interested_course_id || (courses.value[0]?.id || ''),
    student_name: lead.student_name || lead.parent_name,
    gender: 'Nam',
    dob: '',
    total_sessions: 12,
    price: lead.expected_revenue || (lead.course?.price || 3600000),
  };
  showConvertModal.value = true;
};

const submitConvert = async () => {
  if (!selectedLead.value) return;
  try {
    isSubmitting.value = true;
    await leadApi.convertToStudent(selectedLead.value.id, convertForm.value);
    showConvertModal.value = false;
    notify.success(`Đã chuyển đổi thành công học viên ${convertForm.value.student_name}!`);
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi chuyển đổi thành học viên');
  } finally {
    isSubmitting.value = false;
  }
};

// Mở modal hình ảnh
const openImageModal = (lead) => {
  selectedLead.value = lead;
  imageUrlInput.value = lead.image_url || '';
  showImageModal.value = true;
};

const submitImage = async () => {
  if (!selectedLead.value) return;
  try {
    await leadApi.updateStatus(selectedLead.value.id, {
      status: selectedLead.value.status,
      image_url: imageUrlInput.value,
    });
    selectedLead.value.image_url = imageUrlInput.value;
    showImageModal.value = false;
  } catch (err) {
    notify.error('Lỗi lưu hình ảnh', { description: errorMessage(err, err.message) });
  }
};

// Sao chép thông tin lead
const copyLeadInfo = (lead) => {
  const text = `Phụ huynh: ${lead.parent_name}\nBé: ${lead.student_name || 'Chưa có'}\nSĐT: ${lead.phone}\nKhóa học: ${lead.course?.name || 'N/A'}\nGhi chú: ${lead.notes || 'Không có'}`;
  navigator.clipboard.writeText(text);
  copySuccessId.value = lead.id;
  setTimeout(() => {
    copySuccessId.value = null;
  }, 2000);
};

// Mở modal mẫu tin nhắn
const openMessageModal = (lead) => {
  selectedLead.value = {
    name: lead.student_name || lead.parent_name,
    parent_phone: lead.phone,
    course: lead.course || { name: 'Âm nhạc' },
  };
  showMessageModal.value = true;
};

// Xóa lead
const deleteLead = async (lead) => {
  if (!(await confirmDialog({
    title: 'Xoá khách hàng tiềm năng?',
    message: `Khách hàng "${lead.parent_name}" và lịch sử chăm sóc sẽ bị xoá.`,
    confirmText: 'Xoá',
  }))) return;
  try {
    await leadApi.delete(lead.id);
    await fetchData();
  } catch (err) {
    notify.error('Lỗi khi xóa lead', { description: errorMessage(err, err.message) });
  }
};

// Xuất CSV
const downloadCsv = async () => {
  try {
    await leadApi.exportCsv(filterParams());
  } catch (err) {
    notify.apiError(err, 'Không thể xuất file khách hàng');
  }
};

// Mở modal tạo lead mới / sửa lead
const editingLeadId = ref(null);
const openEditModal = (lead) => {
  editingLeadId.value = lead.id;
  newLeadForm.value = {
    parent_name: lead.parent_name || '',
    student_name: lead.student_name || '',
    phone: lead.phone || '',
    email: lead.email || '',
    interested_course_id: lead.interested_course_id || '',
    source: lead.source || 'Facebook',
    status: lead.status || 'new',
    center: lead.center || defaultCenter.value,
    trial_date: lead.trial_date ? String(lead.trial_date).slice(0, 10) : '',
    notes: lead.notes || '',
    image_url: lead.image_url || '',
  };
  showCreateModal.value = true;
};

const openCreateModal = () => {
  editingLeadId.value = null;
  newLeadForm.value = {
    parent_name: '',
    student_name: '',
    phone: '',
    email: '',
    interested_course_id: courses.value[0]?.id || '',
    source: 'Facebook',
    status: 'new',
    center: filterCenter.value !== 'all' ? filterCenter.value : defaultCenter.value,
    trial_date: '',
    notes: '',
    image_url: '',
  };
  showCreateModal.value = true;
};

const submitCreateLead = async () => {
  try {
    isSubmitting.value = true;
    if (editingLeadId.value) {
      await leadApi.update(editingLeadId.value, newLeadForm.value);
      notify.success('Đã cập nhật khách hàng');
    } else {
      await leadApi.create(newLeadForm.value);
    }
    showCreateModal.value = false;
    page.value = 1;
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Lỗi khi tạo lead');
  } finally {
    isSubmitting.value = false;
  }
};
</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- KHỐI CHÍNH: KHÁCH HÀNG TIỀM NĂNG (Khớp 100% hình tham khảo Seny) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <!-- Header hàng trên: Tiêu đề + Phụ đề + Nút Khách hàng mới + Switch xem -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-start gap-3">
          <div class="rounded-xl bg-rose-50 p-2.5 text-rose-600 shrink-0">
            <UserPlus class="h-6 w-6" />
          </div>
          <div>
            <h1 class="text-base sm:text-lg font-bold text-slate-900">Khách hàng tiềm năng</h1>
            <p class="text-xs text-slate-500 mt-0.5">Theo dõi phụ huynh quan tâm nhưng chưa đăng ký học</p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <!-- Toggle chuyển đổi Danh sách / Kanban -->
          <div
            class="inline-flex items-center rounded-xl border border-slate-200 bg-slate-50/70 p-0.5 text-xs font-semibold">
            <button type="button" @click="viewMode = 'list'" :class="[
              'flex items-center gap-1 rounded-lg px-2.5 py-1.5 transition-all cursor-pointer',
              viewMode === 'list' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-900'
            ]">
              <List class="h-3.5 w-3.5" />
              <span>Danh sách</span>
            </button>
            <button type="button" @click="viewMode = 'kanban'" :class="[
              'flex items-center gap-1 rounded-lg px-2.5 py-1.5 transition-all cursor-pointer',
              viewMode === 'kanban' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-900'
            ]">
              <Kanban class="h-3.5 w-3.5" />
              <span>Kanban</span>
            </button>
          </div>

          <!-- Nút đỏ + Khách hàng mới (Khớp chính xác hình tham khảo) -->
          <button type="button" @click="openCreateModal"
            class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-2xs transition-all active:scale-[0.98] cursor-pointer">
            <Plus class="h-4 w-4" />
            <span>Khách hàng mới</span>
          </button>
        </div>
      </div>

      <!-- Filter Bar: Trung tâm | Năm | Tháng | Nút Tải khách hàng về (Khớp chính xác hình tham khảo) -->
      <div class="flex flex-wrap items-center gap-3 pt-1 border-t border-slate-100">
        <!-- Trung tâm -->
        <div class="flex items-center gap-1.5">
          <span class="text-xs text-slate-500 font-medium shrink-0">Trung tâm</span>
          <select v-model="filterCenter" @change="applyFilters"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <!-- Năm -->
        <div class="flex items-center gap-1.5">
          <span class="text-xs text-slate-500 font-medium shrink-0">Năm</span>
          <select v-model="filterYear" @change="applyFilters"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả năm</option>
            <option v-for="y in yearOptions" :key="y" :value="String(y)">{{ y }}</option>
          </select>
        </div>

        <!-- Tháng -->
        <div class="flex items-center gap-1.5">
          <span class="text-xs text-slate-500 font-medium shrink-0">Tháng</span>
          <select v-model="filterMonth" @change="applyFilters"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả tháng</option>
            <option v-for="m in 12" :key="m" :value="String(m)">Tháng {{ m }}</option>
          </select>
        </div>

        <!-- Tìm kiếm nhanh -->
        <div class="flex-1 min-w-44">
          <input v-model="searchQuery" @keyup.enter="applyFilters" type="text" placeholder="Tìm tên, SĐT phụ huynh..."
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 placeholder:text-slate-400 focus:border-rose-500 focus:outline-none" />
        </div>

        <!-- Nút Tải khách hàng về (CSV) -->
        <button type="button" @click="downloadCsv"
          class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer ml-auto">
          <Download class="h-3.5 w-3.5 text-slate-500" />
          <span>Tải khách hàng về</span>
        </button>
      </div>

      <!-- CHẾ ĐỘ 1: DANH SÁCH CHI TIẾT (CHUẨN BỐ CỤC ẢNH THAM KHẢO) -->
      <div v-if="viewMode === 'list'" class="pt-2">
        <!-- Khi không có dữ liệu -->
        <div v-if="leads.length === 0" class="py-10 text-center text-xs sm:text-sm text-slate-500">
          Chưa có khách hàng tiềm năng nào
        </div>

        <!-- Danh sách từng dòng khách hàng -->
        <div v-else class="divide-y divide-slate-100 space-y-4">
          <div v-for="lead in leads" :key="lead.id" class="pt-4 first:pt-0 space-y-2 group">
            <!-- Dòng 1: Tên Phụ Huynh (Ngày tiếp nhận) + Badge Nguồn -->
            <div class="flex items-center justify-between gap-2 flex-wrap">
              <div class="flex items-center gap-2">
                <span class="font-bold text-slate-900 text-sm sm:text-base">
                  {{ lead.parent_name }}
                  <span v-if="lead.student_name" class="font-medium text-slate-600">
                    (Bé: {{ lead.student_name }})
                  </span>
                  <span class="text-xs font-normal text-slate-500 ml-1">
                    ({{ formatDate(lead.created_at) }})
                  </span>
                </span>

                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                  {{ lead.source }}
                </span>

                <span v-if="lead.center" class="text-[11px] text-slate-400">
                  &bull; {{ lead.center }}
                </span>
              </div>
            </div>

            <!-- Dòng 2: Số điện thoại + Khóa học quan tâm -->
            <div class="text-xs text-slate-600 flex items-center gap-3 flex-wrap">
              <a :href="`tel:${lead.phone}`"
                class="font-mono text-slate-800 hover:text-blue-600 font-medium inline-flex items-center gap-1">
                <Phone class="h-3 w-3 text-slate-400" />
                {{ lead.phone }}
              </a>

              <span v-if="lead.course" class="text-blue-700 font-semibold bg-blue-50 px-2 py-0.5 rounded-md">
                [{{ lead.course.name }}]
              </span>
            </div>

            <!-- Dòng 3: Ghi chú lịch hẹn / test năng khiếu -->
            <div v-if="lead.notes || lead.trial_date" class="text-xs text-slate-600 flex items-start gap-1.5 italic">
              <span class="shrink-0">📅</span>
              <span class="text-slate-700">
                <strong v-if="lead.trial_date">Lịch test: {{ formatDate(lead.trial_date) }} &bull; </strong>
                {{ lead.notes || 'Chưa có ghi chú' }}
              </span>
            </div>

            <!-- Dòng 4: HÀNG NÚT THAO TÁC NHANH (Khớp 100% các nút trong ảnh mẫu) -->
            <div class="flex items-center gap-2 flex-wrap pt-1">
              <!-- Nút đổi trạng thái nhanh (Pill màu) -->
              <div class="relative">
                <select :value="lead.status" @change="changeStatus(lead, $event.target.value)"
                  class="rounded-lg bg-blue-600 text-white font-semibold px-2.5 py-1 text-xs focus:outline-none cursor-pointer border-0 shadow-2xs"
                  :class="{
                    '!bg-blue-600': lead.status === 'new',
                    '!bg-amber-500': lead.status === 'called',
                    '!bg-purple-600': lead.status === 'quote_sent',
                    '!bg-indigo-600': lead.status === 'trial_done',
                    '!bg-emerald-600': lead.status === 'registered',
                    '!bg-slate-500': lead.status === 'lost',
                  }">
                  <option v-for="st in statusOptions" :key="st.value" :value="st.value">
                    {{ st.label }}
                  </option>
                </select>
              </div>

              <!-- Nút [ 📄 Hóa đơn ] (Xanh dương) -->
              <router-link to="/invoices"
                class="inline-flex items-center gap-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white px-2.5 py-1 text-xs font-semibold shadow-2xs transition-all cursor-pointer">
                <FileText class="h-3.5 w-3.5" />
                <span>Hóa đơn</span>
              </router-link>

              <!-- Nút [ 📞 Chăm sóc ] (Vàng / Amber) -->
              <button type="button" @click="openCareModal(lead)"
                class="inline-flex items-center gap-1 rounded-lg bg-amber-100 hover:bg-amber-200 text-amber-800 px-2.5 py-1 text-xs font-semibold border border-amber-200 transition-all cursor-pointer">
                <Phone class="h-3.5 w-3.5 text-amber-600" />
                <span>Chăm sóc</span>
              </button>

              <!-- Nút [ 🗹 Đã đăng ký ] (Xanh mint / Emerald) -->
              <button type="button" @click="openConvertModal(lead)"
                class="inline-flex items-center gap-1 rounded-lg bg-emerald-100 hover:bg-emerald-200 text-emerald-800 px-2.5 py-1 text-xs font-semibold border border-emerald-300 transition-all cursor-pointer">
                <CheckCircle2 class="h-3.5 w-3.5 text-emerald-600" />
                <span>Đã đăng ký</span>
              </button>

              <!-- Nút [ 📄 ] (Sao chép tóm tắt lead) -->
              <button type="button" @click="openEditModal(lead)" title="Sửa thông tin khách hàng"
                class="rounded-lg border border-slate-200 bg-white hover:bg-slate-50 p-1 text-slate-500 hover:text-slate-800 transition-all cursor-pointer">
                <Pencil class="h-4 w-4" />
              </button>

              <button type="button" @click="copyLeadInfo(lead)" title="Sao chép thông tin khách hàng"
                class="rounded-lg border border-slate-200 bg-white hover:bg-slate-50 p-1 text-slate-500 hover:text-slate-800 transition-all cursor-pointer">
                <Check v-if="copySuccessId === lead.id" class="h-4 w-4 text-emerald-600" />
                <Copy v-else class="h-4 w-4" />
              </button>

              <!-- Nút [ 💬 ] (Mẫu tin nhắn soạn sẵn) -->
              <button type="button" @click="openMessageModal(lead)" title="Mẫu tin nhắn soạn sẵn"
                class="rounded-lg border border-slate-200 bg-white hover:bg-slate-50 p-1 text-slate-500 hover:text-slate-800 transition-all cursor-pointer">
                <MessageSquare class="h-4 w-4" />
              </button>

              <!-- Nút [ 🖼️ Hình ảnh ] (Tím) -->
              <button type="button" @click="openImageModal(lead)"
                class="inline-flex items-center gap-1 rounded-lg bg-purple-100 hover:bg-purple-200 text-purple-700 px-2.5 py-1 text-xs font-semibold border border-purple-200 transition-all cursor-pointer">
                <Image class="h-3.5 w-3.5 text-purple-600" />
                <span>Hình ảnh</span>
              </button>

              <!-- Nút [ 🗑️ ] (Xóa lead) -->
              <button type="button" @click="deleteLead(lead)" title="Xóa khách hàng này"
                class="rounded-lg border border-rose-200 bg-white hover:bg-rose-50 p-1 text-rose-500 transition-all cursor-pointer ml-auto">
                <Trash2 class="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>
        <Pagination :meta="pagination" class="mt-4" @change-page="changePage" @change-per-page="changePerPage" />
      </div>

      <!-- CHẾ ĐỘ 2: PIPELINE KANBAN (Cho phép kéo/đổi trực quan) -->
      <div v-else class="pt-2 overflow-x-auto pb-4">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 min-w-[900px]">
          <div v-for="col in pipelineColumns" :key="col.key"
            class="rounded-2xl border border-slate-200/90 bg-slate-50/50 p-3 space-y-3">
            <!-- Cột header -->
            <div class="flex items-center justify-between pb-1 border-b border-slate-200">
              <span class="text-xs font-bold text-slate-800">{{ col.label }}</span>
              <span class="rounded-full bg-slate-200/80 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                {{leads.filter(l => l.status === col.key).length}}
              </span>
            </div>

            <!-- Danh sách thẻ trong cột -->
            <div class="space-y-2.5">
              <div v-for="lead in leads.filter(l => l.status === col.key)" :key="lead.id"
                class="rounded-xl border border-slate-200 bg-white p-3 shadow-2xs space-y-2">
                <div class="flex items-start justify-between gap-1">
                  <div class="font-bold text-slate-900 text-xs">
                    {{ lead.parent_name }}
                    <span v-if="lead.student_name" class="block text-[11px] text-slate-500 font-normal">
                      Bé: {{ lead.student_name }}
                    </span>
                  </div>
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 shrink-0">
                    {{ lead.source }}
                  </span>
                </div>

                <div class="text-[11px] text-slate-500">
                  <span class="font-mono">{{ lead.phone }}</span>
                  <div v-if="lead.course" class="text-blue-700 font-medium truncate mt-0.5">
                    {{ lead.course.name }}
                  </div>
                </div>

                <div v-if="lead.notes" class="text-[11px] text-slate-400 italic line-clamp-2">
                  {{ lead.notes }}
                </div>

                <!-- Thao tác trong thẻ Kanban -->
                <div class="flex items-center justify-between pt-1 border-t border-slate-100 text-xs">
                  <button type="button" @click="openConvertModal(lead)"
                    class="font-semibold text-emerald-600 hover:underline inline-flex items-center gap-1">
                    <CheckCircle2 class="h-3.5 w-3.5" />
                    <span>Tạo HV</span>
                  </button>

                  <div class="flex items-center gap-1">
                    <button type="button" @click="openCareModal(lead)"
                      class="text-amber-600 p-1 hover:bg-amber-50 rounded">
                      <Phone class="h-3.5 w-3.5" />
                    </button>
                    <button type="button" @click="deleteLead(lead)" class="text-rose-500 p-1 hover:bg-rose-50 rounded">
                      <Trash2 class="h-3.5 w-3.5" />
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL 1: THÊM KHÁCH HÀNG MỚI -->
    <Modal :show="showCreateModal" :title="editingLeadId ? 'Sửa Khách Hàng Tiềm Năng' : 'Thêm Khách Hàng Tiềm Năng'"
      @close="showCreateModal = false">
      <form @submit.prevent="submitCreateLead" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Tên Phụ Huynh (*)</label>
            <input v-model="newLeadForm.parent_name" required placeholder="VD: Chị Hồng Phạm"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs sm:text-sm focus:border-rose-500 focus:outline-none" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Tên Học Viên / Bé</label>
            <input v-model="newLeadForm.student_name" placeholder="VD: Bé Gia Bảo (6 tuổi)"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs sm:text-sm focus:border-rose-500 focus:outline-none" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Số Điện Thoại (*)</label>
            <input v-model="newLeadForm.phone" required placeholder="VD: 0982531765"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs sm:text-sm focus:border-rose-500 focus:outline-none" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Nguồn Tiếp Cận</label>
            <select v-model="newLeadForm.source"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs sm:text-sm focus:border-rose-500 focus:outline-none">
              <option value="Facebook">Facebook Ads / Fanpage</option>
              <option value="Zalo">Zalo OA / Tin nhắn</option>
              <option value="Hotline">Hotline / Gọi điện</option>
              <option value="Giới thiệu">Người quen giới thiệu</option>
              <option value="Trực tiếp">Đến trực tiếp trung tâm</option>
              <option value="Website">Website</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Khóa Học Quan Tâm</label>
            <SearchableSelect v-model="newLeadForm.interested_course_id" :options="courseOptions"
              placeholder="Chọn khóa học..." />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Cơ sở áp dụng</label>
            <select v-model="newLeadForm.center"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs sm:text-sm focus:border-rose-500 focus:outline-none">
              <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Lịch Hẹn Học Thử / Test</label>
            <input v-model="newLeadForm.trial_date" type="datetime-local"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs sm:text-sm focus:border-rose-500 focus:outline-none" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Trạng Thái Ban Đầu</label>
            <select v-model="newLeadForm.status"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs sm:text-sm focus:border-rose-500 focus:outline-none">
              <option v-for="st in statusOptions" :key="st.value" :value="st.value">
                {{ st.label }}
              </option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">Ghi Chú Nhu Cầu</label>
          <textarea v-model="newLeadForm.notes" rows="2"
            placeholder="VD: Bé muốn học Piano vào tối Thứ 6 hoặc Chủ Nhật..."
            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs sm:text-sm focus:border-rose-500 focus:outline-none"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
          <button type="button" @click="showCreateModal = false"
            class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 cursor-pointer">
            Hủy
          </button>
          <button type="submit" :disabled="isSubmitting"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 text-xs font-semibold text-white shadow-2xs cursor-pointer">
            {{ isSubmitting ? 'Đang lưu...' : 'Lưu khách hàng' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- MODAL 2: GHI CHÚ CHĂM SÓC (📞) -->
    <Modal :show="showCareModal" title="Ghi Nhận Lịch Sử Chăm Sóc" @close="showCareModal = false">
      <div v-if="selectedLead" class="space-y-4 text-xs sm:text-sm">
        <div class="rounded-xl bg-slate-50 p-3 border border-slate-100">
          <div class="font-bold text-slate-900">{{ selectedLead.parent_name }}</div>
          <div class="text-xs text-slate-500 mt-0.5">SĐT: {{ selectedLead.phone }} &bull; Khóa: {{
            selectedLead.course?.name || 'N/A' }}</div>
          <div v-if="selectedLead.notes"
            class="text-xs text-slate-700 mt-2 p-2 bg-white rounded border border-slate-200/80 whitespace-pre-line">
            {{ selectedLead.notes }}
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">Ghi chú cuộc gọi / tư vấn mới</label>
          <textarea v-model="careNote" rows="3"
            placeholder="VD: Đã gọi tư vấn, mẹ hẹn thứ 7 tuần này dẫn bé qua trung tâm test phím..."
            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
          <button type="button" @click="showCareModal = false"
            class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Đóng
          </button>
          <button type="button" @click="submitCareNote"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 text-xs font-semibold text-white shadow-2xs">
            Lưu ghi chú
          </button>
        </div>
      </div>
    </Modal>

    <!-- MODAL 3: CHUYỂN THÀNH HỌC VIÊN CHÍNH THỨC -->
    <Modal :show="showConvertModal" title="Kích Hoạt & Tạo Hồ Sơ Học Viên" @close="showConvertModal = false">
      <form @submit.prevent="submitConvert" class="space-y-4 text-xs sm:text-sm">
        <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-xs text-emerald-800">
          Hệ thống sẽ tự động tạo hồ sơ Học Viên chính thức và sinh Hóa đơn thu học phí đầu vào.
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Tên Học Viên (*)</label>
            <input v-model="convertForm.student_name" required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Giới tính</label>
            <select v-model="convertForm.gender"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none">
              <option value="Nam">Nam</option>
              <option value="Nữ">Nữ</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Khóa học đăng ký (*)</label>
            <select v-model="convertForm.course_id" required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none">
              <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Số buổi đăng ký (*)</label>
            <input v-model.number="convertForm.total_sessions" type="number" min="1" required
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-bold" />
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">Số tiền học phí đã thu (VNĐ)</label>
          <MoneyInput v-model="convertForm.price"
            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-bold text-rose-600" />
        </div>

        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
          <button type="button" @click="showConvertModal = false"
            class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Hủy
          </button>
          <button type="submit" :disabled="isSubmitting"
            class="rounded-xl bg-emerald-600 hover:bg-emerald-700 px-5 py-2 text-xs font-semibold text-white shadow-2xs">
            {{ isSubmitting ? 'Đang tạo...' : 'Xác nhận tạo học viên' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- MODAL 4: HÌNH ẢNH HỢP ĐỒNG / BÀI TEST (🖼️) -->
    <Modal :show="showImageModal" title="Hình Ảnh & Phiếu Đăng Ký" @close="showImageModal = false">
      <div v-if="selectedLead" class="space-y-4 text-xs sm:text-sm">
        <p class="text-slate-600">
          Lưu trữ hình ảnh phiếu đăng ký, kết quả bài test đầu vào hoặc ảnh chân dung của học viên.
        </p>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">Link URL hình ảnh</label>
          <input v-model="imageUrlInput" type="url" placeholder="https://..."
            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
        </div>

        <div v-if="imageUrlInput"
          class="rounded-xl border border-slate-200 overflow-hidden max-h-64 flex items-center justify-center bg-slate-50">
          <img :src="imageUrlInput" alt="Ảnh phiếu đăng ký" class="max-h-64 object-contain" />
        </div>

        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
          <button type="button" @click="showImageModal = false"
            class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Đóng
          </button>
          <button type="button" @click="submitImage"
            class="rounded-xl bg-purple-600 hover:bg-purple-700 px-5 py-2 text-xs font-semibold text-white shadow-2xs">
            Lưu hình ảnh
          </button>
        </div>
      </div>
    </Modal>

    <!-- MODAL 5: MẪU TIN NHẮN (💬) -->
    <MessageTemplateModal :show="showMessageModal" :student="selectedLead" :templates="leadTemplates"
      @close="showMessageModal = false" />
  </div>
</template>
