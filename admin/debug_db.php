<?php
require_once '../config/db.php';

// Check database connection
if (!$pdo) {
    die("Database connection failed");
}

// Check LICH_SU_HOAT_DONG table exists
try {
    $stmt = $pdo->query("DESCRIBE LICH_SU_HOAT_DONG");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>LICH_SU_HOAT_DONG Table Columns:</h3>";
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error describing table: " . $e->getMessage();
}

// Check total records
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM LICH_SU_HOAT_DONG");
    $result = $stmt->fetch();
    echo "<h3>Total Records in LICH_SU_HOAT_DONG: " . $result['total'] . "</h3>";
} catch (Exception $e) {
    echo "Error counting records: " . $e->getMessage();
}

// Check sample records
try {
    $stmt = $pdo->query("SELECT * FROM LICH_SU_HOAT_DONG ORDER BY ThoiGian DESC LIMIT 5");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Sample Records:</h3>";
    echo "<pre>";
    print_r($records);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error fetching records: " . $e->getMessage();
}

// Check all unique roles
try {
    $stmt = $pdo->query("SELECT DISTINCT MaTK, TenNhanVien FROM LICH_SU_HOAT_DONG");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Users in Activity Log:</h3>";
    echo "<pre>";
    print_r($users);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error fetching users: " . $e->getMessage();
}

// Check user accounts
try {
    $stmt = $pdo->query("SELECT * FROM TAI_KHOAN");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>All User Accounts:</h3>";
    echo "<pre>";
    print_r($users);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error fetching user accounts: " . $e->getMessage();
}

// Check all activity records with user info
try {
    $stmt = $pdo->query("SELECT * FROM LICH_SU_HOAT_DONG ORDER BY ThoiGian DESC");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>All Activity Records:</h3>";
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
} catch (Exception $e) {
    echo "Error fetching all records: " . $e->getMessage();
}
?>
