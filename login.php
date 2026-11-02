<?php
require_once __DIR__ . '/include/session.php';
require_once 'database/pdo.php';

function login_redirect_for_role(?string $role, ?string $redirect = null): string
{
    if ($redirect !== null && $redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
        return $redirect;
    }
    return gemora_is_admin_role($role) ? 'admin/admin.php' : 'profile.php';
}

$successMessage = '';
$errorMessage = '';

if (isset($_SESSION['user_id'])) {
    $redirect = $_GET['redirect'] ?? null;
    header('Location: ' . login_redirect_for_role($_SESSION['role'] ?? null, $redirect));
    exit;
}

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $successMessage = 'Account created successfully. Please log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errorMessage = 'Please enter both email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } else {
        try {
            $selectQuery = "SELECT user_id, name, email, role, password, image FROM users WHERE email = ? LIMIT 1";
            $statement = $pdo->prepare($selectQuery);
            $statement->execute([$email]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['image'] = $user['image'];

                $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? null;
                header('Location: ' . login_redirect_for_role($user['role'], $redirect));
                exit;
            }

            $errorMessage = 'Invalid email or password.';
        } catch (PDOException $e) {
            error_log("Login query failed: " . $e->getMessage());
            $errorMessage = 'Could not process login request. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Gemora</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .login-page-wrap {
            max-width: 520px;
            margin: 0 auto 24px;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px;
        }

        .login-title {
            margin: 0 0 6px;
            font-size: 26px;
            font-weight: 700;
            color: var(--accent);
        }

        .login-subtitle {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 14px;
        }

        .login-form {
            display: grid;
            gap: 14px;
        }

        .field-group {
            display: grid;
            gap: 6px;
        }

        .field-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }

        .field-input {
            width: 100%;
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s ease;
        }

        .field-input:focus {
            border-color: var(--accent);
        }

        .login-actions {
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .tiny-link {
            font-size: 13px;
            color: var(--accent);
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .feedback {
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .feedback.success {
            background: #e8f4ea;
            border: 1px solid #b4d8b8;
            color: #245d2b;
        }

        .feedback.error {
            background: #fbeaea;
            border: 1px solid #e4b5b5;
            color: #7f2020;
        }

        @media (max-width: 700px) {
            .login-card {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="page" id="app">
        <?php include 'include/header.php'; ?>

        <main class="login-page-wrap">
            <section class="login-card" aria-labelledby="loginHeading">
                <h2 id="loginHeading" class="login-title">Welcome Back</h2>
                <p class="login-subtitle">Log in to access your account, orders, and wishlist.</p>

                <?php if ($successMessage !== ''): ?>
                    <div class="feedback success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="feedback error"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <?php $loginRedirect = $_GET['redirect'] ?? ''; ?>
                <form class="login-form" method="post" action="login.php<?php echo $loginRedirect !== '' ? '?redirect=' . urlencode($loginRedirect) : ''; ?>" novalidate>
                    <?php if ($loginRedirect !== ''): ?>
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($loginRedirect); ?>">
                    <?php endif; ?>
                    <div class="field-group">
                        <label class="field-label" for="email">Email Address *</label>
                        <input class="field-input" type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="password">Password *</label>
                        <input class="field-input" type="password" id="password" name="password" required>
                    </div>

                    <div class="login-actions">
                        <button type="submit" class="btn primary">Log In</button>
                        <a class="tiny-link" href="register.php">Create a new account</a>
                    </div>
                </form>
            </section>
        </main>

        <?php include 'include/footer.php'; ?>
    </div>
</body>
</html>
