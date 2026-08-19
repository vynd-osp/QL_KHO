<?php
// Xử lý import Excel cho trang quản lý phiếu nhập (imports.php)
// - Sử dụng thư viện tự viết SimpleXLSXReader (không cần Composer)
// - Hỗ trợ tạo phiếu nhập + chi tiết từ file .xlsx

session_start();

require_once '../config/db.php';
require_once './activity_history.php';
require_once './libs_excel_reader.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['username'] ?? 'Người dùng';
$validStatuses = ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'];

/**
 * Trim an toàn
 */
function safe_trim($value) {
    if ($value === null) return '';
    return trim((string)$value);
}

/**
 * Chuẩn hóa ngày về định dạng Y-m-d, nếu không hợp lệ thì trả về ngày hiện tại
 */
function normalizeDate($value) {
    $value = safe_trim($value);
    if ($value === '') {
        return date('Y-m-d');
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return date('Y-m-d');
    }
    return date('Y-m-d', $timestamp);
}

/**
 * Chuẩn hóa trạng thái theo danh sách hợp lệ
 */
function normalizeStatus($value, array $validStatuses, $default = 'Đang xử lý') {
    $value = safe_trim($value);
    if ($value === '') {
        return $default;
    }
    $valueLower = mb_strtolower($value, 'UTF-8');
    foreach ($validStatuses as $status) {
        if (mb_strtolower($status, 'UTF-8') === $valueLower) {
            return $status;
        }
    }
    return $default;
}

/**
 * Sinh mã chi tiết phiếu nhập tiếp theo dựa trên max hiện tại
 */
function nextMaCTPN($pdo, &$currentMax) {
    if ($currentMax === null) {
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaCTPN, 5) AS UNSIGNED)) as max_id FROM CHITIETPHIEUNHAP");
        $row = $stmt->fetch();
        $currentMax = (int)($row['max_id'] ?? 0);
    }
    $currentMax++;
    return 'CTPN' . str_pad($currentMax, 3, '0', STR_PAD_LEFT);
}

$result = [
    'success_rows'   => 0,
    'error_count'    => 0,
    'created_imports'=> 0,
    'errors'         => []
];

try {
    // 1. Kiểm tra file upload
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Vui lòng chọn file Excel (.xlsx) để import.');
    }

    $file = $_FILES['excel_file'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'csv'])) {
        throw new Exception('File không hợp lệ. Chỉ chấp nhận định dạng .xlsx hoặc .csv');
    }

    $tmpPath = $file['tmp_name'];
    if (!file_exists($tmpPath)) {
        throw new Exception('Không tìm thấy file tạm để đọc dữ liệu.');
    }

    // 2. Đọc dữ liệu Excel hoặc CSV
    $reader = new ExcelReader($tmpPath);
    $rows = $reader->readRows();

    if (empty($rows) || count($rows) < 2) {
        throw new Exception('File Excel không có dữ liệu (cần ít nhất 1 dòng tiêu đề và 1 dòng dữ liệu).');
    }

    // 3. Chuẩn hóa header
    $headerRow = array_shift($rows);
    $headerMap = [];
    foreach ($headerRow as $colIndex => $title) {
        $normalized = mb_strtolower(trim((string)$title), 'UTF-8');
        $normalized = str_replace([' ', '.', ',', '-', '_'], '', $normalized);
        $headerMap[$colIndex] = $normalized;
    }

    $getValue = function(array $row, array $aliases) use ($headerMap) {
        foreach ($headerMap as $colIndex => $normalizedName) {
            if (in_array($normalizedName, $aliases, true)) {
                return safe_trim($row[$colIndex] ?? '');
            }
        }
        return '';
    };

    $importsData = [];

    foreach ($rows as $idx => $row) {
        $excelLine = $idx + 2; // vì đã bỏ header (dòng 1)

        // Bỏ qua dòng trống hoàn toàn
        $isEmpty = true;
        foreach ($row as $cell) {
            if (safe_trim($cell) !== '') {
                $isEmpty = false;
                break;
            }
        }
        if ($isEmpty) {
            continue;
        }

        // Lấy dữ liệu cần thiết
        $maPN = $getValue($row, ['mapn', 'maphieu', 'maphieunhap']);
        $maSP = $getValue($row, ['masp', 'mãsảnphẩm', 'masanpham']);
        $soLuongStr = $getValue($row, ['soluong', 'sốlượng', 'sln', 'slnhap']);
        $ngayNhapRaw = $getValue($row, ['ngaynhap', 'ngàynhap', 'ngay', 'date']);
        $maTKRaw = $getValue($row, ['matk', 'mátk', 'nguoinhap', 'nguoitao']);
        $tinhTrangRaw = $getValue($row, ['tinhtrang', 'tìnhtrang', 'trangthai', 'trạngthái']);

        // Validate các trường bắt buộc
        $missingFields = [];
        if ($maPN === '') {
            $missingFields[] = 'Mã phiếu nhập';
        }
        if ($maSP === '') {
            $missingFields[] = 'Mã sản phẩm';
        }
        if ($soLuongStr === '' || !is_numeric($soLuongStr) || (int)$soLuongStr <= 0) {
            $missingFields[] = 'Số lượng (>0)';
        }

        if (!empty($missingFields)) {
            $result['error_count']++;
            $result['errors'][] = "Dòng {$excelLine}: Thiếu hoặc sai dữ liệu - " . implode(', ', $missingFields);
            continue;
        }

        $maPN = strtoupper($maPN);
        $maSP = strtoupper($maSP);
        $soLuong = (int)$soLuongStr;

        if (!isset($importsData[$maPN])) {
            $importsData[$maPN] = [
                'info' => [
                    'NgayNhap' => $ngayNhapRaw,
                    'MaTK' => $maTKRaw,
                    'TinhTrang' => $tinhTrangRaw,
                ],
                'details' => [],
                'row_numbers' => [],
                'row_count' => 0,
            ];
        } else {
            // Ghi đè thông tin nếu trước đó chưa có mà hiện tại có
            if ($importsData[$maPN]['info']['NgayNhap'] === '' && $ngayNhapRaw !== '') {
                $importsData[$maPN]['info']['NgayNhap'] = $ngayNhapRaw;
            }
            if ($importsData[$maPN]['info']['MaTK'] === '' && $maTKRaw !== '') {
                $importsData[$maPN]['info']['MaTK'] = $maTKRaw;
            }
            if ($importsData[$maPN]['info']['TinhTrang'] === '' && $tinhTrangRaw !== '') {
                $importsData[$maPN]['info']['TinhTrang'] = $tinhTrangRaw;
            }
        }

        $importsData[$maPN]['row_numbers'][] = $excelLine;
        $importsData[$maPN]['row_count']++;

        $detailKey = $maSP;
        if (!isset($importsData[$maPN]['details'][$detailKey])) {
            $importsData[$maPN]['details'][$detailKey] = [
                'MaSP' => $maSP,
                'SLN' => $soLuong,
                'rows' => [$excelLine]
            ];
        } else {
            $importsData[$maPN]['details'][$detailKey]['SLN'] += $soLuong;
            $importsData[$maPN]['details'][$detailKey]['rows'][] = $excelLine;
        }
    }

    if (empty($importsData)) {
        throw new Exception('Không có dòng hợp lệ để import.');
    }

    // 4. Kiểm tra mã phiếu đã tồn tại
    $maPNlist = array_keys($importsData);
    $existingMaPNs = [];
    if (!empty($maPNlist)) {
        $placeholders = implode(',', array_fill(0, count($maPNlist), '?'));
        $stmt = $pdo->prepare("SELECT MaPN FROM PHIEUNHAP WHERE MaPN IN ($placeholders)");
        $stmt->execute($maPNlist);
        $existingMaPNs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    foreach ($existingMaPNs as $existingMaPN) {
        if (!isset($importsData[$existingMaPN])) continue;
        $rows = $importsData[$existingMaPN]['row_numbers'];
        foreach ($rows as $line) {
            $result['error_count']++;
            $result['errors'][] = "Dòng {$line}: Mã phiếu {$existingMaPN} đã tồn tại, bỏ qua.";
        }
        unset($importsData[$existingMaPN]);
    }

    if (empty($importsData)) {
        throw new Exception('Tất cả mã phiếu trong file đã tồn tại, không thể import.');
    }

    // 5. Import từng phiếu
    $currentMaxCTPN = null;
    foreach ($importsData as $maPN => $data) {
        // Chuẩn hóa thông tin phiếu
        $ngayNhap = normalizeDate($data['info']['NgayNhap']);
        $maTK = safe_trim($data['info']['MaTK']) ?: $userId;
        $tinhTrang = normalizeStatus($data['info']['TinhTrang'], $validStatuses);

        // Xác thực người nhập
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM TAIKHOAN WHERE MaTK = ?");
        $stmt->execute([$maTK]);
        if ($stmt->fetchColumn() == 0) {
            foreach ($data['row_numbers'] as $line) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$line}: Mã tài khoản {$maTK} không tồn tại, bỏ qua phiếu {$maPN}.";
            }
            continue;
        }

        if (empty($data['details'])) {
            foreach ($data['row_numbers'] as $line) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$line}: Phiếu {$maPN} không có sản phẩm nào.";
            }
            continue;
        }

        try {
            $pdo->beginTransaction();

            // Thêm phiếu nhập
            $stmt = $pdo->prepare("INSERT INTO PHIEUNHAP (MaPN, NgayNhap, MaTK, TinhTrang_PN) VALUES (?, ?, ?, ?)");
            $stmt->execute([$maPN, $ngayNhap, $maTK, $tinhTrang]);

            $failedRowsForImport = 0;

            foreach ($data['details'] as $detail) {
                $maSP = $detail['MaSP'];
                $soLuong = (int)$detail['SLN'];

                // Kiểm tra sản phẩm
                $stmt = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                $stmt->execute([$maSP]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    foreach ($detail['rows'] as $line) {
                        $result['error_count']++;
                        $result['errors'][] = "Dòng {$line}: Không tìm thấy sản phẩm {$maSP}, bỏ qua chi tiết thuộc phiếu {$maPN}.";
                    }
                    $failedRowsForImport += count($detail['rows']);
                    continue;
                }

                $maCTPN = nextMaCTPN($pdo, $currentMaxCTPN);
                $giaBan = (float)$product['GiaBan'];
                $thanhTien = $giaBan * $soLuong;

                $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUNHAP (MaCTPN, MaPN, MaSP, SLN, ThanhTien) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$maCTPN, $maPN, $maSP, $soLuong, $thanhTien]);

                if ($tinhTrang === 'Hoàn thành') {
                    $stmtUpdate = $pdo->prepare("
                        UPDATE SANPHAM 
                        SET SLTK = SLTK + ?, 
                            TinhTrang = CASE 
                                WHEN TinhTrang = 'Ngừng kinh doanh' THEN 'Ngừng kinh doanh'
                                WHEN SLTK + ? > 0 THEN 'Còn hàng'
                                ELSE 'Hết hàng'
                            END
                        WHERE MaSP = ?
                    ");
                    $stmtUpdate->execute([$soLuong, $soLuong, $maSP]);
                }
            }

            $pdo->commit();

            $result['created_imports']++;
            $successfulRows = max(0, $data['row_count'] - $failedRowsForImport);
            $result['success_rows'] += $successfulRows;

            logActivity(
                $pdo,
                $userId,
                $userName,
                'Import',
                "Import phiếu nhập từ Excel: $maPN",
                "Ngày: $ngayNhap, Số sản phẩm: " . count($data['details'])
            );
        } catch (Exception $e) {
            $pdo->rollBack();
            foreach ($data['row_numbers'] as $line) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$line}: Lỗi khi tạo phiếu {$maPN} - " . $e->getMessage();
            }
        }
    }

    $summary = "Import phiếu nhập hoàn tất. "
        . "Phiếu tạo mới: {$result['created_imports']}, "
        . "Dòng hợp lệ: {$result['success_rows']}, "
        . "Dòng lỗi: {$result['error_count']}.";

    $_SESSION['flash'] = [
        'type' => $result['error_count'] > 0 ? 'error' : 'success',
        'message' => $summary
    ];
    $_SESSION['imports_import_result'] = $result;
} catch (Exception $e) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Lỗi import Excel: ' . $e->getMessage()
    ];
    $_SESSION['imports_import_result'] = $result;
}

header("Location: imports.php");
exit();


