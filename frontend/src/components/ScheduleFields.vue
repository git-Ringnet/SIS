<script setup>
import { computed, onMounted, ref } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import { staffApi } from '../services/api';
import { useCenters } from '../services/useCenters';

// Lịch học nhiều ca của học viên: model.schedules = [{ id, day, start, end, teacher, room }]
// Một ngày có thể nhiều ca, mỗi ca giờ / giáo viên / phòng riêng.
const model = defineModel({ type: Object, required: true });

const props = defineProps({
  // Trung tâm của học viên, dùng để gợi ý phòng học của đúng trung tâm
  center: { type: String, default: '' },
});

// Theo Carbon::dayOfWeek ở backend: 0 = Chủ nhật, 1 = Thứ 2 ...
const WEEKDAYS = [
  { value: 1, label: 'Thứ 2' },
  { value: 2, label: 'Thứ 3' },
  { value: 3, label: 'Thứ 4' },
  { value: 4, label: 'Thứ 5' },
  { value: 5, label: 'Thứ 6' },
  { value: 6, label: 'Thứ 7' },
  { value: 0, label: 'CN' },
];

const { roomNames, loadCenters } = useCenters();
const teachers = ref([]);
const uid = Math.random().toString(36).slice(2, 8);

onMounted(async () => {
  loadCenters();
  if (!Array.isArray(model.value.schedules)) model.value.schedules = [];
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
const slots = computed(() => model.value.schedules || []);

const addSlot = () => {
  const last = slots.value[slots.value.length - 1];
  // Dòng mới lấy sẵn giờ / giáo viên / phòng của dòng trước cho nhanh
  model.value.schedules = [
    ...slots.value,
    { id: '', day: '', start: last?.start || '', end: last?.end || '', teacher: last?.teacher || '', room: last?.room || '' },
  ];
};

// Chọn giờ bắt đầu thì giờ kết thúc tự đặt = bắt đầu + 1 tiếng (vẫn sửa tay được), tối đa 23:59
const onStartChange = (slot) => {
  if (!slot.start) return;
  const [h, m] = slot.start.split(':').map(Number);
  const end = Math.min(h * 60 + m + 60, 23 * 60 + 59);
  slot.end = `${String(Math.floor(end / 60)).padStart(2, '0')}:${String(end % 60).padStart(2, '0')}`;
};

const removeSlot = (i) => {
  model.value.schedules = slots.value.filter((_, idx) => idx !== i);
};

// Tên giáo viên phải trùng tên tài khoản Giáo viên ở màn Nhân sự thì giáo viên mới thấy ca dạy để tự check-in
const normalizeName = (v) => (v || '').trim().replace(/\s+/g, ' ').toLowerCase();
const teacherUnmatched = (name) => normalizeName(name) !== '' && !teachers.value.some((t) => normalizeName(t) === normalizeName(name));

const inputClass =
  'w-full rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500';
</script>

<template>
  <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4 space-y-3">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="text-xs font-bold text-slate-800">Lịch học</p>
        <p class="text-[11px] text-slate-500 mt-0.5">
          Mỗi dòng là một ca học. Một ngày có thể nhiều ca, mỗi ca giờ, giáo viên, phòng riêng. Học viên hiện ở màn Điểm danh theo từng ca.
        </p>
      </div>
      <button
        type="button"
        @click="addSlot"
        class="inline-flex shrink-0 items-center gap-1 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
      >
        <Plus class="h-3.5 w-3.5" /> Thêm ca
      </button>
    </div>

    <p v-if="slots.length === 0" class="text-xs text-slate-400">Chưa có ca học nào. Bấm "Thêm ca" để xếp lịch.</p>

    <datalist :id="`teachers-${uid}`">
      <option v-for="t in teachers" :key="t" :value="t" />
    </datalist>
    <datalist :id="`rooms-${uid}`">
      <option v-for="r in rooms" :key="r" :value="r" />
    </datalist>

    <div v-for="(slot, i) in slots" :key="i" class="space-y-1">
      <div class="grid grid-cols-2 sm:grid-cols-[6.5rem_7.5rem_7.5rem_1fr_1fr_auto] gap-2 items-center">
        <select v-model="slot.day" :class="inputClass" aria-label="Thứ">
          <option value="" disabled>Thứ</option>
          <option v-for="d in WEEKDAYS" :key="d.value" :value="d.value">{{ d.label }}</option>
        </select>
        <input v-model="slot.start" type="time" @change="onStartChange(slot)" :class="inputClass" aria-label="Giờ bắt đầu" title="Giờ bắt đầu" />
        <input v-model="slot.end" type="time" :min="slot.start || undefined" :class="inputClass" aria-label="Giờ kết thúc" title="Giờ kết thúc" />
        <input v-model="slot.teacher" :list="`teachers-${uid}`" placeholder="Giáo viên" :class="inputClass" aria-label="Giáo viên" />
        <input v-model="slot.room" :list="`rooms-${uid}`" placeholder="Phòng" :class="inputClass" aria-label="Phòng học" />
        <button type="button" @click="removeSlot(i)" class="justify-self-start p-1.5 rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Xoá ca">
          <Trash2 class="h-4 w-4" />
        </button>
      </div>
      <p v-if="slot.day === '' || !slot.start" class="text-[11px] text-amber-700">Ca này chưa có thứ hoặc giờ bắt đầu nên sẽ không được lưu.</p>
      <p v-else-if="teacherUnmatched(slot.teacher)" class="text-[11px] text-amber-700">
        "{{ slot.teacher }}" không trùng tài khoản giáo viên nào ở màn Nhân sự, giáo viên sẽ không tự thấy ca dạy để check-in.
      </p>
    </div>
  </div>
</template>
