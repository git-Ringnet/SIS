<script setup>
import { ref, watch, onBeforeUnmount, nextTick } from 'vue';
import jsQR from 'jsqr';
import Modal from './Modal.vue';

// Quét mã QR bằng camera sau của điện thoại (giáo viên quét mã check-in đang hiện ở quầy trung tâm).
// Đọc từng khung hình video bằng jsQR nên chạy được cả trên iPhone (Safari không có BarcodeDetector).
// Trình duyệt chỉ cho mở camera trên HTTPS hoặc localhost.
const props = defineProps({
  show: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'scanned']);

const video = ref(null);
const error = ref('');
const starting = ref(false);
let stream = null;
let frame = null;
const canvas = document.createElement('canvas');
const ctx = canvas.getContext('2d', { willReadFrequently: true });

const stop = () => {
  cancelAnimationFrame(frame);
  frame = null;
  stream?.getTracks().forEach((t) => t.stop());
  stream = null;
};

const scan = () => {
  const v = video.value;
  if (!v || !stream) return;
  if (v.readyState >= v.HAVE_ENOUGH_DATA && v.videoWidth) {
    // Thu nhỏ khung hình cho nhẹ máy, vẫn đủ để đọc mã QR trên màn hình quầy
    const scale = Math.min(1, 640 / v.videoWidth);
    canvas.width = Math.round(v.videoWidth * scale);
    canvas.height = Math.round(v.videoHeight * scale);
    ctx.drawImage(v, 0, 0, canvas.width, canvas.height);
    const code = jsQR(ctx.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height, {
      inversionAttempts: 'dontInvert',
    });
    if (code?.data) {
      stop();
      emit('scanned', code.data);
      return;
    }
  }
  frame = requestAnimationFrame(scan);
};

const start = async () => {
  error.value = '';
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    error.value = 'Trình duyệt không cho mở camera trên trang này (cần mở hệ thống bằng địa chỉ https). Hãy dùng app Camera hoặc Zalo để quét mã.';
    return;
  }
  try {
    starting.value = true;
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
    await nextTick();
    video.value.srcObject = stream;
    await video.value.play();
    frame = requestAnimationFrame(scan);
  } catch (err) {
    stop();
    error.value = err?.name === 'NotAllowedError'
      ? 'Bạn chưa cho phép dùng camera. Hãy bật quyền Camera cho trang này trong cài đặt trình duyệt rồi thử lại.'
      : 'Không mở được camera trên thiết bị này. Hãy dùng app Camera hoặc Zalo để quét mã.';
  } finally {
    starting.value = false;
  }
};

watch(() => props.show, (show) => (show ? start() : stop()));
onBeforeUnmount(stop);

const close = () => {
  stop();
  emit('close');
};
</script>

<template>
  <Modal :show="show" max-width="md" title="Quét mã QR check-in" @close="close">
    <div class="space-y-3">
      <p class="text-xs text-slate-500">Hướng camera vào <strong class="text-slate-700">mã QR đang hiện ở quầy</strong> của trung tâm.</p>

      <div v-if="!error" class="relative overflow-hidden rounded-2xl bg-black aspect-square">
        <video ref="video" class="h-full w-full object-cover" playsinline muted></video>
        <!-- Khung ngắm -->
        <div class="pointer-events-none absolute inset-[12%] rounded-2xl border-4 border-white/80"></div>
        <p v-if="starting" class="absolute inset-0 flex items-center justify-center text-sm text-white/80">Đang mở camera...</p>
      </div>

      <p v-else class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">{{ error }}</p>

      <div class="flex justify-end gap-2">
        <button v-if="error" type="button" @click="start"
          class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700">
          Thử lại
        </button>
        <button type="button" @click="close"
          class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700">
          Đóng
        </button>
      </div>
    </div>
  </Modal>
</template>
