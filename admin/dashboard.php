<?php
// admin/dashboard.php - Trang tổng quan hệ thống
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userName = $_SESSION['username'] ?? 'Người dùng';
$userRole = $_SESSION['role'] ?? 'Nhân viên';

// Khởi tạo tất cả các biến thống kê với giá trị mặc định
$totalProducts = 0;
$totalStores = 0;
$totalInventoryValue = 0;
$lowStockProducts = 0;
$outOfStockProducts = 0;
$pendingImports = 0;
$pendingExports = 0;
$recentActivities = [];
$error = null;

// Lấy thời gian hiện tại để hiển thị lời chào phù hợp
$currentHour = date('H');
if ($currentHour < 12) {
    $greeting = "Chào buổi sáng";
} elseif ($currentHour < 18) {
    $greeting = "Chào buổi chiều";
} else {
    $greeting = "Chào buổi tối";
}

// Lấy thống kê tổng quan
try {
    // Tổng số sản phẩm
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sanpham");
    $totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Tổng số cửa hàng
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cuahang");
    $totalStores = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Tổng giá trị hàng tồn kho
    $stmt = $pdo->query("SELECT SUM(SLTK * GiaBan) as total FROM sanpham");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalInventoryValue = $result['total'] ?? 0;
    
    // Sản phẩm sắp hết hàng (dưới 10)
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM sanpham WHERE SLTK < 10 AND SLTK > 0");
    $stmt->execute();
    $lowStockProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Sản phẩm hết hàng
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sanpham WHERE SLTK = 0");
    $outOfStockProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Phiếu nhập có trạng thái "Đang xử lý"
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM phieunhap WHERE TinhTrang_PN = 'Đang xử lý'");
    $stmt->execute();
    $pendingImports = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Phiếu xuất có trạng thái "Đang xử lý"
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM phieuxuat WHERE TinhTrang_PX = 'Đang xử lý'");
    $stmt->execute();
    $pendingExports = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
} catch (Exception $e) {
    // Xử lý lỗi nếu có
    $error = "Lỗi khi tải dữ liệu thống kê: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tổng Quan - Hệ Thống Quản Lý Kho Tink</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS cho phần chào mừng */
        .welcome-section {
            background: linear-gradient(135deg, var(--primary), #005bb5);
            color: white;
            padding: 25px 30px;
            margin: 0 30px 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255,255,255,0.1);
            transform: rotate(45deg);
        }
        
        .welcome-content h1 {
            font-size: 28px;
            margin-bottom: 8px;
            font-weight: 700;
        }
        
        .welcome-content p {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .welcome-icon {
            position: absolute;
            right: 30px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 60px;
            opacity: 0.2;
        }
        
        @media (max-width: 768px) {
            .welcome-section {
                margin: 0 15px 20px;
                padding: 20px;
                text-align: center;
            }
            
            .welcome-content h1 {
                font-size: 24px;
            }
            
            .welcome-icon {
                display: none;
            }
        }
        
        /* CSS cho giá trị tồn kho - tự động điều chỉnh */
        .stat-info-inventory {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            width: 100%;
            min-width: 0; /* Cho phép shrink */
        }
        
        .stat-info-inventory .inventory-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0;
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
            white-space: normal; /* Cho phép xuống dòng */
            display: block;
            width: 100%;
        }
        
        .stat-info-inventory .inventory-currency {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            margin-top: 2px;
            display: block;
            line-height: 1.2;
        }
        
        /* Responsive cho giá trị tồn kho */
        @media (max-width: 1024px) {
            .stat-info-inventory .inventory-value {
                font-size: 28px;
            }
            
            .stat-info-inventory .inventory-currency {
                font-size: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .stat-info-inventory .inventory-value {
                font-size: 24px;
            }
            
            .stat-info-inventory .inventory-currency {
                font-size: 18px;
            }
        }
        
        @media (max-width: 480px) {
            .stat-info-inventory .inventory-value {
                font-size: 20px;
            }
            
            .stat-info-inventory .inventory-currency {
                font-size: 16px;
            }
        }
    </style>
    <script>
        // Tự động điều chỉnh font size cho giá trị tồn kho nếu quá dài
        document.addEventListener('DOMContentLoaded', function() {
            const inventoryValue = document.querySelector('.inventory-value');
            if (inventoryValue) {
                const statCard = inventoryValue.closest('.stat-card');
                const statInfo = inventoryValue.closest('.stat-info-inventory');
                
                function adjustInventoryValue() {
                    if (!statCard || !statInfo) return;
                    
                    // Reset về mặc định
                    inventoryValue.style.fontSize = '';
                    inventoryValue.style.transform = '';
                    
                    const cardWidth = statCard.offsetWidth;
                    const iconWidth = statCard.querySelector('.stat-icon')?.offsetWidth || 60;
                    const iconMargin = 20;
                    const padding = 50;
                    const availableWidth = cardWidth - iconWidth - iconMargin - padding;
                    
                    const valueWidth = inventoryValue.scrollWidth;
                    
                    // Nếu số quá dài, tự động scale down
                    if (valueWidth > availableWidth && availableWidth > 0) {
                        const scale = Math.min(0.95, (availableWidth - 10) / valueWidth);
                        if (scale < 1) {
                            inventoryValue.style.transform = `scale(${scale})`;
                            inventoryValue.style.transformOrigin = 'left top';
                        }
                    } else {
                        inventoryValue.style.transform = '';
                    }
                }
                
                // Chạy khi load và khi resize
                setTimeout(adjustInventoryValue, 100);
                window.addEventListener('resize', function() {
                    setTimeout(adjustInventoryValue, 100);
                });
            }
        });
    </script>
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
            <!-- Phần chào mừng -->
            <div class="welcome-section">
                <div class="welcome-content">
                    <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($userName); ?>!</h1>
                    <p>Chào mừng quay trở lại hệ thống quản lý kho TINK Jewelry</p>
                </div>
                <div class="welcome-icon">
                    <i class="fas fa-gem"></i>
                </div>
            </div>
            
            <!-- Thông báo lỗi nếu có -->
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Phần thống kê tổng quan -->
            <div class="stats-section">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-gem"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $totalProducts; ?></h3>
                        <p>Tổng Sản Phẩm</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $totalStores; ?></h3>
                        <p>Cửa Hàng</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-info stat-info-inventory">
                        <h3 class="inventory-value"><?php echo number_format($totalInventoryValue, 0, ',', '.'); ?></h3>
                        <span class="inventory-currency">VNĐ</span>
                        <p>Giá Trị Tồn Kho</p>
                    </div>
                </div>
            </div>
            
            <!-- Thống kê tồn kho -->
            <div class="stats-section">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ff9800, #ff5722);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $lowStockProducts; ?></h3>
                        <p>Sản Phẩm Sắp Hết</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f44336, #d32f2f);">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $outOfStockProducts; ?></h3>
                        <p>Sản Phẩm Hết Hàng</p>
                    </div>
                </div>
            </div>
            
            <!-- Phiếu cần xử lý -->
            <div class="stats-section">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #2196F3, #1976D2);">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $pendingImports; ?></h3>
                        <p>Phiếu Nhập Cần Xử Lý</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #4CAF50, #388E3C);">
                        <i class="fas fa-shipping-fast"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $pendingExports; ?></h3>
                        <p>Phiếu Xuất Cần Xử Lý</p>
                    </div>
                </div>
            </div>
            
            <!-- Hành động nhanh -->
            <div class="quick-actions">
                <h2 class="section-title">Hành Động Nhanh</h2>
                <div class="action-grid">
                    <a href="products.php" class="action-card">
                        <i class="fas fa-plus-circle"></i>
                        <h3>Thêm Sản Phẩm</h3>
                        <p>Thêm sản phẩm mới vào hệ thống</p>
                    </a>
                    
                    <a href="imports.php" class="action-card">
                        <i class="fas fa-box-open"></i>
                        <h3>Nhập Kho</h3>
                        <p>Tạo phiếu nhập kho mới</p>
                    </a>
                    
                    <a href="exports.php" class="action-card">
                        <i class="fas fa-dolly"></i>
                        <h3>Xuất Kho</h3>
                        <p>Tạo phiếu xuất kho mới</p>
                    </a>
                    
                    <?php if ($userRole == 'Quản lý'): ?>
                    <a href="reports.php" class="action-card">
                        <i class="fas fa-chart-bar"></i>
                        <h3>Báo Cáo</h3>
                        <p>Xem báo cáo và thống kê</p>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Hoạt động gần đây -->
            <div class="recent-activity">
                <h2 class="section-title">Hoạt Động Gần Đây</h2>
                <ul class="activity-list">
                    <li class="activity-item">
                        <div class="activity-content">
                            <p>Hệ thống đang được cập nhật. Dữ liệu hoạt động sẽ hiển thị ở đây.</p>
                        </div>
                    </li>
                </ul>
            </div>
        </main>
    </div>

    <script src="../assets/js/script.js"></script>
    <script src="https://kit.fontawesome.com/a2e0b2b9f5.js" crossorigin="anonymous"></script>
    <?php require_once 'chatbot_handler.php'; ?>
</body>
</html>