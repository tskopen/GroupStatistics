<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['tournament_name'] ?? '');
    $participantCount = (int)($_POST['participant_count'] ?? 0);
    $participantIds = $_POST['participant_ids'] ?? [];
    
    $validCounts = [4, 8, 16, 32, 64];
    
    if (!$name) {
        $error = 'Tournament name is required.';
    } elseif (!in_array($participantCount, $validCounts)) {
        $error = 'Invalid participant count. Choose 4, 8, 16, 32, or 64.';
    } elseif (count($participantIds) !== $participantCount) {
        $error = 'You must select exactly ' . $participantCount . ' participants.';
    } else {
        // Create bracket
        $bracket = generateBracket($name, $participantIds, $squadrons);
        $brackets = readJson(DATA_DIR . '/brackets.json');
        $brackets[] = $bracket;
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        
        header('Location: admin-bracket-edit.php?id=' . $bracket['id']);
        exit;
    }
}

function generateBracket($name, $participantIds, $squadrons) {
    $squadronMap = [];
    foreach ($squadrons as $s) {
        $squadronMap[$s['id']] = $s;
    }
    
    $bracket = [
        'id' => bin2hex(random_bytes(12)),
        'name' => $name,
        'created_date' => date('c'),
        'participants' => array_map(fn($id) => [
            'id' => $id,
            'name' => $squadronMap[$id]['name'] ?? 'Unknown',
            'icon' => $squadronMap[$id]['icon'] ?? null
        ], $participantIds),
        'rounds' => [],
        'champion_id' => null,
        'status' => 'in_progress'
    ];
    
    // Generate rounds
    $numParticipants = count($participantIds);
    $numRounds = log2($numParticipants);
    $roundNames = ['Round of ' . $numParticipants, 'Quarterfinals', 'Semifinals', 'Finals', 'Champion'];
    
    $currentRoundParticipants = $participantIds;
    
    for ($round = 0; $round < $numRounds; $round++) {
        $roundNum = $round + 1;
        $roundName = $roundNames[$round] ?? 'Round ' . $roundNum;
        $matchups = [];
        
        for ($i = 0; $i < count($currentRoundParticipants); $i += 2) {
            $team1 = $currentRoundParticipants[$i];
            $team2 = $currentRoundParticipants[$i + 1] ?? null;
            
            $matchups[] = [
                'id' => bin2hex(random_bytes(8)),
                'team1_id' => $team1,
                'team2_id' => $team2,
                'team1_score' => null,
                'team2_score' => null,
                'winner_id' => null,
                'status' => 'upcoming',
                'points' => null,
                'next_match_id' => null
            ];
        }
        
        $bracket['rounds'][] = [
            'round_num' => $roundNum,
            'round_name' => $roundName,
            'matchups' => $matchups
        ];
        
        // Link matchups to next round
        if ($round < $numRounds - 1) {
            $nextRoundMatchups = $bracket['rounds'][$round + 1]['matchups'] ?? [];
            for ($i = 0; $i < count($matchups); $i += 2) {
                if (isset($nextRoundMatchups[$i / 2])) {
                    $matchups[$i]['next_match_id'] = $nextRoundMatchups[$i / 2]['id'];
                    if (isset($matchups[$i + 1])) {
                        $matchups[$i + 1]['next_match_id'] = $nextRoundMatchups[$i / 2]['id'];
                    }
                }
            }
        }
        
        $bracket['rounds'][$round]['matchups'] = $matchups;
        
        // Prepare for next round
        $currentRoundParticipants = array_fill(0, count($matchups), null);
    }
    
    return $bracket;
}

$participantCount = $_POST['participant_count'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Tournament</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); margin: 0; padding: 20px; min-height: 100vh; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { text-align: center; color: #fff; margin-bottom: 40px; }
        .header h1 { margin: 0; font-size: 2.5em; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
        .header p { margin: 5px 0 0 0; opacity: 0.9; }
        
        .card { background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); padding: 30px; margin-bottom: 20px; }
        
        h2 { color: #333; margin-top: 0; border-bottom: 2px solid #667eea; padding-bottom: 15px; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; color: #333; margin-bottom: 8px; }
        input[type="text"], select { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; transition: border-color 0.3s; }
        input[type="text"]:focus, select:focus { outline: none; border-color: #667eea; }
        
        .participant-count-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .count-btn { padding: 12px; border: 2px solid #ddd; background: #fff; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .count-btn:hover { border-color: #667eea; color: #667eea; }
        .count-btn.active { background: #667eea; color: #fff; border-color: #667eea; }
        
        .participants-section { margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; }
        .participants-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; max-height: 400px; overflow-y: auto; }
        .participant-option { padding: 12px; background: #fff; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 10px; }
        .participant-option input[type="checkbox"] { cursor: pointer; }
        .participant-option:hover { border-color: #667eea; }
        .participant-option.selected { background: #e8eaf6; border-color: #667eea; }
        .participant-icon { width: 30px; height: 30px; border-radius: 4px; background: #ccc; object-fit: cover; }
        
        .selection-count { color: #666; font-size: 14px; margin-top: 10px; }
        
        .error { background: #ffebee; color: #c62828; padding: 12px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #c62828; }
        .success { background: #e8f5e9; color: #2e7d32; padding: 12px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #2e7d32; }
        
        .button-group { display: flex; gap: 10px; margin-top: 30px; }
        button { flex: 1; padding: 14px; font-size: 16px; font-weight: 600; border: none; border-radius: 6px; cursor: pointer; transition: all 0.3s; }
        .btn-submit { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-back { background: #f0f0f0; color: #333; }
        .btn-back:hover { background: #e0e0e0; }
        
        @media (max-width: 600px) {
            .card { padding: 20px; }
            .header h1 { font-size: 1.8em; }
            .button-group { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🏆 Create Tournament</h1>
        <p>Set up a new single-elimination bracket</p>
    </div>
    
    <div class="card">
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" id="bracketForm">
            <div class="form-group">
                <label for="name">Tournament Name</label>
                <input type="text" id="name" name="tournament_name" placeholder="e.g., Spring 2024 Championship" required value="<?php echo htmlspecialchars($_POST['tournament_name'] ?? ''); ?>">
            </div>
            
            <h2>Step 1: Choose Participant Count</h2>
            <div class="participant-count-grid">
                <?php foreach ([4, 8, 16, 32, 64] as $count): ?>
                    <button type="button" class="count-btn <?php echo $participantCount == $count ? 'active' : ''; ?>" data-count="<?php echo $count; ?>">
                        <?php echo $count; ?> Teams
                    </button>
                <?php endforeach; ?>
            </div>
            
            <h2>Step 2: Select Participants</h2>
            <div class="participants-section" id="participantsSection" style="display: none;">
                <div class="participants-grid" id="participantsGrid"></div>
                <div class="selection-count">Selected: <span id="selectedCount">0</span> / <span id="requiredCount">-</span></div>
            </div>
            
            <input type="hidden" id="participantCount" name="participant_count">
            
            <div class="button-group">
                <a href="admin-panel.php" class="btn-back">← Back</a>
                <button type="submit" class="btn-submit" id="submitBtn" disabled>Create Tournament</button>
            </div>
        </form>
    </div>
</div>

<script>
const squadrons = <?php echo json_encode($squadrons); ?>;
const form = document.getElementById('bracketForm');
const countBtns = document.querySelectorAll('.count-btn');
const participantsSection = document.getElementById('participantsSection');
const participantsGrid = document.getElementById('participantsGrid');
const participantCountInput = document.getElementById('participantCount');
const selectedCountSpan = document.getElementById('selectedCount');
const requiredCountSpan = document.getElementById('requiredCount');
const submitBtn = document.getElementById('submitBtn');

let selectedCount = 0;
let requiredCount = 0;

countBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        countBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        requiredCount = parseInt(btn.dataset.count);
        requiredCountSpan.textContent = requiredCount;
        participantCountInput.value = requiredCount;
        
        participantsSection.style.display = requiredCount > 0 ? 'block' : 'none';
        renderParticipants();
        checkFormValid();
    });
});

function renderParticipants() {
    participantsGrid.innerHTML = '';
    squadrons.forEach(squadron => {
        const div = document.createElement('div');
        div.className = 'participant-option';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'participant_ids';
        checkbox.value = squadron.id;
        checkbox.addEventListener('change', () => {
            updateSelectedCount();
            div.classList.toggle('selected', checkbox.checked);
            checkFormValid();
        });
        
        const icon = document.createElement('img');
        if (squadron.icon) {
            icon.src = 'image.php?file=' + encodeURIComponent(squadron.icon);
            icon.className = 'participant-icon';
        } else {
            icon.className = 'participant-icon';
            icon.style.background = '#667eea';
        }
        
        const label = document.createElement('label');
        label.style.margin = '0';
        label.style.flex = '1';
        label.style.cursor = 'pointer';
        label.textContent = squadron.name;
        
        div.appendChild(checkbox);
        div.appendChild(icon);
        div.appendChild(label);
        
        div.addEventListener('click', (e) => {
            if (e.target !== checkbox) {
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change'));
            }
        });
        
        participantsGrid.appendChild(div);
    });
}

function updateSelectedCount() {
    selectedCount = document.querySelectorAll('input[name="participant_ids"]:checked').length;
    selectedCountSpan.textContent = selectedCount;
}

function checkFormValid() {
    const nameValid = document.getElementById('name').value.trim().length > 0;
    const countValid = requiredCount > 0;
    const selectionsValid = selectedCount === requiredCount;
    submitBtn.disabled = !(nameValid && countValid && selectionsValid);
}

document.getElementById('name').addEventListener('input', checkFormValid);
</script>
</body>
</html>
