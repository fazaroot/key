<?php
/**
 * sentinel_verifier.php
 * Endpoint Verifikasi Langganan Jarak Jauh (Remote Subscription Verification)
 *
 * File ini harus dihosting di server Anda yang terpisah.
 */

header('Content-Type: application/json');
date_default_timezone_set('Asia/Jakarta'); // Sesuaikan Zona Waktu Anda

// --- KONFIGURASI PENTING ---
// Kunci rahasia untuk otorisasi permintaan, mencegah akses publik yang mudah.
const SECRET_VERIFICATION_KEY = 'SENTINEL_PRO_2025_KEY'; 
const DB_FILE = 'subscription_db.json';
// -----------------------------

function load_subscription_db() {
    if (!file_exists(DB_FILE)) {
        // Buat file DB kosong jika belum ada
        file_put_contents(DB_FILE, json_encode([]));
        return [];
    }
    $data = file_get_contents(DB_FILE);
    return json_decode($data, true) ?? [];
}

function save_subscription_db($db) {
    file_put_contents(DB_FILE, json_encode($db, JSON_PRETTY_PRINT));
}

// ----------------------------------------------------
// Aksi: Update Database Langganan (Digunakan oleh Bot Telegram Anda)
// ----------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'update_sub') {
    $auth_key = $_POST['auth_key'] ?? '';
    $chat_id = $_POST['chat_id'] ?? '';
    $expiry_date = $_POST['expiry_date'] ?? ''; // Format YYYY-MM-DD
    $status = $_POST['status'] ?? 'active'; // active, expired, banned

    if ($auth_key !== SECRET_VERIFICATION_KEY) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access.']);
        exit;
    }

    if (empty($chat_id) || empty($expiry_date)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing Chat ID or Expiry Date.']);
        exit;
    }

    $db = load_subscription_db();
    
    $db[$chat_id] = [
        'expiry' => $expiry_date,
        'status' => $status,
        'last_update' => date('Y-m-d H:i:s'),
    ];

    save_subscription_db($db);

    echo json_encode(['status' => 'success', 'message' => "Subscription for $chat_id updated to $status, expires $expiry_date."]);
    exit;
}


// ----------------------------------------------------
// Aksi: Verifikasi Akses (Dipanggil oleh PHP-Sentinel)
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'verify_access') {
    $chat_id = $_GET['chat_id'] ?? '';
    $verification_key = $_GET['v_key'] ?? ''; // Kunci verifikasi dari Sentinel
    $current_date = date('Y-m-d');
    
    // Verifikasi Kunci Rahasia
    if ($verification_key !== SECRET_VERIFICATION_KEY) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid verification key.']);
        exit;
    }

    if (empty($chat_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing Chat ID.']);
        exit;
    }

    $db = load_subscription_db();
    
    if (!isset($db[$chat_id])) {
        http_response_code(403);
        echo json_encode(['status' => 'inactive', 'message' => 'Chat ID not found in database. Please subscribe.']);
        exit;
    }
    
    $user_data = $db[$chat_id];
    
    // Cek Status (Active vs Banned)
    if ($user_data['status'] === 'banned') {
        http_response_code(403);
        echo json_encode(['status' => 'banned', 'message' => 'This Chat ID has been permanently banned from the service.']);
        exit;
    }

    // Cek Tanggal Kedaluwarsa
    if ($user_data['expiry'] >= $current_date) {
        http_response_code(200);
        echo json_encode(['status' => 'active', 'message' => 'Subscription is Active.']);
        exit;
    } else {
        // Otomatis tandai sebagai expired di DB (Opsional, untuk konsistensi)
        if ($user_data['status'] !== 'expired') {
            $user_data['status'] = 'expired';
            $db[$chat_id] = $user_data;
            save_subscription_db($db);
        }
        
        http_response_code(403);
        echo json_encode(['status' => 'expired', 'message' => 'Subscription has expired. Please renew your payment.']);
        exit;
    }
}

// Default response jika tidak ada aksi yang valid
http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Action not specified or not found.']);
?>
