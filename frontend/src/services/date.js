const pad = (n) => String(n).padStart(2, '0');

// Ngày dạng YYYY-MM-DD theo giờ máy (toISOString() dùng giờ UTC nên 0h–7h sáng ở VN sẽ ra ngày hôm trước)
export const toLocalDateString = (date = new Date()) =>
  `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

// Tháng dạng YYYY-MM theo giờ máy
export const toLocalMonthString = (date = new Date()) =>
  `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;

export const addDays = (days, date = new Date()) => {
  const d = new Date(date);
  d.setDate(d.getDate() + days);
  return d;
};
