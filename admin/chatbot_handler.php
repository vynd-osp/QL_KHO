<?php
// admin/chatbot_handler.php (PHIÊN BẢN CẬP NHẬT)

/*
======================================================================
PART 1: API BACKEND LOGIC (ĐÃ CẬP NHẬT)
======================================================================
*/

// Kiểm tra xem đây có phải là một API call (POST request với JSON)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Nếu có 'action', chúng ta xử lý nó như một API backend
if ($data && isset($data['action'])) {
    
    // --- Bắt đầu API Mode ---
    
    header('Content-Type: application/json');
    if (session_status() == PHP_SESSION_NONE) {
        @session_start();
    }
    require_once '../config/db.php'; 

    // === HÀM TIỆN ÍCH API ===
    function sendResponse($reply, $buttons = [], $await_input = null) {
        echo json_encode([
            'reply' => $reply,
            'buttons' => $buttons,
            'await_input' => $await_input
        ]);
        exit; // Dừng script
    }

    function getBackButton() {
        return [['label' => 'Quay lại menu chính', 'action' => 'parse_message', 'payload' => 'menu']];
    }
    
    $action = $data['action'] ?? 'welcome';
    $payload = $data['payload'] ?? null;

    if (!$pdo) {
        sendResponse('Lỗi: Không thể kết nối đến cơ sở dữ liệu.');
    }

    // === CÁC HÀM XỬ LÝ ===

    // 1.1 Tìm theo tên (Fuzzy)
    function handle_stock_name($pdo, $payload) {
        $keywords = array_filter(explode(' ', $payload));
        if (empty($keywords)) {
            sendResponse("Vui lòng nhập tên sản phẩm.");
        }

        $sql = "SELECT MaSP, TenSP, SLTK, TinhTrang FROM SANPHAM WHERE ";
        $params = [];
        $conditions = [];
        
        foreach ($keywords as $word) {
            $conditions[] = "TenSP LIKE ?";
            $params[] = '%' . $word . '%';
        }
        
        $sql .= implode(' AND ', $conditions) . " LIMIT 5";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($products);

        if ($count === 0) {
            sendResponse("Không tìm thấy sản phẩm nào khớp với: '$payload'.");
        } elseif ($count === 1) {
            $product = $products[0];
            $maSP = $product['MaSP'];
            $reply = "Tìm thấy 1 sản phẩm khớp:\n"
                   . "\n• ({$product['MaSP']}) {$product['TenSP']} - Tồn: {$product['SLTK']} ({$product['TinhTrang']})"
                   . "\n\nBạn có muốn xem toàn bộ thông tin của sản phẩm này không?";
            $buttons = [
                ['label' => 'Có (Xem chi tiết)', 'action' => 'handle_stock_code', 'payload' => $maSP],
                ['label' => 'Không', 'action' => 'handle_stock_detail_no']
            ];
            sendResponse($reply, $buttons);
        } else {
            $reply = "Tìm thấy " . $count . " sản phẩm khớp (Tối đa 5):\n";
            foreach ($products as $p) {
                $reply .= "\n• ({$p['MaSP']}) {$p['TenSP']} - Tồn: {$p['SLTK']} ({$p['TinhTrang']})";
            }
            $reply .= "\n\nVui lòng nhập mã SP (ví dụ: SP00001) để xem chi tiết.";
            sendResponse($reply);
        }
    }

    // 1.2 Tìm theo mã SP
    function handle_stock_code($pdo, $payload) {
        $stmt = $pdo->prepare("SELECT * FROM SANPHAM WHERE MaSP = ?");
        $stmt->execute([strtoupper($payload)]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$p) { sendResponse("Không tìm thấy sản phẩm với mã: '$payload'."); }
        $reply = "Thông tin sản phẩm {$p['MaSP']}:\n"
               . "\n• Tên SP: {$p['TenSP']}"
               . "\n• Tồn kho: {$p['SLTK']}"
               . "\n• Tình trạng: {$p['TinhTrang']}"
               . "\n• Giá bán: " . number_format($p['GiaBan']) . " VNĐ"
               . "\n• Thể loại: {$p['TheLoai']}";
        sendResponse($reply);
    }

    // 1.3 Tồn kho nhiều nhất
    function handle_stock_top($pdo) {
        $stmt = $pdo->query("SELECT TenSP, SLTK FROM SANPHAM ORDER BY SLTK DESC LIMIT 1");
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        $reply = $p ? "Sản phẩm tồn kho nhiều nhất là:\n• {$p['TenSP']} (Số lượng: {$p['SLTK']})" : "Chưa có dữ liệu sản phẩm.";
        sendResponse($reply);
    }

    // 1.4 Tìm SP theo trạng thái
    function handle_stock_status($pdo, $payload) {
        $stmt = $pdo->prepare("SELECT MaSP, TenSP, SLTK FROM SANPHAM WHERE TinhTrang = ? LIMIT 10");
        $stmt->execute([$payload]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$products) { sendResponse("Không có sản phẩm nào ở trạng thái: '$payload'."); }
        $reply = "Các sản phẩm đang '{$payload}':\n";
        foreach ($products as $p) $reply .= "\n• ({$p['MaSP']}) {$p['TenSP']} (Tồn: {$p['SLTK']})";
        sendResponse($reply);
    }

    // 2.1 Tìm theo mã Phiếu
    function handle_slip_code($pdo, $payload) {
        $code = strtoupper(trim($payload));
        $reply = "Không tìm thấy thông tin cho mã: '$code'.";

        if (strpos($code, 'PN') === 0) {
            $stmt = $pdo->prepare("SELECT p.MaPN, p.NgayNhap, p.TinhTrang_PN, t.TenTK, ct.SLN, ct.SLN_MOI, s.TenSP FROM PHIEUNHAP p LEFT JOIN TAIKHOAN t ON p.MaTK = t.MaTK LEFT JOIN CHITIETPHIEUNHAP ct ON p.MaPN = ct.MaPN LEFT JOIN SANPHAM s ON ct.MaSP = s.MaSP WHERE p.MaPN = ?");
            $stmt->execute([$code]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($details && $details[0]['MaPN'] != null) {
                $info = $details[0];
                $reply = "Thông tin Phiếu Nhập {$info['MaPN']}:\n"
                       . "\n• Ngày nhập: " . date('d/m/Y', strtotime($info['NgayNhap']))
                       . "\n• Người nhập: {$info['TenTK']}"
                       . "\n• Trạng thái: {$info['TinhTrang_PN']}"
                       . "\n\nChi tiết sản phẩm:";
                foreach ($details as $d) $reply .= "\n• {$d['TenSP']} (SL: " . (($d['TinhTrang_PN'] == 'Có thay đổi' && $d['SLN_MOI'] !== null) ? $d['SLN_MOI'] : $d['SLN']) . ")";
            }
        } elseif (strpos($code, 'PX') === 0) {
            $stmt = $pdo->prepare("SELECT px.MaPX, px.NgayXuat, px.TinhTrang_PX, t.TenTK, c.TenCH, ct.SLX, ct.SLX_MOI, s.TenSP FROM PHIEUXUAT px LEFT JOIN TAIKHOAN t ON px.MaTK = t.MaTK LEFT JOIN CUAHANG c ON px.MaCH = c.MaCH LEFT JOIN CHITIETPHIEUXUAT ct ON px.MaPX = ct.MaPX LEFT JOIN SANPHAM s ON ct.MaSP = s.MaSP WHERE px.MaPX = ?");
            $stmt->execute([$code]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($details && $details[0]['MaPX'] != null) {
                $info = $details[0];
                $reply = "Thông tin Phiếu Xuất {$info['MaPX']}:\n"
                       . "\n• Ngày xuất: " . date('d/m/Y', strtotime($info['NgayXuat']))
                       . "\n• Người xuất: {$info['TenTK']}"
                       . "\n• Cửa hàng: {$info['TenCH']}"
                       . "\n• Trạng thái: {$info['TinhTrang_PX']}"
                       . "\n\nChi tiết sản phẩm:";
                foreach ($details as $d) $reply .= "\n• {$d['TenSP']} (SL: " . (($d['TinhTrang_PX'] == 'Có thay đổi' && $d['SLX_MOI'] !== null) ? $d['SLX_MOI'] : $d['SLX']) . ")";
            }
        }
        sendResponse($reply);
    }
    
    // 2.2 Tìm phiếu (cả hai) theo trạng thái
    function handle_slip_status($pdo, $payload) {
        $status = $payload;
        $reply = "Các phiếu ở trạng thái '{$status}':\n";
        
        $stmt_pn = $pdo->prepare("SELECT MaPN, NgayNhap FROM PHIEUNHAP WHERE TinhTrang_PN = ? LIMIT 5");
        $stmt_pn->execute([$status]);
        $pns = $stmt_pn->fetchAll(PDO::FETCH_ASSOC);
        if ($pns) {
            $reply .= "\nPhiếu Nhập:";
            foreach ($pns as $p) $reply .= "\n• {$p['MaPN']} (" . date('d/m/Y', strtotime($p['NgayNhap'])) . ")";
        }

        $stmt_px = $pdo->prepare("SELECT MaPX, NgayXuat FROM PHIEUXUAT WHERE TinhTrang_PX = ? LIMIT 5");
        $stmt_px->execute([$status]);
        $pxs = $stmt_px->fetchAll(PDO::FETCH_ASSOC);
        if ($pxs) {
            $reply .= "\n\nPhiếu Xuất:";
            foreach ($pxs as $p) $reply .= "\n• {$p['MaPX']} (" . date('d/m/Y', strtotime($p['NgayXuat'])) . ")";
        }
        
        if (!$pns && !$pxs) $reply = "Không tìm thấy phiếu nào ở trạng thái '$status'.";
        sendResponse($reply);
    }
    
    // 2.2a Tìm CHỈ phiếu nhập theo trạng thái
    function handle_import_slip_status($pdo, $payload) {
        $status = $payload;
        $reply = "Các Phiếu Nhập ở trạng thái '{$status}':\n";
        $stmt_pn = $pdo->prepare("SELECT MaPN, NgayNhap FROM PHIEUNHAP WHERE TinhTrang_PN = ? LIMIT 5");
        $stmt_pn->execute([$status]);
        $pns = $stmt_pn->fetchAll(PDO::FETCH_ASSOC);
        if ($pns) foreach ($pns as $p) $reply .= "\n• {$p['MaPN']} (" . date('d/m/Y', strtotime($p['NgayNhap'])) . ")";
        else $reply = "Không tìm thấy Phiếu Nhập nào ở trạng thái '$status'.";
        sendResponse($reply);
    }

    // 2.2b Tìm CHỈ phiếu xuất theo trạng thái
    function handle_export_slip_status($pdo, $payload) {
        $status = $payload;
        $reply = "Các Phiếu Xuất ở trạng thái '{$status}':\n";
        $stmt_px = $pdo->prepare("SELECT MaPX, NgayXuat FROM PHIEUXUAT WHERE TinhTrang_PX = ? LIMIT 5");
        $stmt_px->execute([$status]);
        $pxs = $stmt_px->fetchAll(PDO::FETCH_ASSOC);
        if ($pxs) foreach ($pxs as $p) $reply .= "\n• {$p['MaPX']} (" . date('d/m/Y', strtotime($p['NgayXuat'])) . ")";
        else $reply = "Không tìm thấy Phiếu Xuất nào ở trạng thái '$status'.";
        sendResponse($reply);
    }

    // 2.3 Tra cứu cửa hàng của PX
    function handle_px_store_lookup($pdo, $payload) {
        $code = strtoupper(trim($payload));
        if (!preg_match('/^(PX\d{5})$/i', $code)) {
            sendResponse("Mã phiếu xuất không hợp lệ. Vui lòng nhập mã (ví dụ: PX00001) và hỏi lại.");
        }
        
        $stmt = $pdo->prepare("SELECT c.MaCH, c.TenCH, c.DiaChi, c.SoDienThoai FROM PHIEUXUAT px JOIN CUAHANG c ON px.MaCH = c.MaCH WHERE px.MaPX = ?");
        $stmt->execute([$code]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$store) { sendResponse("Không tìm thấy cửa hàng cho phiếu xuất $code."); }
        $reply = "Phiếu xuất $code thuộc về cửa hàng:\n"
               . "\n• Mã CH: {$store['MaCH']}"
               . "\n• Tên CH: {$store['TenCH']}"
               . "\n• Địa chỉ: {$store['DiaChi']}"
               . "\n• SĐT: {$store['SoDienThoai']}";
        sendResponse($reply);
    }

    // 2.4 Thống kê SP Nhập/Xuất (Max/Min)
    function handle_product_stats($pdo, $type) {
        $reply = "";
        $query_base_import = "FROM CHITIETPHIEUNHAP ct JOIN SANPHAM sp ON ct.MaSP = sp.MaSP JOIN PHIEUNHAP pn ON ct.MaPN = pn.MaPN WHERE pn.TinhTrang_PN IN ('Hoàn thành', 'Có thay đổi') GROUP BY ct.MaSP ORDER BY total";
        $query_base_export = "FROM CHITIETPHIEUXUAT ct JOIN SANPHAM sp ON ct.MaSP = sp.MaSP JOIN PHIEUXUAT px ON ct.MaPX = px.MaPX WHERE px.TinhTrang_PX IN ('Hoàn thành', 'Có thay đổi') GROUP BY ct.MaSP ORDER BY total";
        
        switch ($type) {
            case 'import_max':
                $stmt = $pdo->query("SELECT sp.TenSP, SUM(CASE WHEN pn.TinhTrang_PN = 'Có thay đổi' THEN IFNULL(ct.SLN_MOI, ct.SLN) ELSE ct.SLN END) as total $query_base_import DESC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Sản phẩm nhập nhiều nhất (theo SL) là:\n• {$data['TenSP']} (Tổng SL: {$data['total']})" : "Chưa có dữ liệu nhập kho.";
                break;
            case 'import_min':
                $stmt = $pdo->query("SELECT sp.TenSP, SUM(CASE WHEN pn.TinhTrang_PN = 'Có thay đổi' THEN IFNULL(ct.SLN_MOI, ct.SLN) ELSE ct.SLN END) as total $query_base_import ASC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Sản phẩm nhập ít nhất (theo SL) là:\n• {$data['TenSP']} (Tổng SL: {$data['total']})" : "Chưa có dữ liệu nhập kho.";
                break;
            case 'export_max':
                $stmt = $pdo->query("SELECT sp.TenSP, SUM(CASE WHEN px.TinhTrang_PX = 'Có thay đổi' THEN IFNULL(ct.SLX_MOI, ct.SLX) ELSE ct.SLX END) as total $query_base_export DESC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Sản phẩm xuất nhiều nhất (theo SL) là:\n• {$data['TenSP']} (Tổng SL: {$data['total']})" : "Chưa có dữ liệu xuất kho.";
                break;
            case 'export_min':
                $stmt = $pdo->query("SELECT sp.TenSP, SUM(CASE WHEN px.TinhTrang_PX = 'Có thay đổi' THEN IFNULL(ct.SLX_MOI, ct.SLX) ELSE ct.SLX END) as total $query_base_export ASC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Sản phẩm xuất ít nhất (theo SL) là:\n• {$data['TenSP']} (Tổng SL: {$data['total']})" : "Chưa có dữ liệu xuất kho.";
                break;
        }
        sendResponse($reply);
    }
    
    // 2.5 Thống kê Nhân viên/Cửa hàng (Max/Min)
    function handle_staff_store_stats($pdo, $type) {
        $reply = ""; $stmt = null;
        switch ($type) {
            case 'staff_import_max':
                $stmt = $pdo->query("SELECT t.TenTK, COUNT(p.MaPN) as total FROM PHIEUNHAP p JOIN TAIKHOAN t ON p.MaTK = t.MaTK GROUP BY p.MaTK ORDER BY total DESC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Nhân viên tạo nhiều Phiếu Nhập nhất là:\n• {$data['TenTK']} ({$data['total']} phiếu)" : "Chưa có dữ liệu.";
                break;
            case 'staff_import_min':
                $stmt = $pdo->query("SELECT t.TenTK, COUNT(p.MaPN) as total FROM PHIEUNHAP p JOIN TAIKHOAN t ON p.MaTK = t.MaTK GROUP BY p.MaTK ORDER BY total ASC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Nhân viên tạo ít Phiếu Nhập nhất là:\n• {$data['TenTK']} ({$data['total']} phiếu)" : "Chưa có dữ liệu.";
                break;
            case 'staff_export_max':
                $stmt = $pdo->query("SELECT t.TenTK, COUNT(p.MaPX) as total FROM PHIEUXUAT p JOIN TAIKHOAN t ON p.MaTK = t.MaTK GROUP BY p.MaTK ORDER BY total DESC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Nhân viên tạo nhiều Phiếu Xuất nhất là:\n• {$data['TenTK']} ({$data['total']} phiếu)" : "Chưa có dữ liệu.";
                break;
            case 'staff_export_min':
                $stmt = $pdo->query("SELECT t.TenTK, COUNT(p.MaPX) as total FROM PHIEUXUAT p JOIN TAIKHOAN t ON p.MaTK = t.MaTK GROUP BY p.MaTK ORDER BY total ASC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Nhân viên tạo ít Phiếu Xuất nhất là:\n• {$data['TenTK']} ({$data['total']} phiếu)" : "Chưa có dữ liệu.";
                break;
            case 'store_export_max':
                $stmt = $pdo->query("SELECT c.TenCH, COUNT(p.MaPX) as total FROM PHIEUXUAT p JOIN CUAHANG c ON p.MaCH = c.MaCH GROUP BY p.MaCH ORDER BY total DESC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Cửa hàng nhận nhiều Phiếu Xuất nhất là:\n• {$data['TenCH']} ({$data['total']} phiếu)" : "Chưa có dữ liệu.";
                break;
            case 'store_export_min':
                $stmt = $pdo->query("SELECT c.TenCH, COUNT(p.MaPX) as total FROM PHIEUXUAT p JOIN CUAHANG c ON p.MaCH = c.MaCH GROUP BY p.MaCH ORDER BY total ASC LIMIT 1");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $reply = $data ? "Cửa hàng nhận ít Phiếu Xuất nhất là:\n• {$data['TenCH']} ({$data['total']} phiếu)" : "Chưa có dữ liệu.";
                break;
        }
        sendResponse($reply);
    }

    // 2.6 Tra cứu thông tin tài khoản
    function handle_account_lookup($pdo, $payload) {
        $search = strtoupper(trim($payload));
        
        // Tìm kiếm theo bất kỳ trường nào: MaTK, TenTK, VaiTro
        $stmt = $pdo->prepare("SELECT MaTK, TenTK, VaiTro FROM TAIKHOAN 
                              WHERE MaTK = ? 
                                 OR UPPER(TenTK) LIKE ? 
                                 OR UPPER(VaiTro) LIKE ? 
                              LIMIT 10");
        $stmt->execute([$search, '%' . $search . '%', '%' . $search . '%']);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($accounts)) {
            sendResponse("Không tìm thấy tài khoản phù hợp với: '$payload'.");
        } elseif (count($accounts) === 1) {
            $acc = $accounts[0];
            $reply = "📋 **Thông tin tài khoản {$acc['MaTK']}:**\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "Mã TK: `{$acc['MaTK']}`\n"
                   . "Tên: `{$acc['TenTK']}`\n"
                   . "Vai trò: `{$acc['VaiTro']}`";
            sendResponse($reply);
        } else {
            $reply = "Tìm thấy " . count($accounts) . " tài khoản khớp:\n";
            foreach ($accounts as $acc) {
                $reply .= "\n`{$acc['MaTK']}` • {$acc['TenTK']} • {$acc['VaiTro']}";
            }
            sendResponse($reply);
        }
    }

    // 2.7 Tra cứu thông tin cửa hàng
    function handle_store_lookup($pdo, $payload) {
        $search = strtoupper(trim($payload));
        
        // Tìm kiếm theo bất kỳ trường nào: MaCH, TenCH, DiaChi, SoDienThoai
        $stmt = $pdo->prepare("SELECT MaCH, TenCH, DiaChi, SoDienThoai FROM CUAHANG 
                              WHERE MaCH = ? 
                                 OR UPPER(TenCH) LIKE ? 
                                 OR UPPER(DiaChi) LIKE ? 
                                 OR SoDienThoai LIKE ? 
                              LIMIT 10");
        $stmt->execute([$search, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%']);
        $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($stores)) {
            sendResponse("Không tìm thấy cửa hàng phù hợp với: '$payload'.");
        } elseif (count($stores) === 1) {
            $store = $stores[0];
            $reply = "🏪 **Thông tin cửa hàng {$store['MaCH']}:**\n"
                   . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                   . "Mã CH: `{$store['MaCH']}`\n"
                   . "Tên: `{$store['TenCH']}`\n"
                   . "Địa chỉ: `{$store['DiaChi']}`\n"
                   . "SĐT: `{$store['SoDienThoai']}`";
            sendResponse($reply);
        } else {
            $reply = "Tìm thấy " . count($stores) . " cửa hàng khớp:\n";
            foreach ($stores as $store) {
                $reply .= "\n`{$store['MaCH']}` • {$store['TenCH']} • {$store['DiaChi']}";
            }
            sendResponse($reply);
        }
    }

    // 2.8 Liệt kê tất cả tài khoản/cửa hàng
    function handle_list_accounts($pdo) {
        $stmt = $pdo->query("SELECT MaTK, TenTK, VaiTro FROM TAIKHOAN ORDER BY VaiTro DESC, TenTK ASC LIMIT 15");
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($accounts)) {
            sendResponse("Chưa có tài khoản nào trong hệ thống.");
        } else {
            $reply = "Danh sách tài khoản (" . count($accounts) . "):\n";
            foreach ($accounts as $acc) {
                $reply .= "\n• ({$acc['MaTK']}) {$acc['TenTK']} - {$acc['VaiTro']}";
            }
            sendResponse($reply);
        }
    }

    function handle_list_stores($pdo) {
        $stmt = $pdo->query("SELECT MaCH, TenCH, DiaChi FROM CUAHANG ORDER BY TenCH ASC LIMIT 15");
        $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($stores)) {
            sendResponse("Chưa có cửa hàng nào trong hệ thống.");
        } else {
            $reply = "Danh sách cửa hàng (" . count($stores) . "):\n";
            foreach ($stores as $store) {
                $reply .= "\n• ({$store['MaCH']}) {$store['TenCH']} - {$store['DiaChi']}";
            }
            sendResponse($reply);
        }
    }

    // 2.9 Tra cứu lịch sử hành động theo ngày/tháng
    function handle_activity_history($pdo, $payload) {
        $dateStr = '';
        $startDate = '';
        $endDate = '';
        
        // Trường hợp 1: Khoảng ngày (16/11/2025 - 19/11/2025)
        if (preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})\s*[-–]\s*(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})/', $payload, $matches)) {
            $day1 = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month1 = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year1 = $matches[3];
            if (strlen($year1) === 2) {
                $year1 = ($year1 < 50) ? '20' . $year1 : '19' . $year1;
            }
            
            $day2 = str_pad($matches[4], 2, '0', STR_PAD_LEFT);
            $month2 = str_pad($matches[5], 2, '0', STR_PAD_LEFT);
            $year2 = $matches[6];
            if (strlen($year2) === 2) {
                $year2 = ($year2 < 50) ? '20' . $year2 : '19' . $year2;
            }
            
            $startDate = "$year1-$month1-$day1";
            $endDate = "$year2-$month2-$day2";
            
            // Kiểm tra ngày hợp lệ
            if (!strtotime($startDate) || !strtotime($endDate)) {
                sendResponse("❌ Ngày không hợp lệ. Vui lòng nhập theo format: dd/mm/yyyy - dd/mm/yyyy");
                return;
            }
            
            if (strtotime($endDate) < strtotime($startDate)) {
                sendResponse("❌ Ngày kết thúc phải sau ngày bắt đầu. Vui lòng kiểm tra lại.");
                return;
            }
            
            $dateStr = "$day1/$month1/$year1 - $day2/$month2/$year2";
        }
        // Trường hợp 2: Một ngày duy nhất (18/11/2025)
        elseif (preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})/', $payload, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            
            if (strlen($year) === 2) {
                $year = ($year < 50) ? '20' . $year : '19' . $year;
            }
            
            $startDate = "$year-$month-$day";
            $endDate = "$year-$month-$day";
            $dateStr = "$day/$month/$year";
            
            if (!strtotime($startDate)) {
                sendResponse("❌ Ngày không hợp lệ. Vui lòng nhập theo format: dd/mm/yyyy");
                return;
            }
        } else {
            sendResponse("❌ Định dạng ngày không đúng. Vui lòng nhập:\n• **Lịch sử dd/mm/yyyy** (một ngày)\n• **Lịch sử dd/mm/yyyy - dd/mm/yyyy** (khoảng ngày)\n\nVí dụ: Lịch sử 18/11/2025 hoặc Lịch sử 16/11/25 - 19/11/25");
            return;
        }
        
        // Truy vấn hành động trong khoảng ngày
        $stmt = $pdo->prepare("
            SELECT 
                TenNhanVien,
                LoaiHanhDong,
                DoiTuong,
                ChiTiet,
                ThoiGian
            FROM LICH_SU_HOAT_DONG 
            WHERE DATE(ThoiGian) BETWEEN ? AND ?
            ORDER BY ThoiGian ASC
        ");
        $stmt->execute([$startDate, $endDate]);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($activities)) {
            sendResponse("📅 Không có hành động nào trong khoảng **$dateStr**");
            return;
        }
        
        // Định dạng kết quả
        $reply = "📋 **Lịch sử hành động $dateStr** (" . count($activities) . " hành động)\n"
               . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach ($activities as $idx => $activity) {
            $time = substr($activity['ThoiGian'], 11, 5); // HH:MM
            $date = substr($activity['ThoiGian'], 0, 10); // YYYY-MM-DD
            $displayDate = implode('/', array_reverse(explode('-', $date))); // DD/MM/YYYY
            
            $reply .= ($idx + 1) . ". **$displayDate $time** - {$activity['TenNhanVien']}\n"
                   . "   • Hành động: `{$activity['LoaiHanhDong']}`\n"
                   . "   • Đối tượng: `{$activity['DoiTuong']}`\n";
            
            if (!empty($activity['ChiTiet'])) {
                $reply .= "   • Chi tiết: {$activity['ChiTiet']}\n";
            }
            $reply .= "\n";
        }
        
        sendResponse($reply);
    }

    // 2.10 Helper tra cứu ngày/tháng
    function parseDateFromQuery($query) {
        if (preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})/', $query, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            return ['type' => 'date', 'value' => "$year-$month-$day"];
        }
        if (preg_match('/tháng\s+(\d{1,2})(?:[\s\/-]+(?:năm\s+)?(\d{4}))?/', $query, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $year = $matches[2] ?? date('Y');
            return ['type' => 'month', 'value' => ['month' => $month, 'year' => $year]];
        }
        if (strpos($query, 'hôm nay') !== false) {
            return ['type' => 'date', 'value' => date('Y-m-d')];
        }
        return null;
    }

    // 2.7 Xử lý tìm phiếu theo ngày/tháng
    function handle_slip_by_date($pdo, $slip_type, $date_info) {
        $table = $slip_type == 'PN' ? 'PHIEUNHAP' : 'PHIEUXUAT';
        $date_col = $slip_type == 'PN' ? 'NgayNhap' : 'NgayXuat';
        $id_col = $slip_type == 'PN' ? 'MaPN' : 'MaPX';
        $sql = "SELECT $id_col, $date_col FROM $table WHERE ";
        $params = [];

        if ($date_info['type'] == 'date') {
            $sql .= "$date_col = ?";
            $params[] = $date_info['value'];
            $reply_date = date('d/m/Y', strtotime($date_info['value']));
            $reply = "Các $table của ngày $reply_date:\n";
        } elseif ($date_info['type'] == 'month') {
            $sql .= "MONTH($date_col) = ? AND YEAR($date_col) = ?";
            $params[] = $date_info['value']['month'];
            $params[] = $date_info['value']['year'];
            $reply_date = "tháng {$date_info['value']['month']}/{$date_info['value']['year']}";
            $reply = "Các $table của $reply_date:\n";
        }

        $stmt = $pdo->prepare($sql . " LIMIT 10");
        $stmt->execute($params);
        $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$slips) { sendResponse("Không tìm thấy $table nào cho $reply_date."); }
        foreach ($slips as $slip) $reply .= "\n• " . $slip[$id_col];
        sendResponse($reply);
    }

    // 3. Hướng dẫn
    function handle_guide($pdo, $payload) {
        $reply = 'Xin lỗi, tôi chưa có hướng dẫn cho mục này.';
        switch ($payload) {
            case null: // Người dùng chỉ gõ "hướng dẫn"
                sendResponse('Vui lòng chọn câu hỏi bạn quan tâm:', [
                    ['label' => 'Cách tạo phiếu nhập mới?', 'action' => 'handle_guide', 'payload' => 'guide_create_import'],
                    ['label' => 'Làm sao để sửa thông tin sản phẩm?', 'action' => 'handle_guide', 'payload' => 'guide_edit_product'],
                    ['label' => 'Làm sao để in báo cáo?', 'action' => 'handle_guide', 'payload' => 'guide_print_report'],
                    ['label' => 'Quy trình kiểm kê cuối tháng?', 'action' => 'handle_guide', 'payload' => 'guide_inventory_check'],
                    ['label' => 'Cách phân quyền nhân viên?', 'action' => 'handle_guide', 'payload' => 'guide_manage_account']
                ]); break;
            case 'guide_create_import': $reply = "Để tạo phiếu nhập, bạn vào mục 'Quản Lý Nhập Kho', nhấn nút 'Thêm Phiếu Nhập'.\n\nSau đó, bạn điền ngày nhập, chọn người nhập, và thêm các sản phẩm cùng số lượng cần nhập."; break;
            case 'guide_edit_product': $reply = "Bạn vào 'Quản Lý Sản Phẩm', tìm sản phẩm cần sửa trong danh sách và nhấn nút 'Sửa'.\n\nMột cửa sổ sẽ hiện lên cho phép bạn thay đổi thông tin sản phẩm."; break;
            case 'guide_print_report': $reply = "Để in báo cáo, bạn vào 'Quản Lý Báo Cáo', chọn tháng/năm bạn muốn xem.\n\nSau khi nhấn 'Xem', bạn có thể nhấn nút 'Xuất PDF' để tải tệp báo cáo về máy."; break;
            case 'guide_inventory_check': $reply = "Quy trình kiểm kê cuối tháng:\n1. Đảm bảo tất cả PN/PX trong tháng đã được chốt (Hoàn thành / Có thay đổi).\n2. Đối chiếu SLTK trên hệ thống (tại 'Quản Lý Sản Phẩm') với thực tế.\n3. Tạo phiếu điều chỉnh nếu có chênh lệch.\n4. Vào 'Quản Lý Báo Cáo' để xem tổng kết."; break;
            case 'guide_manage_account': $reply = "Chức năng này chỉ dành cho 'Quản lý'.\n\nBạn vào 'Quản Lý Tài Khoản', nhấn 'Thêm Tài Khoản' và chọn 'Vai Trò' (Quản lý hoặc Nhân viên) để phân quyền."; break;
        }
        sendResponse($reply);
    }


    // === BỘ ĐIỀU HƯỚNG API CHÍNH (ĐÃ CẬP NHẬT) ===
    try {
        switch ($action) {
            
            // --- Các nút bấm (Actions) ---
            case 'handle_stock_code': handle_stock_code($pdo, $payload); break;
            case 'handle_stock_top': handle_stock_top($pdo); break;
            case 'handle_stock_status': handle_stock_status($pdo, $payload); break;
            case 'handle_stock_detail_no': sendResponse('Ok. Nếu bạn còn câu hỏi nào khác hãy nói cho tôi biết nhé!'); break;
            case 'handle_slip_code': handle_slip_code($pdo, $payload); break;
            case 'handle_slip_status': handle_slip_status($pdo, $payload); break;
            case 'handle_import_slip_status': handle_import_slip_status($pdo, $payload); break;
            case 'handle_export_slip_status': handle_export_slip_status($pdo, $payload); break;
            case 'handle_account_lookup': handle_account_lookup($pdo, $payload); break;
            case 'handle_store_lookup': handle_store_lookup($pdo, $payload); break;
            case 'handle_list_accounts': handle_list_accounts($pdo); break;
            case 'handle_list_stores': handle_list_stores($pdo); break;
            case 'handle_activity_history': handle_activity_history($pdo, $payload); break;
            case 'handle_guide': handle_guide($pdo, $payload); break;
            
            // --- Welcome ---
            case 'welcome':
                sendResponse('Xin chào, tôi có thể giúp gì cho bạn?');
                break;
            
            // --- Main Parser (User typed text) ---
            case 'parse_message':
                $lower_payload = strtolower(trim($payload ?? ''));

                // 1. Chào hỏi
                if (in_array($lower_payload, ['hi', 'chào', 'xin chào', 'hello', 'bắt đầu', 'menu'])) {
                    sendResponse('Xin chào, tôi có thể giúp gì cho bạn?');
                
                // 2. Mã SP
                } elseif (preg_match('/^(SP\d{5})$/i', $lower_payload, $matches)) {
                    handle_stock_code($pdo, $matches[1]);
                
                // 3. Mã PN/PX
                } elseif (preg_match('/^((PN|PX)\d{5})$/i', $lower_payload, $matches)) {
                    handle_slip_code($pdo, $matches[1]);
                
                // 3a. Mã TK / Mã CH
                } elseif (preg_match('/^(TK\d+)$/i', $lower_payload, $matches)) {
                    handle_account_lookup($pdo, $matches[1]);
                } elseif (preg_match('/^(CH\d+)$/i', $lower_payload, $matches)) {
                    handle_store_lookup($pdo, $matches[1]);
                
                // 4. Hỏi cửa hàng của 1 PX
                } elseif (strpos($lower_payload, 'cửa hàng') !== false && preg_match('/(PX\d{5})/i', $lower_payload, $matches)) {
                    handle_px_store_lookup($pdo, $matches[1]);

                // 4a. Hỏi thông tin tài khoản (từ khóa)
                } elseif ((strpos($lower_payload, 'tài khoản') !== false || strpos($lower_payload, 'nhân viên') !== false) && strlen($lower_payload) > 8) {
                    $query = str_replace(['tài khoản', 'nhân viên'], '', $lower_payload);
                    $query = trim($query);
                    if ($query) handle_account_lookup($pdo, $query);
                    else handle_list_accounts($pdo);
                
                // 4b. Hỏi thông tin cửa hàng (từ khóa)
                } elseif ((strpos($lower_payload, 'cửa hàng') !== false || strpos($lower_payload, 'chi nhánh') !== false) && !preg_match('/(PX\d{5})/i', $lower_payload) && strlen($lower_payload) > 8) {
                    $query = str_replace(['cửa hàng', 'chi nhánh'], '', $lower_payload);
                    $query = trim($query);
                    if ($query) handle_store_lookup($pdo, $query);
                    else handle_list_stores($pdo);
                
                // 4c. Liệt kê tài khoản
                } elseif (strpos($lower_payload, 'danh sách tài khoản') !== false || strpos($lower_payload, 'danh sách nhân viên') !== false) {
                    handle_list_accounts($pdo);
                
                // 4d. Liệt kê cửa hàng
                } elseif (strpos($lower_payload, 'danh sách cửa hàng') !== false || strpos($lower_payload, 'danh sách chi nhánh') !== false) {
                    handle_list_stores($pdo);

                // 4e. Lịch sử hành động theo ngày hoặc khoảng ngày
                } elseif (strpos($lower_payload, 'lịch sử') !== false && preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})/', $lower_payload)) {
                    // Trích xuất toàn bộ phần ngày/khoảng ngày
                    if (preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})\s*[-–]\s*(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})/', $lower_payload, $date_matches)) {
                        // Khoảng ngày
                        $date_str = "{$date_matches[1]}/{$date_matches[2]}/{$date_matches[3]} - {$date_matches[4]}/{$date_matches[5]}/{$date_matches[6]}";
                    } else {
                        // Một ngày
                        preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})/', $lower_payload, $date_matches);
                        $date_str = "{$date_matches[1]}/{$date_matches[2]}/{$date_matches[3]}";
                    }
                    handle_activity_history($pdo, $date_str);

                // 5. Hỏi phiếu theo ngày/tháng
                } elseif (($date_info = parseDateFromQuery($lower_payload)) !== null && (strpos($lower_payload, 'phiếu') !== false || strpos($lower_payload, 'pn') !== false || strpos($lower_payload, 'px') !== false)) {
                    $slip_type = (strpos($lower_payload, 'phiếu nhập') !== false || strpos($lower_payload, 'pn') !== false) ? 'PN' : null;
                    if (!$slip_type) $slip_type = (strpos($lower_payload, 'phiếu xuất') !== false || strpos($lower_payload, 'px') !== false) ? 'PX' : null;
                    
                    if ($slip_type) handle_slip_by_date($pdo, $slip_type, $date_info);
                    else sendResponse("Vui lòng cho biết bạn muốn xem 'phiếu nhập' hay 'phiếu xuất' cho ngày/tháng này.");

                // 6. Hướng dẫn
                } elseif (strpos($lower_payload, 'hướng dẫn') !== false || strpos($lower_payload, 'cách') === 0 || strpos($lower_payload, 'làm sao') === 0) {
                     if (strpos($lower_payload, 'phiếu nhập') !== false) handle_guide($pdo, 'guide_create_import');
                     elseif (strpos($lower_payload, 'sửa sản phẩm') !== false) handle_guide($pdo, 'guide_edit_product');
                     elseif (strpos($lower_payload, 'in báo cáo') !== false) handle_guide($pdo, 'guide_print_report');
                     elseif (strpos($lower_payload, 'kiểm kê') !== false) handle_guide($pdo, 'guide_inventory_check');
                     elseif (strpos($lower_payload, 'phân quyền') !== false || strpos($lower_payload, 'nhân viên mới') !== false) handle_guide($pdo, 'guide_manage_account');
                     else handle_guide($pdo, null); // Hiện menu hướng dẫn
                
                // 7. SP Stats
                } elseif (strpos($lower_payload, 'sản phẩm nhập nhiều nhất') !== false) {
                     handle_product_stats($pdo, 'import_max');
                } elseif (strpos($lower_payload, 'sản phẩm nhập ít nhất') !== false) {
                     handle_product_stats($pdo, 'import_min');
                } elseif (strpos($lower_payload, 'sản phẩm xuất nhiều nhất') !== false) {
                     handle_product_stats($pdo, 'export_max');
                } elseif (strpos($lower_payload, 'sản phẩm xuất ít nhất') !== false) {
                     handle_product_stats($pdo, 'export_min');

                // 8. Staff Stats
                } elseif (strpos($lower_payload, 'nhân viên nhập nhiều nhất') !== false) {
                     handle_staff_store_stats($pdo, 'staff_import_max');
                } elseif (strpos($lower_payload, 'nhân viên nhập ít nhất') !== false) {
                     handle_staff_store_stats($pdo, 'staff_import_min');
                } elseif (strpos($lower_payload, 'nhân viên xuất nhiều nhất') !== false) {
                     handle_staff_store_stats($pdo, 'staff_export_max');
                } elseif (strpos($lower_payload, 'nhân viên xuất ít nhất') !== false) {
                     handle_staff_store_stats($pdo, 'staff_export_min');

                // 9. Store Stats
                } elseif (strpos($lower_payload, 'cửa hàng nhận nhiều phiếu nhất') !== false) {
                     handle_staff_store_stats($pdo, 'store_export_max');
                } elseif (strpos($lower_payload, 'cửa hàng nhận ít phiếu nhất') !== false) {
                     handle_staff_store_stats($pdo, 'store_export_min');

                // 10. Trạng thái phiếu (có từ khóa "phiếu", "pn", "px")
                } elseif (preg_match('/(phiếu|pn|px)/', $lower_payload) && preg_match('/(đang xử lý|đã duyệt|bị từ chối|hoàn thành|có thay đổi)/', $lower_payload, $status_matches)) {
                    $status_map = ['đang xử lý' => 'Đang xử lý', 'đã duyệt' => 'Đã duyệt', 'bị từ chối' => 'Bị từ chối', 'hoàn thành' => 'Hoàn thành', 'có thay đổi' => 'Có thay đổi'];
                    $status = $status_map[$status_matches[1]];
                    
                    $found_pn = (strpos($lower_payload, 'phiếu nhập') !== false || strpos($lower_payload, 'pn') !== false);
                    $found_px = (strpos($lower_payload, 'phiếu xuất') !== false || strpos($lower_payload, 'px') !== false);

                    if ($found_pn && !$found_px) handle_import_slip_status($pdo, $status);
                    elseif ($found_px && !$found_pn) handle_export_slip_status($pdo, $status);
                    else handle_slip_status($pdo, $status);

                // 11. Trạng thái SP (không có từ "phiếu")
                } elseif (preg_match('/(hết hàng|còn hàng|ngừng kinh doanh)/', $lower_payload, $status_matches)) {
                    $stock_status_map = ['hết hàng' => 'Hết hàng', 'còn hàng' => 'Còn hàng', 'ngừng kinh doanh' => 'Ngừng kinh doanh'];
                    $status = $stock_status_map[$status_matches[1]];
                    handle_stock_status($pdo, $status);

                // 12. Stats Tồn kho
                } elseif (strpos($lower_payload, 'tồn kho nhiều nhất') !== false) {
                    handle_stock_top($pdo);
                
                // *** CẬP NHẬT LOGIC FALLBACK ***
                } else {
                    // 13. Kiểm tra xem có phải câu hỏi ngoài luồng không
                    $irrelevant_keywords = ['thời tiết', 'ăn cơm', 'bạn là ai', 'khỏe không', 'thế nào', 'bạn ăn', 'bạn có'];
                    $is_irrelevant = false;
                    foreach ($irrelevant_keywords as $key) {
                        if (strpos($lower_payload, $key) !== false) {
                            $is_irrelevant = true;
                            break;
                        }
                    }

                    // 14. Kiểm tra các từ khóa nghiệp vụ (nếu có 1 từ, nó sẽ không bị coi là ngoài luồng)
                    $business_keywords = ['sản phẩm', 'sp', 'phiếu', 'pn', 'px', 'kho', 'tồn', 'nhân viên', 'cửa hàng'];
                    $is_business = false;
                    foreach ($business_keywords as $key) {
                        if (strpos($lower_payload, $key) !== false) {
                            $is_business = true;
                            break;
                        }
                    }
                    
                    if ($is_irrelevant && !$is_business) {
                        // Nếu có từ khóa ngoài luồng VÀ không có từ khóa nghiệp vụ -> Fallback
                        sendResponse('Hiện tại, chatbot không hỗ trợ phần này.');
                    } else {
                        // Mặc định cuối cùng: Coi đó là tìm kiếm tên SP
                        handle_stock_name($pdo, $lower_payload);
                    }
                }
                
                // Fallback (just in case)
                sendResponse('Hiện tại, chatbot không hỗ trợ phần này.');
                break;
            
            default:
                sendResponse('Hiện tại, chatbot không hỗ trợ phần này.');
        }
    } catch (PDOException $e) {
        sendResponse('Lỗi cơ sở dữ liệu: ' . $e->getMessage());
    } catch (Exception $e) {
        sendResponse('Đã xảy ra lỗi: ' . $e->getMessage());
    }
    
    // --- Kết thúc API Mode ---
    exit;
}

/*
======================================================================
PART 2: UI FRONTEND INJECTION (Không thay đổi)
======================================================================
*/
?>

<style>
    #chat-bubble {
        position: fixed; bottom: 25px; right: 25px;
        width: 60px; height: 60px;
        background: #004080; color: white;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 30px; cursor: pointer;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 9998; transition: transform 0.2s;
    }
    #chat-bubble:hover { transform: scale(1.1); }
    
    #chat-container {
        position: fixed; bottom: 100px; right: 25px;
        width: 350px; height: 500px;
        background-color: #fff; border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        display: none; flex-direction: column;
        overflow: hidden; z-index: 9999;
        font-family: Arial, sans-serif;
    }
    #chat-container.open { display: flex; }

    #chat-header {
        background-color: #004080; color: white;
        padding: 15px; font-weight: bold;
        display: flex; justify-content: space-between; align-items: center;
    }
    
    #chat-header div {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    #chat-reset-btn, #chat-close-btn {
        font-size: 24px; font-weight: bold;
        cursor: pointer; opacity: 0.8;
    }
    #chat-reset-btn { font-size: 20px; }
    #chat-reset-btn:hover, #chat-close-btn:hover { opacity: 1; }

    #chat-messages {
        flex-grow: 1; padding: 15px;
        overflow-y: auto; display: flex;
        flex-direction: column; gap: 10px;
        background-color: #f0f2f5;
    }
    
    .message {
        padding: 10px 15px; border-radius: 18px;
        max-width: 85%; word-wrap: break-word;
        line-height: 1.4;
    }
    .message.user {
        background-color: #007bff; color: white;
        align-self: flex-end; border-bottom-right-radius: 5px;
    }
    .message.bot {
        background-color: #e9ecef; color: #333;
        align-self: flex-start; border-bottom-left-radius: 5px;
    }
    
    .message pre {
        white-space: pre-wrap; word-wrap: break-word;
        font-family: inherit; margin: 0; padding: 0;
    }

    .message.bot .buttons {
        margin-top: 10px; display: flex;
        flex-direction: column; gap: 5px;
    }
    .message.bot .buttons button {
        background-color: #fff; border: 1px solid #007bff;
        color: #007bff; padding: 8px; border-radius: 15px;
        cursor: pointer; text-align: left;
        transition: background-color 0.2s;
        font-size: 14px;
    }
    .message.bot .buttons button:hover { background-color: #f0f0f0; }

    #chat-input-container {
        display: flex; border-top: 1px solid #ddd;
        padding: 10px; background: #fff;
    }
    #chat-input {
        flex-grow: 1; border: 1px solid #ccc;
        border-radius: 20px; padding: 10px 15px;
        outline: none; font-size: 14px;
    }
    #send-btn {
        background-color: #004080; color: white;
        border: none; border-radius: 50%;
        width: 40px; height: 40px;
        margin-left: 10px; cursor: pointer;
        font-size: 18px;
    }
</style>

<div id="chat-bubble">
    <i class="fas fa-comment-dots"></i>
</div>

<div id="chat-container">
    <div id="chat-header">
        <span>TINK Jewelry Chatbot</span>
        <div>
            <span id="chat-reset-btn" title="Xóa lịch sử">&#x21bb;</span>
            <span id="chat-close-btn" title="Đóng">&times;</span>
        </div>
    </div>
    <div id="chat-messages" id="chat-messages">
        </div>
    <div id="chat-input-container">
        <input type="text" id="chat-input" placeholder="Nhập câu hỏi của bạn...">
        <button id="send-btn">➤</button>
    </div>
</div>

<script>
(function() {
    // === KHAI BÁO BIẾN ===
    const API_URL = 'chatbot_handler.php'; 
    const CHAT_HISTORY_KEY = 'tinkChatHistory';
    
    const chatBubble = document.getElementById('chat-bubble');
    const chatContainer = document.getElementById('chat-container');
    const chatCloseBtn = document.getElementById('chat-close-btn');
    const chatResetBtn = document.getElementById('chat-reset-btn');
    const chatMessages = document.getElementById('chat-messages');
    const chatInput = document.getElementById('chat-input');
    const sendBtn = document.getElementById('send-btn');
    
    let currentInputAction = null;
    let isChatStarted = false;

    // === HÀM LƯU/TẢI LỊCH SỬ ===

    function saveHistory(sender, text, buttons = []) {
        if (text === '...') return;
        let history = JSON.parse(sessionStorage.getItem(CHAT_HISTORY_KEY)) || [];
        if (history.length > 50) history = history.slice(history.length - 50);
        history.push({ sender, text, buttons });
        sessionStorage.setItem(CHAT_HISTORY_KEY, JSON.stringify(history));
    }

    function loadHistory() {
        const history = JSON.parse(sessionStorage.getItem(CHAT_HISTORY_KEY)) || [];
        chatMessages.innerHTML = '';
        if (history.length > 0) {
            history.forEach(msg => addMessageToChat(msg.sender, msg.text, msg.buttons, false));
            isChatStarted = true;
        } else {
            isChatStarted = false;
        }
        scrollToBottom();
    }
    
    function resetChat() {
        chatMessages.innerHTML = '';
        sessionStorage.removeItem(CHAT_HISTORY_KEY);
        isChatStarted = false;
        currentInputAction = null;
        chatInput.placeholder = 'Nhập câu hỏi của bạn...';
        postToAction('welcome', null);
    }

    // === HÀM GỬI YÊU CẦU ===
    async function postToAction(action, payload) {
        try {
            chatInput.disabled = true;
            addMessageToChat('bot', '...', [], false);

            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, payload })
            });
            
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            
            const data = await response.json();
            renderResponse(data);

        } catch (error) {
            console.error('Lỗi khi gọi API:', error);
            renderResponse({reply: 'Tôi đã gặp lỗi kết nối. Vui lòng thử lại.'});
        } finally {
            chatInput.disabled = false;
            chatInput.focus();
        }
    }

    // === HÀM XỬ LÝ GIAO DIỆN ===
    
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function addMessageToChat(sender, text, buttons = [], save = true) {
        const loadingMsg = chatMessages.querySelector('.message.bot.loading');
        if (loadingMsg) loadingMsg.remove();
        
        if (sender === 'bot' && text === '...') {
             const msgDiv = document.createElement('div');
             msgDiv.className = `message bot loading`;
             msgDiv.innerText = '...';
             chatMessages.appendChild(msgDiv);
        } else {
             const msgDiv = document.createElement('div');
            msgDiv.className = `message ${sender}`;
            
            const pre = document.createElement('pre');
            pre.textContent = text;
            msgDiv.appendChild(pre);

            if (buttons && buttons.length > 0) {
                const btnContainer = document.createElement('div');
                btnContainer.className = 'buttons';
                
                buttons.forEach(btn => {
                    const button = document.createElement('button');
                    button.innerText = btn.label;
                    button.dataset.action = btn.action;
                    if(btn.payload) {
                        button.dataset.payload = btn.payload;
                    }
                    btnContainer.appendChild(button);
                });
                msgDiv.appendChild(btnContainer);
            }
            chatMessages.appendChild(msgDiv);
            
            if (save) saveHistory(sender, text, buttons);
        }
        scrollToBottom();
    }

    function renderResponse(data) {
        const loadingMsg = chatMessages.querySelector('.message.bot.loading');
        if (loadingMsg) loadingMsg.remove();
        
        addMessageToChat('bot', data.reply, data.buttons || []);
        
        if (data.await_input) {
            currentInputAction = data.await_input;
            chatInput.placeholder = data.reply;
        } else {
            currentInputAction = null;
            chatInput.placeholder = 'Nhập câu hỏi của bạn...';
        }
    }

    // === HÀM XỬ LÝ TƯƠNG TÁC ===
    function handleUserInput() {
        const text = chatInput.value.trim();
        if (text === '') return;
        addMessageToChat('user', text);
        let action = 'parse_message';
        let payload = text;
        currentInputAction = null;
        chatInput.placeholder = 'Nhập câu hỏi của bạn...';
        postToAction(action, payload);
        chatInput.value = '';
    }

    function handleButtonClick(action, payload, label) {
        addMessageToChat('user', label);
        postToAction(action, payload);
    }

    // === LẮNG NGHE SỰ KIỆN ===
    
    chatBubble.addEventListener('click', () => {
        chatContainer.classList.toggle('open');
        if (chatContainer.classList.contains('open') && !isChatStarted) {
            postToAction('welcome', null);
            isChatStarted = true;
        }
        if (chatContainer.classList.contains('open')) scrollToBottom();
    });
    
    chatCloseBtn.addEventListener('click', () => {
        chatContainer.classList.remove('open');
    });
    
    chatResetBtn.addEventListener('click', () => {
        if (confirm('Bạn có chắc chắn muốn xóa lịch sử cuộc trò chuyện này?')) {
            resetChat();
        }
    });

    sendBtn.addEventListener('click', handleUserInput);
    chatInput.addEventListener('keyup', (e) => {
        if (e.key === 'Enter') handleUserInput();
    });

    chatMessages.addEventListener('click', (e) => {
        if (e.target.tagName === 'BUTTON' && e.target.dataset.action) {
            const action = e.target.dataset.action;
            const payload = e.target.dataset.payload || null;
            const label = e.target.innerText;
            handleButtonClick(action, payload, label);
        }
    });
    
    loadHistory();

})(); // Kết thúc IIFE
</script>