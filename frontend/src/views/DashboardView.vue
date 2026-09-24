<script setup>
import { ref, onMounted } from 'vue';
import { dashboardApi } from '../services/api';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import RetentionNoteModal from '../components/RetentionNoteModal.vue';
import RenewModal from '../components/RenewModal.vue';
import AttendanceButton from '../components/AttendanceButton.vue';
import {
  Users,
  PauseCircle,
  Ban,
  AlertTriangle,
  Receipt,
  DollarSign,
  BookOpen,
  FileX,
  CheckCircle2,
  Sparkles,
  Megaphone,
  FileText,
  PhoneCall,
  Check,
  MessageSquare,
  ChevronDown,
  Building2,
  Clock,
  ArrowUpRight,
} from 'lucide-vue-next';

const stats = ref({});
const expiringStudents = ref([]);
const absenceAlerts = ref([]);
const expiringEndDateStudents = ref([]);
const recentAttendances = ref([]);
const courses = ref([]);
const templates = ref([]);
const loading = ref(true);

const selectedCenter = ref('all');
const showTemplateModal = ref(false);
const showNoteModal = ref(false);
const showRenewModal = ref(false);
const selectedStudent = ref(null);

const quoteList = [
  '“Giáo dục là hành trình dài. Kiên nhẫn luôn là một phần của kết quả.”',
  '“Thành công của học viên là niềm tự hào và động lực lớn nhất của người thầy.”',
  '“Sự gắn kết tận tâm hôm nay tạo nên sự tin tưởng bền vững của phụ huynh ngày mai.”',
];
const currentQuote = ref(quoteList[0]);

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await dashboardApi.getStats();
    stats.value = res.data.stats || {};
    expiringStudents.value = res.data.expiringStudents || [];
    absenceAlerts.value = res.data.absenceAlerts || [];
    expiringEndDateStudents.value = res.data.expiringEndDateStudents || [];
    recentAttendances.value = res.data.recentAttendances || [];
    courses.value = res.data.courses || [];
    templates.value = res.data.templates || [];
  } catch (err) {
    console.error('Error fetching dashboard data: ', err);
  } finally {
    loading.value = false;
  }
};

onMounted(fetchData);

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

const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const formatDate = (val) => {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleDateString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
};

const getZaloLink = (phone) => {
  if (!phone) return '#';
  return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};
</script>

<template>
  <div class="space-y-6 w-full">
    <!-- Center Filter Bar matching Senyapp -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
      <div class="flex items-center gap-3 w-full sm:w-auto">
        <label class="text-xs sm:text-sm font-bold text-slate-800 shrink-0">
          Trung tâm
        </label>
        <div class="relative w-full sm:w-64">
          <select
            v-model="selectedCenter"
            class="w-full appearance-none rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 pr-8 text-xs sm:text-sm font-medium text-slate-800 hover:border-slate-300 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
          >
            <option value="all">Tất cả trung tâm</option>
            <option value="center-1">Cơ sở 1 - Khoa Anh Music</option>
            <option value="center-2">Cơ sở 2 - Khoa Anh Art</option>
          </select>
          <ChevronDown class="pointer-events-none absolute right-3 top-2.5 h-4 w-4 text-slate-400" />
        </div>
      </div>

      <div class="flex items-center gap-2 self-end sm:self-auto">
        <router-link
          to="/retention"
          class="rounded-xl bg-red-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-red-700 shadow-xs transition-colors flex items-center gap-1.5"
        >
          <span>Quy trình Tái đăng ký</span>
          <span class="rounded-full bg-white/20 px-1.5 py-0.2 text-[10px] font-extrabold">
            {{ stats.expiringCount || 0 }}
          </span>
        </router-link>
      </div>
    </div>

    <!-- Motivational Message Banner ("Thông điệp hôm nay") matching Senyapp -->
    <div class="rounded-2xl border border-red-100 bg-white p-5 sm:p-6 shadow-2xs">
      <div class="flex items-center gap-2 text-red-600 font-bold text-xs sm:text-sm uppercase tracking-wide">
        <Megaphone class="h-4 w-4 shrink-0" />
        <span>Thông điệp hôm nay</span>
      </div>
      <p class="mt-2.5 text-sm sm:text-base font-bold text-red-600 italic">
        {{ currentQuote }}
      </p>
    </div>

    <!-- 10 Metric Stat Cards (5 cols x 2 rows) matching Senyapp exactly -->
    <div class="space-y-3">
      <!-- ROW 1 (5 Cards) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <!-- 1. HỌC VIÊN ĐANG HỌC -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Học viên đang học
            </p>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">
              {{ stats.activeStudents || 0 }}
            </p>
          </div>
          <div class="text-red-600">
            <Users class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>

        <!-- 2. TẠM DỪNG -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Tạm dừng
            </p>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">
              {{ stats.pausedStudents || 0 }}
            </p>
          </div>
          <div class="text-red-600">
            <PauseCircle class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>

        <!-- 3. ĐÃ NGHỈ -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Đã nghỉ
            </p>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">
              {{ stats.leftStudents || 0 }}
            </p>
          </div>
          <div class="text-red-600">
            <Ban class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>

        <!-- 4. < 6 BUỔI CÒN LẠI -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              &lt; 6 buổi còn lại
            </p>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">
              {{ stats.expiringCount || 0 }}
            </p>
          </div>
          <div class="text-red-600">
            <AlertTriangle class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>

        <!-- 5. HÓA ĐƠN CÔNG NỢ -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between col-span-2 sm:col-span-1">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Hóa đơn công nợ
            </p>
            <div class="mt-1">
              <span class="text-2xl sm:text-3xl font-black text-slate-900 block">
                {{ stats.debtInvoicesCount || 0 }}
              </span>
              <span class="text-[10px] text-red-600 font-bold block mt-0.5">
                Công nợ: {{ formatVND(stats.debtTotalAmount) }}
              </span>
            </div>
          </div>
          <div class="text-red-600">
            <Receipt class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>
      </div>

      <!-- ROW 2 (5 Cards) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <!-- 6. DOANH THU THÁNG NÀY -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Doanh thu tháng này
            </p>
            <p class="text-base sm:text-xl font-black text-slate-900 mt-1 truncate">
              {{ formatVND(stats.monthRevenue) }}
            </p>
          </div>
          <div class="text-red-600">
            <DollarSign class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>

        <!-- 7. DOANH THU NĂM NAY -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Doanh thu năm nay
            </p>
            <p class="text-base sm:text-xl font-black text-slate-900 mt-1 truncate">
              {{ formatVND(stats.yearRevenue || stats.monthRevenue) }}
            </p>
          </div>
          <div class="text-red-600">
            <DollarSign class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>

        <!-- 8. BUỔI ĐIỂM DANH (THÁNG) -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Buổi điểm danh (tháng)
            </p>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">
              {{ stats.monthPresentCount || 0 }}
            </p>
          </div>
          <div class="text-red-600">
            <BookOpen class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>

        <!-- 9. VẮNG (THÁNG) -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Vắng (tháng)
            </p>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">
              {{ stats.monthAbsentCount || 0 }}
            </p>
          </div>
          <div class="text-red-600">
            <FileX class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>

        <!-- 10. TỶ LỆ ĐI HỌC (THÁNG) -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs hover:shadow-xs transition-shadow flex items-center justify-between col-span-2 sm:col-span-1">
          <div>
            <p class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-500">
              Tỷ lệ đi học (tháng)
            </p>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">
              {{ stats.attendanceRate ?? 100 }}%
            </p>
          </div>
          <div class="text-red-600">
            <CheckCircle2 class="h-6 w-6 stroke-[1.75]" />
          </div>
        </div>
      </div>
    </div>

    <!-- SECTION 1: CẢNH BÁO SẮP HẾT BUỔI matching Senyapp layout -->
    <div class="rounded-2xl border border-red-200/90 bg-white shadow-2xs overflow-hidden">
      <div class="p-4 sm:p-5 border-b border-red-100 bg-red-50/30">
        <div class="flex items-center gap-2 text-red-600 font-bold text-sm">
          <AlertTriangle class="h-4 w-4" />
          <span>Cảnh báo sắp hết buổi</span>
        </div>
        <p class="text-xs text-slate-500 mt-0.5">
          Học viên có tổng buổi còn lại ít hơn 6
        </p>
      </div>

      <div class="p-4 sm:p-5 divide-y divide-slate-100 space-y-4">
        <!-- Student Row in Senyapp Style -->
        <div
          v-for="s in expiringStudents"
          :key="s.id"
          class="pt-4 first:pt-0 flex flex-col lg:flex-row lg:items-center justify-between gap-4"
        >
          <!-- Left: Name & Course -->
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <router-link
                :to="`/students/${s.id}`"
                class="font-black text-slate-900 hover:text-red-600 text-sm sm:text-base tracking-tight uppercase"
              >
                {{ s.name }} &mdash; <span class="text-slate-500 font-medium normal-case">(PH: {{ s.parent_name || 'N/A' }})</span>
              </router-link>
            </div>
            <p class="text-xs text-slate-500 mt-0.5">
              [{{ s.course?.category || 'HSSV' }}] {{ s.course?.name || 'Khóa học năng khiếu' }}
            </p>
          </div>

          <!-- Right: Horizontal Buttons matching Senyapp exactly -->
          <div class="flex flex-wrap items-center gap-2 shrink-0">
            <!-- [ Hóa đơn ] (Blue) -->
            <router-link
              to="/invoices"
              class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 px-3 py-1.5 text-xs font-bold text-white shadow-2xs transition-colors"
            >
              <FileText class="h-3.5 w-3.5" />
              <span>Hóa đơn</span>
            </router-link>

            <!-- [ Chăm sóc ] (Orange) -->
            <button
              @click="openNoteModal(s)"
              class="inline-flex items-center gap-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 px-3 py-1.5 text-xs font-bold text-white shadow-2xs transition-colors"
            >
              <PhoneCall class="h-3.5 w-3.5" />
              <span>Chăm sóc</span>
            </button>

            <!-- [ Đã đóng ] (Green outline / pill) -->
            <button
              @click="openRenewModal(s)"
              class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500 bg-emerald-50/70 hover:bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-700 transition-colors"
            >
              <Check class="h-3.5 w-3.5" />
              <span>Đã đóng</span>
            </button>

            <!-- [ X còn lại ] (Cyan / Blue pill badge) -->
            <span class="inline-flex items-center rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-black text-white shadow-2xs">
              {{ s.remaining_sessions }} còn lại
            </span>

            <!-- [ Mẫu tin nhắn ] (Gray outline) -->
            <button
              @click="openTemplateModal(s)"
              class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors"
            >
              <MessageSquare class="h-3.5 w-3.5 text-slate-500" />
              <span>Mẫu tin nhắn</span>
            </button>

            <!-- Quick Attendance & Zalo -->
            <a
              :href="getZaloLink(s.parent_phone)"
              target="_blank"
              class="rounded-lg bg-blue-50 border border-blue-200 px-2.5 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-100"
              title="Nhắn tin Zalo"
            >
              Zalo
            </a>
            <AttendanceButton :student="s" :compact="true" @attended="fetchData" @undone="fetchData" />
          </div>
        </div>

        <div v-if="expiringStudents.length === 0" class="py-6 text-center text-xs text-slate-400">
          Tất cả học viên đều còn nhiều hơn 5 buổi học.
        </div>
      </div>
    </div>

    <!-- SECTION 2: CẢNH BÁO ĐẾN HẠN ĐÓNG HỌC PHÍ matching Senyapp layout -->
    <div class="rounded-2xl border border-amber-200/90 bg-white shadow-2xs overflow-hidden">
      <div class="p-4 sm:p-5 border-b border-amber-100 bg-amber-50/30">
        <div class="flex items-center gap-2 text-amber-700 font-bold text-sm">
          <AlertTriangle class="h-4 w-4" />
          <span>Cảnh báo đến hạn đóng học phí</span>
        </div>
        <p class="text-xs text-slate-500 mt-0.5">
          Khoá học có ngày kết thúc dự kiến trong vòng 5 ngày tới
        </p>
      </div>

      <div class="p-4 sm:p-5">
        <div v-if="expiringEndDateStudents.length === 0" class="py-4 text-xs font-semibold text-slate-600 flex items-center gap-1.5">
          <span>Tất cả ổn</span>
          <span>🥂</span>
        </div>

        <div v-else class="divide-y divide-slate-100 space-y-3">
          <div
            v-for="s in expiringEndDateStudents"
            :key="s.id"
            class="pt-3 first:pt-0 flex items-center justify-between"
          >
            <div>
              <span class="font-bold text-slate-900 text-xs">{{ s.name }}</span>
              <span class="text-slate-500 text-xs ml-2">({{ s.course?.name }})</span>
              <p class="text-[11px] text-amber-700 mt-0.5">Hạn kết thúc: {{ s.end_date }}</p>
            </div>
            <button
              @click="openRenewModal(s)"
              class="rounded-lg bg-amber-600 px-3 py-1 text-xs font-bold text-white hover:bg-amber-700"
            >
              Gia hạn
            </button>
          </div>
        </div>
      </div>
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
