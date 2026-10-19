import { toast } from 'vue-sonner';

// Lấy thông báo lỗi từ response của API, nếu không có thì dùng câu mặc định
export const errorMessage = (err, fallback = 'Có lỗi xảy ra. Vui lòng thử lại!') =>
  err?.response?.data?.error || err?.response?.data?.message || fallback;

export const notify = {
  success: (message, options) => toast.success(message, options),
  error: (message, options) => toast.error(message, options),
  warning: (message, options) => toast.warning(message, options),
  info: (message, options) => toast.info(message, options),
  // Hiện lỗi từ một exception của axios
  apiError: (err, fallback, options) => toast.error(errorMessage(err, fallback), options),
};
