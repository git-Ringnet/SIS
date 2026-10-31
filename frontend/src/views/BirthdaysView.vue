<script setup>
import { ref, onMounted } from 'vue';
import { birthdayApi } from '../services/api';
import { notify } from '../services/notify';
import MessageTemplateModal from '../components/MessageTemplateModal.vue';
import {
  Gift,
  Check,
} from 'lucide-vue-next';

const loading = ref(true);
const selectedMonth = ref(10); // Tháng 10
const selectedCenter = ref('all');
const selectedYear = ref(new Date().getFullYear());
const students = ref([]);
const centers = ref([]);

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
  } catch (err) {
    console.error('Error fetching birthdays:', err);
  } finally {
    loading.value = false;
  }
};

const handleToggleGift = async (student) => {
  try {
    student.toggling = true;
    const res = await birthdayApi.toggleGift(student.id, { year: selectedYear.value });
    student.is_gifted = res.data.is_gifted;
    if (res.data.is_gifted) {
      notify.success(`Đã tặng quà sinh nhật cho học viên ${student.name}!`);
    } else {
      notify.info(`Đã hủy trạng thái tặng quà của học viên ${student.name}`);
    }
  } catch (err) {
    notify.apiError(err, 'Không thể cập nhật trạng thái tặng quà');
  } finally {
    student.toggling = false;
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

</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Sinh nhật</h1>
    </div>

    <!-- Card: SINH NHẬT THÁNG NÀY -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <!-- Title & Year -->
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Sinh nhật tháng này</h2>
          <p class="text-xs text-slate-500 mt-0.5">{{ selectedYear }}</p>
        </div>

        <!-- Filter Dropdowns -->
        <div class="flex flex-wrap items-center gap-3">
          <!-- Chọn Tháng -->
          <select v-model.number="selectedMonth" @change="fetchData"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none cursor-pointer">
            <option v-for="m in 12" :key="m" :value="m">Tháng {{ m }}</option>
          </select>

          <!-- Chọn Trung tâm -->
          <select v-model="selectedCenter" @change="fetchData"
            class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none cursor-pointer">
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
      </div>

      <!-- Empty State -->
      <div v-if="students.length === 0" class="py-10 text-xs sm:text-sm text-slate-500">
        Không có sinh nhật trong các tháng gần đây
      </div>

      <!-- Students Birthdays List -->
      <div v-else class="divide-y divide-slate-100 pt-2">
        <div v-for="s in students" :key="s.id"
          class="py-3.5 first:pt-1 last:pb-1 flex items-center justify-between gap-4 text-xs sm:text-sm">
          <!-- Left: Tên học viên & Phụ huynh (Khớp hình tham khảo) -->
          <div>
            <router-link :to="`/students/${s.id}`"
              class="font-bold text-slate-900 hover:text-blue-600 text-sm sm:text-base tracking-tight">
              {{ s.name }}
            </router-link>
            <p class="text-xs text-slate-400 mt-0.5">
              Phụ huynh: {{ s.parent_name || 'Phi' }}
            </p>
          </div>

          <!-- Right: Nút Tặng quà / Đã tặng quà + Badge Ngày sinh (Khớp hình tham khảo) -->
          <div class="flex items-center gap-2.5 shrink-0">
            <!-- Nút Tặng quà / Đã tặng quà -->
            <button type="button" @click="handleToggleGift(s)" :disabled="s.toggling"
              class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold transition-all active:scale-95 cursor-pointer"
              :class="s.is_gifted
                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100'
                : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 hover:border-slate-400 shadow-2xs'">
              <Check v-if="s.is_gifted" class="w-3.5 h-3.5 text-emerald-600 stroke-[2.5]" />
              <span>{{ s.is_gifted ? 'Đã tặng quà' : 'Tặng quà' }}</span>
            </button>

            <!-- Blue pill ngày sinh: VD 01-10 -->
            <span
              class="inline-flex items-center justify-center rounded-full bg-blue-600 text-white font-bold text-xs px-2.5 py-0.5 shadow-2xs min-w-12 text-center">
              {{ s.birthday_date }}
            </span>

            <!-- Nút Gửi lời chúc -->
            <button type="button" @click="openGreetingModal(s)" title="Gửi lời chúc mừng"
              class="inline-flex items-center justify-center rounded-full p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer ml-0.5">
              <Gift class="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL MẪU TIN NHẮN CHÚC MỪNG SINH NHẬT -->
    <MessageTemplateModal group="birthday" :show="showTemplateModal" :student="selectedStudent"
      @close="showTemplateModal = false" />
  </div>
</template>
