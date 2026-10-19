<script setup>
// Ô nhập số tiền: hiển thị có dấu chấm phân cách hàng nghìn (1.000.000) nhưng v-model vẫn là số nguyên
const props = defineProps({
  modelValue: {
    type: [Number, String],
    default: null,
  },
});

const emit = defineEmits(['update:modelValue']);

const format = (value) => {
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '';
  return new Intl.NumberFormat('vi-VN').format(Math.round(Number(value)));
};

const onInput = (e) => {
  const el = e.target;
  const caret = el.selectionStart ?? el.value.length;
  // Số chữ số nằm trước con trỏ, để đặt lại con trỏ đúng chỗ sau khi chèn dấu chấm
  const digitsBeforeCaret = el.value.slice(0, caret).replace(/\D/g, '').length;

  const digits = el.value.replace(/\D/g, '');
  const value = digits === '' ? null : Number(digits);
  const formatted = format(value);
  el.value = formatted;

  let pos = 0;
  for (let seen = 0; pos < formatted.length && seen < digitsBeforeCaret; pos++) {
    if (/\d/.test(formatted[pos])) seen++;
  }
  el.setSelectionRange(pos, pos);

  emit('update:modelValue', value);
};
</script>

<template>
  <input
    type="text"
    inputmode="numeric"
    autocomplete="off"
    :value="format(props.modelValue)"
    @input="onInput"
  />
</template>
