-- Tạo lại database mới
CREATE DATABASE IF NOT EXISTS quanlykhotrangsuc CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE quanlykhotrangsuc;

-- Bảng TAIKHOAN
CREATE TABLE IF NOT EXISTS TAIKHOAN (
    MaTK CHAR(7) PRIMARY KEY,
    TenTK VARCHAR(50) NOT NULL,
    MatKhau VARCHAR(50) NOT NULL,
    VaiTro ENUM('Quản lý', 'Nhân viên') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bảng CUAHANG
CREATE TABLE IF NOT EXISTS CUAHANG (
    MaCH CHAR(7) PRIMARY KEY,
    TenCH VARCHAR(100) NOT NULL,
    DiaChi VARCHAR(150) NOT NULL,
    SoDienThoai VARCHAR(15)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bảng SANPHAM
CREATE TABLE IF NOT EXISTS SANPHAM (
    MaSP CHAR(7) PRIMARY KEY,
    TenSP VARCHAR(100) NOT NULL,
    TheLoai ENUM('Vòng tay', 'Vòng cổ', 'Khuyên tai', 'Nhẫn', 'Bông tai') NOT NULL,
    MauSP VARCHAR(50),
    TinhTrang ENUM('Còn hàng', 'Hết hàng', 'Ngừng kinh doanh') DEFAULT 'Còn hàng',
    SLTK INT CHECK (SLTK >= 0),
    GiaBan DECIMAL(12,2) CHECK (GiaBan > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bảng PHIEUNHAP
CREATE TABLE IF NOT EXISTS PHIEUNHAP (
    MaPN CHAR(7) PRIMARY KEY,
    NgayNhap DATE NOT NULL,
    MaTK CHAR(7),
    TinhTrang_PN ENUM('Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi') DEFAULT 'Đang xử lý',
    FOREIGN KEY (MaTK) REFERENCES TAIKHOAN(MaTK)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bảng CHITIETPHIEUNHAP
CREATE TABLE IF NOT EXISTS CHITIETPHIEUNHAP (
    MaCTPN CHAR(7) PRIMARY KEY,
    MaPN CHAR(7),
    MaSP CHAR(7),
    SLN INT CHECK (SLN > 0),
    FOREIGN KEY (MaPN) REFERENCES PHIEUNHAP(MaPN),
    FOREIGN KEY (MaSP) REFERENCES SANPHAM(MaSP)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bảng PHIEUXUAT
CREATE TABLE IF NOT EXISTS PHIEUXUAT (
    MaPX CHAR(7) PRIMARY KEY,
    NgayXuat DATE NOT NULL,
    MaCH CHAR(7),
    MaTK CHAR(7),
    TinhTrang_PX ENUM('Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi') DEFAULT 'Đang xử lý',
    FOREIGN KEY (MaCH) REFERENCES CUAHANG(MaCH),
    FOREIGN KEY (MaTK) REFERENCES TAIKHOAN(MaTK)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bảng CHITIETPHIEUXUAT
CREATE TABLE IF NOT EXISTS CHITIETPHIEUXUAT (
    MaCTPX CHAR(7) PRIMARY KEY,
    MaPX CHAR(7),
    MaSP CHAR(7),
    SLX INT CHECK (SLX > 0),
    FOREIGN KEY (MaPX) REFERENCES PHIEUXUAT(MaPX),
    FOREIGN KEY (MaSP) REFERENCES SANPHAM(MaSP)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dữ liệu mẫu --
-- TÀI KHOẢN --
INSERT INTO TAIKHOAN VALUES
('TK00001','Nguyen Van A','123456','Quản lý'),
('TK00002','Tran Thi B','123456','Nhân viên'),
('TK00003','Le Van C','123456','Nhân viên'),
('TK00004','Pham Thi D','123456','Nhân viên'),
('TK00005','Do Van E','123456','Nhân viên'),
('TK00006','Nguyen Thi F','123456','Nhân viên'),
('TK00007','Bui Van G','123456','Nhân viên'),
('TK00008','Vo Thi H','123456','Nhân viên'),
('TK00009','Pham Van I','123456','Nhân viên'),
('TK00010','Hoang Thi K','123456','Nhân viên');

-- CỬA HÀNG --
INSERT INTO CUAHANG VALUES
('CH00001','Cửa hàng Trang sức Tink Phố Huế','123 Phố Huế, Hai Bà Trưng, Hà Nội','02438761234'),
('CH00002','Cửa hàng Trang sức Tink Hàng Bông','45 Hàng Bông, Hoàn Kiếm, Hà Nội','02439284567'),
('CH00003','Cửa hàng Trang sức Tink Nguyễn Trãi','88 Nguyễn Trãi, Thanh Xuân, Hà Nội','02435678900'),
('CH00004','Cửa hàng Trang sức Tink Cầu Giấy','12 Cầu Giấy, Cầu Giấy, Hà Nội','02432221111'),
('CH00005','Cửa hàng Trang sức Tink La Thành','66 Đê La Thành, Đống Đa, Hà Nội','02433445566'),
('CH00006','Cửa hàng Trang sức Tink Kim Mã','101 Kim Mã, Ba Đình, Hà Nội','02439887766'),
('CH00007','Cửa hàng Trang sức Tink Tràng Tiền','25 Tràng Tiền, Hoàn Kiếm, Hà Nội','02436668888'),
('CH00008','Cửa hàng Trang sức Tink Láng Hạ','88 Láng Hạ, Đống Đa, Hà Nội','02437779999'),
('CH00009','Cửa hàng Trang sức Tink Nguyễn Du','9 Nguyễn Du, Hai Bà Trưng, Hà Nội','02439994444'),
('CH00010','Cửa hàng Trang sức Tink Tôn Đức Thắng','15 Tôn Đức Thắng, Đống Đa, Hà Nội','02434446666');

-- SẢN PHẨM --
INSERT INTO SANPHAM VALUES
('SP00001','Bông tai vàng hồng 14K dáng giọt lệ','Bông tai','Bông tai mẫu 1','Còn hàng',200,2500000),
('SP00002','Bông tai bạc Ý đính đá Swarovski','Bông tai','Bông tai mẫu 2','Còn hàng',300,1800000),
('SP00003','Bông tai ngọc trai tự nhiên cao cấp','Bông tai','Bông tai mẫu 3','Còn hàng',150,3500000),
('SP00004','Vòng cổ vàng trắng 18K mặt trái tim','Vòng cổ','Vòng cổ mẫu 1','Còn hàng',250,5200000),
('SP00005','Vòng cổ bạc Ý mảnh nhẹ đính charm','Vòng cổ','Vòng cổ mẫu 2','Còn hàng',400,2100000),
('SP00006','Vòng cổ bạch kim cao cấp đá CZ','Vòng cổ','Vòng cổ mẫu 3','Còn hàng',180,6800000),
('SP00007','Vòng tay vàng 18K kiểu trơn đơn giản','Vòng tay','Vòng tay mẫu 1','Còn hàng',220,4500000),
('SP00008','Vòng tay bạc đính đá xanh ngọc','Vòng tay','Vòng tay mẫu 2','Còn hàng',350,2300000),
('SP00009','Vòng tay da phong cách unisex','Vòng tay','Vòng tay mẫu 3','Còn hàng',280,1700000),
('SP00010','Nhẫn bạch kim đơn giản nam','Nhẫn','Nhẫn mẫu 1','Còn hàng',200,5100000);

-- PHIẾU NHẬP --
INSERT INTO PHIEUNHAP VALUES
('PN00001','2025-10-01','TK00002','Đang xử lý'),
('PN00002','2025-10-02','TK00003','Đã duyệt'),
('PN00003','2025-10-03','TK00004','Hoàn thành'),
('PN00004','2025-10-04','TK00005','Có thay đổi'),
('PN00005','2025-10-05','TK00006','Bị từ chối'),
('PN00006','2025-10-06','TK00007','Hoàn thành'),
('PN00007','2025-10-07','TK00008','Đang xử lý'),
('PN00008','2025-10-08','TK00009','Đã duyệt'),
('PN00009','2025-10-09','TK00010','Hoàn thành'),
('PN00010','2025-10-10','TK00002','Có thay đổi');

-- CHI TIẾT PHIẾU NHẬP -- 
INSERT INTO CHITIETPHIEUNHAP VALUES
('CTPN001','PN00001','SP00001',100),
('CTPN002','PN00001','SP00002',50),
('CTPN003','PN00002','SP00003',80),
('CTPN004','PN00003','SP00004',120),
('CTPN005','PN00004','SP00005',100),
('CTPN006','PN00005','SP00006',150),
('CTPN007','PN00006','SP00007',60),
('CTPN008','PN00007','SP00008',90),
('CTPN009','PN00008','SP00009',70),
('CTPN010','PN00009','SP00010',50);

-- PHIẾU XUẤT -- 
INSERT INTO PHIEUXUAT VALUES
('PX00001','2025-10-05','CH00001','TK00003','Đang xử lý'),
('PX00002','2025-10-06','CH00002','TK00004','Đã duyệt'),
('PX00003','2025-10-07','CH00003','TK00005','Hoàn thành'),
('PX00004','2025-10-08','CH00004','TK00006','Bị từ chối'),
('PX00005','2025-10-09','CH00005','TK00007','Hoàn thành'),
('PX00006','2025-10-10','CH00006','TK00008','Có thay đổi'),
('PX00007','2025-10-11','CH00007','TK00009','Đang xử lý'),
('PX00008','2025-10-12','CH00008','TK00010','Đã duyệt'),
('PX00009','2025-10-13','CH00009','TK00002','Hoàn thành'),
('PX00010','2025-10-14','CH00010','TK00003','Có thay đổi');

-- CHI TIẾT PHIẾU XUẤT --
INSERT INTO CHITIETPHIEUXUAT VALUES
('CTPX001','PX00001','SP00001',30),
('CTPX002','PX00001','SP00002',20),
('CTPX003','PX00002','SP00003',40),
('CTPX004','PX00003','SP00004',30),
('CTPX005','PX00004','SP00005',50),
('CTPX006','PX00005','SP00006',20),
('CTPX007','PX00006','SP00007',30),
('CTPX008','PX00007','SP00008',40),
('CTPX009','PX00008','SP00009',20),
('CTPX010','PX00009','SP00010',30);

-- Thêm 2 chitietphieunhap cho PN00010 --
INSERT INTO CHITIETPHIEUNHAP (MaCTPN, MaPN, MaSP, SLN) VALUES
('CTPN011', 'PN00010', 'SP00001', 25),
('CTPN012', 'PN00010', 'SP00002', 35);

-- Bổ sung các cột thay đổi cấu trúc bảng (SLN_MOI, SLX_MOI, HinhAnh, ThanhTien)
ALTER TABLE CHITIETPHIEUNHAP ADD COLUMN IF NOT EXISTS SLN_MOI INT AFTER SLN;
ALTER TABLE CHITIETPHIEUXUAT ADD COLUMN IF NOT EXISTS SLX_MOI INT AFTER SLX;

ALTER TABLE SANPHAM ADD COLUMN IF NOT EXISTS HinhAnh VARCHAR(255) NULL DEFAULT NULL COMMENT 'Đường dẫn ảnh sản phẩm' AFTER GiaBan;

-- 1. Thêm cột ThanhTien vào bảng CHITIETPHIEUNHAP
ALTER TABLE CHITIETPHIEUNHAP ADD COLUMN IF NOT EXISTS ThanhTien DECIMAL(15,2) DEFAULT 0 AFTER SLN;

-- 2. Thêm cột ThanhTien vào bảng CHITIETPHIEUXUAT
ALTER TABLE CHITIETPHIEUXUAT ADD COLUMN IF NOT EXISTS ThanhTien DECIMAL(15,2) DEFAULT 0 AFTER SLX;

-- 3. Cập nhật dữ liệu ThanhTien cho các bản ghi hiện có trong CHITIETPHIEUNHAP
UPDATE CHITIETPHIEUNHAP ct
INNER JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
SET ct.ThanhTien = ct.SLN * sp.GiaBan;

-- 4. Cập nhật dữ liệu ThanhTien cho các bản ghi hiện có trong CHITIETPHIEUXUAT
UPDATE CHITIETPHIEUXUAT ct
INNER JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
SET ct.ThanhTien = ct.SLX * sp.GiaBan;

-- Tạo bảng lịch sử hoạt động
CREATE TABLE IF NOT EXISTS LICH_SU_HOAT_DONG (
    MaLS INT AUTO_INCREMENT PRIMARY KEY,
    MaTK CHAR(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    TenNhanVien VARCHAR(100) NOT NULL,
    LoaiHanhDong VARCHAR(50) NOT NULL,
    DoiTuong VARCHAR(100) NOT NULL,
    ChiTiet TEXT,
    ThoiGian DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_matk (MaTK),
    INDEX idx_thoigian (ThoiGian),
    INDEX idx_loaihanhdong (LoaiHanhDong),

    CONSTRAINT fk_ls_tk FOREIGN KEY (MaTK)
        REFERENCES TAIKHOAN(MaTK)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB 
DEFAULT CHARSET=utf8mb4 
COLLATE=utf8mb4_general_ci;

-- Cập nhật hình ảnh mẫu cho 10 sản phẩm đầu tiên từ thư mục uploads/images/ có sẵn
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab10999d4c5.67799620.webp' WHERE MaSP = 'SP00001';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab16b4e8ea6.14824440.jpg' WHERE MaSP = 'SP00002';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab17ede5379.21355088.webp' WHERE MaSP = 'SP00003';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab18900d413.47218105.jpg' WHERE MaSP = 'SP00004';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab191b0f6c8.55140927.jpeg' WHERE MaSP = 'SP00005';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab1a04459c7.57785856.jpg' WHERE MaSP = 'SP00006';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab1aba517b3.66019947.webp' WHERE MaSP = 'SP00007';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab1d90a0cc8.07023555.jpg' WHERE MaSP = 'SP00008';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab31712d8a3.61370080.jpg' WHERE MaSP = 'SP00009';
UPDATE SANPHAM SET HinhAnh = 'uploads/images/product_691ab33274a472.32683562.jpg' WHERE MaSP = 'SP00010';

