<?php
// Xử lý import Excel cho trang quản lý phiếu xuất (exports.php)

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

function safe_trim($value) {
    if ($value === null) {
        return '';
    }
    return trim((string)$value);
}

function normalizeDateValue($value) {
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

function nextMaCTPX($pdo, &$currentMax) {
    if ($currentMax === null) {
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaCTPX, 5) AS UNSIGNED)) as max_id FROM CHITIETPHIEUXUAT");
        $row = $stmt->fetch();
        $currentMax = (int)($row['max_id'] ?? 0);
    }
    $currentMax++;
    return 'CTPX' . str_pad($currentMax, 3, '0', STR_PAD_LEFT);
}

$result = [
    'success_rows'   => 0,
    'error_count'    => 0,
    'created_exports'=> 0,
    'errors'         => []
];

try {
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

    $reader = new ExcelReader($tmpPath);
    $rows = $reader->readRows();

    if (empty($rows) || count($rows) < 2) {
        throw new Exception('File Excel không có dữ liệu (cần ít nhất 1 dòng tiêu đề và 1 dòng dữ liệu).');
    }

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

    $exportsData = [];

    foreach ($rows as $idx => $row) {
        $excelLine = $idx + 2; // đếm từ 1 và bỏ qua header

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

        $maPX = $getValue($row, ['mapx', 'maphieu', 'maphieuxuat']);
        $maCH = $getValue($row, ['mach', 'macuahang', 'cuahang', 'cuahangs', 'storecode']);
        $maSP = $getValue($row, ['masp', 'masanpham', 'mãsảnphẩm']);
        $soLuongStr = $getValue($row, ['soluong', 'sốlượng', 'slx', 'slxuat']);
        $ngayXuatRaw = $getValue($row, ['ngayxuat', 'ngàyxuất', 'ngay', 'date']);
        $maTKRaw = $getValue($row, ['matk', 'mátk', 'nguoixuat', 'nguoitao']);
        $tinhTrangRaw = $getValue($row, ['tinhtrang', 'tìnhtrang', 'trangthai', 'trạngthái']);

        $missingFields = [];
        if ($maPX === '') {
            $missingFields[] = 'Mã phiếu xuất';
        }
        if ($maCH === '') {
            $missingFields[] = 'Mã cửa hàng';
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

        $maPX = strtoupper($maPX);
        $maSP = strtoupper($maSP);
        $maCH = strtoupper($maCH);
        $soLuong = (int)$soLuongStr;

        if (!isset($exportsData[$maPX])) {
            $exportsData[$maPX] = [
                'info' => [
                    'NgayXuat' => $ngayXuatRaw,
                    'MaCH' => $maCH,
                    'MaTK' => $maTKRaw,
                    'TinhTrang' => $tinhTrangRaw,
                ],
                'details' => [],
                'row_numbers' => [],
                'row_count' => 0,
            ];
        } else {
            if ($exportsData[$maPX]['info']['NgayXuat'] === '' && $ngayXuatRaw !== '') {
                $exportsData[$maPX]['info']['NgayXuat'] = $ngayXuatRaw;
            }
            if ($maCH !== '' && $exportsData[$maPX]['info']['MaCH'] === '') {
                $exportsData[$maPX]['info']['MaCH'] = $maCH;
            } elseif ($maCH !== '' && $exportsData[$maPX]['info']['MaCH'] !== '' && $exportsData[$maPX]['info']['MaCH'] !== $maCH) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$excelLine}: Mã cửa hàng không nhất quán cho phiếu {$maPX}.";
                continue;
            }
            if ($exportsData[$maPX]['info']['MaTK'] === '' && $maTKRaw !== '') {
                $exportsData[$maPX]['info']['MaTK'] = $maTKRaw;
            }
            if ($exportsData[$maPX]['info']['TinhTrang'] === '' && $tinhTrangRaw !== '') {
                $exportsData[$maPX]['info']['TinhTrang'] = $tinhTrangRaw;
            }
        }

        $exportsData[$maPX]['row_numbers'][] = $excelLine;
        $exportsData[$maPX]['row_count']++;

        $detailKey = $maSP;
        if (!isset($exportsData[$maPX]['details'][$detailKey])) {
            $exportsData[$maPX]['details'][$detailKey] = [
                'MaSP' => $maSP,
                'SLX' => $soLuong,
                'rows' => [$excelLine]
            ];
        } else {
            $exportsData[$maPX]['details'][$detailKey]['SLX'] += $soLuong;
            $exportsData[$maPX]['details'][$detailKey]['rows'][] = $excelLine;
        }
    }

    if (empty($exportsData)) {
        throw new Exception('Không có dòng hợp lệ để import.');
    }

    $maPXlist = array_keys($exportsData);
    $existingMaPXs = [];
    if (!empty($maPXlist)) {
        $placeholders = implode(',', array_fill(0, count($maPXlist), '?'));
        $stmt = $pdo->prepare("SELECT MaPX FROM PHIEUXUAT WHERE MaPX IN ($placeholders)");
        $stmt->execute($maPXlist);
        $existingMaPXs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    foreach ($existingMaPXs as $existingMaPX) {
        if (!isset($exportsData[$existingMaPX])) {
            continue;
        }
        $rowsForPX = $exportsData[$existingMaPX]['row_numbers'];
        foreach ($rowsForPX as $line) {
            $result['error_count']++;
            $result['errors'][] = "Dòng {$line}: Mã phiếu {$existingMaPX} đã tồn tại, bỏ qua.";
        }
        unset($exportsData[$existingMaPX]);
    }

    if (empty($exportsData)) {
        throw new Exception('Tất cả mã phiếu trong file đã tồn tại, không thể import.');
    }

    $currentMaxCTPX = null;
    foreach ($exportsData as $maPX => $data) {
        $ngayXuat = normalizeDateValue($data['info']['NgayXuat']);
        $maTK = safe_trim($data['info']['MaTK']) ?: $userId;
        $maCH = safe_trim($data['info']['MaCH']);
        $tinhTrang = normalizeStatus($data['info']['TinhTrang'], $validStatuses);

        if ($maCH === '') {
            foreach ($data['row_numbers'] as $line) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$line}: Phiếu {$maPX} thiếu mã cửa hàng.";
            }
            continue;
        }

        $maCH = strtoupper($maCH);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM CUAHANG WHERE MaCH = ?");
        $stmt->execute([$maCH]);
        if ($stmt->fetchColumn() == 0) {
            foreach ($data['row_numbers'] as $line) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$line}: Mã cửa hàng {$maCH} không tồn tại, bỏ qua phiếu {$maPX}.";
            }
            continue;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM TAIKHOAN WHERE MaTK = ?");
        $stmt->execute([$maTK]);
        if ($stmt->fetchColumn() == 0) {
            foreach ($data['row_numbers'] as $line) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$line}: Mã tài khoản {$maTK} không tồn tại, bỏ qua phiếu {$maPX}.";
            }
            continue;
        }

        if (empty($data['details'])) {
            foreach ($data['row_numbers'] as $line) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$line}: Phiếu {$maPX} không có sản phẩm nào.";
            }
            continue;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO PHIEUXUAT (MaPX, NgayXuat, MaCH, MaTK, TinhTrang_PX) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$maPX, $ngayXuat, $maCH, $maTK, $tinhTrang]);

            $failedRowsForExport = 0;

            foreach ($data['details'] as $detail) {
                $maSP = $detail['MaSP'];
                $soLuong = (int)$detail['SLX'];

                $stmt = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                $stmt->execute([$maSP]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    foreach ($detail['rows'] as $line) {
                        $result['error_count']++;
                        $result['errors'][] = "Dòng {$line}: Không tìm thấy sản phẩm {$maSP}, bỏ qua chi tiết thuộc phiếu {$maPX}.";
                    }
                    $failedRowsForExport += count($detail['rows']);
                    continue;
                }

                $maCTPX = nextMaCTPX($pdo, $currentMaxCTPX);
                $giaBan = (float)$product['GiaBan'];
                $thanhTien = $giaBan * $soLuong;

                $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX, ThanhTien) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$maCTPX, $maPX, $maSP, $soLuong, $thanhTien]);

                if ($tinhTrang === 'Hoàn thành') {
                    $stmtUpdate = $pdo->prepare("
                        UPDATE SANPHAM 
                        SET SLTK = SLTK - ?, 
                            TinhTrang = CASE 
                                WHEN TinhTrang = 'Ngừng kinh doanh' THEN 'Ngừng kinh doanh'
                                WHEN SLTK - ? > 0 THEN 'Còn hàng'
                                ELSE 'Hết hàng'
                            END
                        WHERE MaSP = ?
                    ");
                    $stmtUpdate->execute([$soLuong, $soLuong, $maSP]);
                }
            }

            $pdo->commit();

            $result['created_exports']++;
            $successfulRows = max(0, $data['row_count'] - $failedRowsForExport);
            $result['success_rows'] += $successfulRows;

            logActivity(
                $pdo,
                $userId,
                $userName,
                'Import',
                "Import phiếu xuất từ Excel: $maPX",
                "Cửa hàng: $maCH, Số sản phẩm: " . count($data['details'])
            );
        } catch (Exception $e) {
            $pdo->rollBack();
            foreach ($data['row_numbers'] as $line) {
                $result['error_count']++;
                $result['errors'][] = "Dòng {$line}: Lỗi khi tạo phiếu {$maPX} - " . $e->getMessage();
            }
        }
    }

    $summary = "Import phiếu xuất hoàn tất. "
        . "Phiếu tạo mới: {$result['created_exports']}, "
        . "Dòng hợp lệ: {$result['success_rows']}, "
        . "Dòng lỗi: {$result['error_count']}.";

    $_SESSION['flash'] = [
        'type' => $result['error_count'] > 0 ? 'error' : 'success',
        'message' => $summary
    ];
    $_SESSION['exports_import_result'] = $result;
} catch (Exception $e) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Lỗi import Excel: ' . $e->getMessage()
    ];
    $_SESSION['exports_import_result'] = $result;
}

header("Location: exports.php");
exit();

