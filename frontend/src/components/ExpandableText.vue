<script setup>
import { computed, ref } from 'vue';

// Văn bản nhiều dòng (nhận xét / báo bài): dài thì chỉ hiện vài dòng đầu, có nút Xem thêm / Thu gọn
const props = defineProps({
  text: { type: String, default: '' },
  // Số dòng hiện khi thu gọn
  lines: { type: Number, default: 3 },
});

const expanded = ref(false);

// Dài khi nhiều dòng hơn số dòng thu gọn, hoặc một dòng rất dài (tràn xuống nhiều dòng trên điện thoại)
const isLong = computed(() => {
  const rows = (props.text || '').split('\n');
  return rows.length > props.lines || (props.text || '').length > props.lines * 80;
});
</script>

<template>
  <div>
    <p
      class="whitespace-pre-line break-words"
      :style="isLong && !expanded ? { display: '-webkit-box', WebkitBoxOrient: 'vertical', WebkitLineClamp: lines, overflow: 'hidden' } : {}"
    >{{ text }}</p>
    <button
      v-if="isLong"
      type="button"
      @click="expanded = !expanded"
      class="mt-0.5 text-[11px] font-bold not-italic text-blue-600 hover:underline cursor-pointer"
    >
      {{ expanded ? 'Thu gọn' : 'Xem thêm' }}
    </button>
  </div>
</template>
