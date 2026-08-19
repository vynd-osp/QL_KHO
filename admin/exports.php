<?php
// admin/exports.php - Trang quản lý xuất kho
session_start();
require_once '../config/db.php';
require_once './activity_history.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userName = $_SESSION['username'] ?? 'Người dùng';
$userRole = $_SESSION['role'] ?? 'Nhân viên';
$userId = $_SESSION['user_id'];

// Lấy flash message (nếu có) và xóa khỏi session
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
}

// Lấy kết quả import Excel (nếu có) và reset
$exportImportResult = $_SESSION['exports_import_result'] ?? null;
if (isset($_SESSION['exports_import_result'])) {
    unset($_SESSION['exports_import_result']);
}

// Hàm tạo mã phiếu xuất tự động
function generateMaPX($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaPX, 3) AS UNSIGNED)) as max_id FROM PHIEUXUAT");
    $result = $stmt->fetch();
    $next_id = $result['max_id'] + 1;
    return 'PX' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// ============================
//  XỬ LÝ AJAX: LẤY CHI TIẾT PHIẾU XUẤT
// ============================
if (isset($_GET['action']) && $_GET['action'] == 'get_detail') {
    header('Content-Type: application/json');
    $maPX = $_GET['maPX'] ?? '';

    if (!$maPX) {
        echo json_encode(['success' => false, 'message' => 'Thiếu mã phiếu xuất']);
        exit;
    }

    try {
        // Lấy thông tin phiếu xuất
        $stmt = $pdo->prepare("
            SELECT px.*, ch.TenCH, tk.TenTK 
            FROM PHIEUXUAT px 
            LEFT JOIN CUAHANG ch ON px.MaCH = ch.MaCH
            LEFT JOIN TAIKHOAN tk ON px.MaTK = tk.MaTK
            WHERE px.MaPX = ?
        ");
        $stmt->execute([$maPX]);
        $phieu = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$phieu) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy phiếu xuất']);
            exit;
        }

        // Kiểm tra quyền: Nhân viên chỉ được xem phiếu của mình
        if ($userRole == 'Nhân viên' && $userId && $phieu['MaTK'] != $userId) {
            echo json_encode(['success' => false, 'message' => 'Bạn không có quyền xem phiếu xuất này']);
            exit;
        }

        // Lấy chi tiết sản phẩm (thêm SLX_MOI và SLTK)
        $stmt = $pdo->prepare("
            SELECT ct.MaCTPX, ct.MaSP, ct.SLX, ct.SLX_MOI, ct.ThanhTien, sp.TenSP, sp.TheLoai, sp.SLTK, sp.GiaBan
            FROM CHITIETPHIEUXUAT ct
            JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
            WHERE ct.MaPX = ?
        ");
        $stmt->execute([$maPX]);
        $chiTiet = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'phieu' => $phieu,
                'chiTiet' => $chiTiet
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


// ============================
//  XỬ LÝ THÊM / SỬA / XÓA / CẬP NHẬT TRẠNG THÁI
// ============================
if ($_POST['action'] ?? '') {
    $action = $_POST['action'];
    
    try {
        if ($action == 'add') {
            // Thêm phiếu xuất mới
            $ngayXuat = $_POST['NgayXuat'] ?? '';
            $maCH = $_POST['MaCH'] ?? '';
            // Nếu là Nhân viên, tự động lưu MaTK = userId của người đang đăng nhập
            if ($userRole == 'Nhân viên' && $userId) {
                $maTK = $userId;
            } else {
                $maTK = $_POST['MaTK'] ?? $userId;
            }
            $tinhTrang = $_POST['TinhTrang_PX'] ?? 'Đang xử lý';

            // Kiểm tra các trường bắt buộc
            if (empty($ngayXuat) || empty($maCH) || empty($maTK) || empty($tinhTrang)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: exports.php");
                exit();
            }

            // Kiểm tra có sản phẩm
            $products = [];
            if (!empty($_POST['products'])) {
                $products = json_decode($_POST['products'], true);
            }
            
            if (empty($products)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng thêm ít nhất một sản phẩm!'];
                header("Location: exports.php");
                exit();
            }

            $maPX = generateMaPX($pdo); // Tạo mã phiếu xuất tự động
            
            // Tạo phiếu xuất
            $stmt = $pdo->prepare("INSERT INTO PHIEUXUAT (MaPX, NgayXuat, MaCH, MaTK, TinhTrang_PX) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$maPX, $ngayXuat, $maCH, $maTK, $tinhTrang]);
            
            // Thêm chi tiết phiếu xuất
            // Lấy mã CTPX lớn nhất hiện có
            $stmt = $pdo->query("SELECT MaCTPX FROM CHITIETPHIEUXUAT ORDER BY MaCTPX DESC LIMIT 1");
            $lastCTPX = $stmt->fetchColumn();
            $nextNumber = 1;
            if ($lastCTPX) {
                $nextNumber = intval(substr($lastCTPX, 4)) + 1;
            }
            
            foreach ($products as $index => $product) {
                // Lấy giá bán của sản phẩm
                $stmtPrice = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                $stmtPrice->execute([$product['MaSP']]);
                $giaBan = $stmtPrice->fetchColumn();
                $thanhTien = $product['SLX'] * $giaBan;
                
                $maCTPX = 'CTPX' . str_pad($nextNumber + $index, 3, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX, ThanhTien) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$maCTPX, $maPX, $product['MaSP'], $product['SLX'], $thanhTien]);
                
                // Chỉ cập nhật số lượng tồn kho nếu trạng thái là "Hoàn thành"
                if ($tinhTrang == 'Hoàn thành') {
                    // Không thay đổi status nếu sản phẩm đã được đánh dấu "Ngừng kinh doanh"
                    $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK - ?, TinhTrang = CASE WHEN TinhTrang = 'Ngừng kinh doanh' THEN 'Ngừng kinh doanh' WHEN SLTK - ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END WHERE MaSP = ?");
                    $stmt->execute([$product['SLX'], $product['SLX'], $product['MaSP']]);
                }
            }
            
            // Ghi lịch sử hoạt động
            $productCount = count($products);
            logActivity($pdo, $userId, $userName, 'Thêm', "PX: $maPX", "Cửa hàng: $maCH, Số sản phẩm: $productCount");
            
        } elseif ($action == 'edit') {
            // Sửa phiếu xuất (chỉ được sửa khi trạng thái "Đang xử lý")
            $maPX = $_POST['MaPX'];
            
            // Kiểm tra trạng thái
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $tinhTrang = $stmt->fetchColumn();
            
            if ($tinhTrang != 'Đang xử lý') {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Không thể sửa phiếu đã được xử lý'];
                header("Location: exports.php");
                exit();
            }
            
            // Kiểm tra quyền: Nhân viên chỉ được sửa phiếu của mình
            if ($userRole == 'Nhân viên') {
                $stmt = $pdo->prepare("SELECT MaTK FROM PHIEUXUAT WHERE MaPX = ?");
                $stmt->execute([$maPX]);
                $maTK = $stmt->fetchColumn();
                if ($maTK != $userId) {
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Bạn không có quyền sửa phiếu này'];
                    header("Location: exports.php");
                    exit();
                }
            }
            
            $maCH = $_POST['MaCH'];
            
            // Cập nhật phiếu xuất (chỉ sửa Cửa Hàng, không sửa Ngày Xuất và Người Xuất)
            $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET MaCH=? WHERE MaPX=?");
            $stmt->execute([$maCH, $maPX]);
            
            // Xóa chi tiết cũ và thêm mới
            $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX=?");
            $stmt->execute([$maPX]);
            
            if (!empty($_POST['products'])) {
                $products = json_decode($_POST['products'], true);
                
                // Lấy mã CTPX lớn nhất hiện có
                $stmt = $pdo->query("SELECT MaCTPX FROM CHITIETPHIEUXUAT ORDER BY MaCTPX DESC LIMIT 1");
                $lastCTPX = $stmt->fetchColumn();
                $nextNumber = 1;
                if ($lastCTPX) {
                    $nextNumber = intval(substr($lastCTPX, 4)) + 1;
                }
                
                foreach ($products as $index => $product) {
                    // Lấy giá bán của sản phẩm
                    $stmtPrice = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                    $stmtPrice->execute([$product['MaSP']]);
                    $giaBan = $stmtPrice->fetchColumn();
                    $thanhTien = $product['SLX'] * $giaBan;
                    
                    $maCTPX = 'CTPX' . str_pad($nextNumber + $index, 3, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX, ThanhTien) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$maCTPX, $maPX, $product['MaSP'], $product['SLX'], $thanhTien]);
                }
            }
            
            // Ghi lịch sử hoạt động
            logActivity($pdo, $userId, $userName, 'Sửa', "PX: $maPX", "Cửa hàng: $maCH");
            
        } elseif ($action == 'delete') {
            // Xóa phiếu xuất (chỉ được xóa khi trạng thái "Đang xử lý")
            $maPXs = $_POST['MaPX'] ?? [];
            if (empty($maPXs) || !is_array($maPXs)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng chọn ít nhất một phiếu xuất để xóa!'];
                header("Location: exports.php");
                exit();
            }
            
            $deletedCount = 0;
            $errorMessages = [];
            
            foreach ($maPXs as $maPX) {
                // Kiểm tra trạng thái
                $stmt = $pdo->prepare("SELECT TinhTrang_PX, MaTK FROM PHIEUXUAT WHERE MaPX = ?");
                $stmt->execute([$maPX]);
                $phieu = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$phieu) {
                    $errorMessages[] = "Phiếu xuất $maPX không tồn tại.";
                    continue;
                }
                
                $tinhTrang = $phieu['TinhTrang_PX'];
                $maTK = $phieu['MaTK'];
                
                if ($tinhTrang != 'Đang xử lý') {
                    $errorMessages[] = "Phiếu xuất $maPX đã được xử lý, không thể xóa.";
                    continue;
                }
                
                // Kiểm tra quyền: Nhân viên chỉ được xóa phiếu của mình
                if ($userRole == 'Nhân viên' && $maTK != $userId) {
                    $errorMessages[] = "Bạn không có quyền xóa phiếu xuất $maPX.";
                    continue;
                }
                
                try {
                    // Xóa chi tiết trước
                    $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX=?");
                    $stmt->execute([$maPX]);
                    
                    // Xóa phiếu
                    $stmt = $pdo->prepare("DELETE FROM PHIEUXUAT WHERE MaPX=?");
                    $stmt->execute([$maPX]);
                    
                    // Ghi lịch sử hoạt động
                    logActivity($pdo, $userId, $userName, 'Xóa', "PX: $maPX", "Xóa phiếu xuất");
                    
                    $deletedCount++;
                } catch (Exception $e) {
                    $errorMessages[] = "Lỗi khi xóa phiếu xuất $maPX: " . $e->getMessage();
                }
            }
            
            if ($deletedCount > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'message' => "Đã xóa thành công $deletedCount phiếu xuất!"];
            }
            if (!empty($errorMessages)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => implode(' ', $errorMessages)];
            }
        } elseif ($action == 'edit_detail') {
            // Sửa chi tiết phiếu xuất - Cho phép sửa: Cửa hàng, SP, SLX
            $maPX = $_POST['MaPX'] ?? '';
            $maCH = $_POST['MaCH'] ?? '';
            $maCTPXs = $_POST['MaCTPX'] ?? [];
            $maSPs = $_POST['MaSP'] ?? [];
            $slxs = $_POST['SLX'] ?? [];
            
            // Kiểm tra trạng thái phiếu xuất - chỉ cho sửa khi "Đang xử lý"
            $stmt = $pdo->prepare("SELECT TinhTrang_PX, MaTK FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $phieu = $stmt->fetch();
            
            // Kiểm tra quyền: Nhân viên chỉ được sửa phiếu của mình
            if ($userRole == 'Nhân viên' && $userId && $phieu && $phieu['MaTK'] != $userId) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Bạn không có quyền sửa phiếu xuất này!'];
                header("Location: exports.php");
                exit();
            }
            
            if ($phieu && $phieu['TinhTrang_PX'] === 'Đang xử lý') {
                try {
                    $pdo->beginTransaction();
                    
                    // Cập nhật Cửa hàng của phiếu xuất
                    if (!empty($maCH)) {
                        $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET MaCH = ? WHERE MaPX = ?");
                        $stmt->execute([$maCH, $maPX]);
                    }
                    
                    // Lấy danh sách MaCTPX hiện có của phiếu xuất
                    $stmt = $pdo->prepare("SELECT MaCTPX FROM CHITIETPHIEUXUAT WHERE MaPX = ?");
                    $stmt->execute([$maPX]);
                    $existingCTPXs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Xóa các chi tiết không còn trong danh sách mới
                    $submittedCTPXs = array_filter($maCTPXs);
                    $toDelete = array_diff($existingCTPXs, $submittedCTPXs);
                    foreach ($toDelete as $maCTPXToDelete) {
                        $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaCTPX = ? AND MaPX = ?");
                        $stmt->execute([$maCTPXToDelete, $maPX]);
                    }
                    
                    // Cập nhật hoặc thêm mới chi tiết sản phẩm
                    foreach ($maCTPXs as $index => $maCTPX) {
                        $maSP = $maSPs[$index] ?? '';
                        $slx = $slxs[$index] ?? '';
                        
                        if (!empty($maSP) && !empty($slx)) {
                            if (!empty($maCTPX) && in_array($maCTPX, $existingCTPXs)) {
                                // Lấy giá bán và tính thành tiền
                                $stmtPrice = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                                $stmtPrice->execute([$maSP]);
                                $giaBan = $stmtPrice->fetchColumn();
                                $thanhTien = $slx * $giaBan;
                                
                                // Cập nhật chi tiết hiện có
                                $stmt = $pdo->prepare("UPDATE CHITIETPHIEUXUAT SET MaSP = ?, SLX = ?, ThanhTien = ? WHERE MaCTPX = ? AND MaPX = ?");
                                $stmt->execute([$maSP, $slx, $thanhTien, $maCTPX, $maPX]);
                            } else {
                                // Thêm mới chi tiết (sản phẩm mới)
                                // Lấy mã CTPX lớn nhất hiện có
                                $stmt = $pdo->query("SELECT MaCTPX FROM CHITIETPHIEUXUAT ORDER BY MaCTPX DESC LIMIT 1");
                                $lastCTPX = $stmt->fetchColumn();
                                $nextNumber = 1;
                                if ($lastCTPX) {
                                    $nextNumber = intval(substr($lastCTPX, 4)) + 1;
                                }
                                $newMaCTPX = 'CTPX' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
                                
                                // Lấy giá bán và tính thành tiền
                                $stmtPrice = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                                $stmtPrice->execute([$maSP]);
                                $giaBan = $stmtPrice->fetchColumn();
                                $thanhTien = $slx * $giaBan;
                                
                                $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX, ThanhTien) VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([$newMaCTPX, $maPX, $maSP, $slx, $thanhTien]);
                            }
                        }
                    }
                    
                    $pdo->commit();
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Sửa chi tiết phiếu xuất thành công!'];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi sửa: ' . $e->getMessage()];
                }
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Chỉ sửa được khi trạng thái là Đang xử lý'];
            }
            
        } elseif ($action == 'adjustment') {
            // Xử lý nhập SLX_MOI và trừ khỏi SLTK
            header('Content-Type: application/json');
            $maPX = $_POST['MaPX'] ?? '';
            $maCTPXs = $_POST['MaCTPX'] ?? [];
            $slx_mois = $_POST['SLX_MOI'] ?? [];

            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $phieu = $stmt->fetch();

            if ($phieu && $phieu['TinhTrang_PX'] === 'Có thay đổi') {
                try {
                    $pdo->beginTransaction();
                    
                    // Kiểm tra tồn kho trước khi trừ
                    foreach ($maCTPXs as $index => $maCTPX) {
                        $slx_moi = $slx_mois[$index] ?? 0;
                        
                        $stmt_sp = $pdo->prepare("SELECT ct.MaSP, sp.SLTK FROM CHITIETPHIEUXUAT ct JOIN SANPHAM sp ON ct.MaSP = sp.MaSP WHERE ct.MaCTPX = ?");
                        $stmt_sp->execute([$maCTPX]);
                        $sp = $stmt_sp->fetch();
                        
                        if ($sp && $sp['SLTK'] < $slx_moi) {
                            throw new Exception("Sản phẩm {$sp['MaSP']} không đủ tồn kho. Hiện tại: {$sp['SLTK']}, cần: {$slx_moi}");
                        }
                    }
                    
                    // Nếu đủ tồn kho, tiến hành cập nhật
                    foreach ($maCTPXs as $index => $maCTPX) {
                        $slx_moi = $slx_mois[$index] ?? 0;
                        $stmt = $pdo->prepare("UPDATE CHITIETPHIEUXUAT SET SLX_MOI = ? WHERE MaCTPX = ? AND MaPX = ?");
                        $stmt->execute([$slx_moi, $maCTPX, $maPX]);

                        // Trừ SLX_MOI khỏi SLTK (không phải SLX ban đầu)
                        $stmt_sp = $pdo->prepare("SELECT MaSP FROM CHITIETPHIEUXUAT WHERE MaCTPX = ?");
                        $stmt_sp->execute([$maCTPX]);
                        $sp = $stmt_sp->fetch();
                        if ($sp) {
                            $stmt_update = $pdo->prepare("
                                UPDATE SANPHAM 
                                SET SLTK = SLTK - ?, 
                                    TinhTrang = CASE WHEN SLTK - ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END 
                                WHERE MaSP = ?
                            ");
                            $stmt_update->execute([$slx_moi, $slx_moi, $sp['MaSP']]);
                        }
                    }
                    $pdo->commit();
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Không hợp lệ']);
            }
            exit();
            
        } elseif ($action == 'update_status') {
            // Cập nhật trạng thái - chuyển thành AJAX
            header('Content-Type: application/json');
            
            if (!in_array($userRole, ['Quản lý', 'Nhân viên'])) {
                echo json_encode(['success' => false, 'error' => 'Bạn không có quyền cập nhật trạng thái']);
                exit();
            }
            
            $maPX = $_POST['MaPX'] ?? '';
            $tinhTrangMoi = $_POST['TinhTrang'] ?? '';
            
            // Danh sách trạng thái hợp lệ
            $validStatuses = ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'];
            $finalStatuses = ['Hoàn thành', 'Có thay đổi']; // Chỉ 2 trạng thái này không được đổi
            
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $currentStatus = $stmt->fetchColumn();
            
            // Nếu trạng thái hiện tại là trạng thái cuối cùng, không cho phép đổi
            if (in_array($currentStatus, $finalStatuses)) {
                echo json_encode(['success' => false, 'error' => 'Phiếu xuất này đã được khóa và không thể thay đổi trạng thái']);
                exit();
            }
            
            if (in_array($tinhTrangMoi, $validStatuses)) {
                try {
                    $pdo->beginTransaction();
                    
                    // Lấy trạng thái cũ của phiếu xuất
                    $stmt = $pdo->prepare("SELECT TinhTrang_PX, MaTK FROM PHIEUXUAT WHERE MaPX = ?");
                    $stmt->execute([$maPX]);
                    $phieu = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Kiểm tra quyền: Nhân viên chỉ được đổi trạng thái phiếu của mình
                    if ($userRole == 'Nhân viên' && $userId && $phieu && $phieu['MaTK'] != $userId) {
                        echo json_encode(['success' => false, 'error' => 'Bạn không có quyền đổi trạng thái phiếu xuất này']);
                        exit();
                    }
                    
                    $oldStatus = $phieu['TinhTrang_PX'];
                    
                    // Cập nhật trạng thái mới cho phiếu xuất
                    $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET TinhTrang_PX = ? WHERE MaPX = ?");
                    $stmt->execute([$tinhTrangMoi, $maPX]);

                    // Nếu chuyển sang trạng thái "Hoàn thành"
                    if ($tinhTrangMoi == 'Hoàn thành' && !in_array($oldStatus, ['Hoàn thành', 'Có thay đổi'])) {
                        // Cập nhật số lượng tồn kho cho từng sản phẩm trong phiếu xuất (trừ SLX gốc)
                        $stmt = $pdo->prepare("
                            UPDATE SANPHAM sp
                            INNER JOIN CHITIETPHIEUXUAT ct ON sp.MaSP = ct.MaSP
                            SET 
                                sp.SLTK = sp.SLTK - ct.SLX,
                                sp.TinhTrang = CASE 
                                    WHEN sp.TinhTrang = 'Ngừng kinh doanh' THEN 'Ngừng kinh doanh'
                                    WHEN (sp.SLTK - ct.SLX) > 0 THEN 'Còn hàng'
                                    ELSE 'Hết hàng'
                                END
                            WHERE ct.MaPX = ?
                        ");
                        $stmt->execute([$maPX]);
                    }
                    
                    $pdo->commit();
                    
                    // Ghi lịch sử hoạt động
                    logActivity($pdo, $userId, $userName, 'Đổi trạng thái', "PX: $maPX", "Từ: $oldStatus → Tới: $tinhTrangMoi");
                    
                    echo json_encode(['success' => true, 'newStatus' => $tinhTrangMoi, 'needsAdjustment' => ($tinhTrangMoi == 'Có thay đổi')]);
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Trạng thái không hợp lệ']);
            }
            exit();
        }
        
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Thao tác thành công!'];
        header("Location: exports.php");
        exit();
        
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi: ' . $e->getMessage()];
        header("Location: exports.php");
        exit();
    }
}


// ============================
//  LẤY DANH SÁCH PHIẾU XUẤT
// ============================
$search = $_GET['search'] ?? '';
// Ràng buộc phân quyền: Nhân viên chỉ thấy phiếu của mình, Quản lý thấy tất cả
$permissionFilter = '';
if ($userRole == 'Nhân viên' && $userId) {
    $permissionFilter = "WHERE px.MaTK = '$userId'";
}

$searchFilter = $search ? ($permissionFilter ? "AND" : "WHERE") . " (px.MaPX LIKE '%$search%' OR ch.TenCH LIKE '%$search%' OR tk.TenTK LIKE '%$search%' OR px.NgayXuat LIKE '%$search%' OR px.TinhTrang_PX LIKE '%$search%' OR EXISTS (SELECT 1 FROM CHITIETPHIEUXUAT cte JOIN SANPHAM spe ON cte.MaSP=spe.MaSP WHERE cte.MaPX=px.MaPX AND spe.TenSP LIKE '%$search%'))" : '';
$where = $permissionFilter . $searchFilter;

// Phân trang đầu phiếu PX
$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Tổng số đầu phiếu
$countStmt = $pdo->query("
    SELECT COUNT(*) as total
    FROM PHIEUXUAT px
    LEFT JOIN CUAHANG ch ON px.MaCH = ch.MaCH
    LEFT JOIN TAIKHOAN tk ON px.MaTK = tk.MaTK
    " . ($where ? $where : '') . "
");
$totalRows = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Lấy danh sách đầu phiếu trang hiện tại
$stmt = $pdo->prepare("
    SELECT px.MaPX, px.NgayXuat, px.TinhTrang_PX, ch.MaCH, ch.TenCH, tk.TenTK, tk.MaTK
    FROM PHIEUXUAT px
    LEFT JOIN CUAHANG ch ON px.MaCH = ch.MaCH
    LEFT JOIN TAIKHOAN tk ON px.MaTK = tk.MaTK
    $where
    ORDER BY px.MaPX DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy chi tiết theo MaPX ở trang hiện tại
$groupedExports = [];
$maPXs = array_column($headers, 'MaPX');
if (!empty($maPXs)) {
    foreach ($headers as $h) {
        $groupedExports[$h['MaPX']] = [
            'info' => [
                'MaPX' => $h['MaPX'],
                'NgayXuat' => $h['NgayXuat'],
                'TenCH' => $h['TenCH'],
                'TenTK' => $h['TenTK'],
                'MaTK' => $h['MaTK'],
                'TinhTrang_PX' => $h['TinhTrang_PX']
            ],
            'details' => []
        ];
    }
    $inPlaceholders = implode(',', array_fill(0, count($maPXs), '?'));
    $stmt = $pdo->prepare("
        SELECT ct.MaPX, ct.MaCTPX, ct.MaSP, ct.SLX, ct.SLX_MOI, ct.ThanhTien, sp.TenSP, sp.GiaBan
        FROM CHITIETPHIEUXUAT ct
        LEFT JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
        WHERE ct.MaPX IN ($inPlaceholders)
        ORDER BY ct.MaPX, sp.TenSP
    ");
    $stmt->execute($maPXs);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($details as $row) {
        $groupedExports[$row['MaPX']]['details'][] = [
            'TenSP' => $row['TenSP'],
            'SLX' => $row['SLX'],
            'GiaBan' => $row['GiaBan'],
            'ThanhTien' => $row['ThanhTien'] ?? ($row['SLX'] * $row['GiaBan']),
            'SLX_MOI' => $row['SLX_MOI'] ?? null,
            'MaCTPX' => $row['MaCTPX'],
            'MaSP' => $row['MaSP']
        ];
    }
}

// Lấy danh sách cửa hàng cho dropdown
$stmtCH = $pdo->query("SELECT MaCH, TenCH FROM CUAHANG ORDER BY TenCH");
$cuaHangs = $stmtCH->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách sản phẩm cho dropdown
$stmtSP = $pdo->query("SELECT MaSP, TenSP, SLTK FROM SANPHAM WHERE TinhTrang != 'Ngừng kinh doanh' ORDER BY TenSP");
$sanPhams = $stmtSP->fetchAll(PDO::FETCH_ASSOC);

// Mapping trạng thái database sang hiển thị
function getTrangThaiDisplay($tinhTrang) {
    $mapping = [
        'Đang xử lý' => 'Chờ duyệt',
        'Bị từ chối' => 'Bị từ chối',
        'Đã duyệt' => 'Đã duyệt',
        'Hoàn thành' => 'Hoàn thành',
        'Có thay đổi' => 'Có thay đổi'
    ];
    return $mapping[$tinhTrang] ?? $tinhTrang;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Sản Phẩm - Hệ Thống Quản Lý Kho Tink</title>
    <!-- Liên kết CSS -->
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo time(); ?>">
    <!-- Thêm Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>
<body>
    <!-- Header --> 
    <header class="header">
        <button class="mobile-menu-toggle" onclick="toggleSidebar(); return false;" aria-label="Toggle menu">
            <i class="fas fa-bars"></i>
        </button> 
        <div class="logo">TINK <span>Jewelry</span></div> 
        <div class="user-section"> 
            <div class="user-info"> 
                <div class="user-name"><?php echo htmlspecialchars($userName); ?></div> 
                <div class="user-role"><?php echo htmlspecialchars($userRole); ?></div> 
            </div> 
            <div class="user-avatar"> <i class="fas fa-user"></i> </div> 
        </div> 
    </header>

    <div class="dashboard-layout">
    <!-- SIDEBAR --> 
    <?php require_once './sidebar.php'; ?>

<!-- MAIN CONTENT -->
        </aside>

<!-- MAIN CONTENT --> 
 <main class="main-content"> 
    <div class="management-header"> 
        <div class="management-topbar"> 
            <h2>Quản Lý Xuất Kho</h2> 
            <div class="management-tools"> 
                <form method="GET" class="search-form"> 
                    <input type="text" placeholder="Tìm kiếm..." name="search" value="<?php echo htmlspecialchars($search); ?>"> 
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i></button> 
                </form>
                <button class="column-toggle-btn" onclick="showColumnToggle('table.management-table')">
                    <i class="fas fa-sliders-h"></i> Tùy chọn cột
                </button>
                <button class="add-btn" onclick="openModal('addModal')"> 
                    <i class="fas fa-plus"></i> Thêm Phiếu Xuất </button>
                <button class="add-btn import-btn" onclick="openModal('importExcelModal')">
                    <i class="fas fa-file-excel"></i> Import Excel
                </button>
                <button class="btn-export-pdf" id="exportPdfBtn" onclick="exportExportPDF()" disabled> 
                    <i class="fas fa-file-pdf"></i> Xuất PDF </button>
                <?php if ($userRole != 'Nhân viên'): ?>
                <button class="delete-btn" id="deleteSelectedBtn" onclick="deleteSelectedExports()" disabled> 
                    <i class="fas fa-trash"></i> Xóa Đã Chọn </button> 
                <?php endif; ?> 
            </div> 
        </div> 
    </div> 
        <!-- Thông báo kết quả tìm kiếm --> 
         <?php if (!empty($searchMessage ?? '')): ?> 
    <div style="margin-bottom: 15px; padding: 12px; border-radius: 8px; 
                background: <?php echo strpos($searchMessage ?? '', 'Không tìm thấy') !== false ? '#ffebee' : '#e8f5e8'; ?>; 
                color: <?php echo strpos($searchMessage ?? '', 'Không tìm thấy') !== false ? '#c62828' : '#2e7d32'; ?>;"> 
        <?php echo htmlspecialchars($searchMessage ?? ''); ?> 
    </div> 
<?php endif; ?>
 
        <!-- Flash message --> 
         <?php if ($flash): ?> 
            <div id="flashMessage" style="margin-bottom: 15px; padding: 12px; border-radius: 8px; 
                        background: <?php echo ($flash['type'] ?? '') === 'error' ? '#ffebee' : '#e8f5e8'; ?>; 
                        color: <?php echo ($flash['type'] ?? '') === 'error' ? '#c62828' : '#2e7d32'; ?>;"> 
                <?php echo htmlspecialchars($flash['message']); ?> 
            </div> 
        <?php endif; ?>

        <!-- Kết quả Import Excel (nếu có) -->
        <?php if ($exportImportResult): ?>
            <div style="margin-bottom: 15px; padding: 12px; border-radius: 8px; background: #e3f2fd; color: #0d47a1;">
                <strong>Kết quả import phiếu xuất:</strong><br>
                - Dòng hợp lệ: <?php echo (int)($exportImportResult['success_rows'] ?? 0); ?><br>
                - Dòng lỗi: <?php echo (int)($exportImportResult['error_count'] ?? 0); ?><br>
                - Phiếu xuất tạo mới: <?php echo (int)($exportImportResult['created_exports'] ?? 0); ?>
                <?php if (!empty($exportImportResult['errors'])): ?>
                    <details style="margin-top: 8px;">
                        <summary>Chi tiết lỗi</summary>
                        <ul style="margin-top: 6px; padding-left: 20px;">
                            <?php foreach ($exportImportResult['errors'] as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="management-container">
        <table class="management-table wide-layout">
                <thead>
                    <tr>
                        <th data-column="mapx">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Mã PX</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th data-column="ngayxuat">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Ngày Xuất</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th data-column="cuahang">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Cửa Hàng</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th data-column="nguoixuat">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Người Xuất</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th data-column="sanpham">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Sản Phẩm</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th data-column="soluong">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Số Lượng</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th data-column="giaxuat">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Giá Xuất</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th data-column="thanhtien">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Thành Tiền</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th class="col-new-qty" data-column="slnmoi">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Số lượng mới</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th class="status-column" data-column="tinhtrang">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Tình Trạng</span>
                                <button class="th-filter-btn" type="button">
                                    <i class="fas fa-filter caret"></i>
                                </button>
                                <div class="th-filter-menu">
                                    <button data-sort="asc">Tăng dần (A-Z, 0-9)</button>
                                    <button data-sort="desc">Giảm dần (Z-A, 9-0)</button>
                                    <div class="th-sep"></div>
                                    <div class="th-values-block">
                                        <div class="th-values-actions">
                                            <span>Lọc theo giá trị</span>
                                        </div>
                                        <div class="th-values-list"></div>
                                    </div>
                                    <div class="th-filter-actions">
                                        <button class="primary" data-action="filter">Áp dụng</button>
                                        <button class="ghost" data-action="clear">Xóa bộ lọc</button>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th class="actions-column" data-column="actions">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $finalStatuses = ['Hoàn thành', 'Có thay đổi']; // Chỉ 2 trạng thái này không được đổi
                    $mutableStatuses = ['Đang xử lý']; // Chỉ cho sửa khi trạng thái 'Đang xử lý'
                    ?>
                    <?php foreach ($groupedExports as $maPX => $export): ?>
                        <?php 
                        $rowspan = max(1, count($export['details']));
                        $canEdit = in_array($export['info']['TinhTrang_PX'], $mutableStatuses) && 
                                  ($userRole == 'Quản lý' || $export['info']['MaTK'] == $userId);
                        $isLocked = in_array($export['info']['TinhTrang_PX'], $finalStatuses);
                        ?>
                        <?php if (empty($export['details'])): ?>
                            <tr class="selectable-row" data-id="<?php echo htmlspecialchars($export['info']['MaPX']); ?>" onclick="toggleRowSelection(this, event)">
                                <td data-column="mapx"><?php echo htmlspecialchars($export['info']['MaPX']); ?></td>
                                <td data-column="ngayxuat"><?php echo date('d/m/Y', strtotime($export['info']['NgayXuat'])); ?></td>
                                <td data-column="cuahang"><?php echo htmlspecialchars($export['info']['TenCH']); ?></td>
                                <td data-column="nguoixuat"><?php echo htmlspecialchars($export['info']['TenTK']); ?></td>
                                <td data-column="sanpham"><em>Chưa có sản phẩm</em></td>
                                <td data-column="soluong"><em>0</em></td>
                                <td data-column="giaxuat"><em>-</em></td>
                                <td data-column="thanhtien"><em>-</em></td>
                                <td class="col-new-qty" data-column="slnmoi"></td>
                                <td class="status-cell" data-column="tinhtrang"><?php echo htmlspecialchars($export['info']['TinhTrang_PX']); ?></td>
                                <td class="management-actions" data-column="actions">
                                    <?php if ($canEdit): ?>
                                        <button class="edit-btn" onclick="editExport('<?php echo $export['info']['MaPX']; ?>')">Sửa</button>
                                    <?php else: ?>
                                        <button class="edit-btn disabled-btn" disabled title="Chỉ sửa được khi trạng thái là 'Đang xử lý'">Sửa</button>
                                    <?php endif; ?>
                                                            <?php if (in_array($userRole, ['Quản lý', 'Nhân viên'])): ?>
                                                                <?php if ($isLocked): ?>
                                                                    <button class="edit-btn disabled-btn" disabled title="Phiếu xuất này đã bị khóa">Đổi trạng thái</button>
                                                                <?php else: ?>
                                                                    <button class="edit-btn" style="background: var(--warning)" onclick="changeStatus('<?php echo $export['info']['MaPX']; ?>')">Đổi trạng thái</button>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($export['details'] as $index => $detail): ?>
                                <tr class="<?php echo $index === 0 ? 'selectable-row' : ''; ?>" <?php echo $index === 0 ? 'data-id="' . htmlspecialchars($export['info']['MaPX']) . '" onclick="toggleRowSelection(this, event)"' : ''; ?>>
                                    <?php if ($index === 0): ?>
                                        <td rowspan="<?php echo $rowspan; ?>" data-column="mapx"><?php echo htmlspecialchars($export['info']['MaPX']); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>" data-column="ngayxuat"><?php echo date('d/m/Y', strtotime($export['info']['NgayXuat'])); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>" data-column="cuahang"><?php echo htmlspecialchars($export['info']['TenCH']); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>" data-column="nguoixuat"><?php echo htmlspecialchars($export['info']['TenTK']); ?></td>
                                    <?php endif; ?>
                                    <td data-column="sanpham"><?php echo htmlspecialchars($detail['TenSP']); ?></td>
                                    <td data-column="soluong"><?php echo htmlspecialchars($detail['SLX']); ?> cái</td>
                                    <td data-column="giaxuat"><?php echo number_format($detail['GiaBan'], 0, ',', '.'); ?> VNĐ</td>
                                    <td data-column="thanhtien"><?php echo number_format($detail['ThanhTien'], 0, ',', '.'); ?> VNĐ</td>
                                    <td class="col-new-qty" data-column="slnmoi"><?php echo ($detail['SLX_MOI'] !== null) ? $detail['SLX_MOI'] . ' cái' : (($export['info']['TinhTrang_PX'] === 'Có thay đổi') ? 'Chưa cập nhật' : '-'); ?></td>
                                    <?php if ($index === 0): ?>
                                        <td rowspan="<?php echo $rowspan; ?>" class="status-cell" data-column="tinhtrang"><?php echo htmlspecialchars($export['info']['TinhTrang_PX']); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>" class="management-actions" data-column="actions">
                                            <?php if ($canEdit): ?>
                                                <button class="edit-btn" onclick="editExportDetail('<?php echo $export['info']['MaPX']; ?>')">Sửa</button>
                                            <?php else: ?>
                                                <button class="edit-btn disabled-btn" disabled title="Chỉ sửa được khi trạng thái là 'Đang xử lý'">Sửa</button>
                                            <?php endif; ?>
                                            <?php if (in_array($userRole, ['Quản lý', 'Nhân viên'])): ?>
                                                <?php if ($isLocked): ?>
                                                    <button class="edit-btn disabled-btn" disabled title="Phiếu xuất này đã bị khóa">Đổi trạng thái</button>
                                                <?php else: ?>
                                                    <button class="edit-btn" style="background: var(--warning)" onclick="changeStatus('<?php echo $export['info']['MaPX']; ?>')">Đổi trạng thái</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
        </table>
        <?php if (($totalPages ?? 1) > 1): ?>
        <div class="pagination" style="margin-top: 12px; display: flex; gap: 6px; flex-wrap: wrap; justify-content: center;">
            <?php
                $baseUrl = 'exports.php';
                $params = $_GET;
                unset($params['page']);
                $queryBase = http_build_query($params);
                function pageLinkExp($p, $queryBase, $baseUrl) { 
                    $q = $queryBase ? ($queryBase . '&page=' . $p) : ('page=' . $p);
                    return $baseUrl . '?' . $q;
                }
            ?>
            <a href="<?php echo pageLinkExp(max(1, $page-1), $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; background:#eee; border-radius:6px;<?php echo $page==1?' pointer-events:none; opacity:.5;':''; ?>">«</a>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="<?php echo pageLinkExp($p, $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; border-radius:6px; <?php echo $p==$page?'background: var(--primary); color:#fff;':'background:#eee;'; ?>">
                    <?php echo $p; ?>
                </a>
            <?php endfor; ?>
            <a href="<?php echo pageLinkExp(min($totalPages, $page+1), $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; background:#eee; border-radius:6px;<?php echo $page==$totalPages?' pointer-events:none; opacity:.5;':''; ?>">»</a>
        </div>
        <?php endif; ?>
        </div>
    </div>
</main> 

    <!-- Modal Import Excel Phiếu Xuất -->
    <div id="importExcelModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('importExcelModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: #004080;">
                <i class="fas fa-file-import"></i> Import Phiếu Xuất từ Excel
            </h2>
            <form method="POST" action="exports_import_excel.php" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Chọn file Excel (.xlsx): <span style="color: red;">*</span></label>
                    <input 
                        type="file" 
                        name="excel_file" 
                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        required
                    >
                    <small style="color: #666; font-size: 12px;">
                        File phải là định dạng .xlsx, dòng đầu tiên là tiêu đề cột.<br>
                        Cần có tối thiểu các cột: <strong>Mã phiếu</strong>, <strong>Mã cửa hàng</strong>, <strong>Mã sản phẩm</strong>, <strong>Số lượng</strong>.<br>
                        Cột tùy chọn: <strong>Ngày xuất</strong> (yyyy-mm-dd), <strong>Mã TK</strong>, <strong>Tình trạng</strong> (Đang xử lý / Đã duyệt / Hoàn thành / Có thay đổi / Bị từ chối).
                    </small>
                </div>
                <div class="form-group import-hint">
                    <strong>Quy tắc xử lý:</strong>
                    <ul>
                        <li>Bỏ qua dòng trống hoặc thiếu dữ liệu bắt buộc.</li>
                        <li>Nếu Mã phiếu đã tồn tại, toàn bộ dòng liên quan sẽ bị bỏ qua.</li>
                        <li>Tất cả dòng cùng Mã phiếu phải cùng một Mã cửa hàng.</li>
                        <li>Trạng thái "Hoàn thành" sẽ tự động trừ tồn kho sản phẩm.</li>
                    </ul>
                </div>
                <div class="modal-actions" style="margin-top: 18px;">
                    <button type="button" class="btn-cancel" onclick="closeModal('importExcelModal')">Hủy</button>
                    <button type="submit" class="btn-save" style="background: #004080;">Thực hiện Import</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Tùy chọn cột -->
    <div id="columnToggleModal" class="column-toggle-modal" onclick="closeColumnToggleOnBackdrop(event)">
        <div class="column-toggle-content" onclick="event.stopPropagation()">
            <div class="column-toggle-header">
                <h3><i class="fas fa-columns"></i> Tùy chọn hiển thị cột</h3>
                <button class="column-toggle-close" onclick="closeColumnToggle()">&times;</button>
            </div>
            <div class="column-toggle-list" id="columnToggleList">
                <div class="column-toggle-item"><input type="checkbox" id="col-mapx" data-column="mapx" checked><label for="col-mapx">Mã PX</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-ngayxuat" data-column="ngayxuat" checked><label for="col-ngayxuat">Ngày Xuất</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-cuahang" data-column="cuahang" checked><label for="col-cuahang">Cửa Hàng</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-nguoixuat" data-column="nguoixuat" checked><label for="col-nguoixuat">Người Xuất</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-sanpham" data-column="sanpham" checked><label for="col-sanpham">Sản Phẩm</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-soluong" data-column="soluong" checked><label for="col-soluong">Số Lượng</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-giaxuat" data-column="giaxuat" checked><label for="col-giaxuat">Giá Xuất</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-thanhtien" data-column="thanhtien" checked><label for="col-thanhtien">Thành Tiền</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-slnmoi" data-column="slnmoi" checked><label for="col-slnmoi">Số lượng mới</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-tinhtrang" data-column="tinhtrang" checked><label for="col-tinhtrang">Tình Trạng</label></div>
                <div class="column-toggle-item"><input type="checkbox" id="col-actions" data-column="actions" checked disabled><label for="col-actions" style="opacity:0.6;">Thao Tác (Không thể ẩn)</label></div>
            </div>
            <div class="column-toggle-actions">
                <button class="column-toggle-reset" onclick="resetColumnToggle()">Đặt lại mặc định</button>
                <button class="column-toggle-apply" onclick="applyColumnToggle()">Áp dụng</button>
            </div>
        </div>
    </div>

    <script>
        // Column toggle for exports
        const EXPORTS_STORAGE_KEY = 'exports_column_preferences';
        let _exports_table_selector = 'table.management-table';

        function showColumnToggle(selector) {
            _exports_table_selector = selector || _exports_table_selector;
            const modal = document.getElementById('columnToggleModal');
            modal.classList.add('show');
            loadCheckboxStates();
        }

        function closeColumnToggle() {
            const modal = document.getElementById('columnToggleModal');
            modal.classList.remove('show');
        }

        function closeColumnToggleOnBackdrop(event) {
            if (event.target.id === 'columnToggleModal') closeColumnToggle();
        }

        function loadCheckboxStates() {
            const preferences = JSON.parse(localStorage.getItem(EXPORTS_STORAGE_KEY) || '{}');
            const checkboxes = document.querySelectorAll('#columnToggleList input[type="checkbox"]');
            checkboxes.forEach(cb => {
                const col = cb.getAttribute('data-column');
                cb.checked = preferences.hasOwnProperty(col) ? !!preferences[col] : true;
            });
        }

        function resetColumnToggle() {
            const checkboxes = document.querySelectorAll('#columnToggleList input[type="checkbox"]');
            checkboxes.forEach(cb => {
                // Giữ nguyên trạng thái của cột actions (disabled checkbox)
                if (cb.hasAttribute('disabled')) {
                    cb.checked = true; // Đảm bảo actions luôn checked
                } else {
                    cb.checked = true; // Reset tất cả các cột khác về checked
                }
            });
            applyColumnToggle();
        }

        function applyColumnToggle() {
            const preferences = {};
            const checkboxes = document.querySelectorAll('#columnToggleList input[type="checkbox"]');
            checkboxes.forEach(cb => {
                const col = cb.getAttribute('data-column');
                preferences[col] = cb.checked;
                // Đảm bảo cột actions luôn là true
                if (col === 'actions') {
                    preferences[col] = true;
                }
            });
            console.log('Preferences to apply:', preferences);
            localStorage.setItem(EXPORTS_STORAGE_KEY, JSON.stringify(preferences));
            applyColumnVisibility(preferences);
            // Không thay đổi width của các cột - giữ nguyên width ban đầu
            closeColumnToggle();
        }

        function applyColumnVisibility(preferences) {
            // Tìm bảng trong container - thử nhiều cách, ưu tiên wide-layout
            let table = document.querySelector('.management-container table.management-table.wide-layout');
            if (!table) {
                table = document.querySelector('table.management-table.wide-layout');
            }
            if (!table) {
                table = document.querySelector('.management-container table.management-table');
            }
            if (!table) {
                table = document.querySelector('table.management-table');
            }
            if (!table) {
                table = document.querySelector(_exports_table_selector);
            }
            if (!table) {
                console.error('Không tìm thấy bảng để áp dụng tùy chọn cột');
                return;
            }
            
            console.log('Bảng tìm thấy:', table);
            console.log('Preferences:', preferences);
            
            // Áp dụng visibility cho từng cột
            Object.keys(preferences).forEach(col => {
                // Đảm bảo cột actions luôn được hiển thị
                if (col === 'actions') {
                    const cells = table.querySelectorAll(`th[data-column="${col}"], td[data-column="${col}"]`);
                    console.log(`Cột ${col} (actions): tìm thấy ${cells.length} cells`);
                    cells.forEach(cell => {
                        cell.classList.remove('hidden');
                        cell.style.display = '';
                    });
                    return;
                }
                const isVisible = preferences[col];
                // Tìm tất cả cells có data-column, bao gồm cả th và td
                const cells = table.querySelectorAll(`th[data-column="${col}"], td[data-column="${col}"]`);
                console.log(`Cột ${col}: isVisible=${isVisible}, tìm thấy ${cells.length} cells`);
                
                if (cells.length === 0) {
                    console.warn(`Không tìm thấy cell nào cho cột ${col}`);
                }
                
                cells.forEach(cell => {
                    if (isVisible) {
                        cell.classList.remove('hidden');
                        cell.style.display = '';
                    } else {
                        cell.classList.add('hidden');
                        cell.style.display = 'none';
                    }
                });
            });
            // Đảm bảo cột actions luôn visible (trong trường hợp preferences không có key 'actions')
            const actionsCells = table.querySelectorAll(`th[data-column="actions"], td[data-column="actions"]`);
            actionsCells.forEach(cell => {
                cell.classList.remove('hidden');
                cell.style.display = '';
            });
            // Không thay đổi width của các cột - giữ nguyên width ban đầu
        }

        function loadColumnPreferences() {
            const preferences = JSON.parse(localStorage.getItem(EXPORTS_STORAGE_KEY) || '{}');
            if (Object.keys(preferences).length > 0) {
                // Đảm bảo cột actions luôn visible
                preferences['actions'] = true;
                applyColumnVisibility(preferences);
            }
        }

                function updateColumnWidths(table) {
                    if (!table) return;
                    const headerCells = Array.from(table.querySelectorAll('thead th'));
                    const toggleable = headerCells.filter(th => !th.classList.contains('actions-column'));
                    const visible = toggleable.filter(th => !th.classList.contains('hidden'));
                    if (visible.length === 0) return;

                    const targetCount = Math.min(visible.length, 5);
                    const targetHeaders = visible.slice(0, targetCount);

                    const tableRect = table.getBoundingClientRect();
                    let actionsWidth = 0;
                    const actionsTh = headerCells.find(th => th.classList.contains('actions-column'));
                    if (actionsTh) actionsWidth = actionsTh.getBoundingClientRect().width || 0;

                    const available = Math.max(0, tableRect.width - actionsWidth - 20);
                    let equal = Math.floor(available / targetCount) || 80;
                    if (equal < 80) equal = 80;

                    targetHeaders.forEach(th => {
                        th.style.width = equal + 'px'; th.style.minWidth = equal + 'px';
                        const col = th.getAttribute('data-column'); if (!col) return;
                        const cells = table.querySelectorAll(`[data-column="${col}"]`);
                        cells.forEach(td => { td.style.width = equal + 'px'; td.style.minWidth = equal + 'px'; });
                    });

                    const other = visible.slice(targetCount);
                    other.forEach(th => {
                        const col = th.getAttribute('data-column'); if (!col) return;
                        th.style.width = ''; th.style.minWidth = '';
                        const cells = table.querySelectorAll(`[data-column="${col}"]`);
                        cells.forEach(td => { td.style.width = ''; td.style.minWidth = ''; });
                    });

                    table.style.minWidth = (equal * targetCount + actionsWidth + 20) + 'px';
                }

                function clearInlineColumnWidths(table) {
                    if (!table) return;
                    const cells = table.querySelectorAll('thead th[data-column], tbody td[data-column]');
                    cells.forEach(el => { el.style.width = ''; el.style.minWidth = ''; el.style.maxWidth = ''; });
                    table.style.minWidth = '';
                }

        // run on load
        document.addEventListener('DOMContentLoaded', function() { loadColumnPreferences(); });
    </script>

    <!-- Modal Thêm/Sửa Phiếu Xuất -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: var(--primary);">Thêm Phiếu Xuất</h2>
            
            <form method="POST" id="phieuForm">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="products" id="productsData" value="">
                
                <div class="form-group">
                <label>Mã Phiếu Xuất:</label>
                <?php 
                $nextMaPX = generateMaPX($pdo);
                ?>
                <input type="text" id="MaPX" value="<?php echo $nextMaPX; ?>" disabled 
                       style="background-color: #f0f0f0;">
                </div>
                
                <div class="form-group">
                <label>Ngày Xuất:</label>
                <input type="date" name="NgayXuat" id="NgayXuat" required>
                </div>

                <div class="form-group">
                <label>Cửa Hàng:</label>
                <select name="MaCH" id="MaCH" required>
                    <option value="">Chọn cửa hàng</option>
                    <?php foreach ($cuaHangs as $ch): ?>
                        <option value="<?php echo $ch['MaCH']; ?>"><?php echo htmlspecialchars($ch['TenCH']); ?></option>
                    <?php endforeach; ?>
                </select>
                </div>
                
                <div class="form-group">
                <label>Người Xuất:</label>
                <?php if ($userRole == 'Nhân viên' && $userId): ?>
                    <?php
                    // Lấy tên người đăng nhập
                    $stmt = $pdo->prepare("SELECT TenTK FROM TAIKHOAN WHERE MaTK = ?");
                    $stmt->execute([$userId]);
                    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <input type="hidden" name="MaTK" id="MaTK" value="<?php echo htmlspecialchars($userId); ?>">
                    <input type="text" value="<?php echo htmlspecialchars($currentUser['TenTK'] ?? 'Bạn'); ?>" disabled 
                           style="background-color: #f0f0f0;">
                    <small style="color: #666; font-size: 12px;">(Tự động gán cho bạn)</small>
                <?php else: ?>
                    <select name="MaTK" id="MaTK" required>
                        <option value="">Chọn người xuất</option>
                        <?php
                        $users = $pdo->query("SELECT MaTK, TenTK FROM TAIKHOAN")->fetchAll();
                        foreach ($users as $user) {
                            $selected = ($user['MaTK'] == $userId) ? 'selected' : '';
                            echo "<option value='{$user['MaTK']}' {$selected}>{$user['TenTK']}</option>";
                        }
                        ?>
                    </select>
                <?php endif; ?>
                </div>

                <div class="form-group">
                <label>Tình Trạng:</label>
                <select name="TinhTrang_PX" id="TinhTrang_PX" required>
                    <option value="Đang xử lý" selected>Đang xử lý</option>
                    <option value="Đã duyệt">Đã duyệt</option>
                    <option value="Bị từ chối">Bị từ chối</option>
                    <option value="Hoàn thành">Hoàn thành</option>
                    <option value="Có thay đổi">Có thay đổi</option>
                </select>
                </div>
                
                <div class="form-group">
                <h3>Chi Tiết Sản Phẩm</h3>
                <div id="productsList">
                    <div class="product-row" style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <select class="product-select" required style="width: 100%; padding: 8px;">
                                <option value="">Chọn sản phẩm</option>
                                <?php foreach ($sanPhams as $sp): ?>
                                    <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                                        <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <input type="number" class="product-quantity" placeholder="Số lượng" min="1" required style="width: 100%; padding: 8px;">
                        </div>
                        <div>
                            <button type="button" class="delete-btn" onclick="removeProductRow(this)" style="padding: 8px;">×</button>
                        </div>
                    </div>
                </div>
                </div>
                
                <button type="button" onclick="addProductRow()" class="btn-update" style="margin: 10px 0;"><i class="fas fa-plus"></i> Thêm sản phẩm</button>
                <button type="submit" class="btn-save" onclick="return validateForm()">Lưu</button>
            </form>
        </div>
    </div>

    <!-- Modal Xem Chi Tiết -->
    <div id="detailModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeModal('detailModal')">&times;</span>
            <h2>Chi Tiết Phiếu Xuất</h2>
            <div id="detailInfo"></div>
        </div>
    </div>

    <!-- Modal sửa chi tiết phiếu xuất -->
    <div id="editDetailModal" class="modal">
        <div class="modal-content modal-medium">
            <span class="close" onclick="closeModal('editDetailModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: var(--primary);">Sửa Chi Tiết Phiếu Xuất</h2>
            
            <div class="form-group">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <label>Mã Phiếu Xuất:</label>
                        <input type="text" id="editMaPX" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                    <div>
                        <label>Ngày Xuất:</label>
                        <input type="text" id="editNgayXuat" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                    <div>
                        <label>Cửa Hàng: <span style="color: red;">*</span></label>
                        <select id="editMaCH" name="MaCH" required style="width: 100%; padding: 8px;">
                            <option value="">Chọn cửa hàng</option>
                            <?php foreach ($cuaHangs as $ch): ?>
                                <option value="<?php echo $ch['MaCH']; ?>"><?php echo htmlspecialchars($ch['TenCH']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Tình Trạng:</label>
                        <input type="text" id="editTinhTrang" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                </div>
            </div>

            <div class="form-group">
            <h3>Chi Tiết Sản Phẩm</h3>
            <div id="detailsList" style="max-height: 400px; overflow-y: auto;">
                <!-- Sẽ được load bằng JavaScript -->
            </div>
            </div>
        </div>
    </div>

    <!-- Modal đổi trạng thái -->
    <div id="statusModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <span class="close" onclick="closeModal('statusModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: var(--primary);">Đổi Trạng Thái Phiếu Xuất</h2>
            <p id="statusMaPX" style="margin-bottom: 25px; font-weight: 600; color: #ff9800; font-size: 16px; padding: 10px; background-color: #fff3e0; border-radius: 5px; border-left: 4px solid #ff9800;"></p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <button class="status-btn" onclick="updateStatus('Đang xử lý')" style="background-color: #ff9800; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';">
                    ⏳ Đang xử lý
                </button>
                
                <button class="status-btn" onclick="updateStatus('Đã duyệt')" style="background-color: #4caf50; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';">
                    ✓ Đã duyệt
                </button>
                
                <button class="status-btn" onclick="updateStatus('Bị từ chối')" style="background-color: #f44336; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';"> 
                    ✗ Bị từ chối
                </button>
                
                <button class="status-btn" onclick="updateStatus('Hoàn thành')" style="background-color: #2196f3; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';">
                    ✓ Hoàn thành
                </button>
                
                <button class="status-btn" onclick="updateStatus('Có thay đổi')" style="background-color: #ff6f00; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';">
                    ⚠ Có thay đổi
                </button>
            </div>
        </div>
    </div>

    <!-- Modal nhập số lượng mới khi trạng thái "Có thay đổi" -->
    <div id="adjustmentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('adjustmentModal')">&times;</span>
            <h2>Nhập Số Lượng Mới</h2>
            <p id="adjustmentMaPX" style="margin-bottom: 20px; font-weight: 600; color: #ff9800;"></p>
            
            <div id="adjustmentDetails" style="max-height: 400px; overflow-y: auto;">
                <!-- Sẽ được load bằng JavaScript -->
            </div>
            
            <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                <button type="button" id="saveAdjustmentBtn" onclick="saveAdjustment()" class="btn btn-add">Lưu và Cập Nhật Kho</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('adjustmentModal')">Hủy</button>
            </div>
        </div>
    </div>

    <!-- Modal xác nhận xóa -->
    <div id="confirmDeleteModal" class="modal">
        <div class="modal-content modal-small">
            <span class="close" onclick="closeModal('confirmDeleteModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: var(--primary);">Xác Nhận Xóa</h2>
            <p id="confirmDeleteMessage" style="margin: 20px 0; font-size: 16px;">Bạn có chắc chắn muốn xóa phiếu xuất này?</p>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-cancel" onclick="closeModal('confirmDeleteModal')" style="background-color: #999;">Hủy</button>
                <button class="btn btn-delete" id="confirmDeleteBtn" onclick="confirmDelete()" style="background-color: #d32f2f;">Xóa</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Thêm Phiếu Xuất';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('phieuForm').reset();
            
            // Reset danh sách sản phẩm về 1 dòng
            document.getElementById('productsList').innerHTML = `
                <div class="product-row" style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <select class="product-select" required style="width: 100%; padding: 8px;">
                            <option value="">Chọn sản phẩm</option>
                            <?php foreach ($sanPhams as $sp): ?>
                                <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                                    <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <input type="number" class="product-quantity" placeholder="Số lượng" min="1" required style="width: 100%; padding: 8px;">
                    </div>
                    <div>
                        <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="padding: 8px;">×</button>
                    </div>
                </div>
            `;
            
            openModal('addModal');
        }

        function editPhieu(maPX) {
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const phieu = data.data.phieu;
                        const chiTiet = data.data.chiTiet;
                        
                        document.getElementById('modalTitle').innerText = 'Sửa Phiếu Xuất';
                        document.getElementById('modalAction').value = 'edit';
                        document.getElementById('MaPX').value = phieu.MaPX;
                        document.getElementById('MaPX').readOnly = true;
                        document.getElementById('NgayXuat').value = phieu.NgayXuat;
                        document.getElementById('MaCH').value = phieu.MaCH;
                        
                        // Hiển thị thông tin chỉ đọc
                        document.getElementById('readonlyInfo').style.display = 'block';
                        document.getElementById('infoNguoiLap').innerText = phieu.TenTK || 'N/A';
                        document.getElementById('infoTrangThai').innerText = phieu.TinhTrang_PX;
                        
                        // Hiển thị chi tiết sản phẩm
                        let html = '';
                        chiTiet.forEach((sp, index) => {
                            html += `
                                <div class="product-row" style="display: flex; gap: 10px; margin-bottom: 10px;">
                                    <select class="product-select" style="flex: 2;" required>
                                        <option value="">Chọn Sản Phẩm</option>
                                        <?php foreach ($sanPhams as $sp): ?>
                                            <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>" ${sp.MaSP == '<?php echo $sp['MaSP']; ?>' ? 'selected' : ''}>
                                                <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="product-quantity" placeholder="Số lượng" min="1" style="flex: 1;" value="${sp.SLX}" required>
                                    <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="flex: 0;">Xóa</button>
                                </div>
                            `;
                        });
                        document.getElementById('productsList').innerHTML = html;
                        
                        openModal('addModal');
                    } else {
                        alert('Không thể tải thông tin phiếu xuất');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra');
                });
        }


        function viewDetail(maPX, canEdit, canDelete) {
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const phieu = data.data.phieu;
                        const chiTiet = data.data.chiTiet;
                        
                        let html = `
                            <div class="stock-info">
                                <p><strong>Mã phiếu xuất:</strong> ${phieu.MaPX}</p>
                                <p><strong>Ngày xuất:</strong> ${new Date(phieu.NgayXuat).toLocaleDateString('vi-VN')}</p>
                                <p><strong>Cửa hàng:</strong> ${phieu.TenCH || 'N/A'}</p>
                                <p><strong>Người lập:</strong> ${phieu.TenTK || 'N/A'}</p>
                                <p><strong>Trạng thái:</strong> ${phieu.TinhTrang_PX}</p>
                                <h3>Chi tiết sản phẩm:</h3>
                                <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                                    <thead>
                                        <tr style="background: #004080; color: white;">
                                            <th style="padding: 10px; border: 1px solid #ddd;">Mã SP</th>
                                            <th style="padding: 10px; border: 1px solid #ddd;">Tên SP</th>
                                            <th style="padding: 10px; border: 1px solid #ddd;">Thể loại</th>
                                            <th style="padding: 10px; border: 1px solid #ddd;">SL Xuất</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        chiTiet.forEach(sp => {
                            html += `
                                <tr>
                                    <td style="padding: 10px; border: 1px solid #ddd;">${sp.MaSP}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd;">${sp.TenSP}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd;">${sp.TheLoai}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd;">${sp.SLX}</td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                    </tbody>
                                </table>
                        `;
                        
                        // Thêm các nút hành động vào modal
                        if (canEdit || canDelete) {
                            html += `<div style="margin-top: 20px; text-align: center;">`;
                            if (canEdit) {
                                html += `<button class="btn btn-edit" onclick="closeModal('detailModal'); editPhieu('${phieu.MaPX}');" style="margin-right: 10px;">Sửa Phiếu</button>`;
                            }
                            if (canDelete) {
                                html += `<button class="btn btn-delete" onclick="closeModal('detailModal'); deletePhieu('${phieu.MaPX}');">Xóa Phiếu</button>`;
                            }
                            html += `</div>`;
                        }
                        
                        html += `</div>`;
                        
                        document.getElementById('detailInfo').innerHTML = html;
                        openModal('detailModal');
                    } else {
                        alert('Không thể tải chi tiết phiếu xuất');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra');
                });
        }

        let statusChangeMaPX = null;

        function editExport(maPX) {
            // TODO: implement if needed
        }

        function editExportDetail(maPX) {
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const phieu = data.data.phieu;
                        const chiTiet = data.data.chiTiet;
                        
                        // Điền thông tin phiếu xuất
                        document.getElementById('editMaPX').value = phieu.MaPX;
                        document.getElementById('editNgayXuat').value = new Date(phieu.NgayXuat).toLocaleDateString('vi-VN');
                        document.getElementById('editMaCH').value = phieu.MaCH; // Cho phép chọn cửa hàng mới
                        document.getElementById('editTinhTrang').value = phieu.TinhTrang_PX;
                        
                        // Tạo form cho tất cả chi tiết sản phẩm
                        const detailsList = document.getElementById('detailsList');
                        detailsList.innerHTML = `
                            <form method="POST" id="editDetailsForm" onsubmit="return validateEditDetailForm()">
                                <input type="hidden" name="action" value="edit_detail">
                                <input type="hidden" name="MaPX" value="${phieu.MaPX}">
                                <input type="hidden" name="MaCH" id="hiddenMaCH">
                                
                                <div id="productsList" style="margin-bottom: 20px;">
                                    ${chiTiet.map((detail, index) => `
                                        <div class="edit-product-row" style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; position: relative;">
                                            <input type="hidden" name="MaCTPX[]" value="${detail.MaCTPX}">
                                            <div style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px;">
                                                <div>
                                                    <label>Sản Phẩm:</label>
                                                    <select name="MaSP[]" class="edit-product-select" required style="width: 100%; padding: 10px;">
                                                        <option value="">Chọn sản phẩm</option>
                                                        <?php
                                                        $products = $pdo->query("SELECT MaSP, TenSP, SLTK FROM SANPHAM WHERE TinhTrang != 'Ngừng kinh doanh'")->fetchAll();
                                                        foreach ($products as $product) {
                                                            echo "<option value='{$product['MaSP']}' data-sltk='{$product['SLTK']}'>{$product['TenSP']} (Tồn: {$product['SLTK']})</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label>Số Lượng:</label>
                                                    <input type="number" name="SLX[]" class="edit-product-quantity" min="1" value="${detail.SLX}" required style="width: 100%; padding: 10px;">
                                                </div>
                                                <div>
                                                    <label>&nbsp;</label>
                                                    <button type="button" class="delete-btn" onclick="removeEditProductRow(this)" style="padding: 8px; width: 100%;">×</button>
                                                </div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                                
                                <div style="display: flex; justify-content: center; gap: 10px; margin-bottom: 20px;">
                                    <button type="button" onclick="addEditProductRow()" class="btn-update" style="padding: 10px 20px;"><i class="fas fa-plus"></i> Thêm sản phẩm</button>
                                </div>
                                
                                <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                                    <button type="submit" class="btn-save" style="padding: 10px 30px;">Lưu tất cả</button>
                                    <button type="button" class="btn-cancel" onclick="closeModal('editDetailModal')" style="padding: 10px 30px;">Hủy</button>
                                </div>
                            </form>
                        `;
                        
                        // Set giá trị cho các select và input
                        chiTiet.forEach((detail, index) => {
                            const selects = document.getElementsByName('MaSP[]');
                            const inputs = document.getElementsByName('SLX[]');
                            if (selects[index]) {
                                selects[index].value = detail.MaSP;
                            }
                            if (inputs[index]) {
                                inputs[index].value = detail.SLX;
                            }
                        });
                        
                        // Đồng bộ giá trị Cửa hàng từ dropdown vào hidden input khi submit
                        const form = document.getElementById('editDetailsForm');
                        if (form) {
                            form.onsubmit = function(e) {
                                if (!validateEditDetailForm()) {
                                    e.preventDefault();
                                    return false;
                                }
                                document.getElementById('hiddenMaCH').value = document.getElementById('editMaCH').value;
                            };
                        }
                        
                        openModal('editDetailModal');
                    } else {
                        alert('Không thể tải dữ liệu');
                    }
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể tải dữ liệu');
                });
        }

        function toggleRowSelection(row, event) {
            if (event.target.tagName === 'BUTTON' || event.target.closest('button')) {
                return;
            }
            
            // Tìm tất cả các row cùng group (cùng MaPX) để highlight
            const maPX = row.getAttribute('data-id');
            
            // Nếu row này đã selected, bỏ selected cho tất cả rows trong group
            if (row.classList.contains('selected')) {
                row.classList.remove('selected');
                // Tìm và bỏ selected cho các row tiếp theo trong cùng group
                let nextRow = row.nextElementSibling;
                while (nextRow && !nextRow.classList.contains('selectable-row')) {
                    nextRow.classList.remove('selected');
                    nextRow = nextRow.nextElementSibling;
                }
            } else {
                row.classList.add('selected');
                // Highlight các row tiếp theo trong cùng group
                let nextRow = row.nextElementSibling;
                while (nextRow && !nextRow.classList.contains('selectable-row')) {
                    nextRow.classList.add('selected');
                    nextRow = nextRow.nextElementSibling;
                }
            }
            
            updateDeleteButton();
        }

        function updateDeleteButton() {
            const selectedRows = document.querySelectorAll('.selectable-row.selected');
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            const exportBtn = document.getElementById('exportPdfBtn');
            if (deleteBtn) {
                deleteBtn.disabled = selectedRows.length === 0;
            }
            if (exportBtn) {
                exportBtn.disabled = selectedRows.length === 0;
            }
        }

        function deleteSelectedExports() {
            const selectedRows = document.querySelectorAll('.selectable-row.selected');
            if (selectedRows.length === 0) {
                alert('Vui lòng chọn ít nhất một phiếu xuất để xóa!');
                return;
            }
            
            const selectedIds = Array.from(selectedRows).map(row => row.getAttribute('data-id'));
            const count = selectedIds.length;
            
            if (confirm(`Bạn có chắc chắn muốn xóa ${count} phiếu xuất đã chọn?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete">';
                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'MaPX[]';
                    input.value = id;
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
            }
        }

        function changeStatus(maPX) {
            statusChangeMaPX = maPX;
            document.getElementById('statusMaPX').innerText = `Phiếu xuất: ${maPX}`;
            openModal('statusModal');
        }

        function updateStatus(newStatus) {
            if (statusChangeMaPX) {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('MaPX', statusChangeMaPX);
                formData.append('TinhTrang', newStatus);

                fetch('exports.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeModal('statusModal');
                        
                        if (data.needsAdjustment) {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}". Vui lòng nhập số lượng mới cho từng sản phẩm.`);
                            openAdjustmentModal(statusChangeMaPX);
                        } else {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}"`);
                            location.reload();
                        }
                    } else {
                        alert('Lỗi: ' + (data.error || 'Không thể cập nhật trạng thái'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra');
                });
            }
        }

        function openAdjustmentModal(maPX) {
            statusChangeMaPX = maPX;
            document.getElementById('adjustmentMaPX').innerText = `Phiếu xuất: ${maPX} - Nhập số lượng mới cho từng sản phẩm`;
            
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const adjustmentDetails = document.getElementById('adjustmentDetails');
                        adjustmentDetails.innerHTML = `
                            <form id="adjustmentForm">
                                <input type="hidden" name="action" value="adjustment">
                                <input type="hidden" name="MaPX" value="${maPX}">
                                ${data.data.chiTiet.map((detail, index) => `
                                    <div style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                        <h4>${detail.TenSP}</h4>
                                        <p>Số lượng ban đầu: ${detail.SLX} cái</p>
                                        <label>Số lượng mới (sẽ trừ khỏi tồn kho):</label>
                                        <input type="number" name="SLX_MOI[]" min="0" value="${detail.SLX_MOI || ''}" required style="width: 100%; padding: 8px; margin-bottom: 10px;">
                                        <input type="hidden" name="MaCTPX[]" value="${detail.MaCTPX}">
                                    </div>
                                `).join('')}
                            </form>
                        `;
                        openModal('adjustmentModal');
                    } else {
                        alert('Không thể tải chi tiết');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Không thể tải chi tiết');
                });
        }

        function saveAdjustment() {
            if (statusChangeMaPX) {
                const formData = new FormData(document.getElementById('adjustmentForm'));
                fetch('exports.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Đã lưu số lượng mới và cập nhật tồn kho thành công!');
                        closeModal('adjustmentModal');
                        location.reload();
                    } else {
                        alert('Lỗi khi lưu: ' + (data.error || 'Không xác định'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Không thể lưu');
                });
            }
        }

        function addProductRow() {
            const productsList = document.getElementById('productsList');
            const newRow = document.createElement('div');
            newRow.className = 'product-row';
            newRow.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px; margin-bottom: 10px;';
            newRow.innerHTML = `
                <div>
                    <select class="product-select" required style="width: 100%; padding: 8px;">
                        <option value="">Chọn sản phẩm</option>
                        <?php foreach ($sanPhams as $sp): ?>
                            <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                                <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <input type="number" class="product-quantity" placeholder="Số lượng" min="1" required style="width: 100%; padding: 8px;">
                </div>
                <div>
                    <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="padding: 8px;">×</button>
                </div>
            `;
            productsList.appendChild(newRow);
        }

        function removeProductRow(button) {
            const productsList = document.getElementById('productsList');
            if (productsList.children.length > 1) {
                button.closest('.product-row').remove();
            } else {
                alert('Phải có ít nhất 1 sản phẩm');
            }
        }

        function addEditProductRow() {
            // Tìm form sửa chi tiết trong modal
            const editModal = document.getElementById('editDetailModal');
            if (!editModal) {
                alert('Không tìm thấy modal sửa chi tiết. Vui lòng thử lại.');
                return;
            }
            
            const form = editModal.querySelector('#editDetailsForm');
            if (!form) {
                alert('Không tìm thấy form sửa chi tiết. Vui lòng thử lại.');
                return;
            }
            
            const productsList = form.querySelector('#productsList');
            if (!productsList) {
                alert('Không tìm thấy danh sách sản phẩm. Vui lòng thử lại.');
                return;
            }
            
            const newRow = document.createElement('div');
            newRow.className = 'edit-product-row';
            newRow.style.cssText = 'margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; position: relative;';
            newRow.innerHTML = `
                <input type="hidden" name="MaCTPX[]" value="">
                <div style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px;">
                    <div>
                        <label>Sản Phẩm:</label>
                        <select name="MaSP[]" class="edit-product-select" required style="width: 100%; padding: 10px;">
                            <option value="">Chọn sản phẩm</option>
                            <?php
                            $products = $pdo->query("SELECT MaSP, TenSP, SLTK FROM SANPHAM WHERE TinhTrang != 'Ngừng kinh doanh'")->fetchAll();
                            foreach ($products as $product) {
                                echo "<option value='{$product['MaSP']}' data-sltk='{$product['SLTK']}'>{$product['TenSP']} (Tồn: {$product['SLTK']})</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Số Lượng:</label>
                        <input type="number" name="SLX[]" class="edit-product-quantity" min="1" required style="width: 100%; padding: 10px;">
                    </div>
                    <div>
                        <label>&nbsp;</label>
                        <button type="button" class="delete-btn" onclick="removeEditProductRow(this)" style="padding: 8px; width: 100%;">×</button>
                    </div>
                </div>
            `;
            productsList.appendChild(newRow);
        }

        function removeEditProductRow(button) {
            // Tìm form sửa chi tiết trong modal
            const editModal = document.getElementById('editDetailModal');
            if (!editModal) return;
            
            const form = editModal.querySelector('#editDetailsForm');
            if (!form) return;
            
            const productsList = form.querySelector('#productsList');
            if (!productsList) return;
            
            const rows = productsList.querySelectorAll('.edit-product-row');
            if (rows.length > 1) {
                button.closest('.edit-product-row').remove();
            } else {
                alert('Phải có ít nhất 1 sản phẩm');
            }
        }

        function validateEditDetailForm() {
            const form = document.getElementById('editDetailsForm');
            if (!form) return false;
            
            const productRows = form.querySelectorAll('.edit-product-row');
            
            if (productRows.length === 0) {
                alert('Vui lòng thêm ít nhất một sản phẩm!');
                return false;
            }
            
            for (let row of productRows) {
                const select = row.querySelector('.edit-product-select');
                const quantity = row.querySelector('.edit-product-quantity');
                
                if (!select || !quantity) {
                    continue;
                }
                
                if (!select.value || !quantity.value) {
                    alert('Vui lòng điền đầy đủ thông tin sản phẩm');
                    return false;
                }
                
                const sltk = parseInt(select.options[select.selectedIndex]?.getAttribute('data-sltk') || 0);
                if (parseInt(quantity.value) > sltk) {
                    alert(`Số lượng xuất vượt quá tồn kho (${sltk}) của sản phẩm ${select.options[select.selectedIndex].text}`);
                    return false;
                }
            }
            
            return true;
        }

        function validateForm() {
            const form = document.getElementById('phieuForm');
            if (!form) return false;
            
            // Kiểm tra các trường bắt buộc
            const ngayXuat = form.querySelector('#NgayXuat')?.value;
            const maCH = form.querySelector('#MaCH')?.value;
            const maTK = form.querySelector('#MaTK')?.value;
            const tinhTrang = form.querySelector('#TinhTrang_PX')?.value;
            
            if (!ngayXuat || !maCH || !maTK || !tinhTrang) {
                alert('Vui lòng điền đầy đủ tất cả các trường!');
                return false;
            }
            
            const productRows = form.querySelectorAll('.product-row');
            const products = [];
            
            if (productRows.length === 0) {
                alert('Vui lòng thêm ít nhất một sản phẩm!');
                return false;
            }
            
            for (let row of productRows) {
                const select = row.querySelector('.product-select');
                const quantity = row.querySelector('.product-quantity');
                
                if (!select || !quantity) {
                    continue; // Bỏ qua nếu không tìm thấy element
                }
                
                if (!select.value || !quantity.value) {
                    alert('Vui lòng điền đầy đủ thông tin sản phẩm');
                    return false;
                }
                
                const sltk = parseInt(select.options[select.selectedIndex]?.getAttribute('data-sltk') || 0);
                if (parseInt(quantity.value) > sltk) {
                    alert(`Số lượng xuất vượt quá tồn kho (${sltk}) của sản phẩm ${select.options[select.selectedIndex].text}`);
                    return false;
                }
                
                products.push({
                    MaSP: select.value,
                    SLX: quantity.value
                });
            }
            
            if (products.length === 0) {
                alert('Vui lòng thêm ít nhất một sản phẩm hợp lệ!');
                return false;
            }
            
            const productsDataInput = document.getElementById('productsData');
            if (productsDataInput) {
                productsDataInput.value = JSON.stringify(products);
            }
            return true;
        }

        function getTrangThaiDisplay(tinhTrang) {
            const mapping = {
                'Đang xử lý': 'Chờ duyệt',
                'Bị từ chối': 'Bị từ chối',
                'Đã duyệt': 'Đã duyệt',
                'Hoàn thành': 'Hoàn thành',
                'Có thay đổi': 'Có thay đổi'
            };
            return mapping[tinhTrang] || tinhTrang;
        }

        // Hàm giả định từ script.js (nếu chưa có)
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function exportExportPDF() {
            const selectedRows = document.querySelectorAll('.selectable-row.selected');
            if (selectedRows.length === 0) {
                alert('Vui lòng chọn ít nhất một phiếu xuất để xuất PDF!');
                return;
            }
            
            const selectedIds = Array.from(selectedRows).map(row => row.getAttribute('data-id'));
            
            if (selectedIds.length > 1) {
                alert('Chỉ có thể xuất một phiếu xuất tại một thời điểm!');
                return;
            }
            
            // Tạo form ẩn để gửi request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_export_pdf.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'MaPX';
            input.value = selectedIds[0];
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Tự ẩn flash message sau 4s (nếu có)
        document.addEventListener('DOMContentLoaded', function() {
            const flash = document.getElementById('flashMessage');
            if (!flash) return;
            setTimeout(function() {
                flash.style.opacity = '0';
                setTimeout(function() {
                    if (flash.parentNode) flash.parentNode.removeChild(flash);
                }, 500);
            }, 4000);
            
            // Tải các tùy chọn cột đã lưu
            loadColumnPreferences();
        });
    </script>
    <?php require_once 'chatbot_handler.php'; ?>
</body>
</html>