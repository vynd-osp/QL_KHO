<?php
// admin/export_export_pdf.php - Xuat PDF phieu xuat
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'Nhan vien';

// Kiem tra ma phieu xuat
if (!isset($_POST['MaPX']) || empty($_POST['MaPX'])) {
    die('Loi: Ma phieu xuat khong xac dinh');
}

$maPX = $_POST['MaPX'];

try {
    // Lay thong tin phieu xuat
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
        die('Loi: Khong tim thay phieu xuat');
    }

    // Kiem tra quyen: Nhan vien chi duoc xem phieu cua minh
    if ($userRole == 'Nhan vien' && $phieu['MaTK'] != $userId) {
        die('Loi: Ban khong co quyen xuat phieu xuat nay');
    }

    // Lay chi tiet san pham
    $stmt = $pdo->prepare("
        SELECT ct.*, sp.TenSP
        FROM CHITIETPHIEUXUAT ct
        LEFT JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
        WHERE ct.MaPX = ?
        ORDER BY ct.MaSP
    ");
    $stmt->execute([$maPX]);
    $chiTiet = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tinh tong tien
    $tongTien = 0;
    foreach ($chiTiet as $ct) {
        $tongTien += $ct['ThanhTien'];
    }

    // Ham xac dinh class CSS cho status
    $getStatusClass = function($status) {
        $classes = [
            'Dang xu ly' => 'status-pending',
            'Da duyet' => 'status-approved',
            'Bi tu choi' => 'status-rejected',
            'Hoan thanh' => 'status-completed',
            'Co thay doi' => 'status-changed'
        ];
        return $classes[$status] ?? 'status-pending';
    };

    $statusClass = $getStatusClass($phieu['TinhTrang_PX']);
    $tenCH = htmlspecialchars($phieu['TenCH'] ?? 'N/A');
    $tenTK = htmlspecialchars($phieu['TenTK'] ?? 'N/A');
    $maPXEsc = htmlspecialchars($maPX);
    $ngayXuat = date('d/m/Y', strtotime($phieu['NgayXuat']));
    $tinhTrang = htmlspecialchars($phieu['TinhTrang_PX']);
    $ngayIn = date('d/m/Y H:i:s');

    // Dau ra HTML
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phieu Xuat - <?php echo $maPXEsc; ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .header h1 {
            margin: 0;
            color: #333;
            font-size: 28px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
            font-size: 14px;
        }
        .info-section {
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .info-group {
            padding: 10px 0;
        }
        .info-label {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
            font-size: 13px;
            text-transform: uppercase;
        }
        .info-value {
            color: #666;
            font-size: 14px;
            padding-left: 10px;
            border-left: 2px solid #007bff;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        th {
            background-color: #007bff;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #0056b3;
        }
        td {
            padding: 10px 12px;
            border: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .total-row {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .total-row td {
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 12px;
        }
        .status-pending {
            background-color: #ffc107;
            color: #000;
        }
        .status-approved {
            background-color: #28a745;
            color: white;
        }
        .status-rejected {
            background-color: #dc3545;
            color: white;
        }
        .status-completed {
            background-color: #007bff;
            color: white;
        }
        .status-changed {
            background-color: #fd7e14;
            color: white;
        }
        @media print {
            body {
                background-color: white;
            }
            .container {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>PHIEU XUAT KHO</h1>
            <p>TINK Jewelry Management System</p>
        </div>

        <div class="info-section">
            <div>
                <div class="info-group">
                    <div class="info-label">Ma Phieu Xuat</div>
                    <div class="info-value"><?php echo $maPXEsc; ?></div>
                </div>
                <div class="info-group">
                    <div class="info-label">Ngay Xuat</div>
                    <div class="info-value"><?php echo $ngayXuat; ?></div>
                </div>
                <div class="info-group">
                    <div class="info-label">Cua Hang</div>
                    <div class="info-value"><?php echo $tenCH; ?></div>
                </div>
            </div>
            <div>
                <div class="info-group">
                    <div class="info-label">Nguoi Xuat</div>
                    <div class="info-value"><?php echo $tenTK; ?></div>
                </div>
                <div class="info-group">
                    <div class="info-label">Tinh Trang</div>
                    <div class="info-value">
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo $tinhTrang; ?>
                        </span>
                    </div>
                </div>
                <div class="info-group">
                    <div class="info-label">Ngay In</div>
                    <div class="info-value"><?php echo $ngayIn; ?></div>
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">STT</th>
                    <th style="width: 10%;">Ma SP</th>
                    <th style="width: 35%;">Ten San Pham</th>
                    <th style="width: 12%; text-align: right;">So Luong</th>
                    <th style="width: 15%; text-align: right;">Gia Xuat (VND)</th>
                    <th style="width: 18%; text-align: right;">Thanh Tien (VND)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stt = 1;
                foreach ($chiTiet as $ct) {
                    $giaBan = $ct['ThanhTien'] / $ct['SLX'];
                    $tenSP = htmlspecialchars($ct['TenSP']);
                    $maSP = htmlspecialchars($ct['MaSP']);
                    ?>
                    <tr>
                        <td class="text-center"><?php echo $stt; ?></td>
                        <td><?php echo $maSP; ?></td>
                        <td><?php echo $tenSP; ?></td>
                        <td class="text-right"><?php echo $ct['SLX']; ?> cai</td>
                        <td class="text-right"><?php echo number_format($giaBan, 0, ',', '.'); ?></td>
                        <td class="text-right"><?php echo number_format($ct['ThanhTien'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php
                    $stt++;
                }
                ?>
                <tr class="total-row">
                    <td colspan="5" class="text-right">TONG CONG:</td>
                    <td class="text-right"><?php echo number_format($tongTien, 0, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <p>Tai lieu nay duoc in tu dong tu he thong. Vui long giu de lam tai lieu tham khao.</p>
            <p>2024 TINK Jewelry. Tat ca cac quyen duoc bao luu.</p>
        </div>
    </div>

    <script>
        window.print();
    </script>
</body>
</html>
    <?php

} catch (Exception $e) {
    die('Loi: ' . $e->getMessage());
}
?>
