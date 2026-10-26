<?php
header('Content-Type: application/json');
session_start();

// Safely handle DB inclusion
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

// Session checking with fallbacks
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? 0;
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Allow request if project_id is present or user is logged in
if ($user_id <= 0 && $project_id <= 0) {
    echo json_encode(['status' => 401, 'message' => 'Unauthorized user. Please login.']);
    exit;
}

try {
    // Fetch Project Title
    $project_title = "Project";
    if ($project_id > 0) {
        $sqlP = "SELECT title FROM projects WHERE project_id = ?";
        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sqlP);
            if ($stmt) {
                $stmt->bind_param("i", $project_id);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                if ($res) $project_title = $res['title'];
                $stmt->close();
            }
        } else if ($db instanceof PDO) {
            $stmt = $db->prepare($sqlP);
            $stmt->execute([$project_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) $project_title = $res['title'];
        }
    }

    // Fetch Talent Join Requests
    $talentRequests = [];
    
    $whereConditions = [];
    $params = [];
    $types = "";

    if ($project_id > 0) {
        $whereConditions[] = "j.project_id = ?";
        $params[] = $project_id;
        $types .= "i";
    }

    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

    $sqlTalent = "SELECT 
                    j.request_id, 
                    j.project_id, 
                    j.user_id, 
                    j.status, 
                    COALESCE(u.name, 'Applicant') as applicant_name, 
                    COALESCE(u.email, 'N/A') as applicant_email,
                    'Team Member' as role_applied,
                    'Talent Member' as applicant_role,
                    '95%' as match_score,
                    COALESCE(j.expected_salary, 25000) as expected_salary,
                    u.portfolio_url as portfolio_link
                  FROM joinrequests j
                  LEFT JOIN projects p ON j.project_id = p.project_id
                  LEFT JOIN users u ON j.user_id = u.user_id
                  $whereClause
                  ORDER BY j.request_id DESC";

    if ($db instanceof mysqli) {
        $stmt = $db->prepare($sqlTalent);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $talentRequests[] = $row;
            $stmt->close();
        }
    } else if ($db instanceof PDO) {
        $stmt = $db->prepare($sqlTalent);
        $stmt->execute($params);
        $talentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch Investor Funding Commitments
    $investorRequests = [];
    
    $whereInvestorConditions = [];
    $investorParams = [];
    $investorTypes = "";

    if ($project_id > 0) {
        $whereInvestorConditions[] = "i.project_id = ?";
        $investorParams[] = $project_id;
        $investorTypes .= "i";
    }

    $whereInvestorClause = !empty($whereInvestorConditions) ? "WHERE " . implode(" AND ", $whereInvestorConditions) : "";

    $sqlInvestor = "SELECT 
                        i.investment_id as commitment_id, 
                        i.project_id, 
                        i.investor_id, 
                        i.amount as committed_amount, 
                        i.status as escrow_status,
                        COALESCE(u.name, 'Investor') as investor_name, 
                        COALESCE(u.email, '') as investor_email
                    FROM investments i
                    LEFT JOIN projects p ON i.project_id = p.project_id
                    LEFT JOIN users u ON i.investor_id = u.user_id
                    $whereInvestorClause";

    if ($db instanceof mysqli) {
        $stmt = $db->prepare($sqlInvestor);
        if ($stmt) {
            if (!empty($investorParams)) {
                $stmt->bind_param($investorTypes, ...$investorParams);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $investorRequests[] = $row;
            $stmt->close();
        }
    } else if ($db instanceof PDO) {
        $stmt = $db->prepare($sqlInvestor);
        $stmt->execute($investorParams);
        $investorRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'status' => 200,
        'project_title' => $project_title,
        'talent_requests' => $talentRequests,
        'investor_commitments' => $investorRequests
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
}
?>


