<?php
require_once '../config/db.php';

echo "<h2>Kiểm Tra và Tạo Bảng LICH_SU_HOAT_DONG</h2>";

// Check if table exists
try {
    $stmt = $pdo->query("SELECT 1 FROM LICH_SU_HOAT_DONG LIMIT 1");
    echo "<p style='color: green;'><strong>✓ Bảng LICH_SU_HOAT_DONG đã tồn tại</strong></p>";
} catch (Exception $e) {
    echo "<p style='color: orange;'><strong>⚠ Bảng LICH_SU_HOAT_DONG chưa tồn tại, đang tạo...</strong></p>";
    
    // Create the table
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS LICH_SU_HOAT_DONG (
                MaLS INT AUTO_INCREMENT PRIMARY KEY,
                MaTK INT NOT NULL,
                TenNhanVien VARCHAR(100) NOT NULL,
                LoaiHanhDong VARCHAR(50) NOT NULL,
                DoiTuong VARCHAR(100) NOT NULL,
                ChiTiet TEXT,
                ThoiGian DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_matok (MaTK),
                INDEX idx_thoigian (ThoiGian),
                INDEX idx_loaihanhdong (LoaiHanhDong)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "<p style='color: green;'><strong>✓ Bảng LICH_SU_HOAT_DONG đã được tạo thành công</strong></p>";
    } catch (Exception $ex) {
        echo "<p style='color: red;'><strong>✗ Lỗi tạo bảng: " . $ex->getMessage() . "</strong></p>";
        exit;
    }
}

// Check table structure
try {
    $stmt = $pdo->query("DESCRIBE LICH_SU_HOAT_DONG");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Cấu Trúc Bảng:</h3>";
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($col['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>✗ Lỗi kiểm tra cấu trúc: " . $e->getMessage() . "</strong></p>";
}

// Check record count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM LICH_SU_HOAT_DONG");
    $result = $stmt->fetch();
    $total = $result['total'];
    echo "<h3>Dữ Liệu: <strong>" . $total . " bản ghi</strong></h3>";
    
    if ($total > 0) {
        echo "<h3>Chi Tiết Bản Ghi:</h3>";
        $stmt = $pdo->query("SELECT * FROM LICH_SU_HOAT_DONG ORDER BY ThoiGian DESC");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>MaLS</th><th>MaTK</th><th>TenNhanVien</th><th>LoaiHanhDong</th><th>DoiTuong</th><th>ChiTiet</th><th>ThoiGian</th></tr>";
        foreach ($records as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['MaLS']) . "</td>";
            echo "<td>" . htmlspecialchars($row['MaTK']) . "</td>";
            echo "<td>" . htmlspecialchars($row['TenNhanVien']) . "</td>";
            echo "<td>" . htmlspecialchars($row['LoaiHanhDong']) . "</td>";
            echo "<td>" . htmlspecialchars($row['DoiTuong']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($row['ChiTiet'], 0, 50)) . "</td>";
            echo "<td>" . htmlspecialchars($row['ThoiGian']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>✗ Lỗi đếm bản ghi: " . $e->getMessage() . "</strong></p>";
}

// Test insert a sample record
echo "<h3>Test: Thêm Bản Ghi Mẫu</h3>";
try {
    $stmt = $pdo->prepare("
        INSERT INTO LICH_SU_HOAT_DONG (MaTK, TenNhanVien, LoaiHanhDong, DoiTuong, ChiTiet)
        VALUES (?, ?, ?, ?, ?)
    ");
    $result = $stmt->execute([1, 'Tran Thi B', 'Thêm', 'PN: PN00021', 'Ngày: 2025-11-17, Sản phẩm: SP001, SP002']);
    
    if ($result) {
        echo "<p style='color: green;'><strong>✓ Thêm bản ghi thành công</strong></p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>✗ Lỗi thêm bản ghi: " . $e->getMessage() . "</strong></p>";
}

echo "<hr>";
echo "<p><a href='activity_log.php'>← Quay lại trang Lịch Sử Hoạt Động</a></p>";
?>
