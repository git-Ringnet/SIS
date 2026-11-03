<script setup>
import { computed, ref } from 'vue';

// Văn bản nhiều dòng (nhận xét / báo bài): dài thì chỉ hiện vài dòng đầu, có nút Xem thêm / Thu gọn.
// Đường link trong nội dung (https://..., www...) hiện màu khác và bấm được (mở tab mới).
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

// Tách chữ thường / đường link. Không dùng v-html: nội dung giáo viên gõ luôn hiện dạng chữ, chỉ link mới thành thẻ <a>.
const URL_RE = /(https?:\/\/[^\s<>"']+|www\.[^\s<>"']+)/gi;
const parts = computed(() => {
  const text = props.text || '';
  const result = [];
  let last = 0;
  for (const m of text.matchAll(URL_RE)) {
    let url = m[0];
    // Bỏ dấu câu dính ở cuối link (vd "xem tại https://abc.com." hoặc "(https://abc.com)")
    const trail = url.match(/[.,;:!?)\]}]+$/);
    if (trail) url = url.slice(0, -trail[0].length);
    if (m.index > last) result.push({ text: text.slice(last, m.index) });
    result.push({ text: url, href: /^https?:\/\//i.test(url) ? url : `https://${url}` });
    last = m.index + url.length;
  }
  if (last < text.length) result.push({ text: text.slice(last) });
  return result;
});
</script>

<template>
  <div>
    <p
      class="whitespace-pre-line break-words"
      :style="isLong && !expanded ? { display: '-webkit-box', WebkitBoxOrient: 'vertical', WebkitLineClamp: lines, overflow: 'hidden' } : {}"
    ><template v-for="(part, i) in parts" :key="i"><a
      v-if="part.href"
      :href="part.href"
      target="_blank"
      rel="noopener noreferrer"
      class="font-semibold text-blue-600 underline decoration-blue-300 underline-offset-2 break-all hover:text-blue-800"
      @click.stop
    >{{ part.text }}</a><template v-else>{{ part.text }}</template></template></p>
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
