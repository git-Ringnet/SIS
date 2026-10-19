<script setup>
import { computed, onMounted, ref } from 'vue';
import { staffApi } from '../services/api';
import { useCenters } from '../services/useCenters';

// Lịch học cố định hằng tuần của học viên:
// { class_days: [1, 3], class_start_time: '17:30', class_end_time: '18:30', teacher: '', room: '' }
const schedule = defineModel({ type: Object, required: true });

const props = defineProps({
  // Trung tâm của học viên, dùng để gợi ý phòng học của đúng trung tâm
  center: {
    type: String,
    default: '',
  },
});

// Theo Carbon::dayOfWeek ở backend: 0 = Chủ nhật, 1 = Thứ 2 ...
const WEEKDAYS = [
  { value: 1, label: 'T2' },
  { value: 2, label: 'T3' },
  { value: 3, label: 'T4' },
  { value: 4, label: 'T5' },
  { value: 5, label: 'T6' },
  { value: 6, label: 'T7' },
  { value: 0, label: 'CN' },
];

const { roomNames, loadCenters } = useCenters();
const teachers = ref([]);
const uid = Math.random().toString(36).slice(2, 8);

onMounted(async () => {
  loadCenters();
  try {
    const res = await staffApi.getStaff();
    teachers.value = (res.data?.members || [])
      .filter((m) => (m.role || '').includes('Giáo viên'))
      .map((m) => m.name);
  } catch (err) {
    console.error('Lỗi tải danh sách giáo viên:', err);
  }
});

const rooms = computed(() => roomNames(props.center || 'all'));

const isSelected = (day) => (schedule.value.class_days || []).includes(day);

const toggleDay = (day) => {
  const days = schedule.value.class_days || [];
  schedule.value.class_days = isSelected(day) ? days.filter((d) => d !== day) : [...days, day];
};

const inputClass =
  'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500';
</script>

<template>
  <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4 space-y-3">
    <div>
      <p class="text-xs font-bold text-slate-800">Lịch học cố định hằng tuần</p>
      <p class="text-[11px] text-slate-500 mt-0.5">Học viên sẽ hiện ở màn Điểm danh vào các thứ đã chọn.</p>
    </div>

    <div class="flex flex-wrap gap-1.5">
      <button
        v-for="d in WEEKDAYS"
        :key="d.value"
        type="button"
        @click="toggleDay(d.value)"
        :class="[
          isSelected(d.value)
            ? 'bg-rose-600 border-rose-600 text-white'
            : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50',
          'h-9 min-w-11 rounded-lg border px-2.5 text-xs font-bold transition-colors',
        ]"
        :aria-pressed="isSelected(d.value)"
      >
        {{ d.label }}
      </button>
    </div>

    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Giờ bắt đầu</label>
        <input v-model="schedule.class_start_time" type="time" :class="inputClass" />
      </div>
      <div>
        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Giờ kết thúc</label>
        <input v-model="schedule.class_end_time" type="time" :min="schedule.class_start_time || undefined" :class="inputClass" />
      </div>
      <div>
        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Giáo viên</label>
        <input v-model="schedule.teacher" :list="`teachers-${uid}`" placeholder="Chọn hoặc nhập tên" :class="inputClass" />
        <datalist :id="`teachers-${uid}`">
          <option v-for="t in teachers" :key="t" :value="t" />
        </datalist>
      </div>
      <div>
        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Phòng học</label>
        <input v-model="schedule.room" :list="`rooms-${uid}`" placeholder="Chọn hoặc nhập phòng" :class="inputClass" />
        <datalist :id="`rooms-${uid}`">
          <option v-for="r in rooms" :key="r" :value="r" />
        </datalist>
      </div>
    </div>
  </div>
</template>
