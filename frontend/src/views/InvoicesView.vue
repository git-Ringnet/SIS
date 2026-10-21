<script setup>
import { toLocalDateString, toLocalMonthString } from '../services/date';
import { notify } from '../services/notify';
import { confirmDialog } from '../services/useConfirm';
import { ref, computed, onMounted } from 'vue';
import { invoiceApi, studentApi } from '../services/api';
import Badge from '../components/Badge.vue';
import Modal from '../components/Modal.vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import DatePickerVN from '../components/DatePickerVN.vue';
import Pagination from '../components/Pagination.vue';
import { Receipt, Plus, X, Edit3, Trash2, FileDown, Loader2, Ban, Undo2, Hourglass } from 'lucide-vue-next';
import MoneyInput from '../components/MoneyInput.vue';

const invoices = ref([]);
const monthlyTotal = ref(0);
const students = ref([]);
const courses = ref([]);
const currentMonth = ref(toLocalMonthString());
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);

const showModal = ref(false);
const isSubmitting = ref(false);
const editingId = ref(null);
const editingStudentName = ref('');
const downloadingId = ref(null);

// Không chọn sẵn học viên: bắt buộc chọn đúng người để tránh thu tiền nhầm học viên
const emptyForm = () => ({
  student_id: '',
  course_id: '',
  amount: 3600000,
  sessions_added: 12,
  payment_method: 'bank_transfer',
  paid_at: toLocalDateString(),
  note: '',
});

const form = ref(emptyForm());

// Phiếu chờ thu (phụ huynh chưa đóng), xác nhận khi đã nhận tiền
const pendingInvoices = ref([]);
const confirmForm = ref({ id: null, payment_method: 'bank_transfer', paid_at: toLocalDateString() });
const startConfirm = (inv) => {
  confirmForm.value = { id: inv.id, payment_method: inv.payment_method || 'bank_transfer', paid_at: toLocalDateString() };
};
const confirmPaid = async () => {
  try {
    const res = await invoiceApi.markPaid(confirmForm.value.id, confirmForm.value);
    notify.success(res.data.message);
    confirmForm.value.id = null;
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không xác nhận được phiếu thu');
  }
};

const paymentMethodLabels = {
  bank_transfer: 'Chuyển khoản',
  cash: 'Tiền mặt',
  pos: 'Quẹt POS',
  momo: 'MoMo / QR',
};

const fetchData = async () => {
  try {
    const res = await invoiceApi.getAll({ month: currentMonth.value, page: page.value, per_page: perPage.value });
    pagination.value = res.data.pagination || null;
    invoices.value = res.data.invoices;
    monthlyTotal.value = res.data.monthlyTotal;
    students.value = res.data.students;
    courses.value = res.data.courses;
    pendingInvoices.value = res.data.pending_invoices || [];
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

// Đổi tháng hoặc số dòng mỗi trang thì quay về trang 1
const applyFilters = () => {
  page.value = 1;
  fetchData();
};

const changePage = (p) => {
  page.value = p;
  fetchData();
};

const changePerPage = (n) => {
  perPage.value = n;
  applyFilters();
};

onMounted(fetchData);

const openCreate = () => {
  editingId.value = null;
  form.value = emptyForm();
  showModal.value = true;
};

const openEdit = (inv) => {
  editingId.value = inv.id;
  editingStudentName.value = inv.student?.name || '';
  form.value = {
    student_id: inv.student_id,
    course_id: inv.course_id || '',
    amount: Number(inv.amount),
    sessions_added: inv.sessions_added,
    payment_method: inv.payment_method,
    paid_at: inv.paid_at ? toLocalDateString(new Date(inv.paid_at)) : toLocalDateString(),
    note: inv.note || '',
  };
  showModal.value = true;
};

const submit = async () => {
  try {
    isSubmitting.value = true;
    if (editingId.value) {
      const res = await invoiceApi.update(editingId.value, form.value);
      notify.success(res.data.message || 'Đã cập nhật phiếu thu!');
      fetchData();
    } else {
      await invoiceApi.create(form.value);
      applyFilters();
    }
    showModal.value = false;
  } catch (err) {
    notify.apiError(err, editingId.value ? 'Có lỗi xảy ra khi cập nhật phiếu thu' : 'Có lỗi xảy ra khi tạo phiếu thu');
  } finally {
    isSubmitting.value = false;
  }
};

// Hủy phiếu thu / gia hạn nhầm: trừ lại số buổi đã cộng, không tính doanh thu; khôi phục được
const cancelInvoice = async (inv) => {
  const sessionsNote = inv.sessions_added > 0
    ? ` Học viên ${inv.student?.name || ''} sẽ bị trừ lại ${inv.sessions_added} buổi đã cộng (buổi đã học thì không trừ).`
    : '';
  if (!(await confirmDialog({
    title: 'Hủy phiếu thu?',
    message: `Phiếu "${inv.invoice_code}" (${formatVND(inv.amount)}) sẽ không còn tính vào doanh thu.${sessionsNote} Có thể khôi phục lại nếu hủy nhầm.`,
    confirmText: 'Hủy phiếu',
  }))) return;
  try {
    const res = await invoiceApi.cancel(inv.id, { reason: 'Thu / gia hạn nhầm' });
    notify.success(res.data.message);
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không hủy được phiếu thu');
  }
};

const restoreInvoice = async (inv) => {
  try {
    const res = await invoiceApi.restore(inv.id);
    notify.success(res.data.message);
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không khôi phục được phiếu thu');
  }
};

// Ghi "đã thu" nhầm khi phụ huynh chưa đóng: về chờ thu, giữ số buổi
const markUnpaid = async (inv) => {
  if (!(await confirmDialog({
    title: 'Chuyển về chờ thu?',
    message: `Phiếu "${inv.invoice_code}" sẽ chuyển về "Chưa thanh toán" (không tính doanh thu), số buổi của học viên giữ nguyên.`,
    confirmText: 'Chuyển về chờ thu',
    variant: 'warning',
  }))) return;
  try {
    const res = await invoiceApi.markUnpaid(inv.id);
    notify.success(res.data.message);
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không chuyển được phiếu thu');
  }
};

// Chỉ xóa hẳn phiếu đã hủy (số buổi đã trừ lúc hủy)
const deleteInvoice = async (inv) => {
  if (!(await confirmDialog({
    title: 'Xoá hẳn phiếu đã hủy?',
    message: `Phiếu thu "${inv.invoice_code}" sẽ bị xoá vĩnh viễn, không khôi phục được nữa.`,
    confirmText: 'Xoá hẳn',
  }))) return;

  try {
    const res = await invoiceApi.delete(inv.id);
    notify.success(res.data.message || 'Đã xoá phiếu thu!');
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không thể xoá phiếu thu');
  }
};

const downloadPdf = async (inv) => {
  downloadingId.value = inv.id;
  try {
    await invoiceApi.downloadPdf(inv);
  } catch (err) {
    notify.apiError(err, 'Không thể tải file PDF phiếu thu');
  } finally {
    downloadingId.value = null;
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
          @change="applyFilters"
          class="rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 shadow-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
        />

        <button
          @click="openCreate"
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
        <p class="text-sm text-indigo-300 mt-1">Tổng cộng {{ pagination?.total ?? invoices.length }} phiếu thu đã hoàn tất.</p>
      </div>
      <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-white backdrop-blur-xs">
        <Receipt class="h-7 w-7" />
      </div>
    </div>

    <!-- Phiếu chờ thu (phụ huynh chưa đóng): không tính vào thực thu cho tới khi xác nhận -->
    <div v-if="pendingInvoices.length > 0" class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4 space-y-3">
      <div>
        <h2 class="text-sm font-bold text-amber-900">Phiếu chờ thu ({{ pendingInvoices.length }})</h2>
        <p class="text-[11px] text-amber-800/80">Học phí phụ huynh chưa đóng. Cổng phụ huynh hiện "Chưa thanh toán" và chưa tính vào thực thu. Bấm "Xác nhận đã thu" khi nhận tiền (không cộng thêm buổi).</p>
      </div>
      <div class="divide-y divide-amber-100 rounded-xl border border-amber-200 bg-white">
        <div v-for="inv in pendingInvoices" :key="inv.id" class="flex flex-col lg:flex-row lg:items-center justify-between gap-2 px-3 py-2.5 text-xs">
          <div>
            <span class="font-bold text-slate-900">{{ inv.student?.name || '—' }}</span>
            <span class="ml-1.5 font-mono text-slate-400">{{ inv.invoice_code }}</span>
            <span class="block text-slate-500">
              {{ inv.course?.name || inv.student?.course?.name || 'Khóa học' }} · lập ngày {{ formatDate(inv.created_at) }}
              <template v-if="inv.sessions_added"> · {{ inv.sessions_added }} buổi đã cấp</template>
            </span>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-extrabold text-amber-700 text-sm">{{ formatVND(inv.amount) }}</span>
            <template v-if="confirmForm.id === inv.id">
              <select v-model="confirmForm.payment_method" class="rounded-lg border border-slate-200 px-2 py-1.5">
                <option v-for="(label, key) in paymentMethodLabels" :key="key" :value="key">{{ label }}</option>
              </select>
              <input v-model="confirmForm.paid_at" type="date" class="rounded-lg border border-slate-200 px-2 py-1" />
              <button type="button" @click="confirmPaid" class="rounded-lg bg-emerald-600 hover:bg-emerald-700 px-3 py-1.5 font-bold text-white">Lưu</button>
              <button type="button" @click="confirmForm.id = null" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-3 py-1.5 font-semibold text-slate-600">Hủy</button>
            </template>
            <button v-else type="button" @click="startConfirm(inv)"
              class="rounded-lg border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 px-3 py-1.5 font-bold text-emerald-700">
              Xác nhận đã thu
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Invoices Table -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-xs overflow-hidden">
      <!-- Màn hình nhỏ: bảng giữ độ rộng tối thiểu và cuộn ngang thay vì bóp cột -->
      <div class="overflow-x-auto">
      <table class="w-full min-w-[1100px] text-left text-sm">
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
            <th class="py-4 px-4 font-semibold text-right">Thao tác</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 text-slate-700">
          <tr v-for="inv in invoices" :key="inv.id" class="hover:bg-slate-50" :class="inv.status === 'cancelled' ? 'opacity-60' : ''">
            <td class="py-4 px-4 font-mono font-bold text-slate-900 whitespace-nowrap">
              {{ inv.invoice_code }}
              <span v-if="inv.status === 'cancelled'" class="block font-sans text-[11px] font-bold text-rose-600" :title="inv.cancelled_reason || ''">Đã hủy</span>
              <span v-else-if="inv.status === 'pending'" class="block font-sans text-[11px] font-bold text-amber-600">Chờ thu</span>
            </td>
            <td class="py-4 px-4">
              <span class="font-bold text-slate-900">{{ inv.student?.name }}</span>
              <span class="block text-slate-400 text-xs">PH: {{ inv.student?.parent_name }}</span>
            </td>
            <td class="py-4 px-4">{{ inv.course?.name || inv.student?.course?.name }}</td>
            <td class="py-4 px-4 font-extrabold text-base whitespace-nowrap" :class="inv.status === 'cancelled' ? 'text-slate-400 line-through' : 'text-emerald-600'">{{ formatVND(inv.amount) }}</td>
            <td class="py-4 px-4 text-center font-bold" :class="inv.status === 'cancelled' ? 'text-slate-400 line-through' : 'text-indigo-600'">+{{ inv.sessions_added }} buổi</td>
            <td class="py-4 px-4">
              <Badge variant="gray" size="sm">{{ paymentMethodLabels[inv.payment_method] || inv.payment_method }}</Badge>
            </td>
            <td class="py-4 px-4 font-mono text-slate-500 whitespace-nowrap">{{ formatDate(inv.paid_at) }}</td>
            <td class="py-4 px-4 text-slate-600">{{ inv.note || '—' }}</td>
            <td class="py-4 px-4">
              <div class="flex items-center justify-end gap-1">
                <button
                  type="button"
                  @click="downloadPdf(inv)"
                  :disabled="downloadingId === inv.id"
                  class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 disabled:opacity-50"
                  title="Tải phiếu thu PDF"
                >
                  <Loader2 v-if="downloadingId === inv.id" class="h-4 w-4 animate-spin" />
                  <FileDown v-else class="h-4 w-4" />
                </button>
                <template v-if="inv.status === 'cancelled'">
                  <button type="button" @click="restoreInvoice(inv)"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50" title="Khôi phục phiếu (hủy nhầm)">
                    <Undo2 class="h-4 w-4" />
                  </button>
                  <button type="button" @click="deleteInvoice(inv)"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Xoá hẳn phiếu đã hủy">
                    <Trash2 class="h-4 w-4" />
                  </button>
                </template>
                <template v-else>
                  <button
                    type="button"
                    @click="openEdit(inv)"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50"
                    title="Sửa phiếu thu"
                  >
                    <Edit3 class="h-4 w-4" />
                  </button>
                  <button v-if="inv.status === 'paid'" type="button" @click="markUnpaid(inv)"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50" title="Ghi nhầm đã thu: chuyển về chờ thu">
                    <Hourglass class="h-4 w-4" />
                  </button>
                  <button type="button" @click="cancelInvoice(inv)"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Hủy phiếu (thu / gia hạn nhầm), trừ lại số buổi">
                    <Ban class="h-4 w-4" />
                  </button>
                </template>
              </div>
            </td>
          </tr>

          <tr v-if="invoices.length === 0">
            <td colspan="9" class="py-12 text-center text-sm text-slate-400">
              Không có phiếu thu nào trong tháng này.
            </td>
          </tr>
        </tbody>
      </table>
      </div>
      <Pagination :meta="pagination" @change-page="changePage" @change-per-page="changePerPage" />
    </div>

    <!-- Create / Edit Invoice Modal -->
    <Modal :show="showModal" max-width="lg" @close="showModal = false">
      <div class="p-6">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
          <h3 class="text-base font-bold text-slate-900">{{ editingId ? 'Sửa Phiếu Thu Học Phí' : 'Tạo Phiếu Thu Học Phí' }}</h3>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
        </div>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Chọn học viên *</label>
            <div v-if="editingId" class="rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">
              {{ editingStudentName || '—' }}
            </div>
            <SearchableSelect
              v-else
              v-model="form.student_id"
              :options="studentOptions"
              sublabel-key="sublabel"
              placeholder="Tìm kiếm và chọn học viên..."
              required
            />
            <p v-if="editingId" class="mt-1 text-[11px] text-slate-400">
              Không đổi được học viên của phiếu đã lập. Đổi số buổi cộng thêm sẽ tự điều chỉnh số buổi của học viên.
            </p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700">Số tiền thu (VNĐ)</label>
              <MoneyInput v-model="form.amount" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-emerald-700" />
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
            <button type="submit" :disabled="isSubmitting" class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50">{{ editingId ? 'Lưu thay đổi' : 'Lưu Phiếu Thu' }}</button>
          </div>
        </form>
      </div>
    </Modal>
  </div>
</template>
