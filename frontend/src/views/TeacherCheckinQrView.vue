<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import QRCode from 'qrcode';
import { ArrowLeft, Maximize2 } from 'lucide-vue-next';
import { teacherCheckinApi } from '../services/api';
import { notify } from '../services/notify';
import { useBrand } from '../services/useBrand';

// Màn hình đặt ở quầy: mã QR đổi liên tục, giáo viên quét bằng camera điện thoại để check-in ca dạy
const { brand } = useBrand();
const center = ref('');
const centers = ref([]);
const qrDataUrl = ref('');
const secondsLeft = ref(0);
const windowSeconds = ref(30);
const container = ref(null);
let timer = null;

const refreshToken = async () => {
  try {
    const res = await teacherCheckinApi.getQrToken(center.value || undefined);
    center.value = res.data.center;
    centers.value = res.data.centers || [];
    windowSeconds.value = res.data.window_seconds || 30;
    secondsLeft.value = res.data.expires_in;

    const link = `${window.location.origin}/teacher-checkin?qr=${res.data.token}&c=${encodeURIComponent(res.data.center)}`;
    qrDataUrl.value = await QRCode.toDataURL(link, { width: 640, margin: 1, errorCorrectionLevel: 'M' });
  } catch (err) {
    notify.apiError(err, 'Không tạo được mã QR');
  }
};

const tick = () => {
  secondsLeft.value -= 1;
  if (secondsLeft.value <= 0) refreshToken();
};

const changeCenter = () => refreshToken();

const goFullscreen = () => container.value?.requestFullscreen?.();

onMounted(async () => {
  await refreshToken();
  timer = setInterval(tick, 1000);
});

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
  <div class="space-y-4 pb-12 max-w-3xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <router-link
        to="/teacher-checkin"
        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-2xs"
      >
        <ArrowLeft class="h-4 w-4" /> Về trang Check-in
      </router-link>
      <div class="flex items-center gap-2">
        <select
          v-if="centers.length > 1"
          v-model="center"
          @change="changeCenter"
          class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm text-slate-800 focus:border-rose-500 focus:outline-none"
        >
          <option v-for="c in centers" :key="c" :value="c">{{ c }}</option>
        </select>
        <button
          type="button"
          @click="goFullscreen"
          class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-2xs"
        >
          <Maximize2 class="h-4 w-4" /> Toàn màn hình
        </button>
      </div>
    </div>

    <div ref="container" class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-10 shadow-2xs text-center flex flex-col items-center justify-center gap-5">
      <div>
        <h1 class="text-xl sm:text-2xl font-black text-slate-900">Check-in ca dạy</h1>
        <p class="text-sm text-slate-500 mt-1">{{ brand?.brand_name }} &bull; {{ center }}</p>
      </div>

      <img v-if="qrDataUrl" :src="qrDataUrl" alt="Mã QR check-in ca dạy" class="w-72 h-72 sm:w-96 sm:h-96 rounded-xl border border-slate-100" />

      <div class="w-full max-w-sm space-y-1.5">
        <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
          <div
            class="h-full bg-rose-500 transition-all duration-1000 ease-linear"
            :style="{ width: `${Math.max(0, (secondsLeft / windowSeconds) * 100)}%` }"
          ></div>
        </div>
        <p class="text-xs text-slate-500">Mã tự đổi sau <strong class="text-slate-800">{{ Math.max(0, secondsLeft) }} giây</strong></p>
      </div>

      <p class="max-w-md text-sm text-slate-600 leading-relaxed">
        Giáo viên mở <strong>Camera</strong> hoặc <strong>Zalo</strong> trên điện thoại, quét mã này rồi bấm <strong>Check-in</strong> ở ca của mình.
        Mã chỉ dùng được trong khoảng 1 phút nên phải quét trực tiếp tại trung tâm.
      </p>
    </div>
  </div>
</template>
