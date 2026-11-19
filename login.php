<?php
session_start();
require_once "config/database.php";
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"]);
    $password = $_POST["password"];
    $sql = "SELECT * FROM users WHERE username = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user["password_hash"])) {
        session_regenerate_id(true);
        $_SESSION["user_id"] = $user["user_id"];
        $_SESSION["username"] = $user["username"];
        $_SESSION["full_name"] = $user["full_name"];
        header("Location: dashboard.php");
        exit();
    } else {
        $message = "Incorrect username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Login | Golden Hour Bakehouse</title>
    <style>
        /* LOGIN PAGE */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            color: #2C1A0E;
        }
        .login-page {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 25px 15px;
            background: linear-gradient(
                to bottom,
                #FDF8F0 0%,
                #FDF8F0 50%,
                #F8E8C3 50%,
                #F8E8C3 100%
            );
        }
        .login-card {
            width: 100%;
            max-width: 360px;
            background-color: #2C1A0E;
            padding: 35px 28px 25px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 8px 22px rgba(44, 26, 14, 0.20);
        }
        .login-logo {
            margin-bottom: 12px;
        }
        .login-logo svg {
            width: 75px;
            height: 65px;
        }
        .login-card h1 {
            color: #F5C842;
            font-size: 22px;
            line-height: 1.2;
            font-weight: bold;
            margin-bottom: 25px;
        }
        .login-subtitle {
            color: #F5C842;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 14px;
        }
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .login-form input {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #E8D7C5;
            border-radius: 6px;
            background-color: #FDF8F0;
            color: #2C1A0E;
            font-family: inherit;
            font-size: 14px;
            outline: none;
        }
        .login-form input:focus {
            border-color: #F5C842;
            box-shadow: 0 0 0 2px rgba(245, 200, 66, 0.2);
        }
        .login-form input::placeholder {
            color: #8B6242;
        }
        .login-button {
            width: 100%;
            padding: 12px;
            margin-top: 1px;
            border: none;
            border-radius: 6px;
            background-color: #D4920A;
            color: #2C1A0E;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .login-button:hover {
            background-color: #F5C842;
        }
        .login-error {
            background-color: #F8E2DF;
            color: #8A3025;
            padding: 10px;
            border-radius: 5px;
            font-size: 13px;
            margin-bottom: 15px;
        }
        .forgot-password {
            display: block;
            text-align: right;
            margin-top: 10px;
            color: #E8D7C5;
            font-size: 11px;
        }
        .forgot-password a {
            color: inherit;
            text-decoration: none;
        }
        .forgot-password a:hover {
            color: #F5C842;
            text-decoration: underline;
        }
        @media (max-width: 480px) {
            .login-card {
                max-width: 340px;
                padding: 30px 22px 23px;
            }
            .login-card h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <main class="login-page">
        <section class="login-card">
            <div class="login-logo">
                <!-- Decorative bakery-inspired wheat illustration -->
                <svg viewBox="0 0 100 85" fill="none"
                     xmlns="http://www.w3.org/2000/svg">
                    <g stroke="#8B6242" stroke-width="1.5"
                       stroke-linecap="round">
                        <path d="M50 78 C49 55 45 30 32 8"/>
                        <path d="M50 78 C52 52 62 29 76 9"/>
                        <path d="M50 78 C50 48 50 24 50 5"/>
                        <path d="M38 24 C26 22 24 15 27 10"/>
                        <path d="M43 34 C29 33 27 26 29 20"/>
                        <path d="M46 45 C33 44 31 37 33 31"/>
                        <path d="M62 25 C75 22 77 15 74 10"/>
                        <path d="M57 36 C70 34 73 27 71 21"/>
                        <path d="M54 47 C67 45 70 38 68 32"/>
                        <path d="M47 18 C38 12 39 6 42 3"/>
                        <path d="M53 18 C62 12 61 6 58 3"/>
                    </g>
                </svg>
            </div>
            <h1>GOLDEN HOUR<br>BAKEHOUSE</h1>
            <p class="login-subtitle">MANAGEMENT SYSTEM</p>
            <?php if (!empty($message)): ?>
                <div class="login-error">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="login-form">
                <input
                    type="text"
                    name="username"
                    placeholder="Username"
                    autocomplete="username"
                    required
                >
                <input
                    type="password"
                    name="password"
                    placeholder="Password"
                    autocomplete="current-password"
                    required
                >
                <button type="submit" class="login-button">
                    LOGIN
                </button>
            </form>
            <div class="forgot-password">
                <a href="forgot-password.php">Forgot Password?</a>
            </div>
        </section>
    </main>
</body>
</html>
