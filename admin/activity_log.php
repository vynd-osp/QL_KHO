<?php
// admin/activity_log.php - Xem lịch sử hoạt động
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

// Phân trang
$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Lọc theo loại hành động (nếu có)
$filterAction = $_GET['filter'] ?? '';

// Lọc theo loại đối tượng (nếu có)
$filterObjectType = $_GET['objectType'] ?? '';

// Lấy lịch sử hoạt động
$activityLogs = getActivityHistory($pdo, $userId, $userRole, $perPage, $offset, $filterAction, $filterObjectType);
$totalLogs = countActivityHistory($pdo, $userId, $userRole, $filterAction, $filterObjectType);
$totalPages = max(1, (int)ceil($totalLogs / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $activityLogs = getActivityHistory($pdo, $userId, $userRole, $perPage, $offset, $filterAction, $filterObjectType);
}

// Lọc theo ngày (nếu có)
$filterDate = $_GET['date'] ?? '';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch Sử Hoạt Động - Hệ Thống Quản Lý Kho Tink</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .activity-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .activity-filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--text);
        }

        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .activity-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .activity-table thead {
            background-color: var(--primary);
            color: white;
        }

        .activity-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #ddd;
        }

        .activity-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .activity-table tbody tr:hover {
            background-color: #f9f9f9;
        }

        .expand-btn {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-weight: 600;
            padding: 0 8px;
            font-size: 18px;
            transition: transform 0.2s;
        }

        .expand-btn:hover {
            color: #005bb5;
        }

        .expand-btn.expanded {
            transform: rotate(90deg);
        }

        .detail-row {
            display: none;
        }

        .detail-row.show {
            display: table-row;
        }

        .detail-content {
            padding: 20px;
            background-color: #f5f5f5;
            border-top: 2px solid var(--primary);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .detail-item {
            background: white;
            padding: 12px;
            border-radius: 6px;
            border-left: 4px solid var(--primary);
        }

        .detail-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 13px;
            color: var(--text);
            word-break: break-word;
            line-height: 1.5;
        }

        .action-badge.them {
            background-color: #d4edda;
            color: #155724;
        }

        .action-badge.sua {
            background-color: #cfe2ff;
            color: #084298;
        }

        .action-badge.xoa {
            background-color: #f8d7da;
            color: #842029;
        }

        .action-badge.doi-trang-thai {
            background-color: #fff3cd;
            color: #664d03;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .pagination {
            margin-top: 25px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 6px;
            background: #eee;
            text-decoration: none;
            color: var(--text);
            transition: all 0.2s;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
        }

        .pagination .current {
            background: var(--primary);
            color: white;
            font-weight: 600;
        }

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-card .count {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-card .label {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 5px;
            text-transform: uppercase;
        }
    </style>
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
            <div class="activity-container">
                <div class="activity-header">
                    <h2><i class="fas fa-history"></i> Lịch Sử Hoạt Động</h2>
                    <div class="activity-filters">
                        <div class="filter-group">
                            <label>Loại Hành Động:</label>
                            <select onchange="window.location.href='activity_log.php?filter=' + this.value + '&objectType=<?php echo urlencode($filterObjectType); ?>&page=1'">
                                <option value="">Tất Cả</option>
                                <option value="Thêm" <?php echo $filterAction === 'Thêm' ? 'selected' : ''; ?>>Thêm</option>
                                <option value="Sửa" <?php echo $filterAction === 'Sửa' ? 'selected' : ''; ?>>Sửa</option>
                                <option value="Xóa" <?php echo $filterAction === 'Xóa' ? 'selected' : ''; ?>>Xóa</option>
                                <option value="Đổi trạng thái" <?php echo $filterAction === 'Đổi trạng thái' ? 'selected' : ''; ?>>Đổi trạng thái</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Loại Đối Tượng:</label>
                            <select onchange="window.location.href='activity_log.php?filter=<?php echo urlencode($filterAction); ?>&objectType=' + this.value + '&page=1'">
                                <option value="">Tất Cả</option>
                                <option value="PN" <?php echo $filterObjectType === 'PN' ? 'selected' : ''; ?>>Phiếu Nhập (PN)</option>
                                <option value="PX" <?php echo $filterObjectType === 'PX' ? 'selected' : ''; ?>>Phiếu Xuất (PX)</option>
                                <option value="SP" <?php echo $filterObjectType === 'SP' ? 'selected' : ''; ?>>Sản Phẩm (SP)</option>
                                <option value="CH" <?php echo $filterObjectType === 'CH' ? 'selected' : ''; ?>>Cửa Hàng (CH)</option>
                                <option value="TK" <?php echo $filterObjectType === 'TK' ? 'selected' : ''; ?>>Tài Khoản (TK)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="stats">
                    <div class="stat-card">
                        <div class="count"><?php echo $totalLogs; ?></div>
                        <div class="label">Tổng Hoạt Động</div>
                    </div>
                </div>

                <!-- Activity Log Table -->
                <?php if (!empty($activityLogs)): ?>
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;"></th>
                                <th style="width: 60px;">STT</th>
                                <th style="width: 130px;">Thời Gian</th>
                                <th style="width: 120px;">Nhân Viên</th>
                                <th style="width: 100px;">Hành Động</th>
                                <th style="width: 100px;">Loại Đối Tượng</th>
                                <th>Tóm Tắt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activityLogs as $index => $log): ?>
                                <?php
                                $logId = $offset + $index + 1;
                                $actionBadgeClass = match($log['LoaiHanhDong']) {
                                    'Thêm' => 'them',
                                    'Sửa' => 'sua',
                                    'Xóa' => 'xoa',
                                    'Đổi trạng thái' => 'doi-trang-thai',
                                    default => ''
                                };
                                
                                // Xác định loại đối tượng
                                $object = htmlspecialchars($log['DoiTuong']);
                                $objectType = '';
                                if (strpos($object, 'PN:') !== false) {
                                    $objectType = 'Phiếu Nhập';
                                } elseif (strpos($object, 'PX:') !== false) {
                                    $objectType = 'Phiếu Xuất';
                                } elseif (strpos($object, 'CH:') !== false) {
                                    $objectType = 'Cửa Hàng';
                                } elseif (strpos($object, 'SP:') !== false) {
                                    $objectType = 'Sản Phẩm';
                                } elseif (strpos($object, 'TK:') !== false) {
                                    $objectType = 'Tài Khoản';
                                } else {
                                    $objectType = 'Khác';
                                }
                                
                                // Tóm tắt chi tiết
                                $details = htmlspecialchars($log['ChiTiet']);
                                $summary = substr($details, 0, 40);
                                if (strlen($details) > 40) {
                                    $summary .= '...';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <button class="expand-btn" onclick="toggleDetail(this, 'detail-<?php echo $logId; ?>')">
                                            ▶
                                        </button>
                                    </td>
                                    <td><?php echo $logId; ?></td>
                                    <td><?php echo date('d/m/Y H:i:s', strtotime($log['ThoiGian'])); ?></td>
                                    <td><?php echo htmlspecialchars($log['TenNhanVien']); ?></td>
                                    <td><span class="action-badge <?php echo $actionBadgeClass; ?>"><?php echo htmlspecialchars($log['LoaiHanhDong']); ?></span></td>
                                    <td><strong><?php echo $objectType; ?></strong></td>
                                    <td><?php echo $summary; ?></td>
                                </tr>
                                <tr class="detail-row" id="detail-<?php echo $logId; ?>">
                                    <td colspan="7">
                                        <div class="detail-content">
                                            <div class="detail-grid">
                                                <div class="detail-item">
                                                    <div class="detail-label">Thời Gian</div>
                                                    <div class="detail-value"><?php echo date('d/m/Y H:i:s', strtotime($log['ThoiGian'])); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Nhân Viên / Tài Khoản</div>
                                                    <div class="detail-value"><?php echo htmlspecialchars($log['TenNhanVien']); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Hành Động</div>
                                                    <div class="detail-value"><span class="action-badge <?php echo $actionBadgeClass; ?>"><?php echo htmlspecialchars($log['LoaiHanhDong']); ?></span></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Loại Đối Tượng</div>
                                                    <div class="detail-value"><?php echo $objectType; ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <div class="detail-label">Đối Tượng (Mã / ID)</div>
                                                    <div class="detail-value"><strong><?php echo $object; ?></strong></div>
                                                </div>
                                                <div class="detail-item" style="grid-column: 1 / -1;">
                                                    <div class="detail-label">Chi Tiết Thay Đổi (Trước → Sau)</div>
                                                    <div class="detail-value" style="white-space: pre-wrap;"><?php echo nl2br($details); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="activity_log.php?page=1<?php echo $filterAction ? '&filter=' . urlencode($filterAction) : ''; ?><?php echo $filterObjectType ? '&objectType=' . urlencode($filterObjectType) : ''; ?>">&laquo; Đầu</a>
                                <a href="activity_log.php?page=<?php echo $page - 1; ?><?php echo $filterAction ? '&filter=' . urlencode($filterAction) : ''; ?><?php echo $filterObjectType ? '&objectType=' . urlencode($filterObjectType) : ''; ?>">&lsaquo; Trước</a>
                            <?php else: ?>
                                <span class="disabled">&laquo; Đầu</span>
                                <span class="disabled">&lsaquo; Trước</span>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1): ?>
                                <span>...</span>
                            <?php endif;

                            for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <?php if ($p == $page): ?>
                                    <span class="current"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a href="activity_log.php?page=<?php echo $p; ?><?php echo $filterAction ? '&filter=' . urlencode($filterAction) : ''; ?><?php echo $filterObjectType ? '&objectType=' . urlencode($filterObjectType) : ''; ?>"><?php echo $p; ?></a>
                                <?php endif; ?>
                            <?php endfor;

                            if ($endPage < $totalPages): ?>
                                <span>...</span>
                            <?php endif; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="activity_log.php?page=<?php echo $page + 1; ?><?php echo $filterAction ? '&filter=' . urlencode($filterAction) : ''; ?><?php echo $filterObjectType ? '&objectType=' . urlencode($filterObjectType) : ''; ?>">Sau &rsaquo;</a>
                                <a href="activity_log.php?page=<?php echo $totalPages; ?><?php echo $filterAction ? '&filter=' . urlencode($filterAction) : ''; ?><?php echo $filterObjectType ? '&objectType=' . urlencode($filterObjectType) : ''; ?>">Cuối &raquo;</a>
                            <?php else: ?>
                                <span class="disabled">Sau &rsaquo;</span>
                                <span class="disabled">Cuối &raquo;</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>Không Có Lịch Sử Hoạt Động</h3>
                        <p>Hiện chưa có hoạt động nào được ghi nhận.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        function toggleDetail(btn, detailId) {
            const detail = document.getElementById(detailId);
            if (detail) {
                detail.classList.toggle('show');
                btn.classList.toggle('expanded');
                btn.textContent = detail.classList.contains('show') ? '▼' : '▶';
            }
        }
    </script>
</body>
</html>
