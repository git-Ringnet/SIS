<script setup>
import { ref, computed, onMounted } from 'vue';
import { ArrowLeft, CalendarX, Repeat, Trash2 } from 'lucide-vue-next';
import { teacherCheckinApi } from '../services/api';
import { notify, errorMessage } from '../services/notify';
import { confirmDialog } from '../services/useConfirm';
import { toLocalDateString } from '../services/date';
import DatePickerVN from '../components/DatePickerVN.vue';

const today = toLocalDateString();

// Bộ lọc danh sách
const filters = ref({ teacher: '', status: 'all', from: '', to: '' });
const teachers = ref([]);
const leaves = ref([]);
const substitutions = ref([]);

const leaveForm = ref({ teacher: '', from: today, to: today, reason: '' });
const subForm = ref({ original: '', substitute: '', from: today, to: today, reason: '' });
const savingLeave = ref(false);
const savingSub = ref(false);

const STATUS_LABELS = {
  upcoming: { label: 'Sắp tới', class: 'bg-blue-50 text-blue-700' },
  active: { label: 'Đang diễn ra', class: 'bg-amber-50 text-amber-700' },
  ended: { label: 'Đã kết thúc', class: 'bg-slate-100 text-slate-500' },
};

const substituteOptions = computed(() => teachers.value.filter((t) => t !== subForm.value.original));

const formatDate = (iso) => {
  if (!iso) return '';
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
};

const fetchData = async () => {
  try {
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v && v !== 'all'));
    const res = await teacherCheckinApi.getLeaves(params);
    leaves.value = res.data.leaves || [];
    substitutions.value = res.data.substitutions || [];
    teachers.value = res.data.teachers || [];
  } catch (err) {
    notify.apiError(err, 'Không tải được danh sách nghỉ phép / dạy thay');
  }
};

onMounted(fetchData);

const saveLeave = async () => {
  try {
    savingLeave.value = true;
    const res = await teacherCheckinApi.createLeave(leaveForm.value);
    notify.success(res.data.message);
    leaveForm.value = { teacher: '', from: today, to: today, reason: '' };
    await fetchData();
  } catch (err) {
    notify.error('Không lưu được nghỉ phép', { description: errorMessage(err, err.message) });
  } finally {
    savingLeave.value = false;
  }
};

const saveSubstitution = async () => {
  try {
    savingSub.value = true;
    const res = await teacherCheckinApi.createSubstitution(subForm.value);
    notify.success(res.data.message);
    subForm.value = { original: '', substitute: '', from: today, to: today, reason: '' };
    await fetchData();
  } catch (err) {
    notify.error('Không lưu được phân công dạy thay', { description: errorMessage(err, err.message) });
  } finally {
    savingSub.value = false;
  }
};

const removePeriod = async (type, item) => {
  const what = type === 'leaves' ? `nghỉ phép của ${item.teacher}` : `phân công ${item.substitute} dạy thay ${item.original}`;
  if (!(await confirmDialog({
    title: 'Xoá đợt này?',
    message: `Xoá ${what} (${formatDate(item.from)} → ${formatDate(item.to)}). Các ca chưa check-in trong khoảng ngày này sẽ trở lại bình thường.`,
    confirmText: 'Xoá',
  }))) return;

  try {
    const res = await teacherCheckinApi.deletePeriod(type, item.id);
    notify.success(res.data.message);
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không xoá được');
  }
};

const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none';
</script>

<template>
  <div class="space-y-6 pb-12">
    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Nghỉ phép & dạy thay</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">Gán vắng có phép theo khoảng ngày và phân công giáo viên dạy
          thay cho ca dạy.</p>
      </div>
      <router-link to="/teacher-checkin"
        class="inline-flex items-center gap-1.5 self-start rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-2xs">
        <ArrowLeft class="h-4 w-4" /> Về trang Check-in
      </router-link>
    </div>

    <!-- Bộ lọc -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-3">
      <h2 class="text-sm sm:text-base font-bold text-slate-900">Bộ lọc</h2>
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <label class="text-[11px] font-medium text-slate-500">
          Giáo viên
          <select v-model="filters.teacher" @change="fetchData" :class="[inputClass, 'mt-1']">
            <option value="">Tất cả giáo viên</option>
            <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
          </select>
        </label>
        <label class="text-[11px] font-medium text-slate-500">
          Trạng thái
          <select v-model="filters.status" @change="fetchData" :class="[inputClass, 'mt-1']">
            <option value="all">Tất cả</option>
            <option value="upcoming">Sắp tới</option>
            <option value="active">Đang diễn ra</option>
            <option value="ended">Đã kết thúc</option>
          </select>
        </label>
        <div class="text-[11px] font-medium text-slate-500">
          Từ ngày
          <DatePickerVN v-model="filters.from" :default-today="false" class="mt-1" @change="fetchData" />
        </div>
        <div class="text-[11px] font-medium text-slate-500">
          Đến ngày
          <DatePickerVN v-model="filters.to" :default-today="false" class="mt-1" @change="fetchData" />
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Nghỉ phép theo khoảng ngày -->
      <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
        <div>
          <h2 class="flex items-center gap-2 text-sm sm:text-base font-bold text-slate-900">
            <CalendarX class="h-4 w-4 text-slate-600" /> Vắng có phép theo khoảng ngày
          </h2>
          <p class="text-xs text-slate-500 mt-1">
            Trong khoảng ngày này, tất cả ca dạy của giáo viên được tính là Vắng có phép và giáo viên không thể
            check-in.
          </p>
        </div>

        <form @submit.prevent="saveLeave" class="space-y-3">
          <label class="block text-[11px] font-semibold text-slate-600">
            Giáo viên
            <select v-model="leaveForm.teacher" required :class="[inputClass, 'mt-1']">
              <option value="" disabled>Chọn giáo viên</option>
              <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
            </select>
          </label>
          <div class="grid grid-cols-2 gap-3">
            <div class="text-[11px] font-semibold text-slate-600">Từ ngày
              <DatePickerVN v-model="leaveForm.from" class="mt-1" required />
            </div>
            <div class="text-[11px] font-semibold text-slate-600">Đến ngày
              <DatePickerVN v-model="leaveForm.to" class="mt-1" required />
            </div>
          </div>
          <label class="block text-[11px] font-semibold text-slate-600">
            Lý do (tuỳ chọn)
            <input v-model="leaveForm.reason" placeholder="Nghỉ ốm, việc gia đình..." :class="[inputClass, 'mt-1']" />
          </label>
          <button type="submit" :disabled="savingLeave"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-4 py-2 text-xs sm:text-sm font-bold text-white">
            Lưu nghỉ phép
          </button>
        </form>

        <div class="space-y-2">
          <p class="text-xs font-semibold text-slate-600">Danh sách nghỉ phép ({{ leaves.length }})</p>
          <p v-if="leaves.length === 0"
            class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-xs text-slate-500">
            Không có đợt nghỉ phép nào khớp bộ lọc.
          </p>
          <div v-for="l in leaves" :key="l.id"
            class="flex items-start justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-xs">
            <div>
              <p class="font-bold text-slate-900">{{ l.teacher }}</p>
              <p class="text-slate-600">{{ formatDate(l.from) }} → {{ formatDate(l.to) }}<template v-if="l.reason"> · {{
                  l.reason }}</template>
              </p>
              <span
                :class="['mt-1 inline-block rounded-md px-1.5 py-0.5 text-[11px] font-semibold', STATUS_LABELS[l.status]?.class]">{{
                  STATUS_LABELS[l.status]?.label }}</span>
            </div>
            <button type="button" @click="removePeriod('leaves', l)"
              class="p-1 rounded-lg text-rose-500 hover:bg-rose-50" title="Xoá">
              <Trash2 class="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Phân công dạy thay theo khoảng ngày -->
      <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
        <div>
          <h2 class="flex items-center gap-2 text-sm sm:text-base font-bold text-slate-900">
            <Repeat class="h-4 w-4 text-slate-600" /> Phân công dạy thay
          </h2>
          <p class="text-xs text-slate-500 mt-1">
            Áp dụng cho toàn bộ ca dạy cố định của giáo viên gốc trong khoảng ngày: người dạy thay thấy ca, tự check-in
            và
            nhận thù lao theo đơn giá của mình. Đổi người cho đúng 1 ca thì dùng nút "Báo vắng / Dạy thay" trên ca đó ở
            trang
            Check-in.
          </p>
        </div>

        <form @submit.prevent="saveSubstitution" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <label class="text-[11px] font-semibold text-slate-600">
              Giáo viên gốc
              <select v-model="subForm.original" required :class="[inputClass, 'mt-1']">
                <option value="" disabled>Chọn giáo viên</option>
                <option v-for="t in teachers" :key="t" :value="t">{{ t }}</option>
              </select>
            </label>
            <label class="text-[11px] font-semibold text-slate-600">
              Giáo viên dạy thay
              <select v-model="subForm.substitute" required :class="[inputClass, 'mt-1']">
                <option value="" disabled>Chọn giáo viên</option>
                <option v-for="t in substituteOptions" :key="t" :value="t">{{ t }}</option>
              </select>
            </label>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div class="text-[11px] font-semibold text-slate-600">Từ ngày
              <DatePickerVN v-model="subForm.from" class="mt-1" required />
            </div>
            <div class="text-[11px] font-semibold text-slate-600">Đến ngày
              <DatePickerVN v-model="subForm.to" class="mt-1" required />
            </div>
          </div>
          <label class="block text-[11px] font-semibold text-slate-600">
            Lý do (tuỳ chọn)
            <input v-model="subForm.reason" placeholder="GV gốc nghỉ phép..." :class="[inputClass, 'mt-1']" />
          </label>
          <button type="submit" :disabled="savingSub"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-50 px-4 py-2 text-xs sm:text-sm font-bold text-white">
            Lưu phân công dạy thay
          </button>
        </form>

        <div class="space-y-2">
          <p class="text-xs font-semibold text-slate-600">Danh sách phân công ({{ substitutions.length }})</p>
          <p v-if="substitutions.length === 0"
            class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-xs text-slate-500">
            Không có phân công dạy thay nào khớp bộ lọc.
          </p>
          <div v-for="sub in substitutions" :key="sub.id"
            class="flex items-start justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-xs">
            <div>
              <p class="font-bold text-slate-900">{{ sub.substitute }} <span class="font-normal text-slate-500">dạy
                  thay</span> {{ sub.original }}</p>
              <p class="text-slate-600">{{ formatDate(sub.from) }} → {{ formatDate(sub.to) }}<template
                  v-if="sub.reason"> · {{ sub.reason }}</template></p>
              <span
                :class="['mt-1 inline-block rounded-md px-1.5 py-0.5 text-[11px] font-semibold', STATUS_LABELS[sub.status]?.class]">{{
                  STATUS_LABELS[sub.status]?.label }}</span>
            </div>
            <button type="button" @click="removePeriod('substitutions', sub)"
              class="p-1 rounded-lg text-rose-500 hover:bg-rose-50" title="Xoá">
              <Trash2 class="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
