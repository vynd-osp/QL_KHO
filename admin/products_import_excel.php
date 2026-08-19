<?php
// Backend import Excel cho sản phẩm
// - Tách riêng khỏi giao diện (products.php)
// - Không dùng Composer, dùng thư viện đọc .xlsx tự viết (libs_simplexlsx.php)

session_start();

require_once '../config/db.php';
require_once './activity_history.php';
require_once './libs_excel_reader.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$userName = $_SESSION['username'] ?? 'Người dùng';
$userRole = $_SESSION['role'] ?? 'Nhân viên';
$userId   = $_SESSION['user_id'] ?? null;

/**
 * Hàm trim safe cho chuỗi, nếu null thì trả về chuỗi rỗng.
 */
function safe_trim($value)
{
    if ($value === null) return '';
    return trim((string)$value);
}

/**
 * Hàm tạo mã sản phẩm tự động (tương tự products.php)
 */
function generateMaSPImport($pdo)
{
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaSP, 3) AS UNSIGNED)) as max_id FROM SANPHAM");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'SP' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// Kết quả import sẽ lưu vào session để products.php hiển thị
$result = [
    'success_count' => 0,
    'error_count'   => 0,
    'errors'        => []
];

try {
    // 1. Kiểm tra file upload
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Vui lòng chọn file Excel (.xlsx) để import.');
    }

    $file = $_FILES['excel_file'];

    // 2. Kiểm tra định dạng file dựa vào đuôi file
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'csv'])) {
        throw new Exception('File không hợp lệ. Chỉ chấp nhận định dạng .xlsx hoặc .csv');
    }

    // Có thể kiểm tra thêm MIME type nếu cần (không bắt buộc)

    $tmpPath = $file['tmp_name'];
    if (!file_exists($tmpPath)) {
        throw new Exception('Không tìm thấy file tạm để đọc dữ liệu.');
    }

    // 3. Đọc dữ liệu Excel hoặc CSV
    $reader = new ExcelReader($tmpPath);
    $rows = $reader->readRows();

    if (empty($rows) || count($rows) < 2) {
        throw new Exception('File Excel không có dữ liệu (cần ít nhất 1 dòng tiêu đề và 1 dòng dữ liệu).');
    }

    // 4. Giả định dòng đầu là header (tiêu đề cột)
    // Mapping cột: index -> tên cột chuẩn hoá (lowercase, bỏ dấu cách)
    $headerRow = array_shift($rows); // lấy dòng đầu tiên
    $headerMap = [];

    foreach ($headerRow as $colIndex => $title) {
        $normalized = mb_strtolower(trim((string)$title), 'UTF-8');
        // Chuẩn hoá đơn giản: bỏ khoảng trắng, dấu chấm, dấu phẩy
        $normalized = str_replace([' ', '.', ','], '', $normalized);
        $headerMap[$colIndex] = $normalized;
    }

    /**
     * Hàm lấy giá trị cột theo tên logic, cho phép nhiều alias tên cột (không phân biệt hoa thường, bỏ khoảng trắng).
     */
    $getValue = function (array $row, array $aliases) use ($headerMap) {
        foreach ($headerMap as $colIndex => $normalizedName) {
            foreach ($aliases as $alias) {
                if ($normalizedName === $alias) {
                    return safe_trim($row[$colIndex] ?? '');
                }
            }
        }
        return '';
    };

    $currentMaxAutoId = null; // để tránh query MAX quá nhiều lần

    // 5. Duyệt từng dòng dữ liệu
    // Lưu ý: $rows hiện là mảng 0-based, nhưng dòng thực tế trong Excel = index + 2 (vì đã bỏ header)
    foreach ($rows as $idx => $row) {
        $excelLine = $idx + 2; // số dòng thực trong file Excel (để báo lỗi)

        // Bỏ qua dòng hoàn toàn trống
        $isEmptyRow = true;
        foreach ($row as $v) {
            if (trim((string)$v) !== '') {
                $isEmptyRow = false;
                break;
            }
        }
        if ($isEmptyRow) {
            // Không tính là lỗi, chỉ đơn giản bỏ qua
            continue;
        }

        // Lấy dữ liệu các cột theo alias
        $tenSP = $getValue($row, ['tênsảnphẩm', 'tensanpham', 'tensp']);
        $theLoai = $getValue($row, ['thểloại', 'theloai']);
        $maSP = $getValue($row, ['mãsảnphẩm', 'masanpham', 'masp']);
        $mauSP = $getValue($row, ['mẫu', 'mau', 'mẫusp', 'mausp']);
        $tinhTrang = $getValue($row, ['tìnhtrạng', 'tinhtrang']);
        $soLuongStr = $getValue($row, ['sốlượng', 'soluong', 'sốlượngtồn', 'slt', 'sltk']);
        $giaStr = $getValue($row, ['giá', 'gia', 'giábán', 'giaban']);

        // Ràng buộc: các trường bắt buộc
        $missingFields = [];
        if ($tenSP === '') $missingFields[] = 'Tên sản phẩm';
        if ($theLoai === '') $missingFields[] = 'Thể loại';
        if ($maSP === '') $missingFields[] = 'Mã sản phẩm';

        if (!empty($missingFields)) {
            $result['error_count']++;
            $result['errors'][] = "Dòng {$excelLine} thiếu thông tin bắt buộc: " . implode(', ', $missingFields) . '.';
            continue;
        }

        // Parse số lượng, giá: nếu rỗng hoặc không phải số -> coi là 0, nhưng không xem là lỗi chết
        $soLuong = 0;
        if ($soLuongStr !== '' && is_numeric($soLuongStr)) {
            $soLuong = (int)$soLuongStr;
            if ($soLuong < 0) $soLuong = 0;
        }

        $giaBan = 0;
        if ($giaStr !== '' && is_numeric($giaStr)) {
            $giaBan = (float)$giaStr;
            if ($giaBan < 0) $giaBan = 0;
        }

        // Nếu không có tình trạng -> suy ra từ số lượng
        if ($tinhTrang === '') {
            $tinhTrang = $soLuong > 0 ? 'Còn hàng' : 'Hết hàng';
        }

        // 6. Kiểm tra trùng mã sản phẩm
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM SANPHAM WHERE MaSP = ?');
        $checkStmt->execute([$maSP]);
        $exists = $checkStmt->fetchColumn() > 0;
        if ($exists) {
            $result['error_count']++;
            $result['errors'][] = "Dòng {$excelLine}: Mã sản phẩm {$maSP} đã tồn tại, bỏ qua.";
            continue;
        }

        // 7. Insert vào DB
        try {
            // Nếu muốn cho phép để trống MaSP trong file -> sinh tự động
            if ($maSP === '') {
                // Cache current max để không query nhiều lần
                if ($currentMaxAutoId === null) {
                    $stmtMax = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaSP, 3) AS UNSIGNED)) as max_id FROM SANPHAM");
                    $resMax = $stmtMax->fetch();
                    $currentMaxAutoId = (int)($resMax['max_id'] ?? 0);
                }
                $currentMaxAutoId++;
                $maSP = 'SP' . str_pad($currentMaxAutoId, 5, '0', STR_PAD_LEFT);
            }

            $stmt = $pdo->prepare('INSERT INTO SANPHAM (MaSP, TenSP, TheLoai, MauSP, TinhTrang, SLTK, GiaBan) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$maSP, $tenSP, $theLoai, $mauSP, $tinhTrang, $soLuong, $giaBan]);

            // Ghi lịch sử
            logActivity(
                $pdo,
                $userId,
                $userName,
                'Import',
                "Import SP từ Excel: $maSP",
                "Tên: $tenSP, Thể loại: $theLoai, SL: $soLuong, Giá: $giaBan"
            );

            $result['success_count']++;
        } catch (Exception $e) {
            $result['error_count']++;
            $result['errors'][] = "Dòng {$excelLine}: Lỗi khi thêm sản phẩm {$maSP} - " . $e->getMessage();
            continue;
        }
    }

    // 8. Thiết lập flash message tóm tắt
    $summaryMsg = "Import Excel hoàn tất. Thành công: {$result['success_count']} dòng, Lỗi: {$result['error_count']} dòng.";
    $_SESSION['flash'] = [
        'type'    => $result['error_count'] > 0 ? 'error' : 'success',
        'message' => $summaryMsg
    ];

    // Lưu chi tiết vào session để products.php hiển thị
    $_SESSION['import_result'] = $result;
} catch (Exception $e) {
    // Lỗi tổng quát (không đọc được file, sai định dạng,...)
    $_SESSION['flash'] = [
        'type'    => 'error',
        'message' => 'Lỗi import Excel: ' . $e->getMessage()
    ];
    $_SESSION['import_result'] = $result;
}

// Quay lại trang quản lý sản phẩm
header('Location: products.php');
exit();


