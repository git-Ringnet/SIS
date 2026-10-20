<script setup>
import { useBrand } from '../services/useBrand';
import BrandLogo from '../components/BrandLogo.vue';
import { ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuth } from '../services/useAuth';
import { Lock, Mail, Eye, EyeOff, LogIn } from 'lucide-vue-next';

const { nameParts } = useBrand();

const router = useRouter();
const route = useRoute();
const { login } = useAuth();

const email = ref('');
const password = ref('');
const showPassword = ref(false);
const error = ref('');
const isLoading = ref(false);

const handleLogin = async () => {
  error.value = '';
  if (!email.value || !password.value) {
    error.value = 'Vui lòng điền đầy đủ email và mật khẩu';
    return;
  }

  try {
    isLoading.value = true;
    await login({
      email: email.value,
      password: password.value,
    });
    // Quay lại trang đang mở trước khi đăng nhập (vd giáo viên quét QR check-in khi chưa đăng nhập)
    const redirect = route.query.redirect;
    router.push(typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/');
  } catch (err) {
    error.value = err.response?.data?.message || 'Đăng nhập không thành công. Vui lòng kiểm tra lại.';
  } finally {
    isLoading.value = false;
  }
};
</script>

<template>
  <div class="min-h-screen bg-[#f8fafc] flex flex-col justify-center py-12 sm:px-6 lg:px-8 antialiased">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <!-- Brand Logo -->
      <div class="flex justify-center">
        <BrandLogo box-class="h-12 w-12 rounded-2xl" icon-class="h-6 w-6" />
      </div>
      <h2 class="mt-4 text-center text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
        {{ nameParts.head }} <span class="text-red-600">{{ nameParts.tail }}</span>
      </h2>
      <p class="mt-1 text-center text-xs sm:text-sm font-semibold text-slate-500">
        Hệ Thống Quản Trị & Giữ Chân Học Viên (EduCRM • SIS)
      </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4 sm:px-0">
      <div class="bg-white py-8 px-5 sm:px-10 shadow-xs border border-slate-200/90 rounded-2xl sm:rounded-3xl">
        <div class="mb-6">
          <h3 class="text-lg font-bold text-slate-900">Đăng nhập tài khoản</h3>
          <p class="text-xs text-slate-500 mt-0.5">Nhập email và mật khẩu của bạn để truy cập hệ thống</p>
        </div>

        <form class="space-y-4" @submit.prevent="handleLogin">
          <!-- Error Alert -->
          <div v-if="error" class="rounded-xl bg-red-50 border border-red-200 p-3 text-xs font-semibold text-red-700 flex items-start gap-2">
            <span>⚠️</span>
            <span>{{ error }}</span>
          </div>

          <!-- Email Field -->
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">
              Email quản trị
            </label>
            <div class="relative">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <Mail class="h-4 w-4" />
              </div>
              <input
                v-model="email"
                type="email"
                required
                placeholder="admin@khoaanhmusic.com"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-9 pr-3.5 py-2.5 text-xs sm:text-sm font-medium text-slate-800 placeholder:text-slate-400 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <!-- Password Field -->
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-xs font-bold text-slate-700">
                Mật khẩu
              </label>
            </div>
            <div class="relative">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <Lock class="h-4 w-4" />
              </div>
              <input
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                required
                placeholder="••••••••"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-9 pr-10 py-2.5 text-xs sm:text-sm font-medium text-slate-800 placeholder:text-slate-400 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
              <button
                type="button"
                @click="showPassword = !showPassword"
                class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"
              >
                <EyeOff v-if="showPassword" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
              </button>
            </div>
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            :disabled="isLoading"
            class="mt-2 flex w-full justify-center items-center gap-2 rounded-xl bg-red-600 py-2.5 px-4 text-xs sm:text-sm font-bold text-white shadow-xs hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-all disabled:opacity-50"
          >
            <LogIn class="h-4 w-4" />
            <span>{{ isLoading ? 'Đang đăng nhập...' : 'Đăng nhập hệ thống' }}</span>
          </button>
        </form>
      </div>
    </div>
  </div>
</template>
