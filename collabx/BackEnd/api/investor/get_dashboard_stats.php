<?php
header('Content-Type: application/json');
session_start();

if (file_exists(__DIR__ . '/../../config/db.php')) {
    require_once __DIR__ . '/../../config/db.php';
} else if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
}

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? 0;

if ($user_id <= 0) {
    echo json_encode(['status' => 401, 'message' => 'Unauthorized investor']);
    exit;
}

try {
    $totalInvested = 0;
    $activeProjectsCount = 0;
    $walletBalance = 0;
    $portfolio = [];
    $transactions = [];

    if ($db instanceof mysqli) {
        // 1. Total Invested Amount
        $res1 = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM investments WHERE investor_id = $user_id AND status != 'refunded'");
        if ($res1 && $row = $res1->fetch_assoc()) {
            $totalInvested = floatval($row['total']);
        }

        // 2. Active Projects Count
        $res2 = $db->query("SELECT COUNT(DISTINCT project_id) as total FROM investments WHERE investor_id = $user_id AND status != 'refunded'");
        if ($res2 && $row = $res2->fetch_assoc()) {
            $activeProjectsCount = intval($row['total']);
        }

        // 3. Wallet Balance
        $res3 = $db->query("SELECT balance FROM wallets WHERE user_id = $user_id");
        if ($res3 && $row = $res3->fetch_assoc()) {
            $walletBalance = floatval($row['balance']);
        }

        // 4. Portfolio
        $sqlPort = "SELECT i.investment_id, i.project_id, i.amount, i.equity_percentage, i.escrow_status, i.status, i.invested_at,
                           p.title as project_title, p.expected_roi 
                    FROM investments i 
                    JOIN projects p ON i.project_id = p.project_id 
                    WHERE i.investor_id = $user_id 
                    ORDER BY i.investment_id DESC";
        $resPort = $db->query($sqlPort);
        if ($resPort) {
            while ($r = $resPort->fetch_assoc()) {
                $portfolio[] = $r;
            }
        }

        // 5. Transactions
        $sqlTx = "SELECT t.transaction_id, t.type, t.amount, t.reference, t.created_at
                  FROM transactions t
                  JOIN wallets w ON t.wallet_id = w.wallet_id
                  WHERE w.user_id = $user_id
                  ORDER BY t.transaction_id DESC LIMIT 10";
        $resTx = $db->query($sqlTx);
        if ($resTx) {
            while ($r = $resTx->fetch_assoc()) {
                $transactions[] = $r;
            }
        }
    } else if ($db instanceof PDO) {
        // PDO Handling
        // 1. Total Invested Amount
        $stmt1 = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM investments WHERE investor_id = ? AND status != 'refunded'");
        $stmt1->execute([$user_id]);
        $totalInvested = floatval($stmt1->fetchColumn());

        // 2. Active Projects Count
        $stmt2 = $db->prepare("SELECT COUNT(DISTINCT project_id) as total FROM investments WHERE investor_id = ? AND status != 'refunded'");
        $stmt2->execute([$user_id]);
        $activeProjectsCount = intval($stmt2->fetchColumn());

        // 3. Wallet Balance
        $stmt3 = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
        $stmt3->execute([$user_id]);
        $walletBalance = floatval($stmt3->fetchColumn());

        // 4. Portfolio
        $stmt4 = $db->prepare("SELECT i.investment_id, i.project_id, i.amount, i.equity_percentage, i.escrow_status, i.status, i.invested_at,
                                      p.title as project_title, p.expected_roi 
                               FROM investments i 
                               JOIN projects p ON i.project_id = p.project_id 
                               WHERE i.investor_id = ? 
                               ORDER BY i.investment_id DESC");
        $stmt4->execute([$user_id]);
        $portfolio = $stmt4->fetchAll(PDO::FETCH_ASSOC);

        // 5. Transactions
        $stmt5 = $db->prepare("SELECT t.transaction_id, t.type, t.amount, t.reference, t.created_at
                               FROM transactions t
                               JOIN wallets w ON t.wallet_id = w.wallet_id
                               WHERE w.user_id = ?
                               ORDER BY t.transaction_id DESC LIMIT 10");
        $stmt5->execute([$user_id]);
        $transactions = $stmt5->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'status' => 200,
        'total_invested' => $totalInvested,
        'active_projects_count' => $activeProjectsCount,
        'wallet_balance' => $walletBalance,
        'portfolio' => $portfolio,
        'transactions' => $transactions
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
}
?>