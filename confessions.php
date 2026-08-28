<?php
session_start();

// Not logged in? Send them to the shared login gate instead of showing
// a popup on this page. It'll bring them right back here after login.
if (!isset($_SESSION['register_number'])) {
    $current_url = $_SERVER['REQUEST_URI']; 
    $page_title  = 'Confessions';

    header("Location: ../login_now.php?redirect=" . urlencode($current_url) . "&title=" . urlencode($page_title));
    exit();
}

// --- DATABASE CONNECTION ---
$servername = "localhost";
$username = "";
$password = "";
$dbname = "";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// --- USER IDENTIFICATION ---
$current_user_register_number = $_SESSION['register_number'] ?? null;
$current_user_name = $_SESSION['name'] ?? null;

// If reg number exists but no name, fetch it
if ($current_user_register_number && !$current_user_name) {
    $userQuery = $pdo->prepare("SELECT name FROM students WHERE register_number = ?");
    $userQuery->execute([$current_user_register_number]);
    $user = $userQuery->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $current_user_name = $user['name'];
        $_SESSION['name'] = $user['name'];
    }
}

// ============================================================
// ADVANCED CONFESSION SCHEDULE ENGINE
// All schedule decisions are made server-side in IST.
// ============================================================
date_default_timezone_set('Asia/Kolkata');
$tz = new DateTimeZone('Asia/Kolkata');
$now = new DateTime('now', $tz);
$todayDate = new DateTime('today', $tz);
$today = $todayDate->format('Y-m-d');
$dayOfWeek = (int)$todayDate->format('N');

$confSettingsQuery = $pdo->prepare("SELECT setting_name, setting_value FROM confession_settings");
$confSettingsQuery->execute();
$confSettings = $confSettingsQuery->fetchAll(PDO::FETCH_KEY_PAIR);
$maxSubmissions = max(1, min(50, (int)($confSettings['max_submissions'] ?? 2)));
$submissionDays = [5, 6];
if (!empty($confSettings['submission_days'])) {
    $legacyDays = array_values(array_filter(array_map('intval', explode(',', $confSettings['submission_days']))));
    $legacyDays = array_values(array_intersect(range(1,7), $legacyDays));
    if ($legacyDays) $submissionDays = $legacyDays;
}

$scheduleRules = []; 
$scheduleTableAvailable = true;
try {
    $q = $pdo->query("SELECT * FROM confession_schedule_rules WHERE is_enabled=1 ORDER BY priority ASC, id ASC");
    $scheduleRules = $q->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { 
    $scheduleTableAvailable = false; 
}

function confessionTimeValue($value, $fallback) {
    $value = (string)$value;
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value) ? (strlen($value) === 5 ? $value . ':00' : $value) : $fallback;
}
function confessionRuleDate(int $year, int $month, int $day): ?string {
    if ($day < 1 || $day > 31) return null;
    $last = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    if ($day > $last) $day = $last;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}
function confessionDateTime($date, $time = '00:00:00') { 
    return new DateTime($date . ' ' . confessionTimeValue($time, '00:00:00'), new DateTimeZone('Asia/Kolkata')); 
}
function confessionRuleDateMatches(array $rule, DateTime $date): bool {
    $todayStr = $date->format('Y-m-d'); 
    $type = $rule['rule_type'] ?? 'weekly';
    if ($type === 'weekly') return in_array((int)$date->format('N'), array_map('intval', array_filter(explode(',', (string)($rule['submission_days'] ?? '5,6')))), true);
    if ($type === 'this_week') { 
        $m = (clone $date)->modify('monday this week'); 
        $open = $rule['open_date'] ?? $m->format('Y-m-d'); 
        $close = $rule['close_date'] ?? $m->modify('+6 days')->format('Y-m-d'); 
        return $todayStr >= $open && $todayStr <= $close; 
    }
    if ($type === 'date_range') return !empty($rule['open_date']) && !empty($rule['close_date']) && $todayStr >= $rule['open_date'] && $todayStr <= $rule['close_date'];
    if ($type === 'monthly') { 
        $d = (int)$date->format('j'); 
        $open = max(1, min(31, (int)($rule['month_open_day'] ?? 1))); 
        $close = max($open, min(31, (int)($rule['month_close_day'] ?? $open))); 
        return $d >= $open && $d <= $close; 
    }
    return false;
}
function confessionRuleRevealAt(array $rule, DateTime $submissionDate): DateTime {
    $type = $rule['rule_type'] ?? 'weekly'; 
    $revealTime = confessionTimeValue($rule['reveal_time'] ?? '00:00:00', '00:00:00');
    if (($type === 'date_range' || $type === 'this_week') && !empty($rule['reveal_date'])) return confessionDateTime($rule['reveal_date'], $revealTime);
    if ($type === 'monthly') { 
        $day = max(1, min(31, (int)($rule['month_reveal_day'] ?? 7))); 
        $date = confessionRuleDate((int)$submissionDate->format('Y'), (int)$submissionDate->format('m'), $day) ?: $submissionDate->format('Y-m-d'); 
        return confessionDateTime($date, $revealTime); 
    }
    $target = max(1, min(7, (int)($rule['weekly_reveal_day'] ?? 7))); 
    $current = (int)$submissionDate->format('N'); 
    $delta = $target - $current; 
    if ($delta <= 0) $delta += 7; 
    $r = clone $submissionDate; 
    $r->modify("+{$delta} days"); 
    return confessionDateTime($r->format('Y-m-d'), $revealTime);
}
function confessionRuleWindow(array $rule, DateTime $now): array {
    $open = confessionTimeValue($rule['open_time'] ?? '00:00:00', '00:00:00'); 
    $close = confessionTimeValue($rule['close_time'] ?? '23:59:59', '23:59:59');
    $openDT = confessionDateTime($now->format('Y-m-d'), $open); 
    $closeDT = confessionDateTime($now->format('Y-m-d'), $close); 
    if ($closeDT < $openDT) $closeDT->modify('+1 day'); 
    return [$openDT, $closeDT];
}

function formatConfessionDaysList(array $days): string {
    $dayMap = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $days = array_values(array_unique(array_filter(array_map('intval', $days))));
    sort($days);
    if (empty($days)) return 'Scheduled Days';
    $names = [];
    foreach ($days as $d) {
        if (isset($dayMap[$d])) $names[] = $dayMap[$d];
    }
    if (count($names) === 1) return $names[0];
    if (count($names) === 2) return $names[0] . ' & ' . $names[1];
    $last = array_pop($names);
    return implode(', ', $names) . ' & ' . $last;
}

$activeSchedule = null; 
$isInputPeriod = false; 
$displayDate = $today; 
$displayRevealAt = null;

foreach ($scheduleRules as $rule) {
    if (confessionRuleDateMatches($rule, $todayDate)) { 
        [$openDT, $closeDT] = confessionRuleWindow($rule, $now); 
        if ($now >= $openDT && $now <= $closeDT) {
            $activeSchedule = $rule;
            $isInputPeriod = true;
            break;
        } 
    }
}

if (!$scheduleTableAvailable) {
    $isInputPeriod = in_array($dayOfWeek, $submissionDays, true);
    $activeSchedule = [
        'rule_name' => 'Standard Weekly Schedule',
        'rule_type' => 'weekly',
        'submission_days' => implode(',', $submissionDays),
        'weekly_reveal_day' => 7,
        'open_time' => '00:00:00',
        'close_time' => '23:59:59',
        'reveal_time' => '00:00:00',
        'max_submissions' => $maxSubmissions
    ];
}

if ($activeSchedule) {
    $maxSubmissions = max(1, min(50, (int)($activeSchedule['max_submissions'] ?? $maxSubmissions)));
    $scheduleRuleName = $activeSchedule['rule_name'] ?? 'Active confession schedule';
    $displayRevealAt = confessionRuleRevealAt($activeSchedule, $todayDate); 
    $displayDate = $displayRevealAt->format('Y-m-d');
} else {
    $scheduleRuleName = 'No active confession schedule';
}

$nextScheduleText = 'No upcoming opening configured.'; 
$nextRevealText = 'No upcoming reveal configured.'; 
$nextOpenAt = null; 
$nextRevealAt = null;

if ($scheduleRules) {
    for ($offset = 0; $offset <= 370; $offset++) {
        $candidate = (clone $todayDate)->modify("+{$offset} days");
        foreach ($scheduleRules as $rule) {
            if (!confessionRuleDateMatches($rule, $candidate)) continue;
            $candidateOpen = confessionDateTime($candidate->format('Y-m-d'), $rule['open_time'] ?? '00:00:00');
            if ($candidateOpen > $now && ($nextOpenAt === null || $candidateOpen < $nextOpenAt)) { 
                $nextOpenAt = $candidateOpen; 
                $nextScheduleText = $candidateOpen->format('D, M j · g:i A') . ' IST · ' . ($rule['rule_name'] ?? 'Confessions'); 
            }
            $candidateReveal = confessionRuleRevealAt($rule, $candidate);
            if ($candidateReveal > $now && ($nextRevealAt === null || $candidateReveal < $nextRevealAt)) { 
                $nextRevealAt = $candidateReveal; 
                $nextRevealText = $candidateReveal->format('D, M j · g:i A') . ' IST · ' . ($rule['rule_name'] ?? 'Confessions'); 
            }
        }
        if ($nextOpenAt && $nextRevealAt) break;
    }
}

if (!$scheduleTableAvailable) {
    $nextScheduleText = 'Next configured submission day';
    $nextRevealText = 'Sunday · 12:00 AM IST';
}

$revealWindowText = $displayRevealAt ? $displayRevealAt->format('l, M j, Y · g:i A') . ' IST' : 'Admin controlled';
$nextRevealFormatted = $revealWindowText;
$submissionWindowText = $activeSchedule ? ($activeSchedule['rule_name'] ?? 'Admin Schedule') : 'Admin schedule';
$openTimeLabel = $activeSchedule ? date('g:i A', strtotime('1970-01-01 ' . confessionTimeValue($activeSchedule['open_time'] ?? '00:00:00', '00:00:00'))) : '';
$closeTimeLabel = $activeSchedule ? date('g:i A', strtotime('1970-01-01 ' . confessionTimeValue($activeSchedule['close_time'] ?? '23:59:59', '23:59:59'))) : '';

// Days configuration
$dayNames = [
    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
    4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'
];

$activeDaysArray = [];
if (!empty($scheduleRules)) {
    foreach ($scheduleRules as $r) {
        if (!empty($r['submission_days'])) {
            $parsed = array_map('intval', explode(',', $r['submission_days']));
            $activeDaysArray = array_merge($activeDaysArray, $parsed);
        }
    }
}
if (empty($activeDaysArray)) {
    $activeDaysArray = $submissionDays;
}
$activeDaysArray = array_values(array_unique($activeDaysArray));
sort($activeDaysArray);

$formattedAllDaysString = formatConfessionDaysList($activeDaysArray);

// --- AJAX ENDPOINTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Check submission count for THIS WEEK (YEARWEEK ISO-8601 Mode 1)
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM confessions WHERE sender_register_number = ? AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)");
    $countStmt->execute([$current_user_register_number]);
    $currentSubmissionCount = (int)$countStmt->fetchColumn();

    if ($_POST['action'] === 'submit_confession') {
        if (!$isInputPeriod) {
            echo json_encode(['status' => 'error', 'message' => 'Submissions are currently closed.']);
            exit();
        }

        if ($currentSubmissionCount >= $maxSubmissions) {
            echo json_encode(['status' => 'error', 'message' => "Your weekly limit is reached ($maxSubmissions/week). See you next week!"]);
            exit();
        }

        $confessionText   = trim($_POST['confession_text'] ?? '');
        $instagramAccount = trim($_POST['instagram_account'] ?? '') ?: null;
        $registerNumber   = trim($_POST['register_number'] ?? '') ?: null;
        $newOptionalText  = trim($_POST['new_optional_text'] ?? '') ?: null;

        if ($instagramAccount && strpos($instagramAccount, '@') !== false) {
            echo json_encode(['status' => 'error', 'message' => "Please remove the '@' symbol from the Instagram username."]);
            exit();
        }

        if (empty($confessionText)) {
            echo json_encode(['status' => 'error', 'message' => 'Heart is empty? Please write something.']);
            exit();
        }

        // Random initial likes count (between 30 and 70)
        $initialFakeLikes = rand(30, 70);
        $shareToken = bin2hex(random_bytes(16));

        // Compute Month ID for the current month
        $monthKey = date('Y-m');
        $monthCountQuery = $pdo->prepare("SELECT COUNT(*) FROM confessions WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
        $monthCountQuery->execute([$monthKey]);
        $currentMonthCount = (int)$monthCountQuery->fetchColumn();
        $assignedMonthId = $currentMonthCount + 1;
        $assignedMonthName = date('F');
        $assignedMonthShort = substr($assignedMonthName, 0, 3);

        $stmt = $pdo->prepare("INSERT INTO confessions (confession_text, instagram_account, register_number, new_optional_text, sender_register_number, sender_name, display_date, reveal_at, share_token, like_count, fake_like_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())");
        $revealAtForInsert = $displayRevealAt ?: confessionDateTime($displayDate, $activeSchedule['reveal_time'] ?? '00:00:00');
        $stmt->execute([$confessionText, $instagramAccount, $registerNumber, $newOptionalText, $current_user_register_number, $current_user_name, $displayDate, $revealAtForInsert->format('Y-m-d H:i:s'), $shareToken, $initialFakeLikes]);
        $insertedId = $pdo->lastInsertId();

        $newCount = $currentSubmissionCount + 1;
        $formattedRevealDate = date('M j, Y', strtotime($displayDate));
        $confessionIdTag = $assignedMonthShort . ' #' . $assignedMonthId;

        echo json_encode([
            'status' => 'success',
            'title' => 'Confession Submitted! 🔒',
            'message' => 'Your secret is safely locked until the reveal date.',
            'reveal_date' => $formattedRevealDate,
            'confession_id_tag' => $confessionIdTag,
            'new_count' => $newCount,
            'limit_reached' => ($newCount >= $maxSubmissions),
            'confession' => [
                'id' => $insertedId,
                'confession_text' => $confessionText,
                'created_at' => date('d M Y'),
                'reveal_date' => $formattedRevealDate,
                'confession_id_tag' => $confessionIdTag,
                'like_count' => $initialFakeLikes,
                'share_token' => $shareToken
            ]
        ]);
        exit();
    }

    if ($_POST['action'] === 'delete_confession') {
        if (!$isInputPeriod) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete outside the submission period.']);
            exit();
        }

        $deleteId = $_POST['delete_confession_id'] ?? 0;
        
        $delStmt = $pdo->prepare("DELETE FROM confessions WHERE id = ? AND sender_register_number = ? AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)");
        $delStmt->execute([$deleteId, $current_user_register_number]);

        if ($delStmt->rowCount() > 0) {
            $newCount = max(0, $currentSubmissionCount - 1);
            echo json_encode([
                'status' => 'success',
                'title' => 'Deleted!',
                'message' => 'Your confession has been permanently removed.',
                'new_count' => $newCount
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Unable to delete confession or it is from a previous week.']);
        }
        exit();
    }

    if ($_POST['action'] === 'generate_share_token') {
        $confId = $_POST['confession_id'] ?? 0;
        
        $tokenStmt = $pdo->prepare("SELECT share_token, display_date, confession_text, sender_register_number FROM confessions WHERE id = ?");
        $tokenStmt->execute([$confId]);
        $row = $tokenStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Confession not found.']);
            exit();
        }

        $token = $row['share_token'];
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
            $updateToken = $pdo->prepare("UPDATE confessions SET share_token = ? WHERE id = ?");
            $updateToken->execute([$token, $confId]);
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $shareUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'])[0] . "?share_token=" . $token;

        echo json_encode([
            'status' => 'success', 
            'share_url' => $shareUrl, 
            'token' => $token,
            'snippet' => mb_strimwidth($row['confession_text'], 0, 100, "...")
        ]);
        exit();
    }
}

// --- HANDLE LIKES (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['like_confession_id'])) {
    $confessionId = $_POST['like_confession_id'];
    $userIp = $_SERVER['REMOTE_ADDR'];

    // Verify confession is revealed before accepting likes
    $checkRevealed = $pdo->prepare("SELECT display_date, reveal_at FROM confessions WHERE id = ?");
    $checkRevealed->execute([$confessionId]);
    $revealedRow = $checkRevealed->fetch(PDO::FETCH_ASSOC);
    $confRevealAt = $revealedRow['reveal_at'] ?? null;
    $isRevealedNow = $confRevealAt ? (strtotime($confRevealAt) <= $now->getTimestamp()) : (!empty($revealedRow['display_date']) && date('Y-m-d', strtotime($revealedRow['display_date'])) <= $today);

    if ($isRevealedNow) {
        $likeCheckQuery = $pdo->prepare("SELECT COUNT(*) FROM confession_likes WHERE confession_id = ? AND user_ip = ?");
        $likeCheckQuery->execute([$confessionId, $userIp]);

        if ($likeCheckQuery->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO confession_likes (confession_id, user_ip, liker_register_number, liker_name, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$confessionId, $userIp, $current_user_register_number, $current_user_name]);
            
            $updateLikes = $pdo->prepare("UPDATE confessions SET like_count = like_count + 1 WHERE id = ?");
            $updateLikes->execute([$confessionId]);
        }
    }
    
    $likeCountQuery = $pdo->prepare("SELECT (like_count + fake_like_count) AS total_like_count FROM confessions WHERE id = ?");
    $likeCountQuery->execute([$confessionId]);
    echo json_encode(['like_count' => $likeCountQuery->fetchColumn()]);
    exit();
}

// --- FETCH ALL ACTIVE & OLD CONFESSIONS FOR THE LOGGED-IN USER ---
$myConfessions = [];
$weeklySubmissionCount = 0;
$hasReachedLimit = false;

if ($current_user_register_number) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM confessions WHERE sender_register_number = ? AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)");
    $countStmt->execute([$current_user_register_number]);
    $weeklySubmissionCount = (int)$countStmt->fetchColumn();
    
    if ($weeklySubmissionCount >= $maxSubmissions) {
        $hasReachedLimit = true;
    }

    $myStmt = $pdo->prepare("SELECT *, (like_count + fake_like_count) AS total_like_count, (YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)) as is_current_week FROM confessions WHERE sender_register_number = ? ORDER BY created_at DESC");
    $myStmt->execute([$current_user_register_number]);
    $myConfessions = $myStmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- FETCH & PROCESS PUBLIC FEED (STRICTLY REVEALED ONLY) ---
$processedConfessions = [];
$filteredConfessions = [];
$availableMonths = [];

$confessionsQuery = $pdo->prepare("
    SELECT c.*, (c.like_count + c.fake_like_count) AS total_like_count 
    FROM confessions c 
    WHERE COALESCE(c.reveal_at, CONCAT(DATE(c.display_date), ' 00:00:00')) <= ? 
    ORDER BY c.created_at DESC
");
$confessionsQuery->execute([$now->format('Y-m-d H:i:s')]);
$allConfessions = $confessionsQuery->fetchAll(PDO::FETCH_ASSOC);

$groupedByMonth = [];
foreach ($allConfessions as $c) {
    $monthKey = date('Y-m', strtotime($c['created_at']));
    $groupedByMonth[$monthKey][] = $c;
}

foreach ($groupedByMonth as $mKey => $monthConfs) {
    $totalInMonth = count($monthConfs);
    foreach ($monthConfs as $c) {
        $c['month_id'] = $totalInMonth;
        $c['month_name'] = date('F', strtotime($c['created_at']));
        $c['year_val'] = date('Y', strtotime($c['created_at']));
        $c['full_month_key'] = $mKey;
        $processedConfessions[] = $c;
        $totalInMonth--;
    }
}

// Apply Feed Filters
$filterMonth = $_GET['filter_month'] ?? '';
$filterId    = $_GET['filter_id'] ?? '';
$shareTokenFilter = $_GET['share_token'] ?? '';

if (!empty($processedConfessions)) {
    foreach ($processedConfessions as $c) {
        $confDisplayDate = !empty($c['display_date']) ? date('Y-m-d', strtotime($c['display_date'])) : '1970-01-01';
        $confRevealAtTs = !empty($c['reveal_at']) ? strtotime($c['reveal_at']) : strtotime($confDisplayDate . ' 00:00:00');
        if ($confRevealAtTs === false || $confRevealAtTs > $now->getTimestamp()) {
            continue;
        }

        $matches = true;

        if (!empty($shareTokenFilter)) {
            if (($c['share_token'] ?? '') !== $shareTokenFilter) {
                $matches = false;
            }
        } else {
            if (!empty($filterMonth) && $c['full_month_key'] !== $filterMonth) {
                $matches = false;
            }
            if (!empty($filterId) && $c['month_id'] != $filterId) {
                $matches = false;
            }
        }

        if ($matches) {
            $filteredConfessions[] = $c;
        }
    }
}

$availableMonths = array_unique(array_column($processedConfessions, 'full_month_key'));
rsort($availableMonths); 

// --- TEXT FORMATTING FUNCTION ---
function formatConfessionText($text) {
    $loveWords = ['love', 'crush', 'heart', 'romance', 'forever', 'beautiful', 'cute', 'istam', 'prema', 'miss you', 'smile', 'eyes', 'soulmate', 'admirer', 'darling', 'dear'];
    foreach ($loveWords as $word) {
        $text = preg_replace("/\b(" . preg_quote($word, '/') . ")\b/i", '<span class="text-pink-500 font-bold drop-shadow-[0_0_8px_rgba(255,20,147,0.8)]">$1</span>', $text);
    }

    $text = preg_replace(
        '/\b(https?:\/\/[^\s]+)/i',
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-sky-400 font-medium hover:text-sky-300 underline decoration-sky-400/30 underline-offset-2 break-all relative z-10">$1</a>',
        $text
    );

    return $text;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Confessions | CONNECT SRMAP</title>
    <link rel="icon" href="https://oursrmap.purlyedit.in/logo.jpg" type="image/jpeg">
    <link rel="apple-touch-icon" href="https://oursrmap.purlyedit.in/logo.jpg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        dark: '#000000',
                        card: '#0a0a0a',
                        neon: '#ff1493',
                        soft: '#ff66b2',
                    },
                    animation: {
                        'beat': 'beat 1.5s infinite',
                        'float': 'float 3s ease-in-out infinite',
                        'pop': 'pop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275)',
                        'slide-up': 'slideUp 0.5s ease-out',
                        'zoom-in': 'zoomIn 0.4s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'float-up': 'floatUp 15s linear infinite',
                        'float-slow': 'floatSlow 6s ease-in-out infinite',
                    },
                    keyframes: {
                        beat: {
                            '0%, 100%': { transform: 'scale(1)' },
                            '50%': { transform: 'scale(1.1)' },
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' },
                        },
                        pop: {
                            '0%': { transform: 'scale(0.5)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        zoomIn: {
                            '0%': { transform: 'scale(0.9)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                        floatUp: {
                            '0%': { transform: 'translateY(100vh) scale(0.5)', opacity: '0' },
                            '10%': { opacity: '0.4' },
                            '90%': { opacity: '0.4' },
                            '100%': { transform: 'translateY(-10vh) scale(1.2)', opacity: '0' },
                        },
                        floatSlow: {
                            '0%, 100%': { transform: 'translateY(0) rotate(0deg)' },
                            '50%': { transform: 'translateY(-8px) rotate(5deg)' },
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #000; color: #fff; overflow-x: hidden; }
        .glow-text { text-shadow: 0 0 15px rgba(255, 20, 147, 0.6); }
        .modal-backdrop { backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .confession-content { white-space: pre-wrap; word-wrap: break-word; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        .heart-fall {
            position: fixed;
            top: -10%;
            color: rgba(255, 20, 147, 0.2);
            pointer-events: none;
            z-index: -1;
            animation: heartFall linear infinite;
        }
        @keyframes heartFall {
            0% { transform: translateY(0) rotate(0deg); opacity: 0; }
            20% { opacity: 0.5; }
            100% { transform: translateY(110vh) rotate(360deg); opacity: 0; }
        }

        .bottom-bar {
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .heart-liked {
            color: #ff0000 !important;
            text-shadow: 0 0 10px rgba(255, 0, 0, 0.5);
            animation: beat 2s infinite ease-in-out;
        }
        
        #reg-suggestions {
            scrollbar-width: thin;
            scrollbar-color: #ff1493 #1a1a1a;
        }
        #reg-suggestions::-webkit-scrollbar { width: 6px; }
        #reg-suggestions::-webkit-scrollbar-thumb { background-color: #ff1493; border-radius: 20px; }
        .suggestion-item:hover { background-color: rgba(255, 20, 147, 0.1); color: #ff1493; }

        .share-btn-glow {
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.2);
        }
        .share-btn-glow:hover {
            box-shadow: 0 0 25px rgba(56, 189, 248, 0.4);
        }
    </style>
</head>
<body class="antialiased pb-28 no-scrollbar relative">

    <div class="fixed inset-0 pointer-events-none overflow-hidden z-[-1]">
        <?php for($i=0; $i<15; $i++): ?>
            <i class="fas fa-heart heart-fall" style="left: <?php echo rand(0, 100); ?>%; animation-duration: <?php echo rand(10, 20); ?>s; animation-delay: <?php echo rand(0, 10); ?>s; font-size: <?php echo rand(10, 30); ?>px;"></i>
        <?php endfor; ?>
    </div>

    <div id="loading-screen" class="fixed inset-0 z-[1000] bg-black flex flex-col items-center justify-center transition-opacity duration-700">
        <div class="relative w-32 h-32 flex items-center justify-center">
            <div class="absolute inset-0 border-2 border-neon/20 rounded-full animate-[ping_2s_linear_infinite]"></div>
            <div class="absolute inset-2 border border-neon/40 rounded-full animate-[pulse_3s_ease-in-out_infinite]"></div>
            <i class="fas fa-heart text-6xl text-neon animate-beat drop-shadow-[0_0_20px_rgba(255,20,147,0.8)]"></i>
        </div>
        <div class="mt-10 text-center">
            <h2 class="text-white font-bold tracking-[0.3em] text-sm uppercase animate-pulse">Initializing</h2>
            <p class="text-gray-500 text-[10px] mt-2 tracking-widest uppercase">Connect SRMAP Secrets</p>
        </div>
    </div>

    <div class="sticky top-0 z-40 bg-black/80 backdrop-blur-xl border-b border-white/10 px-5 py-4 flex justify-between items-center shadow-lg shadow-pink-500/5">
        <a href="javascript:history.back()" 
           class="text-neon font-bold text-lg flex items-center gap-2 transition-transform hover:-translate-x-1">
            <i class="fas fa-arrow-left"></i> Secrets
        </a>

        <div class="flex gap-4">
            <a href="student.php"
               class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-gray-300 hover:bg-neon hover:text-white transition duration-300 border border-white/10">
                <i class="fas fa-home"></i>
            </a>
            
            <button onclick="toggleFilter()"
                class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-gray-300 hover:bg-neon hover:text-white transition duration-300 border border-white/10 relative">
                <i class="fas fa-filter"></i>
                <?php if(!empty($_GET['filter_month']) || !empty($_GET['filter_id']) || !empty($_GET['share_token'])): ?>
                    <span class="absolute top-0 right-0 w-3 h-3 bg-neon rounded-full border-2 border-black"></span>
                <?php endif; ?>
            </button>

            <button onclick="toggleHelp()"
                class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-gray-300 hover:bg-neon hover:text-white transition duration-300 border border-white/10">
                <i class="fas fa-question"></i>
            </button>
        </div>
    </div>

    <div id="filterOverlay" onclick="toggleFilter()" class="fixed inset-0 bg-black/50 hidden z-40 backdrop-blur-sm transition-opacity"></div>
    <div id="filterPopup" class="fixed top-20 right-16 bg-[#111] border border-white/10 p-6 rounded-2xl w-80 hidden shadow-2xl origin-top-right animate-zoom-in z-50">
        <div class="flex items-center justify-between border-b border-gray-800 pb-3 mb-4">
            <h4 class="text-white font-bold text-base">Filter Confessions</h4>
            <button onclick="toggleFilter()" class="text-gray-500 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        
        <form action="" method="get" class="space-y-4">
            <div>
                <label class="text-xs text-gray-400 mb-2 block uppercase tracking-wider font-bold">By Month</label>
                <div class="relative">
                    <select name="filter_month" class="w-full bg-gray-900 border border-gray-700 text-white text-sm rounded-xl px-4 py-3 focus:outline-none focus:border-neon appearance-none">
                        <option value="">All Months</option>
                        <?php foreach($availableMonths as $month): ?>
                            <option value="<?php echo $month; ?>" <?php echo (($_GET['filter_month'] ?? '') == $month) ? 'selected' : ''; ?>>
                                <?php echo date('F Y', strtotime($month . '-01')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="absolute right-3 top-3.5 text-gray-500 pointer-events-none">
                        <i class="fas fa-chevron-down text-xs"></i>
                    </div>
                </div>
            </div>
            
            <div>
                <label class="text-xs text-gray-400 mb-2 block uppercase tracking-wider font-bold">By ID Number</label>
                <div class="relative">
                    <span class="absolute left-4 top-3 text-gray-500 font-bold">#</span>
                    <input type="number" name="filter_id" value="<?php echo htmlspecialchars($_GET['filter_id'] ?? ''); ?>" placeholder="e.g. 1, 2" class="w-full bg-gray-900 border border-gray-700 text-white text-sm rounded-xl pl-8 pr-4 py-3 focus:outline-none focus:border-neon">
                </div>
            </div>
            
            <div class="pt-2 flex gap-2">
                <a href="confessions.php" class="flex-1 bg-gray-800 text-white py-3 rounded-xl text-sm font-bold text-center hover:bg-gray-700 transition">Reset</a>
                <button type="submit" class="flex-1 bg-neon text-white py-3 rounded-xl text-sm font-bold hover:bg-pink-600 transition">Apply</button>
            </div>
        </form>
    </div>

    <div id="helpOverlay" onclick="toggleHelp()" class="fixed inset-0 bg-black/50 hidden z-40 backdrop-blur-sm transition-opacity"></div>
    <div id="helpPopup" class="fixed top-20 right-4 bg-[#111] border border-white/10 p-6 rounded-2xl w-80 hidden shadow-2xl origin-top-right animate-zoom-in z-50">
        <div class="flex items-center justify-between border-b border-gray-800 pb-3 mb-4">
            <h4 class="text-white font-bold text-base">What Can You Share? 💌</h4>
            <button onclick="toggleHelp()" class="text-gray-500 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="space-y-4">
            <p class="text-xs text-gray-300 leading-relaxed">
                Got something on your mind? Share a secret, appreciate a friend, post a campus story, or send a warm anonymous message.
                The schedule is automatic—no manual date updates are required.
            </p>
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-gray-800 flex-shrink-0 flex items-center justify-center text-xs font-bold text-gray-400">1</div>
                <p class="text-gray-400 text-sm leading-snug">Submission days, opening/closing times, and reveal timing are shown from the active admin schedule above.</p>
            </div>
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-gray-800 flex-shrink-0 flex items-center justify-center text-xs font-bold text-gray-400">2</div>
                <p class="text-gray-400 text-sm leading-snug">Post up to <b class="text-neon">2 anonymous messages</b> per week.</p>
            </div>
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-gray-800 flex-shrink-0 flex items-center justify-center text-xs font-bold text-gray-400">3</div>
                <p class="text-gray-400 text-sm leading-snug">Tag an Instagram username or mention a student using their Reg No.</p>
            </div>
            <div class="flex gap-3">
                <div class="w-6 h-6 rounded-full bg-gray-800 flex-shrink-0 flex items-center justify-center text-xs font-bold text-gray-400">4</div>
                <p class="text-gray-400 text-sm leading-snug">Reveal date is controlled by the active admin schedule.</p>
            </div>
            <div class="mt-4 pt-3 border-t border-gray-800 text-center">
                <span class="text-[10px] text-gray-600 uppercase font-bold tracking-widest"><i class="fas fa-shield-alt mr-1"></i> 100% Encrypted & Anonymous</span>
            </div>
        </div>
    </div>

    <!-- SUCCESS MODAL -->
    <div id="successModal" class="fixed inset-0 z-[999] hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4 transition-all duration-300">
        <div class="relative w-full max-w-[320px] bg-[#121212] border border-white/10 rounded-2xl p-5 shadow-2xl animate-pop text-left">
            <h2 id="modalTitle" class="text-base font-bold text-white tracking-wide mb-1 flex items-center gap-1.5">
                Confession Locked 🔒
            </h2>
            <p id="modalMessage" class="text-gray-400 text-xs leading-relaxed font-normal mb-3">
                Your confession has been submitted.
            </p>
            <div id="modalDateBadge" class="hidden flex-col gap-1.5 bg-pink-500/10 border border-neon/30 text-neon rounded-xl p-3 mb-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-eye text-[11px]"></i>
                    <span class="text-[11px] font-medium leading-tight">Reveals on <b id="modalRevealDateText" class="font-bold text-white tracking-wide ml-0.5"></b></span>
                </div>
                <div id="modalConfIdBox" class="flex items-center gap-1.5 pt-1 border-t border-neon/20">
                    <i class="fas fa-hashtag text-[10px] text-neon"></i>
                    <span class="text-[11px] font-bold text-white">Your ID: <span id="modalConfIdText" class="text-neon"></span></span>
                </div>
            </div>
            <div class="flex items-center justify-end">
                <button onclick="closeModal()" class="px-5 py-2 bg-neon hover:bg-pink-600 text-white font-semibold text-xs rounded-xl shadow-md transition-all active:scale-95">
                    Got it
                </button>
            </div>
        </div>
    </div>

    <!-- FULL SECRET MODAL WITH COMPACT ACTION BAR (LIKE + SHARE) -->
    <div id="viewMoreModal" class="fixed inset-0 z-[999] hidden flex-col items-center justify-center bg-black/95 modal-backdrop transition-all duration-300">
        <div class="w-full h-full flex flex-col relative p-0 animate-slide-up">
            <button onclick="closeViewModal()" class="absolute top-6 right-6 z-20 w-11 h-11 bg-gray-900/80 rounded-full flex items-center justify-center text-white hover:bg-neon transition-colors backdrop-blur-md border border-white/10 shadow-lg">
                <i class="fas fa-times text-lg"></i>
            </button>
            <div class="flex-1 overflow-y-auto p-6 md:p-10 flex items-center justify-center no-scrollbar">
                <div class="max-w-2xl w-full my-auto pb-24">
                    <i class="fas fa-quote-left text-3xl text-gray-800 mb-4 block"></i>
                    <div id="full-confession-content" class="text-base md:text-lg text-gray-200 font-light leading-relaxed whitespace-pre-wrap word-wrap break-word"></div>
                    <i class="fas fa-quote-right text-3xl text-gray-800 mt-4 block text-right"></i>
                </div>
            </div>

            <!-- COMPACT FLOATING BAR: SHARE & LIKE -->
            <div id="modalActionsContainer" class="fixed bottom-8 right-6 z-30 flex items-center gap-3">
                <button id="modalShareBtn" onclick="shareModalConfession(this)" class="bg-gray-900/90 border border-sky-400/30 hover:border-sky-400 text-gray-300 hover:text-white px-4 py-2.5 rounded-full shadow-[0_0_15px_rgba(56,189,248,0.25)] flex items-center gap-2 hover:bg-sky-500/20 transition-all duration-300 active:scale-90 backdrop-blur-md group">
                    <i class="fas fa-paper-plane text-sm text-sky-400 group-hover:scale-110 transition-transform"></i>
                    <span class="text-xs font-semibold tracking-wider text-sky-300 group-hover:text-white">Share</span>
                </button>

                <button id="modalLikeBtn" onclick="likeModalConfession(this)" class="bg-gray-900/90 border border-neon/40 text-white px-4 py-2.5 rounded-full shadow-[0_0_20px_rgba(255,20,147,0.3)] flex items-center gap-2 hover:bg-neon/20 transition-all duration-300 active:scale-90 group backdrop-blur-md">
                    <i id="modalLikeIcon" class="far fa-heart text-sm text-neon group-hover:text-white transition-colors"></i>
                    <span id="modalLikeCount" class="text-xs font-bold tracking-wider">0</span>
                </button>
            </div>
        </div>
    </div>

    <!-- HIGH Z-INDEX SHARE POPUP MODAL -->
    <div id="shareModal" class="fixed inset-0 z-[1100] hidden items-center justify-center bg-black/85 backdrop-blur-xl p-4 transition-all duration-300">
        <div class="relative w-full max-w-sm bg-[#111111] border border-white/15 rounded-3xl p-6 shadow-[0_0_50px_rgba(255,20,147,0.25)] animate-pop text-center overflow-hidden">
            <button onclick="closeShareModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white w-8 h-8 rounded-full bg-white/5 flex items-center justify-center transition">
                <i class="fas fa-times text-sm"></i>
            </button>

            <div class="relative w-16 h-16 mx-auto mb-4">
                <span class="absolute inset-0 rounded-2xl bg-sky-500/20 animate-ping"></span>
                <div class="relative w-16 h-16 bg-gradient-to-br from-sky-500/20 to-sky-500/5 border border-sky-500/30 rounded-2xl flex items-center justify-center shadow-[0_0_25px_rgba(56,189,248,0.25)]">
                    <i class="fas fa-share-nodes text-2xl text-sky-400"></i>
                </div>
            </div>

            <div class="w-8 h-0.5 bg-gradient-to-r from-transparent via-sky-400 to-transparent mx-auto mb-3"></div>
            <h3 class="text-lg font-bold text-white mb-1 tracking-wide">Share Confession</h3>
            <p class="text-xs text-gray-400 mb-5">Spread the secret, keep it anonymous.</p>

            <div class="bg-black/60 border border-white/10 hover:border-sky-500/20 rounded-2xl p-3 mb-5 text-left transition-colors">
                <div class="border-l-2 border-sky-500/40 pl-2.5 mb-3">
                    <p id="shareSnippetText" class="text-xs text-gray-300 line-clamp-2 italic font-light">"..."</p>
                </div>
                <div class="flex items-center gap-2 bg-gray-900 border border-gray-800 rounded-xl px-3 py-2">
                    <i class="fas fa-link text-gray-600 text-[10px]"></i>
                    <input type="text" id="shareUrlInput" readonly class="bg-transparent text-[11px] text-gray-400 w-full focus:outline-none select-all font-mono">
                    <button onclick="copyShareUrl()" id="copyShareBtn" class="bg-sky-500 hover:bg-sky-400 text-black font-bold text-[10px] px-3 py-1.5 rounded-lg transition-all flex items-center gap-1 flex-shrink-0 active:scale-90">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-4 gap-3 mb-4">
                <a id="shareWhatsappBtn" href="#" target="_blank" class="flex flex-col items-center gap-1.5 group">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 group-hover:bg-emerald-500 group-hover:text-black text-emerald-400 flex items-center justify-center transition-all duration-300 shadow-lg group-hover:scale-105 group-hover:-translate-y-0.5">
                        <i class="fab fa-whatsapp text-2xl"></i>
                    </div>
                    <span class="text-[10px] text-gray-400 group-hover:text-white font-medium">WhatsApp</span>
                </a>

                <a id="shareInstagramBtn" onclick="shareToInstagram(event)" href="#" class="flex flex-col items-center gap-1.5 group">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-amber-500/20 via-rose-500/20 to-purple-500/20 border border-rose-500/30 group-hover:from-amber-500 group-hover:via-rose-500 group-hover:to-purple-500 group-hover:text-white text-rose-400 flex items-center justify-center transition-all duration-300 shadow-lg group-hover:scale-105 group-hover:-translate-y-0.5">
                        <i class="fab fa-instagram text-2xl"></i>
                    </div>
                    <span class="text-[10px] text-gray-400 group-hover:text-white font-medium">Instagram</span>
                </a>

                <a id="shareTelegramBtn" href="#" target="_blank" class="flex flex-col items-center gap-1.5 group">
                    <div class="w-12 h-12 rounded-2xl bg-sky-500/10 border border-sky-500/30 group-hover:bg-sky-500 group-hover:text-black text-sky-400 flex items-center justify-center transition-all duration-300 shadow-lg group-hover:scale-105 group-hover:-translate-y-0.5">
                        <i class="fab fa-telegram-plane text-2xl"></i>
                    </div>
                    <span class="text-[10px] text-gray-400 group-hover:text-white font-medium">Telegram</span>
                </a>

                <button onclick="triggerNativeShare()" class="flex flex-col items-center gap-1.5 group">
                    <div class="w-12 h-12 rounded-2xl bg-pink-500/10 border border-neon/30 group-hover:bg-neon group-hover:text-white text-neon flex items-center justify-center transition-all duration-300 shadow-lg group-hover:scale-105 group-hover:-translate-y-0.5">
                        <i class="fas fa-ellipsis-h text-xl"></i>
                    </div>
                    <span class="text-[10px] text-gray-400 group-hover:text-white font-medium">More</span>
                </button>
            </div>

            <p class="text-[10px] text-gray-500 uppercase tracking-widest font-bold">Connect SRMAP Secrets</p>
        </div>
    </div>

    <div class="w-full max-w-2xl mx-auto px-4 mt-6 md:mt-10 relative z-10">
        <div class="text-center mb-8">
            <h1 class="text-3xl md:text-4xl font-black text-white mb-2 glow-text tracking-tight">Confessions</h1>
            <p class="text-gray-400 text-xs md:text-sm font-light">Speak your heart. <span class="text-neon font-medium">100% Anonymous.</span></p>
        </div>

        <?php if (!empty($shareTokenFilter)): ?>
            <!-- SINGLE CONFESSION SHARE FILTER NOTICE BANNER -->
            <div class="bg-neon/10 border border-neon/40 rounded-2xl p-4 mb-6 flex items-center justify-between gap-3 shadow-[0_0_20px_rgba(255,20,147,0.15)] animate-pop">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-link text-neon text-sm"></i>
                    <span class="text-xs text-gray-200 font-medium">Viewing shared confession</span>
                </div>
                <a href="confessions.php" class="bg-neon text-white px-3 py-1.5 rounded-xl text-xs font-bold hover:bg-pink-600 transition-colors shadow-md">
                    Show All Confessions
                </a>
            </div>
        <?php endif; ?>

        <!-- ENHANCED SCHEDULE STATUS CARD -->
        <div class="mb-6">
            <?php
                $compactOpenText = $isInputPeriod
                    ? ($openTimeLabel ?: 'Open')
                    : ($nextOpenAt ? $nextOpenAt->format('D, M j · g:i A') . ' IST' : 'Not scheduled');

                $compactCloseText = $isInputPeriod
                    ? ($closeTimeLabel ?: '11:59 PM')
                    : '—';

                $compactRevealText = $isInputPeriod
                    ? ($nextRevealAt ? $nextRevealAt->format('D, M j · g:i A') . ' IST' : $nextRevealFormatted)
                    : ($nextRevealAt ? $nextRevealAt->format('D, M j · g:i A') . ' IST' : $nextRevealText);
            ?>

            <div class="bg-gradient-to-b from-[#151515] to-[#0a0a0a] border border-white/10 rounded-2xl p-4 md:p-5 shadow-2xl relative overflow-hidden">
                <!-- Status & Active Days Highlight Header -->
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3.5 border-b border-white/5 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center <?php echo $isInputPeriod ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 shadow-[0_0_15px_rgba(16,185,129,0.2)]' : 'bg-rose-500/15 text-rose-400 border border-rose-500/30'; ?>">
                            <i class="fas <?php echo $isInputPeriod ? 'fa-lock-open text-sm' : 'fa-lock text-sm'; ?>"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs md:text-sm font-bold text-white tracking-wide">
                                    <?php echo $isInputPeriod ? 'Submissions Open Today' : 'Submissions Closed'; ?>
                                </span>
                                <span class="relative flex h-2.5 w-2.5">
                                    <span class="<?php echo $isInputPeriod ? 'animate-ping bg-emerald-400' : 'bg-rose-400'; ?> absolute inline-flex h-full w-full rounded-full opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 <?php echo $isInputPeriod ? 'bg-emerald-500' : 'bg-rose-500'; ?>"></span>
                                </span>
                            </div>
                            <p class="text-[11px] text-pink-300/90 font-semibold mt-0.5 flex items-center gap-1">
                                <i class="far fa-calendar-check text-[10px] text-neon"></i> Every <span class="text-white font-bold"><?php echo htmlspecialchars($formattedAllDaysString); ?></span>
                            </p>
                        </div>
                    </div>

                    <!-- Clean Day Indicators (Mon-Sun) -->
                    <div class="flex items-center gap-1.5 self-stretch sm:self-auto justify-between sm:justify-start bg-black/50 px-3 py-2 rounded-xl border border-white/10">
                        <?php 
                        $shortDays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                        foreach ($shortDays as $num => $dayLetter): 
                            $isAllowedDay = in_array($num, $activeDaysArray, true);
                            $isToday = ($num === $dayOfWeek);
                        ?>
                            <div class="flex flex-col items-center justify-center px-1.5 py-1 rounded-lg text-[9px] md:text-[10px] font-bold transition-all <?php 
                                if ($isToday && $isInputPeriod) {
                                    echo 'bg-neon text-white shadow-[0_0_12px_rgba(255,20,147,0.9)] ring-1 ring-white/40 scale-105';
                                } elseif ($isAllowedDay) {
                                    echo 'bg-pink-500/20 text-neon border border-neon/30';
                                } else {
                                    echo 'text-gray-600 bg-white/[0.02]';
                                }
                            ?>">
                                <?php echo $dayLetter; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Schedule Key Info Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 mt-3.5">
                    <div class="bg-black/40 border border-white/5 rounded-xl p-2.5">
                        <span class="text-[9px] font-bold uppercase text-gray-500 tracking-wider flex items-center gap-1.5">
                            <i class="fas fa-door-open text-[8px] text-sky-400"></i> Open
                        </span>
                        <p class="text-white text-xs font-semibold mt-0.5 truncate"><?php echo htmlspecialchars($compactOpenText); ?></p>
                    </div>
                    
                    <div class="bg-black/40 border border-white/5 rounded-xl p-2.5">
                        <span class="text-[9px] font-bold uppercase text-gray-500 tracking-wider flex items-center gap-1.5">
                            <i class="fas fa-door-closed text-[8px] text-amber-400"></i> <?php echo $isInputPeriod ? 'Closes' : 'Next Open'; ?>
                        </span>
                        <p class="text-white text-xs font-semibold mt-0.5 truncate"><?php echo htmlspecialchars($isInputPeriod ? $compactCloseText : $compactOpenText); ?></p>
                    </div>

                    <div class="col-span-2 sm:col-span-1 bg-neon/10 border border-neon/25 rounded-xl p-2.5">
                        <span class="text-[9px] font-bold uppercase text-pink-400 tracking-wider flex items-center gap-1.5">
                            <i class="fas fa-sparkles text-[8px] text-neon"></i> Secret Reveal
                        </span>
                        <p class="text-neon text-xs font-bold mt-0.5 truncate"><?php echo htmlspecialchars($compactRevealText); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isInputPeriod): ?>
            <!-- SUBMISSION FORM -->
            <div id="formContainer" class="<?php echo $hasReachedLimit ? 'hidden' : ''; ?> bg-[#0a0a0a] border border-white/10 rounded-3xl p-5 md:p-6 shadow-2xl relative overflow-hidden group hover:border-neon/30 transition-colors duration-500 mb-8">
                <div class="bg-gradient-to-r from-pink-500/10 via-purple-500/10 to-transparent p-3.5 rounded-2xl border border-pink-500/20 mb-4 flex items-start gap-3 shadow-lg">
                    <div class="w-8 h-8 rounded-xl bg-neon/20 flex items-center justify-center flex-shrink-0 text-neon border border-neon/30">
                        <i class="fas fa-wand-magic-sparkles text-xs"></i>
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-white tracking-wide mb-0.5 flex items-center gap-1.5">
                            Share Anything Anonymously <span class="text-neon">✨</span>
                        </h4>
                        <p class="text-[11px] text-gray-400 leading-snug">
                            Have a secret crush? Want to tell a friend how awesome they are, share a funny campus moment, or express feelings you can't say in person? Type away—your identity stays completely safe.
                        </p>
                    </div>
                </div>

                <form id="confessionForm" onsubmit="handleConfessionSubmit(event)" autocomplete="off" class="space-y-4 relative z-10">
                    <div class="flex justify-between items-center text-[10px] md:text-xs text-gray-500 uppercase tracking-widest font-bold">
                        <span>New Entry (This Week)</span>
                        <span id="submissionCounterDisplay" class="<?php echo $weeklySubmissionCount > 0 ? 'text-neon' : 'text-gray-500'; ?>"><?php echo $weeklySubmissionCount; ?>/<?php echo $maxSubmissions; ?> Weekly Slots Used</span>
                    </div>

                    <div class="relative">
                        <textarea name="confession_text" id="confession_text" class="w-full bg-[#111] border border-white/10 rounded-2xl p-4 text-white placeholder-gray-600 focus:outline-none focus:border-neon focus:ring-1 focus:ring-neon transition-all duration-300 min-h-[140px] text-xs md:text-sm leading-relaxed resize-none" placeholder="Confess a crush, thank a special friend, share a secret story..." required></textarea>
                        <i class="fas fa-pen-fancy absolute right-4 bottom-4 text-gray-700 pointer-events-none text-xs"></i>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[11px] text-gray-500 mb-1 ml-1 block font-medium">Instagram Username (Optional)</label>
                            <div class="relative group">
                                <div class="absolute left-3 top-2.5 w-5 h-5 flex items-center justify-center bg-gray-800 rounded-md group-focus-within:bg-pink-500/20 transition-colors">
                                    <i class="fab fa-instagram text-pink-500 text-[10px]"></i>
                                </div>
                                <input type="text" name="instagram_account" id="instagram_account" class="w-full bg-[#111] border border-white/10 rounded-xl py-2 pl-10 pr-3 text-xs text-white focus:border-neon focus:outline-none transition-colors" placeholder="username_only">
                            </div>
                        </div>
                        <div class="relative">
                            <label class="text-[11px] text-gray-500 mb-1 ml-1 block font-medium">Mention Person (Optional)</label>
                            <div class="relative group">
                                <div class="absolute left-3 top-2.5 w-5 h-5 flex items-center justify-center bg-gray-800 rounded-md group-focus-within:bg-neon/20 transition-colors">
                                    <i class="fas fa-id-badge text-neon text-[10px]"></i>
                                </div>
                                <input type="text" name="register_number" id="register_number" class="w-full bg-[#111] border border-white/10 rounded-xl py-2 pl-10 pr-3 text-xs text-white focus:border-neon focus:outline-none transition-colors" placeholder="Name or Reg No...">
                            </div>
                            <div id="reg-suggestions" class="absolute w-full bg-[#1a1a1a] border border-gray-700 rounded-xl mt-2 max-h-40 overflow-y-auto hidden z-50 shadow-2xl"></div>
                        </div>
                    </div>

                    <div>
                        <label class="text-[11px] text-gray-500 mb-1 ml-1 block font-medium">Extra Hint / Note (Optional)</label>
                        <input type="text" name="new_optional_text" id="new_optional_text" class="w-full bg-[#111] border border-white/10 rounded-xl py-2 px-3 text-xs text-white focus:border-neon focus:outline-none transition-colors" placeholder="E.g., 'To the girl in blue shirt at library'">
                    </div>

                    <button type="submit" id="submit_btn" class="w-full bg-white text-black hover:bg-neon hover:text-white font-bold py-3 rounded-xl shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group text-sm">
                        <span>Submit Securely</span>
                        <i class="fas fa-paper-plane group-hover:translate-x-1 transition-transform text-xs"></i>
                    </button>
                </form>
            </div>

            <!-- LIMIT REACHED NOTICE -->
            <div id="limitNotice" class="<?php echo $hasReachedLimit ? '' : 'hidden'; ?> bg-gray-900/40 border border-gray-800 rounded-3xl p-8 text-center backdrop-blur-sm mb-8">
                <div class="w-16 h-16 bg-gray-800/50 rounded-full flex items-center justify-center mx-auto mb-4 text-neon ring-4 ring-gray-900">
                    <i class="fas fa-calendar-week text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Weekly Limit Reached</h3>
                <p class="text-gray-400 text-xs mb-4 leading-relaxed">Your current limit is completed (<b><?php echo (int)$weeklySubmissionCount; ?>/<?php echo (int)$maxSubmissions; ?> confessions</b>). <br><span class="text-neon font-medium">See you next week!</span></p>
            </div>
        <?php endif; ?>

        <!-- USER SUBMITTED CONFESSIONS -->
        <div id="myConfessionsWrapper" class="<?php echo empty($myConfessions) ? 'hidden' : ''; ?> mb-12 animate-slide-up">
            <div class="flex items-center justify-between mb-4 px-1">
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <span class="bg-neon/10 p-1.5 rounded-full"><i class="fas fa-user-secret text-neon text-xs"></i></span> 
                    Your Submitted Confessions
                </h3>
            </div>
            
            <div id="myConfessionsList" class="grid gap-3">
                <?php foreach($myConfessions as $myC): 
                    $confDisplayDate = !empty($myC['display_date']) ? date('Y-m-d', strtotime($myC['display_date'])) : '1970-01-01';
                    $confRevealAtTs = !empty($myC['reveal_at']) ? strtotime($myC['reveal_at']) : strtotime($confDisplayDate . ' 00:00:00');
                    $isRevealed = ($confRevealAtTs !== false && $confRevealAtTs <= $now->getTimestamp());
                    
                    $feedIdDisplay = "Locked (Reveals on " . date('M j', strtotime($confDisplayDate)) . ")";
                    $isLive = false;
                    $feedTargetId = "";
                    $confMonthTag = "";
                    
                    if ($isRevealed && isset($processedConfessions)) {
                        $key = array_search($myC['id'], array_column($processedConfessions, 'id'));
                        if ($key !== false) {
                            $match = $processedConfessions[$key];
                            $confMonthTag = substr($match['month_name'], 0, 3) . ' #' . $match['month_id'];
                            $feedIdDisplay = $confMonthTag;
                            $isLive = true;
                            $feedTargetId = "conf-" . $match['id'];
                        }
                    } else {
                        $confMonthTag = substr(date('F', strtotime($myC['created_at'])), 0, 3) . ' (Locked)';
                    }

                    $hasBinIcon = ($isInputPeriod && $myC['is_current_week']);
                ?>
                    <div id="my-conf-<?php echo $myC['id']; ?>" class="group relative bg-gray-900/60 backdrop-blur-md border border-white/5 rounded-xl p-4 hover:border-neon/30 transition-all duration-300 shadow-lg cursor-pointer" onclick='openViewModal(<?php echo json_encode(htmlspecialchars($myC["confession_text"])); ?>, <?php echo $myC["id"]; ?>, <?php echo (int)($myC["total_like_count"] ?? 0); ?>, <?php echo json_encode($myC["share_token"] ?? ""); ?>, <?php echo $isRevealed ? "true" : "false"; ?>)'>
                        <div class="flex justify-between items-start gap-3">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2 flex-wrap">
                                    <span class="bg-gray-800 text-gray-400 text-[9px] font-bold px-1.5 py-0.5 rounded border border-gray-700 tracking-wider">
                                        <i class="far fa-calendar-alt mr-1"></i> <?php echo date('d M Y', strtotime($myC['created_at'])); ?>
                                    </span>
                                    <?php if ($isLive && $feedTargetId): ?>
                                        <a href="#<?php echo $feedTargetId; ?>" onclick="event.stopPropagation();" class="text-neon hover:underline text-[10px] font-black italic tracking-wider flex items-center gap-1">
                                            <i class="fas fa-check-circle text-[9px]"></i> <?php echo $feedIdDisplay; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-yellow-500 text-[10px] font-black italic tracking-wider">
                                            <i class="fas fa-lock text-[8px] mr-1"></i><?php echo $feedIdDisplay; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-gray-300 text-xs leading-relaxed line-clamp-2"><?php echo htmlspecialchars($myC['confession_text']); ?></p>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-neon text-[9px] font-bold tracking-wider inline-flex items-center gap-1">Click to read full <i class="fas fa-arrow-right text-[8px]"></i></span>
                                    
                                    <!-- CONDITIONAL LIKES RENDERING FOR USER SUBMISSIONS -->
                                    <?php if (!$hasBinIcon && $isRevealed): ?>
                                        <span class="text-gray-500 text-[10px] flex items-center gap-1"><i class="fas fa-heart text-pink-500/80 text-[9px]"></i> <?php echo $myC['total_like_count'] ?? 0; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- TRASH BIN ICON FOR CURRENT WEEK -->
                            <?php if ($hasBinIcon): ?>
                                <button onclick="event.stopPropagation(); deleteConfession(<?php echo $myC['id']; ?>)" class="w-8 h-8 rounded-lg bg-red-500/10 text-red-500 flex items-center justify-center hover:bg-red-500 hover:text-white transition-all duration-300 border border-red-500/20 flex-shrink-0" title="Delete Confession">
                                    <i class="fas fa-trash-alt text-[10px]"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- PUBLIC FEED -->
        <div class="mt-12 mb-8 text-center relative">
            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-800"></div></div>
            <div class="relative z-10 inline-block bg-black px-6">
                 <span class="text-green-500 font-bold text-[10px] tracking-[0.2em] uppercase flex items-center gap-2">
                    <span class="relative flex h-2 w-2">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                    </span>
                    Live Feed
                </span>
            </div>
        </div>

        <?php if (empty($filteredConfessions)): ?>
            <div class="text-center py-16 text-gray-700">
                <i class="far fa-paper-plane text-5xl mb-3 opacity-30 transform -rotate-12"></i>
                <p class="text-xs font-medium">It's quiet here. No revealed secrets found.</p>
                 <?php if(!empty($_GET['filter_month']) || !empty($_GET['filter_id']) || !empty($_GET['share_token'])): ?>
                    <a href="confessions.php" class="inline-block mt-3 text-neon text-xs underline">Clear Filters</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php 
                $lastMonthKey = ''; 
                
                foreach ($filteredConfessions as $confession): 
                    $fullText = trim($confession['confession_text']); 
                    $isLong = mb_strlen($fullText) > 250;
                    $displayText = $isLong ? mb_substr($fullText, 0, 250) . '...' : $fullText;

                    if ($confession['full_month_key'] !== $lastMonthKey):
                ?>
                    <div class="flex items-center justify-center pt-6 pb-2 opacity-90">
                         <div class="bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 text-gray-300 text-[10px] font-bold tracking-[0.2em] px-6 py-1.5 rounded-full border border-gray-700 shadow-lg uppercase">
                             <?php echo $confession['month_name'] . ' ' . $confession['year_val']; ?>
                         </div>
                    </div>
                <?php 
                    $lastMonthKey = $confession['full_month_key'];
                    endif; 
                ?>

                <div class="relative pl-6 border-l border-gray-800 hover:border-neon transition-colors duration-500 py-1" id="conf-<?php echo $confession['id']; ?>">
                    <div class="absolute -left-[5px] top-1.5 w-2.5 h-2.5 rounded-full bg-black border border-gray-700 flex items-center justify-center z-10">
                        <div class="w-1 h-1 rounded-full bg-neon shadow-[0_0_8px_#ff1493]"></div>
                    </div>

                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-neon font-black italic text-xs">
                            <?php echo substr($confession['month_name'], 0, 3); ?> #<?php echo $confession['month_id']; ?>
                        </span>
                        <span class="text-gray-600 text-[9px] font-medium border border-gray-800 px-1 rounded">
                            <?php echo date('d M', strtotime($confession['created_at'])); ?>
                        </span>
                    </div>

                    <div class="text-xs md:text-sm text-gray-200 font-light mb-3 confession-content leading-relaxed"><?php echo formatConfessionText(htmlspecialchars($displayText)); ?></div>

                    <?php if($isLong): ?>
                        <button onclick='openViewModal(<?php echo json_encode(htmlspecialchars($fullText)); ?>, <?php echo $confession["id"]; ?>, <?php echo $confession["total_like_count"]; ?>, <?php echo json_encode($confession["share_token"] ?? ""); ?>, true)' class="text-neon text-[10px] font-bold uppercase tracking-wider mb-3 hover:underline flex items-center gap-1">
                            Read Full Secret <i class="fas fa-expand-alt text-[9px]"></i>
                        </button>
                    <?php endif; ?>

                    <?php if (!empty($confession['new_optional_text'])): ?>
                        <div class="inline-flex items-center gap-1.5 bg-yellow-500/5 text-yellow-500/80 text-[11px] font-medium px-2.5 py-1 rounded-lg mb-3 border border-yellow-500/10">
                            <i class="fas fa-quote-left text-[9px]"></i> <span><?php echo htmlspecialchars($confession['new_optional_text']); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="flex justify-between items-end mt-1">
                        <div class="flex flex-wrap gap-1.5">
                            <?php if (!empty($confession['register_number'])): ?>
                                <a href="view_profile?register_number=<?php echo urlencode($confession['register_number']); ?>" 
                                   class="flex items-center gap-1 bg-gradient-to-r from-sky-500 to-blue-800 hover:from-sky-400 hover:to-blue-700 text-white px-2 py-0.5 rounded text-[8px] font-semibold uppercase tracking-wide shadow-sm transition duration-300">
                                     <i class="fas fa-user text-[8px]"></i> 
                                     <?php echo htmlspecialchars($confession['register_number']); ?>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($confession['instagram_account'])): ?>
                                <a href="https://instagram.com/<?php echo htmlspecialchars(trim($confession['instagram_account'], '@')); ?>" 
                                   target="_blank"
                                   class="flex items-center gap-1 bg-gradient-to-r from-pink-500 via-purple-500 to-indigo-600 hover:from-pink-400 hover:via-purple-400 hover:to-indigo-500 text-white px-2 py-0.5 rounded text-[8px] font-semibold uppercase tracking-wide shadow-sm transition duration-300">
                                     <i class="fab fa-instagram text-[8px]"></i> 
                                     @<?php echo htmlspecialchars(trim($confession['instagram_account'], '@')); ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- FEED ACTIONS: SHARE & LIKES -->
                        <div class="flex items-center gap-3">
                            <button 
                                class="share-feed-btn text-sky-400/80 hover:text-sky-300 bg-sky-500/10 border border-sky-500/20 hover:border-sky-400/40 px-2.5 py-1 rounded-full transition-all duration-300 flex items-center gap-1 text-[11px] font-medium shadow-sm hover:scale-105 active:scale-95" 
                                data-conf-id="<?php echo (int)$confession['id']; ?>"
                                data-share-token="<?php echo htmlspecialchars($confession['share_token'] ?? '', ENT_QUOTES); ?>"
                                data-snippet="<?php echo htmlspecialchars(mb_strimwidth($fullText, 0, 100, '...'), ENT_QUOTES); ?>"
                                title="Share Confession">
                                <i class="fas fa-share-alt text-[10px]"></i>
                                <span>Share</span>
                            </button>

                            <button class="like-btn text-gray-600 hover:text-neon transition flex items-center gap-1.5 group" data-conf-id="<?php echo $confession['id']; ?>" onclick="likeConfession(<?php echo $confession['id']; ?>, this)">
                                <i class="far fa-heart text-base transition-all duration-300 group-active:scale-150"></i>
                                <span class="text-xs font-semibold group-hover:text-white" id="like-count-<?php echo $confession['id']; ?>"><?php echo $confession['total_like_count']; ?></span>
                            </button>
                        </div>
                    </div>
                </div>

                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- CONFIRMATION MODAL -->
    <div id="confirmModal" class="fixed inset-0 z-[1000] hidden items-center justify-center bg-black/80 backdrop-blur-md p-4 transition-all duration-300">
        <div class="relative w-full max-w-[320px] bg-[#121212] border border-white/10 rounded-2xl p-6 shadow-2xl animate-pop text-left">
            <h2 id="confirmModalTitle" class="text-base font-bold text-white tracking-wide mb-2">
                Confirm Action
            </h2>
            <p id="confirmModalMessage" class="text-gray-400 text-xs sm:text-sm leading-relaxed mb-6 font-normal">
                Are you sure you want to proceed?
            </p>
            <div class="flex items-center justify-end gap-3 pt-1">
                <button id="confirmCancelBtn" class="px-4 py-2 hover:bg-white/5 text-gray-300 hover:text-white font-semibold text-xs sm:text-sm rounded-xl transition-colors">
                    Cancel
                </button>
                <button id="confirmActionBtn" class="px-5 py-2 bg-[#222222] hover:bg-neon text-white font-semibold text-xs sm:text-sm rounded-xl border border-white/5 transition-all duration-200 active:scale-95">
                    Confirm
                </button>
            </div>
        </div>
    </div>

    <div class="fixed bottom-0 left-0 right-0 bottom-bar bg-black/60 backdrop-blur-lg border-t border-white/10 px-4 py-3 flex items-center justify-between z-30">
        <div class="flex items-center gap-3">
            <a href="https://oursrmap.purlyedit.in/chat_profile.php?register_number=ADMIN" class="text-gray-400 hover:text-neon transition-colors text-xs font-medium flex items-center gap-1.5">
                <i class="fas fa-headset text-[10px] text-neon"></i>
                Help & Support
            </a>
        </div>
        <div class="flex items-center gap-3">
            <i class="fas fa-heart text-neon/30 text-xs animate-float"></i>
            <i class="fas fa-heart text-neon/40 text-sm animate-float" style="animation-delay: 1s;"></i>
        </div>
        <div class="flex items-center gap-3">
            <a href="https://oursrmap.purlyedit.in/chat_profile.php?register_number=ADMIN" class="text-gray-400 hover:text-neon transition-colors text-xs font-medium flex items-center gap-1.5">
                <i class="fas fa-comments text-[10px]"></i>
                Chat Admin
            </a>
        </div>
    </div>

    <script>
        const MAX_SUBMISSIONS = <?php echo $maxSubmissions; ?>;
        let activeModalConfessionId = null;
        let activeModalShareToken = null;
        let activeShareData = { url: '', snippet: '' };

        window.addEventListener('load', () => {
            document.querySelectorAll('.like-btn').forEach(btn => {
                const id = btn.getAttribute('data-conf-id');
                if(localStorage.getItem('liked_'+id)) {
                    const icon = btn.querySelector('i');
                    icon.classList.replace('far', 'fas');
                    icon.classList.add('heart-liked');
                    btn.classList.add('text-red-500');
                }
            });

            setTimeout(() => {
                const loader = document.getElementById('loading-screen');
                loader.classList.add('opacity-0', 'pointer-events-none');
            }, 800);
        });

        function showConfirmModal(title, message, confirmBtnText = 'Confirm', isDanger = false) {
            return new Promise((resolve) => {
                const modal = document.getElementById('confirmModal');
                const titleElem = document.getElementById('confirmModalTitle');
                const msgElem = document.getElementById('confirmModalMessage');
                const confirmBtn = document.getElementById('confirmActionBtn');
                const cancelBtn = document.getElementById('confirmCancelBtn');

                titleElem.innerText = title;
                msgElem.innerText = message;
                confirmBtn.innerText = confirmBtnText;

                if (isDanger) {
                    confirmBtn.className = "px-5 py-2 bg-red-600/20 hover:bg-red-600 text-red-400 hover:text-white font-semibold text-xs sm:text-sm rounded-xl border border-red-500/30 transition-all duration-200 active:scale-95";
                } else {
                    confirmBtn.className = "px-5 py-2 bg-[#222222] hover:bg-neon text-white font-semibold text-xs sm:text-sm rounded-xl border border-white/5 transition-all duration-200 active:scale-95";
                }

                modal.classList.remove('hidden');
                modal.classList.add('flex');

                const handleConfirm = () => { closeConfirm(); resolve(true); };
                const handleCancel = () => { closeConfirm(); resolve(false); };

                const closeConfirm = () => {
                    modal.classList.remove('flex');
                    modal.classList.add('hidden');
                    confirmBtn.removeEventListener('click', handleConfirm);
                    cancelBtn.removeEventListener('click', handleCancel);
                };

                confirmBtn.addEventListener('click', handleConfirm);
                cancelBtn.addEventListener('click', handleCancel);
            });
        }

        async function handleConfessionSubmit(event) {
            event.preventDefault();

            const userConfirmed = await showConfirmModal(
                'Submit Confession?', 
                'Your secret will be safely locked until the reveal date.', 
                'Submit'
            );
            if (!userConfirmed) return;
            
            const submitBtn = document.getElementById('submit_btn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = `<span>Securing...</span> <i class="fas fa-spinner fa-spin text-xs"></i>`;

            const formData = new FormData(document.getElementById('confessionForm'));
            formData.append('action', 'submit_confession');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<span>Submit Securely</span> <i class="fas fa-paper-plane text-xs"></i>`;

                if (data.status === 'success') {
                    const counterDisp = document.getElementById('submissionCounterDisplay');
                    if (counterDisp) {
                        counterDisp.innerText = `${data.new_count}/${MAX_SUBMISSIONS} Weekly Slots Used`;
                        counterDisp.className = data.new_count > 0 ? 'text-neon' : 'text-gray-500';
                    }

                    document.getElementById('confession_text').value = '';
                    if (document.getElementById('instagram_account')) document.getElementById('instagram_account').value = '';
                    if (document.getElementById('register_number')) document.getElementById('register_number').value = '';
                    if (document.getElementById('new_optional_text')) document.getElementById('new_optional_text').value = '';

                    if (data.confession) {
                        const wrapper = document.getElementById('myConfessionsWrapper');
                        const list = document.getElementById('myConfessionsList');
                        
                        if (wrapper && list) {
                            wrapper.classList.remove('hidden');
                            
                            const newCard = document.createElement('div');
                            newCard.id = `my-conf-${data.confession.id}`;
                            newCard.className = "group relative bg-gray-900/60 backdrop-blur-md border border-white/5 rounded-xl p-4 hover:border-neon/30 transition-all duration-300 shadow-lg animate-pop cursor-pointer";
                            newCard.setAttribute('onclick', `openViewModal(${JSON.stringify(data.confession.confession_text)}, ${data.confession.id}, ${data.confession.like_count}, ${JSON.stringify(data.confession.share_token)}, false)`);
                            newCard.innerHTML = `
                                <div class="flex justify-between items-start gap-3">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                                            <span class="bg-gray-800 text-gray-400 text-[9px] font-bold px-1.5 py-0.5 rounded border border-gray-700 tracking-wider">
                                                <i class="far fa-calendar-alt mr-1"></i> ${data.confession.created_at}
                                            </span>
                                            <span class="bg-pink-500/10 text-neon border border-neon/30 text-[9px] font-bold px-2 py-0.5 rounded-full tracking-wider flex items-center gap-1 shadow-[0_0_10px_rgba(255,20,147,0.15)]">
                                                <i class="fas fa-lock text-[8px]"></i> ${data.confession.confession_id_tag} (Locked)
                                            </span>
                                        </div>
                                        <p class="text-gray-300 text-xs leading-relaxed line-clamp-2">${escapeHtml(data.confession.confession_text)}</p>
                                        <div class="flex items-center justify-between mt-2">
                                            <span class="text-neon text-[9px] font-bold tracking-wider inline-flex items-center gap-1">Click to read full <i class="fas fa-arrow-right text-[8px]"></i></span>
                                        </div>
                                    </div>
                                    
                                    <button onclick="event.stopPropagation(); deleteConfession(${data.confession.id})" class="w-8 h-8 rounded-lg bg-red-500/10 text-red-500 flex items-center justify-center hover:bg-red-500 hover:text-white transition-all duration-300 border border-red-500/20 flex-shrink-0" title="Delete Confession">
                                        <i class="fas fa-trash-alt text-[10px]"></i>
                                    </button>
                                </div>
                            `;
                            list.prepend(newCard);
                        }
                    }

                    if (data.limit_reached) {
                        document.getElementById('formContainer').classList.add('hidden');
                        document.getElementById('limitNotice').classList.remove('hidden');
                    }

                    showModal(data.title, data.message, 'success', data.reveal_date, data.confession_id_tag);
                } else {
                    showModal('Notice', data.message, 'error');
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<span>Submit Securely</span> <i class="fas fa-paper-plane text-xs"></i>`;
                showModal('Error', 'An unexpected error occurred. Please try again.', 'error');
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.innerText = text;
            return div.innerHTML;
        }

        async function deleteConfession(id) {
            const userConfirmed = await showConfirmModal(
                'Delete Confession?', 
                'This secret will be permanently removed.', 
                'Delete', 
                true
            );
            if (!userConfirmed) return;

            const formData = new FormData();
            formData.append('action', 'delete_confession');
            formData.append('delete_confession_id', id);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const card = document.getElementById(`my-conf-${id}`);
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.9)';
                        setTimeout(() => {
                            card.remove();
                            const list = document.getElementById('myConfessionsList');
                            if (list && list.children.length === 0) {
                                document.getElementById('myConfessionsWrapper').classList.add('hidden');
                            }
                        }, 300);
                    }

                    const counterDisp = document.getElementById('submissionCounterDisplay');
                    if (counterDisp) {
                        counterDisp.innerText = `${data.new_count}/${MAX_SUBMISSIONS} Weekly Slots Used`;
                        counterDisp.className = data.new_count > 0 ? 'text-neon' : 'text-gray-500';
                    }

                    document.getElementById('formContainer').classList.remove('hidden');
                    document.getElementById('limitNotice').classList.add('hidden');

                    showModal('Deleted!', data.message, 'success');
                } else {
                    showModal('Error', data.message, 'error');
                }
            })
            .catch(err => {
                showModal('Error', 'Unable to delete confession.', 'error');
            });
        }

        function showModal(title, message, type, revealDate = '', confIdTag = '') {
            const modal = document.getElementById('successModal');
            const titleElem = document.getElementById('modalTitle');
            const dateBadge = document.getElementById('modalDateBadge');
            const dateText = document.getElementById('modalRevealDateText');
            const idBox = document.getElementById('modalConfIdBox');
            const idText = document.getElementById('modalConfIdText');
            
            titleElem.innerText = title;
            document.getElementById('modalMessage').innerText = message;

            if (type === 'success' && revealDate) {
                titleElem.className = "text-base font-bold text-neon tracking-wide mb-1 flex items-center gap-1.5";
                dateText.innerText = revealDate;
                
                if (confIdTag) {
                    idText.innerText = confIdTag;
                    idBox.classList.remove('hidden');
                    idBox.classList.add('flex');
                } else {
                    idBox.classList.add('hidden');
                }
                
                dateBadge.classList.remove('hidden');
                dateBadge.classList.add('flex');
            } else {
                titleElem.className = "text-base font-bold text-red-500 tracking-wide mb-1 flex items-center gap-1.5";
                dateBadge.classList.remove('flex');
                dateBadge.classList.add('hidden');
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeModal() {
            const modal = document.getElementById('successModal');
            modal.classList.add('opacity-0');
            setTimeout(() => { 
                modal.classList.remove('flex', 'opacity-0');
                modal.classList.add('hidden');
            }, 300);
        }

        const regInput = document.getElementById('register_number');
        const suggestionsBox = document.getElementById('reg-suggestions');
        
        if (regInput && suggestionsBox) {
            regInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                if (query.length > 1) {
                    fetch(`confessions/mention_users_fetch.php?query=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            suggestionsBox.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(user => {
                                    const div = document.createElement('div');
                                    div.className = "p-2.5 cursor-pointer hover:bg-white/10 border-b border-white/10 last:border-0 flex items-center gap-2.5 transition-colors suggestion-item";
                                    div.setAttribute('data-value', user.register_number);
                                    
                                    const imgSrc = user.profile_photo ? user.profile_photo : `https://ui-avatars.com/api/?name=${user.name}&background=random`;

                                    div.innerHTML = `
                                        <div class="w-7 h-7 rounded-full bg-gray-700 overflow-hidden flex-shrink-0">
                                             <img src="${imgSrc}" class="w-full h-full object-cover">
                                        </div>
                                        <div>
                                            <p class="text-xs text-white font-bold leading-none">${user.name}</p>
                                            <p class="text-[9px] text-gray-400 mt-0.5">${user.register_number}</p>
                                        </div>
                                    `;
                                    suggestionsBox.appendChild(div);
                                });
                                suggestionsBox.classList.remove('hidden');
                            } else {
                                suggestionsBox.classList.add('hidden');
                            }
                        })
                        .catch(err => {
                            suggestionsBox.classList.add('hidden');
                        });
                } else {
                    suggestionsBox.classList.add('hidden');
                }
            });

            suggestionsBox.addEventListener('click', function(e) {
                const target = e.target.closest('.suggestion-item'); 
                if (target) {
                    const val = target.getAttribute('data-value');
                    regInput.value = val;
                    suggestionsBox.classList.add('hidden');
                }
            });

            document.addEventListener('click', function(e) {
                if (!regInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
                    suggestionsBox.classList.add('hidden');
                }
            });
        }

        // Feed "Share" button listener
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.share-feed-btn');
            if (!btn) return;
            const confId  = btn.getAttribute('data-conf-id');
            const token   = btn.getAttribute('data-share-token');
            const snippet = btn.getAttribute('data-snippet');
            shareConfession(confId, token, snippet, btn);
        });

        function openViewModal(content, confessionId = null, initialLikes = 0, shareToken = '', isRevealed = true) {
            const modal = document.getElementById('viewMoreModal');
            const contentBox = document.getElementById('full-confession-content');
            const modalLikeIcon = document.getElementById('modalLikeIcon');
            const modalLikeCount = document.getElementById('modalLikeCount');
            const modalLikeBtn = document.getElementById('modalLikeBtn');
            const modalActionsContainer = document.getElementById('modalActionsContainer');
            
            activeModalConfessionId = confessionId;
            activeModalShareToken = shareToken;

            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = content;
            let formattedText = tempDiv.innerText;
            
            formattedText = formattedText.replace(
                /\b(https?:\/\/[^\s]+)/gi,
                '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-sky-400 font-medium hover:text-sky-300 underline decoration-sky-400/30 underline-offset-2 break-all relative z-10">$1</a>'
            );

            const regex = new RegExp(`\\b(love|crush|heart|romance|forever|beautiful|cute|istam|prema|miss you|smile|eyes|soulmate|admirer|darling|dear)\\b`, 'gi');
            formattedText = formattedText.replace(regex, '<span class="text-neon font-bold drop-shadow-[0_0_8px_rgba(255,20,147,0.8)]">$1</span>');
            contentBox.innerHTML = formattedText;

            if (confessionId && isRevealed) {
                modalActionsContainer.classList.remove('hidden');
                
                const feedBtnSpan = document.querySelector(`.like-btn[data-conf-id="${confessionId}"] span`);
                const currentLikes = feedBtnSpan ? parseInt(feedBtnSpan.innerText) : initialLikes;
                modalLikeCount.innerText = currentLikes;

                if (localStorage.getItem('liked_' + confessionId)) {
                    modalLikeIcon.className = "fas fa-heart text-sm text-red-500 heart-liked";
                    modalLikeBtn.classList.add('border-red-500/60');
                } else {
                    modalLikeIcon.className = "far fa-heart text-sm text-neon group-hover:text-white transition-colors";
                    modalLikeBtn.classList.remove('border-red-500/60');
                }
            } else {
                modalActionsContainer.classList.add('hidden');
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeViewModal() {
            const modal = document.getElementById('viewMoreModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = 'auto';
            document.body.style.overflowX = 'hidden';
            activeModalConfessionId = null;
            activeModalShareToken = null;
        }

        function shareConfession(confId, token = '', snippet = '', btn = null) {
            let originalBtnHtml = '';
            if (btn) {
                originalBtnHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = `<i class="fas fa-spinner fa-spin text-xs"></i> <span>Generating...</span>`;
            }

            if (token) {
                const shareUrl = `${window.location.origin}${window.location.pathname}?share_token=${token}`;
                if (btn) btn.innerHTML = originalBtnHtml;
                openShareModal(shareUrl, snippet);
            } else {
                const formData = new FormData();
                formData.append('action', 'generate_share_token');
                formData.append('confession_id', confId);

                fetch('', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalBtnHtml;
                    }
                    if (data.status === 'success') {
                        openShareModal(data.share_url, data.snippet || snippet);
                    } else {
                        const fallbackUrl = `${window.location.origin}${window.location.pathname}?share_token=${confId}`;
                        openShareModal(fallbackUrl, snippet);
                    }
                })
                .catch(err => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalBtnHtml;
                    }
                    const fallbackUrl = `${window.location.origin}${window.location.pathname}?share_token=${confId}`;
                    openShareModal(fallbackUrl, snippet);
                });
            }
        }

        function shareModalConfession(btn) {
            if (!activeModalConfessionId) return;
            const fullText = document.getElementById('full-confession-content').innerText;
            const snippet = fullText.length > 100 ? fullText.substring(0, 100) + '...' : fullText;
            shareConfession(activeModalConfessionId, activeModalShareToken, snippet, btn);
        }

        function openShareModal(url, snippet = '') {
            activeShareData = { url, snippet };
            
            document.getElementById('shareUrlInput').value = url;
            document.getElementById('shareSnippetText').innerText = snippet ? `"${snippet}"` : '"Read secret confession on Connect SRMAP!"';
            
            const shareText = `Check out this confession on Connect SRMAP: "${snippet}"`;
            
            document.getElementById('shareWhatsappBtn').href = `https://api.whatsapp.com/send?text=${encodeURIComponent(shareText + "\n" + url)}`;
            document.getElementById('shareTelegramBtn').href = `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(shareText)}`;
            
            const modal = document.getElementById('shareModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeShareModal() {
            const modal = document.getElementById('shareModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function copyShareUrl() {
            const urlInput = document.getElementById('shareUrlInput');
            navigator.clipboard.writeText(urlInput.value).then(() => {
                const btn = document.getElementById('copyShareBtn');
                btn.innerHTML = `<i class="fas fa-check"></i> Copied!`;
                btn.className = "bg-green-500 text-black font-bold text-[10px] px-3 py-1.5 rounded-lg transition-all flex items-center gap-1 flex-shrink-0";
                setTimeout(() => {
                    btn.innerHTML = `<i class="fas fa-copy"></i> Copy`;
                    btn.className = "bg-sky-500 hover:bg-sky-400 text-black font-bold text-[10px] px-3 py-1.5 rounded-lg transition-all flex items-center gap-1 flex-shrink-0";
                }, 2000);
            }).catch(() => {
                showModal('Error', 'Failed to copy link.', 'error');
            });
        }

        function shareToInstagram(e) {
            e.preventDefault();
            navigator.clipboard.writeText(activeShareData.url).then(() => {
                showModal('Link Copied!', 'Confession link copied to clipboard. Paste it into your Instagram Story or DM!', 'success');
                setTimeout(() => {
                    window.open('https://instagram.com', '_blank');
                }, 1200);
            });
        }

        function triggerNativeShare() {
            if (navigator.share) {
                navigator.share({
                    title: 'Confession | Connect SRMAP',
                    text: `Check out this confession: "${activeShareData.snippet}"`,
                    url: activeShareData.url
                }).catch(() => {});
            } else {
                copyShareUrl();
            }
        }

        function likeModalConfession(btn) {
            if (!activeModalConfessionId) return;
            if (localStorage.getItem('liked_' + activeModalConfessionId)) return;

            const targetId = activeModalConfessionId;
            const feedBtn = document.querySelector(`.like-btn[data-conf-id="${targetId}"]`);
            if (feedBtn) {
                likeConfession(targetId, feedBtn);
            } else {
                const icon = document.getElementById('modalLikeIcon');
                const countSpan = document.getElementById('modalLikeCount');
                
                icon.className = "fas fa-heart text-sm text-red-500 heart-liked animate-pop";
                countSpan.innerText = parseInt(countSpan.innerText) + 1;
                localStorage.setItem('liked_' + targetId, true);

                const formData = new FormData();
                formData.append('like_confession_id', targetId);
                fetch('', { method: 'POST', body: formData });
            }

            const icon = document.getElementById('modalLikeIcon');
            const countSpan = document.getElementById('modalLikeCount');
            icon.className = "fas fa-heart text-sm text-red-500 heart-liked animate-pop";
            
            const updatedFeedCount = document.querySelector(`.like-btn[data-conf-id="${targetId}"] span`);
            if (updatedFeedCount) {
                countSpan.innerText = updatedFeedCount.innerText;
            }
        }

        function toggleHelp() {
            const popup = document.getElementById('helpPopup');
            const overlay = document.getElementById('helpOverlay');
            popup.classList.toggle('hidden');
            overlay.classList.toggle('hidden');
            if(!document.getElementById('filterPopup').classList.contains('hidden')) {
                toggleFilter();
            }
        }

        function toggleFilter() {
            const popup = document.getElementById('filterPopup');
            const overlay = document.getElementById('filterOverlay');
            popup.classList.toggle('hidden');
            overlay.classList.toggle('hidden');
            if(!document.getElementById('helpPopup').classList.contains('hidden')) {
                toggleHelp();
            }
        }

        function likeConfession(id, btn) {
            if(localStorage.getItem('liked_'+id)) return;
            
            const icon = btn.querySelector('i');
            const countSpan = btn.querySelector('span');
            
            icon.classList.replace('far', 'fas');
            icon.classList.add('heart-liked', 'animate-pop');
            btn.classList.add('text-red-500');
            
            countSpan.innerText = parseInt(countSpan.innerText) + 1;
            localStorage.setItem('liked_'+id, true);
            
            if (activeModalConfessionId === id) {
                const modalCountSpan = document.getElementById('modalLikeCount');
                const modalIcon = document.getElementById('modalLikeIcon');
                if (modalCountSpan) modalCountSpan.innerText = countSpan.innerText;
                if (modalIcon) modalIcon.className = "fas fa-heart text-sm text-red-500 heart-liked animate-pop";
            }

            const formData = new FormData();
            formData.append('like_confession_id', id);
            fetch('', { method: 'POST', body: formData });
        }
    </script>
</body>
</html>
