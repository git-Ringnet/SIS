<script setup>
import { useCenters } from '../services/useCenters';
import { notify } from '../services/notify';
import { ref, onMounted } from 'vue';
import { exportApi } from '../services/api';
import {
  ArrowLeft,
  Users,
  BookOpen,
  CalendarCheck2,
  DollarSign,
  Package,
  Download,
  CheckCircle2,
} from 'lucide-vue-next';

const selectedCenter = ref('all');
const downloadingType = ref(null);
const successMessage = ref('');

const exportCards = [
  {
    type: 'students',
    title: 'Xuất Học viên',
    desc: 'Danh sách tất cả học viên với thông tin đầy đủ.',
    icon: Users,
  },
  {
    type: 'courses',
    title: 'Xuất Khóa học',
    desc: 'Danh sách khóa học và tổng số buổi đã học.',
    icon: BookOpen,
  },
  {
    type: 'attendance',
    title: 'Xuất Điểm danh',
    desc: 'Toàn bộ lịch sử điểm danh theo học viên.',
    icon: CalendarCheck2,
  },
  {
    type: 'payments',
    title: 'Xuất Thanh toán',
    desc: 'Lịch sử học phí và học cụ đã đóng.',
    icon: DollarSign,
  },
  {
    type: 'supplies',
    title: 'Xuất Học cụ',
    desc: 'Danh sách học cụ với giá nhập, giá bán và tồn kho.',
    icon: Package,
  },
];

const { centerNames, loadCenters } = useCenters();
onMounted(() => loadCenters());

const handleExport = async (type, title) => {
  downloadingType.value = type;
  try {
    await exportApi.exportCsv(type, selectedCenter.value);
    successMessage.value = `Đã xuất ${title} thành công! File CSV chuẩn tiếng Việt đã được tải xuống máy tính.`;
    setTimeout(() => {
      successMessage.value = '';
    }, 4000);
  } catch (err) {
    notify.apiError(err, `Không thể xuất ${title}`);
  } finally {
    downloadingType.value = null;
  }
};
</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- Back to Settings / Navigation -->
    <div>
      <router-link
        to="/settings"
        class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-slate-600 hover:text-slate-900 transition-colors"
      >
        <ArrowLeft class="h-4 w-4" />
        <span>Cài đặt</span>
      </router-link>
    </div>

    <!-- Header & Subtitle -->
    <div class="space-y-1">
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Dữ liệu</h1>
      <p class="text-xs sm:text-sm text-slate-500">
        Xuất dữ liệu trung tâm của bạn bất cứ lúc nào. Dữ liệu luôn thuộc về trung tâm của bạn.
        <span class="text-rose-600 font-semibold ml-1">Chỉ dành cho gói Pro</span>
      </p>
    </div>

    <!-- Success Toast Notification -->
    <div
      v-if="successMessage"
      class="rounded-xl border border-emerald-200 bg-emerald-50/90 p-3.5 flex items-center gap-2.5 text-xs sm:text-sm font-medium text-emerald-800 shadow-xs transition-all"
    >
      <CheckCircle2 class="h-4.5 w-4.5 text-emerald-600 shrink-0" />
      <span>{{ successMessage }}</span>
    </div>

    <!-- Card 1: BỘ LỌC TRUNG TÂM -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4 max-w-2xl">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Bộ lọc</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Chọn trung tâm trước khi xuất dữ liệu.
        </p>
      </div>

      <div class="max-w-md">
        <label class="block text-xs font-semibold text-slate-700 mb-1.5">Trung tâm</label>
        <select
          v-model="selectedCenter"
          class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
        >
          <option value="all">Tất cả trung tâm</option>
          <option v-for="c in centerNames" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
    </div>

    <!-- Grid: CÁC KHỐI XUẤT DỮ LIỆU -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div
        v-for="item in exportCards"
        :key="item.type"
        class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs flex flex-col justify-between space-y-4 hover:shadow-xs transition-all"
      >
        <div class="space-y-1.5">
          <div class="flex items-center gap-2">
            <component :is="item.icon" class="h-4.5 w-4.5 text-rose-500" />
            <h3 class="text-sm sm:text-base font-bold text-slate-900">{{ item.title }}</h3>
          </div>
          <p class="text-xs sm:text-sm text-slate-500 leading-relaxed">
            {{ item.desc }}
          </p>
        </div>

        <div class="pt-2">
          <button
            type="button"
            :disabled="downloadingType === item.type"
            @click="handleExport(item.type, item.title)"
            class="inline-flex items-center gap-1.5 rounded-xl bg-rose-400/90 hover:bg-rose-500 disabled:opacity-60 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-xs transition-all active:scale-[0.98]"
          >
            <Download class="h-3.5 w-3.5" />
            <span>{{ downloadingType === item.type ? 'Đang xuất...' : 'Export' }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
