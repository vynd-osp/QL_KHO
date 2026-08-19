<?php
session_start();
require_once '../config/db.php';
require_once "../middleware.php";
require_once './activity_history.php';
if(!can('account_manage')) die("Không có quyền truy cập trang này");

// ==========================
// KIỂM TRA PHIÊN LÀM VIỆC
// ==========================
if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}
$userName = $_SESSION['username'] ?? 'Người dùng';
$userRole = $_SESSION['role'] ?? 'Nhân viên';
$userId = $_SESSION['user_id'] ?? null;

// ==========================
// XỬ LÝ CRUD NGAY TRÊN FILE NÀY
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $ma = $_POST['MaTK'];
            $ten = trim($_POST['TenTK']);
            $mk = $_POST['MatKhau'];
            $role = $_POST['VaiTro'];
            
            // Kiểm tra các trường bắt buộc
            if (empty($ten) || empty($mk) || empty($role)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: accounts.php");
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO TAIKHOAN (MaTK, TenTK, MatKhau, VaiTro) VALUES (?, ?, ?, ?)");
            $stmt->execute([$ma, $ten, $mk, $role]);
            logActivity($pdo, $userId, $userName, 'Thêm', "TK: $ma", "Tên: $ten, Vai trò: $role");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Thêm tài khoản thành công!'];
            header("Location: accounts.php");
            exit();
        }

        if ($action === 'edit') {
            $ma = $_POST['MaTK'];
            $ten = trim($_POST['TenTK']);
            $mk = $_POST['MatKhau'];
            $role = $_POST['VaiTro'];
            
            // Kiểm tra các trường bắt buộc
            if (empty($ten) || empty($mk) || empty($role)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: accounts.php");
                exit();
            }
            
            $stmt = $pdo->prepare("UPDATE TAIKHOAN SET TenTK=?, MatKhau=?, VaiTro=? WHERE MaTK=?");
            $stmt->execute([$ten, $mk, $role, $ma]);
            logActivity($pdo, $userId, $userName, 'Sửa', "TK: $ma", "Tên: $ten, Vai trò: $role");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cập nhật tài khoản thành công!'];
            header("Location: accounts.php");
            exit();
        }
        
        if ($action === 'delete') {
            $maTKs = $_POST['MaTK'] ?? [];
            if (empty($maTKs) || !is_array($maTKs)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng chọn ít nhất một tài khoản để xóa!'];
                header("Location: accounts.php");
                exit();
            }
            
            $deletedCount = 0;
            $errorMessages = [];
            
            foreach ($maTKs as $ma) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM TAIKHOAN WHERE MaTK=?");
                    $stmt->execute([$ma]);
                    logActivity($pdo, $userId, $userName, 'Xóa', "TK: $ma", "Xóa tài khoản");
                    $deletedCount++;
                } catch (Exception $e) {
                    $errorMessages[] = "Lỗi khi xóa tài khoản $ma: " . $e->getMessage();
                }
            }
            
            if ($deletedCount > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'message' => "Đã xóa thành công $deletedCount tài khoản!"];
            }
            if (!empty($errorMessages)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => implode(' ', $errorMessages)];
            }
            header("Location: accounts.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi xử lý: ' . $e->getMessage()];
        header("Location: accounts.php");
        exit();
    }
}

// ==========================
// HÀM SINH MÃ TỰ ĐỘNG
// ==========================
function generateMaTK($pdo) {
    $stmt = $pdo->query("SELECT MaTK FROM TAIKHOAN ORDER BY MaTK DESC LIMIT 1");
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $num = (int)substr($last['MaTK'], 2) + 1;
        return 'TK' . str_pad($num, 5, '0', STR_PAD_LEFT);
    }
    return 'TK00001';
}

// ==========================
// XỬ LÝ AJAX: LẤY MÃ TÀI KHOẢN MỚI
// ==========================
if (isset($_GET['action']) && $_GET['action'] == 'get_new_maTK') {
    header('Content-Type: application/json');
    try {
        $newMaTK = generateMaTK($pdo);
        echo json_encode(['success' => true, 'data' => ['MaTK' => $newMaTK]]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$newMaTK = generateMaTK($pdo);

// Lấy flash message (nếu có) và xóa khỏi session
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
}

// ==========================
// LẤY DANH SÁCH TÀI KHOẢN
// ==========================
$search = $_GET['search'] ?? '';
$where = '';
$searchMessage = '';

if ($search) {
    $where = "WHERE TenTK LIKE '%$search%' OR MaTK LIKE '%$search%'";
    
    // Kiểm tra xem có tài khoản nào khớp không
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM TAIKHOAN $where");
    $totalResults = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($totalResults == 0) {
        $searchMessage = "Không tìm thấy tài khoản nào với từ khóa: '$search'";
    } else {
        $searchMessage = "Tìm thấy $totalResults tài khoản với từ khóa: '$search'";
    }
}

// Phân trang: 10 dòng/trang
$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Tổng số bản ghi
$countStmt = $pdo->query("SELECT COUNT(*) as total FROM TAIKHOAN $where");
$totalRows = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Dữ liệu trang hiện tại
$stmt = $pdo->prepare("SELECT * FROM TAIKHOAN $where ORDER BY MaTK ASC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            <h2>Quản Lý Tài Khoản</h2> 
            <div class="management-tools"> 
                <form method="GET" class="search-form"> 
                    <input type="text" placeholder="Tìm kiếm..." name="search" value="<?php echo htmlspecialchars($search); ?>"> 
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i></button> 
                </form> 
                <button class="column-toggle-btn" onclick="openColumnToggle()">
                    <i class="fas fa-columns"></i> Tùy chọn cột
                </button>
                <button class="add-btn" onclick="openModal('addModal')"> 
                    <i class="fas fa-plus"></i> Thêm Tài Khoản </button>
                <button class="delete-btn" id="deleteSelectedBtn" onclick="deleteSelectedAccounts()" disabled> 
                    <i class="fas fa-trash"></i> Xóa Đã Chọn </button> 
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

        <!-- Bảng danh sách tài khoản -->
         <div class="management-container">
        <?php if (empty($accounts) && !$search): ?>
            <div style="text-align: center; padding: 40px;"> 
                    <p style="font-size: 18px; margin-bottom: 10px;">Chưa có tài khoản nào trong hệ thống</p> 
                    <button class="add-btn" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Thêm Tài Khoản Đầu Tiên</button> 
                </div>
        <?php else: ?>
                <table class="management-table" id="accountsTable">
                    <thead>
                        <tr>
                            <th data-column="matk">
                                <div class="th-filter-wrapper">
                                    <span class="th-label">Mã TK</span>
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
                            <th data-column="tentk">
                                <div class="th-filter-wrapper">
                                    <span class="th-label">Tên Tài Khoản</span>
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
                            <th data-column="matkhau">
                                <div class="th-filter-wrapper">
                                    <span class="th-label">Mật Khẩu</span>
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
                            <th data-column="vaitro">
                                <div class="th-filter-wrapper">
                                    <span class="th-label">Vai Trò</span>
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
                        <?php foreach ($accounts as $acc): ?>
                            <tr class="selectable-row" data-id="<?= htmlspecialchars($acc['MaTK']); ?>" onclick="toggleRowSelection(this, event)">
                                <td data-column="matk"><?= htmlspecialchars($acc['MaTK']); ?></td>
                                <td data-column="tentk"><?= htmlspecialchars($acc['TenTK']); ?></td>
                                <td data-column="matkhau"><?= htmlspecialchars($acc['MatKhau']); ?></td>
                                <td data-column="vaitro"><?= htmlspecialchars($acc['VaiTro']); ?></td>
                                <td class="management-actions" data-column="actions">
                                    <button class="edit-btn"
                                        onclick="editAccount('<?= $acc['MaTK']; ?>','<?= $acc['TenTK']; ?>','<?= $acc['MatKhau']; ?>','<?= $acc['VaiTro']; ?>')">
                                        Sửa
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top: 12px; display: flex; gap: 6px; flex-wrap: wrap; justify-content: center;">
                    <?php
                        $baseUrl = 'accounts.php';
                        $params = $_GET;
                        unset($params['page']);
                        $queryBase = http_build_query($params);
                        function pageLinkAcc($p, $queryBase, $baseUrl) { 
                            $q = $queryBase ? ($queryBase . '&page=' . $p) : ('page=' . $p);
                            return $baseUrl . '?' . $q;
                        }
                    ?>
                    <a href="<?php echo pageLinkAcc(max(1, $page-1), $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; background:#eee; border-radius:6px;<?php echo $page==1?' pointer-events:none; opacity:.5;':''; ?>">«</a>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <a href="<?php echo pageLinkAcc($p, $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; border-radius:6px; <?php echo $p==$page?'background: var(--primary); color:#fff;':'background:#eee;'; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>
                    <a href="<?php echo pageLinkAcc(min($totalPages, $page+1), $queryBase, $baseUrl); ?>" class="btn" style="padding: 6px 10px; background:#eee; border-radius:6px;<?php echo $page==$totalPages?' pointer-events:none; opacity:.5;':''; ?>">»</a>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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
                    <input type="checkbox" id="col-matk" data-column="matk" checked>
                    <label for="col-matk">Mã TK</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-tentk" data-column="tentk" checked>
                    <label for="col-tentk">Tên Tài Khoản</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-matkhau" data-column="matkhau" checked>
                    <label for="col-matkhau">Mật Khẩu</label>
                </div>
                <div class="column-toggle-item">
                    <input type="checkbox" id="col-vaitro" data-column="vaitro" checked>
                    <label for="col-vaitro">Vai Trò</label>
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
            Bạn có chắc chắn muốn xóa tài khoản này?
        </p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal('confirmDeleteModal')">Hủy</button>
            <button class="btn-delete" onclick="confirmDelete()">Xóa</button>
        </div>
    </div>
</div>

<!-- ========== MODAL FORM ========== -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addModal')">&times;</span>
        <h2 id="modalTitle" style="margin-bottom: 15px; color: var(--primary);">Thêm Tài Khoản</h2>
        <form method="POST" action="accounts.php" onsubmit="return validateAccountForm()">
            <input type="hidden" name="action" id="action" value="add">
            <input type="hidden" name="MaTK" id="MaTKHidden" value="<?= $newMaTK ?>">

            <div class="form-group">
                <label>Mã TK</label>
                <input type="text" id="MaTK" value="<?= $newMaTK ?>" disabled
                style="background-color: #f0f0f0;">
            </div>
            <div class="form-group">
                <label>Tên Tài Khoản</label>
                <input type="text" name="TenTK" id="TenTK" required>
            </div>
            <div class="form-group">
                <label>Mật Khẩu</label>
                <input type="text" name="MatKhau" id="MatKhau" required>
            </div>
            <div class="form-group">
                <label>Vai Trò</label>
                <select name="VaiTro" id="VaiTro" required>
                    <option value="Nhân viên">Nhân viên</option>
                    <option value="Quản lý">Quản lý</option>
                </select>
            </div>
            <button type="submit" class="btn-save">Lưu</button>
        </form>
    </div>
</div>

<!-- ========== SCRIPT ========== -->
<script src="../assets/js/script.js"></script>
<script>
   // Mở modal Thêm
function addAccount() {
    // Lấy mã mới từ server
    fetch('accounts.php?action=get_new_maTK')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const newMaTK = data.data.MaTK;
                document.getElementById('modalTitle').innerText = "Thêm Tài Khoản";
                document.getElementById('action').value = "add";
                document.getElementById('MaTK').value = newMaTK;
                document.getElementById('MaTKHidden').value = newMaTK;
                document.getElementById('TenTK').value = "";
                document.getElementById('MatKhau').value = "";
                document.getElementById('VaiTro').value = "Nhân viên";
                openModal('addModal');
            } else {
                alert('Không thể lấy mã tài khoản mới');
            }
        })
        .catch(error => {
            console.error('Lỗi:', error);
            alert('Không thể lấy mã tài khoản mới');
        });
}

// Sửa tài khoản
function editAccount(ma, ten, mk, role) {
    document.getElementById('modalTitle').innerText = "Chỉnh Sửa Tài Khoản";
    document.getElementById('action').value = "edit";
    document.getElementById('MaTK').value = ma;
    document.getElementById('MaTKHidden').value = ma;
    document.getElementById('TenTK').value = ten;
    document.getElementById('MatKhau').value = mk;
    document.getElementById('VaiTro').value = role;
    openModal('addModal');
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

    function deleteSelectedAccounts() {
        const selectedRows = document.querySelectorAll('.selectable-row.selected');
        if (selectedRows.length === 0) {
            alert('Vui lòng chọn ít nhất một tài khoản để xóa!');
            return;
        }
        
        const selectedIds = Array.from(selectedRows).map(row => row.getAttribute('data-id'));
        const count = selectedIds.length;
        
        if (confirm(`Bạn có chắc chắn muốn xóa ${count} tài khoản đã chọn?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete">';
            selectedIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'MaTK[]';
                input.value = id;
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Validate form trước khi submit
    function validateAccountForm() {
        const tenTK = document.querySelector('input[name="TenTK"]').value.trim();
        const matKhau = document.querySelector('input[name="MatKhau"]').value.trim();
        const vaiTro = document.querySelector('select[name="VaiTro"]').value;

        if (!tenTK || !matKhau || !vaiTro) {
            alert('Vui lòng điền đầy đủ tất cả các trường!');
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
            
            // Load column preferences
            loadColumnPreferences();
        });

        // ========== Tùy chọn cột ==========
        const STORAGE_KEY = 'accounts_column_preferences';

        function openColumnToggle() {
            const modal = document.getElementById('columnToggleModal');
            modal.classList.add('show');
            loadCheckboxStates();
        }

        function closeColumnToggle() {
            const modal = document.getElementById('columnToggleModal');
            modal.classList.remove('show');
        }

        function closeColumnToggleOnBackdrop(event) {
            if (event.target.id === 'columnToggleModal') {
                closeColumnToggle();
            }
        }

        function loadCheckboxStates() {
            const preferences = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            const checkboxes = document.querySelectorAll('#columnToggleList input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                const col = checkbox.getAttribute('data-column');
                if (preferences.hasOwnProperty(col)) {
                    checkbox.checked = preferences[col];
                } else {
                    checkbox.checked = true;
                }
            });
        }

        function resetColumnToggle() {
            const checkboxes = document.querySelectorAll('#columnToggleList input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = !checkbox.hasAttribute('disabled');
            });
            // Persist and apply immediately
            applyColumnToggle();
        }

        function applyColumnToggle() {
            const preferences = {};
            const checkboxes = document.querySelectorAll('#columnToggleList input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                const col = checkbox.getAttribute('data-column');
                preferences[col] = checkbox.checked;
                // Đảm bảo cột actions luôn là true
                if (col === 'actions') {
                    preferences[col] = true;
                }
            });
            
            localStorage.setItem(STORAGE_KEY, JSON.stringify(preferences));
            applyColumnVisibility(preferences);
            // adjust widths using shared helpers (if available)
            try {
                const table = document.getElementById('accountsTable');
                const headerCells = Array.from(table.querySelectorAll('thead th'));
                const toggleableHeaders = headerCells.filter(th => !th.classList.contains('actions-column'));
                const visibleCount = toggleableHeaders.filter(th => !th.classList.contains('hidden')).length;
                if (window.clearInlineColumnWidths && window.updateColumnWidths) {
                    if (visibleCount === toggleableHeaders.length) {
                        window.clearInlineColumnWidths(table);
                    } else {
                        window.updateColumnWidths(table);
                    }
                }
            } catch (e) {
                // ignore
            }
            closeColumnToggle();
        }

        function applyColumnVisibility(preferences) {
            const table = document.getElementById('accountsTable');
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
                
                cells.forEach(cell => {
                    if (isVisible) {
                        cell.classList.remove('hidden');
                    } else {
                        cell.classList.add('hidden');
                    }
                });
            });
            // Đảm bảo cột actions luôn visible (trong trường hợp preferences không có key 'actions')
            const actionsCells = table.querySelectorAll(`[data-column="actions"]`);
            actionsCells.forEach(cell => cell.classList.remove('hidden'));
            // ensure widths are updated when preferences applied from other pages/load
            try {
                if (window.updateColumnWidths) window.updateColumnWidths(table);
            } catch (e) {}
        }

        function loadColumnPreferences() {
            const preferences = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            if (Object.keys(preferences).length > 0) {
                applyColumnVisibility(preferences);
            }
        }
    </script>
    <?php require_once 'chatbot_handler.php'; ?>
</body>
</html>