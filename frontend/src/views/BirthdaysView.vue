<script setup>
import { ref, onMounted } from 'vue';
import { birthdayApi } from '../services/api';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import {
  Cake,
  Gift,
  MessageSquare,
  Calendar,
  Users,
  Sparkles,
} from 'lucide-vue-next';

const loading = ref(true);
const selectedMonth = ref(10); // Tháng 10
const selectedCenter = ref('all');
const selectedYear = ref(2026);
const students = ref([]);
const centers = ref([]);
const templates = ref([]);

// Modal
const showTemplateModal = ref(false);
const selectedStudent = ref(null);

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await birthdayApi.getBirthdays({
      month: selectedMonth.value,
      year: selectedYear.value,
      center: selectedCenter.value,
    });
    students.value = res.data.students || [];
    centers.value = res.data.centers || [];
    templates.value = res.data.templates || [];
  } catch (err) {
    console.error('Error fetching birthdays:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  const currentMonth = new Date().getMonth() + 1;
  selectedMonth.value = currentMonth || 10;
  fetchData();
});

const openGreetingModal = (student) => {
  selectedStudent.value = student;
  showTemplateModal.value = true;
};

const getZaloLink = (phone) => {
  if (!phone) return '#';
  return `https://zalo.me/${phone.replace(/[^0-9]/g, '')}`;
};
</script>

<template>
  <div class="space-y-6 pb-12 max-w-6xl">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Sinh nhật</h1>
    </div>

    <!-- Card: SINH NHẬT THÁNG NÀY -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-5">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <!-- Title & Year -->
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Sinh nhật tháng này</h2>
          <p class="text-xs text-slate-500 mt-0.5">{{ selectedYear }}</p>
        </div>

        <!-- Filter Dropdowns -->
        <div class="flex flex-wrap items-center gap-3">
          <!-- Chọn Tháng -->
          <select
            v-model.number="selectedMonth"
            @change="fetchData"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option v-for="m in 12" :key="m" :value="m">Tháng {{ m }}</option>
          </select>

          <!-- Chọn Trung tâm -->
          <select
            v-model="selectedCenter"
            @change="fetchData"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
          >
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
      </div>

      <!-- Empty State matching competitor screenshot -->
      <div
        v-if="students.length === 0"
        class="py-10 text-xs sm:text-sm text-slate-500"
      >
        Không có sinh nhật trong các tháng gần đây
      </div>

      <!-- Students Birthdays List -->
      <div v-else class="divide-y divide-slate-100 pt-1 space-y-3">
        <div
          v-for="s in students"
          :key="s.id"
          class="pt-3 first:pt-0 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs sm:text-sm"
        >
          <!-- Left: Birthday badge & Student info -->
          <div class="flex items-start gap-3">
            <div class="flex flex-col items-center justify-center rounded-xl bg-rose-50 border border-rose-200/80 px-2.5 py-1.5 text-rose-700 shrink-0 min-w-13">
              <Cake class="h-4 w-4 mb-0.5 text-rose-500" />
              <span class="font-bold text-xs">{{ s.birthday_date }}</span>
            </div>

            <div>
              <div class="flex items-center gap-2 flex-wrap">
                <router-link
                  :to="`/students/${s.id}`"
                  class="font-bold text-slate-900 hover:text-blue-600 uppercase text-xs sm:text-sm"
                >
                  {{ s.name }}
                </router-link>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                  {{ s.age }} tuổi
                </span>
                <span class="text-slate-400 font-mono text-xs">({{ s.code }})</span>
              </div>
              <p class="text-xs text-slate-500 mt-0.5">
                [{{ s.course?.name || 'Khóa học' }}] &bull; PH: {{ s.parent_name || 'N/A' }} ({{ s.parent_phone }})
              </p>
              <p class="text-[11px] text-slate-400 mt-0.5">
                Cơ sở: {{ s.center }}
              </p>
            </div>
          </div>

          <!-- Right: Action Buttons -->
          <div class="flex items-center gap-2 shrink-0">
            <a
              :href="getZaloLink(s.parent_phone)"
              target="_blank"
              class="inline-flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50/70 hover:bg-blue-100 px-3 py-1.5 text-xs font-semibold text-blue-700 transition-all"
            >
              <MessageSquare class="h-3.5 w-3.5" />
              <span>Zalo</span>
            </a>

            <button
              type="button"
              @click="openGreetingModal(s)"
              class="inline-flex items-center gap-1.5 rounded-lg bg-rose-500 hover:bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white shadow-2xs transition-all active:scale-[0.98]"
            >
              <Gift class="h-3.5 w-3.5" />
              <span>Gửi lời chúc</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL MẪU TIN NHẮN CHÚC MỪNG SINH NHẬT -->
    <MessageTemplateModal
      :show="showTemplateModal"
      :student="selectedStudent"
      :templates="templates"
      @close="showTemplateModal = false"
    />
  </div>
</template>
