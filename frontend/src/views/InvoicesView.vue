<script setup>
import { ref, computed, onMounted } from 'vue';
import { invoiceApi, studentApi } from '../services/api';
import Badge from '../components/Badge.vue';
import Modal from '../components/Modal.vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import DatePickerVN from '../components/DatePickerVN.vue';
import { Receipt, Plus, X } from 'lucide-vue-next';

const invoices = ref([]);
const monthlyTotal = ref(0);
const students = ref([]);
const courses = ref([]);
const currentMonth = ref(new Date().toISOString().slice(0, 7));

const showModal = ref(false);
const isSubmitting = ref(false);

const form = ref({
  student_id: '',
  course_id: '',
  amount: 3600000,
  sessions_added: 12,
  payment_method: 'bank_transfer',
  paid_at: new Date().toISOString().split('T')[0],
  note: '',
});

const fetchData = async () => {
  try {
    const res = await invoiceApi.getAll({ month: currentMonth.value });
    invoices.value = res.data.invoices;
    monthlyTotal.value = res.data.monthlyTotal;
    students.value = res.data.students;
    courses.value = res.data.courses;
    if (students.value.length > 0 && !form.value.student_id) {
      form.value.student_id = students.value[0].id;
    }
  } catch (err) {
    console.error('Error fetching invoices: ', err);
  }
};

const studentOptions = computed(() => {
  return students.value.map((s) => ({
    id: s.id,
    name: `${s.name} (${s.code || 'HV'})`,
    sublabel: `PH: ${s.parent_name || 'N/A'} - SĐT: ${s.parent_phone || '—'}`,
  }));
});

onMounted(fetchData);

const submit = async () => {
  try {
    isSubmitting.value = true;
    await invoiceApi.create(form.value);
    showModal.value = false;
    fetchData();
  } catch (err) {
    alert(err.response?.data?.message || 'Có lỗi xảy ra khi tạo phiếu thu');
  } finally {
    isSubmitting.value = false;
  }
};

const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const formatDate = (val) => {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleDateString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
};
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
          Quản Lý Thu Học Phí & Dòng Tiền
        </h1>
        <p class="text-xs text-slate-500 mt-1">
          Theo dõi toàn bộ phiếu thu học phí ban đầu, gia hạn tái ký và dòng tiền thực thu.
        </p>
      </div>

      <div class="flex items-center gap-2">
        <input
          type="month"
          v-model="currentMonth"
          @change="fetchData"
          class="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 shadow-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
        />

        <button
          @click="showModal = true"
          class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs shadow-indigo-600/20 hover:bg-indigo-700 active:scale-[0.98] transition-all"
        >
          <Plus class="h-4 w-4" />
          <span>Tạo Phiếu Thu Mới</span>
        </button>
      </div>
    </div>

    <!-- Revenue Summary Card -->
    <div class="rounded-2xl border border-slate-200 bg-gradient-to-r from-indigo-900 via-indigo-950 to-slate-900 p-6 text-white shadow-md flex items-center justify-between">
      <div>
        <span class="text-xs uppercase font-semibold text-indigo-200 tracking-wider">Tổng thực thu trong tháng {{ currentMonth }}</span>
        <h2 class="text-3xl font-black mt-1 text-white">{{ formatVND(monthlyTotal) }}</h2>
        <p class="text-sm text-indigo-300 mt-1">Tổng cộng {{ invoices.length }} phiếu thu đã hoàn tất.</p>
      </div>
      <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-white backdrop-blur-xs">
        <Receipt class="h-7 w-7" />
      </div>
    </div>

    <!-- Invoices Table -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-xs overflow-hidden">
      <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-500 border-b border-slate-100 text-xs font-bold uppercase tracking-wider">
          <tr>
            <th class="py-4 px-4 font-semibold">Mã phiếu thu</th>
            <th class="py-4 px-4 font-semibold">Học viên / Phụ huynh</th>
            <th class="py-4 px-4 font-semibold">Khóa học</th>
            <th class="py-4 px-4 font-semibold">Số tiền thu</th>
            <th class="py-4 px-4 font-semibold text-center">Cộng số buổi</th>
            <th class="py-4 px-4 font-semibold">Hình thức</th>
            <th class="py-4 px-4 font-semibold">Thời gian thanh toán</th>
            <th class="py-4 px-4 font-semibold">Ghi chú</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 text-slate-700">
          <tr v-for="inv in invoices" :key="inv.id" class="hover:bg-slate-50">
            <td class="py-4 px-4 font-mono font-bold text-slate-900">{{ inv.invoice_code }}</td>
            <td class="py-4 px-4">
              <span class="font-bold text-slate-900">{{ inv.student?.name }}</span>
              <span class="block text-slate-400 text-xs">PH: {{ inv.student?.parent_name }}</span>
            </td>
            <td class="py-4 px-4">{{ inv.course?.name || inv.student?.course?.name }}</td>
            <td class="py-4 px-4 font-extrabold text-emerald-600 text-base">{{ formatVND(inv.amount) }}</td>
            <td class="py-4 px-4 text-center font-bold text-indigo-600">+{{ inv.sessions_added }} buổi</td>
            <td class="py-4 px-4">
              <Badge variant="gray" size="sm">{{ inv.payment_method }}</Badge>
            </td>
            <td class="py-4 px-4 font-mono text-slate-500">{{ formatDate(inv.paid_at) }}</td>
            <td class="py-4 px-4 text-slate-600">{{ inv.note || '—' }}</td>
          </tr>

          <tr v-if="invoices.length === 0">
            <td colspan="8" class="py-12 text-center text-sm text-slate-400">
              Không có phiếu thu nào trong tháng này.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create Invoice Modal -->
    <Modal :show="showModal" max-width="lg" @close="showModal = false">
      <div class="p-6">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
          <h3 class="text-base font-bold text-slate-900">Tạo Phiếu Thu Học Phí</h3>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
        </div>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Chọn học viên *</label>
            <SearchableSelect
              v-model="form.student_id"
              :options="studentOptions"
              sublabel-key="sublabel"
              placeholder="Tìm kiếm và chọn học viên..."
              required
            />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700">Số tiền thu (VNĐ)</label>
              <input type="number" min="0" step="50000" v-model.number="form.amount" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-emerald-700" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-700">Số buổi cộng thêm</label>
              <input type="number" min="0" v-model.number="form.sessions_added" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700">Hình thức thanh toán</label>
              <select v-model="form.payment_method" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                <option value="bank_transfer">Chuyển khoản</option>
                <option value="cash">Tiền mặt</option>
                <option value="pos">Quẹt POS</option>
                <option value="momo">MoMo / QR</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Ngày thu tiền</label>
              <DatePickerVN
                v-model="form.paid_at"
                :default-today="true"
                required
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700">Ghi chú</label>
            <input type="text" v-model="form.note" placeholder="Thu học phí..." class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
          </div>

          <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" @click="showModal = false" class="rounded-lg border px-4 py-2 text-xs font-medium">Hủy</button>
            <button type="submit" :disabled="isSubmitting" class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50">Lưu Phiếu Thu</button>
          </div>
        </form>
      </div>
    </Modal>
  </div>
</template>
