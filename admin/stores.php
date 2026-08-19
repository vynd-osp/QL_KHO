<?php
// admin/stores.php - Trang quản lý cửa hàng 
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

// Hàm tạo mã cửa hàng tự động
function generateMaCH($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaCH, 3) AS UNSIGNED)) as max_id FROM CUAHANG");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'CH' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// Lấy flash message (nếu có) và xóa khỏi session
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
}
// ===============================
// XỬ LÝ AJAX: LẤY THÔNG TIN CỬA HÀNG ĐỂ SỬA
// ===============================
if (isset($_GET['action']) && $_GET['action'] == 'get_store') {
    header('Content-Type: application/json');
    $maCH = $_GET['maCH'] ?? '';
    
    if (!$maCH) {
        echo json_encode(['success' => false, 'message' => 'Thiếu mã cửa hàng']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM CUAHANG WHERE MaCH = ?");
        $stmt->execute([$maCH]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$store) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy cửa hàng']);
            exit;
        }
        
        echo json_encode(['success' => true, 'data' => $store]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ===============================
// XỬ LÝ AJAX: LẤY MÃ CỬA HÀNG MỚI
// ===============================
if (isset($_GET['action']) && $_GET['action'] == 'get_new_maCH') {
    header('Content-Type: application/json');
    try {
        $newMaCH = generateMaCH($pdo);
        echo json_encode(['success' => true, 'data' => ['MaCH' => $newMaCH]]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// ============================
// XỬ LÝ THÊM / SỬA / XÓA
// ============================
if ($_POST['action'] ?? '') {
    $action = $_POST['action'];
    try {
        if ($action == 'add' || $action == 'edit') {
            $tenCH = trim($_POST['TenCH'] ?? '');
            $diaChi = trim($_POST['DiaChi'] ?? '');
            $SoDienThoai = trim($_POST['SoDienThoai'] ?? '');

            // Kiểm tra các trường bắt buộc
            if (empty($tenCH) || empty($diaChi) || empty($SoDienThoai)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: stores.php");
                exit();
            }

            // Kiểm tra định dạng số điện thoại (10-11 số, bắt đầu bằng 0)
            if (!preg_match('/^0[0-9]{9,10}$/', $SoDienThoai)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Số điện thoại không hợp lệ! Phải có 10-11 số và bắt đầu bằng 0.'];
                header("Location: stores.php");
                exit();
            }

            if ($action == 'add') {
                $maCH = generateMaCH($pdo);
                $stmt = $pdo->prepare("INSERT INTO CUAHANG (MaCH, TenCH, DiaChi, SoDienThoai) VALUES (?, ?, ?, ?)");
                $stmt->execute([$maCH, $tenCH, $diaChi, $SoDienThoai]);
                logActivity($pdo, $userId, $userName, 'Thêm', "CH: $maCH", "Tên: $tenCH, Địa chỉ: $diaChi, SĐT: $SoDienThoai");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Thêm cửa hàng thành công!'];
            } else {
                $maCH = $_POST['MaCH'] ?? '';
                $stmt = $pdo->prepare("UPDATE CUAHANG SET TenCH=?, DiaChi=?, SoDienThoai=? WHERE MaCH=?");
                $stmt->execute([$tenCH, $diaChi, $SoDienThoai, $maCH]);
                logActivity($pdo, $userId, $userName, 'Sửa', "CH: $maCH", "Tên: $tenCH, Địa chỉ: $diaChi, SĐT: $SoDienThoai");
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cập nhật cửa hàng thành công!'];
            }
        } elseif ($action == 'delete') {
            $maCHs = $_POST['MaCH'] ?? [];
            if (empty($maCHs) || !is_array($maCHs)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng chọn ít nhất một cửa hàng để xóa!'];
                header("Location: stores.php");
                exit();
            }
            
            $deletedCount = 0;
            $errorMessages = [];
            
            foreach ($maCHs as $maCH) {
                // Kiểm tra xem cửa hàng có phiếu xuất không (bất kỳ trạng thái nào)
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM PHIEUXUAT WHERE MaCH = ?");
                $stmt->execute([$maCH]);
                $hasExports = $stmt->fetchColumn() > 0;
                
                if ($hasExports) {
                    $errorMessages[] = "Cửa hàng $maCH đã có phiếu xuất, không thể xóa.";
                    continue;
                }
                
                try {
                    $stmt = $pdo->prepare("DELETE FROM CUAHANG WHERE MaCH=?");
                    $stmt->execute([$maCH]);
                    logActivity($pdo, $userId, $userName, 'Xóa', "CH: $maCH", "Xóa cửa hàng");
                    $deletedCount++;
                } catch (Exception $e) {
                    $errorMessages[] = "Lỗi khi xóa cửa hàng $maCH: " . $e->getMessage();
                }
            }
            
            if ($deletedCount > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'message' => "Đã xóa thành công $deletedCount cửa hàng!"];
            }
            if (!empty($errorMessages)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => implode(' ', $errorMessages)];
            }
        }
    } catch (Exception $e) {
        // Kiểm tra nếu là lỗi foreign key constraint
        if (strpos($e->getMessage(), 'foreign key constraint') !== false || strpos($e->getMessage(), '1451') !== false) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Không thể xóa cửa hàng này vì đã có dữ liệu liên quan trong hệ thống.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi xử lý: ' . $e->getMessage()];
        }
    }

    header("Location: stores.php"); // Reload trang
    exit();
}

// ============================
// LẤY DANH SÁCH CỬA HÀNG
// ============================
$search = $_GET['search'] ?? '';
$where = '';
$searchMessage = '';

if ($search) {
    $where = "WHERE TenCH LIKE '%$search%' OR MaCH LIKE '%$search%'";
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM CUAHANG $where");
    $totalResults = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    if ($totalResults == 0) {
        $searchMessage = "Không tìm thấy cửa hàng nào với từ khóa: '$search'";
    } else {
        $searchMessage = "Tìm thấy $totalResults cửa hàng với từ khóa: '$search'";
    }
}

// Phân trang: 10 dòng/trang
$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Tổng số bản ghi
$countStmt = $pdo->query("SELECT COUNT(*) as total FROM CUAHANG $where");
$totalRows = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Dữ liệu trang hiện tại
$stmt = $pdo->prepare("SELECT * FROM CUAHANG $where ORDER BY MaCH LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            <h2>Quản Lý Cửa Hàng</h2> 
            <div class="management-tools"> 
                <form method="GET" class="search-form"> 
                    <input type="text" placeholder="Tìm kiếm..." name="search" value="<?php echo htmlspecialchars($search); ?>"> 
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i></button> 
                </form>
                <button class="column-toggle-btn" onclick="showColumnToggle('table.management-table')">
                    <i class="fas fa-sliders-h"></i> Tùy chọn cột
                </button>
                <button class="add-btn" onclick="addStore()"> 
                    <i class="fas fa-plus"></i> Thêm Cửa Hàng </button>
                <?php if($userRole == 'Quản lý'): ?>
                <button class="delete-btn" id="deleteSelectedBtn" onclick="deleteSelectedStores()" disabled> 
                    <i class="fas fa-trash"></i> Xóa Đã Chọn </button> 
                <?php endif; ?> 
            </div> 
        </div> 
    </div> 
        <!-- Thông báo kết quả tìm kiếm --> 
         <?php if ($searchMessage): ?> 
            <div style="margin-bottom: 15px; padding: 12px; border-radius: 8px; 
                        background: <?php echo strpos($searchMessage, 'Không tìm thấy') !== false ? '#ffebee' : '#e8f5e8'; ?>; 
                        color: <?php echo strpos($searchMessage, 'Không tìm thấy') !== false ? '#c62828' : '#2e7d32'; ?>;"> 
                <?php echo htmlspecialchars($searchMessage); ?> 
            </div> <?php endif; ?> 
        <!-- Flash message --> 
         <?php if ($flash): ?> 
            <div id="flashMessage" style="margin-bottom: 15px; padding: 12px; border-radius: 8px; 
                        background: <?php echo ($flash['type'] ?? '') === 'error' ? '#ffebee' : '#e8f5e8'; ?>; 
                        color: <?php echo ($flash['type'] ?? '') === 'error' ? '#c62828' : '#2e7d32'; ?>;"> 
                <?php echo htmlspecialchars($flash['message']); ?> 
            </div> 
        <?php endif; ?>

            <!-- Bảng danh sách cửa hàng -->
         <div class="management-container">
        <?php if (empty($stores) && !$search): ?>
            <div style="text-align: center; padding: 40px;"> 
                    <p style="font-size: 18px; margin-bottom: 10px;">Chưa có cửa hàng nào trong hệ thống</p> 
                    <button class="add-btn" onclick="addStore()"><i class="fas fa-plus"></i> Thêm Cửa Hàng Đầu Tiên</button> 
                </div>
        <?php else: ?>
                <table class="management-table">
                    <thead>
                        <tr>
                            <th data-column="mach">
                                <div class="th-filter-wrapper">
                                    <span class="th-label">Mã CH</span>
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
                            <th data-column="tench">
                                <div class="th-filter-wrapper">
                                    <span class="th-label">Tên Cửa Hàng</span>
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
                            <th data-column="diachi">
                                <div class="th-filter-wrapper">
                                    <span class="th-label">Địa Chỉ</span>
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
                            <th data-column="sodienthoai">
                                <div class="th-filter-wrapper">
                                    <span class="th-label">Số điện thoại</span>
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
                            <th class="actions-column" data-column="actions">Hành Động</th>
                        </tr>
                    </thead>
<tbody>
    <?php foreach ($stores as $s): ?>
    <tr class="selectable-row" data-id="<?php echo htmlspecialchars($s['MaCH']); ?>" onclick="toggleRowSelection(this, event)">
        <td data-column="mach"><?php echo htmlspecialchars($s['MaCH']); ?></td>
        <td data-column="tench"><?php echo htmlspecialchars($s['TenCH']); ?></td>
        <td data-column="diachi"><?php echo htmlspecialchars($s['DiaChi']); ?></td>
        <td data-column="sodienthoai"><?php echo htmlspecialchars($s['SoDienThoai']); ?></td>
        <td data-column="actions"> <div class="management-actions">
            <button class="edit-btn" onclick="editStore('<?php echo $s['MaCH']; ?>')">Sửa</button>
        </div>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody>

                </table>
				<?php if ($totalPages > 1): ?>
				<div class="pagination" style="margin-top: 12px; display: flex; gap: 6px; flex-wrap: wrap; justify-content: center;">
					<?php
						$baseUrl = 'stores.php';
						$params = $_GET;
						unset($params['page']);
						$queryBase = http_build_query($params);
						function pageLinkStore($p, $queryBase, $baseUrl) { 
							$q = $queryBase ? ($queryBase . '&page=' . $p) : ('page=' . $p);
							return $baseUrl . '?' . $q;
						}
					?>
					<a href="<?php echo pageLinkStore(max(1, $page-1), $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; background:#eee; border-radius:6px;<?php echo $page==1?' pointer-events:none; opacity:.5;':''; ?>">«</a>
					<?php for ($p = 1; $p <= $totalPages; $p++): ?>
						<a href="<?php echo pageLinkStore($p, $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; border-radius:6px; <?php echo $p==$page?'background: var(--primary); color:#fff;':'background:#eee;'; ?>">
							<?php echo $p; ?>
						</a>
					<?php endfor; ?>
					<a href="<?php echo pageLinkStore(min($totalPages, $page+1), $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; background:#eee; border-radius:6px;<?php echo $page==$totalPages?' pointer-events:none; opacity:.5;':''; ?>">»</a>
				</div>
				<?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Modal Thêm/Sửa -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2 id="modalTitle" style="margin-bottom: 15px; color: var(--primary);">Thêm Cửa Hàng</h2>
            <form method="POST" onsubmit="return validateStoreForm()">
                <input type="hidden" name="action" id="action" value="add">
                <input type="hidden" name="MaCH" id="MaCHHidden" value="<?php echo generateMaCH($pdo); ?>">
                
                <div class="form-group">
                <label>Mã Cửa Hàng:</label>
                <?php 
                $nextMaCH = generateMaCH($pdo);
                ?>
                <input type="text" id="MaCH" value="<?php echo $nextMaCH; ?>" disabled 
                       style="background-color: #f0f0f0;">
                </div>

                <div class="form-group">
                <label>Tên Cửa Hàng: <span style="color: red;">*</span></label>
                <input type="text" name="TenCH" id="TenCH" placeholder="Tên Cửa Hàng" required>
                </div>

                <div class="form-group">
                <label>Địa Chỉ: <span style="color: red;">*</span></label>
                <input type="text" name="DiaChi" id="DiaChi" placeholder="Địa Chỉ" required>
                </div>

                <div class="form-group">
                <label>Số điện thoại: <span style="color: red;">*</span></label>
                <input type="tel" name="SoDienThoai" id="SoDienThoai" placeholder="Số điện thoại (VD: 0901234567)" 
                       pattern="0[0-9]{9,10}" title="Số điện thoại phải có 10-11 số và bắt đầu bằng 0" required>
                </div>
                <button type="submit" id="submitBtn" class="btn-save">Lưu</button>
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
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-mach" data-column="mach" checked>
                    <label for="col-mach">Mã CH</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-tench" data-column="tench" checked>
                    <label for="col-tench">Tên Cửa Hàng</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-diachi" data-column="diachi" checked>
                    <label for="col-diachi">Địa Chỉ</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-sodienthoai" data-column="sodienthoai" checked>
                    <label for="col-sodienthoai">Số điện thoại</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-actions" data-column="actions" checked disabled>
                    <label for="col-actions" style="opacity: 0.6;">Hành Động (Không thể ẩn)</label>
                </div>
            </div>
            <div class="column-toggle-actions">
                <button class="column-toggle-reset" onclick="resetColumnToggle()">Đặt lại mặc định</button>
                <button class="column-toggle-apply" onclick="applyColumnToggle()">Áp dụng</button>
            </div>
        </div>
    </div>

    <!-- Modal xác nhận xóa -->
    <div id="confirmDeleteModal" class="modal">
    <div class="modal-content modal-small">
        <span class="close" onclick="closeModal('confirmDeleteModal')">&times;</span>
        <h2 style="margin-bottom: 15px; color: var(--primary);">Xác nhận Xóa</h2>
        <p id="confirmDeleteMessage" style="margin-bottom: 25px; color: var(--text);">
            Bạn có chắc chắn muốn xóa cửa hàng này?
        </p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal('confirmDeleteModal')">Hủy</button>
            <button class="btn-delete" onclick="confirmDelete()">Xóa</button>
        </div>
    </div>
</div>

    <script src="../assets/js/script.js"></script>
    <script>
        // Load column preferences on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadColumnPreferences();
        });

        function editStore(maCH) {
            fetch(`stores.php?action=get_store&maCH=${encodeURIComponent(maCH)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const store = data.data;
                        document.getElementById('modalTitle').innerText = "Sửa Cửa Hàng";
                        document.getElementById('action').value = "edit";
                        document.getElementById('MaCH').value = store.MaCH;
                        document.getElementById('MaCHHidden').value = store.MaCH;
                        document.getElementById('TenCH').value = store.TenCH;
                        document.getElementById('DiaChi').value = store.DiaChi;
                        document.getElementById('SoDienThoai').value = store.SoDienThoai;
                        document.getElementById('submitBtn').innerText = "Cập nhật";
                        document.getElementById('submitBtn').className = "btn-update";
                        openModal('addModal');
                    } else {
                        alert('Không thể tải dữ liệu cửa hàng');
                    }
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể tải dữ liệu cửa hàng');
                });
        }
        
        function addStore() {
            // Lấy mã mới từ server
            fetch('stores.php?action=get_new_maCH')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const newMaCH = data.data.MaCH;
                        document.getElementById('modalTitle').innerText = "Thêm Cửa Hàng";
                        document.getElementById('action').value = "add";
                        document.getElementById('MaCH').value = newMaCH;
                        document.getElementById('MaCHHidden').value = newMaCH;
                        document.getElementById('TenCH').value = "";
                        document.getElementById('DiaChi').value = "";
                        document.getElementById('SoDienThoai').value = "";
                        document.getElementById('submitBtn').innerText = "Lưu";
                        document.getElementById('submitBtn').className = "btn-save";
                        openModal('addModal');
                    } else {
                        alert('Không thể lấy mã cửa hàng mới');
                    }
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể lấy mã cửa hàng mới');
                });
        }

        function toggleRowSelection(row, event) {
            if (event.target.tagName === 'BUTTON' || event.target.closest('button')) {
                return;
            }
            row.classList.toggle('selected');
            updateDeleteButton();
        }

        function updateDeleteButton() {
            const selectedRows = document.querySelectorAll('.selectable-row.selected');
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            if (deleteBtn) {
                deleteBtn.disabled = selectedRows.length === 0;
            }
        }

        function deleteSelectedStores() {
            const selectedRows = document.querySelectorAll('.selectable-row.selected');
            if (selectedRows.length === 0) {
                alert('Vui lòng chọn ít nhất một cửa hàng để xóa!');
                return;
            }
            
            const selectedIds = Array.from(selectedRows).map(row => row.getAttribute('data-id'));
            const count = selectedIds.length;
            
            if (confirm(`Bạn có chắc chắn muốn xóa ${count} cửa hàng đã chọn?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete">';
                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'MaCH[]';
                    input.value = id;
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
            }
        }

        function validateStoreForm() {
            const tenCH = document.querySelector('input[name="TenCH"]').value.trim();
            const diaChi = document.querySelector('input[name="DiaChi"]').value.trim();
            const soDienThoai = document.querySelector('input[name="SoDienThoai"]').value.trim();
            
            if (!tenCH || !diaChi || !soDienThoai) {
                alert('Vui lòng điền đầy đủ tất cả các trường!');
                return false;
            }
            
            const phoneRegex = /^0[0-9]{9,10}$/;
            if (!phoneRegex.test(soDienThoai)) {
                alert('Số điện thoại không hợp lệ! Phải có 10-11 số và bắt đầu bằng 0.');
                return false;
            }
            
            return true;
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

        // ========== Column toggle (stores) ==========
        const STORES_STORAGE_KEY = 'stores_column_preferences';
        let _stores_table_selector = 'table.management-table';

        function showColumnToggle(selector) {
            _stores_table_selector = selector || _stores_table_selector;
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
            const preferences = JSON.parse(localStorage.getItem(STORES_STORAGE_KEY) || '{}');
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
            localStorage.setItem(STORES_STORAGE_KEY, JSON.stringify(preferences));
            applyColumnVisibility(preferences);
            // adjust widths
            try {
                const table = document.querySelector(_stores_table_selector);
                if (!table) return;
                const headerCells = Array.from(table.querySelectorAll('thead th')).filter(th => !th.classList.contains('actions-column'));
                const visibleCount = headerCells.filter(th => !th.classList.contains('hidden')).length;
                if (visibleCount === headerCells.length) {
                    clearInlineColumnWidths(table);
                } else {
                    updateColumnWidths(table);
                }
            } catch (e) {}
            closeColumnToggle();
        }

        function applyColumnVisibility(preferences) {
            const table = document.querySelector(_stores_table_selector);
            if (!table) return;
            Object.keys(preferences).forEach(col => {
                // Đảm bảo cột actions luôn được hiển thị
                if (col === 'actions') {
                    const cells = table.querySelectorAll(`[data-column="${col}"]`);
                    cells.forEach(cell => cell.classList.remove('hidden'));
                    return;
                }
                const isVisible = preferences[col];
                const cells = table.querySelectorAll(`[data-column="${col}"]`);
                cells.forEach(cell => isVisible ? cell.classList.remove('hidden') : cell.classList.add('hidden'));
            });
            // Đảm bảo cột actions luôn visible (trong trường hợp preferences không có key 'actions')
            const actionsCells = table.querySelectorAll(`[data-column="actions"]`);
            actionsCells.forEach(cell => cell.classList.remove('hidden'));
            // update widths if necessary
            try { if (table) updateColumnWidths(table); } catch(e){}
        }

        function loadColumnPreferences() {
            const preferences = JSON.parse(localStorage.getItem(STORES_STORAGE_KEY) || '{}');
            if (Object.keys(preferences).length > 0) applyColumnVisibility(preferences);
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
                th.style.width = equal + 'px';
                th.style.minWidth = equal + 'px';
                const col = th.getAttribute('data-column');
                if (!col) return;
                const cells = table.querySelectorAll(`[data-column="${col}"]`);
                cells.forEach(td => {
                    td.style.width = equal + 'px';
                    td.style.minWidth = equal + 'px';
                });
            });

            // clear widths for other visible columns so they size naturally
            const other = visible.slice(targetCount);
            other.forEach(th => {
                const col = th.getAttribute('data-column');
                if (!col) return;
                th.style.width = '';
                th.style.minWidth = '';
                const cells = table.querySelectorAll(`[data-column="${col}"]`);
                cells.forEach(td => { td.style.width = ''; td.style.minWidth = ''; });
            });

            table.style.minWidth = (equal * targetCount + actionsWidth + 20) + 'px';
        }

        function clearInlineColumnWidths(table) {
            if (!table) return;
            const cells = table.querySelectorAll('thead th[data-column], tbody td[data-column]');
            cells.forEach(el => {
                el.style.width = '';
                el.style.minWidth = '';
                el.style.maxWidth = '';
            });
            table.style.minWidth = '';
        }
    </script>
    </script>
    <?php require_once 'chatbot_handler.php'; ?>
</body>
</html>