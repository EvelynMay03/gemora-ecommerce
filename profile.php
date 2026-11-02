<?php
require_once __DIR__ . '/include/session.php';
require_once 'database/pdo.php';
require_once __DIR__ . '/user/include/address_lib.php';
require_once __DIR__ . '/user/include/checkout_lib.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$addressMessage = '';
$addressMessageType = 'success';
$profileMessage = '';
$profileMessageType = 'success';
$securityMessage = '';
$securityMessageType = 'success';
$photoMessage = '';
$photoMessageType = 'success';
$preferencesMessage = '';
$preferencesMessageType = 'success';
$showAddressForm = isset($_GET['add_address']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isProfilePhotoUpload = isset($_POST['upload_profile_image'])
        || (!empty($_FILES['profile_image']['name']) && (int) ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

    if ($isProfilePhotoUpload) {
        $uploadResult = profile_save_user_image($userId, $_FILES['profile_image'] ?? []);
        if (!empty($uploadResult['ok'])) {
            try {
                $oldStmt = $pdo->prepare('SELECT image FROM users WHERE user_id = ? LIMIT 1');
                $oldStmt->execute([$userId]);
                $oldRow = $oldStmt->fetch();
                $oldImage = $oldRow['image'] ?? null;

                $newPath = (string) ($uploadResult['path'] ?? '');
                $update = $pdo->prepare('UPDATE users SET image = ? WHERE user_id = ?');
                $update->execute([$newPath, $userId]);

                profile_remove_user_image_file($oldImage);
                $_SESSION['image'] = $newPath;

                header('Location: profile.php?msg=photo_updated');
                exit;
            } catch (PDOException $e) {
                error_log('Failed to save profile photo: ' . $e->getMessage());
                profile_remove_user_image_file($uploadResult['path'] ?? null);
                $photoMessage = 'Could not save profile photo. Please try again.';
                $photoMessageType = 'error';
            }
        } else {
            $photoMessage = (string) ($uploadResult['message'] ?? 'Could not upload photo.');
            $photoMessageType = 'error';
        }
    }

    if (isset($_POST['save_preferences'])) {
        $prefs = [
            'email_notifications' => isset($_POST['email_notifications']),
            'sms_notifications' => isset($_POST['sms_notifications']),
            'marketing_emails' => isset($_POST['marketing_emails']),
        ];

        try {
            $preferenceJson = profile_encode_preferences($prefs);
            $update = $pdo->prepare('UPDATE users SET preference = ? WHERE user_id = ?');
            $update->execute([$preferenceJson, $userId]);
            header('Location: profile.php?tab=preferences&msg=preferences_saved');
            exit;
        } catch (PDOException $e) {
            error_log('Failed to save preferences: ' . $e->getMessage());
            $preferencesMessage = 'Could not save preferences. Please run database/migrate_preferences.sql and try again.';
            $preferencesMessageType = 'error';
        }
    }

    if (isset($_POST['save_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || $email === '') {
            $profileMessage = 'Name and email are required.';
            $profileMessageType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileMessage = 'Please enter a valid email address.';
            $profileMessageType = 'error';
        } else {
            try {
                $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1');
                $check->execute([$email, $userId]);
                if ($check->fetch()) {
                    $profileMessage = 'This email is already used by another account.';
                    $profileMessageType = 'error';
                } else {
                    $update = $pdo->prepare('
                        UPDATE users SET name = ?, email = ?, phone = ?
                        WHERE user_id = ?
                    ');
                    $update->execute([
                        $name,
                        $email,
                        $phone === '' ? null : $phone,
                        $userId,
                    ]);
                    $_SESSION['name'] = $name;
                    $_SESSION['email'] = $email;
                    header('Location: profile.php?tab=personal-info&msg=profile_saved');
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Failed to update profile: ' . $e->getMessage());
                $profileMessage = 'Could not update profile. Please try again.';
                $profileMessageType = 'error';
            }
        }
    }

    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $securityMessage = 'Please fill in all password fields.';
            $securityMessageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $securityMessage = 'New password must be at least 8 characters.';
            $securityMessageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $securityMessage = 'New passwords do not match.';
            $securityMessageType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT password FROM users WHERE user_id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($currentPassword, $row['password'])) {
                    $securityMessage = 'Current password is incorrect.';
                    $securityMessageType = 'error';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([$hash, $userId]);
                    header('Location: profile.php?tab=security&msg=password_updated');
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Failed to update password: ' . $e->getMessage());
                $securityMessage = 'Could not update password. Please try again.';
                $securityMessageType = 'error';
            }
        }
    }

    if (isset($_POST['save_address'])) {
        try {
            $result = address_save($pdo, $userId, [
                'region' => $_POST['region'] ?? '',
                'city_township' => $_POST['city_township'] ?? '',
                'apartment_building' => $_POST['apartment_building'] ?? '',
                'street_address' => $_POST['street_address'] ?? '',
            ]);
            if ($result['ok']) {
                header('Location: profile.php?tab=addresses&msg=address_saved');
                exit;
            }
            $addressMessage = $result['message'];
            $addressMessageType = 'error';
            $showAddressForm = true;
        } catch (PDOException $e) {
            error_log('Failed to save address: ' . $e->getMessage());
            $addressMessage = 'Could not save address. Please try again.';
            $addressMessageType = 'error';
            $showAddressForm = true;
        }
    }

    if (isset($_POST['delete_address'])) {
        $addressId = (int) ($_POST['address_id'] ?? 0);
        if ($addressId > 0) {
            try {
                $delete = $pdo->prepare('DELETE FROM addresses WHERE address_id = ? AND user_id = ?');
                $delete->execute([$addressId, $userId]);
                header('Location: profile.php?tab=addresses&msg=address_deleted');
                exit;
            } catch (PDOException $e) {
                error_log('Failed to delete address: ' . $e->getMessage());
                $addressMessage = 'Could not remove address. Please try again.';
                $addressMessageType = 'error';
            }
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'address_saved') {
        $addressMessage = 'Address saved successfully.';
        $addressMessageType = 'success';
    } elseif ($_GET['msg'] === 'address_deleted') {
        $addressMessage = 'Address removed.';
        $addressMessageType = 'success';
    } elseif ($_GET['msg'] === 'profile_saved') {
        $profileMessage = 'Profile updated successfully.';
        $profileMessageType = 'success';
    } elseif ($_GET['msg'] === 'password_updated') {
        $securityMessage = 'Password updated successfully.';
        $securityMessageType = 'success';
    } elseif ($_GET['msg'] === 'photo_updated') {
        $photoMessage = 'Profile photo updated successfully.';
        $photoMessageType = 'success';
    } elseif ($_GET['msg'] === 'preferences_saved') {
        $preferencesMessage = 'Notification preferences saved successfully.';
        $preferencesMessageType = 'success';
    }
}

$userData = null;
try {
    $statement = $pdo->prepare(
        'SELECT user_id, name, email, phone, image, preference, role, created_at
         FROM users WHERE user_id = ? LIMIT 1'
    );
    $statement->execute([$userId]);
    $userData = $statement->fetch();
} catch (PDOException $e) {
    error_log('Failed to fetch user data: ' . $e->getMessage());
}

if (!$userData) {
    $userData = [
        'user_id' => $userId,
        'name' => $_SESSION['name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'phone' => '',
        'image' => $_SESSION['image'] ?? null,
        'preference' => null,
        'role' => $_SESSION['role'] ?? 'customer',
        'created_at' => '',
    ];
}

$profileFormDefaults = [
    'name' => $userData['name'] ?? '',
    'email' => $userData['email'] ?? '',
    'phone' => $userData['phone'] ?? '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile']) && $profileMessageType === 'error') {
    $profileFormDefaults = [
        'name' => trim($_POST['name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
    ];
}

$profileImagePath = profile_user_image_url($userData['image'] ?? null);
$profileImageInitial = strtoupper(substr((string) ($userData['name'] ?? 'U'), 0, 1));

$userPreferences = profile_parse_preferences($userData['preference'] ?? null);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_preferences']) && $preferencesMessageType === 'error') {
    $userPreferences = [
        'email_notifications' => isset($_POST['email_notifications']),
        'sms_notifications' => isset($_POST['sms_notifications']),
        'marketing_emails' => isset($_POST['marketing_emails']),
    ];
}

$userAddresses = [];
$addressRegions = address_myanmar_regions();
try {
    $userAddresses = address_fetch_for_user($pdo, $userId);
} catch (PDOException $e) {
    error_log('Failed to fetch addresses: ' . $e->getMessage());
}

$addressFormDefaults = [
    'region' => $_POST['region'] ?? 'Yangon',
    'city_township' => $_POST['city_township'] ?? '',
    'apartment_building' => $_POST['apartment_building'] ?? '',
    'street_address' => $_POST['street_address'] ?? '',
];

$isSuperAdmin = gemora_is_superadmin_role($userData['role'] ?? ($_SESSION['role'] ?? ''));

$userOrders = [];
$allUserOrders = [];
$orderSearchQuery = trim((string) ($_GET['order_q'] ?? ''));
$selectedOrderId = max(0, (int) ($_GET['order'] ?? 0));
try {
    $allUserOrders = profile_fetch_user_orders($pdo, $userId);
    $userOrders = $allUserOrders;

    if ($orderSearchQuery !== '') {
        $needle = strtoupper(preg_replace('/\s+/', '', $orderSearchQuery));
        $userOrders = array_values(array_filter($allUserOrders, static function (array $order) use ($needle): bool {
            $orderNumber = strtoupper(trim((string) ($order['order_number'] ?? '')));
            if ($orderNumber !== '' && str_contains($orderNumber, $needle)) {
                return true;
            }

            $displayNumber = strtoupper(checkout_display_order_number($order));

            return str_contains($displayNumber, $needle);
        }));
    }
} catch (Throwable $e) {
    error_log('Failed to fetch user orders: ' . $e->getMessage());
    $allUserOrders = [];
    $userOrders = [];
}
$hasAnyOrders = !empty($allUserOrders);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Gemora</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="page" id="app">
        <?php include 'include/header.php'; ?>

        <main class="profile-page">
            <div class="profile-header">
                <div class="header-content">
                    <div>
                        <h1>My Profile</h1>
                        <p class="profile-subtitle">Manage your account settings and preferences</p>
                    </div>
                    <div class="header-actions">
                        <?php if ($isSuperAdmin): ?>
                        <a href="admin/admin.php" class="header-action-btn admin-dashboard-btn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" aria-hidden="true">
                                <rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                <rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                <rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                <rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            Back to Dashboard
                        </a>
                        <?php endif; ?>
                        <button type="button" class="header-action-btn header-logout" onclick="window.location.href='logout.php'">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            <polyline points="16,17 21,12 16,7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <line x1="21" y1="12" x2="9" y2="12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        Logout
                        </button>
                    </div>
                </div>
            </div>

            <div class="profile-container">
                <!-- Sidebar Navigation -->
                <aside class="profile-sidebar">
                    <div class="profile-card">
                        <div class="profile-avatar">
                            <div class="avatar-circle<?php echo $profileImagePath !== '' ? ' has-image' : ''; ?>">
                                <?php if ($profileImagePath !== ''): ?>
                                <img src="<?php echo htmlspecialchars($profileImagePath, ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="<?php echo htmlspecialchars($userData['name'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?>">
                                <?php else: ?>
                                <span class="avatar-initial" aria-hidden="true"><?php echo htmlspecialchars($profileImageInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="profile.php" enctype="multipart/form-data" id="profilePhotoForm" class="profile-photo-form">
                                <input type="hidden" name="upload_profile_image" value="1">
                                <input type="file" name="profile_image" id="profileImageInput"
                                    accept="image/jpeg,image/png,image/webp" hidden>
                                <button type="button" class="change-photo-btn" id="changePhotoBtn">Change Photo</button>
                            </form>
                            <?php if ($photoMessage !== ''): ?>
                            <p class="profile-photo-feedback <?php echo $photoMessageType === 'error' ? 'error' : 'success'; ?>">
                                <?php echo htmlspecialchars($photoMessage); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <h2 class="profile-name"><?php echo htmlspecialchars($userData['name'] ?? 'User'); ?></h2>
                        <p class="profile-email"><?php echo htmlspecialchars($userData['email'] ?? ''); ?></p>
                    </div>

                    <nav class="profile-nav">
                        <a href="#personal-info" class="nav-item active" data-tab="personal-info">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
                                <circle cx="12" cy="8" r="3" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M5 20c1.5-3 4.5-5 7-5s5.5 2 7 5" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <span>Personal Information</span>
                        </a>
                        <a href="#addresses" class="nav-item" data-tab="addresses">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <span>Addresses</span>
                        </a>
                        <a href="#orders" class="nav-item" data-tab="orders">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
                                <path d="M6 6h15l-1.5 9h-12zM6 6L4 2H2" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="9" cy="21" r="1" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="20" cy="21" r="1" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <span>Orders</span>
                        </a>
                        <a href="#security" class="nav-item" data-tab="security">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
                                <rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <span>Security</span>
                        </a>
                        <a href="#preferences" class="nav-item" data-tab="preferences">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M12 1v3m0 16v3M5.64 5.64l2.12 2.12m9.48 9.48l2.12 2.12M1 12h3m16 0h3M5.64 18.36l2.12-2.12m9.48-9.48l2.12-2.12" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <span>Preferences</span>
                        </a>
                    </nav>
                </aside>

                <!-- Main Content Area -->
                <div class="profile-content">
                    <!-- Personal Information Tab -->
                    <div class="tab-content active" id="personal-info">
                        <div class="content-card">
                            <div class="card-header">
                                <h2>Personal Information</h2>
                                <p>Update your personal details and contact information</p>
                            </div>

                            <?php if ($profileMessage !== ''): ?>
                            <div class="profile-feedback <?php echo $profileMessageType === 'error' ? 'error' : 'success'; ?>">
                                <?php echo htmlspecialchars($profileMessage); ?>
                            </div>
                            <?php endif; ?>

                            <form method="post" action="profile.php?tab=personal-info" class="profile-form" id="personalInfoForm">
                                <div class="form-group">
                                    <label for="name">Full Name</label>
                                    <input type="text" id="name" name="name" maxlength="100"
                                        value="<?php echo htmlspecialchars($profileFormDefaults['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="email">Email Address</label>
                                    <input type="email" id="email" name="email" maxlength="100"
                                        value="<?php echo htmlspecialchars($profileFormDefaults['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="phone">Phone Number</label>
                                    <input type="tel" id="phone" name="phone" maxlength="20"
                                        value="<?php echo htmlspecialchars($profileFormDefaults['phone'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div class="form-actions">
                                    <button type="button" class="btn ghost" id="personalInfoCancel">Cancel</button>
                                    <button type="submit" name="save_profile" value="1" class="btn primary">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Addresses Tab -->
                    <div class="tab-content" id="addresses">
                        <div class="content-card">
                            <div class="card-header">
                                <h2>Saved Addresses</h2>
                                <button type="button" class="btn primary" id="addAddressBtn">Add New Address</button>
                            </div>

                            <?php if ($addressMessage !== ''): ?>
                            <div class="profile-feedback <?php echo $addressMessageType === 'error' ? 'error' : 'success'; ?>">
                                <?php echo htmlspecialchars($addressMessage); ?>
                            </div>
                            <?php endif; ?>

                            <form method="post" action="profile.php?tab=addresses" id="addressForm" class="address-form"<?php echo $showAddressForm ? '' : ' style="display:none;"'; ?>>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="address_region">Region *</label>
                                        <select id="address_region" name="region" required>
                                            <?php foreach ($addressRegions as $region): ?>
                                            <option value="<?php echo htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?>"
                                                <?php echo $addressFormDefaults['region'] === $region ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="address_city">City / Township *</label>
                                        <input type="text" id="address_city" name="city_township" required maxlength="100"
                                            value="<?php echo htmlspecialchars($addressFormDefaults['city_township'], ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="address_building">Apartment / building (optional)</label>
                                    <input type="text" id="address_building" name="apartment_building" maxlength="255"
                                        value="<?php echo htmlspecialchars($addressFormDefaults['apartment_building'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="address_street">Street address</label>
                                    <input type="text" id="address_street" name="street_address" maxlength="255"
                                        value="<?php echo htmlspecialchars($addressFormDefaults['street_address'], ENT_QUOTES, 'UTF-8'); ?>"
                                        placeholder="House no., street, ward">
                                </div>
                                <div class="form-actions">
                                    <button type="button" class="btn ghost" id="cancelAddressBtn">Cancel</button>
                                    <button type="submit" name="save_address" value="1" class="btn primary">Save Address</button>
                                </div>
                            </form>

                            <div class="addresses-list">
                                <?php if (!empty($userAddresses)): ?>
                                    <?php foreach ($userAddresses as $addr): ?>
                                    <div class="address-card">
                                        <div class="address-details">
                                            <?php if (!empty($addr['region']) || !empty($addr['city_township'])): ?>
                                            <p><strong>Region:</strong> <?php echo htmlspecialchars((string) ($addr['region'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p><strong>City / Township:</strong> <?php echo htmlspecialchars((string) ($addr['city_township'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <?php if (!empty($addr['apartment_building'])): ?>
                                            <p><strong>Apartment / building:</strong> <?php echo htmlspecialchars((string) $addr['apartment_building'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($addr['street_address'])): ?>
                                            <p><strong>Street:</strong> <?php echo htmlspecialchars((string) $addr['street_address'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <p><?php echo nl2br(htmlspecialchars((string) ($addr['name'] ?? ''))); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="address-actions">
                                            <form method="post" action="profile.php?tab=addresses" class="address-delete-form">
                                                <input type="hidden" name="address_id" value="<?php echo (int) $addr['address_id']; ?>">
                                                <button type="button" class="btn-link danger address-remove-trigger">Remove</button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="address-card address-empty">
                                        <div class="address-details">
                                            <p class="address-empty-text">No address saved yet.</p>
                                        </div>
                                        <div class="address-actions">
                                            <button type="button" class="btn primary" id="addAddressEmptyBtn">Add Address</button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Orders Tab -->
                    <div class="tab-content" id="orders">
                        <div class="content-card">
                            <div class="card-header orders-card-header">
                                <div>
                                    <h2>Order History</h2>
                                    <p>View your past and current orders</p>
                                </div>
                                <?php if ($hasAnyOrders): ?>
                                <form method="get" action="profile.php" class="orders-search-form">
                                    <input type="hidden" name="tab" value="orders">
                                    <label class="sr-only" for="orderSearchInput">Search by order number</label>
                                    <input
                                        type="search"
                                        id="orderSearchInput"
                                        name="order_q"
                                        placeholder="e.g. GO00001 or GP00002"
                                        value="<?php echo htmlspecialchars($orderSearchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                                        autocomplete="off"
                                    >
                                    <button type="submit" class="btn primary small">Search</button>
                                    <?php if ($orderSearchQuery !== ''): ?>
                                    <a href="profile.php?tab=orders" class="btn ghost small">Clear</a>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>
                            </div>

                            <div class="orders-list">
                                <?php if (!$hasAnyOrders): ?>
                                <div class="orders-empty">
                                    <p>You have not placed any orders yet.</p>
                                    <a class="btn primary small" href="user/index.php">Start Shopping</a>
                                </div>
                                <?php elseif (empty($userOrders)): ?>
                                <div class="orders-empty">
                                    <p>No orders found for &ldquo;<?php echo htmlspecialchars($orderSearchQuery, ENT_QUOTES, 'UTF-8'); ?>&rdquo;.</p>
                                    <a class="btn ghost small" href="profile.php?tab=orders">Show all orders</a>
                                </div>
                                <?php else: ?>
                                <?php foreach ($userOrders as $order): ?>
                                <?php
                                $orderId = (int) ($order['order_id'] ?? 0);
                                $orderStatus = (string) ($order['status'] ?? 'Pending');
                                $statusClass = profile_order_status_class($orderStatus);
                                $orderItems = $order['items'] ?? [];
                                $firstProductId = !empty($orderItems) ? (int) ($orderItems[0]['product_id'] ?? 0) : 0;
                                $isDelivered = $orderStatus === 'Delivered';
                                $showTrackOrder = in_array($orderStatus, ['Pending', 'Confirmed', 'Shipped', 'Delivered', 'Returned'], true);
                                $trackingMessage = profile_order_tracking_message($orderStatus);
                                $displayOrderNumber = checkout_display_order_number($order);
                                $detailsOpen = $selectedOrderId > 0 && $selectedOrderId === $orderId;
                                $shippingFee = (int) ($order['shipping'] ?? 0);
                                ?>
                                <article class="order-card" data-order-id="<?php echo $orderId; ?>" data-order-number="<?php echo htmlspecialchars(checkout_display_order_number($order), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="order-header">
                                        <div>
                                            <h3>Order <?php echo htmlspecialchars(checkout_display_order_number($order), ENT_QUOTES, 'UTF-8'); ?></h3>
                                            <p class="order-date">Placed on <?php echo htmlspecialchars(profile_format_order_date($order['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <span class="order-status <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <div class="order-items">
                                        <?php foreach ($orderItems as $item): ?>
                                        <?php
                                        $qty = (int) ($item['qty'] ?? 0);
                                        $unitPrice = (int) ($item['unit_price'] ?? 0);
                                        $size = trim((string) ($item['size'] ?? ''));
                                        $color = trim((string) ($item['color'] ?? ''));
                                        $variantParts = [];
                                        if ($size !== '') {
                                            $variantParts[] = 'Size: ' . $size;
                                        }
                                        if ($color !== '') {
                                            $variantParts[] = 'Color: ' . $color;
                                        }
                                        $isPreorderItem = (int) ($item['stock_qty'] ?? 0) <= 0 && (int) ($item['preorder_flag'] ?? 0) === 1;
                                        ?>
                                        <div class="order-item">
                                            <img src="<?php echo htmlspecialchars(profile_order_img_url($item['image_path'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                                alt="<?php echo htmlspecialchars((string) ($item['title'] ?? 'Product'), ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                                            <div class="order-item-info">
                                                <h4><?php echo htmlspecialchars((string) ($item['title'] ?? 'Product'), ENT_QUOTES, 'UTF-8'); ?></h4>
                                                <?php if (!empty($variantParts)): ?>
                                                <p><?php echo htmlspecialchars(implode(' • ', $variantParts), ENT_QUOTES, 'UTF-8'); ?></p>
                                                <?php endif; ?>
                                                <?php if ($isPreorderItem): ?>
                                                <p class="order-item-preorder">Preorder item</p>
                                                <?php endif; ?>
                                                <p class="order-item-price">MMK <?php echo number_format($unitPrice); ?></p>
                                            </div>
                                            <div class="order-item-qty">Qty: <?php echo $qty; ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="order-details-panel" id="order-details-<?php echo $orderId; ?>" <?php echo $detailsOpen ? '' : 'hidden'; ?>>
                                        <div class="order-details-grid">
                                            <div>
                                                <span class="order-details-label">Payment</span>
                                                <span><?php echo htmlspecialchars((string) ($order['payment_method'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <div>
                                                <span class="order-details-label">Shipping</span>
                                                <span><?php echo $shippingFee > 0 ? 'MMK ' . number_format($shippingFee) : 'Free'; ?></span>
                                            </div>
                                            <?php if (!empty($order['shipping_address'])): ?>
                                            <div class="order-details-full">
                                                <span class="order-details-label">Delivery address</span>
                                                <pre class="order-details-address"><?php echo htmlspecialchars((string) $order['shipping_address'], ENT_QUOTES, 'UTF-8'); ?></pre>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="order-footer">
                                        <div class="order-total">
                                            <span>Total:</span>
                                            <strong>MMK <?php echo number_format((int) ($order['total'] ?? 0)); ?></strong>
                                        </div>
                                        <div class="order-actions">
                                            <button type="button" class="btn ghost small order-toggle-details" data-order-details="<?php echo $orderId; ?>" aria-expanded="<?php echo $detailsOpen ? 'true' : 'false'; ?>">
                                                View Details
                                            </button>
                                            <?php if ($isDelivered && $firstProductId > 0): ?>
                                            <a class="btn primary small" href="user/product.php?id=<?php echo $firstProductId; ?>">Reorder</a>
                                            <?php elseif ($showTrackOrder): ?>
                                            <button type="button" class="btn ghost small order-track-trigger"
                                                data-order-number="<?php echo htmlspecialchars($displayOrderNumber, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status-class="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-tracking-message="<?php echo htmlspecialchars($trackingMessage, ENT_QUOTES, 'UTF-8'); ?>">
                                                Track Order
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Security Tab -->
                    <div class="tab-content" id="security">
                        <div class="content-card">
                            <div class="card-header">
                                <h2>Change Password</h2>
                                <p>Update your password to keep your account secure</p>
                            </div>

                            <?php if ($securityMessage !== ''): ?>
                            <div class="profile-feedback <?php echo $securityMessageType === 'error' ? 'error' : 'success'; ?>">
                                <?php echo htmlspecialchars($securityMessage); ?>
                            </div>
                            <?php endif; ?>

                            <form method="post" action="profile.php?tab=security" class="profile-form" id="securityPasswordForm">
                                <div class="form-group">
                                    <label for="currentPassword">Current Password</label>
                                    <input type="password" id="currentPassword" name="current_password" required autocomplete="current-password">
                                </div>

                                <div class="form-group">
                                    <label for="newPassword">New Password</label>
                                    <input type="password" id="newPassword" name="new_password" required minlength="8" autocomplete="new-password">
                                    <small class="form-hint">Must be at least 8 characters long</small>
                                </div>

                                <div class="form-group">
                                    <label for="confirmPassword">Confirm New Password</label>
                                    <input type="password" id="confirmPassword" name="confirm_password" required minlength="8" autocomplete="new-password">
                                </div>

                                <div class="form-actions">
                                    <button type="submit" name="change_password" value="1" class="btn primary">Update Password</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Preferences Tab -->
                    <div class="tab-content" id="preferences">
                        <div class="content-card">
                            <div class="card-header">
                                <h2>Notifications</h2>
                                <p>Manage how you receive notifications</p>
                            </div>

                            <?php if ($preferencesMessage !== ''): ?>
                            <div class="profile-feedback <?php echo $preferencesMessageType === 'error' ? 'error' : 'success'; ?>">
                                <?php echo htmlspecialchars($preferencesMessage); ?>
                            </div>
                            <?php endif; ?>

                            <form method="post" action="profile.php?tab=preferences" id="preferencesForm">
                                <div class="preference-setting">
                                    <div class="setting-info">
                                        <h3>Email Notifications</h3>
                                        <p>Receive updates about orders and promotions via email</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="email_notifications" value="1"
                                            <?php echo !empty($userPreferences['email_notifications']) ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="preference-setting">
                                    <div class="setting-info">
                                        <h3>SMS Notifications</h3>
                                        <p>Receive order updates via SMS</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="sms_notifications" value="1"
                                            <?php echo !empty($userPreferences['sms_notifications']) ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="preference-setting">
                                    <div class="setting-info">
                                        <h3>Marketing Emails</h3>
                                        <p>Receive promotional emails and special offers</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="marketing_emails" value="1"
                                            <?php echo !empty($userPreferences['marketing_emails']) ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" name="save_preferences" value="1" class="btn primary">Save Preferences</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php include 'include/footer.php'; ?>
    </div>

    <div class="profile-confirm-backdrop" id="addressRemoveModal" hidden role="dialog" aria-modal="true" aria-labelledby="addressRemoveModalTitle">
        <div class="profile-confirm-dialog">
            <button type="button" class="profile-confirm-close" id="addressRemoveClose" aria-label="Close">&times;</button>
            <div class="profile-confirm-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
                    <path d="M3 6h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M8 6V4h8v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M19 6l-1 14H6L5 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </div>
            <h3 id="addressRemoveModalTitle">Remove this address?</h3>
            <p class="profile-confirm-message">This address will be permanently removed from your saved addresses.</p>
            <div class="profile-confirm-preview" id="addressRemovePreview" hidden></div>
            <div class="profile-confirm-actions">
                <button type="button" class="btn ghost" id="addressRemoveCancel">Cancel</button>
                <button type="button" class="btn primary profile-confirm-danger" id="addressRemoveConfirm">Remove Address</button>
            </div>
        </div>
    </div>

    <div class="profile-confirm-backdrop" id="orderTrackModal" hidden role="dialog" aria-modal="true" aria-labelledby="orderTrackModalTitle">
        <div class="profile-confirm-dialog profile-track-dialog">
            <button type="button" class="profile-confirm-close" id="orderTrackClose" aria-label="Close">&times;</button>
            <div class="profile-track-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
                    <path d="M1 6h15v9H1z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M16 9h4l3 3v3h-7V9z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <circle cx="5.5" cy="18.5" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                    <circle cx="18.5" cy="18.5" r="2.5" stroke="currentColor" stroke-width="1.8"/>
                </svg>
            </div>
            <h3 id="orderTrackModalTitle">Track Order</h3>
            <p class="profile-track-order-number" id="orderTrackNumber"></p>
            <span class="order-status profile-track-status" id="orderTrackStatusBadge"></span>
            <div class="profile-track-message-box" id="orderTrackMessage" role="status"></div>
            <div class="profile-confirm-actions">
                <button type="button" class="btn primary" id="orderTrackDone">Got it</button>
            </div>
        </div>
    </div>

    <style>
        /* Profile Page Styles */
        .profile-page {
            margin: 24px 0;
        }

        .profile-header {
            margin-bottom: 24px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .header-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 36px;
            padding: 0 14px;
            border: 1px solid transparent;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            line-height: 1;
            font-family: inherit;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-sizing: border-box;
            white-space: nowrap;
        }

        .header-action-btn svg {
            flex-shrink: 0;
        }

        .admin-dashboard-btn {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .admin-dashboard-btn:hover {
            background: #4a5a60;
            border-color: #4a5a60;
            color: #fff;
        }

        .admin-dashboard-btn:active {
            background: #0f2f38;
            border-color: #0f2f38;
        }

        .header-logout {
            background: #fff;
            border-color: var(--glass-border);
            color: var(--text);
        }

        .header-logout:hover {
            background: #dc2626;
            border-color: #dc2626;
            color: #fff;
        }

        .header-logout:active {
            background: #b91c1c;
            border-color: #b91c1c;
            color: #fff;
        }

        .profile-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: var(--text);
        }

        .profile-subtitle {
            color: var(--muted);
            font-size: 14px;
            margin: 0;
        }

        .profile-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* Sidebar */
        .profile-sidebar {
            position: sticky;
            top: 20px;
        }

        .profile-card {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 16px;
            text-align: center;
        }

        .profile-avatar {
            margin-bottom: 16px;
        }

        .avatar-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            overflow: hidden;
        }

        .avatar-circle.has-image {
            background: var(--bg);
            border: 1px solid var(--glass-border);
        }

        .avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .avatar-initial {
            font-size: 36px;
            font-weight: 600;
            line-height: 1;
        }

        .profile-photo-form {
            margin: 0;
        }

        .profile-photo-feedback {
            margin: 10px 0 0;
            font-size: 12px;
            line-height: 1.4;
        }

        .profile-photo-feedback.success {
            color: #0f766e;
        }

        .profile-photo-feedback.error {
            color: #b91c1c;
        }

        .change-photo-btn {
            background: transparent;
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 12px;
            color: var(--text);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .change-photo-btn:hover {
            background: var(--accent-2);
            border-color: var(--muted);
        }

        .profile-name {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 4px 0;
            color: var(--text);
        }

        .profile-email {
            font-size: 13px;
            color: var(--muted);
            margin: 0;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-top: 16px;
            padding: 10px 16px;
            background: transparent;
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            color: var(--text);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .logout-btn:hover {
            background: #dc2626;
            border-color: #dc2626;
            color: white;
        }

        .logout-btn svg {
            flex-shrink: 0;
        }

        .profile-nav {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 6px;
            color: var(--text);
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 14px;
        }

        .nav-item:hover {
            background: var(--accent-2);
        }

        .nav-item.active {
            background: var(--accent);
            color: white;
        }

        .nav-item svg {
            flex-shrink: 0;
        }

        /* Content Area */
        .profile-content {
            min-height: 600px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .content-card {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 24px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--glass-border);
        }

        .card-header h2 {
            font-size: 20px;
            font-weight: 600;
            margin: 0 0 4px 0;
            color: var(--text);
        }

        .card-header p {
            font-size: 14px;
            color: var(--muted);
            margin: 0;
        }

        .orders-card-header {
            flex-wrap: wrap;
            gap: 16px;
        }

        .orders-search-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin: 0;
        }

        .orders-search-form input[type="search"] {
            min-width: 220px;
            padding: 8px 12px;
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            color: var(--text);
        }

        .orders-search-form input[type="search"]:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(19, 63, 76, 0.12);
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* Forms */
        .profile-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px 12px;
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            font-size: 14px;
            background: white;
            color: var(--text);
            transition: border-color 0.2s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
            line-height: 1.5;
        }

        .profile-feedback {
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .profile-feedback.success {
            background: #e8f4ea;
            border: 1px solid #b4d8b8;
            color: #245d2b;
        }

        .profile-feedback.error {
            background: #fbeaea;
            border: 1px solid #e4b5b5;
            color: #7f2020;
        }

        .address-form {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--glass-border);
        }

        .address-empty-text {
            color: var(--muted);
            font-style: italic;
            margin: 0;
        }

        .btn-link.danger {
            color: #9b2226;
        }

        .btn-link.danger:hover {
            color: #7f2020;
        }

        .address-delete-form {
            margin: 0;
        }

        .profile-confirm-backdrop {
            position: fixed;
            inset: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, 0.45);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .profile-confirm-backdrop.is-open {
            opacity: 1;
            visibility: visible;
        }

        .profile-confirm-backdrop[hidden] {
            display: none !important;
        }

        .profile-confirm-backdrop.is-open[hidden] {
            display: flex !important;
        }

        .profile-confirm-dialog {
            position: relative;
            width: min(100%, 420px);
            background: #fff;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.18);
            padding: 28px 24px 24px;
            text-align: center;
            transform: translateY(12px) scale(0.98);
            transition: transform 0.2s ease;
        }

        .profile-confirm-backdrop.is-open .profile-confirm-dialog {
            transform: translateY(0) scale(1);
        }

        .profile-confirm-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--muted);
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .profile-confirm-close:hover {
            background: var(--accent-2);
            color: var(--text);
        }

        .profile-confirm-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(155, 34, 38, 0.1);
            color: #9b2226;
        }

        .profile-confirm-dialog h3 {
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
        }

        .profile-confirm-message {
            margin: 0 0 16px;
            font-size: 14px;
            line-height: 1.5;
            color: var(--muted);
        }

        .profile-confirm-preview {
            margin: 0 0 20px;
            padding: 12px 14px;
            border-radius: 8px;
            background: var(--bg);
            border: 1px solid var(--glass-border);
            text-align: left;
            font-size: 13px;
            line-height: 1.5;
            color: var(--text);
        }

        .profile-confirm-preview p {
            margin: 0 0 4px;
        }

        .profile-confirm-preview p:last-child {
            margin-bottom: 0;
        }

        .profile-confirm-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .profile-confirm-actions .btn {
            min-width: 130px;
            justify-content: center;
        }

        .profile-confirm-danger {
            background: #9b2226;
            border-color: #9b2226;
        }

        .profile-confirm-danger:hover {
            background: #7f2020;
            border-color: #7f2020;
            opacity: 1;
        }

        .profile-track-dialog {
            text-align: center;
        }

        .profile-track-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--accent-2);
            color: var(--accent);
        }

        .profile-track-order-number {
            margin: 0 0 10px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .profile-track-status {
            display: inline-block;
            margin-bottom: 16px;
        }

        .profile-track-message-box {
            margin: 0 0 20px;
            padding: 14px 16px;
            border-radius: 8px;
            background: var(--bg);
            border: 1px solid var(--glass-border);
            font-size: 14px;
            line-height: 1.6;
            color: var(--text);
            text-align: left;
        }

        .form-hint {
            font-size: 12px;
            color: var(--muted);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 8px;
        }

        /* Addresses */
        .addresses-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .address-card {
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            padding: 16px;
            background: white;
        }

        .address-details {
            margin-bottom: 12px;
        }

        .address-details p {
            font-size: 14px;
            color: var(--text);
            margin: 4px 0;
        }

        .address-phone {
            color: var(--muted);
            font-size: 13px;
        }

        .address-actions {
            display: flex;
            gap: 16px;
            padding-top: 12px;
            border-top: 1px solid var(--glass-border);
        }

        .btn-link {
            background: none;
            border: none;
            color: var(--accent);
            font-size: 14px;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }

        .btn-link:hover {
            color: var(--text);
        }

        /* Orders */
        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .order-card {
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            padding: 16px;
            background: white;
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--glass-border);
        }

        .order-header h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 4px 0;
            color: var(--text);
        }

        .order-date {
            font-size: 13px;
            color: var(--muted);
            margin: 0;
        }

        .order-status {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .order-status.delivered {
            background: #d1fae5;
            color: #065f46;
        }

        .order-status.processing {
            background: #dbeafe;
            color: #1e40af;
        }

        .order-status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .order-status.returned,
        .order-status.refunded {
            background: #fee2e2;
            color: #991b1b;
        }

        .orders-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }

        .orders-empty p {
            margin: 0 0 16px;
        }

        .order-item-preorder {
            font-size: 12px;
            color: var(--accent);
            font-weight: 600;
            margin: 0;
        }

        .order-item + .order-item {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--glass-border);
        }

        .order-details-panel {
            margin-bottom: 16px;
            padding: 12px;
            background: var(--bg);
            border-radius: 6px;
            border: 1px solid var(--glass-border);
        }

        .order-details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            font-size: 13px;
            color: var(--text);
        }

        .order-details-full {
            grid-column: 1 / -1;
        }

        .order-details-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .order-details-address {
            margin: 0;
            white-space: pre-wrap;
            font-family: inherit;
            font-size: 13px;
            line-height: 1.5;
            color: var(--text);
        }

        .order-items {
            margin-bottom: 16px;
        }

        .order-item {
            display: grid;
            grid-template-columns: 60px 1fr auto;
            gap: 12px;
            align-items: start;
        }

        .order-item img {
            width: 60px;
            height: 60px;
            border-radius: 6px;
            object-fit: cover;
        }

        .order-item-info h4 {
            font-size: 14px;
            font-weight: 600;
            margin: 0 0 4px 0;
            color: var(--text);
        }

        .order-item-info p {
            font-size: 13px;
            color: var(--muted);
            margin: 2px 0;
        }

        .order-item-price {
            font-weight: 600;
            color: var(--accent);
        }

        .order-item-qty {
            font-size: 13px;
            color: var(--muted);
        }

        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid var(--glass-border);
        }

        .order-total {
            font-size: 16px;
            color: var(--text);
        }

        .order-total strong {
            color: var(--accent);
            font-size: 18px;
        }

        .order-actions {
            display: flex;
            gap: 8px;
        }

        /* Preferences */
        .preference-setting {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid var(--glass-border);
        }

        .preference-setting:last-child {
            border-bottom: none;
        }

        .setting-info h3 {
            font-size: 15px;
            font-weight: 600;
            margin: 0 0 4px 0;
            color: var(--text);
        }

        .setting-info p {
            font-size: 13px;
            color: var(--muted);
            margin: 0;
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.3s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
        }

        .toggle-switch input:checked + .toggle-slider {
            background-color: var(--accent);
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        /* Mobile Responsive */
        @media (max-width: 900px) {
            .profile-container {
                grid-template-columns: 1fr;
            }

            .profile-sidebar {
                position: static;
            }

            .profile-nav {
                flex-direction: row;
                overflow-x: auto;
                padding: 8px;
            }

            .nav-item {
                white-space: nowrap;
                flex-shrink: 0;
            }

            .nav-item span {
                display: none;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .header-actions {
                align-self: flex-end;
                flex-wrap: wrap;
                justify-content: flex-end;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .card-header {
                flex-direction: column;
                gap: 12px;
            }

            .orders-search-form {
                width: 100%;
            }

            .orders-search-form input[type="search"] {
                flex: 1;
                min-width: 0;
                width: 100%;
            }

            .orders-search-form .btn {
                flex: 1;
            }

            .order-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .order-actions {
                width: 100%;
                flex-direction: column;
            }

            .order-actions .btn {
                width: 100%;
            }

            .profile-confirm-actions {
                flex-direction: column;
            }

            .profile-confirm-actions .btn {
                width: 100%;
                min-width: 0;
            }
        }
    </style>

    <script>
        // Tab Navigation
        document.addEventListener('DOMContentLoaded', function() {
            const navItems = document.querySelectorAll('.nav-item');
            const tabContents = document.querySelectorAll('.tab-content');

            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const targetTab = this.getAttribute('data-tab');
                    
                    // Remove active class from all nav items and tab contents
                    navItems.forEach(nav => nav.classList.remove('active'));
                    tabContents.forEach(tab => tab.classList.remove('active'));
                    
                    // Add active class to clicked nav item and corresponding tab
                    this.classList.add('active');
                    document.getElementById(targetTab).classList.add('active');
                });
            });

            const changePhotoBtn = document.getElementById('changePhotoBtn');
            const profileImageInput = document.getElementById('profileImageInput');
            const profilePhotoForm = document.getElementById('profilePhotoForm');
            if (changePhotoBtn && profileImageInput && profilePhotoForm) {
                changePhotoBtn.addEventListener('click', function() {
                    profileImageInput.click();
                });
                profileImageInput.addEventListener('change', function() {
                    if (!profileImageInput.files || profileImageInput.files.length === 0) {
                        return;
                    }
                    changePhotoBtn.disabled = true;
                    changePhotoBtn.textContent = 'Uploading...';
                    if (typeof profilePhotoForm.requestSubmit === 'function') {
                        profilePhotoForm.requestSubmit();
                    } else {
                        profilePhotoForm.submit();
                    }
                });
            }

            const personalInfoForm = document.getElementById('personalInfoForm');
            const personalInfoCancel = document.getElementById('personalInfoCancel');
            if (personalInfoForm && personalInfoCancel) {
                personalInfoCancel.addEventListener('click', function() {
                    personalInfoForm.querySelectorAll('input').forEach(function(input) {
                        input.value = input.defaultValue;
                    });
                });
            }

            const addressForm = document.getElementById('addressForm');
            const addAddressBtn = document.getElementById('addAddressBtn');
            const addAddressEmptyBtn = document.getElementById('addAddressEmptyBtn');
            const cancelAddressBtn = document.getElementById('cancelAddressBtn');

            function openAddressForm() {
                if (addressForm) {
                    addressForm.style.display = 'block';
                    document.getElementById('address_text')?.focus();
                }
            }

            function closeAddressForm() {
                if (addressForm) {
                    addressForm.style.display = 'none';
                    const textarea = document.getElementById('address_text');
                    if (textarea) textarea.value = '';
                }
            }

            if (addAddressBtn) addAddressBtn.addEventListener('click', openAddressForm);
            if (addAddressEmptyBtn) addAddressEmptyBtn.addEventListener('click', openAddressForm);
            if (cancelAddressBtn) cancelAddressBtn.addEventListener('click', closeAddressForm);

            var addressRemoveModal = document.getElementById('addressRemoveModal');
            var addressRemovePreview = document.getElementById('addressRemovePreview');
            var addressRemoveCancel = document.getElementById('addressRemoveCancel');
            var addressRemoveConfirm = document.getElementById('addressRemoveConfirm');
            var addressRemoveClose = document.getElementById('addressRemoveClose');
            var pendingDeleteForm = null;

            function openAddressRemoveModal(form) {
                if (!addressRemoveModal || !form) return;
                pendingDeleteForm = form;

                if (addressRemovePreview) {
                    var card = form.closest('.address-card');
                    var details = card ? card.querySelector('.address-details') : null;
                    if (details) {
                        addressRemovePreview.innerHTML = details.innerHTML;
                        addressRemovePreview.hidden = false;
                    } else {
                        addressRemovePreview.innerHTML = '';
                        addressRemovePreview.hidden = true;
                    }
                }

                addressRemoveModal.hidden = false;
                requestAnimationFrame(function() {
                    addressRemoveModal.classList.add('is-open');
                });
                document.body.style.overflow = 'hidden';
                if (addressRemoveCancel) addressRemoveCancel.focus();
            }

            function closeAddressRemoveModal() {
                if (!addressRemoveModal) return;
                addressRemoveModal.classList.remove('is-open');
                document.body.style.overflow = '';
                pendingDeleteForm = null;
                window.setTimeout(function() {
                    if (!addressRemoveModal.classList.contains('is-open')) {
                        addressRemoveModal.hidden = true;
                    }
                }, 200);
            }

            function submitAddressRemoval() {
                if (!pendingDeleteForm) return;
                var submitInput = pendingDeleteForm.querySelector('input[name="delete_address"]');
                if (!submitInput) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'delete_address';
                    hidden.value = '1';
                    pendingDeleteForm.appendChild(hidden);
                }
                pendingDeleteForm.submit();
            }

            document.querySelectorAll('.address-remove-trigger').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var form = btn.closest('.address-delete-form');
                    openAddressRemoveModal(form);
                });
            });

            if (addressRemoveCancel) {
                addressRemoveCancel.addEventListener('click', closeAddressRemoveModal);
            }
            if (addressRemoveClose) {
                addressRemoveClose.addEventListener('click', closeAddressRemoveModal);
            }
            if (addressRemoveConfirm) {
                addressRemoveConfirm.addEventListener('click', submitAddressRemoval);
            }
            if (addressRemoveModal) {
                addressRemoveModal.addEventListener('click', function(e) {
                    if (e.target === addressRemoveModal) {
                        closeAddressRemoveModal();
                    }
                });
            }
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape') return;
                if (addressRemoveModal && addressRemoveModal.classList.contains('is-open')) {
                    closeAddressRemoveModal();
                }
                if (orderTrackModal && orderTrackModal.classList.contains('is-open')) {
                    closeOrderTrackModal();
                }
            });

            var orderTrackModal = document.getElementById('orderTrackModal');
            var orderTrackNumber = document.getElementById('orderTrackNumber');
            var orderTrackStatusBadge = document.getElementById('orderTrackStatusBadge');
            var orderTrackMessage = document.getElementById('orderTrackMessage');
            var orderTrackClose = document.getElementById('orderTrackClose');
            var orderTrackDone = document.getElementById('orderTrackDone');

            function openOrderTrackModal(btn) {
                if (!orderTrackModal || !btn) return;

                var orderNumber = btn.getAttribute('data-order-number') || '';
                var orderStatus = btn.getAttribute('data-order-status') || '';
                var statusClass = btn.getAttribute('data-status-class') || 'pending';
                var message = btn.getAttribute('data-tracking-message') || '';

                if (orderTrackNumber) {
                    orderTrackNumber.textContent = 'Order ' + orderNumber;
                }
                if (orderTrackStatusBadge) {
                    orderTrackStatusBadge.textContent = orderStatus;
                    orderTrackStatusBadge.className = 'order-status profile-track-status ' + statusClass;
                }
                if (orderTrackMessage) {
                    orderTrackMessage.textContent = message;
                }

                orderTrackModal.hidden = false;
                requestAnimationFrame(function() {
                    orderTrackModal.classList.add('is-open');
                });
                document.body.style.overflow = 'hidden';
                if (orderTrackDone) orderTrackDone.focus();
            }

            function closeOrderTrackModal() {
                if (!orderTrackModal) return;
                orderTrackModal.classList.remove('is-open');
                document.body.style.overflow = '';
                window.setTimeout(function() {
                    if (!orderTrackModal.classList.contains('is-open')) {
                        orderTrackModal.hidden = true;
                    }
                }, 200);
            }

            document.querySelectorAll('.order-track-trigger').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    openOrderTrackModal(btn);
                });
            });

            if (orderTrackClose) orderTrackClose.addEventListener('click', closeOrderTrackModal);
            if (orderTrackDone) orderTrackDone.addEventListener('click', closeOrderTrackModal);
            if (orderTrackModal) {
                orderTrackModal.addEventListener('click', function(e) {
                    if (e.target === orderTrackModal) {
                        closeOrderTrackModal();
                    }
                });
            }

            document.querySelectorAll('.order-toggle-details').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var orderId = btn.getAttribute('data-order-details');
                    if (!orderId) return;
                    var panel = document.getElementById('order-details-' + orderId);
                    if (!panel) return;
                    var isHidden = panel.hasAttribute('hidden');
                    if (isHidden) {
                        panel.removeAttribute('hidden');
                        btn.setAttribute('aria-expanded', 'true');
                    } else {
                        panel.setAttribute('hidden', '');
                        btn.setAttribute('aria-expanded', 'false');
                    }
                });
            });

            function activateProfileTab(tabName) {
                navItems.forEach(nav => nav.classList.remove('active'));
                tabContents.forEach(tab => tab.classList.remove('active'));
                const nav = document.querySelector('[data-tab="' + tabName + '"]');
                const tab = document.getElementById(tabName);
                if (nav) nav.classList.add('active');
                if (tab) tab.classList.add('active');
            }

            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            if (tabParam === 'personal-info' || tabParam === 'addresses' || tabParam === 'orders' || tabParam === 'security' || tabParam === 'preferences') {
                activateProfileTab(tabParam);
            }
        });
    </script>
</body>
</html>

