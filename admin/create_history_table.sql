-- Tạo bảng lịch sử hoạt động
CREATE TABLE IF NOT EXISTS LICH_SU_HOAT_DONG (
    MaLS INT AUTO_INCREMENT PRIMARY KEY,
    MaTK INT NOT NULL,
    TenNhanVien VARCHAR(100) NOT NULL,
    LoaiHanhDong VARCHAR(50) NOT NULL,
    DoiTuong VARCHAR(100) NOT NULL,
    ChiTiet TEXT,
    ThoiGian DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaTK) REFERENCES TAIKHOAN(MaTK),
    INDEX idx_matok (MaTK),
    INDEX idx_thoigian (ThoiGian),
    INDEX idx_loaihanhdong (LoaiHanhDong)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
