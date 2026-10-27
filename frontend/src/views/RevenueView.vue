<script setup>
import { useAuth } from '../services/useAuth';
import { useCenters } from '../services/useCenters';
import { toLocalDateString } from '../services/date';
import { notify, errorMessage } from '../services/notify';
import { ref, onMounted, computed } from 'vue';
import { invoiceApi, revenueApi } from '../services/api';
import Pagination from '../components/Pagination.vue';
import {
  Download,
  Plus,
  Info,
  Calendar,
  CreditCard,
  DollarSign,
  TrendingUp,
  TrendingDown,
  Percent,
  X,
  FileSpreadsheet,
  CheckCircle2,
  Building,
  Pencil,
  Trash2,
  Users,
  ChevronDown,
  AlertTriangle,
} from 'lucide-vue-next';
import { useRouter } from 'vue-router';
import MoneyInput from '../components/MoneyInput.vue';

// Tab hiện tại
const activeTab = ref('revenue'); // 'revenue' | 'expenses' | 'profit' | 'allocation'

// Bộ lọc
const filterPeriod = ref('monthly'); // 'monthly', 'quarterly', 'yearly', 'all'
const filterYear = ref(String(new Date().getFullYear()));
const filterMonth = ref(String(new Date().getMonth() + 1));
// Năm hiện tại và 4 năm trước
const yearOptions = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i);
const filterCenter = ref('all');
const filterType = ref('all'); // 'all', 'course', 'inventory'

// Dữ liệu API
const loading = ref(true);
const metrics = ref({
  revenue_month_total: 0,
  course_total: 0,
  inventory_total: 0,
  shown_transactions_count: 0,
  total_transactions_count: 0,
});
const chartData = ref([]);
const transactions = ref([]);
// Phân trang bảng giao dịch (các chỉ số tổng và biểu đồ vẫn tính trên toàn bộ dữ liệu)
const page = ref(1);
const perPage = ref(20);
const pagination = ref(null);
const expenses = ref([]);
// Chi phí chỉ lấy theo tháng (đã có đủ để tính tổng chi) nên chia trang ngay trên trình duyệt
const expensePage = ref(1);
const expensePerPage = ref(20);
const expensePagination = computed(() => {
  const total = expenses.value.length;
  const lastPage = Math.max(1, Math.ceil(total / expensePerPage.value));
  const current = Math.min(expensePage.value, lastPage);
  return {
    current_page: current,
    last_page: lastPage,
    per_page: expensePerPage.value,
    total,
    from: total ? (current - 1) * expensePerPage.value + 1 : null,
    to: total ? Math.min(current * expensePerPage.value, total) : null,
  };
});
const pagedExpenses = computed(() => {
  const { current_page: current, per_page: size } = expensePagination.value;
  return expenses.value.slice((current - 1) * size, current * size);
});
const changeExpensePerPage = (n) => {
  expensePerPage.value = n;
  expensePage.value = 1;
};
// Lương giáo viên / giáo vụ trong tháng: tự tính từ Check-in ca dạy (không nhập tay)
const payroll = ref({ total: 0, shifts: 0, teachers: [], manual_overlap: false });
const showPayrollDetail = ref(false);
const profit = ref({
  total_revenue: 0,
  total_expense: 0,
  gross_profit: 0,
  profit_margin: 0,
});
const allocation = ref({
  accrued_revenue: 0,
  deferred_revenue: 0,
});
const centers = ref([]);
const students = ref([]);
const courses = ref([]);

// Tooltip biểu đồ ngày tương tác
const hoveredDay = ref(new Date().getDate()); // Mặc định hiển thị ngày hôm nay

// Người ghi nhận mặc định là tài khoản đang đăng nhập
const { user } = useAuth();
const currentUserName = user.value?.name || 'Admin';

// Modal Thêm / Sửa Doanh Thu
const showRevenueModal = ref(false);
const editingRevenueId = ref(null);
const emptyRevForm = () => ({
  recorded_by: currentUserName,
  date: toLocalDateString(),
  student_name: '',
  course_name: '',
  inventory_name: '',
  payment_method: 'Chuyển khoản',
  account_name: '',
  tuition_fee: 0,
  inventory_fee: 0,
  center: '',
  note: '',
  student_id: '',
  course_id: '',
  invoice_pdf: null,
});
const revForm = ref(emptyRevForm());

// Modal Thêm / Sửa Chi Phí
const showExpenseModal = ref(false);
const editingExpenseId = ref(null);
const emptyExpForm = () => ({
  date: toLocalDateString(),
  category: 'Thuê mặt bằng',
  spender: currentUserName,
  content: '',
  account: '',
  amount: 0,
  center: centers.value?.[0] || '',
});
const expForm = ref(emptyExpForm());

const router = useRouter();

const openCreateRevenue = () => {
  editingRevenueId.value = null;
  revForm.value = { ...emptyRevForm(), center: centers.value?.[0] || '' };
  showRevenueModal.value = true;
};

// Dòng sinh từ hoá đơn thì sửa ở màn Hóa đơn (để số buổi học viên cập nhật theo)
const isInvoiceRow = (tx) => String(tx.id).startsWith('inv_');

const openEditRevenue = (tx) => {
  if (isInvoiceRow(tx)) {
    router.push({ path: '/invoices', query: { search: tx.invoice_code || '' } });
    return;
  }
  editingRevenueId.value = tx.id;
  revForm.value = {
    ...emptyRevForm(),
    recorded_by: tx.recorded_by,
    date: tx.date,
    student_name: tx.student_name,
    course_name: tx.course_name === '-' ? '' : tx.course_name,
    inventory_name: tx.inventory_name === '-' ? '' : tx.inventory_name,
    payment_method: tx.payment_method,
    account_name: tx.account_name,
    tuition_fee: tx.tuition_fee,
    inventory_fee: tx.inventory_fee,
    center: tx.center,
    note: tx.note,
  };
  showRevenueModal.value = true;
};

const deleteRevenue = async (tx) => {
  if (isInvoiceRow(tx)) {
    router.push({ path: '/invoices', query: { search: tx.invoice_code || '' } });
    return;
  }
  if (!confirm(`Xóa khoản thu ${formatCurrency(tx.total_amount)} của ${tx.student_name}?`)) return;
  try {
    await revenueApi.deleteRevenue(tx.id);
    notify.success('Đã xóa khoản doanh thu');
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không xóa được khoản doanh thu');
  }
};

const openCreateExpense = () => {
  editingExpenseId.value = null;
  expForm.value = emptyExpForm();
  showExpenseModal.value = true;
};

const openEditExpense = (ex) => {
  editingExpenseId.value = ex.id;
  expForm.value = { ...emptyExpForm(), ...ex };
  showExpenseModal.value = true;
};

const deleteExpense = async (ex) => {
  if (!confirm(`Xóa khoản chi "${ex.content}" (${formatCurrency(ex.amount)})?`)) return;
  try {
    await revenueApi.deleteExpense(ex.id);
    notify.success('Đã xóa khoản chi');
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Không xóa được khoản chi');
  }
};

const formatCurrency = (val) => {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
};

const formatNumber = (val) => {
  return new Intl.NumberFormat('vi-VN').format(val || 0);
};

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await revenueApi.getRevenue({
      period: filterPeriod.value,
      year: filterYear.value,
      month: filterMonth.value,
      center: filterCenter.value,
      type: filterType.value,
      page: page.value,
      per_page: perPage.value,
    });
    pagination.value = res.data.pagination || null;
    metrics.value = res.data.metrics || {};
    chartData.value = res.data.chart || [];
    transactions.value = res.data.transactions || [];
    expenses.value = res.data.expenses || [];
    payroll.value = res.data.payroll || { total: 0, shifts: 0, teachers: [], manual_overlap: false };
    expensePage.value = 1;
    profit.value = res.data.profit || {};
    allocation.value = res.data.allocation || {};
    centers.value = res.data.centers || [];
    students.value = res.data.students || [];
    courses.value = res.data.courses || [];
    // Form thêm thu / chi mặc định chọn trung tâm chính
    revForm.value.center ||= defaultCenter.value || centers.value[0] || '';
    expForm.value.center ||= defaultCenter.value || centers.value[0] || '';
  } catch (err) {
    console.error('Lỗi tải dữ liệu doanh thu:', err);
  } finally {
    loading.value = false;
  }
};

// Đổi bộ lọc thì quay về trang 1
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

const { defaultCenter, loadCenters } = useCenters();

onMounted(async () => {
  await loadCenters();
  fetchData();
});

// Giá trị tính toán cho biểu đồ cột
const maxChartValue = computed(() => {
  if (!chartData.value.length) return 4;
  const max = Math.max(...chartData.value.map(d => d.total));
  return max > 0 ? max : 4;
});

const getHoveredData = computed(() => {
  if (!chartData.value.length) return { day: 25, course: 0, inventory: 0, total: 0 };
  const found = chartData.value.find(d => d.day === hoveredDay.value);
  return found || { day: hoveredDay.value, course: 0, inventory: 0, total: 0 };
});

// Xuất CSV
const downloadCsv = async () => {
  try {
    await revenueApi.exportCsv({
      year: filterYear.value,
      month: filterMonth.value,
      center: filterCenter.value,
    });
  } catch (err) {
    notify.apiError(err, 'Không thể xuất file doanh thu');
  }
};

// Lưu doanh thu mới
const submitRevenue = async () => {
  if (revForm.value.tuition_fee <= 0 && revForm.value.inventory_fee <= 0) {
    notify.warning('Vui lòng nhập Học phí hoặc Phí học cụ lớn hơn 0!');
    return;
  }
  if (editingRevenueId.value) {
    try {
      await revenueApi.updateRevenue(editingRevenueId.value, revForm.value);
      notify.success('Đã cập nhật khoản doanh thu');
      showRevenueModal.value = false;
      await fetchData();
    } catch (err) {
      notify.apiError(err, 'Không cập nhật được khoản doanh thu');
    }
    return;
  }
  try {
    const selectedStudent = students.value.find((student) => student.name === revForm.value.student_name);
    const selectedCourse = courses.value.find((course) => course.name === revForm.value.course_name);

    if (selectedStudent && revForm.value.tuition_fee > 0) {
      const payment = new FormData();
      payment.append('student_id', selectedStudent.id);
      payment.append('course_id', selectedCourse?.id || selectedStudent.course_id || '');
      payment.append('amount', String(revForm.value.tuition_fee));
      payment.append('sessions_added', String(selectedCourse?.default_sessions || 0));
      payment.append('payment_method', revForm.value.payment_method);
      payment.append('paid_at', revForm.value.date);
      payment.append('note', revForm.value.note || '');
      if (revForm.value.invoice_pdf) {
        payment.append('invoice_pdf', revForm.value.invoice_pdf);
      }
      await invoiceApi.create(payment);
    }

    if (!selectedStudent || revForm.value.inventory_fee > 0 || revForm.value.tuition_fee > 0 && !selectedStudent) {
      await revenueApi.createRevenue({
        ...revForm.value,
        student_name: selectedStudent?.name || revForm.value.student_name,
        course_name: selectedCourse?.name || revForm.value.course_name,
        tuition_fee: selectedStudent ? 0 : revForm.value.tuition_fee,
      });
    }
    showRevenueModal.value = false;
    await fetchData();
  } catch (err) {
    notify.error('Lỗi thêm doanh thu', { description: errorMessage(err, err.message) });
  }
};

// Lưu chi phí mới
const submitExpense = async () => {
  if (expForm.value.amount <= 0) {
    notify.warning('Vui lòng nhập số tiền chi lớn hơn 0!');
    return;
  }
  try {
    if (editingExpenseId.value) {
      await revenueApi.updateExpense(editingExpenseId.value, expForm.value);
    } else {
      await revenueApi.createExpense(expForm.value);
    }
    showExpenseModal.value = false;
    await fetchData();
  } catch (err) {
    notify.error('Lỗi thêm chi phí', { description: errorMessage(err, err.message) });
  }
};
</script>

<template>
  <div class="space-y-5 pb-12">
    <!-- Top Navigation Tabs (Khớp chính xác hình tham khảo) -->
    <div class="flex items-center gap-1.5 border-b border-slate-200/80 pb-2">
      <button type="button" @click="activeTab = 'revenue'" :class="[
        'rounded-lg px-3.5 py-1.5 text-xs sm:text-sm font-semibold transition-all cursor-pointer',
        activeTab === 'revenue'
          ? 'bg-rose-600 text-white shadow-2xs'
          : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
      ]">
        Doanh thu
      </button>

      <button type="button" @click="activeTab = 'expenses'" :class="[
        'rounded-lg px-3.5 py-1.5 text-xs sm:text-sm font-semibold transition-all cursor-pointer',
        activeTab === 'expenses'
          ? 'bg-rose-600 text-white shadow-2xs'
          : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
      ]">
        Chi phí
      </button>

      <button type="button" @click="activeTab = 'profit'" :class="[
        'rounded-lg px-3.5 py-1.5 text-xs sm:text-sm font-semibold transition-all cursor-pointer',
        activeTab === 'profit'
          ? 'bg-rose-600 text-white shadow-2xs'
          : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
      ]">
        Lợi nhuận
      </button>

      <button type="button" @click="activeTab = 'allocation'" :class="[
        'rounded-lg px-3.5 py-1.5 text-xs sm:text-sm font-semibold transition-all cursor-pointer',
        activeTab === 'allocation'
          ? 'bg-rose-600 text-white shadow-2xs'
          : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
      ]">
        Doanh thu phân bổ
      </button>
    </div>

    <!-- Filter Card (Khớp chính xác hình tham khảo) -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs">
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <!-- Kỳ -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1">Kỳ</label>
          <select v-model="filterPeriod" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="monthly">Theo tháng</option>
            <option value="quarterly">Theo quý</option>
            <option value="yearly">Theo năm</option>
            <option value="all">Tất cả kỳ</option>
          </select>
        </div>

        <!-- Năm -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1">Năm</label>
          <select v-model="filterYear" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả năm</option>
            <option v-for="y in yearOptions" :key="y" :value="String(y)">{{ y }}</option>
          </select>
        </div>

        <!-- Tháng -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1">Tháng</label>
          <select v-model="filterMonth" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả tháng</option>
            <option v-for="m in 12" :key="m" :value="String(m)">Tháng {{ m }}</option>
          </select>
        </div>

        <!-- Trung tâm -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1">Trung tâm</label>
          <select v-model="filterCenter" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Tất cả trung tâm</option>
            <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <!-- Loại -->
        <div>
          <label class="block text-[11px] text-slate-500 mb-1">Loại</label>
          <select v-model="filterType" @change="applyFilters"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none">
            <option value="all">Cả hai</option>
            <option value="course">Khóa học</option>
            <option value="inventory">Học cụ</option>
          </select>
        </div>
      </div>
    </div>

    <!-- TAB 1: DOANH THU -->
    <div v-if="activeTab === 'revenue'" class="space-y-5">
      <!-- 3 Metrics Cards (Khớp chính xác hình tham khảo) -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Card 1: DOANH THU THÁNG NÀY -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-1">
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
            DOANH THU THÁNG NÀY
          </div>
          <div class="text-2xl sm:text-3xl font-extrabold text-slate-900">
            {{ formatCurrency(metrics.revenue_month_total) }}
          </div>
          <div class="text-xs text-slate-500 pt-1">
            Khóa học: {{ formatCurrency(metrics.course_total) }} &bull; Học cụ: {{
              formatCurrency(metrics.inventory_total) }}
          </div>
        </div>

        <!-- Card 2: THANH TOÁN HIỂN THỊ -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-1">
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
            THANH TOÁN HIỂN THỊ
          </div>
          <div class="text-2xl sm:text-3xl font-extrabold text-slate-900">
            {{ metrics.shown_transactions_count || 0 }}
          </div>
        </div>

        <!-- Card 3: TỔNG THANH TOÁN -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-1">
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
            TỔNG THANH TOÁN
          </div>
          <div class="text-2xl sm:text-3xl font-extrabold text-slate-900">
            {{ metrics.total_transactions_count || 0 }}
          </div>
        </div>
      </div>

      <!-- Biểu đồ: DOANH THU THEO NGÀY (THÁNG NÀY) (Khớp chính xác hình tham khảo) -->
      <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Doanh thu theo ngày (tháng này)</h2>
          <p class="text-xs text-slate-500 mt-0.5">Khóa học và học cụ</p>
        </div>

        <!-- Chart Area -->
        <div class="relative pt-4 pb-2">
          <!-- Guide lines & Y-axis labels -->
          <div class="space-y-7 border-b border-slate-200 pb-2">
            <div v-for="val in [4, 3, 2, 1, 0]" :key="val" class="flex items-center gap-2 text-[11px] text-slate-400">
              <span class="w-4 text-right">{{ val }}</span>
              <div class="flex-1 border-b border-dashed border-slate-200/70"></div>
            </div>
          </div>

          <!-- Interactive 31 Days Columns -->
          <div class="absolute inset-x-8 bottom-7 top-4 flex items-end justify-between px-1">
            <div v-for="d in chartData" :key="d.day" @mouseenter="hoveredDay = d.day"
              class="relative group flex flex-col items-center flex-1 h-full justify-end cursor-pointer">
              <!-- Highlight column on hover (Xám nhạt giống ngày 25 trong hình mẫu) -->
              <div v-if="hoveredDay === d.day"
                class="absolute inset-x-0.5 bottom-0 top-0 bg-slate-200/70 rounded-xs pointer-events-none"></div>

              <!-- Bar Stack (Khóa học + Học cụ) -->
              <div class="w-2.5 z-10 flex flex-col justify-end transition-all" :style="{
                height: maxChartValue > 0 ? `${Math.min(100, (d.total / maxChartValue) * 85)}%` : '0%'
              }">
                <!-- Khóa học (Màu vàng amber) -->
                <div v-if="d.course > 0" class="w-full bg-amber-400 rounded-t-xs"
                  :style="{ height: `${(d.course / (d.total || 1)) * 100}%` }"></div>
                <!-- Học cụ (Màu xanh đậm) -->
                <div v-if="d.inventory > 0" class="w-full bg-blue-700"
                  :style="{ height: `${(d.inventory / (d.total || 1)) * 100}%` }"></div>
              </div>

              <!-- Tooltip popup trên ngày được hover (Khớp chính xác hình ảnh mẫu) -->
              <div v-if="hoveredDay === d.day"
                class="absolute bottom-16 z-30 w-36 rounded-xl border border-slate-200 bg-white p-2.5 shadow-lg text-left pointer-events-none">
                <div class="font-bold text-slate-900 text-xs">{{ d.day }}</div>
                <div class="text-[11px] text-amber-600 font-medium mt-1">
                  Khóa học : {{ formatCurrency(d.course) }}
                </div>
                <div class="text-[11px] text-blue-700 font-medium mt-0.5">
                  Học cụ : {{ formatCurrency(d.inventory) }}
                </div>
              </div>
            </div>
          </div>

          <!-- X-axis Days Labels (1 to 31) -->
          <div class="flex items-center justify-between pl-8 pr-2 pt-2 text-[10px] text-slate-400">
            <span v-for="d in 31" :key="d" class="text-center flex-1">
              {{ d % 2 === 1 || d === 31 ? d : '' }}
            </span>
          </div>

          <!-- Legend at bottom (Khớp chính xác hình mẫu) -->
          <div class="flex items-center justify-center gap-6 pt-4 text-xs">
            <div class="flex items-center gap-1.5 text-slate-600">
              <span class="inline-block h-3 w-3 rounded-xs bg-amber-400"></span>
              <span>Khóa học</span>
            </div>
            <div class="flex items-center gap-1.5 text-slate-600">
              <span class="inline-block h-3 w-3 rounded-xs bg-blue-700"></span>
              <span>Học cụ</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Card: THANH TOÁN (Khớp chính xác bảng thanh toán hình tham khảo) -->
      <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <h2 class="text-sm sm:text-base font-bold text-slate-900">Thanh toán</h2>
            <p class="text-xs text-slate-500 mt-0.5">Lọc theo kỳ, năm, tháng, trung tâm và loại</p>
          </div>

          <div class="flex items-center gap-2">
            <!-- Nút Tải doanh thu -->
            <button type="button" @click="downloadCsv"
              class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-700 hover:bg-slate-50 transition-all cursor-pointer">
              <Download class="h-3.5 w-3.5 text-slate-500" />
              <span>Tải doanh thu</span>
            </button>

            <!-- Icon Info -->
            <button type="button" title="Thông tin ghi nhận giao dịch doanh thu"
              class="rounded-xl border border-slate-200 p-1.5 text-slate-400 hover:text-slate-600">
              <Info class="h-4 w-4" />
            </button>

            <!-- Nút + Thêm doanh thu (Đỏ / Rose) -->
            <button type="button" @click="openCreateRevenue"
              class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 px-3.5 py-1.5 text-xs sm:text-sm font-semibold text-white shadow-2xs transition-all active:scale-[0.98] cursor-pointer">
              <Plus class="h-4 w-4" />
              <span>Thêm doanh thu</span>
            </button>
          </div>
        </div>

        <!-- Empty State khi không có thanh toán -->
        <div v-if="transactions.length === 0" class="py-8 text-center text-xs sm:text-sm text-slate-500">
          Không có thanh toán
        </div>

        <!-- Bảng danh sách thanh toán khi có dữ liệu -->
        <div v-else class="overflow-x-auto pt-1">
          <table class="w-full text-left text-xs sm:text-sm">
            <thead>
              <tr class="border-b border-slate-200 text-slate-500 text-[11px] sm:text-xs uppercase font-semibold">
                <th class="py-2.5 pr-2">Người phụ trách</th>
                <th class="py-2.5 px-2">Ngày</th>
                <th class="py-2.5 px-2">Học viên</th>
                <th class="py-2.5 px-2">Khóa học</th>
                <th class="py-2.5 px-2">Học cụ</th>
                <th class="py-2.5 px-2">Phương thức</th>
                <th class="py-2.5 px-2">Tài khoản</th>
                <th class="py-2.5 px-2 text-right">Học phí</th>
                <th class="py-2.5 px-2 text-right">Phí học cụ</th>
                <th class="py-2.5 pl-2 text-right">Tổng</th>
                <th class="py-2.5 pl-2 w-16"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="tx in transactions" :key="tx.id" class="hover:bg-slate-50/70 transition-colors">
                <!-- Người phụ trách -->
                <td class="py-3 pr-2 text-xs font-medium text-slate-800">
                  {{ tx.recorded_by }}
                </td>

                <!-- Ngày -->
                <td class="py-3 px-2 text-xs text-slate-500 font-mono">
                  {{ tx.date_formatted }}
                </td>

                <!-- Học viên -->
                <td class="py-3 px-2 font-bold text-slate-900">
                  {{ tx.student_name }}
                </td>

                <!-- Khóa học -->
                <td class="py-3 px-2 text-xs text-slate-600">
                  {{ tx.course_name }}
                </td>

                <!-- Học cụ -->
                <td class="py-3 px-2 text-xs text-slate-600">
                  {{ tx.inventory_name }}
                </td>

                <!-- Phương thức -->
                <td class="py-3 px-2 text-xs text-slate-600">
                  <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                    {{ tx.payment_method }}
                  </span>
                </td>

                <!-- Tài khoản -->
                <td class="py-3 px-2 text-xs text-slate-500">
                  {{ tx.account_name }}
                </td>

                <!-- Học phí -->
                <td class="py-3 px-2 text-right text-xs font-medium text-slate-700">
                  {{ tx.tuition_fee > 0 ? formatCurrency(tx.tuition_fee) : '-' }}
                </td>

                <!-- Phí học cụ -->
                <td class="py-3 px-2 text-right text-xs font-medium text-slate-700">
                  {{ tx.inventory_fee > 0 ? formatCurrency(tx.inventory_fee) : '-' }}
                </td>

                <!-- Tổng -->
                <td class="py-3 pl-2 text-right font-extrabold text-slate-900">
                  {{ formatCurrency(tx.total_amount) }}
                </td>

                <!-- Thao tác -->
                <td class="py-3 pl-2">
                  <div class="flex items-center justify-end gap-1">
                    <button type="button" @click="openEditRevenue(tx)"
                      class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                      :title="isInvoiceRow(tx) ? `Khoản thu từ hóa đơn ${tx.invoice_code}: sửa ở màn Hóa đơn` : 'Sửa'">
                      <Pencil class="h-4 w-4" />
                    </button>
                    <button type="button" @click="deleteRevenue(tx)"
                      class="rounded-lg p-1.5 text-rose-500 hover:bg-rose-50 hover:text-rose-700"
                      :title="isInvoiceRow(tx) ? `Khoản thu từ hóa đơn ${tx.invoice_code}: xóa ở màn Hóa đơn` : 'Xóa'">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pagination :meta="pagination" @change-page="changePage" @change-per-page="changePerPage" />
      </div>
    </div>

    <!-- TAB 2: CHI PHÍ -->
    <div v-else-if="activeTab === 'expenses'" class="space-y-5">
      <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <h2 class="text-base font-bold text-slate-900">Quản lý chi phí vận hành</h2>
            <p class="text-xs text-slate-500 mt-0.5">Lương giáo viên trong tháng (tự tính) và các chi phí phát sinh: mặt
              bằng, điện nước, marketing…</p>
          </div>

          <button type="button" @click="openCreateExpense"
            class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 px-3.5 py-1.5 text-xs sm:text-sm font-semibold text-white shadow-2xs transition-all active:scale-[0.98] cursor-pointer">
            <Plus class="h-4 w-4" />
            <span>Thêm chi phí</span>
          </button>
        </div>

        <!-- Lương giáo viên trong tháng: tự tính từ các ca đã check-in, cùng số với trang Check-in ca dạy -->
        <div class="rounded-xl border border-indigo-100 bg-indigo-50/40 p-4 space-y-3">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div class="flex items-start gap-2.5">
              <Users class="h-5 w-5 text-indigo-600 shrink-0 mt-0.5" />
              <div>
                <div class="text-sm font-bold text-slate-900">Lương giáo viên tháng {{ payroll.month }}</div>
                <div class="text-[11px] text-slate-500">
                  Tự tính từ {{ payroll.shifts }} ca đã check-in ở trang
                  <router-link to="/teacher-checkin" class="font-semibold text-indigo-700 hover:underline">Check-in ca dạy</router-link>,
                  không cần nhập tay.
                </div>
              </div>
            </div>
            <div class="flex items-center gap-2 self-end sm:self-auto">
              <span class="text-lg font-extrabold text-rose-600">{{ formatCurrency(payroll.total) }}</span>
              <button v-if="payroll.teachers.length" type="button" @click="showPayrollDetail = !showPayrollDetail"
                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                Từng người
                <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="showPayrollDetail ? 'rotate-180' : ''" />
              </button>
            </div>
          </div>

          <div v-if="showPayrollDetail && payroll.teachers.length" class="divide-y divide-indigo-100 border-t border-indigo-100 text-xs">
            <div v-for="t in payroll.teachers" :key="t.name" class="flex items-center justify-between py-2">
              <span class="font-semibold text-slate-800">{{ t.name }}
                <span class="font-normal text-slate-500">· {{ t.shifts }} ca</span></span>
              <span class="font-bold text-slate-900">{{ formatCurrency(t.amount) }}</span>
            </div>
          </div>

          <p v-if="payroll.manual_overlap"
            class="flex items-start gap-1.5 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-[11px] text-amber-800">
            <AlertTriangle class="h-3.5 w-3.5 shrink-0 mt-0.5" />
            Tháng này còn khoản "Lương giáo viên" nhập tay trước đây, lương đang bị tính hai lần. Xóa khoản nhập tay đó
            (hoặc đổi sang "Lương khác" nếu là thưởng / phụ cấp).
          </p>
        </div>

        <div v-if="expenses.length === 0" class="py-6 text-center text-xs sm:text-sm text-slate-500">
          Chưa có chi phí phát sinh nào khác trong kỳ này
        </div>

        <div v-else class="overflow-x-auto pt-1">
          <table class="w-full text-left text-xs sm:text-sm">
            <thead>
              <tr class="border-b border-slate-200 text-slate-500 text-[11px] sm:text-xs uppercase font-semibold">
                <th class="py-2.5 pr-2">Ngày</th>
                <th class="py-2.5 px-2">Danh mục chi</th>
                <th class="py-2.5 px-2">Người chi</th>
                <th class="py-2.5 px-2">Nội dung chi</th>
                <th class="py-2.5 px-2">Tài khoản</th>
                <th class="py-2.5 px-2">Cơ sở</th>
                <th class="py-2.5 pl-2 text-right">Số tiền</th>
                <th class="py-2.5 pl-2 w-16"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="ex in pagedExpenses" :key="ex.id" class="hover:bg-slate-50/70">
                <td class="py-3 pr-2 font-mono text-xs text-slate-500">{{ ex.date }}</td>
                <td class="py-3 px-2 font-semibold text-slate-800">{{ ex.category }}</td>
                <td class="py-3 px-2 text-slate-600">{{ ex.spender }}</td>
                <td class="py-3 px-2 text-slate-700">{{ ex.content }}</td>
                <td class="py-3 px-2 text-xs text-slate-500">{{ ex.account }}</td>
                <td class="py-3 px-2 text-xs text-slate-500">{{ ex.center }}</td>
                <td class="py-3 pl-2 text-right font-bold text-rose-600">{{ formatCurrency(ex.amount) }}</td>
                <td class="py-3 pl-2">
                  <div class="flex items-center justify-end gap-1">
                    <button type="button" @click="openEditExpense(ex)"
                      class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800" title="Sửa">
                      <Pencil class="h-4 w-4" />
                    </button>
                    <button type="button" @click="deleteExpense(ex)"
                      class="rounded-lg p-1.5 text-rose-500 hover:bg-rose-50 hover:text-rose-700" title="Xóa">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          <Pagination :meta="expensePagination" @change-page="(p) => (expensePage = p)"
            @change-per-page="changeExpensePerPage" />

          <div
            class="pt-4 border-t border-slate-100 flex justify-between items-center text-xs font-medium text-slate-700">
            <span>{{ expenses.length }} khoản chi phát sinh: {{ formatCurrency(profit.manual_expense) }}
              + lương giáo viên {{ formatCurrency(profit.payroll_expense) }}</span>
            <span>Tổng chi: <strong class="text-rose-600 text-sm font-bold">{{ formatCurrency(profit.total_expense)
                }}</strong></span>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 3: LỢI NHUẬN -->
    <div v-else-if="activeTab === 'profit'" class="space-y-5">
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <!-- Doanh thu -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-1">
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">TỔNG DOANH THU</div>
          <div class="text-2xl font-extrabold text-emerald-600">{{ formatCurrency(profit.total_revenue) }}</div>
        </div>

        <!-- Chi phí -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-1">
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">TỔNG CHI PHÍ</div>
          <div class="text-2xl font-extrabold text-rose-600">{{ formatCurrency(profit.total_expense) }}</div>
        </div>

        <!-- Lợi nhuận gộp -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-1">
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">LỢI NHUẬN THUẦN</div>
          <div :class="['text-2xl font-extrabold', profit.gross_profit >= 0 ? 'text-blue-600' : 'text-rose-600']">
            {{ formatCurrency(profit.gross_profit) }}
          </div>
        </div>

        <!-- Tỷ suất lợi nhuận -->
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-1">
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">TỶ SUẤT LỢI NHUẬN</div>
          <div class="text-2xl font-extrabold text-slate-900">{{ profit.profit_margin }}%</div>
        </div>
      </div>
    </div>

    <!-- TAB 4: DOANH THU PHÂN BỔ -->
    <div v-else-if="activeTab === 'allocation'" class="space-y-5">
      <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
        <div>
          <h2 class="text-base font-bold text-slate-900">Doanh thu phân bổ theo buổi học thực tế</h2>
          <p class="text-xs text-slate-500 mt-0.5">Phân định rõ ràng giữa Doanh thu đã dạy (Earned Revenue) và Doanh thu
            nhận trước chưa thực hiện (Deferred Revenue)</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
          <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 space-y-1.5">
            <div class="text-xs font-semibold text-emerald-800">DOANH THU ĐÃ THỰC HIỆN (HỌC VIÊN ĐÃ ĐIỂM DANH)</div>
            <div class="text-2xl font-extrabold text-emerald-700">{{ formatCurrency(allocation.accrued_revenue) }}</div>
            <p class="text-xs text-emerald-600">Giá trị các buổi học đã được giáo viên giảng dạy thành công.</p>
          </div>

          <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 space-y-1.5">
            <div class="text-xs font-semibold text-amber-800">DOANH THU BẢO LƯU / CHƯA HỌC (DOANH THU NHẬN TRƯỚC)</div>
            <div class="text-2xl font-extrabold text-amber-700">{{ formatCurrency(allocation.deferred_revenue) }}</div>
            <p class="text-xs text-amber-600">Số buổi còn lại của học viên cần được dạy trong tương lai.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL THÊM DOANH THU -->
    <div v-if="showRevenueModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">{{ editingRevenueId ? 'Sửa khoản doanh thu' : 'Ghi nhận doanh thu mới' }}</h3>
          <button type="button" @click="showRevenueModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitRevenue" class="space-y-3.5 text-xs sm:text-sm">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Người phụ trách (*)</label>
              <input v-model="revForm.recorded_by" type="text" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Ngày thu (*)</label>
              <input v-model="revForm.date" type="date" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Học viên / Khách hàng</label>
              <input v-model="revForm.student_name" list="revenue-students" type="text"
                :data-student-count="students.length" placeholder="VD: Nguyễn Minh Khang"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Cơ sở áp dụng (*)</label>
              <select v-model="revForm.center" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none">
                <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Khóa học (nếu có)</label>
              <input v-model="revForm.course_name" list="revenue-courses" type="text" placeholder="VD: Piano Căn Bản"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Học cụ (nếu có)</label>
              <input v-model="revForm.inventory_name" type="text" placeholder="VD: Giáo trình Alfred"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Tiền học phí (VNĐ)</label>
              <MoneyInput v-model="revForm.tuition_fee"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-bold text-amber-600" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Tiền học cụ (VNĐ)</label>
              <MoneyInput v-model="revForm.inventory_fee"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-bold text-blue-600" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Phương thức thanh toán</label>
              <select v-model="revForm.payment_method"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none">
                <option value="Chuyển khoản">Chuyển khoản ngân hàng</option>
                <option value="Tiền mặt">Tiền mặt tại quầy</option>
                <option value="Quẹt thẻ POS">Quẹt thẻ POS</option>
                <option value="Ví MoMo">Ví MoMo</option>
              </select>
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Tài khoản nhận tiền</label>
              <input v-model="revForm.account_name" type="text" placeholder="VD: Vietcombank - Tài khoản trung tâm"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Ghi chú</label>
            <input v-model="revForm.note" type="text" placeholder="VD: Đóng học phí đợt 1..."
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
          </div>

          <datalist id="revenue-students">
            <option v-for="student in students" :key="student.id" :value="student.name">{{ student.code }}</option>
          </datalist>
          <datalist id="revenue-courses">
            <option v-for="course in courses" :key="course.id" :value="course.name">{{ formatCurrency(course.price) }}
            </option>
          </datalist>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Invoice PDF</label>
            <input type="file" accept="application/pdf" @change="revForm.invoice_pdf = $event.target.files?.[0] || null"
              class="block w-full rounded-xl border border-slate-200 px-3 py-2 text-xs text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:font-medium" />
            <p class="mt-1 text-[11px] text-slate-400">Tối đa 10 MB; tệp được đính kèm vào phiếu thu học phí.</p>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showRevenueModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors">
              Hủy
            </button>
            <button type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]">
              Lưu thanh toán
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL THÊM CHI PHÍ -->
    <div v-if="showExpenseModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
      <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 class="text-base font-bold text-slate-900">{{ editingExpenseId ? 'Sửa khoản chi' : 'Ghi nhận khoản chi mới' }}</h3>
          <button type="button" @click="showExpenseModal = false"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitExpense" class="space-y-3.5 text-xs sm:text-sm">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Ngày chi (*)</label>
              <input v-model="expForm.date" type="date" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Danh mục chi (*)</label>
              <select v-model="expForm.category"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none">
                <option value="Thuê mặt bằng">Thuê mặt bằng</option>
                <!-- Lương ca giáo viên tự tính từ Check-in, ở đây chỉ nhập khoản lương khác (thưởng, phụ cấp...) -->
                <option value="Lương khác">Lương khác (thưởng, phụ cấp…)</option>
                <option v-if="expForm.category === 'Lương giáo viên'" value="Lương giáo viên">Lương giáo viên (nhập tay trước đây)</option>
                <option value="Điện nước & Internet">Điện nước & Internet</option>
                <option value="Marketing & Quảng cáo">Marketing & Quảng cáo</option>
                <option value="Mua học cụ & Nhạc cụ">Mua học cụ & Nhạc cụ</option>
                <option value="Bảo trì cơ sở vật chất">Bảo trì cơ sở vật chất</option>
                <option value="Chi phí khác">Chi phí khác</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Người thực hiện chi (*)</label>
              <input v-model="expForm.spender" type="text" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Cơ sở chi (*)</label>
              <select v-model="expForm.center"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none">
                <option value="Tất cả trung tâm">Tất cả trung tâm</option>
                <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block font-medium text-slate-700 mb-1">Nội dung chi chi tiết (*)</label>
            <input v-model="expForm.content" type="text" required placeholder="VD: Trả tiền thuê mặt bằng tháng này"
              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-medium text-slate-700 mb-1">Tài khoản chi</label>
              <input v-model="expForm.account" type="text" placeholder="VD: Vietcombank - Tài khoản trung tâm"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none" />
            </div>
            <div>
              <label class="block font-medium text-slate-700 mb-1">Số tiền chi (VNĐ) (*)</label>
              <MoneyInput v-model="expForm.amount" required
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-slate-900 focus:border-rose-500 focus:outline-none font-bold text-rose-600" />
            </div>
          </div>

          <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" @click="showExpenseModal = false"
              class="rounded-xl border border-slate-200 px-4 py-2 font-medium text-slate-600 hover:bg-slate-50 transition-colors">
              Hủy
            </button>
            <button type="submit"
              class="rounded-xl bg-rose-600 hover:bg-rose-700 px-5 py-2 font-semibold text-white shadow-2xs transition-all active:scale-[0.98]">
              Lưu khoản chi
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
