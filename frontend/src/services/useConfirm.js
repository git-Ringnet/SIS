import { reactive } from 'vue';

// Trạng thái dùng chung cho một hộp thoại xác nhận duy nhất được gắn ở App.vue
export const confirmState = reactive({
  open: false,
  title: '',
  message: '',
  confirmText: 'Xác nhận',
  cancelText: 'Huỷ',
  variant: 'danger', // danger | warning | primary
  resolve: null,
});

/**
 * Mở hộp thoại xác nhận, trả về Promise<boolean>.
 *
 *   if (!(await confirmDialog({ title: 'Xoá học viên?', message: '...' }))) return;
 */
export const confirmDialog = (options = {}) => {
  // Đóng hộp thoại cũ (nếu có) như khi bấm Huỷ
  confirmState.resolve?.(false);

  Object.assign(confirmState, {
    title: 'Bạn có chắc chắn?',
    message: '',
    confirmText: 'Xác nhận',
    cancelText: 'Huỷ',
    variant: 'danger',
    ...options,
    open: true,
  });

  return new Promise((resolve) => {
    confirmState.resolve = resolve;
  });
};

export const settleConfirm = (result) => {
  const resolve = confirmState.resolve;
  confirmState.open = false;
  confirmState.resolve = null;
  resolve?.(result);
};
