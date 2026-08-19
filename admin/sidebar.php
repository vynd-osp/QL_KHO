<?php
// admin/sidebar.php - Sidebar menu chung cho tất cả các trang
// Lấy tên file hiện tại để set active state
$current_page = basename($_SERVER['PHP_SELF']);

// Định nghĩa các menu items
$menu_items = [
    ['href' => 'dashboard.php', 'icon' => 'fa-tachometer-alt', 'label' => 'Tổng Quan'],
];

// Thêm menu Quản Lý Tài Khoản (chỉ Quản lý)
if ($userRole == 'Quản lý') {
    $menu_items[] = ['href' => 'accounts.php', 'icon' => 'fa-users', 'label' => 'Quản Lý Tài Khoản'];
}

// Thêm các menu khác
$menu_items[] = ['href' => 'stores.php', 'icon' => 'fa-store', 'label' => 'Quản Lý Cửa Hàng'];
$menu_items[] = ['href' => 'products.php', 'icon' => 'fa-gem', 'label' => 'Quản Lý Sản Phẩm'];
$menu_items[] = ['href' => 'imports.php', 'icon' => 'fa-arrow-down', 'label' => 'Quản Lý Nhập Kho'];
$menu_items[] = ['href' => 'exports.php', 'icon' => 'fa-arrow-up', 'label' => 'Quản Lý Xuất Kho'];

// Thêm menu Quản Lý Báo Cáo (chỉ Quản lý)
if ($userRole == 'Quản lý') {
    $menu_items[] = ['href' => 'reports.php', 'icon' => 'fa-chart-bar', 'label' => 'Quản Lý Báo Cáo'];
}

// Thêm menu Lịch Sử Hoạt Động
$menu_items[] = ['href' => 'activity_log.php', 'icon' => 'fa-history', 'label' => 'Lịch Sử Hoạt Động'];
?>

<aside class="sidebar">
    <ul class="nav-menu">
        <?php foreach ($menu_items as $item): ?>
            <li class="nav-item">
                <a href="<?php echo $item['href']; ?>" 
                   class="nav-link <?php echo ($current_page === $item['href']) ? 'active' : ''; ?>">
                    <i class="fas <?php echo $item['icon']; ?>"></i><?php echo $item['label']; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <li class="divider"></li>
    <div class="logout">
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Đăng Xuất
        </a>
    </div>
</aside>
