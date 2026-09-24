<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../services/useAuth';
import { Music, Lock, Mail, User, Eye, EyeOff, UserPlus, ArrowRight } from 'lucide-vue-next';

const router = useRouter();
const { register } = useAuth();

const name = ref('');
const email = ref('');
const password = ref('');
const password_confirmation = ref('');
const showPassword = ref(false);
const error = ref('');
const isLoading = ref(false);

const handleRegister = async () => {
  error.value = '';
  if (!name.value || !email.value || !password.value) {
    error.value = 'Vui lòng điền đầy đủ tất cả các trường';
    return;
  }

  if (password.value !== password_confirmation.value) {
    error.value = 'Mật khẩu xác nhận không khớp';
    return;
  }

  try {
    isLoading.value = true;
    await register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: password_confirmation.value,
    });
    router.push('/');
  } catch (err) {
    error.value = err.response?.data?.message || 'Đăng ký không thành công. Vui lòng thử lại.';
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
        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-red-600 text-white shadow-md shadow-red-500/20">
          <Music class="h-6 w-6" />
        </div>
      </div>
      <h2 class="mt-4 text-center text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
        Khoa Anh <span class="text-red-600">Music</span>
      </h2>
      <p class="mt-1 text-center text-xs sm:text-sm font-semibold text-slate-500">
        Tạo tài khoản quản trị viên trung tâm mới
      </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4 sm:px-0">
      <div class="bg-white py-8 px-5 sm:px-10 shadow-xs border border-slate-200/90 rounded-2xl sm:rounded-3xl">
        <div class="mb-6">
          <h3 class="text-lg font-bold text-slate-900">Đăng ký tài khoản</h3>
          <p class="text-xs text-slate-500 mt-0.5">Điền thông tin để tạo tài khoản vận hành hệ thống</p>
        </div>

        <form class="space-y-4" @submit.prevent="handleRegister">
          <!-- Error Alert -->
          <div v-if="error" class="rounded-xl bg-red-50 border border-red-200 p-3 text-xs font-semibold text-red-700 flex items-start gap-2">
            <span>⚠️</span>
            <span>{{ error }}</span>
          </div>

          <!-- Name Field -->
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">
              Họ và tên
            </label>
            <div class="relative">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <User class="h-4 w-4" />
              </div>
              <input
                v-model="name"
                type="text"
                required
                placeholder="Nguyễn Văn A"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-9 pr-3.5 py-2.5 text-xs sm:text-sm font-medium text-slate-800 placeholder:text-slate-400 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <!-- Email Field -->
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">
              Email
            </label>
            <div class="relative">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <Mail class="h-4 w-4" />
              </div>
              <input
                v-model="email"
                type="email"
                required
                placeholder="teacher@khoaanhmusic.com"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-9 pr-3.5 py-2.5 text-xs sm:text-sm font-medium text-slate-800 placeholder:text-slate-400 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <!-- Password Field -->
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">
              Mật khẩu (tối thiểu 6 ký tự)
            </label>
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

          <!-- Password Confirmation Field -->
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">
              Xác nhận mật khẩu
            </label>
            <div class="relative">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <Lock class="h-4 w-4" />
              </div>
              <input
                v-model="password_confirmation"
                :type="showPassword ? 'text' : 'password'"
                required
                placeholder="••••••••"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-9 pr-3.5 py-2.5 text-xs sm:text-sm font-medium text-slate-800 placeholder:text-slate-400 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500 transition-all"
              />
            </div>
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            :disabled="isLoading"
            class="mt-2 flex w-full justify-center items-center gap-2 rounded-xl bg-red-600 py-2.5 px-4 text-xs sm:text-sm font-bold text-white shadow-xs hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-all disabled:opacity-50"
          >
            <UserPlus class="h-4 w-4" />
            <span>{{ isLoading ? 'Đang tạo tài khoản...' : 'Hoàn tất đăng ký' }}</span>
          </button>
        </form>

        <!-- Link to Login -->
        <div class="mt-6 text-center">
          <p class="text-xs text-slate-500">
            Đã có tài khoản?
            <router-link to="/login" class="font-bold text-red-600 hover:text-red-700 underline ml-1">
              Đăng nhập ngay
            </router-link>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
