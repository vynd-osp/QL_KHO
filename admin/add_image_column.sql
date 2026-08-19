-- Script để thêm cột HinhAnh vào bảng SANPHAM
-- Chạy script này trong phpMyAdmin hoặc MySQL command line

ALTER TABLE SANPHAM 
ADD COLUMN HinhAnh VARCHAR(255) NULL DEFAULT NULL 
COMMENT 'Đường dẫn ảnh sản phẩm' 
AFTER GiaBan;

