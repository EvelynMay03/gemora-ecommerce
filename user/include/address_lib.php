<?php

/**
 * Ensure addresses table has structured columns aligned with the checkout form.
 */
function address_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [
        'region' => 'VARCHAR(100) NULL',
        'city_township' => 'VARCHAR(100) NULL',
        'apartment_building' => 'VARCHAR(255) NULL',
        'street_address' => 'VARCHAR(255) NULL',
    ];

    foreach ($columns as $column => $definition) {
        try {
            $pdo->query('SELECT ' . $column . ' FROM addresses LIMIT 1');
        } catch (PDOException $e) {
            try {
                $pdo->exec('ALTER TABLE addresses ADD COLUMN ' . $column . ' ' . $definition);
            } catch (PDOException $ignored) {
            }
        }
    }

    $done = true;
}

/**
 * @return list<string>
 */
function address_myanmar_regions(): array
{
    return [
        'Yangon',
        'Mandalay',
        'Naypyidaw',
        'Bago',
        'Ayeyarwady',
        'Magway',
        'Sagaing',
        'Tanintharyi',
        'Shan',
        'Mon',
        'Kachin',
        'Kayah',
        'Kayin',
        'Chin',
        'Rakhine',
    ];
}

/**
 * @param array<string, mixed> $row
 */
function address_format_label(array $row): string
{
    $parts = [];
    $region = trim((string) ($row['region'] ?? ''));
    $city = trim((string) ($row['city_township'] ?? ''));
    $building = trim((string) ($row['apartment_building'] ?? ''));
    $street = trim((string) ($row['street_address'] ?? ''));

    if ($region !== '') {
        $parts[] = $region;
    }
    if ($city !== '') {
        $parts[] = $city;
    }
    if ($building !== '') {
        $parts[] = $building;
    }
    if ($street !== '') {
        $parts[] = $street;
    }

    if (!empty($parts)) {
        return implode(' · ', $parts);
    }

    return trim((string) ($row['name'] ?? ''));
}

/**
 * @return list<array<string, mixed>>
 */
function address_fetch_for_user(PDO $pdo, int $userId): array
{
    address_ensure_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT address_id, user_id, region, city_township, apartment_building, street_address, name
        FROM addresses
        WHERE user_id = ?
        ORDER BY address_id DESC
    ');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['display_label'] = address_format_label($row);
    }
    unset($row);

    return $rows;
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string, errors?: array<string, string>}
 */
function address_validate(array $input): array
{
    $errors = [];

    if (trim((string) ($input['region'] ?? '')) === '') {
        $errors['region'] = 'Select region.';
    }
    if (trim((string) ($input['city_township'] ?? '')) === '') {
        $errors['city_township'] = 'Enter city or township.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'message' => 'Please complete the address fields.', 'errors' => $errors];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string}
 */
function address_save(PDO $pdo, int $userId, array $input): array
{
    address_ensure_schema($pdo);

    $validation = address_validate($input);
    if (!$validation['ok']) {
        return $validation;
    }

    $region = trim((string) ($input['region'] ?? ''));
    $city = trim((string) ($input['city_township'] ?? ''));
    $building = trim((string) ($input['apartment_building'] ?? ''));
    $street = trim((string) ($input['street_address'] ?? ''));
    $label = address_format_label([
        'region' => $region,
        'city_township' => $city,
        'apartment_building' => $building,
        'street_address' => $street,
    ]);

    $pdo->prepare('
        INSERT INTO addresses (user_id, region, city_township, apartment_building, street_address, name)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        $userId,
        $region,
        $city,
        $building === '' ? null : $building,
        $street === '' ? null : $street,
        $label,
    ]);

    return ['ok' => true, 'message' => 'Address saved.'];
}
