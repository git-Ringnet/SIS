<script setup>
import { ref, computed, watch } from 'vue';
import { X } from 'lucide-vue-next';
import { studentApi } from '../services/api';
import { notify } from '../services/notify';
import Modal from './Modal.vue';

// Hồ sơ học viên dạng chỉ xem: thông tin, lịch học, tiến độ khoá học, học cụ đã giao (không có thao tác)
const props = defineProps({
  show: { type: Boolean, default: false },
  studentId: { type: [Number, String], default: null },
});

const emit = defineEmits(['close']);

const student = ref(null);
const loading = ref(false);

const DAY_LABELS = { 1: 'Thứ 2', 2: 'Thứ 3', 3: 'Thứ 4', 4: 'Thứ 5', 5: 'Thứ 6', 6: 'Thứ 7', 0: 'CN' };
const STATUS = {
  active: { label: 'Đang học', class: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  paused: { label: 'Tạm dừng', class: 'bg-amber-50 text-amber-700 border-amber-200' },
  dropped: { label: 'Đã nghỉ', class: 'bg-rose-50 text-rose-700 border-rose-200' },
};

const fetchStudent = async () => {
  try {
    loading.value = true;
    const res = await studentApi.get(props.studentId);
    student.value = res.data.student;
  } catch (err) {
    notify.apiError(err, 'Không thể tải hồ sơ học viên');
  } finally {
    loading.value = false;
  }
};

watch(
  () => [props.show, props.studentId],
  ([show, id]) => {
    if (show && id) fetchStudent();
    else student.value = null;
  },
  { immediate: true },
);

const formatDate = (val) => {
  if (!val) return '';
  const [y, m, d] = String(val).slice(0, 10).split('-');
  return d ? `${d}/${m}/${y}` : String(val);
};

// Thông tin cơ bản, theo thứ tự hai cột như mẫu
const fields = computed(() => {
  const s = student.value || {};
  return [
    ['Họ tên', s.name],
    ['Ngày sinh', formatDate(s.dob)],
    ['Tên phụ huynh', s.parent_name],
    ['SĐT phụ huynh', s.parent_phone],
    ['Email phụ huynh', s.parent_email],
    ['SĐT Zalo', s.parent_zalo && s.parent_zalo !== s.parent_phone ? s.parent_zalo : ''],
    ['Địa chỉ', s.address],
    ['Ngày nhập học', formatDate(s.join_date)],
    ['Cơ sở', s.center],
  ];
});

const status = computed(() => STATUS[student.value?.status] || STATUS.active);

// Lịch học: mỗi ca một nhãn (một ngày có thể nhiều ca), kèm giáo viên / phòng của ca
const scheduleChips = computed(() =>
  (student.value?.schedule_slots || []).map((slot) => ({
    key: slot.id,
    label: `${DAY_LABELS[slot.day]} · ${slot.start}${slot.end ? ` - ${slot.end}` : ''}`,
    extra: [slot.teacher, slot.room].filter(Boolean).join(' · '),
  })),
);

const sessions = computed(() => {
  const s = student.value;
  const total = s?.total_sessions || 0;
  const attended = Math.max(0, total - (s?.remaining_sessions || 0));
  return { total, attended, remaining: Math.max(0, total - attended), percent: total ? Math.min(100, (attended / total) * 100) : 0 };
});

const initials = computed(() =>
  (student.value?.name || '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(-2)
    .map((w) => w[0])
    .join('')
    .toUpperCase(),
);
</script>

<template>
  <Modal :show="show" max-width="2xl" @close="emit('close')">
    <div class="p-6 sm:p-7">
      <div v-if="loading || !student" class="py-16 text-center text-sm text-slate-400">Đang tải hồ sơ...</div>

      <template v-else>
        <!-- Tiêu đề -->
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-slate-900">{{ student.name }}</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">{{ student.code }}</p>
          </div>
          <button type="button" @click="emit('close')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Đóng">
            <X class="h-5 w-5" />
          </button>
        </div>

        <!-- Thông tin cơ bản -->
        <div class="mt-5 flex flex-col-reverse sm:flex-row gap-5">
          <dl class="grid flex-1 grid-cols-2 gap-x-6 gap-y-4">
            <div v-for="[label, value] in fields" :key="label">
              <dt class="text-xs text-slate-500">{{ label }}</dt>
              <dd class="mt-0.5 text-sm font-semibold text-slate-900 break-words">{{ value || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-slate-500">Trạng thái</dt>
              <dd class="mt-1">
                <span :class="['inline-block rounded-lg border px-2.5 py-0.5 text-xs font-semibold', status.class]">{{ status.label }}</span>
              </dd>
            </div>
          </dl>
          <div class="flex h-24 w-24 sm:h-32 sm:w-32 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-2xl sm:text-3xl font-bold text-slate-400">
            {{ initials }}
          </div>
        </div>

        <!-- Lịch học -->
        <div class="mt-5">
          <p class="text-xs text-slate-500">Lịch học</p>
          <div v-if="scheduleChips.length" class="mt-1.5 flex flex-wrap gap-2">
            <span v-for="chip in scheduleChips" :key="chip.key" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-800">
              {{ chip.label }}<span v-if="chip.extra" class="font-normal text-slate-500"> · {{ chip.extra }}</span>
            </span>
          </div>
          <p v-else class="mt-1 text-sm text-slate-400">Chưa xếp lịch học</p>
        </div>

        <!-- Khóa học -->
        <div class="mt-5">
          <p class="text-xs text-slate-500">
            Khóa học · Đã mua: {{ sessions.total }} · Đã điểm danh: {{ sessions.attended }} · Còn lại: {{ sessions.remaining }}
          </p>
          <div class="mt-1.5 rounded-xl border border-slate-200 p-3.5">
            <div class="flex items-center justify-between gap-3 text-sm">
              <span class="font-semibold text-slate-900">{{ student.course?.name || 'Chưa có khóa học' }}</span>
              <span class="text-slate-600">{{ sessions.attended }} đã điểm danh · {{ sessions.total }} đã mua</span>
            </div>
            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-rose-100">
              <div class="h-full rounded-full bg-sky-500" :style="{ width: `${sessions.percent}%` }"></div>
            </div>
          </div>
        </div>

        <!-- Học cụ đã giao -->
        <div class="mt-5">
          <p class="text-xs text-slate-500">Học cụ đã giao</p>
          <ul v-if="student.supplies?.length" class="mt-1.5 divide-y divide-slate-100 rounded-xl border border-slate-200">
            <li v-for="(sup, i) in student.supplies" :key="sup.id || i" class="flex items-center justify-between gap-3 px-3.5 py-2 text-sm">
              <span class="font-medium text-slate-800">{{ sup.name }} <span class="text-slate-400">× {{ sup.quantity || 1 }}</span></span>
              <span class="text-xs text-slate-500">{{ formatDate(sup.delivery_date) }}</span>
            </li>
          </ul>
          <p v-else class="mt-1 text-sm text-slate-400">Chưa có học cụ</p>
        </div>

        <!-- Ghi chú -->
        <div v-if="student.notes" class="mt-5">
          <p class="text-xs text-slate-500">Ghi chú</p>
          <p class="mt-1 text-sm text-slate-700 whitespace-pre-line">{{ student.notes }}</p>
        </div>
      </template>
    </div>
  </Modal>
</template>
