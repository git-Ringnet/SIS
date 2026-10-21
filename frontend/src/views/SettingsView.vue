<script setup>
import { useBrand } from '../services/useBrand';
import { richLines } from '../services/richLines';
import { notify } from '../services/notify';
import { ref, computed, onMounted } from 'vue';
import { useAuth } from '../services/useAuth';
import { isPageBlocked } from '../services/permissions';
import { settingsApi } from '../services/api';
import {
  ShieldCheck,
  DatabaseBackup,
  Upload,
  Edit2,
} from 'lucide-vue-next';

const { setBrand } = useBrand();
const { user: authUser } = useAuth();
const canManageSettings = computed(() => !isPageBlocked(authUser.value, 'Cài đặt'));
const loading = ref(true);

// Profile form
const user = ref({
  name: '',
  email: '',
  role: 'Chủ trung tâm',
});
const savingName = ref(false);

// Password form
const passwordForm = ref({
  password: '',
  password_confirmation: '',
});
const changingPassword = ref(false);

// Brand form
const brand = ref({
  owner_email: '',
  brand_name: '',
  phone: '',
  address: '',
  category: 'Âm nhạc',
  scale: '100 – 300',
  logo_url: '',
  bank_name: '',
  bank_account: '',
  bank_holder: '',
  portal_leave_rules: '',
});
const savingBrand = ref(false);
// Quy định nghỉ học mặc định của Cổng phụ huynh (khi để trống ô soạn)
const defaultLeaveRules = ref('');
const leaveRulesPreview = computed(() => richLines(brand.value.portal_leave_rules || defaultLeaveRules.value, { so_buoi_duoc_nghi: 3 }));

// VAT Invoice form
const vat = ref({
  company_name: '',
  tax_code: '',
  vat_email: '',
  vat_address: '',
});
const editingVat = ref(false);
const savingVat = ref(false);


const showToast = (msg, type = 'success') => {
  if (type === 'success') {
    notify.success(msg);
  } else if (msg.startsWith('Vui lòng')) {
    notify.warning(msg);
  } else {
    notify.error(msg);
  }
};

const fetchData = async () => {
  try {
    loading.value = true;
    const res = await settingsApi.getSettings();
    if (res.data?.user) {
      user.value = { ...user.value, ...res.data.user };
    }
    if (res.data?.brand) {
      brand.value = { ...brand.value, ...res.data.brand };
      defaultLeaveRules.value = res.data.default_portal_leave_rules || '';
    }
    if (res.data?.vat) {
      vat.value = { ...vat.value, ...res.data.vat };
    }
  } catch (err) {
    console.error('Error fetching settings:', err);
  } finally {
    loading.value = false;
  }
};

onMounted(fetchData);

// 1. Lưu tên
const handleSaveName = async () => {
  if (!user.value.name.trim()) {
    showToast('Vui lòng nhập họ và tên', 'error');
    return;
  }
  try {
    savingName.value = true;
    const res = await settingsApi.updateProfile({ name: user.value.name });
    showToast(res.data?.message || 'Cập nhật họ và tên thành công!');
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi lưu tên', 'error');
  } finally {
    savingName.value = false;
  }
};

// 2. Đổi mật khẩu
const handleChangePassword = async () => {
  if (!passwordForm.value.password) {
    showToast('Vui lòng nhập mật khẩu mới', 'error');
    return;
  }
  if (passwordForm.value.password !== passwordForm.value.password_confirmation) {
    showToast('Nhập lại mật khẩu không khớp', 'error');
    return;
  }
  try {
    changingPassword.value = true;
    const res = await settingsApi.updatePassword(passwordForm.value);
    passwordForm.value.password = '';
    passwordForm.value.password_confirmation = '';
    showToast(res.data?.message || 'Đổi mật khẩu thành công!');
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi đổi mật khẩu', 'error');
  } finally {
    changingPassword.value = false;
  }
};

// 3. Lưu thương hiệu
const handleSaveBrand = async () => {
  try {
    savingBrand.value = true;
    const res = await settingsApi.updateBrand(brand.value);
    // Cập nhật ngay tên + logo ở sidebar / tiêu đề tab
    setBrand(res.data?.brand || brand.value);
    showToast(res.data?.message || 'Lưu thông tin thương hiệu thành công!');
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi lưu thương hiệu', 'error');
  } finally {
    savingBrand.value = false;
  }
};

// 4. Lưu thông tin hóa đơn VAT
const handleSaveVat = async () => {
  try {
    savingVat.value = true;
    const res = await settingsApi.updateVat(vat.value);
    editingVat.value = false;
    showToast(res.data?.message || 'Lưu thông tin hóa đơn VAT thành công!');
  } catch (err) {
    showToast(err.response?.data?.message || 'Có lỗi xảy ra khi lưu thông tin thuế', 'error');
  } finally {
    savingVat.value = false;
  }
};

const handleLogoUpload = (e) => {
  const file = e.target.files?.[0];
  if (file) {
    if (file.size > 2 * 1024 * 1024) {
      showToast('Kích thước ảnh lớn hơn 2MB, vui lòng chọn ảnh nhỏ hơn', 'error');
      return;
    }
    const reader = new FileReader();
    reader.onload = (uploadEvent) => {
      brand.value.logo_url = uploadEvent.target?.result;
      showToast('Đã chọn logo! Bấm "Lưu thay đổi" để áp dụng.');
    };
    reader.readAsDataURL(file);
  }
};
</script>

<template>
  <div class="space-y-6 pb-12 max-w-5xl">
    <!-- Header -->
    <div>
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Cài đặt</h1>
    </div>


    <!-- 1. TÀI KHOẢN -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Tài khoản</h2>
        <p class="text-xs text-slate-500 mt-0.5">Thông tin cá nhân của bạn.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Họ và tên -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Họ và tên</label>
          <input
            v-model="user.name"
            type="text"
            placeholder="Nhập họ và tên"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          />
        </div>

        <!-- Email -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Email</label>
          <input
            v-model="user.email"
            type="email"
            disabled
            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 text-xs sm:text-sm text-slate-500 cursor-not-allowed"
          />
        </div>

        <!-- Vai trò -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Vai trò</label>
          <input
            :value="user.role || 'Chủ trung tâm'"
            type="text"
            disabled
            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 text-xs sm:text-sm text-slate-500 cursor-not-allowed"
          />
        </div>
      </div>

      <div>
        <button
          type="button"
          :disabled="savingName"
          @click="handleSaveName"
          class="rounded-xl bg-rose-400/90 hover:bg-rose-500 disabled:opacity-60 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-xs transition-all active:scale-[0.98]"
        >
          {{ savingName ? 'Đang lưu...' : 'Lưu tên' }}
        </button>
      </div>
    </div>

    <!-- 2. ĐỔI MẬT KHẨU -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Đổi mật khẩu</h2>
        <p class="text-xs text-slate-500 mt-0.5">Cập nhật mật khẩu đăng nhập của bạn.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl">
        <!-- Mật khẩu mới -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Mật khẩu mới</label>
          <input
            v-model="passwordForm.password"
            type="password"
            placeholder="••••••••"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          />
        </div>

        <!-- Nhập lại mật khẩu mới -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Nhập lại mật khẩu mới</label>
          <input
            v-model="passwordForm.password_confirmation"
            type="password"
            placeholder="••••••••"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          />
        </div>
      </div>

      <div>
        <button
          type="button"
          :disabled="changingPassword"
          @click="handleChangePassword"
          class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-60 px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-xs transition-all active:scale-[0.98]"
        >
          {{ changingPassword ? 'Đang xử lý...' : 'Đổi mật khẩu' }}
        </button>
      </div>
    </div>

    <template v-if="canManageSettings">
    <!-- 3. NHẬT KÝ HOẠT ĐỘNG -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Nhật ký hoạt động</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Xem lịch sử thêm, sửa, xoá dữ liệu của quản trị viên và chủ trung tâm. Chỉ chủ trung tâm xem được.
        </p>
      </div>

      <div>
        <router-link
          to="/settings/activity-logs"
          class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-700 shadow-2xs transition-all active:scale-[0.98]"
        >
          <ShieldCheck class="h-4 w-4 text-emerald-600" />
          <span>Theo dõi hoạt động của admins</span>
        </router-link>
      </div>
    </div>

    <!-- SAO LƯU DỮ LIỆU -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Sao lưu dữ liệu</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Sao lưu toàn bộ dữ liệu hệ thống trên máy chủ và Google Drive, tải về hoặc khôi phục khi cần. Chỉ chủ trung tâm dùng được.
        </p>
      </div>

      <div>
        <router-link
          to="/settings/backups"
          class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-700 shadow-2xs transition-all active:scale-[0.98]"
        >
          <DatabaseBackup class="h-4 w-4 text-rose-600" />
          <span>Quản lý sao lưu</span>
        </router-link>
      </div>
    </div>

    <!-- 4. THƯƠNG HIỆU -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div>
        <h2 class="text-sm sm:text-base font-bold text-slate-900">Thương hiệu</h2>
        <p class="text-xs text-slate-500 mt-0.5">Quản lý thông tin thương hiệu của bạn.</p>
      </div>

      <div class="space-y-4">
        <!-- Email chủ sở hữu -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Email chủ sở hữu</label>
          <input
            v-model="brand.owner_email"
            type="email"
            disabled
            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 text-xs sm:text-sm text-slate-500 cursor-not-allowed"
          />
        </div>

        <!-- Tên thương hiệu -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Tên thương hiệu</label>
          <input
            v-model="brand.brand_name"
            type="text"
            placeholder="Tên trung tâm của bạn"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          />
        </div>

        <!-- Logo Upload -->
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">Logo</label>
          <div class="flex items-center gap-3">
            <label class="cursor-pointer inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3.5 py-2 text-xs sm:text-sm font-semibold text-slate-700 shadow-2xs transition-all active:scale-[0.98]">
              <Upload class="h-3.5 w-3.5 text-slate-500" />
              <span>Tải logo lên</span>
              <input type="file" accept="image/*" class="hidden" @change="handleLogoUpload" />
            </label>
            <span class="text-[11px] sm:text-xs text-slate-400">
              Ảnh lớn sẽ được tự động nén xuống dưới 2MB. (PNG, JPG...)
            </span>
          </div>
          <div v-if="brand.logo_url" class="mt-2">
            <img :src="brand.logo_url" alt="Brand Logo" class="h-12 w-auto object-contain rounded-lg border border-slate-200 p-1" />
          </div>
        </div>

        <!-- Điện thoại & Địa chỉ -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Điện thoại</label>
            <input
              v-model="brand.phone"
              type="text"
              placeholder="0792324514"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Địa chỉ</label>
            <input
              v-model="brand.address"
              type="text"
              placeholder="Số nhà, đường, quận / huyện, tỉnh / thành phố"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>
        </div>

        <!-- Loại hình & Quy mô học viên -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Loại hình</label>
            <select
              v-model="brand.category"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            >
              <option value="Âm nhạc">Âm nhạc</option>
              <option value="Mỹ thuật">Mỹ thuật</option>
              <option value="Ngoại ngữ">Ngoại ngữ</option>
              <option value="Kỹ năng sống">Kỹ năng sống</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Quy mô học viên</label>
            <select
              v-model="brand.scale"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            >
              <option value="Dưới 50">Dưới 50</option>
              <option value="50 – 100">50 – 100</option>
              <option value="100 – 300">100 – 300</option>
              <option value="Trên 300">Trên 300</option>
            </select>
          </div>
        </div>

        <!-- Quy định nghỉ học hiện ở Cổng phụ huynh -->
        <div>
          <div class="flex items-center justify-between gap-2 mb-1.5">
            <label class="block text-xs font-semibold text-slate-700">Quy định nghỉ học (Cổng phụ huynh)</label>
            <button type="button" @click="brand.portal_leave_rules = defaultLeaveRules"
              class="text-[11px] font-semibold text-rose-600 hover:underline">Dùng nội dung mặc định</button>
          </div>
          <textarea
            v-model="brand.portal_leave_rules"
            rows="4"
            :placeholder="defaultLeaveRules"
            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
          ></textarea>
          <p class="text-[11px] text-slate-400 mt-1">
            Mỗi dòng là một ý. Viết <code>**chữ**</code> để in đậm, <code>{so_buoi_duoc_nghi}</code> để tự điền số buổi được nghỉ của học viên.
            Để trống thì dùng nội dung mặc định.
          </p>
          <div class="mt-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
            <p class="text-[11px] font-semibold text-slate-500 mb-1">Xem trước (học viên được nghỉ 3 buổi):</p>
            <ul class="text-[11px] text-slate-500 space-y-0.5 list-disc pl-4">
              <li v-for="(line, i) in leaveRulesPreview" :key="i" v-html="line"></li>
            </ul>
          </div>
        </div>
      </div>

      <div>
        <button
          type="button"
          :disabled="savingBrand"
          @click="handleSaveBrand"
          class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-60 px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-xs transition-all active:scale-[0.98]"
        >
          {{ savingBrand ? 'Đang lưu...' : 'Lưu thay đổi' }}
        </button>
      </div>
    </div>

    <!-- 5. THÔNG TIN XUẤT HOÁ ĐƠN -->
    <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs space-y-4">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-sm sm:text-base font-bold text-slate-900">Thông tin xuất hoá đơn</h2>
          <p class="text-xs text-slate-500 mt-0.5">Dùng cho việc xuất hoá đơn VAT.</p>
        </div>

        <button
          type="button"
          @click="editingVat = !editingVat"
          class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition-all active:scale-[0.98]"
        >
          <Edit2 class="h-3.5 w-3.5 text-slate-500" />
          <span>{{ editingVat ? 'Đóng' : 'Sửa' }}</span>
        </button>
      </div>

      <!-- View Mode -->
      <div v-if="!editingVat" class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs sm:text-sm text-slate-700 pt-1">
        <div>
          <span class="text-slate-500">Tên công ty:</span>
          <span class="ml-2 font-medium">{{ vat.company_name || '—' }}</span>
        </div>
        <div>
          <span class="text-slate-500">Email gửi hoá đơn:</span>
          <span class="ml-2 font-medium">{{ vat.vat_email || '—' }}</span>
        </div>
        <div>
          <span class="text-slate-500">Mã số thuế:</span>
          <span class="ml-2 font-medium">{{ vat.tax_code || '—' }}</span>
        </div>
        <div>
          <span class="text-slate-500">Địa chỉ thuế:</span>
          <span class="ml-2 font-medium">{{ vat.vat_address || '—' }}</span>
        </div>
      </div>

      <!-- Edit Form Mode -->
      <div v-else class="space-y-4 pt-2">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Tên công ty</label>
            <input
              v-model="vat.company_name"
              type="text"
              placeholder="Tên công ty trên hoá đơn VAT"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Mã số thuế</label>
            <input
              v-model="vat.tax_code"
              type="text"
              placeholder="0312345678"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Email nhận hóa đơn</label>
            <input
              v-model="vat.vat_email"
              type="email"
              placeholder="ketoan@khoaanhmusic.com"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Địa chỉ xuất hóa đơn</label>
            <input
              v-model="vat.vat_address"
              type="text"
              placeholder="Số nhà, đường, quận / huyện, tỉnh / thành phố"
              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
            />
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button
            type="button"
            :disabled="savingVat"
            @click="handleSaveVat"
            class="rounded-xl bg-rose-600 hover:bg-rose-700 disabled:opacity-60 px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-xs transition-all active:scale-[0.98]"
          >
            {{ savingVat ? 'Đang lưu...' : 'Lưu thông tin hoá đơn' }}
          </button>
          <button
            type="button"
            @click="editingVat = false"
            class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-700"
          >
            Hủy
          </button>
        </div>
      </div>
    </div>
    </template>

  </div>
</template>
