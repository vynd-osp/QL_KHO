<?php
// login.php - Trang đăng nhập
session_start();
session_destroy(); // Xóa session cũ nếu có
session_start(); // Bắt đầu session mới
require_once 'config/db.php';

// Xử lý đăng nhập
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tenTK = $_POST['TenTK'] ?? '';
    $matKhau = $_POST['MatKhau'] ?? '';

    if (!empty($tenTK) && !empty($matKhau)) {
        $stmt = $pdo->prepare("SELECT MaTK, TenTK, MatKhau, VaiTro FROM TAIKHOAN WHERE TenTK = ? AND MatKhau = ?");
        $stmt->execute([$tenTK, $matKhau]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {  // So sánh trực tiếp mật khẩu (chưa hash)
            $_SESSION['user_id'] = $user['MaTK'];
            $_SESSION['username'] = $user['TenTK'];
            $_SESSION['role'] = $user['VaiTro'];

            header("Location: admin/dashboard.php"); // chuyển về trang tổng quan
            exit();
        } else {
            $error = 'Tên tài khoản hoặc mật khẩu không đúng!';
        }
    } else {
        $error = 'Vui lòng nhập đầy đủ thông tin!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập - Hệ Thống Quản Lý Kho Tink Jewelry</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --primary: #004080;
            --secondary: #f8f5f2;
            --accent: #d4af37;
            --light: #ffffff;
            --dark: #333333;
            --text: #333333;
            --text-light: #888888;
            --danger: #f44336;
            --success: #2ecc71;
            --warning: #ff9800;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", sans-serif;
            background: 
                linear-gradient(135deg, rgba(5, 5, 5, 0.7), rgba(113, 113, 109, 0.9)),
                url('photos/anhnendn2.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;

            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        /* Hiệu ứng ánh sáng lấp lánh */
        .sparkle-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .sparkle {
            position: absolute;
            width: 3px;
            height: 3px;
            background: var(--accent);
            border-radius: 50%;
            animation: twinkle 3s infinite;
        }

        @keyframes twinkle {
            0%, 100% { opacity: 0; transform: scale(0.5); }
            50% { opacity: 1; transform: scale(1); }
        }

        /* Container chính */
        .login-container {
            display: flex;
            width: 90%;
            max-width: 900px;
            height: 500px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 
                0 25px 50px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            z-index: 2;
            position: relative;
        }

        /* Phần thông tin bên trái */
        .info-section {
            flex: 0.7;
            background: 
                linear-gradient(135deg, rgba(248, 245, 242, 0.95), rgba(240, 240, 240, 0.9)),
                url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="diamond" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><path d="M10 0L20 10L10 20L0 10Z" fill="none" stroke="%23d4af37" stroke-width="0.3" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23diamond)"/></svg>');
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 30px;
            color: var(--dark);
            text-align: center;
            position: relative;
            border-right: 1px solid rgba(0, 0, 0, 0.1);
        }

        /* Hiệu ứng kim cương */
        .diamond-container {
            position: relative;
            margin-bottom: 20px;
        }

        .diamond {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--accent), #b8860b);
            transform: rotate(45deg);
            margin: 0 auto;
            position: relative;
            box-shadow: 
                0 0 20px rgba(212, 175, 55, 0.7),
                inset 0 0 10px rgba(255, 255, 255, 0.5);
            animation: diamondJump 3s ease-in-out infinite;
        }

        .diamond::before {
            content: "";
            position: absolute;
            top: 5px;
            left: 5px;
            right: 5px;
            bottom: 5px;
            border: 2px solid rgba(255, 255, 255, 0.6);
            transform: rotate(45deg);
        }

        .diamond-sparkle {
            position: absolute;
            width: 6px;
            height: 6px;
            background: white;
            border-radius: 50%;
            animation: sparkle 2s infinite;
        }

        .sparkle-1 {
            top: 12px;
            left: 12px;
            animation-delay: 0s;
        }

        .sparkle-2 {
            top: 12px;
            right: 12px;
            animation-delay: 0.5s;
        }

        .sparkle-3 {
            bottom: 12px;
            left: 12px;
            animation-delay: 1s;
        }

        .sparkle-4 {
            bottom: 12px;
            right: 12px;
            animation-delay: 1.5s;
        }

        @keyframes diamondJump {
            0%, 100% { transform: rotate(45deg) translateY(0); }
            50% { transform: rotate(45deg) translateY(-8px); }
        }

        @keyframes sparkle {
            0%, 100% { opacity: 0; transform: scale(0.5); }
            50% { opacity: 1; transform: scale(1); }
        }

        .info-title {
            font-family: 'Playfair Display', serif;
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--primary);
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .info-title span {
            color: var(--accent);
        }

        .info-subtitle {
            font-size: 16px;
            color: var(--text-light);
            max-width: 250px;
            line-height: 1.5;
            margin-bottom: 25px;
            font-weight: 500;
        }

        .features {
            text-align: left;
            max-width: 250px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            font-size: 14px;
            color: var(--dark);
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.5);
        }

        .feature-item:hover {
            background: rgba(212, 175, 55, 0.1);
            transform: translateX(5px);
        }

        .feature-item i {
            margin-right: 10px;
            color: var(--accent);
            font-weight: bold;
        }

        /* Phần form đăng nhập bên phải */
        .form-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px;
            background: var(--light);
            position: relative;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-title {
            color: var(--primary);
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .login-subtitle {
            color: var(--text-light);
            font-size: 16px;
            font-weight: 500;
        }

        .login-form {
            width: 100%;
        }

        .input-group {
            margin-bottom: 25px;
        }

        .input-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-group input {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid #2c2c2c;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: var(--light);
            font-weight: 500;
        }

        .input-group input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 64, 128, 0.2);
            transform: translateY(-2px);
        }

        .login-btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), #005bb5);
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            box-shadow: 0 6px 20px rgba(0, 64, 128, 0.3);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 64, 128, 0.4);
            background: linear-gradient(135deg, #005bb5, var(--primary));
        }

        .error {
            color: var(--danger);
            text-align: center;
            margin-bottom: 20px;
            padding: 12px;
            background: rgba(244, 67, 54, 0.1);
            border-radius: 8px;
            border-left: 4px solid var(--danger);
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                height: auto;
                max-width: 450px;
            }
            
            .info-section {
                flex: none;
                padding: 25px 20px;
            }
            
            .form-section {
                padding: 30px;
            }
            
            .login-title {
                font-size: 28px;
            }
            
            .diamond {
                width: 70px;
                height: 70px;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                width: 95%;
            }
            
            .info-section {
                padding: 20px 15px;
            }
            
            .form-section {
                padding: 25px;
            }
            
            .login-title {
                font-size: 24px;
            }
            
            .diamond {
                width: 60px;
                height: 60px;
            }
        }
    </style>
</head>
<body>
    <!-- Hiệu ứng ánh sáng lấp lánh -->
    <div class="sparkle-bg" id="sparkleBg"></div>

    <div class="login-container">
        <!-- Phần thông tin bên trái - Thiết kế mới -->
        <div class="info-section">
            <div class="diamond-container">
                <div class="diamond">
                    <div class="diamond-sparkle sparkle-1"></div>
                    <div class="diamond-sparkle sparkle-2"></div>
                    <div class="diamond-sparkle sparkle-3"></div>
                    <div class="diamond-sparkle sparkle-4"></div>
                </div>
            </div>
            <h2 class="info-title">TINK <span>Jewelry</span></h2>
            <p class="info-subtitle">Hệ thống quản lý kho trang sức chuyên nghiệp</p>
            
            <div class="features">
                <div class="feature-item">
                    <i>✦</i> Quản lý kho trang sức
                </div>
                <div class="feature-item">
                    <i>✦</i> Theo dõi nhập xuất
                </div>
                <div class="feature-item">
                    <i>✦</i> Quản lý tài khoản, cửa hàng
                </div>
                <div class="feature-item">
                    <i>✦</i> Báo cáo doanh thu
                </div>
            </div>
        </div>
        
        <!-- Phần form đăng nhập bên phải -->
        <div class="form-section">
            <div class="login-header">
                <h1 class="login-title">ĐĂNG NHẬP</h1>
                <p class="login-subtitle">Chào mừng quay lại hệ thống quản lý kho</p>
            </div>

            <form class="login-form" method="POST">
                <?php if ($error): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="input-group">
                    <label for="TenTK">Tên tài khoản</label>
                    <input type="text" id="TenTK" name="TenTK" placeholder="Nhập tên tài khoản" required>
                </div>

                <div class="input-group">
                    <label for="MatKhau">Mật khẩu</label>
                    <input type="password" id="MatKhau" name="MatKhau" placeholder="Nhập mật khẩu" required>
                </div>

                <button type="submit" class="login-btn">Đăng Nhập</button>
            </form>
        </div>
    </div>

    <script>
        // Tạo hiệu ứng ánh sáng lấp lánh
        document.addEventListener('DOMContentLoaded', function() {
            const sparkleBg = document.getElementById('sparkleBg');
            const sparkleCount = 20;
            
            for (let i = 0; i < sparkleCount; i++) {
                const sparkle = document.createElement('div');
                sparkle.className = 'sparkle';
                sparkle.style.left = Math.random() * 100 + '%';
                sparkle.style.top = Math.random() * 100 + '%';
                sparkle.style.animationDelay = Math.random() * 3 + 's';
                sparkleBg.appendChild(sparkle);
            }
        });
    </script>
</body>
</html>