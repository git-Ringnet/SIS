<script setup>
// Lập / sửa hóa đơn = ghi nhận phụ huynh đã thanh toán cho các khoản đang nợ của học viên:
// học phí từng khóa (giá khóa, có giảm giá) và học cụ đã giao. Không cộng buổi. Thu thiếu được, phần còn lại là công nợ.
import { ref, computed, watch } from 'vue';
import { invoiceApi } from '../services/api';
import { notify } from '../services/notify';
import { toLocalDateString } from '../services/date';
import Modal from './Modal.vue';
import SearchableSelect from './SearchableSelect.vue';
import DatePickerVN from './DatePickerVN.vue';
import MoneyInput from './MoneyInput.vue';
import { X, BookOpen, Package, Hourglass } from 'lucide-vue-next';

const props = defineProps({
  show: { type: Boolean, default: false },
  // Hóa đơn đang sửa (hóa đơn thanh toán có lines); null = lập mới
  invoice: { type: Object, default: null },
  students: { type: Array, default: () => [] },
  // Mở từ trang học viên: chọn sẵn học viên
  presetStudentId: { type: [Number, String], default: null },
});
const emit = defineEmits(['close', 'saved']);

const center = ref('all');
const studentId = ref('');
const payables = ref(null);
const loading = ref(false);
const saving = ref(false);
// Dòng đang chọn: key → { checked, amount, discount }
const picks = ref({});
// Khoản có trên hóa đơn đang sửa (vẫn hiện dù đã đóng đủ)
const invoiceKeys = ref(new Set());
const form = ref({ payment_method: 'bank_transfer', paid_at: toLocalDateString(), due_date: '', note: '' });

const money = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(Number(v) || 0)) + ' ₫';

const centers = computed(() => [...new Set(props.students.map((s) => s.center).filter(Boolean))]);
const studentOptions = computed(() => props.students
  .filter((s) => center.value === 'all' || s.center === center.value)
  .map((s) => ({
    id: s.id,
    name: `${s.name} (${s.code || 'HV'})${STATUS_NOTE[s.status] ? ` · ${STATUS_NOTE[s.status]}` : ''}`,
    sublabel: `PH: ${s.parent_name || 'N/A'} - SĐT: ${s.parent_phone || '—'}`,
  })));
// Học viên không còn đang học: ghi rõ trạng thái cạnh tên
const STATUS_NOTE = { paused: 'Tạm dừng', inactive: 'Đã nghỉ', dropped: 'Đã nghỉ' };

// Khóa / học cụ còn nợ (đang sửa: cả khoản có trên hóa đơn này). Khoản đã đóng đủ không hiện để chọn.
const courseRows = computed(() => (payables.value?.courses || []).filter((c) => c.debt > 0 || invoiceKeys.value.has(c.key)));
const supplyRows = computed(() => (payables.value?.supplies || []).filter((s) => s.debt > 0 || invoiceKeys.value.has(s.key)));
const paidUpCount = computed(() => (payables.value?.courses || []).filter((c) => c.debt <= 0 && !invoiceKeys.value.has(c.key)).length);

const pick = (row) => picks.value[row.key] || { checked: false, amount: 0, discount: 0 };
const chosen = computed(() => [...courseRows.value, ...supplyRows.value].filter((r) => picks.value[r.key]?.checked));
const totals = computed(() => {
  const debt = chosen.value.reduce((t, r) => t + r.debt, 0);
  const discount = chosen.value.reduce((t, r) => t + (r.type === 'course' ? Number(picks.value[r.key].discount) || 0 : 0), 0);
  const amount = chosen.value.reduce((t, r) => t + (Number(picks.value[r.key].amount) || 0), 0);
  return { debt, discount, amount, remaining: Math.max(0, debt - discount - amount) };
});
const rowError = (row) => {
  const p = picks.value[row.key];
  if (!p?.checked) return '';
  return (Number(p.amount) || 0) + (row.type === 'course' ? Number(p.discount) || 0 : 0) > row.debt + 0.5 ? `Vượt quá số còn nợ ${money(row.debt)}` : '';
};
const canSubmit = computed(() => chosen.value.length > 0 && totals.value.amount + totals.value.discount > 0
  && !chosen.value.some((r) => rowError(r)) && !chosen.value.some((r) => r.pending_invoice));

let loadSeq = 0;
const loadPayables = async () => {
  const seq = ++loadSeq;
  payables.value = null;
  if (!studentId.value) return;
  try {
    loading.value = true;
    const res = await invoiceApi.payables(studentId.value, props.invoice ? { invoice_id: props.invoice.id } : {});
    // Đổi học viên liên tục: bỏ kết quả của lần tải cũ về muộn
    if (seq !== loadSeq) return;
    payables.value = res.data;
    // Mỗi khoản một dòng chọn (mặc định thu đủ số còn nợ); đang sửa: chọn sẵn các khoản trên hóa đơn với số tiền đã thu
    const next = {};
    for (const row of [...res.data.courses, ...res.data.supplies]) {
      next[row.key] = { checked: false, amount: row.debt, discount: 0 };
    }
    const keys = new Set();
    for (const line of props.invoice?.lines || []) {
      const key = line.type === 'course' ? `course:${line.course_item_id}` : `supply:${line.supply_id}`;
      keys.add(key);
      next[key] = { checked: true, amount: Number(line.amount) || 0, discount: Number(line.discount) || 0 };
    }
    invoiceKeys.value = keys;
    picks.value = next;
  } catch (err) {
    notify.apiError(err, 'Không tải được công nợ của học viên');
  } finally {
    loading.value = false;
  }
};

watch(() => props.show, (open) => {
  if (!open) return;
  const inv = props.invoice;
  center.value = 'all';
  studentId.value = inv?.student_id || props.presetStudentId || '';
  form.value = {
    payment_method: inv?.payment_method || 'bank_transfer',
    paid_at: inv?.paid_at ? toLocalDateString(new Date(inv.paid_at)) : toLocalDateString(),
    due_date: inv?.due_date || '',
    note: inv?.note || '',
  };
  loadPayables();
});
// Chọn học viên khác (lập mới) thì tải công nợ của học viên đó
watch(studentId, () => { if (props.show && !props.invoice) loadPayables(); });

// Khóa có phiếu chờ thu kiểu cũ (nhập học "chờ thu"): xác nhận phiếu đó, không lập thêm hóa đơn bắt đóng lần nữa
const confirmPending = async (row) => {
  try {
    const res = await invoiceApi.markPaid(row.pending_invoice.id, { payment_method: form.value.payment_method, paid_at: form.value.paid_at });
    notify.success(res.data.message);
    emit('saved');
    await loadPayables();
  } catch (err) {
    notify.apiError(err, 'Không xác nhận được phiếu chờ thu');
  }
};

const submit = async () => {
  if (!canSubmit.value) return;
  const payload = {
    ...form.value,
    student_id: studentId.value,
    lines: chosen.value.map((r) => ({ key: r.key, amount: Number(picks.value[r.key].amount) || 0, discount: r.type === 'course' ? Number(picks.value[r.key].discount) || 0 : 0 })),
  };
  try {
    saving.value = true;
    const res = props.invoice ? await invoiceApi.update(props.invoice.id, payload) : await invoiceApi.create(payload);
    notify.success(res.data.message);
    emit('saved', res.data.invoice);
    emit('close');
  } catch (err) {
    notify.apiError(err, 'Không lưu được hóa đơn');
  } finally {
    saving.value = false;
  }
};
</script>

<template>
  <Modal :show="show" max-width="3xl" @close="emit('close')">
    <div class="p-6">
      <div class="flex items-center justify-between border-b border-slate-100 pb-4">
        <div>
          <h3 class="text-base font-bold text-slate-900">{{ invoice ? `Sửa hóa đơn ${invoice.invoice_code}` : 'Lập hóa đơn thanh toán' }}</h3>
          <p class="text-[11px] text-slate-500 mt-0.5">Ghi nhận số tiền phụ huynh đã thanh toán cho khóa học / học cụ đang nợ. Hóa đơn không cộng thêm buổi học.</p>
        </div>
        <button @click="emit('close')" class="text-slate-400 hover:text-slate-600"><X class="h-5 w-5" /></button>
      </div>

      <!-- Chưa chọn học viên: chừa chỗ cho danh sách xổ xuống (khung cửa sổ cắt phần tràn ra ngoài) -->
      <form @submit.prevent="submit" class="mt-4 space-y-4 text-xs" :class="!payables && !loading ? 'min-h-[380px]' : ''">
        <!-- Học viên -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div v-if="!invoice && centers.length > 1">
            <label class="block font-semibold text-slate-700 mb-1">Trung tâm</label>
            <select v-model="center" class="block w-full rounded-lg border border-slate-200 px-3 py-2">
              <option value="all">Tất cả trung tâm</option>
              <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div :class="!invoice && centers.length > 1 ? 'sm:col-span-2' : 'sm:col-span-3'">
            <label class="block font-semibold text-slate-700 mb-1">Học viên *</label>
            <div v-if="invoice" class="rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 font-bold text-slate-700">
              {{ invoice.student?.name || payables?.student?.name || '—' }}
            </div>
            <SearchableSelect v-else v-model="studentId" :options="studentOptions" sublabel-key="sublabel" placeholder="Tìm kiếm và chọn học viên..." />
          </div>
        </div>

        <div v-if="loading" class="py-6 text-center text-slate-400">Đang tải công nợ của học viên...</div>

        <template v-else-if="payables">
          <!-- Khóa học còn nợ -->
          <div>
            <div class="flex items-center gap-1.5 font-bold text-slate-800 mb-2"><BookOpen class="h-4 w-4 text-indigo-500" /> Học phí khóa học</div>
            <p v-if="!courseRows.length" class="rounded-lg border border-dashed border-slate-200 px-3 py-3 text-slate-400">
              Không có khóa học nào còn nợ học phí.
            </p>
            <div v-for="row in courseRows" :key="row.key" class="rounded-xl border px-3 py-2.5 mb-2"
              :class="pick(row).checked ? 'border-indigo-300 bg-indigo-50/40' : 'border-slate-200'">
              <div class="flex flex-wrap items-start gap-2">
                <input v-if="!row.pending_invoice" type="checkbox" v-model="pick(row).checked" class="mt-0.5 h-4 w-4 accent-indigo-600" />
                <div class="flex-1 min-w-[200px]">
                  <div class="font-bold text-slate-900">{{ row.name }}</div>
                  <div class="text-[11px] text-slate-500">
                    {{ row.total_sessions }} buổi<template v-if="row.start"> · {{ row.start }}</template><template v-if="row.end"> → {{ row.end }}{{ row.end_estimated ? ' (dự kiến)' : '' }}</template>
                  </div>
                  <div class="text-[11px] mt-0.5">
                    Học phí <strong>{{ money(row.fee) }}</strong>
                    <template v-if="row.discount"> · Đã giảm {{ money(row.discount) }}</template>
                    · Đã đóng <strong class="text-emerald-700">{{ money(row.paid) }}</strong>
                    · Còn nợ <strong class="text-rose-600">{{ money(row.debt) }}</strong>
                  </div>
                </div>
                <div v-if="pick(row).checked && !row.pending_invoice" class="flex gap-2">
                  <label class="w-32">
                    <span class="block text-[10px] text-slate-500">Giảm giá</span>
                    <MoneyInput v-model="pick(row).discount" class="w-full rounded-lg border border-slate-200 px-2 py-1.5" />
                  </label>
                  <label class="w-36">
                    <span class="block text-[10px] text-slate-500">Thu lần này</span>
                    <MoneyInput v-model="pick(row).amount" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 font-bold text-emerald-700" />
                  </label>
                </div>
              </div>
              <p v-if="rowError(row)" class="mt-1 text-[11px] font-semibold text-rose-600">{{ rowError(row) }}</p>
              <!-- Phiếu chờ thu kiểu cũ: xác nhận phiếu đó thay vì lập hóa đơn mới -->
              <div v-if="row.pending_invoice" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 border border-amber-200 px-2.5 py-1.5 text-amber-800">
                <Hourglass class="h-3.5 w-3.5" />
                <span>Khóa này đang có phiếu chờ thu <strong>{{ row.pending_invoice.invoice_code }}</strong> ({{ money(row.pending_invoice.amount) }}).</span>
                <button type="button" @click="confirmPending(row)" class="ml-auto rounded-lg bg-amber-500 px-2.5 py-1 font-bold text-white hover:bg-amber-600">Xác nhận đã thu phiếu này</button>
              </div>
            </div>
            <p v-if="paidUpCount" class="text-[11px] text-slate-400">{{ paidUpCount }} khóa đã đóng đủ học phí (không hiện để chọn).</p>
          </div>

          <!-- Học cụ chưa thanh toán -->
          <div v-if="supplyRows.length">
            <div class="flex items-center gap-1.5 font-bold text-slate-800 mb-2"><Package class="h-4 w-4 text-amber-500" /> Học cụ đã giao chưa thanh toán</div>
            <div v-for="row in supplyRows" :key="row.key" class="rounded-xl border px-3 py-2.5 mb-2 flex flex-wrap items-start gap-2"
              :class="pick(row).checked ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200'">
              <input type="checkbox" v-model="pick(row).checked" class="mt-0.5 h-4 w-4 accent-amber-600" />
              <div class="flex-1 min-w-[200px]">
                <div class="font-bold text-slate-900">{{ row.name }} <span class="font-normal text-slate-500">x{{ row.quantity }}</span></div>
                <div class="text-[11px]">
                  Thành tiền <strong>{{ money(row.total) }}</strong> · Đã đóng <strong class="text-emerald-700">{{ money(row.paid) }}</strong>
                  · Còn nợ <strong class="text-rose-600">{{ money(row.debt) }}</strong>
                  <template v-if="row.delivery_date"> · Giao {{ row.delivery_date.split('-').reverse().join('/') }}</template>
                </div>
                <p v-if="rowError(row)" class="mt-1 text-[11px] font-semibold text-rose-600">{{ rowError(row) }}</p>
              </div>
              <label v-if="pick(row).checked" class="w-36">
                <span class="block text-[10px] text-slate-500">Thu lần này</span>
                <MoneyInput v-model="pick(row).amount" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 font-bold text-emerald-700" />
              </label>
            </div>
          </div>

          <!-- Thanh toán -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-slate-100 pt-4">
            <div class="space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <label>
                  <span class="block font-semibold text-slate-700 mb-1">Hình thức thanh toán</span>
                  <select v-model="form.payment_method" class="block w-full rounded-lg border border-slate-200 px-3 py-2">
                    <option value="bank_transfer">Chuyển khoản</option>
                    <option value="cash">Tiền mặt</option>
                    <option value="pos">Quẹt POS</option>
                    <option value="momo">MoMo / QR</option>
                  </select>
                </label>
                <label>
                  <span class="block font-semibold text-slate-700 mb-1">Ngày thu tiền</span>
                  <DatePickerVN v-model="form.paid_at" :default-today="true" required />
                </label>
              </div>
              <label v-if="totals.remaining > 0" class="block">
                <span class="block font-semibold text-slate-700 mb-1">Hạn thanh toán phần còn lại</span>
                <DatePickerVN v-model="form.due_date" />
              </label>
              <label class="block">
                <span class="block font-semibold text-slate-700 mb-1">Ghi chú</span>
                <textarea v-model="form.note" rows="2" class="block w-full rounded-lg border border-slate-200 px-3 py-2" placeholder="VD: Đóng đợt 1 học phí khóa Piano"></textarea>
              </label>
              <div v-if="payables.bank_info" class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2">
                <div class="font-semibold text-slate-600 mb-0.5">Thông tin chuyển khoản (màn Trung tâm)</div>
                <div class="whitespace-pre-line text-slate-700">{{ payables.bank_info }}</div>
              </div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 space-y-1.5 self-start">
              <div class="flex justify-between"><span class="text-slate-500">Còn nợ các khoản đã chọn</span><strong>{{ money(totals.debt) }}</strong></div>
              <div class="flex justify-between"><span class="text-slate-500">Giảm giá</span><strong>{{ money(totals.discount) }}</strong></div>
              <div class="flex justify-between text-sm border-t border-slate-200 pt-1.5"><span class="font-bold">Thu lần này</span><strong class="text-emerald-700">{{ money(totals.amount) }}</strong></div>
              <div class="flex justify-between"><span class="text-slate-500">Còn lại sau hóa đơn</span><strong :class="totals.remaining > 0 ? 'text-rose-600' : 'text-emerald-700'">{{ money(totals.remaining) }}</strong></div>
            </div>
          </div>
        </template>

        <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
          <button type="button" @click="emit('close')" class="rounded-lg border px-4 py-2 font-medium">Hủy</button>
          <button type="submit" :disabled="saving || !canSubmit" class="rounded-lg bg-emerald-600 px-4 py-2 font-bold text-white hover:bg-emerald-700 disabled:opacity-50">
            {{ saving ? 'Đang lưu...' : invoice ? 'Lưu thay đổi' : 'Lập hóa đơn' }}
          </button>
        </div>
      </form>
    </div>
  </Modal>
</template>
