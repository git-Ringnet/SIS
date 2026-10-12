<script setup>
import { computed } from 'vue';
import { Printer, X, Download, FileText, Building2, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  student: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close']);

const formatVND = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const todayFormatted = computed(() => {
  const d = new Date();
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
});

const invoiceCode = computed(() => {
  if (!props.student) return 'HD-2026-001';
  return `HD-2026-${props.student.code || props.student.id}-${new Date().getMonth() + 1}`;
});

const tuitionAmount = computed(() => {
  return props.student?.course?.price || 3600000;
});

const handlePrint = () => {
  window.print();
};
</script>

<template>
  <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
      <!-- Modal Top Bar (Screen Only) -->
      <div class="print:hidden flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/80">
        <div class="flex items-center gap-2 text-indigo-700 font-bold text-sm">
          <FileText class="h-4.5 w-4.5" />
          <span>Phiếu Báo Học Phí / Hóa Đơn PDF</span>
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            @click="handlePrint"
            class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 px-3.5 py-1.5 text-xs font-bold text-white shadow-xs transition-all"
          >
            <Printer class="h-3.5 w-3.5" />
            <span>In / Lưu PDF</span>
          </button>
          <button
            type="button"
            @click="emit('close')"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
          >
            <X class="h-5 w-5" />
          </button>
        </div>
      </div>

      <!-- Printable Invoice Document -->
      <div id="invoice-print-area" class="p-8 sm:p-10 text-slate-800 space-y-6 bg-white">
        <!-- Header Brand -->
        <div class="flex items-start justify-between border-b-2 border-indigo-600 pb-5">
          <div>
            <h1 class="text-xl sm:text-2xl font-black text-indigo-900 tracking-tight uppercase">
              Khoa Anh Music & Art Center
            </h1>
            <p class="text-xs text-slate-500 mt-1">
              Trung tâm Đào tạo Âm nhạc & Nghệ thuật chuyên nghiệp
            </p>
            <p class="text-xs text-slate-500">
              Địa chỉ: Hóc Môn, TP. Hồ Chí Minh &bull; Hotline: 0912.345.678
            </p>
          </div>
          <div class="text-right">
            <span class="inline-block px-3 py-1 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-black uppercase tracking-wider">
              Phiếu báo học phí
            </span>
            <p class="text-xs text-slate-500 mt-1 font-mono">Mã: {{ invoiceCode }}</p>
            <p class="text-xs text-slate-400">Ngày lập: {{ todayFormatted }}</p>
          </div>
        </div>

        <!-- Student & Parent Information -->
        <div class="bg-slate-50/80 rounded-xl p-4 border border-slate-100 grid grid-cols-2 gap-4 text-xs sm:text-sm">
          <div>
            <span class="text-slate-400 block text-[11px] uppercase font-semibold">Thông tin học viên</span>
            <p class="font-bold text-slate-900 text-base mt-0.5">{{ student?.name || 'Học viên' }}</p>
            <p class="text-slate-600 mt-0.5">Mã học viên: <strong class="font-mono text-indigo-600">{{ student?.code || 'HV-01' }}</strong></p>
            <p class="text-slate-600">Số buổi còn lại: <strong class="text-rose-600">{{ student?.remaining_sessions }} buổi</strong></p>
          </div>
          <div>
            <span class="text-slate-400 block text-[11px] uppercase font-semibold">Người đại diện / Phụ huynh</span>
            <p class="font-bold text-slate-900 text-base mt-0.5">{{ student?.parent_name || 'Phụ huynh' }}</p>
            <p class="text-slate-600 mt-0.5">Số điện thoại: <strong>{{ student?.parent_phone || '—' }}</strong></p>
            <p class="text-slate-600">Zalo: {{ student?.parent_zalo || student?.parent_phone || '—' }}</p>
          </div>
        </div>

        <!-- Course & Tuition Details Table -->
        <div class="overflow-hidden rounded-xl border border-slate-200">
          <table class="w-full text-left text-xs sm:text-sm">
            <thead class="bg-indigo-50/60 text-slate-700 font-bold border-b border-slate-200">
              <tr>
                <th class="py-3 px-4">Nội dung</th>
                <th class="py-3 px-4 text-center">Số buổi</th>
                <th class="py-3 px-4 text-right">Đơn giá</th>
                <th class="py-3 px-4 text-right">Thành tiền</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr>
                <td class="py-3.5 px-4 font-medium">
                  {{ student?.course?.name || 'Khóa học Âm nhạc' }}
                  <span class="block text-xs text-slate-400 font-normal mt-0.5">
                    Học phí tái đăng ký khóa tiếp theo
                  </span>
                </td>
                <td class="py-3.5 px-4 text-center font-bold">
                  {{ student?.course?.default_sessions || 12 }} buổi
                </td>
                <td class="py-3.5 px-4 text-right font-medium text-slate-600">
                  {{ formatVND(tuitionAmount) }}
                </td>
                <td class="py-3.5 px-4 text-right font-black text-indigo-900 text-base">
                  {{ formatVND(tuitionAmount) }}
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-slate-50 font-bold border-t border-slate-200">
              <tr>
                <td colspan="3" class="py-3 px-4 text-right text-slate-600">Tổng thanh toán:</td>
                <td class="py-3 px-4 text-right text-lg font-black text-rose-600">
                  {{ formatVND(tuitionAmount) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Bank Transfer Details -->
        <div class="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50/40 to-white p-4 space-y-2">
          <h4 class="text-xs font-bold text-indigo-900 uppercase tracking-wider flex items-center gap-1.5">
            <Building2 class="h-4 w-4 text-indigo-600" />
            <span>Thông tin chuyển khoản học phí</span>
          </h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
            <p>Ngân hàng: <strong class="text-slate-900">MB Bank (Quân Đội)</strong></p>
            <p>Số tài khoản: <strong class="font-mono text-indigo-700 text-sm">15119928888</strong></p>
            <p>Chủ tài khoản: <strong class="text-slate-900">KHOA ANH MUSIC</strong></p>
            <p>Cú pháp: <strong class="font-mono text-indigo-700 bg-white px-2 py-0.5 rounded border border-indigo-200">HP {{ student?.name }} {{ student?.parent_phone }}</strong></p>
          </div>
        </div>

        <!-- Footer Notice & Signatures -->
        <div class="pt-4 flex items-center justify-between text-xs text-slate-500 border-t border-slate-100">
          <div class="italic">
            * Xin vui lòng hoàn tất học phí để trung tâm bảo lưu lịch học và xếp giờ học viên.
          </div>
          <div class="text-right">
            <p class="font-bold text-slate-700">Bộ phận Kế toán / Thu ngân</p>
            <p class="text-[11px] text-slate-400 mt-8">(Ký và ghi rõ họ tên)</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
@media print {
  body * {
    visibility: hidden;
  }
  #invoice-print-area, #invoice-print-area * {
    visibility: visible;
  }
  #invoice-print-area {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    margin: 0;
    padding: 20px;
  }
}
</style>
