<?php
// admin/imports.php - Trang quản lý phiếu nhập
session_start();
require_once '../config/db.php';
require_once './activity_history.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userName = $_SESSION['username'] ?? 'Người dùng';
$userRole = $_SESSION['role'] ?? 'Nhân viên';
$userId = $_SESSION['user_id'] ?? null;

// Lấy flash message (nếu có) và xóa khỏi session
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
}

// Lấy kết quả import Excel (nếu có) và xóa khỏi session
$importExcelResult = $_SESSION['imports_import_result'] ?? null;
if (isset($_SESSION['imports_import_result'])) {
    unset($_SESSION['imports_import_result']);
}

$finalStatuses = ['Hoàn thành', 'Có thay đổi', 'Bị từ chối'];
$mutableStatuses = ['Đang xử lý', 'Đã duyệt'];

// Hàm tạo mã phiếu nhập tự động
function generateMaPN($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaPN, 3) AS UNSIGNED)) as max_id FROM PHIEUNHAP");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'PN' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// Hàm tạo mã chi tiết phiếu nhập tự động
function generateMaCTPN($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaCTPN, 5) AS UNSIGNED)) as max_id FROM CHITIETPHIEUNHAP");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'CTPN' . str_pad($next_id, 3, '0', STR_PAD_LEFT);
}

// ============================
//  XỬ LÝ THÊM / SỬA / XÓA
// ============================
if (isset($_POST['action']) && !empty($_POST['action'])) {
    $action = $_POST['action'];
    if ($action == 'add' || $action == 'edit') {
        $maPN = $_POST['MaPN'] ?? '';
        $ngayNhap = $_POST['NgayNhap'] ?? '';
        $maTK = $_POST['MaTK'] ?? '';
        $tinhTrang = $_POST['TinhTrang_PN'] ?? 'Đang xử lý';
        $maSPs = $_POST['MaSP'] ?? [];
        $slns = $_POST['SLN'] ?? [];

        if ($action == 'add') {
            // Kiểm tra các trường bắt buộc
            if (empty($ngayNhap) || empty($maTK) || empty($tinhTrang)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: imports.php");
                exit();
            }

            // Kiểm tra có ít nhất một sản phẩm
            if (empty($maSPs) || empty(array_filter($maSPs)) || empty($slns) || empty(array_filter($slns))) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng thêm ít nhất một sản phẩm!'];
                header("Location: imports.php");
                exit();
            }

            try {
                $pdo->beginTransaction();

                // Thêm phiếu nhập
                $maPN = generateMaPN($pdo);
                // Nếu là Nhân viên, tự động lưu MaTK = userId của người đang đăng nhập
                if ($userRole == 'Nhân viên' && $userId) {
                    $maTK = $userId;
                }
                $stmt = $pdo->prepare("INSERT INTO PHIEUNHAP (MaPN, NgayNhap, MaTK, TinhTrang_PN) VALUES (?, ?, ?, ?)");
                $stmt->execute([$maPN, $ngayNhap, $maTK, $tinhTrang]);

                // Thêm chi tiết sản phẩm
                foreach ($maSPs as $index => $maSP) {
                    if (!empty($maSP) && !empty($slns[$index])) {
                        // Kiểm tra trùng sản phẩm trong cùng phiếu nhập
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM CHITIETPHIEUNHAP WHERE MaPN = ? AND MaSP = ?");
                        $stmt->execute([$maPN, $maSP]);
                        if ($stmt->fetchColumn() > 0) {
                            throw new Exception("Sản phẩm $maSP đã tồn tại trong phiếu nhập $maPN");
                        }

                        // Sinh MaCTPN duy nhất
                        $maCTPN = generateMaCTPN($pdo);

                        // Lấy giá bán của sản phẩm
                        $stmtPrice = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                        $stmtPrice->execute([$maSP]);
                        $giaBan = $stmtPrice->fetchColumn();
                        $thanhTien = $slns[$index] * $giaBan;
                        
                        // Thêm chi tiết phiếu nhập
                        $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUNHAP (MaCTPN, MaPN, MaSP, SLN, ThanhTien) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$maCTPN, $maPN, $maSP, $slns[$index], $thanhTien]);

                        // Chỉ cập nhật số lượng tồn kho nếu trạng thái là "Hoàn thành"
                        if ($tinhTrang == 'Hoàn thành') {
                            // Không thay đổi status nếu sản phẩm đã được đánh dấu "Ngừng kinh doanh"
                            $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK + ?, TinhTrang = CASE WHEN TinhTrang = 'Ngừng kinh doanh' THEN 'Ngừng kinh doanh' WHEN SLTK + ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END WHERE MaSP = ?");
                            $stmt->execute([$slns[$index], $slns[$index], $maSP]);
                        }
                    }
                }

                $pdo->commit();
                
                // Ghi lịch sử hoạt động
                $productDetails = implode(', ', array_map(function($sp) { return "MaSP: $sp"; }, array_filter($maSPs)));
                logActivity($pdo, $userId, $userName, 'Thêm', "PN: $maPN", "Ngày: $ngayNhap, Sản phẩm: $productDetails");
                
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Thêm phiếu nhập thành công!'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi thêm: ' . $e->getMessage()];
            }
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE PHIEUNHAP SET NgayNhap=?, MaTK=?, TinhTrang_PN=? WHERE MaPN=?");
                $stmt->execute([$ngayNhap, $maTK, $tinhTrang, $maPN]);
                $pdo->commit();
                
                // Ghi lịch sử hoạt động
                logActivity($pdo, $userId, $userName, 'Sửa', "PN: $maPN", "Ngày: $ngayNhap, Tình trạng: $tinhTrang");
                
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Sửa phiếu nhập thành công!'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi sửa: ' . $e->getMessage()];
            }
        }
    } elseif ($action == 'delete') {
        $maPNs = $_POST['MaPN'] ?? [];
        if (empty($maPNs) || !is_array($maPNs)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng chọn ít nhất một phiếu nhập để xóa!'];
            header("Location: imports.php");
            exit();
        }
        
        $deletedCount = 0;
        $errorMessages = [];
        
        foreach ($maPNs as $maPN) {
            // Kiểm tra trạng thái phiếu nhập - chỉ được xóa khi "Đang xử lý"
            $stmt = $pdo->prepare("SELECT TinhTrang_PN FROM PHIEUNHAP WHERE MaPN = ?");
            $stmt->execute([$maPN]);
            $tinhTrang = $stmt->fetchColumn();
            
            if ($tinhTrang != 'Đang xử lý') {
                $errorMessages[] = "Phiếu nhập $maPN đã được xử lý, không thể xóa.";
                continue;
            }
            
            try {
                $pdo->beginTransaction();

                // Chỉ trừ tồn kho nếu phiếu đã hoàn thành
                if ($tinhTrang == 'Hoàn thành') {
                    // Lấy danh sách chi tiết phiếu nhập để cập nhật tồn kho
                    $stmt = $pdo->prepare("SELECT MaSP, SLN FROM CHITIETPHIEUNHAP WHERE MaPN = ?");
                    $stmt->execute([$maPN]);
                    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Trừ số lượng tồn kho trong SANPHAM
                    foreach ($details as $detail) {
                        $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK - ?, TinhTrang = CASE WHEN SLTK - ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END WHERE MaSP = ?");
                        $stmt->execute([$detail['SLN'], $detail['SLN'], $detail['MaSP']]);
                    }
                }

                // Xóa chi tiết phiếu nhập
                $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUNHAP WHERE MaPN = ?");
                $stmt->execute([$maPN]);

                // Xóa phiếu nhập
                $stmt = $pdo->prepare("DELETE FROM PHIEUNHAP WHERE MaPN = ?");
                $stmt->execute([$maPN]);
                $pdo->commit();
                
                // Ghi lịch sử hoạt động
                logActivity($pdo, $userId, $userName, 'Xóa', "PN: $maPN", "Xóa phiếu nhập");
                
                $deletedCount++;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMessages[] = "Lỗi khi xóa phiếu nhập $maPN: " . $e->getMessage();
            }
        }
        
        if ($deletedCount > 0) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Đã xóa thành công $deletedCount phiếu nhập!"];
        }
        if (!empty($errorMessages)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => implode(' ', $errorMessages)];
        }
    } elseif ($action == 'edit_detail') {
        $maPN = $_POST['MaPN'] ?? '';
        $maCTPNs = $_POST['MaCTPN'] ?? [];
        $maSPs = $_POST['MaSP'] ?? [];
        $slns = $_POST['SLN'] ?? [];

        // Kiểm tra trạng thái phiếu nhập - chỉ cho sửa khi "Đang xử lý"
        $stmt = $pdo->prepare("SELECT TinhTrang_PN, MaTK FROM PHIEUNHAP WHERE MaPN = ?");
        $stmt->execute([$maPN]);
        $import = $stmt->fetch();

        // Kiểm tra quyền: Nhân viên chỉ được sửa phiếu của mình
        if ($userRole == 'Nhân viên' && $userId && $import && $import['MaTK'] != $userId) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Bạn không có quyền sửa phiếu nhập này!'];
            header("Location: imports.php");
            exit();
        }

        if ($import && $import['TinhTrang_PN'] === 'Đang xử lý') {
            try {
                $pdo->beginTransaction();
                
                // Lấy danh sách MaCTPN hiện có của phiếu nhập
                $stmt = $pdo->prepare("SELECT MaCTPN FROM CHITIETPHIEUNHAP WHERE MaPN = ?");
                $stmt->execute([$maPN]);
                $existingCTPNs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Xóa các chi tiết không còn trong danh sách mới
                $submittedCTPNs = array_filter($maCTPNs);
                $toDelete = array_diff($existingCTPNs, $submittedCTPNs);
                foreach ($toDelete as $maCTPNToDelete) {
                    // Lấy thông tin chi tiết để trừ khỏi SLTK
                    $stmt = $pdo->prepare("SELECT MaSP, SLN FROM CHITIETPHIEUNHAP WHERE MaCTPN = ? AND MaPN = ?");
                    $stmt->execute([$maCTPNToDelete, $maPN]);
                    $detailToDelete = $stmt->fetch();
                    // Không cập nhật SLTK vì phiếu đang ở trạng thái "Đang xử lý"
                    // Xóa chi tiết
                    $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUNHAP WHERE MaCTPN = ? AND MaPN = ?");
                    $stmt->execute([$maCTPNToDelete, $maPN]);
                }
                
                // Kiểm tra trùng sản phẩm trong danh sách mới
                $submittedSPs = [];
                foreach ($maSPs as $index => $maSP) {
                    if (!empty($maSP)) {
                        if (in_array($maSP, $submittedSPs)) {
                            throw new Exception("Sản phẩm $maSP đã được chọn nhiều lần trong phiếu nhập");
                        }
                        $submittedSPs[] = $maSP;
                    }
                }
                
                // Cập nhật hoặc thêm mới chi tiết sản phẩm
                foreach ($maCTPNs as $index => $maCTPN) {
                    $maSP = $maSPs[$index] ?? '';
                    $sln = $slns[$index] ?? '';
                    
                    if (!empty($maSP) && !empty($sln)) {
                        if (!empty($maCTPN) && in_array($maCTPN, $existingCTPNs)) {
                            // Cập nhật chi tiết hiện có
                            // Kiểm tra xem MaSP mới có trùng với bản ghi khác trong cùng MaPN không
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM CHITIETPHIEUNHAP WHERE MaPN = ? AND MaSP = ? AND MaCTPN != ?");
                            $stmt->execute([$maPN, $maSP, $maCTPN]);
                            if ($stmt->fetchColumn() > 0) {
                                throw new Exception("Sản phẩm $maSP đã tồn tại trong phiếu nhập $maPN");
                            }
                            
                            // Lấy thông tin chi tiết cũ
                            $stmt = $pdo->prepare("SELECT MaSP, SLN FROM CHITIETPHIEUNHAP WHERE MaCTPN = ? AND MaPN = ?");
                            $stmt->execute([$maCTPN, $maPN]);
                            $oldDetail = $stmt->fetch();
                            
                            if ($oldDetail) {
                                // Không cập nhật SLTK vì phiếu đang ở trạng thái "Đang xử lý"
                                
                                // Lấy giá bán và tính thành tiền
                                $stmtPrice = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                                $stmtPrice->execute([$maSP]);
                                $giaBan = $stmtPrice->fetchColumn();
                                $thanhTien = $sln * $giaBan;
                                
                                // Cập nhật chi tiết phiếu nhập
                                $stmt = $pdo->prepare("UPDATE CHITIETPHIEUNHAP SET MaSP = ?, SLN = ?, ThanhTien = ? WHERE MaCTPN = ? AND MaPN = ?");
                                $stmt->execute([$maSP, $sln, $thanhTien, $maCTPN, $maPN]);
                            }
                        } else {
                            // Thêm mới chi tiết (sản phẩm mới)
                            // Kiểm tra xem sản phẩm đã tồn tại trong phiếu nhập chưa
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM CHITIETPHIEUNHAP WHERE MaPN = ? AND MaSP = ?");
                            $stmt->execute([$maPN, $maSP]);
                            if ($stmt->fetchColumn() > 0) {
                                throw new Exception("Sản phẩm $maSP đã tồn tại trong phiếu nhập $maPN");
                            }
                            
                            // Sinh MaCTPN mới
                            $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaCTPN, 5) AS UNSIGNED)) as max_id FROM CHITIETPHIEUNHAP");
                            $result = $stmt->fetch();
                            $next_id = ($result['max_id'] ?? 0) + 1;
                            $newMaCTPN = 'CTPN' . str_pad($next_id, 3, '0', STR_PAD_LEFT);
                            
                            // Lấy giá bán và tính thành tiền
                            $stmtPrice = $pdo->prepare("SELECT GiaBan FROM SANPHAM WHERE MaSP = ?");
                            $stmtPrice->execute([$maSP]);
                            $giaBan = $stmtPrice->fetchColumn();
                            $thanhTien = $sln * $giaBan;
                            
                            // Thêm chi tiết mới
                            $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUNHAP (MaCTPN, MaPN, MaSP, SLN, ThanhTien) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$newMaCTPN, $maPN, $maSP, $sln, $thanhTien]);
                            
                            // Không cập nhật SLTK vì phiếu đang ở trạng thái "Đang xử lý"
                        }
                    }
                }
                
                $pdo->commit();
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Sửa chi tiết phiếu nhập thành công!'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi sửa chi tiết: ' . $e->getMessage()];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Chỉ sửa được khi trạng thái là Đang xử lý'];
        }
    } elseif ($action == 'adjustment') {
        // Xử lý nhập SLN_MOI và cộng vào SLTK
        header('Content-Type: application/json');
        $maPN = $_POST['MaPN'] ?? '';
        $maCTPNs = $_POST['MaCTPN'] ?? [];
        $sln_mois = $_POST['SLN_MOI'] ?? [];

        $stmt = $pdo->prepare("SELECT TinhTrang_PN FROM PHIEUNHAP WHERE MaPN = ?");
        $stmt->execute([$maPN]);
        $import = $stmt->fetch();

        if ($import && $import['TinhTrang_PN'] === 'Có thay đổi') {
            try {
                $pdo->beginTransaction();
                foreach ($maCTPNs as $index => $maCTPN) {
                    $sln_moi = $sln_mois[$index] ?? 0;
                    $stmt = $pdo->prepare("UPDATE CHITIETPHIEUNHAP SET SLN_MOI = ? WHERE MaCTPN = ? AND MaPN = ?");
                    $stmt->execute([$sln_moi, $maCTPN, $maPN]);

                    // Cộng SLN_MOI vào SLTK
                    $stmt_sp = $pdo->prepare("SELECT MaSP FROM CHITIETPHIEUNHAP WHERE MaCTPN = ?");
                    $stmt_sp->execute([$maCTPN]);
                    $sp = $stmt_sp->fetch();
                    if ($sp) {
                        $stmt_update = $pdo->prepare("
                            UPDATE SANPHAM 
                            SET SLTK = SLTK + ?, 
                                TinhTrang = CASE WHEN SLTK + ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END 
                            WHERE MaSP = ?
                        ");
                        $stmt_update->execute([$sln_moi, $sln_moi, $sp['MaSP']]);
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
    } elseif ($action == 'change_status') {
        $maPN = $_POST['MaPN'] ?? '';
        $newStatus = $_POST['TinhTrang_PN'] ?? '';

        // Danh sách trạng thái hợp lệ
        $validStatuses = ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'];

        if (in_array($newStatus, $validStatuses)) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE PHIEUNHAP SET TinhTrang_PN = ? WHERE MaPN = ?");
                $stmt->execute([$newStatus, $maPN]);
                $pdo->commit();
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Đổi trạng thái thành công!'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi đổi trạng thái: ' . $e->getMessage()];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Trạng thái không hợp lệ'];
        }
    }
    header("Location: imports.php");
    exit();
}

// Xử lý các action GET riêng biệt
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'get_import_details') {
        header('Content-Type: application/json');
        $maPN = $_GET['MaPN'] ?? '';

        // Lấy thông tin phiếu nhập
        $stmt = $pdo->prepare("
            SELECT p.MaPN, p.NgayNhap, p.TinhTrang_PN, t.MaTK, t.TenTK
            FROM PHIEUNHAP p
            LEFT JOIN TAIKHOAN t ON p.MaTK = t.MaTK
            WHERE p.MaPN = ?
        ");
        $stmt->execute([$maPN]);
        $importInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        // Kiểm tra quyền: Nhân viên chỉ được xem phiếu của mình
        if ($userRole == 'Nhân viên' && $userId && $importInfo && $importInfo['MaTK'] != $userId) {
            echo json_encode([
                'error' => 'Bạn không có quyền xem phiếu nhập này'
            ]);
            exit();
        }

        // Lấy chi tiết sản phẩm (thêm SLN_MOI)
        $stmt = $pdo->prepare("
            SELECT ct.MaCTPN, ct.MaSP, ct.SLN, ct.SLN_MOI, ct.ThanhTien, sp.TenSP, sp.GiaBan
            FROM CHITIETPHIEUNHAP ct
            LEFT JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
            WHERE ct.MaPN = ?
        ");
        $stmt->execute([$maPN]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'info' => $importInfo,
            'details' => $details
        ]);
        exit();
    }

    if ($action === 'change_status_ajax') {
        header('Content-Type: application/json');
        $maPN = $_POST['MaPN'] ?? '';
        $newStatus = $_POST['TinhTrang_PN'] ?? '';

        // Danh sách trạng thái hợp lệ
        $validStatuses = ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'];

        $stmt = $pdo->prepare("SELECT TinhTrang_PN, MaTK FROM PHIEUNHAP WHERE MaPN = ?");
        $stmt->execute([$maPN]);
        $phieu = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Kiểm tra quyền: Nhân viên chỉ được đổi trạng thái phiếu của mình
        if ($userRole == 'Nhân viên' && $userId && $phieu && $phieu['MaTK'] != $userId) {
            echo json_encode(['success' => false, 'error' => 'Bạn không có quyền đổi trạng thái phiếu nhập này']);
            exit();
        }
        
        $currentStatus = $phieu['TinhTrang_PN'];

        // Nếu trạng thái hiện tại là trạng thái cuối cùng, không cho phép đổi
        if (in_array($currentStatus, $finalStatuses)) {
            echo json_encode(['success' => false, 'error' => 'Phiếu nhập này đã được khóa và không thể thay đổi trạng thái']);
            exit();
        }

        if (in_array($newStatus, $validStatuses)) {
            try {
                $pdo->beginTransaction();

                // Lấy trạng thái cũ của phiếu nhập
                $stmt = $pdo->prepare("SELECT TinhTrang_PN FROM PHIEUNHAP WHERE MaPN = ?");
                $stmt->execute([$maPN]);
                $oldStatus = $stmt->fetchColumn();

                // Cập nhật trạng thái mới cho phiếu nhập
                $stmt = $pdo->prepare("UPDATE PHIEUNHAP SET TinhTrang_PN = ? WHERE MaPN = ?");
                $stmt->execute([$newStatus, $maPN]);

                // Nếu chuyển sang trạng thái "Hoàn thành"
                if ($newStatus == 'Hoàn thành' && !in_array($oldStatus, ['Hoàn thành', 'Có thay đổi'])) {
                    // Cập nhật số lượng tồn kho cho từng sản phẩm trong phiếu nhập (cộng SLN gốc)
                    $stmt = $pdo->prepare("
                        UPDATE SANPHAM sp
                        INNER JOIN CHITIETPHIEUNHAP ct ON sp.MaSP = ct.MaSP
                        SET 
                            sp.SLTK = sp.SLTK + ct.SLN,
                            sp.TinhTrang = CASE 
                                WHEN sp.TinhTrang = 'Ngừng kinh doanh' THEN 'Ngừng kinh doanh'
                                WHEN (sp.SLTK + ct.SLN) > 0 THEN 'Còn hàng'
                                ELSE 'Hết hàng'
                            END
                        WHERE ct.MaPN = ?
                    ");
                    $stmt->execute([$maPN]);
                }

                $pdo->commit();
                
                // Ghi lịch sử hoạt động
                logActivity($pdo, $userId, $userName, 'Đổi trạng thái', "PN: $maPN", "Từ: $oldStatus → Tới: $newStatus");
                
                echo json_encode(['success' => true, 'newStatus' => $newStatus, 'needsAdjustment' => ($newStatus == 'Có thay đổi')]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Trạng thái không hợp lệ']);
        }
        exit();
    }
}

// ============================
//  LẤY DANH SÁCH PHIẾU NHẬP
// ============================
$search = $_GET['search'] ?? '';
// Ràng buộc phân quyền: Nhân viên chỉ thấy phiếu của mình, Quản lý thấy tất cả
$permissionFilter = '';
if ($userRole == 'Nhân viên' && $userId) {
    $permissionFilter = "WHERE p.MaTK = '$userId'";
}

$searchFilter = $search ? ($permissionFilter ? "AND" : "WHERE") . " (p.MaPN LIKE '%$search%' OR t.TenTK LIKE '%$search%' OR EXISTS (SELECT 1 FROM CHITIETPHIEUNHAP cti JOIN SANPHAM spi ON cti.MaSP=spi.MaSP WHERE cti.MaPN=p.MaPN AND spi.TenSP LIKE '%$search%'))" : '';
$where = $permissionFilter . $searchFilter;

// Phân trang theo đầu phiếu (MaPN): 10 phiếu/trang
$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Tổng số phiếu (đầu phiếu)
$countStmt = $pdo->query("
    SELECT COUNT(*) as total
    FROM PHIEUNHAP p
    LEFT JOIN TAIKHOAN t ON p.MaTK = t.MaTK
    " . ($where ? $where : '') . "
");
$totalRows = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Lấy danh sách đầu phiếu trang hiện tại
$stmt = $pdo->prepare("
    SELECT p.MaPN, p.NgayNhap, p.TinhTrang_PN, t.MaTK, t.TenTK
    FROM PHIEUNHAP p
    LEFT JOIN TAIKHOAN t ON p.MaTK = t.MaTK
    $where
    ORDER BY p.NgayNhap DESC, p.MaPN DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy chi tiết cho các MaPN ở trang hiện tại
$groupedImports = [];
$maPNs = array_column($headers, 'MaPN');
if (!empty($maPNs)) {
    // Map info trước
    foreach ($headers as $h) {
        $groupedImports[$h['MaPN']] = [
            'info' => [
                'MaPN' => $h['MaPN'],
                'NgayNhap' => $h['NgayNhap'],
                'TenTK' => $h['TenTK'],
                'TinhTrang_PN' => $h['TinhTrang_PN']
            ],
            'details' => []
        ];
    }
    // Lấy details
    $inPlaceholders = implode(',', array_fill(0, count($maPNs), '?'));
    $stmt = $pdo->prepare("
        SELECT ct.MaPN, ct.MaCTPN, ct.MaSP, ct.SLN, ct.SLN_MOI, ct.ThanhTien, sp.TenSP, sp.GiaBan
        FROM CHITIETPHIEUNHAP ct
        LEFT JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
        WHERE ct.MaPN IN ($inPlaceholders)
        ORDER BY ct.MaPN, sp.TenSP
    ");
    $stmt->execute($maPNs);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($details as $row) {
        $groupedImports[$row['MaPN']]['details'][] = [
            'TenSP' => $row['TenSP'],
            'SLN' => $row['SLN'],
            'GiaBan' => $row['GiaBan'],
            'ThanhTien' => $row['ThanhTien'] ?? ($row['SLN'] * $row['GiaBan']),
            'SLN_MOI' => $row['SLN_MOI'] ?? null,
            'MaCTPN' => $row['MaCTPN'],
            'MaSP' => $row['MaSP']
        ];
    }
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
 <main class="main-content"> 
    <div class="management-header"> 
        <div class="management-topbar"> 
            <h2>Quản Lý Nhập Kho</h2> 
            <div class="management-tools"> 
                <form method="GET" class="search-form"> 
                    <input type="text" placeholder="Tìm kiếm..." name="search" value="<?php echo htmlspecialchars($search); ?>"> 
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i></button> 
                </form>
                <button class="column-toggle-btn" onclick="showColumnToggle('table.management-table')">
                    <i class="fas fa-sliders-h"></i> Tùy chọn cột
                </button>
                <button class="add-btn" onclick="openModal('addModal')"> 
                    <i class="fas fa-plus"></i> Thêm Phiếu Nhập </button>
                <button class="add-btn import-btn" onclick="openModal('importExcelModal')">
                    <i class="fas fa-file-excel"></i> Import Excel
                </button>
                <button class="btn-export-pdf" id="exportPdfBtn" onclick="exportImportPDF()" disabled> 
                    <i class="fas fa-file-pdf"></i> Xuất PDF </button>
                <?php if ($userRole != 'Nhân viên'): ?>
                <button class="delete-btn" id="deleteSelectedBtn" onclick="deleteSelectedImports()" disabled> 
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
        <?php if ($importExcelResult): ?>
            <div style="margin-bottom: 15px; padding: 12px; border-radius: 8px; background: #e3f2fd; color: #0d47a1;">
                <strong>Kết quả import phiếu nhập:</strong><br>
                - Dòng hợp lệ: <?php echo (int)($importExcelResult['success_rows'] ?? 0); ?><br>
                - Dòng lỗi: <?php echo (int)($importExcelResult['error_count'] ?? 0); ?><br>
                - Phiếu nhập tạo mới: <?php echo (int)($importExcelResult['created_imports'] ?? 0); ?>
                <?php if (!empty($importExcelResult['errors'])): ?>
                    <details style="margin-top: 8px;">
                        <summary>Chi tiết lỗi</summary>
                        <ul style="margin-top: 6px; padding-left: 20px;">
                            <?php foreach ($importExcelResult['errors'] as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Bảng quản lý phiếu nhập -->
        <div class="management-container"> 
        <table class="management-table">
                <thead>
                    <tr>
                        <th data-column="mapn">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Mã PN</span>
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
                        <th data-column="ngaynhap">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Ngày Nhập</span>
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
                        <th data-column="nguoinhan">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Người Nhập</span>
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
                        <th data-column="gianhan">
                            <div class="th-filter-wrapper">
                                <span class="th-label">Giá Nhập</span>
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
                    <?php foreach ($groupedImports as $maPN => $import): ?>
                        <?php 
                        $rowspan = max(1, count($import['details']));
                        $canEdit = in_array($import['info']['TinhTrang_PN'], $mutableStatuses);
                        $isLocked = in_array($import['info']['TinhTrang_PN'], $finalStatuses);
                        ?>
                        <?php if (empty($import['details'])): ?>
                            <tr class="selectable-row" data-id="<?php echo htmlspecialchars($import['info']['MaPN']); ?>" onclick="toggleRowSelection(this, event)">
                                <td data-column="mapn"><?php echo htmlspecialchars($import['info']['MaPN']); ?></td>
                                <td data-column="ngaynhap"><?php echo date('d/m/Y', strtotime($import['info']['NgayNhap'])); ?></td>
                                <td data-column="nguoinhan"><?php echo htmlspecialchars($import['info']['TenTK']); ?></td>
                                <td data-column="sanpham"><em>Chưa có sản phẩm</em></td>
                                <td data-column="soluong"><em>0</em></td>
                                <td data-column="gianhan"><em>-</em></td>
                                <td data-column="thanhtien"><em>-</em></td>
                                <td data-column="slnmoi" class="col-new-qty"></td>
                                <td data-column="tinhtrang" class="status-cell"><?php echo htmlspecialchars($import['info']['TinhTrang_PN']); ?></td>
                                <td data-column="actions" class="management-actions">
                                    <?php if ($canEdit): ?>
                                        <button class="edit-btn" onclick="editImport('<?php echo $import['info']['MaPN']; ?>')">Sửa</button>
                                    <?php else: ?>
                                        <button class="edit-btn disabled-btn" disabled title="Chỉ sửa được khi trạng thái là 'Đang xử lý'">Sửa</button>
                                    <?php endif; ?>
                                        <?php if ($isLocked): ?>
                                            <button class="edit-btn disabled-btn" disabled title="Phiếu nhập này đã bị khóa và không thể thay đổi trạng thái">Đổi trạng thái</button>
                                        <?php else: ?>
                                            <button class="edit-btn" style="background: var(--warning)" onclick="changeStatus('<?php echo $import['info']['MaPN']; ?>')">Đổi trạng thái</button>
                                        <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($import['details'] as $index => $detail): ?>
                                <tr class="<?php echo $index === 0 ? 'selectable-row' : ''; ?>" <?php echo $index === 0 ? 'data-id="' . htmlspecialchars($import['info']['MaPN']) . '" onclick="toggleRowSelection(this, event)"' : ''; ?>>
                                    <?php if ($index === 0): ?>
                                        <td data-column="mapn" rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($import['info']['MaPN']); ?></td>
                                        <td data-column="ngaynhap" rowspan="<?php echo $rowspan; ?>"><?php echo date('d/m/Y', strtotime($import['info']['NgayNhap'])); ?></td>
                                        <td data-column="nguoinhan" rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($import['info']['TenTK']); ?></td>
                                    <?php endif; ?>
                                    <td data-column="sanpham"><?php echo htmlspecialchars($detail['TenSP']); ?></td>
                                    <td data-column="soluong"><?php echo htmlspecialchars($detail['SLN']); ?> cái</td>
                                    <td data-column="gianhan"><?php echo number_format($detail['GiaBan'], 0, ',', '.'); ?> VNĐ</td>
                                    <td data-column="thanhtien"><?php echo number_format($detail['ThanhTien'], 0, ',', '.'); ?> VNĐ</td>
                                    <td data-column="slnmoi" class="col-new-qty"><?php echo ($detail['SLN_MOI'] !== null) ? $detail['SLN_MOI'] . ' cái' : (($import['info']['TinhTrang_PN'] === 'Có thay đổi') ? 'Chưa cập nhật' : '-'); ?></td>
                                    <?php if ($index === 0): ?>
                                        <td data-column="tinhtrang" rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($import['info']['TinhTrang_PN']); ?></td>
                                        <td data-column="actions" rowspan="<?php echo $rowspan; ?>" class="management-actions"> 
                                            <?php if ($canEdit): ?>
                                                <button class="edit-btn" onclick="editImportDetail('<?php echo $import['info']['MaPN']; ?>')">Sửa</button>
                                            <?php else: ?>
                                                <button class="edit-btn disabled-btn" disabled title="Chỉ sửa được khi trạng thái là 'Đang xử lý'">Sửa</button>
                                            <?php endif; ?>
                                                <?php if ($isLocked): ?>
                                                    <button class="edit-btn disabled-btn" disabled title="Phiếu nhập này đã bị khóa và không thể thay đổi trạng thái">Đổi trạng thái</button>
                                                <?php else: ?>
                                                    <button class="edit-btn" style="background: var(--warning)" onclick="changeStatus('<?php echo $import['info']['MaPN']; ?>')">Đổi trạng thái</button>
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
                    $baseUrl = 'imports.php';
                    $params = $_GET;
                    unset($params['page']);
                    $queryBase = http_build_query($params);
                    function pageLinkImp($p, $queryBase, $baseUrl) { 
                        $q = $queryBase ? ($queryBase . '&page=' . $p) : ('page=' . $p);
                        return $baseUrl . '?' . $q;
                    }
                ?>
                <a href="<?php echo pageLinkImp(max(1, $page-1), $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; background:#eee; border-radius:6px;<?php echo $page==1?' pointer-events:none; opacity:.5;':''; ?>">«</a>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?php echo pageLinkImp($p, $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; border-radius:6px; <?php echo $p==$page?'background: var(--primary); color:#fff;':'background:#eee;'; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>
                <a href="<?php echo pageLinkImp(min($totalPages, $page+1), $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; background:#eee; border-radius:6px;<?php echo $page==$totalPages?' pointer-events:none; opacity:.5;':''; ?>">»</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>
    <!-- Modal Thêm Phiếu Nhập -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: var(--primary);">Thêm Phiếu Nhập</h2>
            <form method="POST" id="addImportForm">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                <label>Mã Phiếu Nhập:</label>
                <?php 
                $nextMaPN = generateMaPN($pdo);
                ?>
                <input type="text" value="<?php echo $nextMaPN; ?>" disabled 
                       style="background-color: #f0f0f0;">
                </div>
                
                <div class="form-group">
                <label>Ngày Nhập:</label>
                <input type="date" name="NgayNhap" required>
                </div>
                
                <div class="form-group">
                <label>Người Nhập:</label>
                <?php if ($userRole == 'Nhân viên' && $userId): ?>
                    <?php
                    // Lấy tên người đăng nhập
                    $stmt = $pdo->prepare("SELECT TenTK FROM TAIKHOAN WHERE MaTK = ?");
                    $stmt->execute([$userId]);
                    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <input type="hidden" name="MaTK" value="<?php echo htmlspecialchars($userId); ?>">
                    <input type="text" value="<?php echo htmlspecialchars($currentUser['TenTK'] ?? 'Bạn'); ?>" disabled 
                           style="background-color: #f0f0f0;">
                    <small style="color: #666; font-size: 12px;">(Tự động gán cho bạn)</small>
                <?php else: ?>
                    <select name="MaTK" required>
                        <option value="">Chọn người nhập</option>
                        <?php
                        $users = $pdo->query("SELECT MaTK, TenTK FROM TAIKHOAN")->fetchAll();
                        foreach ($users as $user) {
                            echo "<option value='{$user['MaTK']}'>{$user['TenTK']}</option>";
                        }
                        ?>
                    </select>
                <?php endif; ?>
                </div>
                
                <div class="form-group">
                <label>Tình Trạng:</label>
                <select name="TinhTrang_PN" required>
                    <option value="Đang xử lý" selected>Đang xử lý</option>
                    <option value="Đã duyệt">Đã duyệt</option>
                    <option value="Bị từ chối">Bị từ chối</option>
                    <option value="Hoàn thành">Hoàn thành</option>
                    <option value="Có thay đổi">Có thay đổi</option>
                </select>
                </div>
            

                <div class="form-group">
                <h3>Chi Tiết Sản Phẩm</h3>
                <div id="productEntries">
                    <div class="product-entry product-grid" style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <select name="MaSP[]" required style="width: 100%; padding: 8px;">
                                <option value="">Chọn sản phẩm</option>
                                <?php
                                $products = $pdo->query("SELECT MaSP, TenSP FROM SANPHAM WHERE TinhTrang != 'Ngừng kinh doanh'")->fetchAll();
                                foreach ($products as $product) {
                                    echo "<option value='{$product['MaSP']}'>{$product['TenSP']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <input type="number" name="SLN[]" min="1" placeholder="Số lượng" required style="width: 100%; padding: 8px;">
                        </div>
                        <div>
                            <button type="button" class="delete-btn" onclick="removeProduct(this)" style="padding: 8px;">×</button>
                        </div>
                    </div>
                </div>
                </div>

            <script>
                // Column toggle for imports
                const IMPORTS_STORAGE_KEY = 'imports_column_preferences';
                let _imports_table_selector = 'table.management-table';

                function showColumnToggle(selector) {
                    _imports_table_selector = selector || _imports_table_selector;
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
                    const preferences = JSON.parse(localStorage.getItem(IMPORTS_STORAGE_KEY) || '{}');
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
                    localStorage.setItem(IMPORTS_STORAGE_KEY, JSON.stringify(preferences));
                    applyColumnVisibility(preferences);
                    // Không thay đổi width của các cột - giữ nguyên width ban đầu
                    closeColumnToggle();
                }

                function applyColumnVisibility(preferences) {
                    // Tìm bảng trong container - thử nhiều cách
                    let table = document.querySelector('.management-container table.management-table');
                    if (!table) {
                        table = document.querySelector('table.management-table');
                    }
                    if (!table) {
                        table = document.querySelector(_imports_table_selector);
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
                        const cells = table.querySelectorAll(`th[data-column="${col}"], td[data-column="${col}"]`);
                        console.log(`Cột ${col}: isVisible=${isVisible}, tìm thấy ${cells.length} cells`);
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
                    const preferences = JSON.parse(localStorage.getItem(IMPORTS_STORAGE_KEY) || '{}');
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

                // load stored prefs on DOM ready
                document.addEventListener('DOMContentLoaded', function() { loadColumnPreferences(); });
            </script>

                <button type="button" onclick="addProductEntry()" class="btn-update" style="margin: 10px 0;"><i class="fas fa-plus"></i> Thêm sản phẩm</button>
                <button type="submit" class="btn-save" onclick="return validateImportForm()">Lưu</button>
            </form>
        </div>
    </div>

    <!-- Modal Import Excel Phiếu Nhập -->
    <div id="importExcelModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('importExcelModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: #004080;">
                <i class="fas fa-file-import"></i> Import Phiếu Nhập từ Excel
            </h2>
            <!-- Frontend: chỉ upload file .xlsx, backend sẽ xử lý -->
            <form method="POST" action="imports_import_excel.php" enctype="multipart/form-data">
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
                        Cần có tối thiểu các cột: <strong>Mã phiếu</strong>, <strong>Mã sản phẩm</strong>, <strong>Số lượng</strong>.<br>
                        Cột tùy chọn: <strong>Ngày nhập</strong> (yyyy-mm-dd), <strong>Mã TK</strong>, <strong>Tình trạng</strong> (Đang xử lý / Đã duyệt / Hoàn thành / Có thay đổi / Bị từ chối).
                    </small>
                </div>
                <div class="form-group import-hint">
                    <strong>Quy tắc xử lý:</strong>
                    <ul>
                        <li>Bỏ qua dòng trống hoặc thiếu Mã phiếu / Mã sản phẩm / Số lượng.</li>
                        <li>Nếu Mã phiếu đã tồn tại trong hệ thống, toàn bộ dòng liên quan sẽ bị bỏ qua.</li>
                        <li>Mỗi Mã phiếu mới sẽ tạo một phiếu nhập với tất cả chi tiết trong file.</li>
                        <li>Trạng thái "Hoàn thành" sẽ tự động cộng tồn kho sản phẩm.</li>
                    </ul>
                </div>
                <div class="modal-actions" style="margin-top: 18px;">
                    <button type="button" class="btn-cancel" onclick="closeModal('importExcelModal')">Hủy</button>
                    <button type="submit" class="btn-save" style="background: #004080;">Thực hiện Import</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal sửa chi tiết phiếu nhập -->
    <div id="editDetailModal" class="modal">
        <div class="modal-content modal-medium">
            <span class="close" onclick="closeModal('editDetailModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: var(--primary);">Sửa Chi Tiết Phiếu Nhập</h2>
            
            <div class="form-group">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <label>Mã Phiếu Nhập:</label>
                        <input type="text" id="editMaPN" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                    <div>
                        <label>Ngày Nhập:</label>
                        <input type="text" id="editNgayNhap" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                    <div>
                        <label>Người Nhập:</label>
                        <input type="text" id="editTenTK" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
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

   <!-- Modal xác nhận xóa -->
    <div id="confirmDeleteModal" class="modal">
        <div class="modal-content modal-small">
            <span class="close" onclick="closeModal('confirmDeleteModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: var(--primary);">Xác nhận Xóa</h2>
            <p id="confirmDeleteMessage" style="margin-bottom: 25px; color: var(--text);">
                Bạn có chắc chắn muốn xóa phiếu nhập này?
            </p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeModal('confirmDeleteModal')">Hủy</button>
                <button class="btn-delete" onclick="confirmDelete()">Xóa</button>
            </div>
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
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-mapn" data-column="mapn" checked>
                    <label for="col-mapn">Mã PN</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-ngaynhap" data-column="ngaynhap" checked>
                    <label for="col-ngaynhap">Ngày Nhập</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-nguoinhan" data-column="nguoinhan" checked>
                    <label for="col-nguoinhan">Người Nhập</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-sanpham" data-column="sanpham" checked>
                    <label for="col-sanpham">Sản Phẩm</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-soluong" data-column="soluong" checked>
                    <label for="col-soluong">Số Lượng</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-gianhan" data-column="gianhan" checked>
                    <label for="col-gianhan">Giá Nhập</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-thanhtien" data-column="thanhtien" checked>
                    <label for="col-thanhtien">Thành Tiền</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-slnmoi" data-column="slnmoi" checked>
                    <label for="col-slnmoi">Số lượng mới</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-tinhtrang" data-column="tinhtrang" checked>
                    <label for="col-tinhtrang">Tình Trạng</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-actions" data-column="actions" checked disabled>
                    <label for="col-actions" style="opacity: 0.6;">Thao Tác (Không thể ẩn)</label>
                </div>
            </div>
            <div class="column-toggle-actions">
                <button class="column-toggle-reset" onclick="resetColumnToggle()">Đặt lại mặc định</button>
                <button class="column-toggle-apply" onclick="applyColumnToggle()">Áp dụng</button>
            </div>
        </div>
    </div>

    <!-- Modal Đổi trạng thái -->
    <div id="statusModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <span class="close" onclick="closeModal('statusModal')">&times;</span>
            <h2 style="margin-bottom: 15px; color: var(--primary);">Đổi Trạng Thái Phiếu Nhập</h2>
            <p id="statusMaPN" style="margin-bottom: 25px; font-weight: 600; color: #ff9800; font-size: 16px; padding: 10px; background-color: #fff3e0; border-radius: 5px; border-left: 4px solid #ff9800;"></p>
            
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
            <p id="adjustmentMaPN" style="margin-bottom: 20px; font-weight: 600; color: #ff9800;"></p>
            
            <div id="adjustmentDetails" style="max-height: 400px; overflow-y: auto;">
                <!-- Sẽ được load bằng JavaScript -->
            </div>
            
            <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                <button type="button" id="saveAdjustmentBtn" onclick="saveAdjustment()" class="btn btn-add">Lưu và Cập Nhật Kho</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('adjustmentModal')">Hủy</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        // Load column preferences on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadColumnPreferences();
        });

        let deleteConfirmMaPN = null;
        let statusChangeMaPN = null;

        function editImport(maPN) {
            document.getElementById('modalTitle').innerText = 'Sửa Phiếu Nhập';
            document.getElementById('modalAction').value = 'edit';
            // TODO: Load dữ liệu phiếu nhập vào form
            openModal('addModal');
        }

        function editImportDetail(maPN) {
            fetch(`imports.php?action=get_import_details&MaPN=${encodeURIComponent(maPN)}`)
                .then(response => response.json())
                .then(data => {
                    const info = data.info;
                    const details = data.details;
                    
                    // Điền thông tin phiếu nhập (disabled)
                    document.getElementById('editMaPN').value = info.MaPN;
                    document.getElementById('editNgayNhap').value = new Date(info.NgayNhap).toLocaleDateString('vi-VN');
                    document.getElementById('editTenTK').value = info.TenTK;
                    document.getElementById('editTinhTrang').value = info.TinhTrang_PN;
                    
                    // Tạo form cho tất cả chi tiết sản phẩm
                    const detailsList = document.getElementById('detailsList');
                    detailsList.innerHTML = `
                        <form method="POST" id="editDetailsForm" onsubmit="return validateEditImportDetailForm()">
                            <input type="hidden" name="action" value="edit_detail">
                            <input type="hidden" name="MaPN" value="${info.MaPN}">
                            
                            <div id="productsList" style="margin-bottom: 20px;">
                                ${details.map((detail, index) => `
                                    <div class="edit-import-product-row" style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; position: relative;">
                                        <input type="hidden" name="MaCTPN[]" value="${detail.MaCTPN}">
                                        <div style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px;">
                                            <div>
                                                <label>Sản Phẩm:</label>
                                                <select name="MaSP[]" class="edit-import-product-select" required style="width: 100%; padding: 10px;">
                                                    <option value="">Chọn sản phẩm</option>
                                                    <?php
                                                    $products = $pdo->query("SELECT MaSP, TenSP FROM SANPHAM WHERE TinhTrang != 'Ngừng kinh doanh'")->fetchAll();
                                                    foreach ($products as $product) {
                                                        echo "<option value='{$product['MaSP']}'>{$product['TenSP']}</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label>Số Lượng:</label>
                                                <input type="number" name="SLN[]" class="edit-import-product-quantity" min="1" value="${detail.SLN}" required style="width: 100%; padding: 10px;">
                                            </div>
                                            <div>
                                                <label>&nbsp;</label>
                                                <button type="button" class="delete-btn" onclick="removeEditImportProductRow(this)" style="padding: 8px; width: 100%;">×</button>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                            
                            <div style="display: flex; justify-content: center; gap: 10px; margin-bottom: 20px;">
                                <button type="button" onclick="addEditImportProductRow()" class="btn-update" style="padding: 10px 20px;"><i class="fas fa-plus"></i> Thêm sản phẩm</button>
                            </div>
                            
                            <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                                <button type="submit" class="btn-update" style="padding: 10px 30px;">Lưu tất cả</button>
                                <button type="button" class="btn-cancel" onclick="closeModal('editDetailModal')" style="padding: 10px 30px;">Hủy</button>
                            </div>
                        </form>
                    `;

                    //Set selected values for selects after innerHTML is set
                    const selects = detailsList.querySelectorAll('select[name="MaSP[]"]');
                    details.forEach((detail, index) => {
                        if (selects[index]) {
                            selects[index].value = detail.MaSP;
                        }
                    });

                    openModal('editDetailModal');
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
            
            // Tìm tất cả các row cùng group (cùng MaPN) để highlight
            const maPN = row.getAttribute('data-id');
            const allGroupRows = document.querySelectorAll(`tr[data-id="${maPN}"], tr:has(td[rowspan][data-group="${maPN}"])`);
            
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

        function deleteSelectedImports() {
            const selectedRows = document.querySelectorAll('.selectable-row.selected');
            if (selectedRows.length === 0) {
                alert('Vui lòng chọn ít nhất một phiếu nhập để xóa!');
                return;
            }
            
            const selectedIds = Array.from(selectedRows).map(row => row.getAttribute('data-id'));
            const count = selectedIds.length;
            
            if (confirm(`Bạn có chắc chắn muốn xóa ${count} phiếu nhập đã chọn?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete">';
                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'MaPN[]';
                    input.value = id;
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
            }
        }

        function changeStatus(maPN) {
            statusChangeMaPN = maPN;
            document.getElementById('statusMaPN').innerText = `Phiếu nhập: ${maPN}`;
            openModal('statusModal');
        }

        function updateStatus(newStatus) {
            if (statusChangeMaPN) {
                fetch('imports.php?action=change_status_ajax', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `MaPN=${encodeURIComponent(statusChangeMaPN)}&TinhTrang_PN=${encodeURIComponent(newStatus)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Cập nhật UI
                        const rows = document.querySelectorAll('table tbody tr');
                        rows.forEach(row => {
                            const maPNCell = row.querySelector('td:first-child');
                            if (maPNCell && maPNCell.textContent.trim() === statusChangeMaPN) {
                                const statusCell = row.querySelector('td:nth-child(7)');
                                if (statusCell) {
                                    statusCell.textContent = newStatus;
                                }
                                
                                const editButton = row.querySelector('.btn-edit');
                                if (editButton) {
                                    if (newStatus === 'Đang xử lý') {
                                        editButton.removeAttribute('disabled');
                                        editButton.setAttribute('onclick', `editImportDetail('${statusChangeMaPN}')`);
                                        editButton.removeAttribute('title');
                                    } else {
                                        editButton.setAttribute('disabled', 'disabled');
                                        editButton.removeAttribute('onclick');
                                        editButton.setAttribute('title', "Chỉ sửa được khi trạng thái là 'Đang xử lý'");
                                    }
                                }
                                
                                const statusButton = row.querySelector('.btn-status');
                                if (statusButton) {
                                    const finalStatuses = ['Hoàn thành', 'Có thay đổi', 'Bị từ chối'];
                                    if (finalStatuses.includes(newStatus)) {
                                        statusButton.setAttribute('disabled', 'disabled');
                                        statusButton.removeAttribute('onclick');
                                        statusButton.setAttribute('title', 'Phiếu nhập này đã bị khóa và không thể thay đổi trạng thái');
                                    } else {
                                        statusButton.removeAttribute('disabled');
                                        statusButton.setAttribute('onclick', `changeStatus('${statusChangeMaPN}')`);
                                        statusButton.removeAttribute('title');
                                    }
                                }
                            }
                        });

                        closeModal('statusModal');
                        
                        if (newStatus === 'Hoàn thành') {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}"`);
                        } else if (data.needsAdjustment) {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}". Vui lòng nhập số lượng mới cho từng sản phẩm.`);
                            openAdjustmentModal(statusChangeMaPN);
                        } else {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}"`);
                        }
                    } else {
                        alert('Lỗi: ' + (data.error || 'Không thể cập nhật trạng thái'));
                    }
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể cập nhật trạng thái');
                });
            }
        }

        function openAdjustmentModal(maPN) {
            statusChangeMaPN = maPN;
            document.getElementById('adjustmentMaPN').innerText = `Phiếu nhập: ${maPN} - Nhập số lượng mới cho từng sản phẩm`;
            
            fetch(`imports.php?action=get_import_details&MaPN=${encodeURIComponent(maPN)}`)
                .then(response => response.json())
                .then(data => {
                    const adjustmentDetails = document.getElementById('adjustmentDetails');
                    adjustmentDetails.innerHTML = `
                        <form id="adjustmentForm">
                            <input type="hidden" name="action" value="adjustment">
                            <input type="hidden" name="MaPN" value="${maPN}">
                            ${data.details.map((detail, index) => `
                                <div style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                    <h4>${detail.TenSP}</h4>
                                    <p>Số lượng ban đầu: ${detail.SLN} cái</p>
                                    <label>Số lượng mới (sẽ cộng vào tồn kho):</label>
                                    <input type="number" name="SLN_MOI[]" min="0" value="${detail.SLN_MOI || ''}" required style="width: 100%; padding: 8px; margin-bottom: 10px;">
                                    <input type="hidden" name="MaCTPN[]" value="${detail.MaCTPN}">
                                </div>
                            `).join('')}
                        </form>
                    `;
                    openModal('adjustmentModal');
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể tải chi tiết');
                });
        }

        function saveAdjustment() {
            if (statusChangeMaPN) {
                const formData = new FormData(document.getElementById('adjustmentForm'));
                fetch('imports.php', {
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
                    console.error('Lỗi:', error);
                    alert('Không thể lưu');
                });
            }
        }

        function addProductEntry() {
            const template = document.querySelector('.product-entry').cloneNode(true);
            template.querySelector('select').value = '';
            template.querySelector('input[type="number"]').value = '';
            document.getElementById('productEntries').appendChild(template);
        }

        function removeProduct(button) {
            const entries = document.querySelectorAll('.product-entry');
            if (entries.length > 1) {
                button.closest('.product-entry').remove();
            }
        }

        function addEditImportProductRow() {
            const productsList = document.getElementById('productsList');
            const newRow = document.createElement('div');
            newRow.className = 'edit-import-product-row';
            newRow.style.cssText = 'margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; position: relative;';
            newRow.innerHTML = `
                <input type="hidden" name="MaCTPN[]" value="">
                <div style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px;">
                    <div>
                        <label>Sản Phẩm:</label>
                        <select name="MaSP[]" class="edit-import-product-select" required style="width: 100%; padding: 10px;">
                            <option value="">Chọn sản phẩm</option>
                            <?php
                            $products = $pdo->query("SELECT MaSP, TenSP FROM SANPHAM WHERE TinhTrang != 'Ngừng kinh doanh'")->fetchAll();
                            foreach ($products as $product) {
                                echo "<option value='{$product['MaSP']}'>{$product['TenSP']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Số Lượng:</label>
                        <input type="number" name="SLN[]" class="edit-import-product-quantity" min="1" required style="width: 100%; padding: 10px;">
                    </div>
                    <div>
                        <label>&nbsp;</label>
                        <button type="button" class="delete-btn" onclick="removeEditImportProductRow(this)" style="padding: 8px; width: 100%;">×</button>
                    </div>
                </div>
            `;
            productsList.appendChild(newRow);
        }

        function removeEditImportProductRow(button) {
            const productsList = document.getElementById('productsList');
            const rows = productsList.querySelectorAll('.edit-import-product-row');
            if (rows.length > 1) {
                button.closest('.edit-import-product-row').remove();
            } else {
                alert('Phải có ít nhất 1 sản phẩm');
            }
        }

        function validateEditImportDetailForm() {
            const form = document.getElementById('editDetailsForm');
            if (!form) return false;
            
            const productRows = form.querySelectorAll('.edit-import-product-row');
            
            if (productRows.length === 0) {
                alert('Vui lòng thêm ít nhất một sản phẩm!');
                return false;
            }
            
            // Kiểm tra trùng sản phẩm
            const selectedProducts = [];
            for (let row of productRows) {
                const select = row.querySelector('.edit-import-product-select');
                const quantity = row.querySelector('.edit-import-product-quantity');
                
                if (!select || !quantity) {
                    continue;
                }
                
                if (!select.value || !quantity.value) {
                    alert('Vui lòng điền đầy đủ thông tin sản phẩm');
                    return false;
                }
                
                if (selectedProducts.includes(select.value)) {
                    alert('Sản phẩm đã được chọn. Vui lòng chọn sản phẩm khác!');
                    return false;
                }
                
                selectedProducts.push(select.value);
            }
            
            return true;
        }

        function validateImportForm() {
            const form = document.getElementById('addImportForm');
            if (!form) return false;
            
            const ngayNhap = form.querySelector('input[name="NgayNhap"]')?.value;
            const maTK = form.querySelector('select[name="MaTK"]')?.value;
            const tinhTrang = form.querySelector('select[name="TinhTrang_PN"]')?.value;
            
            if (!ngayNhap || !maTK || !tinhTrang) {
                alert('Vui lòng điền đầy đủ tất cả các trường!');
                return false;
            }
            
            const productEntries = form.querySelectorAll('.product-entry');
            let hasValidProduct = false;
            
            if (productEntries.length === 0) {
                alert('Vui lòng thêm ít nhất một sản phẩm!');
                return false;
            }
            
            for (let entry of productEntries) {
                const maSPSelect = entry.querySelector('select[name="MaSP[]"]');
                const slnInput = entry.querySelector('input[name="SLN[]"]');
                
                if (maSPSelect && slnInput) {
                    const maSP = maSPSelect.value;
                    const sln = slnInput.value;
                    
                    if (maSP && sln && parseInt(sln) > 0) {
                        hasValidProduct = true;
                        break;
                    }
                }
            }
            
            if (!hasValidProduct) {
                alert('Vui lòng thêm ít nhất một sản phẩm hợp lệ!');
                return false;
            }
            
            return true;
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function exportImportPDF() {
            const selectedRows = document.querySelectorAll('.selectable-row.selected');
            if (selectedRows.length === 0) {
                alert('Vui lòng chọn ít nhất một phiếu nhập để xuất PDF!');
                return;
            }
            
            const selectedIds = Array.from(selectedRows).map(row => row.getAttribute('data-id'));
            
            if (selectedIds.length > 1) {
                alert('Chỉ có thể xuất một phiếu nhập tại một thời điểm!');
                return;
            }
            
            // Tạo form ẩn để gửi request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_import_pdf.php?t=' + new Date().getTime();
            form.target = '_blank';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'MaPN';
            input.value = selectedIds[0];
            
            form.appendChild(input);
            document.body.appendChild(form);
            console.log('Submitting form to:', form.action, 'with MaPN:', selectedIds[0]);
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
        });
    </script>
    <?php require_once 'chatbot_handler.php'; ?>
</body>
</html>