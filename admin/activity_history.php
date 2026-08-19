<?php
// admin/activity_history.php - Utility functions for activity logging

/**
 * Ghi lịch sử hoạt động
 * @param PDO $pdo - Database connection
 * @param int $maTK - User ID
 * @param string $tenNhanVien - User name
 * @param string $loaiHanhDong - Type of action (Thêm, Sửa, Xóa, Đổi trạng thái)
 * @param string $doiTuong - Object affected (e.g., MaPN, MaXK)
 * @param string $chiTiet - Details of the action
 */
function logActivity($pdo, $maTK, $tenNhanVien, $loaiHanhDong, $doiTuong, $chiTiet = '') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO LICH_SU_HOAT_DONG (MaTK, TenNhanVien, LoaiHanhDong, DoiTuong, ChiTiet)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$maTK, $tenNhanVien, $loaiHanhDong, $doiTuong, $chiTiet]);
        return true;
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

/**
 * Lấy lịch sử hoạt động
 * @param PDO $pdo - Database connection
 * @param int $maTK - User ID (null = get all for managers)
 * @param string $userRole - Role of current user
 * @param int $limit - Number of records to fetch
 * @param int $offset - Pagination offset
 * @param string $filterAction - Filter by action type (optional)
 * @param string $filterObjectType - Filter by object type: PN, PX, SP, CH, TK (optional)
 */
function getActivityHistory($pdo, $maTK = null, $userRole = 'Nhân viên', $limit = 20, $offset = 0, $filterAction = '', $filterObjectType = '') {
    try {
        $limit = (int)$limit;
        $offset = (int)$offset;
        
        $whereConditions = [];
        $params = [];
        
        // Nhân viên chỉ thấy lịch sử của chính mình
        if (trim($userRole) === 'Nhân viên' && $maTK) {
            $whereConditions[] = "lsh.MaTK = ?";
            $params[] = $maTK;
        }
        
        // Lọc theo loại hành động nếu có
        if (!empty($filterAction)) {
            $whereConditions[] = "lsh.LoaiHanhDong = ?";
            $params[] = $filterAction;
        }
        
        // Lọc theo loại đối tượng (PN, PX, SP, CH, TK) nếu có
        if (!empty($filterObjectType)) {
            $whereConditions[] = "lsh.DoiTuong LIKE ?";
            $params[] = $filterObjectType . ':%';
        }
        
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        if (trim($userRole) === 'Nhân viên' && $maTK) {
            $sql = "
                SELECT 
                    lsh.MaLS,
                    lsh.MaTK,
                    lsh.TenNhanVien,
                    lsh.LoaiHanhDong,
                    lsh.DoiTuong,
                    lsh.ChiTiet,
                    lsh.ThoiGian
                FROM LICH_SU_HOAT_DONG lsh
                $whereClause
                ORDER BY lsh.ThoiGian DESC
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            // Quản lý thấy lịch sử của tất cả
            $sql = "
                SELECT 
                    lsh.MaLS,
                    lsh.MaTK,
                    lsh.TenNhanVien,
                    lsh.LoaiHanhDong,
                    lsh.DoiTuong,
                    lsh.ChiTiet,
                    lsh.ThoiGian
                FROM LICH_SU_HOAT_DONG lsh
                $whereClause
                ORDER BY lsh.ThoiGian DESC
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get activity history error: " . $e->getMessage());
        return [];
    }
}

/**
 * Đếm tổng số lịch sử hoạt động
 * @param PDO $pdo - Database connection
 * @param int $maTK - User ID (null = count all for managers)
 * @param string $userRole - Role of current user
 * @param string $filterAction - Filter by action type (optional)
 * @param string $filterObjectType - Filter by object type: PN, PX, SP, CH, TK (optional)
 */
function countActivityHistory($pdo, $maTK = null, $userRole = 'Nhân viên', $filterAction = '', $filterObjectType = '') {
    try {
        $whereConditions = [];
        $params = [];
        
        // Nhân viên chỉ thấy lịch sử của chính mình
        if (trim($userRole) === 'Nhân viên' && $maTK) {
            $whereConditions[] = "lsh.MaTK = ?";
            $params[] = $maTK;
        }
        
        // Lọc theo loại hành động nếu có
        if (!empty($filterAction)) {
            $whereConditions[] = "lsh.LoaiHanhDong = ?";
            $params[] = $filterAction;
        }
        
        // Lọc theo loại đối tượng (PN, PX, SP, CH, TK) nếu có
        if (!empty($filterObjectType)) {
            $whereConditions[] = "lsh.DoiTuong LIKE ?";
            $params[] = $filterObjectType . ':%';
        }
        
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        $sql = "SELECT COUNT(*) as total FROM LICH_SU_HOAT_DONG lsh $whereClause";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        return (int)($result['total'] ?? 0);
    } catch (Exception $e) {
        error_log("Count activity history error: " . $e->getMessage());
        return 0;
    }
}
?>
