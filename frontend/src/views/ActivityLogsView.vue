<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { settingsApi } from '../services/api';
import Pagination from '../components/Pagination.vue';
import { ShieldCheck, ArrowLeft } from 'lucide-vue-next';

const loading = ref(true);
const errorMessage = ref('');
const logs = ref([]);
const actors = ref([]);
const subjectTypes = ref({});
const actions = ref({});
const retentionDays = ref(7);
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);

const filters = ref({
  search: '',
  user_email: '',
  subject_type: '',
  action: '',
  from: '',
  to: '',
});

const actionStyles = {
  created: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  updated: 'bg-amber-50 text-amber-700 border-amber-200',
  deleted: 'bg-rose-50 text-rose-700 border-rose-200',
};

const fetchLogs = async () => {
  try {
    loading.value = true;
    errorMessage.value = '';
    const params = Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v));
    const res = await settingsApi.getActivityLogs({ ...params, page: page.value, per_page: perPage.value });
    logs.value = res.data?.logs || [];
    pagination.value = res.data?.pagination || null;
    actors.value = res.data?.actors || [];
    subjectTypes.value = res.data?.subject_types || {};
    actions.value = res.data?.actions || {};
    retentionDays.value = res.data?.retention_days ?? 7;
  } catch (err) {
    console.error('Error fetching activity logs:', err);
    errorMessage.value = err.response?.data?.message || 'Không tải được nhật ký hoạt động.';
  } finally {
    loading.value = false;
  }
};

onMounted(fetchLogs);

// Đổi bộ lọc thì quay về trang 1
const applyFilters = () => {
  page.value = 1;
  fetchLogs();
};

const changePage = (p) => {
  page.value = p;
  fetchLogs();
};

const changePerPage = (n) => {
  perPage.value = n;
  applyFilters();
};

// Ô tìm kiếm chờ người dùng gõ xong mới gọi API
let searchTimer = null;
watch(() => filters.value.search, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(applyFilters, 400);
});
watch(
  () => [filters.value.user_email, filters.value.subject_type, filters.value.action, filters.value.from, filters.value.to],
  applyFilters,
);

const hasFilters = computed(() => Object.values(filters.value).some(Boolean));

const resetFilters = () => {
  filters.value = { search: '', user_email: '', subject_type: '', action: '', from: '', to: '' };
};

const formatDateTime = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())} ${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
};

const formatValue = (value) => {
  if (value === null || value === undefined || value === '') return '—';
  return value.length > 60 ? `${value.slice(0, 60)}…` : value;
};
</script>

<template>
  <div class="space-y-5 pb-12 max-w-6xl">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="flex items-center gap-2 text-xl sm:text-2xl font-bold tracking-tight text-slate-900">
          <ShieldCheck class="h-6 w-6 text-rose-600" />
          Nhật ký hoạt động
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
          Theo dõi các thao tác thêm, sửa, xoá của chủ trung tâm và quản trị viên. Chỉ chủ trung tâm nhìn thấy trang này.
          <br />
          Nhật ký được lưu trong <strong class="text-slate-700">{{ retentionDays }} ngày</strong> gần nhất.
        </p>
      </div>
      <router-link
        to="/settings"
        class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3.5 py-2 text-xs sm:text-sm font-semibold text-slate-700 shadow-2xs transition-all"
      >
        <ArrowLeft class="h-4 w-4" />
        <span>Cài đặt</span>
      </router-link>
    </div>

    <!-- Bộ lọc -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs space-y-3">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <input
          v-model="filters.search"
          type="text"
          placeholder="Tìm theo tên đối tượng / email..."
          class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
        />
        <select
          v-model="filters.user_email"
          class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
        >
          <option value="">Tất cả người thực hiện</option>
          <option v-for="a in actors" :key="a.user_email" :value="a.user_email">
            {{ a.user_name }} ({{ a.user_email }})
          </option>
        </select>
        <select
          v-model="filters.subject_type"
          class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
        >
          <option value="">Tất cả đối tượng</option>
          <option v-for="(label, key) in subjectTypes" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <select
          v-model="filters.action"
          class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
        >
          <option value="">Tất cả hành động</option>
          <option v-for="(label, key) in actions" :key="key" :value="key">{{ label }}</option>
        </select>
        <label class="flex items-center gap-2 text-xs sm:text-sm text-slate-500">
          <span class="shrink-0">Từ ngày</span>
          <input
            v-model="filters.from"
            type="date"
            :max="filters.to || undefined"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          />
        </label>
        <label class="flex items-center gap-2 text-xs sm:text-sm text-slate-500">
          <span class="shrink-0">Đến ngày</span>
          <input
            v-model="filters.to"
            type="date"
            :min="filters.from || undefined"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          />
        </label>
      </div>
      <div v-if="hasFilters">
        <button type="button" @click="resetFilters" class="text-xs font-semibold text-rose-600 hover:text-rose-700">
          Xoá bộ lọc
        </button>
      </div>
    </div>

    <!-- Danh sách hoạt động -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs">
      <h2 class="text-sm sm:text-base font-bold text-slate-900">Hoạt động gần đây ({{ pagination?.total ?? logs.length }})</h2>
      <p class="text-xs text-slate-500 mt-0.5">
        Mặc định hiển thị hoạt động trong {{ retentionDays }} ngày gần nhất. Chọn khoảng ngày để xem các hoạt động cũ hơn.
      </p>

      <div v-if="loading" class="py-10 text-center text-xs sm:text-sm text-slate-400">Đang tải...</div>
      <div v-else-if="errorMessage" class="py-10 text-center text-xs sm:text-sm text-rose-600">{{ errorMessage }}</div>
      <div v-else-if="logs.length === 0" class="py-10 text-center text-xs sm:text-sm text-slate-400">
        Chưa có hoạt động nào trong khoảng thời gian này.
      </div>

      <div v-else class="mt-4 divide-y divide-slate-100">
        <div v-for="log in logs" :key="log.id" class="py-3.5 first:pt-0 space-y-1.5">
          <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
            <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs sm:text-sm">
              <span :class="[actionStyles[log.action] || 'bg-slate-50 text-slate-600 border-slate-200', 'rounded-md border px-2 py-0.5 text-[11px] font-bold']">
                {{ actions[log.action] || log.action }}
              </span>
              <span class="font-bold text-slate-900">{{ subjectTypes[log.subject_type] || log.subject_type }}</span>
              <span v-if="log.subject_label" class="text-slate-700">&middot; {{ log.subject_label }}</span>
              <span v-if="log.context" class="text-slate-500">&middot; {{ log.context }}</span>
            </div>
            <span class="text-[11px] sm:text-xs text-slate-400 font-mono">{{ formatDateTime(log.created_at) }}</span>
          </div>

          <p class="text-[11px] sm:text-xs text-slate-500">
            {{ log.user_name || 'Hệ thống' }}
            <template v-if="log.user_email"> &middot; {{ log.user_email }}</template>
            <template v-if="log.user_role"> &middot; {{ log.user_role }}</template>
          </p>

          <div v-if="log.changes?.length" class="flex flex-wrap gap-1.5">
            <span
              v-for="change in log.changes"
              :key="change.field"
              class="rounded-md bg-slate-50 border border-slate-100 px-2 py-0.5 text-[11px] text-slate-600"
            >
              {{ change.label }}:
              <template v-if="log.action === 'created'">{{ formatValue(change.new) }}</template>
              <template v-else-if="log.action === 'deleted'"><span class="line-through">{{ formatValue(change.old) }}</span></template>
              <template v-else>{{ formatValue(change.old) }} &rarr; <strong class="text-slate-800">{{ formatValue(change.new) }}</strong></template>
            </span>
          </div>
        </div>
      </div>
      <Pagination :meta="pagination" class="mt-2 -mx-5 -mb-5" @change-page="changePage" @change-per-page="changePerPage" />
    </div>
  </div>
</template>
