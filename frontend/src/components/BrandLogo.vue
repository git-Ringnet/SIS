<script setup>
import { Music } from 'lucide-vue-next';
import { useBrand } from '../services/useBrand';

defineProps({
  // Class kích thước / bo góc của khung logo, ví dụ "h-9 w-9 rounded-xl"
  boxClass: {
    type: String,
    default: 'h-9 w-9 rounded-xl',
  },
  iconClass: {
    type: String,
    default: 'h-5 w-5',
  },
});

const { brand, brandName } = useBrand();
</script>

<template>
  <img
    v-if="brand.logo_url"
    :src="brand.logo_url"
    :alt="brandName"
    :class="[boxClass, 'shrink-0 object-contain bg-white']"
  />
  <div
    v-else
    :class="[boxClass, 'flex items-center justify-center bg-gradient-to-tr from-rose-500 via-rose-600 to-red-600 text-white shadow-sm shadow-rose-600/20 shrink-0']"
  >
    <Music :class="iconClass" />
  </div>
</template>
