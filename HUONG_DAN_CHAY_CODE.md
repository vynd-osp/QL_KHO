# HƯỚNG DẪN CẤU HÌNH VÀ CHẠY DỰ ÁN QLKHO

Tài liệu này hướng dẫn chi tiết các bước thiết lập cơ sở dữ liệu (CSDL), chạy ứng dụng web và quy trình phát triển chức năng cho hệ thống quản lý kho trang sức.

---

## 🛠️ PHẦN I: HƯỚNG DẪN CÀI ĐẶT & CHẠY DỰ ÁN

### **Bước 1: Cài đặt và Mở XAMPP**

* Tải xuống và cài đặt phần mềm **XAMPP** (nếu chưa có).
* Mở ứng dụng **XAMPP Control Panel**.

### **Bước 2: Khởi động các Dịch vụ**

* Nhấn nút **Start** tại 2 dịch vụ **Apache** và **MySQL**.
* Đảm bảo màu trạng thái của chúng chuyển sang màu xanh lá.

### **Bước 3: Truy cập phpMyAdmin**

* Mở trình duyệt web và truy cập đường dẫn: [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/)

### **Bước 4: Tạo và Nhập Cơ Sở Dữ Liệu (CSDL)**

1. Tại thanh menu bên trái phpMyAdmin, bấm chọn **Mới** (New).
2. Điền tên CSDL là: `quanlykhotrangsuc` rồi ấn nút **Tạo** (Create).
3. Bấm chọn CSDL `quanlykhotrangsuc` vừa tạo ở danh sách bên trái.
4. Chọn tab **SQL** (mục thứ 2 trên thanh công cụ ngang).
5. Mở file [quanlykhotrangsuc.sql](file:///c:/xampp/htdocs/N17TKWEB_QLKHO/quanlykhotrangsuc.sql) trong thư mục dự án, copy toàn bộ nội dung của file và dán vào ô trống nhập liệu SQL.
6. Cuộn xuống và nhấn nút **Thực hiện** (Go) ở góc phải dưới.
7. Chờ hệ thống xử lý để hoàn thành việc tạo cấu trúc bảng và chèn dữ liệu mẫu.

### **Bước 5: Đặt Thư Mục Code vào htdocs**

* Sao chép hoặc di chuyển thư mục dự án `N17TKWEB_QLKHO` vào thư mục gốc của XAMPP:
  `C:\xampp\htdocs\N17TKWEB_QLKHO`

### **Bước 6: Truy cập và Kiểm tra Giao diện**

* Nhập đường link sau lên trình duyệt: [http://localhost/N17TKWEB_QLKHO/login.php](http://localhost/N17TKWEB_QLKHO/login.php)
* **Thông tin đăng nhập mẫu (từ bảng `TAIKHOAN`):**
  * **Tài khoản:** `TK00001` (Quản lý) hoặc `TK00002` (Nhân viên)
  * **Mật khẩu:** `123456`
* Sau khi đăng nhập thành công, bạn sẽ được chuyển hướng trực tiếp đến trang chủ quản lý/dashboard.
