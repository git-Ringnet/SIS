<script setup>
import { ref, computed } from 'vue';
import Modal from './Modal.vue';
import { Copy, Check, ExternalLink, MessageSquareText, Sparkles, X } from 'lucide-vue-next';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  student: {
    type: Object,
    default: null,
  },
  templates: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close']);

const selectedCategory = ref('all');
const copiedId = ref(null);

const categories = [
  { id: 'all', label: 'Tất cả mẫu' },
  { id: 'tuition_reminder', label: 'Nhắc học phí' },
  { id: 'absence_alert', label: 'Cảnh báo vắng' },
  { id: 'payment_confirmation', label: 'Xác nhận đóng phí' },
  { id: 'general_care', label: 'Chăm sóc & Lời khen' },
];

const filteredTemplates = computed(() => {
  if (selectedCategory.value === 'all') return props.templates;
  return props.templates.filter(t => t.category === selectedCategory.value);
});

const formatVND = (val) => {
  if (!val) return 'Liên hệ';
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val);
};

const renderTemplate = (content) => {
  if (!props.student) return content;
  const courseName = props.student.course ? props.student.course.name : 'Khóa học';
  const price = props.student.course ? formatVND(props.student.course.price) : '3.600.000đ';

  return content
    .replace(/{ten_hoc_vien}/g, props.student.name || 'Học viên')
    .replace(/{ten_phu_huynh}/g, props.student.parent_name || 'Phụ huynh')
    .replace(/{so_buoi_con_lai}/g, props.student.remaining_sessions ?? 0)
    .replace(/{khoa_hoc}/g, courseName)
    .replace(/{so_tien}/g, price)
    .replace(/{so_tai_khoan}/g, '1029384756 (HO VIET DUNG)')
    .replace(/{ngan_hang}/g, 'Vietcombank')
    .replace(/{sdt_phu_huynh}/g, props.student.parent_phone || '');
};

const copyToClipboard = async (text, id) => {
  try {
    await navigator.clipboard.writeText(text);
    copiedId.value = id;
    setTimeout(() => {
      copiedId.value = null;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy text: ', err);
  }
};

const getZaloLink = () => {
  if (!props.student || !props.student.parent_phone) return '#';
  const cleanPhone = props.student.parent_phone.replace(/[^0-9]/g, '');
  return `https://zalo.me/${cleanPhone}`;
};
</script>

<template>
  <Modal :show="show" max-width="3xl" @close="emit('close')">
    <div class="p-6">
      <div class="flex items-center justify-between border-b border-slate-100 pb-4">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
            <MessageSquareText class="h-5 w-5" />
          </div>
          <div>
            <h3 class="text-lg font-bold text-slate-900">
              Mẫu Tin Nhắn Tái Đăng Ký & Chăm Sóc
            </h3>
            <p v-if="student" class="text-xs text-slate-500">
              Tự động điền dữ liệu cho bé: <span class="font-semibold text-slate-800">{{ student.name }}</span> (Còn <span class="font-bold text-rose-600">{{ student.remaining_sessions }}</span> buổi)
            </p>
          </div>
        </div>
        <button
          @click="emit('close')"
          class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <div v-if="student" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 text-xs border border-slate-200/80">
        <div class="flex items-center gap-4">
          <div>
            <span class="text-slate-500">Phụ huynh:</span>
            <span class="ml-1 font-semibold text-slate-800">{{ student.parent_name }}</span>
          </div>
          <div>
            <span class="text-slate-500">SĐT:</span>
            <span class="ml-1 font-semibold text-slate-800">{{ student.parent_phone }}</span>
          </div>
          <div>
            <span class="text-slate-500">Khóa học:</span>
            <span class="ml-1 font-semibold text-slate-800">{{ student.course?.name }}</span>
          </div>
        </div>
        <a
          :href="getZaloLink()"
          target="_blank"
          class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 font-medium text-white shadow-xs hover:bg-blue-700 transition-colors"
        >
          <span>Mở Chat Zalo</span>
          <ExternalLink class="h-3.5 w-3.5" />
        </a>
      </div>

      <div class="mt-4 flex flex-wrap gap-1.5">
        <button
          v-for="cat in categories"
          :key="cat.id"
          @click="selectedCategory = cat.id"
          class="rounded-lg px-3 py-1.5 text-xs font-medium transition-all"
          :class="selectedCategory === cat.id ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
        >
          {{ cat.label }}
        </button>
      </div>

      <div class="mt-4 max-h-[420px] space-y-3.5 overflow-y-auto pr-1">
        <div
          v-for="tmpl in filteredTemplates"
          :key="tmpl.id"
          class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs transition-all hover:border-indigo-300 hover:shadow-md"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-2">
              <span class="flex h-2 w-2 rounded-full bg-indigo-500"></span>
              <h4 class="text-sm font-semibold text-slate-800">{{ tmpl.title }}</h4>
            </div>
            <button
              @click="copyToClipboard(renderTemplate(tmpl.template_content), tmpl.id)"
              class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all"
              :class="copiedId === tmpl.id ? 'bg-emerald-600 text-white shadow-xs' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
            >
              <Check v-if="copiedId === tmpl.id" class="h-3.5 w-3.5" />
              <Copy v-else class="h-3.5 w-3.5" />
              <span>{{ copiedId === tmpl.id ? 'Đã Copy Thành Công!' : '1-Click Copy' }}</span>
            </button>
          </div>

          <div class="mt-2.5 rounded-lg bg-slate-50 p-3 font-sans text-xs leading-relaxed text-slate-700 whitespace-pre-line border border-slate-100 select-all">
            {{ renderTemplate(tmpl.template_content) }}
          </div>
        </div>
      </div>

      <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4 text-xs text-slate-500">
        <div class="flex items-center gap-1.5">
          <Sparkles class="h-4 w-4 text-amber-500" />
          <span>Tự động điền tên bé, số buổi còn lại và học phí chính xác.</span>
        </div>
        <button
          @click="emit('close')"
          class="rounded-lg border border-slate-200 bg-white px-4 py-2 font-medium text-slate-700 hover:bg-slate-50"
        >
          Đóng
        </button>
      </div>
    </div>
  </Modal>
</template>
