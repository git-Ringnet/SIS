<script setup>
import { ref, onMounted } from 'vue';
import { retentionApi } from '../services/api';
import Badge from '../components/Badge.vue';
import StateButtons from '../components/StateButtons.vue';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import RetentionNoteModal from '../components/RetentionNoteModal.vue';
import RenewModal from '../components/RenewModal.vue';
import AttendanceButton from '../components/AttendanceButton.vue';
import Pagination from '../components/Pagination.vue';
import {
  Flame,
  Receipt,
  PhoneCall,
  CheckCircle2,
  ExternalLink,
  ChevronDown,
  ChevronUp,
  FileText,
} from 'lucide-vue-next';

const students = ref([]);
const templates = ref([]);
const courses = ref([]);
const counts = ref({
  total_expiring: 0,
  normal: 0,
  invoice_sent: 0,
  following_up: 0,
  renewed: 0,
});

const currentStatus = ref('all');
const currentThreshold = ref(5);
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);
const expandedLogsStudentId = ref(null);

const showTemplateModal = ref(false);
const showNoteModal = ref(false);
const showRenewModal = ref(false);
const selectedStudent = ref(null);

const fetchData = async () => {
  try {
    const res = await retentionApi.getChecklist({
      status: currentStatus.value,
      threshold: currentThreshold.value,
      page: page.value,
      per_page: perPage.value,
    });
    pagination.value = res.data.pagination || null;
    // Trang hiện tại không còn dữ liệu (ví dụ học viên vừa gia hạn rời danh sách) thì lùi về trang cuối
    if (pagination.value && page.value > pagination.value.last_page && pagination.value.last_page >= 1) {
      page.value = pagination.value.last_page;
      return fetchData();
    }
    students.value = res.data.students || [];
    templates.value = res.data.templates || [];
    courses.value = res.data.courses || [];
    counts.value = res.data.counts || {};
  } catch (err) {
    console.error('Error fetching retention checklist: ', err);
  }
};

onMounted(fetchData);

const filterByStatus = (status) => {
  currentStatus.value = status;
  page.value = 1;
  fetchData();
};

const filterByThreshold = (threshold) => {
  currentThreshold.value = threshold;
  page.value = 1;
  fetchData();
};

const changePage = (p) => {
  page.value = p;
  fetchData();
};

const changePerPage = (n) => {
  perPage.value = n;
  page.value = 1;
  fetchData();
};

const openTemplateModal = (student) => {
  selectedStudent.value = student;
  showTemplateModal.value = true;
};

const openNoteModal = (student) => {
  selectedStudent.value = student;
  showNoteModal.value = true;
};

const openRenewModal = (student) => {
  selectedStudent.value = student;
  showRenewModal.value = true;
};

const toggleLogs = (id) => {
  expandedLogsStudentId.value = expandedLogsStudentId.value === id ? null : id;
};

const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const formatDate = (val) => {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleDateString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
};

</script>

<template>
  <div class="space-y-6 w-full">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div>
        <div class="flex items-center gap-2">
          <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100 text-rose-600">
            <Flame class="h-5 w-5 animate-pulse" />
          </div>
          <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900">
            Checklist Tái Đăng Ký & Giữ Chân Học Viên (Retention)
          </h1>
        </div>
        <p class="text-xs text-slate-500 mt-1">
          Hệ thống tự động lọc các học viên có số buổi còn lại &le; 5. Dùng dãy nút trạng thái để cập nhật tiến độ tương tác phụ huynh.
        </p>
      </div>

      <!-- Threshold Selector -->
      <div class="flex items-center gap-1.5 bg-white rounded-xl p-1 border border-slate-200 shadow-2xs text-xs self-start md:self-auto">
        <span class="text-slate-500 px-2 font-medium">Ngưỡng:</span>
        <button
          v-for="th in [3, 5, 8]"
          :key="th"
          @click="filterByThreshold(th)"
          class="rounded-lg px-2.5 py-1 font-bold transition-all"
          :class="currentThreshold === th ? 'bg-rose-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100'"
        >
          &le; {{ th }} buổi
        </button>
      </div>
    </div>

    <!-- Pipeline Status Tabs -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
      <!-- All -->
      <button
        @click="filterByStatus('all')"
        class="rounded-xl border p-3 text-left transition-all relative overflow-hidden"
        :class="currentStatus === 'all' ? 'border-indigo-600 bg-indigo-50/60 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
      >
        <span class="text-xs font-semibold text-slate-500">Tất cả sắp hết</span>
        <div class="mt-1 flex items-baseline justify-between">
          <span class="text-2xl font-extrabold text-slate-900">{{ counts.total_expiring || 0 }}</span>
          <span class="text-[11px] text-slate-400">học viên</span>
        </div>
      </button>

      <!-- Normal -->
      <button
        @click="filterByStatus('normal')"
        class="rounded-xl border p-3 text-left transition-all relative overflow-hidden"
        :class="currentStatus === 'normal' ? 'border-slate-600 bg-slate-100 ring-2 ring-slate-400/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
      >
        <span class="text-xs font-semibold text-slate-500">Chưa chăm sóc</span>
        <div class="mt-1 flex items-baseline justify-between">
          <span class="text-2xl font-extrabold text-slate-700">{{ counts.normal || 0 }}</span>
          <span class="text-[11px] text-slate-400">chờ xử lý</span>
        </div>
      </button>

      <!-- Invoice Sent -->
      <button
        @click="filterByStatus('invoice_sent')"
        class="rounded-xl border p-3 text-left transition-all relative overflow-hidden"
        :class="currentStatus === 'invoice_sent' ? 'border-blue-600 bg-blue-50 ring-2 ring-blue-500/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
      >
        <div class="flex items-center gap-1.5 text-xs font-bold text-blue-700">
          <Receipt class="h-3.5 w-3.5" />
          <span>Đã gửi hóa đơn</span>
        </div>
        <div class="mt-1 flex items-baseline justify-between">
          <span class="text-2xl font-extrabold text-blue-600">{{ counts.invoice_sent || 0 }}</span>
          <span class="text-[11px] text-blue-500">xanh dương</span>
        </div>
      </button>

      <!-- Following Up -->
      <button
        @click="filterByStatus('following_up')"
        class="rounded-xl border p-3 text-left transition-all relative overflow-hidden"
        :class="currentStatus === 'following_up' ? 'border-amber-500 bg-amber-50 ring-2 ring-amber-500/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
      >
        <div class="flex items-center gap-1.5 text-xs font-bold text-amber-800">
          <PhoneCall class="h-3.5 w-3.5" />
          <span>Đang chăm sóc</span>
        </div>
        <div class="mt-1 flex items-baseline justify-between">
          <span class="text-2xl font-extrabold text-amber-600">{{ counts.following_up || 0 }}</span>
          <span class="text-[11px] text-amber-600">màu cam</span>
        </div>
      </button>

      <!-- Renewed -->
      <button
        @click="filterByStatus('renewed')"
        class="rounded-xl border p-3 text-left transition-all relative overflow-hidden"
        :class="currentStatus === 'renewed' ? 'border-emerald-600 bg-emerald-50 ring-2 ring-emerald-500/20' : 'border-slate-200 bg-white hover:bg-slate-50'"
      >
        <div class="flex items-center gap-1.5 text-xs font-bold text-emerald-700">
          <CheckCircle2 class="h-3.5 w-3.5" />
          <span>Đã đóng & Tái ký</span>
        </div>
        <div class="mt-1 flex items-baseline justify-between">
          <span class="text-2xl font-extrabold text-emerald-600">{{ counts.renewed || 0 }}</span>
          <span class="text-[11px] text-emerald-600">thành công 🎉</span>
        </div>
      </button>
    </div>

    <!-- Checklist Table Card -->
    <div class="rounded-xl sm:rounded-2xl border border-slate-200/90 bg-white shadow-2xs overflow-hidden">
      <div class="flex items-center justify-between border-b border-slate-100 p-4 bg-slate-50/50">
        <span class="text-xs font-bold text-slate-700">
          Danh Sách Học Viên Thuộc Chu Kỳ Giữ Chân ({{ students.length }} học viên)
        </span>
        <span class="text-xs text-slate-500 hidden sm:inline">
          Nhấp trực tiếp vào các nút trạng thái để cập nhật tiến độ
        </span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs min-w-[780px]">
          <thead class="bg-slate-50/90 text-slate-500 border-b border-slate-100">
            <tr>
              <th class="py-3 px-3.5 font-semibold w-[220px]">Học viên & Phụ huynh</th>
              <th class="py-3 px-3 font-semibold w-[150px]">Khóa học</th>
              <th class="py-3 px-2.5 font-semibold text-center w-[120px]">Tiến độ khóa</th>
              <th class="py-3 px-3 font-semibold">Dãy Nút Trạng Thái (1-Click Retention)</th>
              <th class="py-3 px-3 font-semibold w-[200px]">Ghi chú gần nhất</th>
              <th class="py-3 px-3.5 font-semibold text-right w-[110px]">Điểm danh</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-slate-700">
            <template v-for="student in students" :key="student.id">
              <tr class="hover:bg-slate-50/80 transition-colors">
                <!-- Student & Parent info -->
                <td class="py-3 px-3.5">
                  <div class="flex items-center gap-2.5">
                    <div class="min-w-0">
                      <router-link :to="`/students/${student.id}`" class="font-bold text-slate-900 hover:text-indigo-600 block truncate">
                        {{ student.name }}
                      </router-link>
                      <div class="text-[11px] text-slate-500 truncate mt-0.5">
                        <span>PH: {{ student.parent_name }}</span> &bull;
                        <a :href="`tel:${student.parent_phone}`" class="text-blue-600 font-semibold hover:underline">
                          {{ student.parent_phone }}
                        </a>
                      </div>
                    </div>
                  </div>
                </td>

                <!-- Course -->
                <td class="py-3 px-3">
                  <div class="font-semibold text-slate-800 truncate" :title="student.course?.name">{{ student.course?.name }}</div>
                  <div class="text-[11px] text-slate-400">{{ formatVND(student.course?.price) }}</div>
                </td>

                <!-- Progress & Sessions -->
                <td class="py-3 px-2.5 text-center whitespace-nowrap">
                  <span
                    class="inline-block rounded-md px-2 py-0.5 font-extrabold text-xs shadow-2xs"
                    :class="student.remaining_sessions <= 2 ? 'bg-rose-100 text-rose-700 ring-1 ring-rose-400' : 'bg-amber-100 text-amber-800 ring-1 ring-amber-400'"
                  >
                    Còn {{ student.remaining_sessions }} buổi
                  </span>

                  <div class="w-20 mx-auto bg-slate-100 rounded-full h-1 mt-1 overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all"
                      :class="student.remaining_sessions <= 2 ? 'bg-rose-500' : 'bg-amber-500'"
                      :style="{ width: `${Math.min(100, Math.round(((student.total_sessions - student.remaining_sessions) / student.total_sessions) * 100))}%` }"
                    ></div>
                  </div>
                </td>

                <!-- State Buttons Column -->
                <td class="py-3 px-3">
                  <StateButtons
                    :student="student"
                    @open-template="openTemplateModal"
                    @open-note="openNoteModal"
                    @open-renew="openRenewModal"
                    @updated="fetchData"
                  />
                </td>

                <!-- Recent Retention Log -->
                <td class="py-3 px-3">
                  <div v-if="student.latest_retention_log" class="rounded-lg bg-slate-50 p-1.5 text-[11px] border border-slate-100">
                    <div class="flex items-center justify-between text-slate-400 text-[10px]">
                      <span class="font-semibold text-slate-700">{{ student.latest_retention_log.created_by }}</span>
                      <span>{{ formatDate(student.latest_retention_log.contact_date) }}</span>
                    </div>
                    <p class="text-slate-600 mt-0.5 line-clamp-1">
                      {{ student.latest_retention_log.content }}
                    </p>
                  </div>
                  <div v-else class="text-[11px] text-slate-400 italic">
                    Chưa có nhật ký
                  </div>

                  <button
                    v-if="student.retention_logs?.length > 1"
                    @click="toggleLogs(student.id)"
                    class="mt-1 text-[10px] font-medium text-indigo-600 hover:underline inline-flex items-center gap-0.5"
                  >
                    <span>Lịch sử ({{ student.retention_logs.length }})</span>
                    <ChevronDown v-if="expandedLogsStudentId !== student.id" class="h-3 w-3" />
                    <ChevronUp v-else class="h-3 w-3" />
                  </button>
                </td>

                <!-- 1-Click Attendance -->
                <td class="py-3 px-3.5 text-right">
                  <AttendanceButton :student="student" @attended="fetchData" @undone="fetchData" />
                </td>
              </tr>

              <!-- Expanded Log Drawer -->
              <tr v-if="expandedLogsStudentId === student.id" class="bg-slate-50/90">
                <td colspan="6" class="p-3 pl-10">
                  <div class="rounded-xl bg-white p-3 border border-slate-200 space-y-2">
                    <h4 class="font-bold text-slate-800 text-xs flex items-center gap-1.5">
                      <FileText class="h-3.5 w-3.5 text-amber-500" />
                      <span>Toàn bộ nhật ký chăm sóc: {{ student.name }}</span>
                    </h4>

                    <div class="space-y-1.5 max-h-40 overflow-y-auto">
                      <div
                        v-for="log in student.retention_logs"
                        :key="log.id"
                        class="rounded-lg bg-slate-50 p-2 text-xs border border-slate-100 flex items-start justify-between gap-4"
                      >
                        <div>
                          <span class="font-semibold text-slate-800">{{ log.created_by }}:</span>
                          <span class="text-slate-700 ml-1">{{ log.content }}</span>
                        </div>
                        <span class="text-[10px] text-slate-400 shrink-0 font-mono">{{ formatDate(log.contact_date) }}</span>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>

            <tr v-if="students.length === 0">
              <td colspan="6" class="py-12 text-center text-xs text-slate-400">
                Không có học viên nào phù hợp với bộ lọc hiện tại.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :meta="pagination" @change-page="changePage" @change-per-page="changePerPage" />
    </div>

    <!-- Modals -->
    <MessageTemplateModal
      :show="showTemplateModal"
      :student="selectedStudent"
      :templates="templates"
      @close="showTemplateModal = false"
    />

    <RetentionNoteModal
      :show="showNoteModal"
      :student="selectedStudent"
      @close="showNoteModal = false"
      @saved="fetchData"
    />

    <RenewModal
      :show="showRenewModal"
      :student="selectedStudent"
      @close="showRenewModal = false"
      @renewed="fetchData"
    />
  </div>
</template>
