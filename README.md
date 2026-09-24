# EduCRM SIS - Hệ Thống SaaS Quản Lý Trung Tâm Đào Tạo & Giữ Chân Học Viên (Retention)

Hệ thống chuyên biệt dành cho Admin & Giáo viên tại các trung tâm Âm nhạc, Mỹ thuật, Ngoại ngữ. Tập trung vào tự động hóa quy trình giữ chân học viên, theo dõi số buổi học, cảnh báo học phí và quản lý dòng tiền.

---

## 📁 Cấu Trúc Thư Mục Dự Án (Clean Decoupled Architecture)

```text
SIS/
├── backend/                  <-- Laravel 12 REST API Server
│   ├── app/
│   │   ├── Http/Controllers/Api/   # REST API Controllers (Dashboard, Students, Retention, Leads, Invoices, Courses)
│   │   └── Models/                 # Eloquent Models (Student, Attendance, RetentionLog, Course, Invoice, Lead...)
│   ├── config/cors.php             # Cấu hình CORS cho kết nối Frontend
│   ├── database/                   # Migrations & Seeders với dữ liệu mẫu Việt Nam thực tế
│   ├── routes/api.php              # RESTful API Endpoints
│   ├── composer.json
│   └── artisan
│
└── frontend/                 <-- Vue 3 Standalone SPA Application
    ├── src/
    │   ├── components/       # Reusable components (StateButtons, AttendanceButton, Modals, Badges)
    │   ├── views/            # DashboardView, RetentionView, StudentsView, StudentDetailView, LeadsView...
    │   ├── router/           # Vue Router SPA navigation
    │   └── services/api.js   # Axios Client kết nối trực tiếp Backend API
    ├── package.json          # Vue 3, Vue Router, Tailwind CSS v4, Lucide Icons, Axios
    └── vite.config.js
```

---

## 🚀 Hướng Dẫn Khởi Chạy Hệ Thống

### 1. Khởi chạy Backend (Laravel API):
```powershell
cd backend
php artisan serve
```
* Backend API hoạt động tại: `http://127.0.0.1:8000/api`

### 2. Khởi chạy Frontend (Vue 3 SPA):
```powershell
cd frontend
npm install   # (nếu chưa cài)
npm run dev
```
* Giao diện người dùng hoạt động tại: `http://localhost:5173`

---

## ⚡ Các Tính Năng Trọng Tâm

1. **Khối Cảnh Báo & Tái Đăng Ký (Retention Checklist):**
   - Lọc học viên sắp hết buổi ($\le 5$ buổi).
   - **Dãy Nút Trạng Thái:**
     - 🔵 `[Hóa đơn]`: Đã gửi thông báo học phí.
     - 🟠 `[Chăm sóc]`: Đang tư vấn, follow-up phụ huynh.
     - 🟢 `[Đã đóng]`: Xác nhận tiền về, tự động cộng thêm số buổi và tạo phiếu thu.
     - 💬 `[Mẫu tin nhắn]`: Popup mẫu tin nhắn Zalo/SMS soạn sẵn, tự động thay tên bé, số buổi còn lại, STK với 1-Click Copy và Mở Zalo.
     - 📝 `[Ghi chú]`: Lưu log trao đổi và hẹn ngày gọi lại.

2. **Quản Lý Học Viên & Điểm Danh 1-Click:**
   - Điểm danh trừ 1 buổi tức thì với **Optimistic UI** (0ms delay) và hỗ trợ **Hoàn tác (Undo)** trong 4 giây.
   - Quản lý cảnh báo học viên vắng học liên tiếp ($\ge 2$ buổi).

3. **Pipeline Khách Hàng Tiềm Năng (Lead CRM):**
   - Kanban Board: *Mới tiếp nhận $\rightarrow$ Đã gọi $\rightarrow$ Gửi báo giá $\rightarrow$ Đã học thử $\rightarrow$ Đăng ký*.
   - 1-Click chuyển đổi thành Học viên chính thức.

4. **Doanh Thu & Khóa Học:**
   - Báo cáo tài chính theo ngày/tháng, quản lý phiếu thu học phí và bảng giá khóa học.
