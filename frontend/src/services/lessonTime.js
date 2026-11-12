// Giờ buổi học lẻ / học bù chọn bằng ô chọn giờ (không gõ tay để tránh nhập sai như "128:00").

// "18:00" + 1 tiếng → "19:00" (qua nửa đêm thì dừng ở 23:59)
export const plusOneHour = (start) => {
  const [h, m] = String(start || '').split(':').map(Number);
  if (Number.isNaN(h) || Number.isNaN(m)) return '';
  return h >= 23 ? '23:59' : `${String(h + 1).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
};

// Chuỗi giờ gửi lên server: "18:00 - 19:00" (thiếu giờ kết thúc thì chỉ giờ bắt đầu)
export const lessonTimeLabel = (start, end) => (start ? (end ? `${start} - ${end}` : start) : '');

// Chọn giờ bắt đầu: giờ kết thúc trống hoặc không sau giờ bắt đầu thì tự đặt +1 tiếng
export const syncEndTime = (form) => {
  if (form.time_start && (!form.time_end || form.time_end <= form.time_start)) {
    form.time_end = plusOneHour(form.time_start);
  }
};
