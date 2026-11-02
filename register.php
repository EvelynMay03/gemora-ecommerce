<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Gemora</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .register-page-wrap {
            max-width: 560px;
            margin: 0 auto 24px;
        }

        .register-card {
            background: #ffffff;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px;
        }

        .register-title {
            margin: 0 0 6px;
            font-size: 26px;
            font-weight: 700;
            color: var(--accent);
        }

        .register-subtitle {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 14px;
        }

        .register-form {
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

        .inline-fields {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr 1fr;
        }

        .check-row {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            font-size: 13px;
            color: var(--muted);
        }

        .check-row input {
            margin-top: 2px;
        }

        .register-actions {
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
            .register-card {
                padding: 16px;
            }

            .inline-fields {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page" id="app">
        <?php include 'include/header.php'; ?>

        <main class="register-page-wrap">
            <section class="register-card" aria-labelledby="registerHeading">
                <h2 id="registerHeading" class="register-title">Create Account</h2>
                <p class="register-subtitle">Register to save your wishlist, track orders, and checkout faster.</p>

                <?php
                require_once 'database/pdo.php';
                $successMessage = '';
                $errorMessage = '';

                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $name = trim($_POST['name'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $phone = trim($_POST['phone'] ?? '');
                    $password = $_POST['password'] ?? '';
                    $confirmPassword = $_POST['confirm_password'] ?? '';
                    $agreeTerms = isset($_POST['agree_terms']);

                    if ($name === '' || $email === '' || $password === '' || $confirmPassword === '') {
                        $errorMessage = 'Please fill in all required fields.';
                    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errorMessage = 'Please enter a valid email address.';
                    } elseif (strlen($password) < 8) {
                        $errorMessage = 'Password must be at least 8 characters long.';
                    } elseif ($password !== $confirmPassword) {
                        $errorMessage = 'Passwords do not match.';
                    } elseif (!$agreeTerms) {
                        $errorMessage = 'Please agree to the Terms and Privacy Policy.';
                    } else {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        try {
                            $insertQuery = 'INSERT INTO users (name, email, phone, password, image) VALUES (?, ?, ?, ?, NULL)';
                            $statement = $pdo->prepare($insertQuery);
                            $statement->execute([$name, $email, $phone, $hashedPassword]);
                            
                            $successMessage = 'Account created successfully. You can now sign in.';
                            $_POST = [];
                        } catch (PDOException $e) {
                            if ($e->getCode() == 23000) {
                                $errorMessage = 'This email is already registered.';
                            } else {
                                error_log("Registration failed: " . $e->getMessage());
                                $errorMessage = 'Registration failed. Please try again.';
                            }
                        }
                    }
                }
                ?>

                <?php if ($successMessage !== ''): ?>
                    <div class="feedback success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="feedback error"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <form class="register-form" method="post" action="register.php" novalidate>
                    <div class="field-group">
                        <label class="field-label" for="name">Name *</label>
                        <input class="field-input" type="text" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="email">Email Address *</label>
                        <input class="field-input" type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="phone">Phone Number</label>
                        <input class="field-input" type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="password">Password *</label>
                        <input class="field-input" type="password" id="password" name="password" required>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="confirm_password">Confirm Password *</label>
                        <input class="field-input" type="password" id="confirm_password" name="confirm_password" required>
                    </div>

                    <label class="check-row" for="agree_terms">
                        <input type="checkbox" id="agree_terms" name="agree_terms" <?php echo isset($_POST['agree_terms']) ? 'checked' : ''; ?>>
                        <span>I agree to the Terms of Service and Privacy Policy.</span>
                    </label>

                    <div class="register-actions">
                        <button type="submit" class="btn primary">Create Account</button>
                        <a class="tiny-link" href="login.php">Already have an account?</a>
                    </div>
                </form>
            </section>
        </main>

        <?php include 'include/footer.php'; ?>
    </div>
</body>
</html>
