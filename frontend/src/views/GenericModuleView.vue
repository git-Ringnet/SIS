<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import {
  CalendarCheck,
  Clock,
  UserX,
  DollarSign,
  Package,
  Cake,
  UserMinus,
  Building2,
  Briefcase,
  Settings,
  Database,
  BarChart3,
  Layers,
  Sparkles,
} from 'lucide-vue-next';

const route = useRoute();

const modulesConfig = {
  '/attendance': {
    title: 'Quản Lý Điểm Danh',
    subtitle: 'Theo dõi điểm danh học viên theo từng lớp học, khóa học và ca học trong ngày.',
    icon: CalendarCheck,
    tag: 'Chính',
    desc: 'Bảng điểm danh hỗ trợ điểm danh nhanh, đánh dấu vắng có phép/không phép và tự động trừ số buổi học.',
  },
  '/teacher-checkin': {
    title: 'Check-in Ca Dạy',
    subtitle: 'Theo dõi lịch dạy, chấm công giáo viên và giảng viên đứng lớp.',
    icon: Clock,
    tag: 'Chính',
    desc: 'Giáo viên quét mã QR hoặc check-in đầu ca dạy. Tự động tính giờ dạy và hỗ trợ tính lương cuối tháng.',
  },
  '/absences': {
    title: 'Cảnh Báo Vắng Liên Tiếp',
    subtitle: 'Danh sách các học viên nghỉ học liên tiếp từ 2 buổi trở lên cần chăm sóc đặc biệt.',
    icon: UserX,
    tag: 'Cảnh báo',
    desc: 'Kích hoạt kịch bản nhắn tin Zalo / gọi điện hỏi thăm lý do vắng để kịp thời giải quyết khó khăn của học viên.',
  },
  '/revenue': {
    title: 'Báo Cáo Doanh Thu',
    subtitle: 'Tổng hợp dòng tiền thực thu, doanh số khóa học, học cụ và công nợ theo thời gian thực.',
    icon: DollarSign,
    tag: 'Tài chính',
    desc: 'Phân tích doanh thu theo từng tháng/năm, so sánh tốc độ tăng trưởng và dự báo doanh thu chu kỳ tới.',
  },
  '/inventory': {
    title: 'Quản Lý Học Cụ & Giáo Trình',
    subtitle: 'Quản lý kho nhạc cụ, sách vở, màu vẽ, đàn và đồng phục trung tâm.',
    icon: Package,
    tag: 'Vận hành',
    desc: 'Theo dõi số lượng tồn kho học cụ, nhập xuất kho và bán kèm khi học viên đăng ký khóa học mới.',
  },
  '/birthdays': {
    title: 'Sinh Nhật Học Viên & Giảng Viên',
    subtitle: 'Danh sách sinh nhật trong tháng để gửi tin nhắn chúc mừng và voucher quà tặng.',
    icon: Cake,
    tag: 'Chăm sóc',
    desc: 'Tự động nhắc nhở sinh nhật trước 3 ngày để chuẩn bị quà hoặc gửi tin nhắn SMS/Zalo tri ân thương hiệu.',
  },
  '/inactive-students': {
    title: 'Học Viên Đã Nghỉ',
    subtitle: 'Lưu trữ hồ sơ học viên đã hoàn thành khóa học hoặc tạm ngưng học lâu ngày.',
    icon: UserMinus,
    tag: 'Lưu trữ',
    desc: 'Kịch bản Remarketing & gửi ưu đãi tái kích hoạt (Reactivation campaign) đối với học viên cũ.',
  },
  '/centers': {
    title: 'Quản Lý Trung Tâm & Chi Nhánh',
    subtitle: 'Cấu hình danh sách cơ sở, phòng học, trang thiết bị tại các điểm đào tạo.',
    icon: Building2,
    tag: 'Cấu hình',
    desc: 'Quản lý đồng thời nhiều chi nhánh: Chi nhánh 1, Chi nhánh 2, phân quyền quản lý theo cơ sở.',
  },
  '/staff': {
    title: 'Nhân Sự & Giảng Viên',
    subtitle: 'Danh sách đội ngũ giáo viên, tư vấn viên, quản trị viên và bảng phân quyền.',
    icon: Briefcase,
    tag: 'Nhân sự',
    desc: 'Quản lý hồ sơ giáo viên, chuyên môn giảng dạy, hợp đồng và tỷ lệ chia sẻ thù lao đứng lớp.',
  },
  '/settings': {
    title: 'Cài Đặt Hệ Thống',
    subtitle: 'Thiết lập thông tin trung tâm, mẫu tin nhắn Zalo ZNS, SMS Brandname và chu kỳ Retention.',
    icon: Settings,
    tag: 'Cài đặt',
    desc: 'Tùy chỉnh ngưỡng cảnh báo sắp hết buổi (3, 5, 8 buổi), tích hợp cổng thanh toán VietQR & Zalo OA.',
  },
  '/data': {
    title: 'Dữ Liệu & Import / Export',
    subtitle: 'Nhập xuất dữ liệu học viên từ file Excel, sao lưu và đồng bộ hóa đám mây an toàn.',
    icon: Database,
    tag: 'Hệ thống',
    desc: 'Hỗ trợ Import danh sách học viên hàng loạt từ Excel và xuất báo cáo tài chính định dạng .xlsx.',
  },
  '/reports': {
    title: 'Báo Cáo Thống Kê & Phân Tích',
    subtitle: 'Báo cáo tỷ lệ Retention (tỷ lệ tái đăng ký), tỷ lệ đi học, chi phí marketing trên từng học viên.',
    icon: BarChart3,
    tag: 'Phân tích',
    desc: 'Biểu đồ trực quan hóa dữ liệu kinh doanh giúp chủ trung tâm ra quyết định mở lớp chính xác.',
  },
};

const currentConfig = computed(() => {
  return (
    modulesConfig[route.path] || {
      title: 'Phân Hệ Quản Lý',
      subtitle: 'Chức năng đang được tích hợp vào hệ thống EduCRM.',
      icon: Layers,
      tag: 'Tính năng',
      desc: 'Hệ thống đang sẵn sàng xử lý dữ liệu của phân hệ này.',
    }
  );
});
</script>

<template>
  <div class="space-y-6 w-full">
    <!-- Header banner -->
    <div class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 shadow-xs">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-start gap-4">
          <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-red-50 text-red-600 ring-1 ring-red-200">
            <component :is="currentConfig.icon" class="h-6 w-6" />
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900">
                {{ currentConfig.title }}
              </h1>
              <span class="rounded-full bg-red-50 border border-red-200 px-2.5 py-0.5 text-[10px] font-bold text-red-700">
                {{ currentConfig.tag }}
              </span>
            </div>
            <p class="text-xs sm:text-sm text-slate-500 mt-1 max-w-2xl leading-relaxed">
              {{ currentConfig.subtitle }}
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <router-link
            to="/students"
            class="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 hover:text-red-600 shadow-2xs transition-colors"
          >
            Xem danh sách học viên
          </router-link>
          <router-link
            to="/retention"
            class="rounded-xl bg-red-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-red-700 shadow-xs transition-colors"
          >
            Quy trình giữ chân
          </router-link>
        </div>
      </div>
    </div>

    <!-- Feature Content Preview -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
      <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs space-y-3">
        <div class="flex items-center gap-2 text-red-600 font-bold text-sm">
          <Sparkles class="h-4 w-4" />
          <span>Tổng quan tính năng</span>
        </div>
        <p class="text-xs text-slate-600 leading-relaxed">
          {{ currentConfig.desc }}
        </p>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs space-y-3">
        <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
          <span>Trạng thái kết nối</span>
        </div>
        <div class="flex items-center gap-2 text-xs text-emerald-600 font-semibold">
          <span class="h-2 w-2 rounded-full bg-emerald-500 animate-ping"></span>
          <span>Hệ thống đồng bộ API thời gian thực</span>
        </div>
        <p class="text-xs text-slate-500">
          Kết nối trực tiếp tới cơ sở dữ liệu MySQL <code class="text-red-600 font-mono">sis_crm</code>.
        </p>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs space-y-3">
        <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
          <span>Truy cập nhanh</span>
        </div>
        <div class="flex flex-wrap gap-2">
          <router-link to="/" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700">
            Bảng điều khiển
          </router-link>
          <router-link to="/invoices" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700">
            Hóa đơn & Công nợ
          </router-link>
          <router-link to="/courses" class="rounded-lg bg-slate-100 hover:bg-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700">
            Khóa học
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>
