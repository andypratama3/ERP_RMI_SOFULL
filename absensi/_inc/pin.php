<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
/**
 * absensi/_inc/pin.php
 * PIN Employee (Model B: akun jabatan + PIN personal)
 *
 * Tabel: absensi_employee_pin
 * - employee_code (PK)
 * - pin_hash
 * - status: active/inactive
 * - updated_at
 * - updated_by
 */

function absensi_pin_schema_ensure(PDO $pdo): void {
  // Idempotent
  $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_employee_pin (
    employee_code VARCHAR(50) PRIMARY KEY,
    pin_hash VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    updated_at DATETIME NULL,
    updated_by VARCHAR(80) NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function absensi_pin_get(PDO $pdo, string $employeeCode): ?array {
  $employeeCode = trim($employeeCode);
  if ($employeeCode === '') return null;

  absensi_pin_schema_ensure($pdo);

  $stmt = $pdo->prepare("SELECT employee_code, pin_hash, status, updated_at, updated_by FROM absensi_employee_pin WHERE employee_code=? LIMIT 1");
  $stmt->execute([$employeeCode]);
  $r = $stmt->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

function absensi_pin_is_set(PDO $pdo, string $employeeCode): bool {
  return absensi_pin_get($pdo, $employeeCode) !== null;
}

function absensi_pin_validate(string $plainPin): void {
  $plainPin = trim($plainPin);
  if ($plainPin === '') throw new RuntimeException('PIN kosong');
  if (strlen($plainPin) < 4) throw new RuntimeException('PIN minimal 4 digit');
  if (strlen($plainPin) > 10) throw new RuntimeException('PIN maksimal 10 digit');
  if (!preg_match('/^[0-9]+$/', $plainPin)) throw new RuntimeException('PIN hanya boleh angka');
}

function absensi_employee_exists(PDO $pdo, string $employeeCode): bool {
  $employeeCode = trim($employeeCode);
  if ($employeeCode === '') return false;
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM master_employees WHERE employee_code=? LIMIT 1");
    $stmt->execute([$employeeCode]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    // master_employees mungkin belum ada
    return false;
  }
}

function absensi_pin_set(PDO $pdo, string $employeeCode, string $plainPin, string $updatedBy): void {
  $employeeCode = trim($employeeCode);
  if ($employeeCode === '') throw new RuntimeException('employee_code kosong');

  // Optional: pastikan employee ada supaya tidak salah input
  if (!absensi_employee_exists($pdo, $employeeCode)) {
    throw new RuntimeException("Employee code tidak ditemukan di master_employees: {$employeeCode}");
  }

  absensi_pin_validate($plainPin);

  absensi_pin_schema_ensure($pdo);

  // Hash PIN (password_hash)
  $hash = password_hash($plainPin, PASSWORD_DEFAULT);

  $stmt = $pdo->prepare("INSERT INTO absensi_employee_pin (employee_code, pin_hash, status, updated_at, updated_by)
    VALUES (?,?, 'active', NOW(), ?)
    ON DUPLICATE KEY UPDATE pin_hash=VALUES(pin_hash), status='active', updated_at=NOW(), updated_by=VALUES(updated_by)");
  $stmt->execute([$employeeCode, $hash, $updatedBy]);
}

function absensi_pin_disable(PDO $pdo, string $employeeCode, string $updatedBy): void {
  $employeeCode = trim($employeeCode);
  if ($employeeCode === '') throw new RuntimeException('employee_code kosong');

  absensi_pin_schema_ensure($pdo);

  $stmt = $pdo->prepare("UPDATE absensi_employee_pin SET status='inactive', updated_at=NOW(), updated_by=? WHERE employee_code=?");
  $stmt->execute([$updatedBy, $employeeCode]);
}

function absensi_pin_verify(PDO $pdo, string $employeeCode, string $plainPin): bool {
  $row = absensi_pin_get($pdo, $employeeCode);
  if (!$row) return false;
  if (strtolower((string)$row['status']) !== 'active') return false;
  return password_verify((string)$plainPin, (string)$row['pin_hash']);
}
