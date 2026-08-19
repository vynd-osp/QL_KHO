<?php
session_start();
require_once '../config/db.php';
require_once './activity_history.php';

// Thiết lập session test
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'Nguyen Van A';
$_SESSION['role'] = 'Quản lý';

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? 'Nhân viên';

echo "User ID: $userId<br>";
echo "User Role: $userRole<br>";
echo "Role type: " . gettype($userRole) . "<br>";
echo "<hr>";

// Test lấy dữ liệu
$activityLogs = getActivityHistory($pdo, $userId, $userRole, 20, 0);
echo "Total records: " . count($activityLogs) . "<br>";

if (!empty($activityLogs)) {
    echo "<table border='1'>";
    foreach ($activityLogs as $log) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($log['TenNhanVien']) . "</td>";
        echo "<td>" . htmlspecialchars($log['LoaiHanhDong']) . "</td>";
        echo "<td>" . htmlspecialchars($log['DoiTuong']) . "</td>";
        echo "<td>" . htmlspecialchars($log['ChiTiet']) . "</td>";
        echo "<td>" . htmlspecialchars($log['ThoiGian']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No records found!<br>";
}

// Direct query test
echo "<hr>";
echo "<h3>Direct Query Test:</h3>";
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM LICH_SU_HOAT_DONG");
$stmt->execute();
$result = $stmt->fetch();
echo "Total in database: " . $result['total'] . "<br>";

$stmt = $pdo->prepare("SELECT * FROM LICH_SU_HOAT_DONG LIMIT 2");
$stmt->execute();
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Sample records:<br>";
foreach ($all as $row) {
    echo "<pre>";
    print_r($row);
    echo "</pre>";
}
?>
