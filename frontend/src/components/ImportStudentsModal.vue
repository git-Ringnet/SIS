<script setup>
import { ref } from 'vue';
import Modal from './Modal.vue';
import { studentApi } from '../services/api';
import { notify } from '../services/notify';
import {
  UploadCloud,
  FileSpreadsheet,
  Download,
  AlertCircle,
  CheckCircle2,
  X,
  Loader2,
  HelpCircle,
} from 'lucide-vue-next';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'imported']);

const fileInput = ref(null);
const selectedFile = ref(null);
const isUploading = ref(false);
const previewRows = ref([]);
const previewHeaders = ref([]);
const isDragging = ref(false);
const errorMessage = ref('');

const resetState = () => {
  selectedFile.value = null;
  previewRows.value = [];
  previewHeaders.value = [];
  errorMessage.value = '';
  isUploading.value = false;
  if (fileInput.value) {
    fileInput.value.value = '';
  }
};

const handleClose = () => {
  if (isUploading.value) return;
  resetState();
  emit('close');
};

const handleFileSelect = (e) => {
  const file = e.target.files?.[0];
  if (file) {
    processFile(file);
  }
};

const handleDrop = (e) => {
  isDragging.value = false;
  const file = e.dataTransfer?.files?.[0];
  if (file) {
    processFile(file);
  }
};

const processFile = (file) => {
  errorMessage.value = '';
  const name = file.name.toLowerCase();
  const isExcel = name.endsWith('.xlsx') || name.endsWith('.xls');
  if (!isExcel && !name.endsWith('.csv')) {
    errorMessage.value = 'Vui lòng chọn file Excel (.xlsx, .xls) hoặc CSV (.csv)!';
    return;
  }

  selectedFile.value = file;
  previewHeaders.value = [];
  previewRows.value = [];

  // File Excel là nhị phân nên không preview được trên trình duyệt, chỉ preview CSV
  if (isExcel) return;

  // Đọc vài dòng đầu để preview dữ liệu
  const reader = new FileReader();
  reader.onload = (e) => {
    const text = e.target?.result;
    if (typeof text === 'string') {
      parsePreview(text);
    }
  };
  reader.readAsText(file, 'UTF-8');
};

const parsePreview = (csvText) => {
  const lines = csvText.split(/\r?\n/).filter(line => line.trim().length > 0);
  const dataLines = [];
  let headers = [];

  for (const line of lines) {
    // Bỏ qua comment bắt đầu bằng #
    if (line.trim().startsWith('#')) continue;

    // Tách dòng CSV cơ bản hỗ trợ dấu ngoặc kép
    const cells = [];
    let insideQuote = false;
    let currentCell = '';

    for (let i = 0; i < line.length; i++) {
      const char = line[i];
      if (char === '"') {
        insideQuote = !insideQuote;
      } else if (char === ',' && !insideQuote) {
        cells.push(currentCell.trim());
        currentCell = '';
      } else {
        currentCell += char;
      }
    }
    cells.push(currentCell.trim());

    if (headers.length === 0) {
      headers = cells;
    } else {
      dataLines.push(cells);
      if (dataLines.length >= 5) break; // Chỉ preview tối đa 5 dòng đầu
    }
  }

  previewHeaders.value = headers;
  previewRows.value = dataLines;
};

const downloadTemplate = async () => {
  try {
    const res = await studentApi.downloadTemplate();
    const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', 'students_template.xlsx');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
    notify.success('Đã tải xuống file mẫu students_template.xlsx!');
  } catch (err) {
    console.error('Error downloading template:', err);
    notify.error('Không thể tải file mẫu. Vui lòng thử lại!');
  }
};

const submitImport = async () => {
  if (!selectedFile.value) {
    errorMessage.value = 'Vui lòng chọn file Excel hoặc CSV trước khi import!';
    return;
  }

  isUploading.value = true;
  errorMessage.value = '';

  try {
    const formData = new FormData();
    formData.append('csv_file', selectedFile.value);

    const res = await studentApi.importCsv(formData);
    notify.success(res.data.message || `Đã nhập thành công ${res.data.imported_count} học viên!`);
    emit('imported');
    handleClose();
  } catch (err) {
    console.error('Import error:', err);
    errorMessage.value = err.response?.data?.message || 'Có lỗi xảy ra khi import danh sách học viên. Vui lòng kiểm tra lại cấu trúc file!';
  } finally {
    isUploading.value = false;
  }
};
</script>

<template>
  <Modal :show="show" max-width="3xl" @close="handleClose">
    <div class="p-6">
      <!-- Header -->
      <div class="flex items-center justify-between pb-4 border-b border-slate-100">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600">
            <FileSpreadsheet class="h-5 w-5" />
          </div>
          <div>
            <h3 class="text-lg font-bold text-slate-900 tracking-tight">Import danh sách học viên (Excel)</h3>
            <p class="text-xs text-slate-500">Tải lên file học viên theo định dạng mẫu để thêm hàng loạt vào hệ thống</p>
          </div>
        </div>
        <button
          @click="handleClose"
          class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <!-- Quick Action: Tải file mẫu -->
      <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-start gap-2.5">
          <HelpCircle class="h-4 w-4 text-indigo-600 mt-0.5 shrink-0" />
          <div class="text-xs text-slate-600 leading-relaxed">
            Hỗ trợ định dạng 13 cột chuẩn SenyApp (<code class="bg-white px-1 py-0.5 rounded border border-slate-200 text-indigo-600 font-mono">full_name</code>, <code class="bg-white px-1 py-0.5 rounded border border-slate-200 font-mono">birthday</code>, <code class="bg-white px-1 py-0.5 rounded border border-slate-200 font-mono">parent_name</code>, <code class="bg-white px-1 py-0.5 rounded border border-slate-200 font-mono">schedules</code>, ...).
          </div>
        </div>
        <button
          type="button"
          @click="downloadTemplate"
          class="inline-flex items-center justify-center gap-2 px-3.5 py-2 text-xs font-semibold text-indigo-700 bg-white border border-indigo-200 rounded-lg hover:bg-indigo-50 transition-colors shadow-2xs shrink-0"
        >
          <Download class="h-3.5 w-3.5" />
          <span>Tải file mẫu Excel</span>
        </button>
      </div>

      <!-- Dropzone -->
      <div class="mt-5">
        <div
          @dragover.prevent="isDragging = true"
          @dragleave.prevent="isDragging = false"
          @drop.prevent="handleDrop"
          @click="fileInput?.click()"
          class="border-2 border-dashed rounded-2xl p-6 sm:p-8 text-center cursor-pointer transition-all"
          :class="[
            isDragging ? 'border-indigo-500 bg-indigo-50/50 scale-[0.99]' : 'border-slate-300 hover:border-indigo-400 hover:bg-slate-50/50',
            selectedFile ? 'bg-indigo-50/20 border-indigo-300' : ''
          ]"
        >
          <input
            ref="fileInput"
            type="file"
            accept=".xlsx,.xls,.csv"
            class="hidden"
            @change="handleFileSelect"
          />

          <div v-if="!selectedFile" class="space-y-3">
            <div class="h-12 w-12 rounded-2xl bg-indigo-100/70 text-indigo-600 mx-auto flex items-center justify-center shadow-xs">
              <UploadCloud class="h-6 w-6" />
            </div>
            <div>
              <p class="text-sm font-semibold text-slate-800">
                Kéo thả file Excel vào đây hoặc <span class="text-indigo-600 hover:underline">chọn từ máy tính</span>
              </p>
              <p class="text-xs text-slate-400 mt-1">Định dạng hỗ trợ: .xlsx, .xls, .csv (UTF-8) dung lượng tối đa 10MB</p>
            </div>
          </div>

          <!-- File đã chọn -->
          <div v-else class="flex items-center justify-between p-3.5 bg-white rounded-xl border border-indigo-100 shadow-2xs">
            <div class="flex items-center gap-3 min-w-0">
              <div class="h-10 w-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                <FileSpreadsheet class="h-5 w-5" />
              </div>
              <div class="text-left min-w-0">
                <p class="text-sm font-bold text-slate-800 truncate">{{ selectedFile.name }}</p>
                <p class="text-xs text-slate-400">{{ (selectedFile.size / 1024).toFixed(1) }} KB</p>
              </div>
            </div>

            <button
              type="button"
              @click.stop="resetState"
              class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition-colors"
              title="Chọn file khác"
            >
              <X class="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Error alert -->
      <div v-if="errorMessage" class="mt-4 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-start gap-2.5">
        <AlertCircle class="h-4 w-4 shrink-0 mt-0.5" />
        <span class="leading-relaxed">{{ errorMessage }}</span>
      </div>

      <!-- Preview bảng dữ liệu -->
      <div v-if="previewRows.length > 0" class="mt-5 space-y-2">
        <div class="flex items-center justify-between">
          <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Xem trước {{ previewRows.length }} dòng đầu:</span>
          <span class="text-xs text-emerald-600 font-semibold flex items-center gap-1">
            <CheckCircle2 class="h-3.5 w-3.5" /> Đã đọc thành công cấu trúc file
          </span>
        </div>
        <div class="max-h-48 overflow-auto rounded-xl border border-slate-200 bg-white">
          <table class="w-full text-xs text-left">
            <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200 sticky top-0">
              <tr>
                <th v-for="(h, i) in previewHeaders" :key="i" class="px-3 py-2 whitespace-nowrap">
                  {{ h }}
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(row, rIdx) in previewRows" :key="rIdx" class="hover:bg-slate-50/60">
                <td v-for="(cell, cIdx) in row" :key="cIdx" class="px-3 py-2 whitespace-nowrap text-slate-700">
                  {{ cell || '-' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal Footer -->
      <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
        <button
          type="button"
          @click="handleClose"
          :disabled="isUploading"
          class="px-4 py-2.5 text-sm font-semibold text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition-colors"
        >
          Hủy bỏ
        </button>
        <button
          type="button"
          @click="submitImport"
          :disabled="!selectedFile || isUploading"
          class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-xl transition-all shadow-xs shadow-indigo-600/20 active:scale-[0.98]"
        >
          <Loader2 v-if="isUploading" class="h-4 w-4 animate-spin" />
          <UploadCloud v-else class="h-4 w-4" />
          <span>{{ isUploading ? 'Đang xử lý import...' : 'Bắt đầu Import' }}</span>
        </button>
      </div>
    </div>
  </Modal>
</template>
