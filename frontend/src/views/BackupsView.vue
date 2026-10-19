<script setup>
import { ref, onMounted } from 'vue';
import { backupApi } from '../services/api';
import { notify, errorMessage } from '../services/notify';
import { confirmDialog } from '../services/useConfirm';
import Modal from '../components/Modal.vue';
import {
  ArrowLeft,
  DatabaseBackup,
  CloudUpload,
  Download,
  RotateCcw,
  Trash2,
  Upload,
  HardDrive,
  Clock,
  Cloud,
  AlertTriangle,
} from 'lucide-vue-next';

const loading = ref(true);
const forbidden = ref(false);
const status = ref(null);
const backups = ref([]);
const creating = ref(false);
const uploadingFile = ref(null); // tên bản đang đẩy lên Drive
const importing = ref(false);

// Khôi phục
const restoreTarget = ref(null);
const restorePassword = ref('');
const restoring = ref(false);

const TRIGGER_LABELS = {
  manual: 'Thủ công',
  schedule: 'Tự động',
  'pre-restore': 'Trước khi khôi phục',
  imported: 'Tải lên',
};

const DRIVE_STYLES = {
  uploaded: { label: 'Đã lên Drive', class: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  failed: { label: 'Lỗi đẩy Drive', class: 'bg-rose-50 text-rose-700 border-rose-200' },
  skipped: { label: 'Chỉ trên máy chủ', class: 'bg-slate-50 text-slate-600 border-slate-200' },
};

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await backupApi.getAll();
    status.value = res.data.status;
    backups.value = res.data.backups || [];
  } catch (err) {
    if (err.response?.status === 403) forbidden.value = true;
    else notify.apiError(err, 'Không tải được danh sách bản sao lưu');
  } finally {
    loading.value = false;
  }
};

onMounted(fetchData);

const createBackup = async () => {
  try {
    creating.value = true;
    const res = await backupApi.create();
    const driveStatus = res.data.backup?.drive?.status;
    (driveStatus === 'failed' ? notify.warning : notify.success)(res.data.message, {
      description: driveStatus === 'failed' ? res.data.backup.drive.message : undefined,
    });
    await fetchData();
  } catch (err) {
    notify.apiError(err, 'Sao lưu thất bại');
  } finally {
    creating.value = false;
  }
};

const uploadToDrive = async (b) => {
  try {
    uploadingFile.value = b.file;
    const res = await backupApi.upload(b.file);
    notify.success(res.data.message);
  } catch (err) {
    notify.apiError(err, 'Không đẩy được lên Google Drive');
  } finally {
    uploadingFile.value = null;
    fetchData();
  }
};

const downloadBackup = async (b) => {
  try {
    await backupApi.download(b.file);
  } catch (err) {
    notify.apiError(err, 'Không tải được bản sao lưu');
  }
};

const deleteBackup = async (b) => {
  const ok = await confirmDialog({
    title: 'Xoá bản sao lưu?',
    message: `${b.file} sẽ bị xoá khỏi máy chủ. Bản đã đẩy lên Google Drive (nếu có) vẫn được giữ.`,
    confirmText: 'Xoá',
  });
  if (!ok) return;
  try {
    const res = await backupApi.delete(b.file);
    notify.success(res.data.message);
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không xoá được bản sao lưu');
  }
};

const openRestore = (b) => {
  restoreTarget.value = b;
  restorePassword.value = '';
};

const submitRestore = async () => {
  if (!restorePassword.value) {
    notify.warning('Vui lòng nhập mật khẩu để xác nhận khôi phục.');
    return;
  }
  try {
    restoring.value = true;
    const res = await backupApi.restore(restoreTarget.value.file, restorePassword.value);
    restoreTarget.value = null;
    notify.success(res.data.message, { description: `Bản an toàn của dữ liệu trước khi khôi phục: ${res.data.safety_backup}` });
    fetchData();
  } catch (err) {
    notify.error('Khôi phục thất bại', { description: errorMessage(err) });
  } finally {
    restoring.value = false;
  }
};

const importBackup = async (e) => {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  const formData = new FormData();
  formData.append('file', file);
  try {
    importing.value = true;
    const res = await backupApi.import(formData);
    notify.success(res.data.message);
    fetchData();
  } catch (err) {
    notify.apiError(err, 'Không tải lên được bản sao lưu');
  } finally {
    importing.value = false;
  }
};

const formatSize = (bytes) => {
  if (!bytes) return '—';
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

const formatDateTime = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getHours())}:${pad(d.getMinutes())} ${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
};
</script>

<template>
  <div class="space-y-5 pb-12 max-w-6xl">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="flex items-center gap-2 text-xl sm:text-2xl font-bold tracking-tight text-slate-900">
          <DatabaseBackup class="h-6 w-6 text-rose-600" />
          Sao lưu dữ liệu
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
          Mỗi bản sao lưu chứa toàn bộ dữ liệu hệ thống: học viên, điểm danh, hoá đơn, khách hàng, khoá học, nhân sự,
          trung tâm, cài đặt, doanh thu, chi phí, học cụ... Lưu trên máy chủ và đẩy lên Google Drive.
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

    <div v-if="loading && !status && !forbidden" class="py-16 text-center text-sm text-slate-400">Đang tải...</div>

    <div v-else-if="forbidden" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
      Chỉ chủ trung tâm được quản lý sao lưu dữ liệu.
    </div>

    <template v-else-if="status">
      <!-- Tình trạng cấu hình -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs space-y-1">
          <p class="flex items-center gap-1.5 text-xs font-bold text-slate-500 uppercase tracking-wider">
            <HardDrive class="h-4 w-4" /> Trên máy chủ
          </p>
          <p class="text-sm text-slate-800">Giữ <strong>{{ status.keep_local }}</strong> bản gần nhất</p>
          <p class="text-[11px] text-slate-400 break-all font-mono">{{ status.path }}</p>
        </div>

        <div class="rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs space-y-1">
          <p class="flex items-center gap-1.5 text-xs font-bold text-slate-500 uppercase tracking-wider">
            <Clock class="h-4 w-4" /> Tự động
          </p>
          <p class="text-sm text-slate-800">Hằng ngày lúc <strong>{{ status.schedule_time }}</strong></p>
          <p class="text-[11px] text-slate-400">Cần bật scheduler trên máy chủ (cron / Task Scheduler).</p>
        </div>

        <div
          :class="[
            status.rclone.configured && status.rclone.available ? 'border-emerald-200' : 'border-amber-200',
            'rounded-2xl border bg-white p-4 shadow-2xs space-y-1',
          ]"
        >
          <p class="flex items-center gap-1.5 text-xs font-bold text-slate-500 uppercase tracking-wider">
            <Cloud class="h-4 w-4" /> Google Drive (rclone)
          </p>
          <template v-if="status.rclone.configured && status.rclone.available">
            <p class="text-sm text-slate-800">
              Đẩy lên <strong class="font-mono">{{ status.rclone.remote }}</strong>
            </p>
            <p class="text-[11px] text-slate-400">
              {{ status.rclone.version }}
              <template v-if="status.rclone.keep_days"> &bull; xoá bản trên Drive cũ hơn {{ status.rclone.keep_days }} ngày</template>
            </p>
          </template>
          <template v-else>
            <p class="flex items-start gap-1.5 text-sm text-amber-700">
              <AlertTriangle class="h-4 w-4 shrink-0 mt-0.5" />
              <span v-if="!status.rclone.configured">Chưa cấu hình. Đặt <code class="font-mono text-xs">BACKUP_RCLONE_REMOTE</code> trong file .env.</span>
              <span v-else>{{ status.rclone.error }}</span>
            </p>
          </template>
        </div>
      </div>

      <!-- Danh sách bản sao lưu -->
      <div class="rounded-2xl border border-slate-200/90 bg-white shadow-2xs overflow-hidden">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 sm:p-5 border-b border-slate-100">
          <div>
            <h2 class="text-sm sm:text-base font-bold text-slate-900">Các bản sao lưu ({{ backups.length }})</h2>
            <p class="text-xs text-slate-500 mt-0.5">Tải về để cất giữ, hoặc khôi phục hệ thống về thời điểm của bản đó.</p>
          </div>
          <div class="flex items-center gap-2">
            <label
              :class="[importing ? 'opacity-60 pointer-events-none' : '', 'cursor-pointer inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3.5 py-2 text-xs sm:text-sm font-semibold text-slate-700 shadow-2xs']"
            >
              <Upload class="h-4 w-4" />
              <span>{{ importing ? 'Đang tải lên...' : 'Tải lên bản sao lưu' }}</span>
              <input type="file" accept=".zip" class="hidden" @change="importBackup" />
            </label>
            <button
              type="button"
              :disabled="creating"
              @click="createBackup"
              class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-60 px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-xs"
            >
              <DatabaseBackup class="h-4 w-4" />
              <span>{{ creating ? 'Đang sao lưu...' : 'Sao lưu ngay' }}</span>
            </button>
          </div>
        </div>

        <div v-if="backups.length === 0" class="py-12 text-center text-sm text-slate-400">
          Chưa có bản sao lưu nào. Bấm "Sao lưu ngay" để tạo bản đầu tiên.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-xs sm:text-sm min-w-[820px]">
            <thead class="bg-slate-50/90 text-slate-500 border-b border-slate-200/80 font-bold uppercase tracking-wider text-[11px]">
              <tr>
                <th class="py-3 px-4">Thời gian</th>
                <th class="py-3 px-4">Loại</th>
                <th class="py-3 px-4">Người tạo</th>
                <th class="py-3 px-4 text-right">Dung lượng</th>
                <th class="py-3 px-4">Google Drive</th>
                <th class="py-3 px-4 text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
              <tr v-for="b in backups" :key="b.file" class="hover:bg-slate-50/60">
                <td class="py-3 px-4">
                  <p class="font-semibold text-slate-900">{{ formatDateTime(b.created_at) }}</p>
                  <p class="text-[11px] text-slate-400 font-mono">{{ b.file }}</p>
                </td>
                <td class="py-3 px-4">{{ TRIGGER_LABELS[b.trigger] || b.trigger }}</td>
                <td class="py-3 px-4">{{ b.created_by || '—' }}</td>
                <td class="py-3 px-4 text-right font-mono">{{ formatSize(b.size) }}</td>
                <td class="py-3 px-4">
                  <span
                    :class="[(DRIVE_STYLES[b.drive?.status] || DRIVE_STYLES.skipped).class, 'inline-block rounded-md border px-2 py-0.5 text-[11px] font-bold']"
                    :title="b.drive?.message || ''"
                  >
                    {{ (DRIVE_STYLES[b.drive?.status] || DRIVE_STYLES.skipped).label }}
                  </span>
                  <p v-if="b.drive?.status === 'failed'" class="text-[11px] text-rose-600 mt-0.5 max-w-[220px] truncate" :title="b.drive.message">
                    {{ b.drive.message }}
                  </p>
                </td>
                <td class="py-3 px-4">
                  <div class="flex items-center justify-end gap-1">
                    <button
                      v-if="status.rclone.configured && b.drive?.status !== 'uploaded'"
                      type="button"
                      :disabled="uploadingFile === b.file"
                      @click="uploadToDrive(b)"
                      title="Đẩy lên Google Drive"
                      class="rounded-lg p-1.5 text-blue-600 hover:bg-blue-50 disabled:opacity-50"
                    >
                      <CloudUpload class="h-4 w-4" :class="uploadingFile === b.file ? 'animate-pulse' : ''" />
                    </button>
                    <button type="button" @click="downloadBackup(b)" title="Tải về máy" class="rounded-lg p-1.5 text-slate-600 hover:bg-slate-100">
                      <Download class="h-4 w-4" />
                    </button>
                    <button type="button" @click="openRestore(b)" title="Khôi phục từ bản này" class="rounded-lg p-1.5 text-amber-600 hover:bg-amber-50">
                      <RotateCcw class="h-4 w-4" />
                    </button>
                    <button type="button" @click="deleteBackup(b)" title="Xoá khỏi máy chủ" class="rounded-lg p-1.5 text-rose-500 hover:bg-rose-50">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Xác nhận khôi phục -->
    <Modal :show="!!restoreTarget" max-width="md" title="Khôi phục dữ liệu" @close="restoreTarget = null">
      <div v-if="restoreTarget" class="space-y-4">
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs sm:text-sm text-amber-800 space-y-1">
          <p class="font-bold flex items-center gap-1.5"><AlertTriangle class="h-4 w-4" /> Toàn bộ dữ liệu hiện tại sẽ bị thay thế</p>
          <p>
            Hệ thống sẽ quay về đúng thời điểm <strong>{{ formatDateTime(restoreTarget.created_at) }}</strong>. Mọi thay đổi sau thời điểm này sẽ mất.
            Trước khi khôi phục, hệ thống tự tạo một bản sao lưu của dữ liệu hiện tại để có thể quay lại.
          </p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Nhập mật khẩu của bạn để xác nhận</label>
          <input
            v-model="restorePassword"
            type="password"
            autocomplete="current-password"
            @keyup.enter="submitRestore"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          />
        </div>
        <div class="flex justify-end gap-2.5">
          <button type="button" @click="restoreTarget = null" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Huỷ
          </button>
          <button
            type="button"
            :disabled="restoring"
            @click="submitRestore"
            class="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 disabled:opacity-60 px-4 py-2 text-sm font-bold text-white"
          >
            <RotateCcw class="h-4 w-4" />
            {{ restoring ? 'Đang khôi phục...' : 'Khôi phục' }}
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
