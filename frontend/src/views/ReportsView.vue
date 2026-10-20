<script setup>
import { ref, computed, onMounted } from 'vue';
import { reportApi } from '../services/api';
import {
  Users,
  TrendingUp,
  CalendarCheck2,
  Building2,
  Filter,
  CheckCircle2,
  AlertCircle,
  HelpCircle,
  ChevronDown,
  Info,
} from 'lucide-vue-next';

// State
const loading = ref(true);
const selectedPeriod = ref('year');
const selectedYear = ref(new Date().getFullYear());
// Năm hiện tại và 4 năm trước
const yearOptions = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i);
const selectedMonth = ref('current');
const selectedCenter = ref('all');

// Room report filter
const roomCenter = ref('all');
const filterRoom = ref('all');
const filterDay = ref('all');
const onlyAvailable = ref(false); // Nút gạt "Chỉ còn chỗ"

// Data from API
const activeTrend = ref([]);
const newVsDrop = ref([]);
const attendanceRateTrend = ref([]);
const roomSlots = ref([]);
const centers = ref([]);
const rooms = ref([]);
const days = ref([]);

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await reportApi.getReports({
      period: selectedPeriod.value,
      year: selectedYear.value,
      month: selectedMonth.value,
      center: selectedCenter.value,
      room: filterRoom.value,
      room_center: roomCenter.value,
      day: filterDay.value,
      only_available: onlyAvailable.value,
    });

    activeTrend.value = res.data.active_students_trend || [];
    newVsDrop.value = res.data.new_vs_drop_students || [];
    attendanceRateTrend.value = res.data.attendance_rate_trend || [];
    roomSlots.value = res.data.room_slots || [];
    centers.value = res.data.centers || [];
    rooms.value = res.data.rooms || [];
    days.value = res.data.days || [];
  } catch (err) {
    console.error('Error fetching report data:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(fetchData);

// ---- Biểu đồ vẽ từ dữ liệu: trục X theo mốc (tháng / ngày), trục Y co giãn theo giá trị lớn nhất ----
const CHART_W = 1100;
const CHART_H = 190;
const niceTop = (max) => Math.max(1, Math.ceil(max / 4)) * 4;
const yTicks = (top) => [4, 3, 2, 1, 0].map((i) => (top * i) / 4);
const xPercent = (i, n) => (n <= 1 ? 50 : (i / (n - 1)) * 100);
// Mốc chưa tới (tương lai) trả về null: không vẽ điểm
const linePoints = (rows, key, top) =>
  rows
    .map((r, i) => ({ i, label: r.label ?? r.month, value: r[key], x: xPercent(i, rows.length) }))
    .filter((pt) => pt.value !== null && pt.value !== undefined)
    .map((pt) => ({ ...pt, y: 100 - (pt.value / top) * 100 }));
const linePath = (pts) => pts.map((pt, i) => `${i ? 'L' : 'M'} ${(pt.x / 100) * CHART_W},${(pt.y / 100) * CHART_H}`).join(' ');
// Nhiều mốc (theo ngày) thì chỉ hiện nhãn cách quãng cho dễ đọc
const showXLabel = (i, n) => n <= 12 || i % 2 === 0 || i === n - 1;

const activeTop = computed(() => niceTop(Math.max(0, ...activeTrend.value.map((r) => r.count || 0))));
const activePoints = computed(() => linePoints(activeTrend.value, 'count', activeTop.value));
const barTop = computed(() => niceTop(Math.max(0, ...newVsDrop.value.flatMap((r) => [r.new_students || 0, r.drop_students || 0]))));
const ratePoints = computed(() => linePoints(attendanceRateTrend.value, 'rate', 100));

// Client-side filtering for room slots (instant reactivity)
const displayedRoomSlots = computed(() => {
  return roomSlots.value.filter((slot) => {
    if (filterRoom.value !== 'all' && slot.room !== filterRoom.value) return false;
    if (filterDay.value !== 'all' && slot.day !== filterDay.value) return false;
    if (onlyAvailable.value && slot.available <= 0) return false;
    return true;
  });
});

const getBadgeStyle = (slot) => {
  if (slot.available <= 0) {
    return 'bg-rose-50 border border-rose-200 text-rose-700 font-bold';
  }
  if (slot.available <= 2) {
    return 'bg-amber-50 border border-amber-200 text-amber-700 font-bold';
  }
  return 'bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold';
};

const getBadgeText = (slot) => {
  if (slot.available <= 0) {
    return 'đã đầy';
  }
  return `còn ${slot.available}`;
};
</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- Header -->
    <div class="space-y-1">
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Báo cáo</h1>
      <p class="text-xs sm:text-sm text-slate-500">
        Thống kê học viên và điểm danh theo thời gian.
      </p>
    </div>

    <!-- 1. BỘ LỌC TỔNG QUAN -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Bộ lọc</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Chọn khoảng thời gian và trung tâm để xem báo cáo.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
        <!-- Khoảng thời gian -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Khoảng thời gian</label>
          <select
            v-model="selectedPeriod"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="year">Theo năm</option>
            <option value="quarter">Theo quý</option>
            <option value="month">Theo tháng</option>
          </select>
        </div>

        <!-- Năm -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Năm</label>
          <select
            v-model="selectedYear"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>

        <!-- Tháng -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Tháng</label>
          <select
            v-model="selectedMonth"
            :disabled="selectedPeriod === 'year'"
            @change="fetchData"
            class="disabled:bg-slate-50 disabled:text-slate-400 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="current">Tháng hiện tại</option>
            <option v-for="m in 12" :key="m" :value="m">Tháng {{ m }}</option>
          </select>
        </div>

        <!-- Trung tâm -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Trung tâm</label>
          <select
            v-model="selectedCenter"
            @change="fetchData"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
      </div>
    </div>

    <!-- 2. HỌC VIÊN ĐANG HỌC (Line Chart) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div class="flex items-start gap-2.5">
        <div class="p-1.5 rounded-lg bg-rose-50 text-rose-500 mt-0.5">
          <Users class="h-4 w-4" />
        </div>
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Học viên đang học</h2>
          <p class="text-xs text-slate-500 mt-0.5">
            Tổng số học viên Đang học + Tạm dừng theo thời gian.
          </p>
        </div>
      </div>

      <!-- Line Chart Container -->
      <div class="relative w-full overflow-x-auto pt-4 pb-2">
        <div class="min-w-[650px] relative">
          <div class="absolute left-0 top-0 h-[190px] w-8 flex flex-col justify-between text-[11px] text-slate-400 font-mono select-none -translate-y-1.5">
            <span v-for="t in yTicks(activeTop)" :key="t">{{ t }}</span>
          </div>
          <div class="ml-8 h-[190px] relative border-b border-l border-slate-200">
            <div class="absolute inset-0 flex flex-col justify-between pointer-events-none">
              <div v-for="n in 4" :key="n" class="border-b border-dashed border-slate-100 w-full h-0"></div>
              <div class="w-full h-0"></div>
            </div>
            <svg class="absolute inset-0 w-full h-full overflow-visible" preserveAspectRatio="none" :viewBox="`0 0 ${CHART_W} ${CHART_H}`">
              <path :d="linePath(activePoints)" fill="none" stroke="#2563eb" stroke-width="3" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
            </svg>
            <div
              v-for="pt in activePoints"
              :key="pt.i"
              class="group absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-blue-600"
              :style="{ left: `${pt.x}%`, top: `${pt.y}%` }"
            >
              <span class="pointer-events-none absolute bottom-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-0.5 text-[11px] text-white opacity-0 group-hover:opacity-100">
                {{ pt.label }}: {{ pt.value }} học viên
              </span>
            </div>
          </div>
          <div class="ml-8 mt-2 relative h-4 text-[11px] text-slate-500 font-medium select-none">
            <span
              v-for="(r, i) in activeTrend"
              v-show="showXLabel(i, activeTrend.length)"
              :key="i"
              class="absolute -translate-x-1/2"
              :style="{ left: `${xPercent(i, activeTrend.length)}%` }"
            >{{ r.label ?? r.month }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 3. HỌC VIÊN MỚI VÀ NGHỈ (Bar Chart) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div class="flex items-start gap-2.5">
        <div class="p-1.5 rounded-lg bg-rose-50 text-rose-500 mt-0.5">
          <TrendingUp class="h-4 w-4" />
        </div>
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Học viên mới và nghỉ</h2>
          <p class="text-xs text-slate-500 mt-0.5">
            Học viên mới theo ngày nhập học so với học viên đã nghỉ theo ngày chuyển trạng thái.
          </p>
        </div>
      </div>

      <!-- Bar Chart Container -->
      <div class="relative w-full overflow-x-auto pt-4 pb-2">
        <div class="min-w-[650px] relative">
          <div class="absolute left-0 top-0 h-[190px] w-8 flex flex-col justify-between text-[11px] text-slate-400 font-mono select-none -translate-y-1.5">
            <span v-for="t in yTicks(barTop)" :key="t">{{ t }}</span>
          </div>
          <div class="ml-8 h-[190px] relative border-b border-l border-slate-200 flex items-end justify-around px-1">
            <div class="absolute inset-0 flex flex-col justify-between pointer-events-none">
              <div v-for="n in 4" :key="n" class="border-b border-dashed border-slate-100 w-full h-0"></div>
              <div class="w-full h-0"></div>
            </div>
            <div v-for="item in newVsDrop" :key="item.month_num" class="group relative z-10 flex h-full flex-1 items-end justify-center gap-0.5">
              <div :style="{ height: `${(item.new_students / barTop) * 100}%` }" class="w-full max-w-4 bg-blue-600 rounded-t-xs hover:bg-blue-700"></div>
              <div :style="{ height: `${(item.drop_students / barTop) * 100}%` }" class="w-full max-w-4 bg-rose-500 rounded-t-xs hover:bg-rose-600"></div>
              <div
                class="absolute -top-8 left-1/2 -translate-x-1/2 bg-slate-900 text-white text-[11px] rounded-lg px-2 py-1 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap shadow-md z-30"
              >
                {{ item.label ?? item.month }} · Mới: {{ item.new_students }} &bull; Nghỉ: {{ item.drop_students }}
              </div>
            </div>
          </div>
          <div class="ml-8 mt-2 flex justify-around px-1 text-[11px] text-slate-500 font-medium select-none">
            <span v-for="(item, i) in newVsDrop" :key="item.month_num" class="flex-1 text-center">
              {{ showXLabel(i, newVsDrop.length) ? item.label ?? item.month : '' }}
            </span>
          </div>

          <!-- Legend -->
          <div class="flex items-center justify-center gap-6 mt-3 text-xs font-semibold text-slate-700">
            <div class="flex items-center gap-1.5">
              <span class="w-3 h-3 bg-blue-600 rounded-xs"></span>
              <span>Học viên mới</span>
            </div>
            <div class="flex items-center gap-1.5">
              <span class="w-3 h-3 bg-rose-500 rounded-xs"></span>
              <span>Học viên đã nghỉ</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 4. TỶ LỆ ĐI HỌC (Line / Curve Chart) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div class="flex items-start gap-2.5">
        <div class="p-1.5 rounded-lg bg-rose-50 text-rose-500 mt-0.5">
          <CalendarCheck2 class="h-4 w-4" />
        </div>
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Tỷ lệ đi học</h2>
          <p class="text-xs text-slate-500 mt-0.5">
            Tỷ lệ buổi đã điểm danh trên tổng số buổi (đã điểm danh + vắng).
          </p>
        </div>
      </div>

      <!-- Rate Chart Container -->
      <div class="relative w-full overflow-x-auto pt-4 pb-2">
        <div class="min-w-[650px] relative">
          <div class="absolute left-0 top-0 h-[190px] w-10 flex flex-col justify-between text-[11px] text-slate-400 font-mono select-none -translate-y-1.5">
            <span v-for="t in yTicks(100)" :key="t">{{ t }}%</span>
          </div>
          <div class="ml-10 h-[190px] relative border-b border-l border-slate-200">
            <div class="absolute inset-0 flex flex-col justify-between pointer-events-none">
              <div v-for="n in 4" :key="n" class="border-b border-dashed border-slate-100 w-full h-0"></div>
              <div class="w-full h-0"></div>
            </div>
            <svg class="absolute inset-0 w-full h-full overflow-visible" preserveAspectRatio="none" :viewBox="`0 0 ${CHART_W} ${CHART_H}`">
              <path :d="linePath(ratePoints)" fill="none" stroke="#2563eb" stroke-width="3" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
            </svg>
            <div
              v-for="pt in ratePoints"
              :key="pt.i"
              class="group absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-blue-600"
              :style="{ left: `${pt.x}%`, top: `${pt.y}%` }"
            >
              <span class="pointer-events-none absolute bottom-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-0.5 text-[11px] text-white opacity-0 group-hover:opacity-100">
                {{ pt.label }}: {{ pt.value }}% ({{ attendanceRateTrend[pt.i]?.attended || 0 }} có mặt / {{ attendanceRateTrend[pt.i]?.absent || 0 }} vắng)
              </span>
            </div>
          </div>
          <div class="ml-10 mt-2 relative h-4 text-[11px] text-slate-500 font-medium select-none">
            <span
              v-for="(r, i) in attendanceRateTrend"
              v-show="showXLabel(i, attendanceRateTrend.length)"
              :key="i"
              class="absolute -translate-x-1/2"
              :style="{ left: `${xPercent(i, attendanceRateTrend.length)}%` }"
            >{{ r.label ?? r.month }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 5. BÁO CÁO PHÒNG HỌC (Room Capacity & Slots) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div class="flex items-start gap-2.5">
        <div class="p-1.5 rounded-lg bg-rose-50 text-rose-500 mt-0.5">
          <Building2 class="h-4 w-4" />
        </div>
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Báo cáo phòng học</h2>
          <p class="text-xs text-slate-500 mt-0.5">
            Công suất từng phòng theo khung giờ. Lọc theo phòng và thứ để tìm ca còn chỗ.
          </p>
        </div>
      </div>

      <!-- Controls Row 1: Trung tâm -->
      <div class="max-w-md">
        <label class="block text-xs font-semibold text-slate-700 mb-1.5">Trung tâm (báo cáo phòng)</label>
        <select
          v-model="roomCenter"
          @change="fetchData"
          class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option value="all">Tất cả trung tâm</option>
          <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>

      <!-- Controls Row 2: Lọc phòng, Lọc ngày, và NÚT GẠT "CHỈ CÒN CHỖ" -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
        <div class="flex flex-wrap items-center gap-3">
          <!-- Chọn phòng -->
          <div class="w-40 sm:w-44">
            <select
              v-model="filterRoom"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="all">Tất cả phòng</option>
              <option v-for="r in rooms" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>

          <!-- Chọn ngày/thứ -->
          <div class="w-40 sm:w-44">
            <select
              v-model="filterDay"
              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="all">Tất cả các ngày</option>
              <option value="Thứ 2">Thứ 2</option>
              <option value="Thứ 3">Thứ 3</option>
              <option value="Thứ 4">Thứ 4</option>
              <option value="Thứ 5">Thứ 5</option>
              <option value="Thứ 6">Thứ 6</option>
              <option value="Thứ 7">Thứ 7</option>
              <option value="Chủ nhật">Chủ nhật</option>
            </select>
          </div>
        </div>

        <!-- NÚT GẠT "CHỈ CÒN CHỖ" (Toggle Switch) -->
        <div class="flex items-center gap-2.5">
          <button
            type="button"
            role="switch"
            :aria-checked="onlyAvailable"
            @click="onlyAvailable = !onlyAvailable"
            :class="[
              onlyAvailable ? 'bg-blue-600' : 'bg-slate-300',
              'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none'
            ]"
          >
            <span
              :class="[
                onlyAvailable ? 'translate-x-5' : 'translate-x-0',
                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-in-out'
              ]"
            />
          </button>
          <span
            @click="onlyAvailable = !onlyAvailable"
            class="text-xs sm:text-sm font-semibold text-slate-700 cursor-pointer select-none"
          >
            Chỉ còn chỗ
          </span>
        </div>
      </div>

      <!-- Ghi chú giải thích màu sắc & cách tính -->
      <div class="flex items-center gap-1.5 text-xs text-slate-500 pt-1">
        <Info class="h-3.5 w-3.5 text-slate-400 shrink-0" />
        <p>
          Số học viên tính từ học viên đang học/tạm dừng được gán vào phòng (lớp nhóm và 1:1). Màu xanh = còn nhiều chỗ, vàng = gần đầy, đỏ = đã đầy.
        </p>
      </div>

      <!-- Bảng báo cáo phòng học -->
      <div class="overflow-x-auto rounded-xl border border-slate-100">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="border-b border-slate-100 bg-slate-50/60 text-[11px] sm:text-xs font-semibold text-slate-500">
              <th class="py-3 px-4">Phòng</th>
              <th class="py-3 px-4">Khung giờ</th>
              <th class="py-3 px-4 text-center">Số HV</th>
              <th class="py-3 px-4 text-center">Sức chứa</th>
              <th class="py-3 px-4 text-right">Còn trống</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
            <tr
              v-for="slot in displayedRoomSlots"
              :key="slot.id"
              class="hover:bg-slate-50/60 transition-colors"
            >
              <!-- Phòng -->
              <td class="py-3.5 px-4 font-bold text-slate-900">
                {{ slot.room }}
              </td>

              <!-- Khung giờ -->
              <td class="py-3.5 px-4 text-slate-700 font-medium">
                {{ slot.schedule_label }}
              </td>

              <!-- Số HV -->
              <td class="py-3.5 px-4 text-center font-semibold text-slate-800">
                {{ slot.current_students }}
              </td>

              <!-- Sức chứa -->
              <td class="py-3.5 px-4 text-center text-slate-500">
                {{ slot.capacity }}
              </td>

              <!-- Còn trống -->
              <td class="py-3.5 px-4 text-right">
                <span
                  :class="[
                    getBadgeStyle(slot),
                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs'
                  ]"
                >
                  {{ getBadgeText(slot) }}
                </span>
              </td>
            </tr>

            <tr v-if="displayedRoomSlots.length === 0">
              <td colspan="5" class="py-8 text-center text-xs text-slate-400">
                Không tìm thấy phòng học hoặc ca học nào phù hợp với bộ lọc hiện tại.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
