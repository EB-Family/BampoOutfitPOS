<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = env('APP_ORIGIN', 'http://localhost:8000');
if (isset($_SERVER['HTTP_ORIGIN']) && hash_equals($allowedOrigin, $_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        error('Invalid JSON body', 422);
    }
    return $data;
}

function response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error(string $message, int $status = 400): never
{
    response(['error' => $message], $status);
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireAuth(): array
{
    $user = currentUser();
    if (!$user) {
        error('Authentication required', 401);
    }
    return $user;
}

function requireRole(array $roles): array
{
    $user = requireAuth();
    if (!in_array($user['role'], $roles, true)) {
        error('You do not have permission for this action', 403);
    }
    return $user;
}

function audit(PDO $pdo, array $user, string $action, string $entity, ?string $entityId = null, array $metadata = []): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity, entity_id, metadata) VALUES (:user_id, :action, :entity, :entity_id, CAST(:metadata AS jsonb))');
    $stmt->execute([
        'user_id' => $user['id'],
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entityId,
        'metadata' => json_encode($metadata),
    ]);
}

function route(): array
{
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
    $path = preg_replace('#^api/?#', '', $path);
    return array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'];
    $parts = route();
    $resource = $parts[0] ?? '';
    $action = $parts[1] ?? '';

    if ($resource === 'auth' && $action === 'login' && $method === 'POST') {
        $input = body();
        $stmt = $pdo->prepare('SELECT u.id, u.full_name, u.email, u.password_hash, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE lower(u.email) = lower(:email) AND u.is_active = true AND u.deleted_at IS NULL LIMIT 1');
        $stmt->execute(['email' => trim((string)($input['email'] ?? ''))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)($input['password'] ?? ''), $user['password_hash'])) {
            error('Invalid email or password', 401);
        }
        unset($user['password_hash']);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        response(['user' => $user]);
    }

    if ($resource === 'auth' && $action === 'me' && $method === 'GET') {
        response(['user' => currentUser()]);
    }

    if ($resource === 'auth' && $action === 'logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        response(['ok' => true]);
    }

    if ($resource === 'products' && $method === 'GET') {
        requireAuth();
        $stmt = $pdo->query('SELECT p.id, p.name, p.sku, p.purchase_price, p.selling_price, p.minimum_stock, c.name AS category, b.name AS brand, COALESCE(SUM(v.quantity), 0) AS stock FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id LEFT JOIN product_variants v ON v.product_id = p.id WHERE p.deleted_at IS NULL GROUP BY p.id, c.name, b.name ORDER BY p.name');
        response(['data' => $stmt->fetchAll()]);
    }

    if ($resource === 'sales' && $method === 'GET') {
        requireAuth();
        $stmt = $pdo->query('SELECT s.id, s.receipt_number, s.subtotal, s.discount, s.tax, s.total, s.status, s.sold_at, u.full_name AS cashier, c.full_name AS customer, pay.method AS payment_method FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN customers c ON c.id = s.customer_id LEFT JOIN LATERAL (SELECT method FROM payments WHERE sale_id = s.id ORDER BY paid_at DESC LIMIT 1) pay ON true ORDER BY s.sold_at DESC LIMIT 200');
        response(['data' => $stmt->fetchAll()]);
    }

    if ($resource === 'sales' && $method === 'POST') {
        $user = requireRole(['admin', 'manager', 'cashier']);
        $input = body();
        $items = $input['items'] ?? [];
        $paymentMethod = (string)($input['payment_method'] ?? 'cash');
        $paidAmount = (float)($input['paid_amount'] ?? 0);
        if (!is_array($items) || count($items) === 0) {
            error('At least one sale item is required', 422);
        }
        $allowedPayments = ['cash', 'mpesa', 'airtel_money', 'mixx_by_yas', 'tigo_pesa', 'bank', 'card'];
        if (!in_array($paymentMethod, $allowedPayments, true)) {
            error('Unsupported payment method', 422);
        }

        $pdo->beginTransaction();
        try {
            $subtotal = 0.0;
            $lockedItems = [];
            foreach ($items as $item) {
                $variantId = (string)($item['variant_id'] ?? '');
                $quantity = (int)($item['quantity'] ?? 0);
                if ($variantId === '' || $quantity < 1) {
                    error('Each sale item needs a valid variant and quantity', 422);
                }
                $stmt = $pdo->prepare('SELECT v.id, v.quantity, p.selling_price, p.purchase_price, p.name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = :id AND p.deleted_at IS NULL FOR UPDATE');
                $stmt->execute(['id' => $variantId]);
                $variant = $stmt->fetch();
                if (!$variant) {
                    error('Product variant not found', 404);
                }
                if ((int)$variant['quantity'] < $quantity) {
                    error("Insufficient stock for {$variant['name']}", 409);
                }
                $lineTotal = (float)$variant['selling_price'] * $quantity;
                $subtotal += $lineTotal;
                $lockedItems[] = [$variant, $quantity, $lineTotal];
            }
            $discount = max(0.0, (float)($input['discount'] ?? 0));
            $tax = max(0.0, (float)($input['tax'] ?? 0));
            $total = max(0.0, $subtotal - $discount + $tax);
            if ($paidAmount < $total) {
                error('Paid amount is less than the sale total', 422);
            }
            $receipt = 'BO-' . date('ymdHis') . '-' . random_int(100, 999);
            $stmt = $pdo->prepare('INSERT INTO sales (receipt_number, customer_id, cashier_id, subtotal, discount, tax, total) VALUES (:receipt, :customer_id, :cashier_id, :subtotal, :discount, :tax, :total) RETURNING id');
            $stmt->execute(['receipt' => $receipt, 'customer_id' => $input['customer_id'] ?? null, 'cashier_id' => $user['id'], 'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total]);
            $saleId = $stmt->fetchColumn();
            foreach ($lockedItems as [$variant, $quantity, $lineTotal]) {
                $stmt = $pdo->prepare('INSERT INTO sale_items (sale_id, variant_id, quantity, unit_price, purchase_price, total) VALUES (:sale_id, :variant_id, :quantity, :unit_price, :purchase_price, :total)');
                $stmt->execute(['sale_id' => $saleId, 'variant_id' => $variant['id'], 'quantity' => $quantity, 'unit_price' => $variant['selling_price'], 'purchase_price' => $variant['purchase_price'], 'total' => $lineTotal]);
                $pdo->prepare('UPDATE product_variants SET quantity = quantity - :quantity WHERE id = :id')->execute(['quantity' => $quantity, 'id' => $variant['id']]);
                $pdo->prepare("INSERT INTO stock_movements (variant_id, type, quantity, reason, reference_id, created_by) VALUES (:variant_id, 'sale', :quantity, 'POS sale', :reference_id, :created_by)")->execute(['variant_id' => $variant['id'], 'quantity' => -$quantity, 'reference_id' => $saleId, 'created_by' => $user['id']]);
            }
            $pdo->prepare('INSERT INTO payments (sale_id, method, amount) VALUES (:sale_id, :method, :amount)')->execute(['sale_id' => $saleId, 'method' => $paymentMethod, 'amount' => $paidAmount]);
            audit($pdo, $user, 'sale.created', 'sale', $saleId, ['receipt_number' => $receipt, 'total' => $total]);
            $pdo->commit();
            response(['data' => ['id' => $saleId, 'receipt_number' => $receipt, 'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total, 'change' => $paidAmount - $total]], 201);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    if ($resource === 'purchases' && $method === 'GET') {
        requireRole(['admin', 'manager', 'stock']);
        $stmt = $pdo->query('SELECT pu.id, pu.invoice_number, pu.total_cost, pu.payment_status, pu.purchased_at, s.name AS supplier FROM purchases pu LEFT JOIN suppliers s ON s.id = pu.supplier_id ORDER BY pu.purchased_at DESC LIMIT 200');
        response(['data' => $stmt->fetchAll()]);
    }

    if ($resource === 'stock' && $method === 'GET') {
        requireRole(['admin', 'manager', 'stock']);
        $stmt = $pdo->query('SELECT sm.id, sm.type, sm.quantity, sm.reason, sm.created_at, v.sku, p.name AS product, u.full_name AS created_by FROM stock_movements sm JOIN product_variants v ON v.id = sm.variant_id JOIN products p ON p.id = v.product_id JOIN users u ON u.id = sm.created_by ORDER BY sm.created_at DESC LIMIT 300');
        response(['data' => $stmt->fetchAll()]);
    }

    if ($resource === 'reports' && $method === 'GET') {
        requireRole(['admin', 'manager']);
        $sales = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE status = 'paid'")->fetchColumn();
        $cost = (float)$pdo->query("SELECT COALESCE(SUM(quantity * purchase_price), 0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status = 'paid'")->fetchColumn();
        $expenses = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses')->fetchColumn();
        response(['data' => ['sales' => $sales, 'cost_of_goods' => $cost, 'expenses' => $expenses, 'net_profit' => $sales - $cost - $expenses]]);
    }

    error('Route not found', 404);
} catch (Throwable $exception) {
    error(getenv('APP_DEBUG') === 'true' ? $exception->getMessage() : 'Server error', 500);
}
