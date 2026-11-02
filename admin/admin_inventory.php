<?php
$adminNavActive = 'inventory';
require_once __DIR__ . '/include/admin_auth.php';
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/../user/include/product_images_lib.php';

/**
 * @return string|null Error message or null on success
 */
function inv_save_uploaded_image(PDO $pdo, int $variantId, array $file): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Image upload failed.';
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return 'Image too large (max 5MB).';
    }
    if (!class_exists('finfo')) {
        return 'File info extension is not enabled.';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        return 'Allowed image types: JPEG, PNG, WebP, GIF.';
    }
    $uploadsRoot = __DIR__ . '/../uploads';
    $dir = $uploadsRoot . '/products';
    if (!is_dir($uploadsRoot) && !@mkdir($uploadsRoot, 0775, true) && !is_dir($uploadsRoot)) {
        return 'Could not create uploads folder. Check folder permissions.';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return 'Could not create product images folder. Check folder permissions.';
    }
    if (!is_writable($dir)) {
        @chmod($uploadsRoot, 0777);
        @chmod($dir, 0777);
    }
    if (!is_writable($dir)) {
        return 'Cannot save images: uploads/products is not writable by the web server. Fix folder permissions and try again.';
    }
    $name = 'v' . $variantId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return 'Could not save image file. Check that uploads/products exists and is writable.';
    }
    $relative = 'uploads/products/' . $name;

    $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM images WHERE variant_id = ?');
    $sortStmt->execute([$variantId]);
    $nextSort = (int) $sortStmt->fetchColumn();

    $ins = $pdo->prepare('INSERT INTO images (variant_id, image, sort_order) VALUES (?, ?, ?)');
    $ins->execute([$variantId, $relative, $nextSort]);
    $newId = (int) $pdo->lastInsertId();

    $vStmt = $pdo->prepare('SELECT image_id FROM variants WHERE variant_id = ?');
    $vStmt->execute([$variantId]);
    $primaryId = $vStmt->fetchColumn();
    if ($primaryId === null || $primaryId === false || $primaryId === '') {
        $pdo->prepare('UPDATE variants SET image_id = ? WHERE variant_id = ?')->execute([$newId, $variantId]);
    }

    return null;
}

function inv_file_uploaded(array $file): bool
{
    return isset($file['error'])
        && (int) $file['error'] !== UPLOAD_ERR_NO_FILE
        && trim((string) ($file['name'] ?? '')) !== '';
}

function inv_variant_has_image(PDO $pdo, int $variantId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM images WHERE variant_id = ? LIMIT 1');
    $stmt->execute([$variantId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return array<int, list<array<string, mixed>>>
 */
function inv_variant_uploads_by_index(): array
{
    if (empty($_FILES['variant_image'])) {
        return [];
    }

    $f = $_FILES['variant_image'];
    $out = [];

    if (!is_array($f['name'])) {
        if (inv_file_uploaded($f)) {
            $out[0][] = $f;
        }

        return $out;
    }

    foreach ($f['name'] as $i => $nameOrNames) {
        $variantIndex = (int) $i;
        if (is_array($nameOrNames)) {
            foreach ($nameOrNames as $j => $name) {
                $file = [
                    'name' => $f['name'][$i][$j],
                    'type' => $f['type'][$i][$j],
                    'tmp_name' => $f['tmp_name'][$i][$j],
                    'error' => $f['error'][$i][$j],
                    'size' => $f['size'][$i][$j],
                ];
                if (inv_file_uploaded($file)) {
                    $out[$variantIndex][] = $file;
                }
            }
        } else {
            $file = [
                'name' => $f['name'][$i],
                'type' => $f['type'][$i],
                'tmp_name' => $f['tmp_name'][$i],
                'error' => $f['error'][$i],
                'size' => $f['size'][$i],
            ];
            if (inv_file_uploaded($file)) {
                $out[$variantIndex][] = $file;
            }
        }
    }

    return $out;
}

function inv_variant_index_has_upload(array $uploadsByIndex, int $index): bool
{
    return !empty($uploadsByIndex[$index]);
}

/**
 * @return list<array<string, mixed>>
 */
function inv_collect_upload_files(string $field): array
{
    if (empty($_FILES[$field])) {
        return [];
    }

    $f = $_FILES[$field];
    $files = [];

    if (!is_array($f['name'])) {
        return inv_file_uploaded($f) ? [$f] : [];
    }

    foreach ($f['name'] as $i => $nameOrNames) {
        if (is_array($nameOrNames)) {
            foreach ($nameOrNames as $j => $name) {
                $file = [
                    'name' => $f['name'][$i][$j],
                    'type' => $f['type'][$i][$j],
                    'tmp_name' => $f['tmp_name'][$i][$j],
                    'error' => $f['error'][$i][$j],
                    'size' => $f['size'][$i][$j],
                ];
                if (inv_file_uploaded($file)) {
                    $files[] = $file;
                }
            }
        } else {
            $file = [
                'name' => $f['name'][$i],
                'type' => $f['type'][$i],
                'tmp_name' => $f['tmp_name'][$i],
                'error' => $f['error'][$i],
                'size' => $f['size'][$i],
            ];
            if (inv_file_uploaded($file)) {
                $files[] = $file;
            }
        }
    }

    return $files;
}

function inv_post_first_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) {
        $value = $value[0] ?? $default;
    }

    return trim((string) $value);
}

function inv_post_first_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) {
        return (int) ($value[0] ?? $default);
    }

    return (int) $value;
}

function inv_post_has_preorder(): bool
{
    if (!isset($_POST['variant_preorder'])) {
        return false;
    }
    $value = $_POST['variant_preorder'];
    if (is_array($value)) {
        return isset($value[0]) && (string) $value[0] === '1';
    }

    return (string) $value === '1';
}

function inv_brand_category_names(PDO $pdo, int $brandId, int $subCategoryId): array
{
    $brandName = '';
    $categoryName = '';
    $subName = '';

    $b = $pdo->prepare('SELECT name FROM brands WHERE brand_id = ?');
    $b->execute([$brandId]);
    $brandName = (string) ($b->fetchColumn() ?: '');

    $s = $pdo->prepare('
        SELECT sc.name AS sub_name, c.category_name
        FROM sub_categories sc
        JOIN categories c ON sc.category_id = c.category_id
        WHERE sc.sub_category_id = ?
    ');
    $s->execute([$subCategoryId]);
    $row = $s->fetch();
    if ($row) {
        $subName = $row['sub_name'];
        $categoryName = $row['category_name'];
    }

    return [$brandName, $categoryName, $subName];
}

function inv_modal_return_url(int $editId = 0, array $query = []): string
{
    if ($editId > 0) {
        $query['edit'] = $editId;
    } else {
        $query['add'] = '1';
    }

    return 'admin_inventory.php?' . http_build_query($query);
}

function inv_post_sizes_are_nested(): bool
{
    $sizes = $_POST['variant_size'] ?? null;
    if (!is_array($sizes)) {
        return false;
    }
    foreach ($sizes as $value) {
        if (is_array($value)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<array{size: ?string, color: ?string, stock: int, preorder: int, color_index: int}>
 */
function inv_parse_variant_rows_from_post(): array
{
    $colors = $_POST['variant_color'] ?? [];

    if (is_array($colors) && inv_post_sizes_are_nested()) {
        $rows = [];
        foreach ($colors as $colorIndex => $color) {
            $color = trim((string) $color);
            $sizes = $_POST['variant_size'][$colorIndex] ?? [];
            $stocks = $_POST['variant_stock_qty'][$colorIndex] ?? [];
            $preorders = $_POST['variant_preorder'][$colorIndex] ?? [];

            if (!is_array($sizes)) {
                $sizes = [trim((string) $sizes)];
            }
            if (!is_array($stocks)) {
                $stocks = [(int) $stocks];
            }
            if (!is_array($preorders)) {
                $preorders = [];
            }

            $sizeCount = max(count($sizes), 1);
            for ($j = 0; $j < $sizeCount; $j++) {
                $size = trim((string) ($sizes[$j] ?? ''));
                if ($size === '' && $j > 0) {
                    continue;
                }
                $rows[] = [
                    'size' => $size === '' ? null : $size,
                    'color' => $color === '' ? null : $color,
                    'stock' => (int) ($stocks[$j] ?? 0),
                    'preorder' => isset($preorders[$j]) && (string) $preorders[$j] === '1' ? 1 : 0,
                    'color_index' => (int) $colorIndex,
                ];
            }
        }

        return $rows;
    }

    $sizes = $_POST['variant_size'] ?? [];
    $stocks = $_POST['variant_stock_qty'] ?? [];
    $preorders = $_POST['variant_preorder'] ?? [];

    if (!is_array($sizes)) {
        $sizes = [trim((string) $sizes)];
    }
    if (!is_array($colors)) {
        $colors = [trim((string) $colors)];
    }
    if (!is_array($stocks)) {
        $stocks = [(int) $stocks];
    }
    if (!is_array($preorders)) {
        $preorders = $preorders ? ['1'] : [];
    }

    $count = max(count($sizes), count($colors), count($stocks), 1);
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $size = trim((string) ($sizes[$i] ?? ''));
        $color = trim((string) ($colors[$i] ?? ''));
        $rows[] = [
            'size' => $size === '' ? null : $size,
            'color' => $color === '' ? null : $color,
            'stock' => (int) ($stocks[$i] ?? 0),
            'preorder' => isset($preorders[$i]) && (string) $preorders[$i] === '1' ? 1 : 0,
            'color_index' => $i,
        ];
    }

    return $rows;
}

function inv_product_color_has_images(PDO $pdo, int $productId, ?string $color): bool
{
    if ($color === null || $color === '') {
        $stmt = $pdo->prepare('
            SELECT 1 FROM images i
            INNER JOIN variants v ON i.variant_id = v.variant_id
            WHERE v.product_id = ? AND (v.color IS NULL OR v.color = "")
            LIMIT 1
        ');
        $stmt->execute([$productId]);
    } else {
        $stmt = $pdo->prepare('
            SELECT 1 FROM images i
            INNER JOIN variants v ON i.variant_id = v.variant_id
            WHERE v.product_id = ? AND v.color = ?
            LIMIT 1
        ');
        $stmt->execute([$productId, $color]);
    }

    return (bool) $stmt->fetchColumn();
}

/**
 * @param list<array{color_index: int}> $variantRows
 */
function inv_validate_color_group_uploads(array $variantRows): ?string
{
    if (empty($variantRows)) {
        return 'Add at least one size for a color.';
    }

    $uploadsByIndex = inv_variant_uploads_by_index();
    $colorIndexes = [];
    foreach ($variantRows as $row) {
        $colorIndexes[$row['color_index']] = true;
    }

    foreach (array_keys($colorIndexes) as $colorIndex) {
        if (!inv_variant_index_has_upload($uploadsByIndex, (int) $colorIndex)) {
            return 'Each color must have at least one image.';
        }
    }

    return null;
}

$message = '';
$messageType = 'success';
$showForm = isset($_GET['add']) || isset($_GET['edit']);
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_brand'])) {
        $returnEdit = (int) ($_POST['return_edit'] ?? 0);
        $showForm = true;
        $editId = $returnEdit;
        $name = trim($_POST['new_brand_name'] ?? '');

        if ($name === '') {
            $message = 'Enter a brand name.';
            $messageType = 'error';
        } else {
            try {
                $pdo->prepare('INSERT INTO brands (name) VALUES (?)')->execute([$name]);
                $newId = (int) $pdo->lastInsertId();
                header('Location: ' . inv_modal_return_url($returnEdit, [
                    'brand_id' => $newId,
                    'msg' => 'brand_added',
                ]));
                exit;
            } catch (PDOException $e) {
                $existing = $pdo->prepare('SELECT brand_id FROM brands WHERE name = ?');
                $existing->execute([$name]);
                $existingId = (int) $existing->fetchColumn();
                if ($existingId > 0) {
                    header('Location: ' . inv_modal_return_url($returnEdit, [
                        'brand_id' => $existingId,
                        'msg' => 'brand_added',
                    ]));
                    exit;
                }
                error_log('Save brand failed: ' . $e->getMessage());
                $message = 'Could not save brand.';
                $messageType = 'error';
            }
        }
    }

    if (isset($_POST['save_sub_category'])) {
        $returnEdit = (int) ($_POST['return_edit'] ?? 0);
        $showForm = true;
        $editId = $returnEdit;
        $subName = trim($_POST['new_sub_name'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $newCategoryName = trim($_POST['new_category_name'] ?? '');

        if ($subName === '') {
            $message = 'Enter a sub-category name.';
            $messageType = 'error';
        } elseif ($categoryId <= 0 && $newCategoryName === '') {
            $message = 'Select a category or enter a new category name.';
            $messageType = 'error';
        } else {
            try {
                if ($categoryId <= 0) {
                    $findCat = $pdo->prepare('SELECT category_id FROM categories WHERE category_name = ?');
                    $findCat->execute([$newCategoryName]);
                    $categoryId = (int) $findCat->fetchColumn();
                    if ($categoryId <= 0) {
                        $pdo->prepare('INSERT INTO categories (category_name) VALUES (?)')->execute([$newCategoryName]);
                        $categoryId = (int) $pdo->lastInsertId();
                    }
                }

                $pdo->prepare('INSERT INTO sub_categories (category_id, name) VALUES (?, ?)')->execute([
                    $categoryId,
                    $subName,
                ]);
                $newSubId = (int) $pdo->lastInsertId();
                header('Location: ' . inv_modal_return_url($returnEdit, [
                    'sub_category_id' => $newSubId,
                    'msg' => 'sub_added',
                ]));
                exit;
            } catch (PDOException $e) {
                error_log('Save sub-category failed: ' . $e->getMessage());
                $message = 'Could not save sub-category.';
                $messageType = 'error';
            }
        }
    }

    if (isset($_POST['delete_id'])) {
        $deleteId = (int) $_POST['delete_id'];
        if ($deleteId > 0) {
            try {
                $paths = $pdo->prepare('
                    SELECT i.image FROM images i
                    INNER JOIN variants v ON i.variant_id = v.variant_id
                    WHERE v.product_id = ?
                ');
                $paths->execute([$deleteId]);
                foreach ($paths->fetchAll() as $row) {
                    $abs = __DIR__ . '/../' . $row['image'];
                    if (is_file($abs)) {
                        @unlink($abs);
                    }
                }
                $pdo->prepare('DELETE FROM products WHERE product_id = ?')->execute([$deleteId]);
                header('Location: admin_inventory.php?msg=deleted');
                exit;
            } catch (PDOException $e) {
                error_log('Delete product failed: ' . $e->getMessage());
                $message = 'Could not delete product.';
                $messageType = 'error';
            }
        }
    }

    if (isset($_POST['save_variant'])) {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $size = inv_post_first_string('variant_size');
        $color = inv_post_first_string('variant_color');
        $stockQty = (int) ($_POST['variant_stock_qty'] ?? 0);
        $preorder = isset($_POST['variant_preorder']) ? 1 : 0;

        $uploadFiles = inv_collect_upload_files('variant_image');
        $colorForImage = $color === '' ? null : $color;
        $colorAlreadyHasImages = inv_product_color_has_images($pdo, $productId, $colorForImage);

        if ($productId <= 0 || $stockQty < 0) {
            $message = 'Invalid variant data.';
            $messageType = 'error';
            $showForm = true;
            $editId = $productId;
        } elseif ($variantId <= 0 && empty($uploadFiles) && !$colorAlreadyHasImages) {
            $message = 'Add at least one image for this color (or pick a color that already has photos).';
            $messageType = 'error';
            $showForm = true;
            $editId = $productId;
        } else {
            try {
                $pCheck = $pdo->prepare('SELECT 1 FROM products WHERE product_id = ?');
                $pCheck->execute([$productId]);
                if (!$pCheck->fetch()) {
                    throw new RuntimeException('Product not found');
                }

                $isNewVariant = $variantId <= 0;

                if ($variantId > 0) {
                    $u = $pdo->prepare('
                        UPDATE variants SET size = ?, color = ?, stock_qty = ?, preorder_flag = ?
                        WHERE variant_id = ? AND product_id = ?
                    ');
                    $u->execute([
                        $size === '' ? null : $size,
                        $color === '' ? null : $color,
                        $stockQty,
                        $preorder,
                        $variantId,
                        $productId,
                    ]);
                } else {
                    $pdo->prepare('
                        INSERT INTO variants (product_id, size, color, stock_qty, preorder_flag)
                        VALUES (?, ?, ?, ?, ?)
                    ')->execute([
                        $productId,
                        $size === '' ? null : $size,
                        $color === '' ? null : $color,
                        $stockQty,
                        $preorder,
                    ]);
                    $variantId = (int) $pdo->lastInsertId();
                }

                if (!empty($uploadFiles)) {
                    foreach ($uploadFiles as $file) {
                        $imgErr = inv_save_uploaded_image($pdo, $variantId, $file);
                        if ($imgErr) {
                            if ($isNewVariant) {
                                $pdo->prepare('DELETE FROM variants WHERE variant_id = ? AND product_id = ?')
                                    ->execute([$variantId, $productId]);
                            }
                            header('Location: admin_inventory.php?edit=' . $productId . '&msg=' . rawurlencode($imgErr) . '&err=1');
                            exit;
                        }
                    }
                } elseif (!inv_variant_has_image($pdo, $variantId) && !$colorAlreadyHasImages) {
                    if ($isNewVariant) {
                        $pdo->prepare('DELETE FROM variants WHERE variant_id = ? AND product_id = ?')
                            ->execute([$variantId, $productId]);
                    }
                    $message = 'Add at least one image for this color.';
                    $messageType = 'error';
                    $showForm = true;
                    $editId = $productId;
                    throw new RuntimeException('variant_image_required');
                }

                header('Location: admin_inventory.php?edit=' . $productId . '&msg=variant_saved');
                exit;
            } catch (Throwable $e) {
                if ($e->getMessage() !== 'variant_image_required') {
                    error_log('Save variant failed: ' . $e->getMessage());
                    $message = 'Could not save variant.';
                    $messageType = 'error';
                    $showForm = true;
                    $editId = $productId;
                }
            }
        }
    }

    if (isset($_POST['save_variant_group'])) {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $variantRows = inv_parse_variant_rows_from_post();
        $groupError = null;

        if ($productId <= 0) {
            $groupError = 'Invalid product.';
        } elseif (empty($variantRows)) {
            $groupError = 'Add at least one size.';
        } else {
            foreach ($variantRows as $row) {
                if ($row['stock'] < 0) {
                    $groupError = 'Stock cannot be negative.';
                    break;
                }
            }
            if ($groupError === null) {
                $uploadError = inv_validate_color_group_uploads($variantRows);
                $firstColor = $variantRows[0]['color'] ?? null;
                if ($uploadError !== null && !inv_product_color_has_images($pdo, $productId, $firstColor)) {
                    $groupError = $uploadError;
                }
            }
        }

        if ($groupError !== null) {
            $message = $groupError;
            $messageType = 'error';
            $showForm = true;
            $editId = $productId;
        } else {
            try {
                $uploadsByIndex = inv_variant_uploads_by_index();
                $variantIns = $pdo->prepare('
                    INSERT INTO variants (product_id, size, color, stock_qty, preorder_flag)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $imagesSavedForColor = [];

                foreach ($variantRows as $row) {
                    $variantIns->execute([
                        $productId,
                        $row['size'],
                        $row['color'],
                        $row['stock'],
                        $row['preorder'],
                    ]);
                    $newVariantId = (int) $pdo->lastInsertId();
                    $ci = $row['color_index'];
                    if (!isset($imagesSavedForColor[$ci])) {
                        foreach ($uploadsByIndex[$ci] ?? [] as $file) {
                            $imgErr = inv_save_uploaded_image($pdo, $newVariantId, $file);
                            if ($imgErr) {
                                header('Location: admin_inventory.php?edit=' . $productId . '&msg=' . rawurlencode($imgErr) . '&err=1');
                                exit;
                            }
                        }
                        $imagesSavedForColor[$ci] = true;
                    }
                }

                header('Location: admin_inventory.php?edit=' . $productId . '&msg=variant_saved');
                exit;
            } catch (Throwable $e) {
                error_log('Save variant group failed: ' . $e->getMessage());
                $message = 'Could not save sizes.';
                $messageType = 'error';
                $showForm = true;
                $editId = $productId;
            }
        }
    }

    if (isset($_POST['save_variant_image'])) {
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        $variantImage = $_FILES['variant_image'] ?? [];

        if ($variantId <= 0 || $productId <= 0) {
            $message = 'Invalid variant.';
            $messageType = 'error';
            $showForm = true;
            $editId = $productId;
        } elseif (!inv_file_uploaded($variantImage)) {
            $message = 'Choose an image to upload.';
            $messageType = 'error';
            $showForm = true;
            $editId = $productId;
        } else {
            try {
                $check = $pdo->prepare('SELECT 1 FROM variants WHERE variant_id = ? AND product_id = ?');
                $check->execute([$variantId, $productId]);
                if (!$check->fetch()) {
                    throw new RuntimeException('Variant not found');
                }

                $imgErr = inv_save_uploaded_image($pdo, $variantId, $variantImage);
                if ($imgErr) {
                    header('Location: admin_inventory.php?edit=' . $productId . '&msg=' . rawurlencode($imgErr) . '&err=1');
                    exit;
                }

                header('Location: admin_inventory.php?edit=' . $productId . '&msg=image_added');
                exit;
            } catch (Throwable $e) {
                error_log('Save variant image failed: ' . $e->getMessage());
                $message = 'Could not upload image.';
                $messageType = 'error';
                $showForm = true;
                $editId = $productId;
            }
        }
    }

    if (isset($_POST['delete_variant'])) {
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        if ($variantId > 0 && $productId > 0) {
            try {
                $paths = $pdo->prepare('
                    SELECT i.image FROM images i
                    INNER JOIN variants v ON i.variant_id = v.variant_id
                    WHERE v.variant_id = ? AND v.product_id = ?
                ');
                $paths->execute([$variantId, $productId]);
                foreach ($paths->fetchAll() as $pr) {
                    $abs = __DIR__ . '/../' . $pr['image'];
                    if (is_file($abs)) {
                        @unlink($abs);
                    }
                }
                $pdo->prepare('DELETE FROM variants WHERE variant_id = ? AND product_id = ?')->execute([$variantId, $productId]);
                header('Location: admin_inventory.php?edit=' . $productId . '&msg=variant_deleted');
                exit;
            } catch (PDOException $e) {
                error_log('Delete variant failed: ' . $e->getMessage());
                $message = 'Could not delete variant.';
                $messageType = 'error';
                $showForm = true;
                $editId = $productId;
            }
        }
    }

    if (isset($_POST['save_product'])) {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $brandId = (int) ($_POST['brand_id'] ?? 0);
        $subCategoryId = (int) ($_POST['sub_category_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (int) ($_POST['price'] ?? 0);
        $variantRows = inv_parse_variant_rows_from_post();
        $isNew = $productId <= 0;

        $saveError = null;
        if ($title === '' || $brandId <= 0 || $subCategoryId <= 0 || $price < 0) {
            $saveError = 'Please fill in all required product fields.';
        } elseif ($isNew) {
            if (empty($variantRows)) {
                $saveError = 'Add at least one color with at least one size.';
            } else {
                foreach ($variantRows as $row) {
                    if ($row['stock'] < 0) {
                        $saveError = 'Stock cannot be negative.';
                        break;
                    }
                }
                if ($saveError === null) {
                    $saveError = inv_validate_color_group_uploads($variantRows);
                }
            }
        }

        if ($saveError !== null) {
            $message = $saveError;
            $messageType = 'error';
            $showForm = true;
            $editId = $productId;
        } else {
            try {
                [$brandName, $categoryName] = inv_brand_category_names($pdo, $brandId, $subCategoryId);

                $pdo->beginTransaction();

                if ($productId > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE products SET brand_id = ?, sub_category_id = ?, title = ?,
                        description = ?, brand = ?, category = ?, price = ?
                        WHERE product_id = ?
                    ');
                    $stmt->execute([
                        $brandId, $subCategoryId, $title,
                        $description === '' ? null : $description,
                        $brandName, $categoryName, $price, $productId,
                    ]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO products (brand_id, sub_category_id, title, description, brand, category, price)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $brandId, $subCategoryId, $title,
                        $description === '' ? null : $description,
                        $brandName, $categoryName, $price,
                    ]);
                    $productId = (int) $pdo->lastInsertId();

                    $variantIns = $pdo->prepare('
                        INSERT INTO variants (product_id, size, color, stock_qty, preorder_flag)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    $uploadsByIndex = inv_variant_uploads_by_index();
                    $imagesSavedForColor = [];
                    foreach ($variantRows as $row) {
                        $variantIns->execute([
                            $productId,
                            $row['size'],
                            $row['color'],
                            $row['stock'],
                            $row['preorder'],
                        ]);
                        $newVariantId = (int) $pdo->lastInsertId();
                        $ci = $row['color_index'];
                        if (!isset($imagesSavedForColor[$ci])) {
                            foreach ($uploadsByIndex[$ci] ?? [] as $file) {
                                $imgErr = inv_save_uploaded_image($pdo, $newVariantId, $file);
                                if ($imgErr) {
                                    $pdo->rollBack();
                                    $message = $imgErr;
                                    $messageType = 'error';
                                    $showForm = true;
                                    $editId = 0;
                                    throw new RuntimeException('rollback');
                                }
                            }
                            $imagesSavedForColor[$ci] = true;
                        }
                    }
                }

                $pdo->commit();

                if ($isNew) {
                    header('Location: admin_inventory.php?edit=' . $productId . '&msg=created');
                } else {
                    header('Location: admin_inventory.php?edit=' . $productId . '&msg=updated');
                }
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getMessage() !== 'rollback') {
                    error_log('Save product failed: ' . $e->getMessage());
                    $message = 'Could not save product.';
                    $messageType = 'error';
                    $showForm = true;
                    $editId = $productId;
                }
            }
        }
    }
}

if (isset($_GET['err']) && $_GET['err'] === '1' && isset($_GET['msg'])) {
    $message = (string) $_GET['msg'];
    $messageType = 'error';
} elseif (isset($_GET['msg'])) {
    $msgMap = [
        'created' => ['Product and first variant created. You can add more variants below.', 'success'],
        'updated' => ['Product updated successfully.', 'success'],
        'deleted' => ['Product deleted.', 'success'],
        'variant_saved' => ['Variant saved.', 'success'],
        'variant_deleted' => ['Variant removed.', 'success'],
        'brand_added' => ['Brand added.', 'success'],
        'sub_added' => ['Sub-category added.', 'success'],
        'image_added' => ['Image added to variant.', 'success'],
    ];
    $m = $_GET['msg'];
    if (isset($msgMap[$m])) {
        [$message, $messageType] = $msgMap[$m];
    }
}

$search = trim($_GET['q'] ?? '');
$perPage = 8;
$page = max(1, (int) ($_GET['page'] ?? 1));

function inv_list_page_url(array $params = []): string
{
    global $search, $page;

    $query = [];
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($page > 1) {
        $query['page'] = $page;
    }

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return 'admin_inventory.php' . ($query ? '?' . http_build_query($query) : '');
}

$productCountSql = '
    SELECT COUNT(*)
    FROM products p
    JOIN brands b ON p.brand_id = b.brand_id
    JOIN sub_categories sc ON p.sub_category_id = sc.sub_category_id
    JOIN categories c ON sc.category_id = c.category_id
';
$countParams = [];
if ($search !== '') {
    $productCountSql .= ' WHERE p.title LIKE ? OR b.name LIKE ?';
    $countParams = ['%' . $search . '%', '%' . $search . '%'];
}

$totalProducts = 0;
try {
    $countStmt = $pdo->prepare($productCountSql);
    $countStmt->execute($countParams);
    $totalProducts = (int) $countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Product count failed: ' . $e->getMessage());
}

$totalPages = max(1, (int) ceil($totalProducts / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$productSql = '
    SELECT
        p.product_id,
        p.title,
        p.price,
        p.created_at,
        b.name AS brand_name,
        CONCAT(c.category_name, " — ", sc.name) AS sub_category_name,
        COALESCE((
            SELECT SUM(v.stock_qty) FROM variants v WHERE v.product_id = p.product_id
        ), 0) AS stock_qty,
        (SELECT COUNT(*) FROM variants v WHERE v.product_id = p.product_id) AS variant_count,
        (
            SELECT ' . user_sql_variant_image_expr('v') . '
            FROM variants v
            WHERE v.product_id = p.product_id
            ORDER BY
              CASE
                WHEN v.image_id IS NOT NULL THEN 0
                WHEN EXISTS (SELECT 1 FROM images i_chk WHERE i_chk.variant_id = v.variant_id) THEN 1
                ELSE 2
              END,
              v.variant_id
            LIMIT 1
        ) AS image_path,
        COALESCE((
            SELECT ROUND(AVG(r.rating), 1)
            FROM reviews r
            WHERE r.product_id = p.product_id
        ), 0) AS avg_rating,
        COALESCE((
            SELECT COUNT(*)
            FROM reviews r
            WHERE r.product_id = p.product_id
        ), 0) AS review_count
    FROM products p
    JOIN brands b ON p.brand_id = b.brand_id
    JOIN sub_categories sc ON p.sub_category_id = sc.sub_category_id
    JOIN categories c ON sc.category_id = c.category_id
';
$params = [];
if ($search !== '') {
    $productSql .= ' WHERE p.title LIKE ? OR b.name LIKE ?';
    $params = ['%' . $search . '%', '%' . $search . '%'];
}
$productSql .= ' ORDER BY p.product_id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

$products = [];
try {
    $stmt = $pdo->prepare($productSql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Product list failed: ' . $e->getMessage());
}

$productCardSlides = [];
if (!empty($products)) {
    $productIds = array_values(array_unique(array_map(static function ($product) {
        return (int) ($product['product_id'] ?? 0);
    }, $products)));
    $productIds = array_values(array_filter($productIds, static fn (int $id): bool => $id > 0));

    if (!empty($productIds)) {
        try {
            $productCardSlides = inv_fetch_product_card_slides($pdo, $productIds);
        } catch (PDOException $e) {
            error_log('Product card slides failed: ' . $e->getMessage());
        }

        foreach ($products as $productRow) {
            $pid = (int) ($productRow['product_id'] ?? 0);
            if ($pid <= 0 || !empty($productCardSlides[$pid])) {
                continue;
            }

            $fallbackPath = trim((string) ($productRow['image_path'] ?? ''));
            $productCardSlides[$pid] = [[
                'image_path' => $fallbackPath,
                'image_url' => inv_img_url($fallbackPath !== '' ? $fallbackPath : null),
                'label' => 'Default',
                'stock_qty' => (int) ($productRow['stock_qty'] ?? 0),
            ]];
        }
    }
}

$brands = [];
$subs = [];
$categories = [];
try {
    $brands = $pdo->query('SELECT brand_id, name FROM brands ORDER BY name')->fetchAll();
    $subs = $pdo->query('
        SELECT sc.sub_category_id, sc.name AS sub_name, c.category_name
        FROM sub_categories sc
        JOIN categories c ON sc.category_id = c.category_id
        ORDER BY c.category_name, sc.name
    ')->fetchAll();
    $categories = $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_name')->fetchAll();
} catch (PDOException $e) {
    error_log('Inventory lookup data failed: ' . $e->getMessage());
    if ($message === '') {
        $message = 'Could not load brands or categories.';
        $messageType = 'error';
    }
}

$editing = null;
$productVariants = [];
$variantImagesByVariant = [];

if ($editId > 0) {
    $e = $pdo->prepare('SELECT * FROM products WHERE product_id = ?');
    $e->execute([$editId]);
    $editing = $e->fetch() ?: null;
    if (!$editing) {
        $editId = 0;
        $showForm = isset($_GET['add']);
    } else {
        $vStmt = $pdo->prepare('SELECT * FROM variants WHERE product_id = ? ORDER BY variant_id');
        $vStmt->execute([$editId]);
        $productVariants = $vStmt->fetchAll();
        foreach ($productVariants as $v) {
            $vid = (int) $v['variant_id'];
            $imgStmt = $pdo->prepare('SELECT * FROM images WHERE variant_id = ? ORDER BY sort_order, image_id');
            $imgStmt->execute([$vid]);
            $variantImagesByVariant[$vid] = $imgStmt->fetchAll();
        }
    }
}

$variantsByColor = [];
foreach ($productVariants as $v) {
    $colorLabel = ($v['color'] ?? '') !== '' ? (string) $v['color'] : '(No color)';
    if (!isset($variantsByColor[$colorLabel])) {
        $variantsByColor[$colorLabel] = [
            'color' => $v['color'],
            'variants' => [],
            'images' => [],
        ];
    }
    $variantsByColor[$colorLabel]['variants'][] = $v;
}
foreach ($variantsByColor as &$colorGroup) {
    $seenImageIds = [];
    foreach ($colorGroup['variants'] as $v) {
        $vid = (int) $v['variant_id'];
        foreach ($variantImagesByVariant[$vid] ?? [] as $img) {
            $iid = (int) $img['image_id'];
            if (!isset($seenImageIds[$iid])) {
                $colorGroup['images'][] = $img;
                $seenImageIds[$iid] = true;
            }
        }
    }
}
unset($colorGroup);

$formDefaults = [
    'product_id' => $editing['product_id'] ?? '',
    'brand_id' => $editing['brand_id'] ?? ($brands[0]['brand_id'] ?? ''),
    'sub_category_id' => $editing['sub_category_id'] ?? ($subs[0]['sub_category_id'] ?? ''),
    'title' => $editing['title'] ?? '',
    'description' => $editing['description'] ?? '',
    'price' => isset($editing['price']) ? (int) $editing['price'] : 0,
    'variant_size' => '',
    'variant_color' => '',
    'variant_stock_qty' => 0,
    'variant_preorder' => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'error') {
    $formDefaults = array_merge($formDefaults, [
        'product_id' => (int) ($_POST['product_id'] ?? 0),
        'brand_id' => (int) ($_POST['brand_id'] ?? 0),
        'sub_category_id' => (int) ($_POST['sub_category_id'] ?? 0),
        'title' => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'price' => (int) ($_POST['price'] ?? 0),
        'variant_size' => inv_post_first_string('variant_size'),
        'variant_color' => inv_post_first_string('variant_color'),
        'variant_stock_qty' => inv_post_first_int('variant_stock_qty'),
        'variant_preorder' => inv_post_has_preorder(),
    ]);
    $editId = (int) $formDefaults['product_id'] ?: $editId;
}

if (isset($_GET['brand_id'])) {
    $formDefaults['brand_id'] = (int) $_GET['brand_id'];
}
if (isset($_GET['sub_category_id'])) {
    $formDefaults['sub_category_id'] = (int) $_GET['sub_category_id'];
}

function inv_img_url(?string $path): string
{
    $path = trim((string) ($path ?? ''));
    if ($path !== '') {
        return '../' . ltrim($path, '/');
    }
    return '../uploads/lace.jpg';
}

/**
 * @param list<int> $productIds
 * @return array<int, list<array{image_path: string, image_url: string, label: string, stock_qty: int}>>
 */
function inv_fetch_product_card_slides(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $colorStockMap = [];

    $stockStmt = $pdo->prepare('
        SELECT
            product_id,
            COALESCE(NULLIF(TRIM(color), ""), "Default") AS color_label,
            SUM(stock_qty) AS stock_qty
        FROM variants
        WHERE product_id IN (' . $placeholders . ')
        GROUP BY product_id, color_label
    ');
    $stockStmt->execute($productIds);
    foreach ($stockStmt->fetchAll() as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $colorStockMap[$pid][(string) ($row['color_label'] ?? 'Default')] = (int) ($row['stock_qty'] ?? 0);
    }

    $imageStmt = $pdo->prepare('
        SELECT
            v.product_id,
            i.image AS image_path,
            COALESCE(NULLIF(TRIM(v.color), ""), "Default") AS color_label
        FROM images i
        INNER JOIN variants v ON v.variant_id = i.variant_id
        WHERE v.product_id IN (' . $placeholders . ')
        ORDER BY v.product_id, v.color, v.variant_id, i.sort_order, i.image_id
    ');
    $imageStmt->execute($productIds);
    $imageRows = $imageStmt->fetchAll();

    $colorTotals = [];
    foreach ($imageRows as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $colorLabel = (string) ($row['color_label'] ?? 'Default');
        $key = $pid . '|' . $colorLabel;
        $colorTotals[$key] = ($colorTotals[$key] ?? 0) + 1;
    }

    $slides = [];
    $colorIndex = [];
    foreach ($imageRows as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        $path = trim((string) ($row['image_path'] ?? ''));
        if ($path === '') {
            continue;
        }

        $colorLabel = (string) ($row['color_label'] ?? 'Default');
        $key = $pid . '|' . $colorLabel;
        $colorIndex[$key] = ($colorIndex[$key] ?? 0) + 1;
        $label = $colorLabel;
        $totalForColor = (int) ($colorTotals[$key] ?? 1);
        if ($totalForColor > 1) {
            $label = $colorLabel . ' (' . $colorIndex[$key] . '/' . $totalForColor . ')';
        }

        $slides[$pid][] = [
            'image_path' => $path,
            'image_url' => inv_img_url($path),
            'label' => $label,
            'stock_qty' => (int) ($colorStockMap[$pid][$colorLabel] ?? 0),
        ];
    }

    return $slides;
}

/**
 * @param list<array<string, mixed>> $cardSlides
 */
function inv_pick_initial_card_slide(array $cardSlides): ?array
{
    foreach ($cardSlides as $slide) {
        if (trim((string) ($slide['image_path'] ?? '')) !== '') {
            return $slide;
        }
    }

    return $cardSlides[0] ?? null;
}

function inv_format_num(int $n): string
{
    return number_format($n);
}

function inv_render_stars(float $avg): string
{
    $rounded = (int) round($avg);
    $rounded = max(0, min(5, $rounded));

    return str_repeat('★', $rounded) . str_repeat('☆', 5 - $rounded);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Inventory — Gemora Admin</title>
<link rel="stylesheet" href="../assets/style.css">
<?php include __DIR__ . '/include/admin_sidebar_styles.php'; ?>
<style>
  :root {
    --inv-accent-soft: rgba(19, 63, 76, 0.12);
  }

  main.content {
    min-width: 0;
    grid-template-columns: minmax(240px, 320px) minmax(0, 1fr);
    align-items: start;
  }
  main.content > .admin-sidebar,
  main.content > aside.admin-sidebar {
    min-width: 240px;
    align-self: start;
  }
  main.content > section {
    min-width: 0;
    width: 100%;
  }

  .inv-wrap {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .inv-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }
  .inv-search-global {
    flex: 1;
    min-width: 220px;
    max-width: 420px;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 10px 14px;
    color: var(--muted);
    box-shadow: var(--shadow);
  }
  .inv-search-global input {
    border: 0;
    outline: 0;
    width: 100%;
    font-size: 14px;
    background: transparent;
    color: var(--text);
  }

  .inv-page-head {
    margin-bottom: 0;
  }
  .inv-page-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .inv-search-inline {
    min-width: 180px;
    padding: 10px 12px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    font-size: 14px;
    background: #fff;
    color: var(--text);
  }
  .inv-search-inline:focus {
    outline: none;
    border-color: var(--accent);
  }
  .inv-add-btn {
    white-space: nowrap;
  }

  .inv-stat.card,
  .inv-panel.card {
    display: block;
  }
  .inv-product-card.card {
    display: block;
    padding: 0;
    gap: 0;
  }
  .inv-product-card.card:hover {
    transform: none;
    border-color: var(--muted);
  }

  .inv-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    min-width: 0;
  }
  .inv-stat {
    padding: 18px;
    min-width: 0;
    overflow: hidden;
    position: relative;
  }
  .inv-stat.featured {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
  }
  .inv-stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
    min-width: 0;
    overflow: hidden;
  }
  .inv-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: var(--accent-2);
    color: var(--accent);
    flex-shrink: 0;
  }
  .inv-stat.featured .inv-stat-icon {
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
  }
  .inv-stat-icon.warn {
    background: #fbeaea;
    color: #9b2226;
  }
  .inv-stat-label {
    font-size: 13px;
    color: var(--muted);
    margin: 0 0 6px;
  }
  .inv-stat.featured .inv-stat-label {
    color: rgba(255, 255, 255, 0.85);
  }
  .inv-stat-value {
    font-size: 26px;
    font-weight: 700;
    margin: 0 0 8px;
    line-height: 1;
    color: var(--text);
  }
  .inv-stat.featured .inv-stat-value {
    color: #fff;
  }
  .inv-stat-meta {
    font-size: 12px;
  }
  .inv-stat-change.positive { color: #2d6a4f; font-weight: 600; }
  .inv-stat.featured .inv-stat-change.positive { color: #b4e8c4; }
  .inv-stat-change.negative { color: #9b2226; font-weight: 600; }
  .inv-stat-change.muted { color: var(--muted); font-weight: 500; }
  .inv-spark-wrap {
    width: 72px;
    height: 28px;
    flex-shrink: 0;
    overflow: hidden;
    position: relative;
  }
  .inv-spark-wrap canvas {
    display: block;
    width: 72px !important;
    height: 28px !important;
    max-width: 72px;
    max-height: 28px;
  }

  .inv-main {
    display: block;
  }
  .inv-panel {
    padding: 18px;
    min-width: 0;
    overflow: hidden;
  }
  .inv-panel-list {
    overflow: visible;
  }
  .inv-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }
  .inv-panel-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }
  .inv-nav-arrows {
    display: flex;
    gap: 8px;
  }
  .inv-nav-arrows button,
  .inv-nav-arrows a {
    width: 34px;
    height: 34px;
    border-radius: var(--radius);
    border: 1px solid var(--glass-border);
    background: #fff;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 18px;
    line-height: 1;
  }
  .inv-nav-arrows button:hover,
  .inv-nav-arrows a:hover {
    background: var(--accent-2);
    border-color: var(--muted);
    color: var(--accent);
  }
  .inv-nav-arrows button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
  }

  .inv-list-meta {
    font-size: 13px;
    color: var(--muted);
    margin: 0 0 16px;
  }

  .inv-products-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    overflow: visible;
  }
  .inv-product-card {
    overflow: visible;
    min-width: 0;
    padding: 0;
    position: relative;
  }
  .inv-product-img {
    aspect-ratio: 1;
    background: var(--accent-2);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }
  .inv-product-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .inv-product-swipe-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid var(--glass-border);
    background: rgba(255, 255, 255, 0.92);
    color: var(--accent);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
    transition: all 0.2s ease;
  }
  .inv-product-swipe-btn:hover {
    background: #fff;
    border-color: var(--muted);
  }
  .inv-product-swipe-btn.prev {
    left: 8px;
  }
  .inv-product-swipe-btn.next {
    right: 8px;
  }
  .inv-product-swipe-btn[hidden] {
    display: none;
  }
  .inv-product-variant-meta {
    font-size: 12px;
    color: var(--muted);
    margin: 4px 0 8px;
  }
  .inv-product-body {
    padding: 14px;
  }
  .inv-product-title {
    margin: 0 0 6px;
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .inv-product-price {
    margin: 0 0 12px;
    font-size: 13px;
    color: var(--muted);
  }
  .inv-product-price strong,
  .inv-product-foot .price-accent {
    color: var(--accent);
    font-weight: 700;
  }
  .inv-product-foot {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 8px;
  }
  .inv-product-rating {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    min-width: 0;
    flex-shrink: 1;
    font-size: 12px;
    line-height: 1.2;
    color: var(--accent);
  }
  .inv-product-rating-stars,
  .inv-product-rating-empty {
    letter-spacing: -0.5px;
    white-space: nowrap;
    line-height: 1;
  }
  .inv-product-rating-empty {
    color: #c5c5c5;
  }
  .inv-product-rating-meta {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--muted);
    white-space: nowrap;
  }
  .inv-product-rating-score {
    font-weight: 700;
    color: var(--text);
  }
  .inv-product-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
  }
  .inv-product-edit-btn,
  .inv-product-delete-btn {
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
  }
  .inv-product-delete-btn {
    color: #9b2226;
    border-color: #e4b5b5;
  }
  .inv-product-delete-btn:hover {
    background: #fbeaea;
    border-color: #d49a9a;
    color: #7f2020;
  }
  .inv-product-actions form {
    margin: 0;
    display: inline;
  }
  .inv-menu-wrap {
    position: relative;
  }
  .inv-menu-btn {
    width: 32px;
    height: 32px;
    border: 0;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    border-radius: var(--radius);
  }
  .inv-menu-btn:hover {
    background: var(--accent-2);
    color: var(--accent);
  }
  .inv-menu {
    display: none;
    position: absolute;
    right: 0;
    bottom: 100%;
    top: auto;
    margin-bottom: 6px;
    min-width: 120px;
    background: #fff;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    z-index: 30;
    overflow: hidden;
  }
  .inv-menu.open { display: block; }
  .inv-menu a,
  .inv-menu button {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 12px;
    border: 0;
    background: #fff;
    font-size: 13px;
    color: var(--text);
    text-decoration: none;
    cursor: pointer;
  }
  .inv-menu a:hover,
  .inv-menu button:hover {
    background: var(--accent-2);
  }
  .inv-menu .danger { color: #9b2226; }

  .inv-chart-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
  }
  .inv-chart-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }
  .inv-chart-head select {
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 8px 10px;
    background: #fff;
    font-size: 13px;
    color: var(--text);
  }
  .inv-chart-head select:focus {
    outline: none;
    border-color: var(--accent);
  }
  .inv-chart-wrap {
    position: relative;
    height: 280px;
    width: 100%;
    min-width: 0;
  }

  .inv-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 48px 20px;
    color: var(--muted);
  }
  .inv-feedback {
    border-radius: var(--radius);
    padding: 12px 14px;
    font-size: 14px;
  }
  .inv-feedback.success {
    background: #e8f4ea;
    border: 1px solid #b4d8b8;
    color: #245d2b;
  }
  .inv-feedback.error {
    background: #fbeaea;
    border: 1px solid #e4b5b5;
    color: #7f2020;
  }
  .inv-modal .inv-feedback {
    margin: 0 0 14px;
  }

  .inv-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(13, 13, 13, 0.35);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100;
    padding: 20px;
  }
  .inv-modal-backdrop.open { display: flex; }
  .inv-confirm-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(13, 43, 51, 0.42);
    backdrop-filter: blur(3px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 200;
    padding: 20px;
  }
  .inv-confirm-backdrop.open { display: flex; }
  .inv-confirm-dialog {
    width: min(420px, 100%);
    background: #fff;
    border-radius: var(--radius);
    border: 1px solid var(--glass-border);
    box-shadow: 0 18px 48px rgba(13, 43, 51, 0.18);
    padding: 24px 24px 20px;
    text-align: center;
    animation: inv-confirm-in 0.18s ease-out;
  }
  @keyframes inv-confirm-in {
    from {
      opacity: 0;
      transform: translateY(8px) scale(0.98);
    }
    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }
  .inv-confirm-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 16px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    font-weight: 700;
    line-height: 1;
  }
  .inv-confirm-icon.is-danger {
    background: #fbeaea;
    color: #9b2226;
    border: 1px solid #e4b5b5;
  }
  .inv-confirm-icon.is-warn {
    background: var(--accent-2);
    color: var(--accent);
    border: 1px solid var(--glass-border);
  }
  .inv-confirm-dialog h3 {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
  }
  .inv-confirm-dialog p {
    margin: 0 0 22px;
    font-size: 14px;
    color: var(--muted);
    line-height: 1.55;
  }
  .inv-confirm-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
  }
  .inv-confirm-actions .btn {
    min-width: 108px;
  }
  .inv-confirm-danger {
    background: #9b2226;
    border: 1px solid #9b2226;
    color: #fff;
  }
  .inv-confirm-danger:hover {
    background: #7f2020;
    border-color: #7f2020;
    opacity: 1;
  }
  .inv-modal {
    width: min(760px, 100%);
    max-height: 90vh;
    overflow-y: auto;
    background: #fff;
    border-radius: var(--radius);
    padding: 22px;
    border: 1px solid var(--glass-border);
    box-shadow: var(--shadow);
  }
  .inv-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
  }
  .inv-modal-head h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
    line-height: 1.3;
  }
  .inv-modal-close {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    background: #fff;
    color: var(--muted);
    font-size: 22px;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
  }
  .inv-modal-close:hover {
    background: var(--accent-2);
    color: var(--text);
    border-color: var(--muted);
  }
  .inv-form-section {
    margin-bottom: 18px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--glass-border);
  }
  .inv-form-section:last-of-type {
    border-bottom: 0;
    margin-bottom: 0;
    padding-bottom: 0;
  }
  .inv-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }
  .inv-section-head h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--accent);
  }
  .inv-form-section h4 {
    margin: 0 0 12px;
    font-size: 15px;
    font-weight: 600;
    color: var(--accent);
  }
  .inv-add-panel {
    margin: 0 0 14px;
    padding: 12px;
    background: var(--accent-2);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    display: grid;
    gap: 10px;
  }
  .inv-add-panel[hidden] { display: none !important; }
  .inv-add-panel-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
  }
  .inv-color-group {
    padding: 14px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    margin-bottom: 12px;
    background: #fafafa;
  }
  .inv-color-group-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 10px;
  }
  .inv-color-group-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--accent);
  }
  .inv-size-rows {
    display: grid;
    gap: 8px;
    margin-bottom: 8px;
  }
  .inv-size-row {
    padding: 10px;
    border: 1px dashed var(--glass-border);
    border-radius: var(--radius);
    background: #fff;
  }
  .inv-size-row-foot {
    display: flex;
    justify-content: flex-end;
    margin-top: 6px;
  }
  .inv-color-group-existing {
    padding: 12px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    margin-bottom: 10px;
    background: var(--accent-2);
  }
  .inv-color-group-existing h5 {
    margin: 0 0 8px;
    font-size: 14px;
    color: var(--accent);
  }
  .inv-size-chip-list {
    margin: 0 0 10px;
    padding: 0;
    list-style: none;
    font-size: 13px;
    color: var(--text);
  }
  .inv-size-chip-list li {
    padding: 6px 0;
  }
  .inv-size-inline-edit {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .inv-size-inline-edit input[type="number"] {
    width: 90px;
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    padding: 6px 8px;
    font-size: 13px;
    background: #fff;
    color: var(--text);
  }
  .inv-size-inline-edit .inv-checkbox {
    font-size: 13px;
    font-weight: 500;
    margin: 0;
  }
  .inv-variant-row {
    padding: 12px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    margin-bottom: 10px;
    background: #fafafa;
  }
  .inv-variant-row-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
  }
  .inv-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .inv-form-row .full { grid-column: 1 / -1; }
  .inv-variant-list {
    display: grid;
    gap: 12px;
    margin-bottom: 14px;
  }
  .inv-variant-item {
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 12px;
    background: var(--accent-2);
  }
  .inv-variant-item-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
  }
  .inv-variant-item-meta {
    font-size: 12px;
    color: var(--muted);
    margin: 0 0 8px;
  }
  .inv-variant-images {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
  }
  .inv-variant-images img {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid var(--glass-border);
  }
  .inv-variant-images img.is-primary {
    outline: 2px solid var(--accent);
    outline-offset: 1px;
  }
  .inv-variant-image-actions {
    margin-top: 10px;
  }
  .inv-variant-image-add {
    margin-top: 10px;
  }
  .inv-variant-image-add label {
    display: grid;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
  }
  .inv-variant-image-block {
    grid-column: 1 / -1;
    display: grid;
    gap: 8px;
  }
  .inv-variant-image-block > span {
    font-size: 14px;
    font-weight: 500;
  }
  .inv-variant-image-inputs {
    display: grid;
    gap: 8px;
  }
  .inv-variant-image-inputs label {
    display: grid;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
  }
  .inv-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 500;
  }
  .inv-checkbox input { width: auto; }
  .btn.small {
    padding: 6px 12px;
    font-size: 12px;
  }
  @media (max-width: 600px) {
    .inv-form-row { grid-template-columns: 1fr; }
  }
  .inv-form-grid {
    display: grid;
    gap: 12px;
  }
  .inv-form-grid label {
    display: grid;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
  }
  .inv-form-grid input,
  .inv-form-grid select,
  .inv-form-grid textarea {
    width: 100%;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 10px 12px;
    font-size: 14px;
    background: #fff;
    color: var(--text);
  }
  .inv-form-grid input:focus,
  .inv-form-grid select:focus,
  .inv-form-grid textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(13, 67, 81, 0.1);
  }
  .inv-form-grid label.is-invalid > input,
  .inv-form-grid label.is-invalid > select,
  .inv-form-grid label.is-invalid > textarea,
  .inv-form-grid input.is-invalid,
  .inv-form-grid select.is-invalid,
  .inv-form-grid textarea.is-invalid {
    border-color: #c45c5c;
    background: #fffafa;
    box-shadow: 0 0 0 3px rgba(155, 34, 38, 0.1);
  }
  .inv-form-grid label.is-invalid > input:focus,
  .inv-form-grid label.is-invalid > select:focus,
  .inv-form-grid label.is-invalid > textarea:focus,
  .inv-form-grid input.is-invalid:focus,
  .inv-form-grid select.is-invalid:focus,
  .inv-form-grid textarea.is-invalid:focus {
    border-color: #9b2226;
    box-shadow: 0 0 0 3px rgba(155, 34, 38, 0.14);
  }
  .inv-field-msg {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    font-size: 12px;
    font-weight: 500;
    color: #9b2226;
    margin-top: 5px;
    line-height: 1.4;
  }
  .inv-field-msg::before {
    content: '!';
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fde8e8;
    border: 1px solid #e4b5b5;
    color: #9b2226;
    font-size: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    margin-top: 1px;
  }
  .inv-form-summary {
    background: #fbeaea;
    border: 1px solid #e4b5b5;
    border-radius: var(--radius);
    padding: 12px 14px;
    font-size: 13px;
    color: #7f2020;
    line-height: 1.5;
  }
  .inv-form-summary strong {
    display: block;
    margin-bottom: 6px;
    color: #9b2226;
    font-size: 13px;
  }
  .inv-form-summary ul {
    margin: 0;
    padding-left: 18px;
  }
  .inv-form-summary li {
    margin-bottom: 2px;
  }
  .inv-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 16px;
  }
  .inv-warn {
    background: var(--accent-2);
    border: 1px solid var(--glass-border);
    color: var(--accent);
    border-radius: var(--radius);
    padding: 12px 14px;
    font-size: 14px;
    margin-bottom: 14px;
  }

  @media (max-width: 1200px) {
    .inv-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .inv-main { grid-template-columns: minmax(0, 1fr); }
    .inv-products-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 900px) {
    main.content { grid-template-columns: 1fr; }
  }
  @media (max-width: 700px) {
    .inv-stats,
    .inv-products-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
  <div class="page" id="app">
    <?php include 'include/admin_header.php'; ?>

    <main class="content" role="main">
      <?php include 'include/admin_sidebar.php'; ?>

      <section class="inv-wrap">
        <div class="inv-toolbar">
          <form class="inv-search-global" method="get" action="admin_inventory.php">
            <span>🔍</span>
            <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search">
          </form>
        </div>

        <?php if ($message !== '' && !$showForm): ?>
          <div class="inv-feedback <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="product-header inv-page-head">
          <div class="left">
            <div style="display:flex;flex-direction:column">
              <div style="font-weight:600;font-size:22px">Inventory Overview</div>
              <div class="product-count">Manage products, stock, and catalog • Myanmar</div>
            </div>
          </div>
          <div class="inv-page-actions">
            <form method="get" action="admin_inventory.php">
              <input type="search" name="q" class="inv-search-inline" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search products">
            </form>
            <a href="admin_inventory.php?add=1" class="btn primary inv-add-btn">+ Add Product</a>
          </div>
        </div>

        <div class="inv-main">
          <div class="inv-panel card inv-panel-list">
            <div class="inv-panel-head">
              <h3>Products List</h3>
              <div class="inv-nav-arrows">
                <?php if ($page > 1): ?>
                  <a href="<?php echo htmlspecialchars(inv_list_page_url(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous page">‹</a>
                <?php else: ?>
                  <button type="button" disabled aria-label="Previous page">‹</button>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                  <a href="<?php echo htmlspecialchars(inv_list_page_url(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next page">›</a>
                <?php else: ?>
                  <button type="button" disabled aria-label="Next page">›</button>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($totalProducts > 0): ?>
            <p class="inv-list-meta">
              Showing <?php echo number_format(min($totalProducts, $offset + 1)); ?>–<?php echo number_format(min($totalProducts, $offset + count($products))); ?>
              of <?php echo number_format($totalProducts); ?> products
              <?php if ($totalPages > 1): ?>
                · Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?>
              <?php endif; ?>
            </p>
            <?php endif; ?>

            <?php if (empty($brands) || empty($subs)): ?>
              <div class="inv-warn">Add at least one brand and sub-category in the database before creating products.</div>
            <?php endif; ?>

            <div class="inv-products-grid">
              <?php if (empty($products)): ?>
                <div class="inv-empty">
                  <p>No products yet.</p>
                  <a href="admin_inventory.php?add=1" class="btn primary" style="margin-top:12px;">+ Add Product</a>
                </div>
              <?php else: ?>
                <?php foreach ($products as $product): ?>
                <?php
                $cardProductId = (int) ($product['product_id'] ?? 0);
                $cardSlides = $productCardSlides[$cardProductId] ?? [];
                if (empty($cardSlides)) {
                    $fallbackPath = trim((string) ($product['image_path'] ?? ''));
                    $cardSlides = [[
                        'stock_qty' => (int) ($product['stock_qty'] ?? 0),
                        'image_path' => $fallbackPath,
                        'image_url' => inv_img_url($fallbackPath !== '' ? $fallbackPath : null),
                        'label' => 'Default',
                    ]];
                }
                $initialSlide = inv_pick_initial_card_slide($cardSlides) ?? $cardSlides[0];
                $initialImageUrl = (string) ($initialSlide['image_url'] ?? inv_img_url((string) ($initialSlide['image_path'] ?? '')));
                $canSwipeSlides = count($cardSlides) > 1;
                ?>
                <article class="inv-product-card card">
                  <div class="inv-product-img" data-variant-swiper data-variants="<?php echo htmlspecialchars(json_encode($cardSlides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>">
                    <img src="<?php echo htmlspecialchars($initialImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                      data-variant-image
                      alt="<?php echo htmlspecialchars($product['title']); ?>">
                    <button type="button" class="inv-product-swipe-btn prev" data-variant-prev <?php echo $canSwipeSlides ? '' : 'hidden'; ?> aria-label="Previous image">‹</button>
                    <button type="button" class="inv-product-swipe-btn next" data-variant-next <?php echo $canSwipeSlides ? '' : 'hidden'; ?> aria-label="Next image">›</button>
                  </div>
                  <div class="inv-product-body">
                    <h4 class="inv-product-title"><?php echo htmlspecialchars($product['title']); ?></h4>
                    <p class="inv-product-meta" style="font-size:12px;color:var(--muted);margin:4px 0 0">
                      <?php echo htmlspecialchars($product['brand_name']); ?>
                      · <?php echo htmlspecialchars($product['sub_category_name'] ?? ''); ?>
                      · <?php echo (int) ($product['variant_count'] ?? 0); ?> variant<?php echo (int) ($product['variant_count'] ?? 0) === 1 ? '' : 's'; ?>
                    </p>
                    <p class="inv-product-variant-meta">Variant <span data-variant-label><?php echo htmlspecialchars((string) $initialSlide['label']); ?></span></p>
                    <p class="inv-product-price">Price <strong>MMK <?php echo inv_format_num((int) $product['price']); ?></strong>
                      · Stock <strong data-variant-stock><?php echo inv_format_num((int) $initialSlide['stock_qty']); ?></strong>
                    </p>
                    <?php
                    $reviewCount = (int) ($product['review_count'] ?? 0);
                    $avgRating = (float) ($product['avg_rating'] ?? 0);
                    $ratingTitle = $reviewCount > 0
                        ? 'Average rating from ' . $reviewCount . ' review' . ($reviewCount === 1 ? '' : 's')
                        : 'No reviews yet';
                    ?>
                    <div class="inv-product-foot">
                      <div class="inv-product-rating" title="<?php echo htmlspecialchars($ratingTitle); ?>">
                        <?php if ($reviewCount > 0): ?>
                          <span class="inv-product-rating-stars" aria-label="<?php echo htmlspecialchars(number_format($avgRating, 1)); ?> out of 5 stars"><?php echo inv_render_stars($avgRating); ?></span>
                          <span class="inv-product-rating-meta">
                            <span class="inv-product-rating-score"><?php echo htmlspecialchars(number_format($avgRating, 1)); ?></span>
                            <span>(<?php echo inv_format_num($reviewCount); ?>)</span>
                          </span>
                        <?php else: ?>
                          <span class="inv-product-rating-empty" aria-label="No reviews">☆☆☆☆☆</span>
                          <span class="inv-product-rating-meta">No reviews</span>
                        <?php endif; ?>
                      </div>
                      <div class="inv-product-actions">
                        <a href="admin_inventory.php?edit=<?php echo (int) $product['product_id']; ?>"
                          class="btn ghost small inv-product-edit-btn">Edit</a>
                        <form method="post" action="admin_inventory.php"
                          data-inv-confirm="This will permanently delete the product and all of its variants."
                          data-inv-confirm-title="Delete product?"
                          data-inv-confirm-btn="Delete"
                          data-inv-confirm-danger="1">
                          <input type="hidden" name="delete_id" value="<?php echo (int) $product['product_id']; ?>">
                          <button type="submit" class="btn ghost small inv-product-delete-btn">Delete</button>
                        </form>
                      </div>
                    </div>
                  </div>
                </article>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <div class="inv-confirm-backdrop" id="invConfirmModal" aria-hidden="true">
    <div class="inv-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="invConfirmTitle" aria-describedby="invConfirmMessage">
      <div class="inv-confirm-icon is-danger" id="invConfirmIcon" aria-hidden="true">!</div>
      <h3 id="invConfirmTitle">Confirm action</h3>
      <p id="invConfirmMessage">Are you sure?</p>
      <div class="inv-confirm-actions">
        <button type="button" class="btn ghost small" id="invConfirmCancel">Cancel</button>
        <button type="button" class="btn primary small" id="invConfirmOk">Confirm</button>
      </div>
    </div>
  </div>

  <div class="inv-modal-backdrop<?php echo $showForm ? ' open' : ''; ?>" id="productModal">
    <div class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="productModalTitle">
      <div class="inv-modal-head">
        <h3 id="productModalTitle"><?php echo $editId ? 'Edit Product' : 'Add Product'; ?></h3>
        <a href="admin_inventory.php" class="inv-modal-close" aria-label="Close" title="Close">&times;</a>
      </div>

      <?php if ($message !== '' && $showForm): ?>
        <div class="inv-feedback <?php echo $messageType; ?>" role="alert"><?php echo htmlspecialchars($message); ?></div>
      <?php endif; ?>

      <form method="post" action="admin_inventory.php<?php echo $editId ? '?edit=' . (int) $editId : '?add=1'; ?>" class="inv-form-grid inv-validated-form" enctype="multipart/form-data" id="productForm" novalidate>
        <input type="hidden" name="return_edit" value="<?php echo (int) $editId; ?>">
        <?php if ($editId): ?>
          <input type="hidden" name="product_id" value="<?php echo (int) $formDefaults['product_id']; ?>">
        <?php endif; ?>

        <section class="inv-form-section">
          <div class="inv-section-head">
            <h4>1. Product</h4>
          </div>
          <div class="inv-form-row">
            <label class="full">
              Name *
              <input type="text" name="title" required maxlength="200" value="<?php echo htmlspecialchars($formDefaults['title']); ?>">
            </label>
            <label class="full">
              Brand *
              <select name="brand_id" required id="brandSelect">
                <?php if (empty($brands)): ?>
                  <option value="">No brands yet</option>
                <?php endif; ?>
                <?php foreach ($brands as $brand): ?>
                  <option value="<?php echo (int) $brand['brand_id']; ?>"
                    <?php echo (string) $formDefaults['brand_id'] === (string) $brand['brand_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($brand['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="full">
              <button type="button" class="btn ghost small inv-add-toggle" data-target="addBrandPanel">+ Add new brand</button>
              <div id="addBrandPanel" class="inv-add-panel" hidden>
                <label>
                  Brand name
                  <input type="text" name="new_brand_name" maxlength="100" placeholder="e.g. Gemora">
                </label>
                <div class="inv-add-panel-actions">
                  <button type="submit" name="save_brand" value="1" formnovalidate class="btn primary small">Save brand</button>
                  <button type="button" class="btn ghost small inv-add-toggle" data-target="addBrandPanel">Cancel</button>
                </div>
              </div>
            </div>
            <label class="full">
              Sub-category *
              <select name="sub_category_id" required id="subCategorySelect">
                <?php if (empty($subs)): ?>
                  <option value="">No sub-categories yet</option>
                <?php endif; ?>
                <?php foreach ($subs as $sub): ?>
                  <option value="<?php echo (int) $sub['sub_category_id']; ?>"
                    data-category="<?php echo htmlspecialchars($sub['category_name']); ?>"
                    <?php echo (string) $formDefaults['sub_category_id'] === (string) $sub['sub_category_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sub['category_name'] . ' — ' . $sub['sub_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="full">
              <button type="button" class="btn ghost small inv-add-toggle" data-target="addSubPanel">+ Add new sub-category</button>
              <div id="addSubPanel" class="inv-add-panel" hidden>
                <label>
                  Sub-category name
                  <input type="text" name="new_sub_name" maxlength="100" placeholder="e.g. Wireless">
                </label>
                <label>
                  Category
                  <select name="category_id">
                    <option value="">— Select existing —</option>
                    <?php foreach ($categories as $cat): ?>
                      <option value="<?php echo (int) $cat['category_id']; ?>">
                        <?php echo htmlspecialchars($cat['category_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  Or new category name
                  <input type="text" name="new_category_name" maxlength="100" placeholder="e.g. Bras">
                </label>
                <div class="inv-add-panel-actions">
                  <button type="submit" name="save_sub_category" value="1" formnovalidate class="btn primary small">Save sub-category</button>
                  <button type="button" class="btn ghost small inv-add-toggle" data-target="addSubPanel">Cancel</button>
                </div>
              </div>
            </div>
            <label>
              List price (MMK) *
              <input type="number" name="price" required min="0" step="1" value="<?php echo (int) $formDefaults['price']; ?>">
            </label>
            <label class="full">
              Description
              <textarea name="description" rows="3"><?php echo htmlspecialchars($formDefaults['description']); ?></textarea>
            </label>
          </div>
        </section>

        <?php if (!$editId): ?>
        <section class="inv-form-section">
          <div class="inv-section-head">
            <h4>2. Variants</h4>
            <button type="button" class="btn ghost small" id="addColorGroupBtn">+ Add new color</button>
          </div>
          <p style="margin:0 0 12px;font-size:13px;color:var(--muted)">Each color has its own photos. Add multiple sizes under the same color.</p>
          <div id="colorGroupsContainer">
            <div class="inv-color-group" data-color-group>
              <div class="inv-color-group-head">
                <span class="inv-color-group-title">Color 1</span>
                <button type="button" class="btn-link danger inv-remove-color-group" hidden>Remove color</button>
              </div>
              <label class="full">
                Color *
                <input type="text" name="variant_color[0]" maxlength="50" required placeholder="e.g. Pink"
                  value="<?php echo htmlspecialchars($formDefaults['variant_color']); ?>">
              </label>
              <div class="inv-variant-image-block">
                <span>Images for this color *</span>
                <div class="inv-variant-image-inputs" data-variant-image-inputs>
                  <label>
                    Image 1
                    <input type="file" name="variant_image[0][]" required accept="image/jpeg,image/png,image/webp,image/gif">
                  </label>
                </div>
                <button type="button" class="btn ghost small inv-add-variant-image">+ Add new image</button>
              </div>
              <div class="inv-size-rows" data-size-rows>
                <div class="inv-size-row" data-size-row>
                  <div class="inv-form-row">
                    <label>
                      Size *
                      <input type="text" name="variant_size[0][]" maxlength="20" required placeholder="e.g. M, 34B"
                        value="<?php echo htmlspecialchars($formDefaults['variant_size']); ?>">
                    </label>
                    <label>
                      Stock *
                      <input type="number" name="variant_stock_qty[0][]" required min="0" step="1"
                        value="<?php echo (int) $formDefaults['variant_stock_qty']; ?>">
                    </label>
                    <label class="inv-checkbox">
                      <input type="checkbox" name="variant_preorder[0][0]" value="1"
                        <?php echo $formDefaults['variant_preorder'] ? 'checked' : ''; ?>>
                      Preorder
                    </label>
                  </div>
                  <div class="inv-size-row-foot">
                    <button type="button" class="btn-link danger inv-remove-size-row" hidden>Remove size</button>
                  </div>
                </div>
              </div>
              <button type="button" class="btn ghost small inv-add-size-row">+ Add new size</button>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <div class="inv-form-actions">
          <a href="admin_inventory.php" class="btn ghost">Cancel</a>
          <button type="submit" name="save_product" value="1" class="btn primary">
            <?php echo $editId ? 'Update product' : 'Create product'; ?>
          </button>
        </div>
      </form>

      <?php if ($editId): ?>
        <section class="inv-form-section" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--glass-border)">
          <div class="inv-section-head">
            <h4>2. Variants</h4>
            <button type="button" class="btn ghost small inv-add-toggle" data-target="addColorPanel">+ Add new color</button>
          </div>

          <?php if (!empty($variantsByColor)): ?>
            <?php foreach ($variantsByColor as $colorLabel => $colorGroup): ?>
              <?php
              $firstVariant = $colorGroup['variants'][0];
              $firstVid = (int) $firstVariant['variant_id'];
              $displayColor = $colorLabel === '(No color)' ? 'No color' : $colorLabel;
              ?>
              <article class="inv-color-group-existing">
                <h5><?php echo htmlspecialchars($displayColor); ?></h5>
                <?php if (!empty($colorGroup['images'])): ?>
                  <div class="inv-variant-images">
                    <?php foreach ($colorGroup['images'] as $img): ?>
                      <img src="<?php echo htmlspecialchars(inv_img_url($img['image'])); ?>" alt="">
                    <?php endforeach; ?>
                  </div>
                  <div class="inv-variant-image-actions" style="margin-bottom:10px">
                    <button type="button" class="btn ghost small inv-add-toggle"
                      data-target="addImagePanel-<?php echo $firstVid; ?>">+ Add new image</button>
                  </div>
                  <form method="post" action="admin_inventory.php?edit=<?php echo (int) $editId; ?>"
                    id="addImagePanel-<?php echo $firstVid; ?>" class="inv-add-panel inv-variant-image-add inv-validated-form" enctype="multipart/form-data" hidden novalidate>
                    <input type="hidden" name="product_id" value="<?php echo (int) $editId; ?>">
                    <input type="hidden" name="variant_id" value="<?php echo $firstVid; ?>">
                    <label>
                      Upload image
                      <input type="file" name="variant_image" required accept="image/jpeg,image/png,image/webp,image/gif">
                    </label>
                    <div class="inv-add-panel-actions">
                      <button type="submit" name="save_variant_image" value="1" class="btn primary small">Save image</button>
                      <button type="button" class="btn ghost small inv-add-toggle"
                        data-target="addImagePanel-<?php echo $firstVid; ?>">Cancel</button>
                    </div>
                  </form>
                <?php endif; ?>
                <ul class="inv-size-chip-list">
                  <?php foreach ($colorGroup['variants'] as $v): ?>
                    <?php $vid = (int) $v['variant_id']; ?>
                    <li>
                      <form method="post" action="admin_inventory.php?edit=<?php echo (int) $editId; ?>" class="inv-size-inline-edit">
                        <input type="hidden" name="product_id" value="<?php echo (int) $editId; ?>">
                        <input type="hidden" name="variant_id" value="<?php echo $vid; ?>">
                        <input type="hidden" name="variant_size" value="<?php echo htmlspecialchars((string) ($v['size'] ?? '')); ?>">
                        <input type="hidden" name="variant_color" value="<?php echo htmlspecialchars((string) ($v['color'] ?? '')); ?>">
                        <span>Size <?php echo $v['size'] ? htmlspecialchars($v['size']) : '—'; ?></span>
                        <span>·</span>
                        <span>Stock</span>
                        <input type="number" name="variant_stock_qty" min="0" step="1" value="<?php echo (int) $v['stock_qty']; ?>" required>
                        <label class="inv-checkbox">
                          <input type="checkbox" name="variant_preorder" value="1"
                            <?php echo !empty($v['preorder_flag']) ? 'checked' : ''; ?>>
                          Preorder
                        </label>
                        <button type="submit" name="save_variant" value="1" class="btn ghost small">Save</button>
                      </form>
                      <form method="post" action="admin_inventory.php?edit=<?php echo (int) $editId; ?>" style="display:inline;margin-left:8px"
                        data-inv-confirm="This size variant will be removed from the product."
                        data-inv-confirm-title="Remove this size?"
                        data-inv-confirm-btn="Remove">
                        <input type="hidden" name="product_id" value="<?php echo (int) $editId; ?>">
                        <input type="hidden" name="variant_id" value="<?php echo $vid; ?>">
                        <button type="submit" name="delete_variant" value="1" class="btn-link danger">Remove</button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <button type="button" class="btn ghost small inv-add-size-existing"
                  data-target="addSizePanel"
                  data-color="<?php echo htmlspecialchars((string) ($colorGroup['color'] ?? '')); ?>"
                  data-color-label="<?php echo htmlspecialchars($displayColor); ?>">+ Add new size</button>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="font-size:13px;color:var(--muted);margin:0 0 12px">No variants yet.</p>
          <?php endif; ?>
        </section>

        <form method="post" action="admin_inventory.php?edit=<?php echo (int) $editId; ?>" class="inv-form-grid inv-add-panel inv-validated-form" id="addColorPanel" enctype="multipart/form-data" hidden novalidate>
          <input type="hidden" name="product_id" value="<?php echo (int) $editId; ?>">
          <h4 style="margin:0;font-size:14px">New color</h4>
          <div class="inv-color-group" data-color-group data-edit-inline>
            <label class="full">
              Color *
              <input type="text" name="variant_color[0]" maxlength="50" required placeholder="e.g. Pink">
            </label>
            <div class="inv-variant-image-block">
              <span>Images for this color *</span>
              <div class="inv-variant-image-inputs" data-variant-image-inputs>
                <label>
                  Image 1
                  <input type="file" name="variant_image[0][]" required accept="image/jpeg,image/png,image/webp,image/gif">
                </label>
              </div>
              <button type="button" class="btn ghost small inv-add-variant-image">+ Add new image</button>
            </div>
            <div class="inv-size-rows" data-size-rows>
              <div class="inv-size-row" data-size-row>
                <div class="inv-form-row">
                  <label>
                    Size *
                    <input type="text" name="variant_size[0][]" maxlength="20" required placeholder="e.g. M">
                  </label>
                  <label>
                    Stock *
                    <input type="number" name="variant_stock_qty[0][]" required min="0" step="1" value="0">
                  </label>
                  <label class="inv-checkbox">
                    <input type="checkbox" name="variant_preorder[0][0]" value="1">
                    Preorder
                  </label>
                </div>
              </div>
            </div>
            <button type="button" class="btn ghost small inv-add-size-row">+ Add new size</button>
          </div>
          <div class="inv-add-panel-actions">
            <button type="submit" name="save_variant_group" value="1" class="btn primary small">Save color &amp; sizes</button>
            <button type="button" class="btn ghost small inv-add-toggle" data-target="addColorPanel">Cancel</button>
          </div>
        </form>

        <form method="post" action="admin_inventory.php?edit=<?php echo (int) $editId; ?>" class="inv-form-grid inv-add-panel inv-validated-form" id="addSizePanel" enctype="multipart/form-data" hidden novalidate>
          <input type="hidden" name="product_id" value="<?php echo (int) $editId; ?>">
          <input type="hidden" name="variant_color" id="addSizeColorField" value="">
          <h4 id="addSizePanelTitle" style="margin:0;font-size:14px">Add size</h4>
          <p style="margin:0;font-size:13px;color:var(--muted)">Uses photos already uploaded for this color.</p>
          <div class="inv-form-row">
            <label>
              Size *
              <input type="text" name="variant_size" maxlength="20" required placeholder="e.g. L">
            </label>
            <label>
              Stock *
              <input type="number" name="variant_stock_qty" required min="0" step="1" value="0">
            </label>
            <label class="full inv-checkbox">
              <input type="checkbox" name="variant_preorder" value="1">
              Preorder
            </label>
          </div>
          <div class="inv-add-panel-actions">
            <button type="submit" name="save_variant" value="1" class="btn primary small">Save size</button>
            <button type="button" class="btn ghost small inv-add-toggle" data-target="addSizePanel">Cancel</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php include 'include/admin_footer.php'; ?>

<script>
  document.querySelectorAll('[data-menu-toggle]').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var menu = btn.nextElementSibling;
      document.querySelectorAll('.inv-menu.open').forEach(function(openMenu) {
        if (openMenu !== menu) openMenu.classList.remove('open');
      });
      menu.classList.toggle('open');
    });
  });

  document.addEventListener('click', function() {
    document.querySelectorAll('.inv-menu.open').forEach(function(menu) {
      menu.classList.remove('open');
    });
  });

  var modal = document.getElementById('productModal');
  if (modal) {
    modal.addEventListener('click', function(e) {
      if (e.target === modal) {
        window.location.href = 'admin_inventory.php';
      }
    });
  }

  document.querySelectorAll('.inv-add-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var id = btn.getAttribute('data-target');
      if (!id) return;
      var panel = document.getElementById(id);
      if (!panel) return;
      var willOpen = panel.hasAttribute('hidden');
      document.querySelectorAll('.inv-add-panel').forEach(function(p) {
        if (p.id !== id) p.setAttribute('hidden', '');
      });
      if (willOpen) {
        panel.removeAttribute('hidden');
        var focus = panel.querySelector('input:not([type="hidden"]), select, textarea');
        if (focus) focus.focus();
      } else {
        panel.setAttribute('hidden', '');
      }
    });
  });

  (function() {
    function invFieldIsVisible(field) {
      if (!field || field.disabled) return false;
      var hidden = field.closest('[hidden]');
      return !hidden;
    }

    function invFieldLabel(field) {
      var label = field.closest('label');
      if (!label) return 'This field';
      var clone = label.cloneNode(true);
      clone.querySelectorAll('input, select, textarea, .inv-field-msg').forEach(function(el) {
        el.remove();
      });
      return (clone.textContent || '').replace(/\*/g, '').replace(/\s+/g, ' ').trim() || 'This field';
    }

    function invClearFieldError(field) {
      if (!field) return;
      field.classList.remove('is-invalid');
      field.removeAttribute('aria-invalid');
      var label = field.closest('label');
      if (label) label.classList.remove('is-invalid');
      var msg = (label || field.parentElement).querySelector('.inv-field-msg');
      if (msg) msg.remove();
    }

    function invClearFormErrors(form) {
      form.querySelectorAll('.inv-field-msg').forEach(function(el) { el.remove(); });
      form.querySelectorAll('.is-invalid').forEach(function(el) {
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
      });
      var summary = form.querySelector('.inv-form-summary');
      if (summary) summary.remove();
    }

    function invValidateField(field) {
      if (!invFieldIsVisible(field)) return null;
      if (!field.hasAttribute('required')) return null;

      var label = invFieldLabel(field);
      var value = field.value;

      if (field.type === 'file') {
        if (!field.files || field.files.length === 0) {
          return 'Please upload ' + label.toLowerCase() + '.';
        }
        return null;
      }

      if (field.tagName === 'SELECT') {
        if (!value || value === '') {
          return 'Please select ' + label.toLowerCase() + '.';
        }
        return null;
      }

      if (field.type === 'number') {
        if (value === '' || value === null) {
          return label + ' is required.';
        }
        if (field.hasAttribute('min') && Number(value) < Number(field.getAttribute('min'))) {
          return label + ' must be at least ' + field.getAttribute('min') + '.';
        }
        return null;
      }

      if (!String(value || '').trim()) {
        return label + ' is required.';
      }

      return null;
    }

    function invShowFieldError(field, message) {
      var label = field.closest('label');
      if (label) {
        label.classList.add('is-invalid');
      }
      field.classList.add('is-invalid');
      field.setAttribute('aria-invalid', 'true');

      var host = label || field.parentElement;
      if (!host) return;

      var msgEl = host.querySelector('.inv-field-msg');
      if (!msgEl) {
        msgEl = document.createElement('span');
        msgEl.className = 'inv-field-msg';
        msgEl.setAttribute('role', 'alert');
        field.insertAdjacentElement('afterend', msgEl);
      }
      msgEl.textContent = message;
    }

    function invShowFormSummary(form, errors) {
      var existing = form.querySelector('.inv-form-summary');
      if (existing) existing.remove();

      var summary = document.createElement('div');
      summary.className = 'inv-form-summary';
      summary.setAttribute('role', 'alert');

      var title = document.createElement('strong');
      title.textContent = 'Please complete the required fields';
      summary.appendChild(title);

      var list = document.createElement('ul');
      var seen = {};
      errors.forEach(function(item) {
        if (seen[item.message]) return;
        seen[item.message] = true;
        var li = document.createElement('li');
        li.textContent = item.message;
        list.appendChild(li);
      });
      summary.appendChild(list);

      form.insertBefore(summary, form.firstChild);
    }

    function invValidateForm(form) {
      invClearFormErrors(form);
      var errors = [];
      form.querySelectorAll('input, select, textarea').forEach(function(field) {
        var message = invValidateField(field);
        if (message) {
          invShowFieldError(field, message);
          errors.push({ field: field, message: message });
        }
      });
      return errors;
    }

    document.querySelectorAll('.inv-validated-form').forEach(function(form) {
      form.addEventListener('input', function(event) {
        var target = event.target;
        if (target && target.matches('input, select, textarea')) {
          invClearFieldError(target);
          var summary = form.querySelector('.inv-form-summary');
          if (summary && !form.querySelector('.is-invalid')) {
            summary.remove();
          }
        }
      });

      form.addEventListener('change', function(event) {
        var target = event.target;
        if (target && target.matches('input[type="file"], select')) {
          invClearFieldError(target);
        }
      });

      form.addEventListener('submit', function(event) {
        if (event.submitter && event.submitter.hasAttribute('formnovalidate')) {
          return;
        }

        var errors = invValidateForm(form);
        if (errors.length > 0) {
          event.preventDefault();
          invShowFormSummary(form, errors);
          errors[0].field.focus({ preventScroll: true });
          errors[0].field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
    });
  })();

  var colorGroupsContainer = document.getElementById('colorGroupsContainer');
  var addColorGroupBtn = document.getElementById('addColorGroupBtn');

  function invColorGroupParent(group) {
    return group.closest('#colorGroupsContainer, #addColorPanel');
  }

  function invColorGroupIndex(group) {
    var parent = invColorGroupParent(group);
    if (!parent) return 0;
    var groups = parent.querySelectorAll('[data-color-group]');
    var idx = Array.prototype.indexOf.call(groups, group);
    return idx >= 0 ? idx : 0;
  }

  function invRenumberColorGroupImages(group, colorIndex) {
    var container = group.querySelector('[data-variant-image-inputs]');
    if (!container) return;
    container.querySelectorAll('input[type="file"]').forEach(function(input, imageIndex) {
      input.name = 'variant_image[' + colorIndex + '][]';
      var label = input.closest('label');
      if (label && label.firstChild && label.firstChild.nodeType === 3) {
        label.firstChild.textContent = 'Image ' + (imageIndex + 1) + ' ';
      }
    });
  }

  function invRenumberSizeRows(group, colorIndex) {
    var sizeRows = group.querySelectorAll('[data-size-row]');
    sizeRows.forEach(function(sizeRow, sizeIndex) {
      sizeRow.querySelectorAll('input[type="text"]').forEach(function(input) {
        input.name = 'variant_size[' + colorIndex + '][]';
      });
      sizeRow.querySelectorAll('input[type="number"]').forEach(function(input) {
        input.name = 'variant_stock_qty[' + colorIndex + '][]';
      });
      var preorder = sizeRow.querySelector('input[type="checkbox"]');
      if (preorder) preorder.name = 'variant_preorder[' + colorIndex + '][' + sizeIndex + ']';
      var removeSize = sizeRow.querySelector('.inv-remove-size-row');
      if (removeSize) removeSize.hidden = sizeRows.length <= 1;
    });
  }

  function invRenumberColorGroups() {
    document.querySelectorAll('#colorGroupsContainer [data-color-group], #addColorPanel [data-color-group]').forEach(function(group) {
      var parent = invColorGroupParent(group);
      if (!parent) return;
      var groups = parent.querySelectorAll('[data-color-group]');
      var colorIndex = Array.prototype.indexOf.call(groups, group);
      var title = group.querySelector('.inv-color-group-title');
      if (title) title.textContent = 'Color ' + (colorIndex + 1);
      var colorInput = group.querySelector('input[name^="variant_color"]');
      if (colorInput) colorInput.name = 'variant_color[' + colorIndex + ']';
      invRenumberColorGroupImages(group, colorIndex);
      invRenumberSizeRows(group, colorIndex);
      var removeColor = group.querySelector('.inv-remove-color-group');
      if (removeColor && parent.id === 'colorGroupsContainer') {
        removeColor.hidden = groups.length <= 1;
      }
    });
  }

  function invAddSizeRow(group) {
    var sizeRowsWrap = group.querySelector('[data-size-rows]');
    var template = group.querySelector('[data-size-row]');
    if (!sizeRowsWrap || !template) return;
    var clone = template.cloneNode(true);
    clone.querySelectorAll('input').forEach(function(input) {
      if (input.type === 'checkbox') {
        input.checked = false;
      } else {
        input.value = input.type === 'number' ? '0' : '';
      }
    });
    sizeRowsWrap.appendChild(clone);
    invRenumberColorGroups();
  }

  function invAddVariantImageInput(container, namePrefix) {
    var count = container.querySelectorAll('input[type="file"]').length;
    var label = document.createElement('label');
    var labelText = document.createTextNode('Image ' + (count + 1) + ' ');
    var input = document.createElement('input');
    input.type = 'file';
    input.name = namePrefix;
    input.required = count === 0;
    input.accept = 'image/jpeg,image/png,image/webp,image/gif';
    label.appendChild(labelText);
    label.appendChild(input);
    container.appendChild(label);
  }

  document.addEventListener('click', function(e) {
    var addImgBtn = e.target.closest('.inv-add-variant-image');
    if (addImgBtn) {
      var group = addImgBtn.closest('[data-color-group]');
      if (group) {
        var container = group.querySelector('[data-variant-image-inputs]');
        if (container) {
          var ci = invColorGroupIndex(group);
          if (ci < 0) ci = 0;
          invAddVariantImageInput(container, 'variant_image[' + ci + '][]');
        }
      }
      return;
    }

    var addSizeBtn = e.target.closest('.inv-add-size-row');
    if (addSizeBtn) {
      var g = addSizeBtn.closest('[data-color-group]');
      if (g) invAddSizeRow(g);
      return;
    }

    var removeSizeBtn = e.target.closest('.inv-remove-size-row');
    if (removeSizeBtn) {
      var sizeRow = removeSizeBtn.closest('[data-size-row]');
      var g2 = removeSizeBtn.closest('[data-color-group]');
      if (sizeRow && g2 && g2.querySelectorAll('[data-size-row]').length > 1) {
        sizeRow.remove();
        invRenumberColorGroups();
      }
      return;
    }

    var removeColorBtn = e.target.closest('.inv-remove-color-group');
    if (removeColorBtn && colorGroupsContainer) {
      var cg = removeColorBtn.closest('[data-color-group]');
      if (cg && colorGroupsContainer.querySelectorAll('[data-color-group]').length > 1) {
        cg.remove();
        invRenumberColorGroups();
      }
      return;
    }

    var addSizeExisting = e.target.closest('.inv-add-size-existing');
    if (addSizeExisting) {
      var sizePanel = document.getElementById('addSizePanel');
      var colorField = document.getElementById('addSizeColorField');
      var titleEl = document.getElementById('addSizePanelTitle');
      if (sizePanel && colorField) {
        colorField.value = addSizeExisting.getAttribute('data-color') || '';
        if (titleEl) {
          titleEl.textContent = 'Add size — ' + (addSizeExisting.getAttribute('data-color-label') || 'color');
        }
        document.querySelectorAll('.inv-add-panel').forEach(function(p) {
          if (p.id !== 'addSizePanel') p.setAttribute('hidden', '');
        });
        sizePanel.removeAttribute('hidden');
        var firstInput = sizePanel.querySelector('input[type="text"]');
        if (firstInput) firstInput.focus();
      }
      return;
    }
  });

  if (addColorGroupBtn && colorGroupsContainer) {
    var templateGroup = colorGroupsContainer.querySelector('[data-color-group]');
    addColorGroupBtn.addEventListener('click', function() {
      if (!templateGroup) return;
      var clone = templateGroup.cloneNode(true);
      clone.querySelectorAll('input').forEach(function(input) {
        if (input.type === 'checkbox') {
          input.checked = false;
        } else if (input.type === 'file') {
          input.value = '';
          input.required = true;
        } else {
          input.value = input.type === 'number' ? '0' : '';
        }
      });
      var imageInputs = clone.querySelector('[data-variant-image-inputs]');
      if (imageInputs) {
        imageInputs.querySelectorAll('label:not(:first-child)').forEach(function(label) {
          label.remove();
        });
      }
      var sizesWrap = clone.querySelector('[data-size-rows]');
      if (sizesWrap) {
        sizesWrap.querySelectorAll('[data-size-row]:not(:first-child)').forEach(function(row) {
          row.remove();
        });
      }
      colorGroupsContainer.appendChild(clone);
      invRenumberColorGroups();
    });
    invRenumberColorGroups();
  }

  var addColorPanel = document.getElementById('addColorPanel');
  if (addColorPanel) {
    invRenumberColorGroups();
    addColorPanel.querySelectorAll('.inv-add-size-row').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var g = btn.closest('[data-color-group]');
        if (g) invAddSizeRow(g);
      });
    });
  }

  if (window.location.search.indexOf('msg=created') !== -1) {
    var panel = document.getElementById('addColorPanel');
    if (panel) panel.removeAttribute('hidden');
  }

  document.querySelectorAll('[data-variant-swiper]').forEach(function(swiper) {
    var variantsRaw = swiper.getAttribute('data-variants');
    if (!variantsRaw) return;

    var variants;
    try {
      variants = JSON.parse(variantsRaw);
    } catch (err) {
      return;
    }

    if (!Array.isArray(variants) || variants.length === 0) return;

    var card = swiper.closest('.inv-product-card');
    if (!card) return;

    var img = swiper.querySelector('[data-variant-image]');
    var stockEl = card.querySelector('[data-variant-stock]');
    var labelEl = card.querySelector('[data-variant-label]');
    var prevBtn = swiper.querySelector('[data-variant-prev]');
    var nextBtn = swiper.querySelector('[data-variant-next]');
    var variantIndex = 0;

    function formatNumber(n) {
      var value = Number(n || 0);
      if (!Number.isFinite(value)) value = 0;
      return value.toLocaleString('en-US');
    }

    function normalizeImagePath(path) {
      var trimmed = String(path || '').replace(/^\/+/, '');
      return trimmed ? ('../' + trimmed) : '../uploads/lace.jpg';
    }

    function resolveImageUrl(variant) {
      if (variant && variant.image_url) {
        return String(variant.image_url);
      }
      return normalizeImagePath(variant ? variant.image_path : '');
    }

    function renderVariant() {
      var current = variants[variantIndex] || variants[0];
      if (img) {
        var nextUrl = resolveImageUrl(current);
        if (nextUrl) img.src = nextUrl;
      }
      if (stockEl) stockEl.textContent = formatNumber(current.stock_qty);
      if (labelEl) labelEl.textContent = current.label || 'Default';
    }

    function stepVariant(direction) {
      if (variants.length <= 1) return;
      variantIndex = (variantIndex + direction + variants.length) % variants.length;
      renderVariant();
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', function() {
        stepVariant(-1);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function() {
        stepVariant(1);
      });
    }

    var touchStartX = null;
    swiper.addEventListener('touchstart', function(event) {
      if (!event.touches || event.touches.length === 0) return;
      touchStartX = event.touches[0].clientX;
    }, { passive: true });

    swiper.addEventListener('touchend', function(event) {
      if (touchStartX === null || !event.changedTouches || event.changedTouches.length === 0) {
        touchStartX = null;
        return;
      }
      var deltaX = event.changedTouches[0].clientX - touchStartX;
      touchStartX = null;
      if (Math.abs(deltaX) < 30) return;
      if (deltaX < 0) {
        stepVariant(1);
      } else {
        stepVariant(-1);
      }
    }, { passive: true });

    if (img && !img.getAttribute('src')) {
      renderVariant();
    }
  });

  (function() {
    var backdrop = document.getElementById('invConfirmModal');
    var titleEl = document.getElementById('invConfirmTitle');
    var messageEl = document.getElementById('invConfirmMessage');
    var iconEl = document.getElementById('invConfirmIcon');
    var cancelBtn = document.getElementById('invConfirmCancel');
    var okBtn = document.getElementById('invConfirmOk');
    var pendingForm = null;
    var lastFocused = null;

    if (!backdrop || !titleEl || !messageEl || !okBtn || !cancelBtn) return;

    function closeConfirm() {
      backdrop.classList.remove('open');
      backdrop.setAttribute('aria-hidden', 'true');
      pendingForm = null;
      if (lastFocused && typeof lastFocused.focus === 'function') {
        lastFocused.focus();
      }
    }

    function openConfirm(form) {
      var title = form.getAttribute('data-inv-confirm-title') || 'Confirm action';
      var message = form.getAttribute('data-inv-confirm') || 'Are you sure?';
      var confirmLabel = form.getAttribute('data-inv-confirm-btn') || 'Confirm';
      var isDanger = form.getAttribute('data-inv-confirm-danger') === '1';

      titleEl.textContent = title;
      messageEl.textContent = message;
      okBtn.textContent = confirmLabel;
      okBtn.classList.toggle('inv-confirm-danger', isDanger);
      okBtn.classList.toggle('primary', !isDanger);
      iconEl.classList.toggle('is-danger', isDanger);
      iconEl.classList.toggle('is-warn', !isDanger);

      pendingForm = form;
      lastFocused = document.activeElement;
      backdrop.classList.add('open');
      backdrop.setAttribute('aria-hidden', 'false');
      cancelBtn.focus();
    }

    document.querySelectorAll('form[data-inv-confirm]').forEach(function(form) {
      form.addEventListener('submit', function(event) {
        if (form.dataset.invConfirmed === '1') {
          form.dataset.invConfirmed = '0';
          return;
        }
        event.preventDefault();
        openConfirm(form);
      });
    });

    cancelBtn.addEventListener('click', closeConfirm);
    backdrop.addEventListener('click', function(event) {
      if (event.target === backdrop) closeConfirm();
    });
    document.addEventListener('keydown', function(event) {
      if (!backdrop.classList.contains('open')) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeConfirm();
      }
    });
    okBtn.addEventListener('click', function() {
      if (!pendingForm) return;
      var form = pendingForm;
      closeConfirm();
      form.dataset.invConfirmed = '1';
      form.requestSubmit();
    });
  })();
</script>
</body>
</html>
